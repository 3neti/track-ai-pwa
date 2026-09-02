<?php

namespace App\Services\TrackAI;

use App\Contracts\SarasClientInterface;
use App\Models\Contract;
use App\Models\ProjectProgressReport;
use App\Services\Saras\SarasProjectContextResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class ContractService
{
    protected SarasProjectContextResolver $contextResolver;

    public function __construct(
        protected SarasClientInterface $sarasClient,
        ?SarasProjectContextResolver $contextResolver = null,
    ) {
        $this->contextResolver = $contextResolver ?? app(SarasProjectContextResolver::class);
    }

    /**
     * Sync contracts from Saras Contract AI into local database.
     *
     * Also fetches ProjectProgress records to find certificates.
     *
     * @return Collection<int, Contract>
     */
    public function syncContractsFromSaras(bool $pruneMissing = true): Collection
    {
        $contractAiId = $this->contextResolver->subProjectId('contract_ai');

        $response = $this->sarasClient->getProcesses($contractAiId, 1, 50);

        // Fetch ProjectProgress records to cross-reference certificates
        $progressCertificates = $this->fetchProgressCertificates();

        $syncedProcessIds = [];

        foreach (($response['processes'] ?? []) as $process) {
            $processId = $process['id'] ?? null;

            if (! $processId) {
                continue;
            }

            $syncedProcessIds[] = $processId;

            $name = $process['fields']['legalName1']
                ?? $process['metaDetails']['title']
                ?? 'Contract #'.($process['metaDetails']['displayNumber'] ?? '?');

            $milestones = $process['fields']['milestone'] ?? [];
            $certificateStatus = $this->determineCertificateStatus($process, $progressCertificates);
            $certificate = $this->extractCertificate($process, $progressCertificates);

            Contract::updateOrCreate(
                ['saras_process_id' => $processId],
                [
                    'name' => $name,
                    'display_number' => $process['metaDetails']['displayNumber'] ?? null,
                    'milestones' => $milestones,
                    'certificate_status' => $certificateStatus,
                    'certificate_file_id' => $certificate['file_id'],
                    'certificate_subproject_id' => $certificate['subproject_id'],
                    'raw_saras_payload' => $process,
                    'last_synced_at' => now(),
                ],
            );
        }

        if ($pruneMissing) {
            Contract::whereNotIn('saras_process_id', $syncedProcessIds)->delete();
        }

        return $this->orderedContractsQuery()
            ->whereIn('saras_process_id', $syncedProcessIds)
            ->get();
    }

    /**
     * List locally cached contracts, syncing from Saras if empty.
     *
     * @return Collection<int, Contract>
     */
    public function listContracts(bool $refresh = false): Collection
    {
        $contracts = $this->orderedContractsQuery()->get();

        if ($refresh && ! $this->sarasClient->isStubMode()) {
            try {
                return $this->syncContractsFromSaras();
            } catch (\Exception $e) {
                Log::warning('ContractService: Failed to refresh contracts from Saras', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($contracts->isEmpty()) {
            try {
                return $this->syncContractsFromSaras();
            } catch (\Exception $e) {
                Log::warning('ContractService: Failed to sync contracts from Saras', [
                    'error' => $e->getMessage(),
                ]);

                return $contracts;
            }
        }

        return $contracts;
    }

    /**
     * @return Builder<Contract>
     */
    protected function orderedContractsQuery(): Builder
    {
        return Contract::query()
            ->orderBy('name')
            ->orderBy('display_number')
            ->orderBy('saras_process_id');
    }

    /**
     * Fetch certificates from ProjectProgress records in Saras.
     *
     * Returns a map of contractId => certificate details.
     *
     * @return array<string, array{file_id: string, subproject_id: string}>
     */
    protected function fetchProgressCertificates(): array
    {
        $certificates = [];

        try {
            $ppSubId = $this->contextResolver->subProjectId('project_progress');
            $ppResponse = $this->sarasClient->getProcesses($ppSubId, 1, 50);

            foreach ($ppResponse['processes'] ?? [] as $pp) {
                $certFileId = $pp['fields']['certificateOfCompletion'] ?? null;
                $contractId = $pp['fields']['contractId'] ?? null;

                if (! empty($certFileId) && ! empty($contractId) && is_string($certFileId)) {
                    // Keep the latest certificate per contract (processes are ordered by creation)
                    $certificates[$contractId] = [
                        'file_id' => $certFileId,
                        'subproject_id' => $ppSubId,
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::warning('ContractService: Failed to fetch progress certificates', [
                'error' => $e->getMessage(),
            ]);
        }

        return $certificates;
    }

    /**
     * Determine certificate status from contract data and progress certificates.
     *
     * @param  array<string, array{file_id: string, subproject_id: string}>  $progressCertificates  Map of contractId => certificate details from ProjectProgress
     */
    public function determineCertificateStatus(array $processData, array $progressCertificates = []): string
    {
        $processId = $processData['id'] ?? null;
        $fields = $processData['fields'] ?? [];

        // 1. Check if the Contract AI record itself has a certificate
        $certificate = $fields['certificateOfCompletion'] ?? null;

        if (! empty($certificate)) {
            return Contract::STATUS_AVAILABLE;
        }

        // 2. Check if a ProjectProgress record for this contract has a certificate
        if ($processId && ! empty($progressCertificates[$processId])) {
            return Contract::STATUS_AVAILABLE;
        }

        // 3. Check local progress reports
        if ($processId) {
            $hasProgress = ProjectProgressReport::where('contract_id', $processId)
                ->whereNotIn('progress_status', ['draft', 'failed'])
                ->whereNull('remote_deleted_at')
                ->exists();

            if ($hasProgress) {
                return Contract::STATUS_PENDING;
            }
        }

        return Contract::STATUS_NOT_STARTED;
    }

    /**
     * Extract certificate file ID and source subproject from contract data or progress certificates.
     *
     * @param  array<string, array{file_id: string, subproject_id: string}>  $progressCertificates  Map of contractId => certificate details from ProjectProgress
     * @return array{file_id: ?string, subproject_id: ?string}
     */
    protected function extractCertificate(array $processData, array $progressCertificates = []): array
    {
        // 1. Check Contract AI record
        $certificate = $processData['fields']['certificateOfCompletion'] ?? null;

        if (is_string($certificate) && ! empty($certificate)) {
            return [
                'file_id' => $certificate,
                'subproject_id' => $this->contextResolver->subProjectId('contract_ai'),
            ];
        }

        if (is_array($certificate) && ! empty($certificate[0]['id'])) {
            return [
                'file_id' => $certificate[0]['id'],
                'subproject_id' => $this->contextResolver->subProjectId('contract_ai'),
            ];
        }

        // 2. Check ProjectProgress records
        $processId = $processData['id'] ?? null;

        if ($processId && ! empty($progressCertificates[$processId])) {
            return [
                'file_id' => $progressCertificates[$processId]['file_id'],
                'subproject_id' => $progressCertificates[$processId]['subproject_id'],
            ];
        }

        return [
            'file_id' => null,
            'subproject_id' => null,
        ];
    }
}

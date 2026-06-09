<?php

namespace App\Services\TrackAI;

use App\Contracts\SarasClientInterface;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class ContractService
{
    public function __construct(
        protected SarasClientInterface $sarasClient,
    ) {}

    /**
     * Sync contracts from Saras Contract AI into local database.
     *
     * Also fetches ProjectProgress records to find certificates.
     *
     * @return Collection<int, Contract>
     */
    public function syncContractsFromSaras(): Collection
    {
        $contractAiId = config('saras.subproject_ids.contract_ai');

        $response = $this->sarasClient->getProcesses($contractAiId, 1, 50);

        // Fetch ProjectProgress records to cross-reference certificates
        $progressCertificates = $this->fetchProgressCertificates();

        foreach (($response['processes'] ?? []) as $process) {
            $processId = $process['id'] ?? null;

            if (! $processId) {
                continue;
            }

            $name = $process['fields']['legalName1']
                ?? $process['metaDetails']['title']
                ?? 'Contract #'.($process['metaDetails']['displayNumber'] ?? '?');

            $milestones = $process['fields']['milestone'] ?? [];
            $certificateStatus = $this->determineCertificateStatus($process, $progressCertificates);
            $certificateFileId = $this->extractCertificateFileId($process, $progressCertificates);

            Contract::updateOrCreate(
                ['saras_process_id' => $processId],
                [
                    'name' => $name,
                    'display_number' => $process['metaDetails']['displayNumber'] ?? null,
                    'milestones' => $milestones,
                    'certificate_status' => $certificateStatus,
                    'certificate_file_id' => $certificateFileId,
                    'raw_saras_payload' => $process,
                    'last_synced_at' => now(),
                ],
            );
        }

        return Contract::orderBy('name')->get();
    }

    /**
     * List locally cached contracts, syncing from Saras if empty.
     *
     * @return Collection<int, Contract>
     */
    public function listContracts(): Collection
    {
        $contracts = Contract::orderBy('name')->get();

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
     * Fetch certificates from ProjectProgress records in Saras.
     *
     * Returns a map of contractId => certificateFileId.
     *
     * @return array<string, string>
     */
    protected function fetchProgressCertificates(): array
    {
        $certificates = [];

        try {
            $ppSubId = config('saras.subproject_ids.project_progress');
            $ppResponse = $this->sarasClient->getProcesses($ppSubId, 1, 50);

            foreach ($ppResponse['processes'] ?? [] as $pp) {
                $certFileId = $pp['fields']['certificateOfCompletion'] ?? null;
                $contractId = $pp['fields']['contractId'] ?? null;

                if (! empty($certFileId) && ! empty($contractId) && is_string($certFileId)) {
                    // Keep the latest certificate per contract (processes are ordered by creation)
                    $certificates[$contractId] = $certFileId;
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
     * @param  array<string, string>  $progressCertificates  Map of contractId => certificateFileId from ProjectProgress
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
            $hasProgress = \App\Models\ProjectProgressReport::where('contract_id', $processId)
                ->whereNotIn('progress_status', ['draft', 'failed'])
                ->exists();

            if ($hasProgress) {
                return Contract::STATUS_PENDING;
            }
        }

        return Contract::STATUS_NOT_STARTED;
    }

    /**
     * Extract certificate file ID from contract data or progress certificates.
     *
     * @param  array<string, string>  $progressCertificates  Map of contractId => certificateFileId from ProjectProgress
     */
    protected function extractCertificateFileId(array $processData, array $progressCertificates = []): ?string
    {
        // 1. Check Contract AI record
        $certificate = $processData['fields']['certificateOfCompletion'] ?? null;

        if (is_string($certificate) && ! empty($certificate)) {
            return $certificate;
        }

        if (is_array($certificate) && ! empty($certificate[0]['id'])) {
            return $certificate[0]['id'];
        }

        // 2. Check ProjectProgress records
        $processId = $processData['id'] ?? null;

        if ($processId && ! empty($progressCertificates[$processId])) {
            return $progressCertificates[$processId];
        }

        return null;
    }
}

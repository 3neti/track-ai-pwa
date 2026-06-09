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
     * @return Collection<int, Contract>
     */
    public function syncContractsFromSaras(): Collection
    {
        $contractAiId = config('saras.subproject_ids.contract_ai');

        $response = $this->sarasClient->getProcesses($contractAiId, 1, 50);

        foreach (($response['processes'] ?? []) as $process) {
            $processId = $process['id'] ?? null;

            if (! $processId) {
                continue;
            }

            $name = $process['fields']['legalName1']
                ?? $process['metaDetails']['title']
                ?? 'Contract #'.($process['metaDetails']['displayNumber'] ?? '?');

            $milestones = $process['fields']['milestone'] ?? [];
            $certificateStatus = $this->determineCertificateStatus($process);
            $certificateFileId = $this->extractCertificateFileId($process);

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
     * Determine certificate status from Saras process data.
     */
    public function determineCertificateStatus(array $processData): string
    {
        $fields = $processData['fields'] ?? [];

        // Check if certificateOfCompletion has a file UUID
        $certificate = $fields['certificateOfCompletion'] ?? null;

        if (! empty($certificate)) {
            return Contract::STATUS_AVAILABLE;
        }

        // Check if any progress reports exist for this contract
        $processId = $processData['id'] ?? null;

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
     * Extract certificate file ID from Saras process data.
     */
    protected function extractCertificateFileId(array $processData): ?string
    {
        $certificate = $processData['fields']['certificateOfCompletion'] ?? null;

        if (is_string($certificate) && ! empty($certificate)) {
            return $certificate;
        }

        if (is_array($certificate) && ! empty($certificate[0]['id'])) {
            return $certificate[0]['id'];
        }

        return null;
    }
}

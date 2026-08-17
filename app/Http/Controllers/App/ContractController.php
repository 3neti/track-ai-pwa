<?php

namespace App\Http\Controllers\App;

use App\Contracts\SarasClientInterface;
use App\Exceptions\SarasApiException;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Services\TrackAI\ContractService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class ContractController extends Controller
{
    public function __construct(
        protected ContractService $contractService,
        protected SarasClientInterface $sarasClient,
    ) {}

    /**
     * Display the contracts page.
     */
    public function index(): Response
    {
        $contracts = $this->contractService->listContracts();

        return Inertia::render('app/Contracts', [
            'contracts' => $contracts->map(fn (Contract $c) => [
                'id' => $c->id,
                'saras_process_id' => $c->saras_process_id,
                'name' => $c->name,
                'display_number' => $c->display_number,
                'milestones' => $c->milestones ?? [],
                'certificate_status' => $c->certificate_status,
                'certificate_file_id' => $c->certificate_file_id,
                'certificate_subproject_id' => $c->certificate_subproject_id,
                'last_synced_at' => $c->last_synced_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * List contracts as JSON.
     */
    public function list(): JsonResponse
    {
        $contracts = $this->contractService->listContracts();

        return response()->json([
            'success' => true,
            'contracts' => $contracts->map(fn (Contract $c) => [
                'id' => $c->id,
                'saras_process_id' => $c->saras_process_id,
                'name' => $c->name,
                'display_number' => $c->display_number,
                'milestones' => $c->milestones ?? [],
                'certificate_status' => $c->certificate_status,
                'certificate_file_id' => $c->certificate_file_id,
                'certificate_subproject_id' => $c->certificate_subproject_id,
                'last_synced_at' => $c->last_synced_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Refresh contracts from Saras.
     */
    public function refresh(): JsonResponse
    {
        try {
            $contracts = $this->contractService->syncContractsFromSaras();

            return response()->json([
                'success' => true,
                'contracts' => $contracts->map(fn (Contract $c) => [
                    'id' => $c->id,
                    'saras_process_id' => $c->saras_process_id,
                    'name' => $c->name,
                    'display_number' => $c->display_number,
                    'milestones' => $c->milestones ?? [],
                    'certificate_status' => $c->certificate_status,
                    'certificate_file_id' => $c->certificate_file_id,
                    'certificate_subproject_id' => $c->certificate_subproject_id,
                    'last_synced_at' => $c->last_synced_at?->toIso8601String(),
                ]),
                'message' => 'Contracts refreshed from Saras.',
            ]);
        } catch (SarasApiException $e) {
            if ($e->type === SarasApiException::TYPE_AUTH_FAILED) {
                return response()->json([
                    'success' => false,
                    'contracts' => [],
                    'message' => $e->getMessage(),
                ], 401);
            }

            if ($e->type === SarasApiException::TYPE_FORBIDDEN) {
                return response()->json([
                    'success' => false,
                    'contracts' => [],
                    'message' => $e->getMessage(),
                ], 403);
            }

            return response()->json([
                'success' => false,
                'contracts' => [],
                'message' => 'Saras is unavailable. Please try again.',
            ], 503);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'contracts' => [],
                'message' => 'Unable to load contracts. Please try again.',
            ]);
        }
    }

    /**
     * Get certificate download info for a contract.
     */
    public function certificate(Contract $contract): JsonResponse
    {
        if (! $contract->isCertificateAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Certificate is not yet available for this contract.',
            ], 404);
        }

        if ($contract->certificate_url) {
            return response()->json([
                'success' => true,
                'download_url' => $contract->certificate_url,
            ]);
        }

        // Fetch a scoped download URL from Saras.
        if ($contract->certificate_file_id) {
            try {
                $certificateSubProjectId = $contract->certificate_subproject_id
                    ?? (data_get($contract->raw_saras_payload, 'fields.certificateOfCompletion')
                        ? config('saras.subproject_ids.contract_ai')
                        : config('saras.subproject_ids.project_progress'));

                $response = $this->sarasClient->getFileUrl(
                    subProjectId: $certificateSubProjectId,
                    fileId: $contract->certificate_file_id,
                );

                $urls = $response['urls'] ?? $response['files'] ?? $response['data'] ?? [];
                $url = $urls[0]['url'] ?? $urls[0]['downloadUrl'] ?? null;

                if ($url) {
                    return response()->json([
                        'success' => true,
                        'download_url' => $url,
                    ]);
                }
            } catch (\Exception $e) {
                // Fall through to info response
            }
        }

        return response()->json([
            'success' => true,
            'download_url' => null,
            'certificate_file_id' => $contract->certificate_file_id,
            'message' => 'Certificate file exists but download URL could not be resolved.',
        ]);
    }
}

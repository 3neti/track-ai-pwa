<?php

namespace App\Http\Controllers\App;

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
                    'last_synced_at' => $c->last_synced_at?->toIso8601String(),
                ]),
                'message' => 'Contracts refreshed from Saras.',
            ]);
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

        // Certificate file exists in Saras but no direct download API is available yet
        return response()->json([
            'success' => true,
            'download_url' => null,
            'certificate_file_id' => $contract->certificate_file_id,
            'message' => 'Certificate is available on the Saras dashboard. File download API is pending Saras deployment.',
        ]);
    }
}

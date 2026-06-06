<?php

namespace App\Http\Controllers\Developer;

use App\Http\Controllers\Controller;
use App\Models\ApiTrace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SarasApiXrayController extends Controller
{
    /**
     * Display the X-Ray page.
     */
    public function index(): Response
    {
        return Inertia::render('developer/SarasApiXray');
    }

    /**
     * API: list traces with filters.
     */
    public function traces(Request $request): JsonResponse
    {
        $query = ApiTrace::query()
            ->where('provider', 'saras')
            ->with('user:id,name')
            ->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('endpoint', 'like', "%{$search}%")
                    ->orWhere('operation', 'like', "%{$search}%")
                    ->orWhere('trace_id', 'like', "%{$search}%")
                    ->orWhere('error_message', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $status = $request->input('status');
            if ($status === 'success') {
                $query->where('status_code', '>=', 200)->where('status_code', '<', 300);
            } elseif ($status === 'error') {
                $query->where('status_code', '>=', 400);
            }
        }

        if ($request->filled('operation')) {
            $query->where('operation', $request->input('operation'));
        }

        if ($request->filled('method')) {
            $query->where('method', $request->input('method'));
        }

        $traces = $query->paginate(25);

        return response()->json([
            'success' => true,
            'data' => $traces->items(),
            'meta' => [
                'current_page' => $traces->currentPage(),
                'last_page' => $traces->lastPage(),
                'total' => $traces->total(),
            ],
        ]);
    }

    /**
     * API: get single trace detail.
     */
    public function show(ApiTrace $apiTrace): JsonResponse
    {
        return response()->json([
            'success' => true,
            'trace' => $apiTrace->load('user:id,name'),
        ]);
    }
}

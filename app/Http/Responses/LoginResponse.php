<?php

namespace App\Http\Responses;

use App\Services\Saras\SarasProjectContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    public function __construct(
        protected SarasProjectContextResolver $contextResolver,
    ) {}

    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return response()->json(['two_factor' => false]);
        }

        if ($request->hasSession() && $request->session()->pull('saras_face_registration_required', false)) {
            return redirect()->route('face-register');
        }

        if ($this->shouldChooseProject($request)) {
            return redirect()->route('app.project-context');
        }

        return redirect()->intended(config('fortify.home'));
    }

    protected function shouldChooseProject(Request $request): bool
    {
        $user = $request->user();

        if (! $user || $user->selected_saras_project_id) {
            return false;
        }

        return count($this->contextResolver->availableProjectOptions($user)) > 1;
    }
}

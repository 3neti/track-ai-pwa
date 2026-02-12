<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FaceLoginController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $username = $request->query('username', '');

        if (empty($username)) {
            return redirect()->route('login');
        }

        return Inertia::render('auth/FaceLogin', [
            'username' => $username,
        ]);
    }
}

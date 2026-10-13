<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AutenticarPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('portal')->check()) {
            return redirect()->route('portal.login');
        }

        return $next($request);
    }
}

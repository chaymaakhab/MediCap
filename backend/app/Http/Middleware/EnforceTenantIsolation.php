<?php

namespace App\Http\Middleware;

use App\Services\CliniqoAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce Strict Multi-Tenant, Multi-Branch & Server-Side RBAC Isolation
 */
class EnforceTenantIsolation
{
    public function handle(Request $request, Closure $next): Response
    {
        // Handle CORS preflight cleanly for local web frontend (:8000 -> :8001)
        if ($request->isMethod('OPTIONS')) {
            return response('', 204)->withHeaders($this->corsHeaders());
        }

        // Login and profile discovery are public; logout still requires an active staff token.
        $path = $request->path();
        if (
            in_array($path, ['api/v1/auth/profiles', 'api/v1/auth/login'], true)
            || str_starts_with($path, 'api/v1/portal/')
        ) {
            $response = $next($request);
            return $this->attachHeaders($response);
        }

        $ctx = CliniqoAuthService::resolveStaffContext($request);
        $request->attributes->set('cliniqo_ctx', $ctx);
        $request->attributes->set('active_tenant_id', $ctx['tenant_id']);
        $request->attributes->set('active_branch_id', $ctx['branch_id']);

        $response = $next($request);
        return $this->attachHeaders($response);
    }

    private function corsHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Accept, Authorization, X-Auth-Token, X-Tenant-ID, X-Branch-ID',
        ];
    }

    private function attachHeaders(Response $response): Response
    {
        foreach ($this->corsHeaders() as $k => $v) {
            $response->headers->set($k, $v);
        }
        return $response;
    }
}

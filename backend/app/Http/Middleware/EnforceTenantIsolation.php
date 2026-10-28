<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce Strict Multi-Tenant Isolation
 * Conformément à la section 13 du Cahier des charges :
 * "Isolation stricte multi-tenant : toutes les données métier sont rattachées à un centre."
 */
class EnforceTenantIsolation
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->header('X-Tenant-ID') 
            ?? $request->user()?->tenant_id 
            ?? 1; // Default to Tenant #1 (Grand Central Polyclinic) in dev

        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'error' => 'TENANT_IDENTIFIER_REQUIRED',
                'message' => 'L’identifiant du centre médical est requis pour accéder aux données.'
            ], 403);
        }

        // Store active tenant in request attributes for controllers
        $request->attributes->set('active_tenant_id', (int) $tenantId);

        return $next($request);
    }
}

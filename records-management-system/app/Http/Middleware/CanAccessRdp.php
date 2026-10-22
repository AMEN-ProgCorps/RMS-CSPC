<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanAccessRdp
{
    public function handle(Request $request, Closure $next): Response
    {
        if (\App\Helpers\MobileHelper::isMobile($request)) {
            return redirect()->route('portal');
        }

        $perms = auth()->user()?->permissions;


        if (! $perms || (! $perms->is_sadm && ! $perms->can_access_rdp)) {
            if (! $this->allowsDcsAppraisal($request, $perms)) {
                return redirect()->route('portal');
            }
        }

        // Check if Records Disposition Program is active globally
        $subsystemsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems';
        $isActive = \DB::table($subsystemsTbl)
            ->where('subsystem_name', 'Records Disposition Program')
            ->value('is_active');

        if (! $isActive && ! $perms->is_sadm) {
            return redirect()->route('portal');
        }

        return $next($request);
    }

    /**
     * Document controllers open the admin appraisal. A distributed office opens
     * only the intake that was forwarded to that office after it was received.
     */
    private function allowsDcsAppraisal(Request $request, mixed $perms): bool
    {
        if (! $perms || ! $request->routeIs('rdp.add-records.inventory-and-appraisal')) {
            return false;
        }

        $requestId = (int) $request->query('dcs_request_id');
        if ($requestId > 0) {
            return \App\Helpers\RegisterQueryHelper::isDocumentControlHead()
                || (bool) ($perms->can_access_dcs_admin ?? false);
        }

        $intakeId = (int) $request->query('prefill_intake_id');
        if ($intakeId < 1) {
            return false;
        }

        $intake = \Illuminate\Support\Facades\DB::table('rdp_received_documents')
            ->where('id', $intakeId)
            ->first();
        if (! $intake || ($intake->source_subsystem ?? '') !== 'DCS' || ($intake->status ?? '') === 'appraised') {
            return false;
        }

        $officeCode = \App\Helpers\RegisterQueryHelper::currentOfficeCode();
        if (! $officeCode || strcasecmp((string) ($intake->target_office ?? ''), $officeCode) !== 0) {
            return false;
        }

        $meta = is_string($intake->metadata ?? null)
            ? json_decode($intake->metadata, true)
            : (array) ($intake->metadata ?? []);
        $dcsRequestId = (int) ($meta['dcs_request_id'] ?? 0);
        $officeId = \App\Helpers\RegisterQueryHelper::currentOfficeId();
        if ($dcsRequestId < 1 || ! $officeId) {
            return false;
        }

        if (! \Illuminate\Support\Facades\Schema::hasColumn('dcs_distribution_offices', 'office_received_at')) {
            return true;
        }

        return \Illuminate\Support\Facades\DB::table('dcs_distribution_offices as doff')
            ->join('dcs_document_distribution as dist', 'dist.id', '=', 'doff.distribution_id')
            ->where('dist.request_id', $dcsRequestId)
            ->where('doff.office_id', $officeId)
            ->whereNotNull('doff.office_received_at')
            ->exists();
    }
}

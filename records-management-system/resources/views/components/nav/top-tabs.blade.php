@props(['system' => 'dts'])

@php
    $currentRoute = request()->route()?->getName() ?? '';
    $tabs = [];
    $sectionTitle = '';
    $sectionIcon = '';

    if ($system === 'dts') {
        $perms = auth()->user()?->permissions;
        $isSadm = $perms?->is_sadm ?? false;
        $canInternal = $isSadm || ($perms?->can_dts_use_internal ?? false);
        $canExternal = $isSadm || ($perms?->can_dts_use_external ?? false);
        $canApplication = $isSadm || ($perms?->can_dts_use_application ?? false);
        $canIssuance = $isSadm || ($perms?->can_dts_use_issuance ?? false);

        // 1. Transactions Section
        if (request()->routeIs('dts') || request()->routeIs('dts.incoming') || request()->routeIs('dts.my-transactions') || request()->routeIs('dts.received') || request()->routeIs('dts.forwarded')) {
            $sectionTitle = 'Transactions';
            $tabs = [
                [
                    'label' => 'My Transactions',
                    'url' => route('dts.my-transactions'),
                    'active' => request()->routeIs('dts.my-transactions'),
                ],
                [
                    'label' => 'Incoming Transactions',
                    'url' => route('dts.incoming'),
                    'active' => request()->routeIs('dts.incoming') || (request()->routeIs('dts') && !request()->routeIs('dts.*')),
                ],
                [
                    'label' => 'Received Transactions',
                    'url' => route('dts.received'),
                    'active' => request()->routeIs('dts.received'),
                ],
                [
                    'label' => 'Forwarded Transactions',
                    'url' => route('dts.forwarded'),
                    'active' => request()->routeIs('dts.forwarded'),
                ],
            ];
        }
        // 2. Scanner Section
        elseif (request()->routeIs('dts.scanner') || request()->routeIs('dts.receive')) {
            $sectionTitle = 'Scanner';
            $tabs = [
                [
                    'label' => 'Scanner',
                    'url' => route('dts.scanner'),
                    'active' => request()->routeIs('dts.scanner'),
                ],
            ];
        }
        // 3. Create Transaction Section
        elseif (request()->routeIs('dts.create.*')) {
            $sectionTitle = 'Create Transaction';
            if ($canInternal) {
                $tabs[] = [
                    'label' => 'Internal Transaction',
                    'url' => route('dts.create.internal'),
                    'active' => request()->routeIs('dts.create.internal'),
                ];
            }
            if ($canExternal) {
                $tabs[] = [
                    'label' => 'External Transaction',
                    'url' => route('dts.create.external'),
                    'active' => request()->routeIs('dts.create.external'),
                ];
            }
            if ($canApplication) {
                $tabs[] = [
                    'label' => 'Application Letters',
                    'url' => route('dts.create.application-letters'),
                    'active' => request()->routeIs('dts.create.application-letters'),
                ];
            }
            if ($canIssuance) {
                $tabs[] = [
                    'label' => 'Issuances',
                    'url' => route('dts.create.issuances'),
                    'active' => request()->routeIs('dts.create.issuances'),
                ];
            }
        }
        // 4. List of Transactions Section
        elseif (request()->routeIs('dts.list.*')) {
            $sectionTitle = 'List of Transactions';
            if ($canInternal) {
                $tabs[] = [
                    'label' => 'Internal Transaction',
                    'url' => route('dts.list.internal'),
                    'active' => request()->routeIs('dts.list.internal'),
                ];
            }
            if ($canExternal) {
                $tabs[] = [
                    'label' => 'External Transaction',
                    'url' => route('dts.list.external'),
                    'active' => request()->routeIs('dts.list.external'),
                ];
            }
            if ($canApplication) {
                $tabs[] = [
                    'label' => 'Application Letters',
                    'url' => route('dts.list.application-letters'),
                    'active' => request()->routeIs('dts.list.application-letters'),
                ];
            }
            if ($canIssuance) {
                $tabs[] = [
                    'label' => 'Issuances',
                    'url' => route('dts.list.issuances'),
                    'active' => request()->routeIs('dts.list.issuances'),
                ];
            }
        }
        // 5. Transaction History Section
        elseif (request()->routeIs('dts.history.*')) {
            $sectionTitle = 'Transaction History';
            if ($canInternal) {
                $tabs[] = [
                    'label' => 'Internal Transaction',
                    'url' => route('dts.history.internal'),
                    'active' => request()->routeIs('dts.history.internal'),
                ];
            }
            if ($canExternal) {
                $tabs[] = [
                    'label' => 'External Transaction',
                    'url' => route('dts.history.external'),
                    'active' => request()->routeIs('dts.history.external'),
                ];
            }
            if ($canApplication) {
                $tabs[] = [
                    'label' => 'Application Letters',
                    'url' => route('dts.history.application-letters'),
                    'active' => request()->routeIs('dts.history.application-letters'),
                ];
            }
            if ($canIssuance) {
                $tabs[] = [
                    'label' => 'Issuances',
                    'url' => route('dts.history.issuances'),
                    'active' => request()->routeIs('dts.history.issuances'),
                ];
            }
        }
    } elseif ($system === 'rdp') {
        // 1. Dashboard Section
        if (request()->routeIs('rdp')) {
            $sectionTitle = 'Dashboard';
            $tabs = [
                [
                    'label' => 'Dashboard',
                    'url' => route('rdp'),
                    'active' => request()->routeIs('rdp'),
                ],
            ];
        }
        // 2. Add Records Section
        elseif (request()->routeIs('rdp.add-records.*')) {
            $sectionTitle = 'Add Records';
            $tabs = [
                [
                    'label' => 'Inventory and Appraisal',
                    'url' => route('rdp.add-records.inventory-and-appraisal'),
                    'active' => request()->routeIs('rdp.add-records.inventory-and-appraisal'),
                ],
                [
                    'label' => 'Records and Disposition Schedule',
                    'url' => route('rdp.add-records.records-and-disposition-schedule'),
                    'active' => request()->routeIs('rdp.add-records.records-and-disposition-schedule'),
                ],
            ];
        }
        // 3. Draft Section
        elseif (request()->routeIs('rdp.draft.*')) {
            $sectionTitle = 'Draft';
            $tabs = [
                [
                    'label' => 'Inventory and Appraisal',
                    'url' => route('rdp.draft.inventory-and-appraisal'),
                    'active' => request()->routeIs('rdp.draft.inventory-and-appraisal'),
                ],
                [
                    'label' => 'Records and Disposition Schedule',
                    'url' => route('rdp.draft.records-and-disposition-schedule'),
                    'active' => request()->routeIs('rdp.draft.records-and-disposition-schedule'),
                ],
            ];
        }
        // 4. Pending Section
        elseif (request()->routeIs('rdp.pending.*')) {
            $sectionTitle = 'Pending';
            $tabs = [
                [
                    'label' => 'List',
                    'url' => route('rdp.pending.list'),
                    'active' => request()->routeIs('rdp.pending.list'),
                ],
                [
                    'label' => 'For Approval',
                    'url' => route('rdp.pending.for-approval'),
                    'active' => request()->routeIs('rdp.pending.for-approval'),
                ],
            ];
        }
        // 5. References Section
        elseif (request()->routeIs('rdp.references.*')) {
            $sectionTitle = 'References';
            $refTypes = \Illuminate\Support\Facades\DB::table('rdp_record_series_type')
                ->where('is_active', true)
                ->orderBy('id', 'asc')
                ->get();
            foreach ($refTypes as $typeItem) {
                $isItemActive = request()->routeIs('rdp.references.show') && strtolower(request()->route('type')) === strtolower($typeItem->shorted_type);
                $tabs[] = [
                    'label' => $typeItem->shorted_type . ' (' . $typeItem->type_name . ')',
                    'url' => route('rdp.references.show', ['type' => strtolower($typeItem->shorted_type)]),
                    'active' => $isItemActive,
                ];
            }
        }
        // 6. Reports Section
        elseif (request()->routeIs('rdp.reports.*')) {
            $sectionTitle = 'Reports';
            $tabs = [
                [
                    'label' => 'NAP Form 1',
                    'url' => route('rdp.reports.nap-form-1'),
                    'active' => request()->routeIs('rdp.reports.nap-form-1'),
                ],
                [
                    'label' => 'NAP Form 2',
                    'url' => route('rdp.reports.nap-form-2'),
                    'active' => request()->routeIs('rdp.reports.nap-form-2'),
                ],
                [
                    'label' => 'NAP Form 3',
                    'url' => route('rdp.reports.nap-form-3'),
                    'active' => request()->routeIs('rdp.reports.nap-form-3'),
                ],
            ];
        }
        // 7. Manage Files Section
        elseif (request()->routeIs('rdp.manage-files')) {
            $sectionTitle = 'Manage Files';
            $tabs = [
                [
                    'label' => 'Manage Files',
                    'url' => route('rdp.manage-files'),
                    'active' => request()->routeIs('rdp.manage-files'),
                ],
            ];
        }
    }
@endphp

@if(!empty($tabs))
<div class="top-nav-tabs-wrapper" data-system="{{ $system }}">
    <div class="top-nav-tabs-container">
        @foreach($tabs as $tab)
            <a href="{{ $tab['url'] }}" 
               class="top-nav-tab-item {{ $tab['active'] ? 'active' : '' }}"
               title="{{ $tab['label'] }}">
                <span class="top-nav-tab-label">{{ $tab['label'] }}</span>
                @if($tab['active'])
                    <span class="top-nav-tab-indicator"></span>
                @endif
            </a>
        @endforeach
    </div>
</div>
@endif

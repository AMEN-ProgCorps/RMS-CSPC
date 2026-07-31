@php
    $refTypes = \Illuminate\Support\Facades\DB::table('rdp_record_series_type')
        ->where('is_active', true)
        ->orderBy('id', 'asc')
        ->get();
@endphp

<div id="rdp-dashboard-id" class="button-section-container">
    <div class="button-container {{ request()->routeIs('rdp') ? 'force-active' : '' }}" onclick="proccedto('{{ route('rdp') }}')">
        <div class="button-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="29" height="29" viewBox="0 0 29 29" fill="none">
                <path d="M4.83333 15.7083H13.2917V4.83331H4.83333V15.7083ZM4.83333 24.1666H13.2917V17.9166H4.83333V24.1666ZM15.7083 24.1666H24.1667V13.2916H15.7083V24.1666ZM15.7083 4.83331V11.0833H24.1667V4.83331H15.7083Z" fill="#4F4F4F"/>
            </svg>
        </div>
        <div class="button-label">
            <span>Dashboard</span>
        </div>
    </div>
</div>

<div id="rdp-add-records-id" class="button-section-container {{ request()->routeIs('rdp.add-records.*') ? 'show' : '' }}">
    <div class="button-container {{ request()->routeIs('rdp.add-records.*') ? 'force-active' : '' }}" onclick="showButtonSection('rdp-add-records-id')">
        <div class="button-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="29" height="29" viewBox="0 0 29 29" fill="none">
                <path d="M24.1667 15.7083H15.7083V24.1667H13.2917V15.7083H4.83333V13.2917H13.2917V4.83334H15.7083V13.2917H24.1667V15.7083Z" fill="#4F4F4F"/>
            </svg>
        </div>
        <div class="button-label">
            <span>Add Records</span>
        </div>
        <div class="show-arrow">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="11" viewBox="0 0 18 11" fill="none">
                <path d="M2.03223 9.76953L2.20898 9.59277L8.8125 2.98828L15.418 9.59375L15.5947 9.77148L15.7715
                9.59375L17.0967 8.26758L17.2734 8.09082L17.0967 7.91406L9.875 0.69043H9.87402C9.73533 0.551169
                9.57112 0.439765 9.38965 0.364258C9.20774 0.28857 9.01246 0.250036 8.81543 0.25C8.61847 0.25
                8.42308 0.28866 8.24121 0.364258C8.05943 0.439892 7.89373 0.550835 7.75488 0.69043L0.530273
                7.91406L0.353516 8.09082L2.03223 9.76953Z" fill="#646464" stroke="#646464" stroke-width="0.5"/>
            </svg>
        </div>
    </div>
    <div class="functions-container">
        <div class="function-button {{ request()->routeIs('rdp.add-records.inventory-and-appraisal') ? 'force-active' : '' }}" onclick="proccedto('{{ route('rdp.add-records.inventory-and-appraisal') }}')">Inventory and Appraisal</div>
        <div class="function-button {{ request()->routeIs('rdp.add-records.records-and-disposition-schedule') ? 'force-active' : '' }}" onclick="proccedto('{{ route('rdp.add-records.records-and-disposition-schedule') }}')">Records and Disposition Schedule</div>
    </div>
</div>

<div id="rdp-reports-id" class="button-section-container {{ request()->routeIs('rdp.reports.*') ? 'show' : '' }}">
    <div class="button-container {{ request()->routeIs('rdp.reports.*') ? 'force-active' : '' }}" onclick="showButtonSection('rdp-reports-id')">
        <div class="button-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="29" height="29" viewBox="0 0 29 29" fill="none">
                <path d="M22.9167 3.625H6.08333C4.76667 3.625 3.625 4.76667 3.625 6.08333V22.9167C3.625 24.2333 4.76667 25.375 6.08333 25.375H22.9167C24.2333 25.375 25.375 24.2333 25.375 22.9167V6.08333C25.375 4.76667 24.2333 3.625 22.9167 3.625ZM11 19.6875H8.54167V12.0833H11V19.6875ZM15.7292 19.6875H13.2708V9.375H15.7292V19.6875ZM20.4583 19.6875H18V14.5417H20.4583V19.6875Z" fill="#4F4F4F"/>
            </svg>
        </div>
        <div class="button-label">
            <span>Reports</span>
        </div>
        <div class="show-arrow">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="11" viewBox="0 0 18 11" fill="none">
                <path d="M2.03223 9.76953L2.20898 9.59277L8.8125 2.98828L15.418 9.59375L15.5947 9.77148L15.7715
                9.59375L17.0967 8.26758L17.2734 8.09082L17.0967 7.91406L9.875 0.69043H9.87402C9.73533 0.551169
                9.57112 0.439765 9.38965 0.364258C9.20774 0.28857 9.01246 0.250036 8.81543 0.25C8.61847 0.25
                8.42308 0.28866 8.24121 0.364258C8.05943 0.439892 7.89373 0.550835 7.75488 0.69043L0.530273
                7.91406L0.353516 8.09082L2.03223 9.76953Z" fill="#646464" stroke="#646464" stroke-width="0.5"/>
            </svg>
        </div>
    </div>
    <div class="functions-container">
        <div class="function-button {{ request()->routeIs('rdp.reports.nap-form-1') ? 'force-active' : '' }}" onclick="proccedto('{{ route('rdp.reports.nap-form-1') }}')">NAP Form 1</div>
        <div class="function-button {{ request()->routeIs('rdp.reports.nap-form-2') ? 'force-active' : '' }}" onclick="proccedto('{{ route('rdp.reports.nap-form-2') }}')">NAP Form 2</div>
        <div class="function-button {{ request()->routeIs('rdp.reports.nap-form-3') ? 'force-active' : '' }}" onclick="proccedto('{{ route('rdp.reports.nap-form-3') }}')">NAP Form 3</div>
    </div>
</div>

<div id="rdp-references-id" class="button-section-container {{ request()->routeIs('rdp.references.*') ? 'show' : '' }}">
    <div class="button-container {{ request()->routeIs('rdp.references.*') ? 'force-active' : '' }}" onclick="showButtonSection('rdp-references-id')">
        <div class="button-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="29" height="29" viewBox="0 0 24 24" fill="none">
                <path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H8V4h12v12z" fill="#4F4F4F"/>
            </svg>
        </div>
        <div class="button-label">
            <span>References</span>
        </div>
        <div class="show-arrow">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="11" viewBox="0 0 18 11" fill="none">
                <path d="M2.03223 9.76953L2.20898 9.59277L8.8125 2.98828L15.418 9.59375L15.5947 9.77148L15.7715
                9.59375L17.0967 8.26758L17.2734 8.09082L17.0967 7.91406L9.875 0.69043H9.87402C9.73533 0.551169
                9.57112 0.439765 9.38965 0.364258C9.20774 0.28857 9.01246 0.250036 8.81543 0.25C8.61847 0.25
                8.42308 0.28866 8.24121 0.364258C8.05943 0.439892 7.89373 0.550835 7.75488 0.69043L0.530273
                7.91406L0.353516 8.09082L2.03223 9.76953Z" fill="#646464" stroke="#646464" stroke-width="0.5"/>
            </svg>
        </div>
    </div>
    <div class="functions-container">
        @foreach($refTypes as $typeItem)
            @php
                $isItemActive = request()->routeIs('rdp.references.show') && strtolower(request()->route('type')) === strtolower($typeItem->shorted_type);
            @endphp
            <div class="function-button {{ $isItemActive ? 'force-active' : '' }}" onclick="proccedto('{{ route('rdp.references.show', ['type' => strtolower($typeItem->shorted_type)]) }}')">
                {{ $typeItem->shorted_type }} ({{ $typeItem->type_name }})
            </div>
        @endforeach
    </div>
</div>

<div id="rdp-pending-id" class="button-section-container {{ request()->routeIs('rdp.pending.*') ? 'show' : '' }}">
    <div class="button-container {{ request()->routeIs('rdp.pending.*') ? 'force-active' : '' }}" onclick="showButtonSection('rdp-pending-id')">
        <div class="button-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="29" height="29" viewBox="0 0 24 24" fill="none">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 14h-2v-2h2v2zm0-4h-2V7h2v5z" fill="#4F4F4F"/>
            </svg>
        </div>
        <div class="button-label">
            <span>Pending</span>
        </div>
        <div class="show-arrow">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="11" viewBox="0 0 18 11" fill="none">
                <path d="M2.03223 9.76953L2.20898 9.59277L8.8125 2.98828L15.418 9.59375L15.5947 9.77148L15.7715
                9.59375L17.0967 8.26758L17.2734 8.09082L17.0967 7.91406L9.875 0.69043H9.87402C9.73533 0.551169
                9.57112 0.439765 9.38965 0.364258C9.20774 0.28857 9.01246 0.250036 8.81543 0.25C8.61847 0.25
                8.42308 0.28866 8.24121 0.364258C8.05943 0.439892 7.89373 0.550835 7.75488 0.69043L0.530273
                7.91406L0.353516 8.09082L2.03223 9.76953Z" fill="#646464" stroke="#646464" stroke-width="0.5"/>
            </svg>
        </div>
    </div>
    <div class="functions-container">
        <div class="function-button {{ request()->routeIs('rdp.pending.list') ? 'force-active' : '' }}" onclick="proccedto('{{ route('rdp.pending.list') }}')">List</div>
        <div class="function-button {{ request()->routeIs('rdp.pending.for-approval') ? 'force-active' : '' }}" onclick="proccedto('{{ route('rdp.pending.for-approval') }}')">For Approval</div>
    </div>
</div>

<div id="rdp-draft-id" class="button-section-container {{ request()->routeIs('rdp.draft.*') ? 'show' : '' }}">
    <div class="button-container {{ request()->routeIs('rdp.draft.*') ? 'force-active' : '' }}" onclick="showButtonSection('rdp-draft-id')">
        <div class="button-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="29" height="29" viewBox="0 0 24 24" fill="none">
                <path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z" fill="#4F4F4F"/>
            </svg>
        </div>
        <div class="button-label">
            <span>Draft</span>
        </div>
        <div class="show-arrow">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="11" viewBox="0 0 18 11" fill="none">
                <path d="M2.03223 9.76953L2.20898 9.59277L8.8125 2.98828L15.418 9.59375L15.5947 9.77148L15.7715
                9.59375L17.0967 8.26758L17.2734 8.09082L17.0967 7.91406L9.875 0.69043H9.87402C9.73533 0.551169
                9.57112 0.439765 9.38965 0.364258C9.20774 0.28857 9.01246 0.250036 8.81543 0.25C8.61847 0.25
                8.42308 0.28866 8.24121 0.364258C8.05943 0.439892 7.89373 0.550835 7.75488 0.69043L0.530273
                7.91406L0.353516 8.09082L2.03223 9.76953Z" fill="#646464" stroke="#646464" stroke-width="0.5"/>
            </svg>
        </div>
    </div>
    <div class="functions-container">
        <div class="function-button {{ request()->routeIs('rdp.draft.inventory-and-appraisal') ? 'force-active' : '' }}" onclick="proccedto('{{ route('rdp.draft.inventory-and-appraisal') }}')">Inventory and Appraisal</div>
        <div class="function-button {{ request()->routeIs('rdp.draft.records-and-disposition-schedule') ? 'force-active' : '' }}" onclick="proccedto('{{ route('rdp.draft.records-and-disposition-schedule') }}')">Records and Disposition Schedule</div>
    </div>
</div>

<div id="rdp-manage-files-id" class="button-section-container">
    <div class="button-container {{ request()->routeIs('rdp.manage-files') ? 'force-active' : '' }}" onclick="proccedto('{{ route('rdp.manage-files') }}')">
        <div class="button-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="29" height="29" viewBox="0 0 24 24" fill="none">
                <path d="M19 3H5C3.89 3 3 3.89 3 5V19C3 20.1 3.89 21 5 21H19C20.1 21 21 20.1 21 19V5C21 3.89 20.1 3 19 3ZM19 19H5V5H19V19ZM17 14H12V17H17V14ZM10 17H7V7H10V17ZM17 11H12V7H17V11Z" fill="#4F4F4F"/>
            </svg>
        </div>
        <div class="button-label">
            <span>Manage Files</span>
        </div>
    </div>
</div>


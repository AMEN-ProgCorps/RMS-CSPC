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

<div id="rdp-pending-id" class="button-section-container {{ request()->routeIs('rdp.pending.*') ? 'show' : '' }}">
    <div class="button-container {{ request()->routeIs('rdp.pending.*') ? 'force-active' : '' }}" onclick="showButtonSection('rdp-pending-id')">
        <div class="button-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 25 25" fill="none">
<path d="M12.3854 0C9.93582 0 7.54123 0.726392 5.50445 2.08732C3.46768 3.44825 1.88021 5.38258 0.942789 7.64572C0.00536638 9.90886 -0.239906 12.3992 0.237988 14.8017C0.715882 17.2042 1.89548 19.4111 3.62761 21.1432C5.35974 22.8754 7.56661 24.055 9.96915 24.5329C12.3717 25.0107 14.862 24.7655 17.1251 23.8281C19.3883 22.8906 21.3226 21.3032 22.6835 19.2664C24.0444 17.2296 24.7708 14.835 24.7708 12.3854C24.7676 9.10158 23.4617 5.95316 21.1397 3.63114C18.8177 1.30912 15.6693 0.00319906 12.3854 0ZM11.1771 6.04167C11.1771 5.7212 11.3044 5.41385 11.531 5.18725C11.7576 4.96064 12.065 4.83333 12.3854 4.83333C12.7059 4.83333 13.0132 4.96064 13.2398 5.18725C13.4664 5.41385 13.5938 5.7212 13.5938 6.04167V13.6904C13.5938 14.0109 13.4664 14.3182 13.2398 14.5448C13.0132 14.7714 12.7059 14.8988 12.3854 14.8988C12.065 14.8988 11.7576 14.7714 11.531 14.5448C11.3044 14.3182 11.1771 14.0109 11.1771 13.6904V6.04167ZM12.3854 19.43C12.0867 19.43 11.7947 19.3414 11.5463 19.1754C11.2979 19.0095 11.1043 18.7736 10.99 18.4976C10.8757 18.2216 10.8457 17.9179 10.904 17.6249C10.9623 17.3319 11.1062 17.0628 11.3174 16.8516C11.5286 16.6403 11.7978 16.4965 12.0908 16.4382C12.3837 16.3799 12.6874 16.4098 12.9634 16.5241C13.2394 16.6385 13.4753 16.8321 13.6413 17.0804C13.8073 17.3288 13.8958 17.6209 13.8958 17.9196C13.8928 18.3087 13.7386 18.6813 13.4657 18.9587C13.1928 19.2361 12.8227 19.3964 12.4338 19.4058L12.3854 19.43Z" fill="#4F4F4F"/>
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

<div id="rdp-references-id" class="button-section-container {{ request()->routeIs('rdp.references.*') ? 'show' : '' }}">
    <div class="button-container {{ request()->routeIs('rdp.references.*') ? 'force-active' : '' }}" onclick="showButtonSection('rdp-references-id')">
        <div class="button-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="29" height="29" viewBox="0 0 29 29" fill="none">
                <path d="M13.2111 1.45001H27.5951V27.55H13.0951V15.4817L9.8544 27.5732L1.4502 25.3199L8.2072 0.108765L13.2097 1.45001H13.2111ZM15.9951 24.65H18.8951V20.3H15.9951V24.65ZM21.7951 24.65H24.6951V20.3H21.7951V24.65ZM5.00415 23.2696L7.80265 24.0193L8.9293 19.8172L6.12935 19.0661L5.00415 23.2696ZM15.9951 4.65886V17.4H18.8951V4.35001H16.0778L15.9951 4.65886ZM21.7951 17.4H24.6951V4.35001H21.7951V17.4ZM6.879 16.2661L9.6775 17.0158L13.056 4.41091L10.2575 3.66126L6.879 16.2661Z" fill="#4F4F4F"/>
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

<div id="rdp-reports-id" class="button-section-container {{ request()->routeIs('rdp.reports.*') ? 'show' : '' }}">
    <div class="button-container {{ request()->routeIs('rdp.reports.*') ? 'force-active' : '' }}" onclick="showButtonSection('rdp-reports-id')">
        <div class="button-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="29" height="29" viewBox="0 0 29 29" fill="none">
<path fill-rule="evenodd" clip-rule="evenodd" d="M4.53125 1.89404C4.2909 1.89404 4.06039 1.98952 3.89043 2.15948C3.72048 2.32943 3.625 2.55994 3.625 2.80029V22.5747C3.625 22.815 3.72048 23.0455 3.89043 23.2155C4.06039 23.3854 4.2909 23.4809 4.53125 23.4809H20.8438C21.0841 23.4809 21.3146 23.3854 21.4846 23.2155C21.6545 23.0455 21.75 22.815 21.75 22.5747V9.72223C21.7502 9.60054 21.726 9.48005 21.6786 9.36795C21.6313 9.25585 21.5619 9.15442 21.4745 9.06973L14.3387 2.15323C14.1699 1.98867 13.9437 1.89635 13.7079 1.89586L4.53125 1.89404ZM18.6071 8.81598L14.6142 4.94267V8.81598H18.6071ZM10.875 9.96873H7.25V8.15623H10.875V9.96873ZM18.125 14.5H7.25V12.6875H18.125V14.5ZM7.25 19.0312H18.125V17.2187H7.25V19.0312Z" fill="#4F4F4F"/>
<path d="M23.5625 13.5938V25.375H8.15625V27.1875H24.4688C24.7091 27.1875 24.9396 27.092 25.1096 26.9221C25.2795 26.7521 25.375 26.5216 25.375 26.2812V13.5938H23.5625Z" fill="#4F4F4F"/>
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

<div id="rdp-manage-files-id" class="button-section-container">
    <div class="button-container {{ request()->routeIs('rdp.manage-files') ? 'force-active' : '' }}" onclick="proccedto('{{ route('rdp.manage-files') }}')">
        <div class="button-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="29" height="29" viewBox="0 0 29 29" fill="none">
<path fill-rule="evenodd" clip-rule="evenodd" d="M17.2188 7.25C16.738 7.25 16.277 7.44096 15.9371 7.78087C15.5972 8.12078 15.4063 8.5818 15.4063 9.0625V10.4219V11.7812C15.4063 12.262 15.5972 12.723 15.9371 13.0629C16.277 13.4028 16.738 13.5938 17.2188 13.5938H19.9375C20.4182 13.5938 20.8792 13.4028 21.2191 13.0629C21.559 12.723 21.75 12.262 21.75 11.7812V9.0625C21.75 8.5818 21.559 8.12078 21.2191 7.78087C20.8792 7.44096 20.4182 7.25 19.9375 7.25H17.2188ZM17.2188 15.4062C16.738 15.4062 16.277 15.5972 15.9371 15.9371C15.5972 16.277 15.4063 16.738 15.4063 17.2188V19.9375C15.4063 20.4182 15.5972 20.8792 15.9371 21.2191C16.277 21.559 16.738 21.75 17.2188 21.75H19.9375C20.4182 21.75 20.8792 21.559 21.2191 21.2191C21.559 20.8792 21.75 20.4182 21.75 19.9375V17.2188C21.75 16.738 21.559 16.277 21.2191 15.9371C20.8792 15.5972 20.4182 15.4062 19.9375 15.4062H17.2188ZM3.625 8.15625C3.625 6.95449 4.1024 5.80195 4.95217 4.95217C5.80195 4.1024 6.95449 3.625 8.15625 3.625H20.8438C22.0455 3.625 23.1981 4.1024 24.0478 4.95217C24.8976 5.80195 25.375 6.95449 25.375 8.15625V20.8438C25.375 22.0455 24.8976 23.1981 24.0478 24.0478C23.1981 24.8976 22.0455 25.375 20.8438 25.375H8.15625C6.95449 25.375 5.80195 24.8976 4.95217 24.0478C4.1024 23.1981 3.625 22.0455 3.625 20.8438V8.15625ZM8.15625 5.4375C7.43519 5.4375 6.74367 5.72394 6.2338 6.2338C5.72394 6.74367 5.4375 7.43519 5.4375 8.15625V20.8438C5.4375 21.5648 5.72394 22.2563 6.2338 22.7662C6.74367 23.2761 7.43519 23.5625 8.15625 23.5625H20.8438C21.5648 23.5625 22.2563 23.2761 22.7662 22.7662C23.2761 22.2563 23.5625 21.5648 23.5625 20.8438V8.15625C23.5625 7.43519 23.2761 6.74367 22.7662 6.2338C22.2563 5.72394 21.5648 5.4375 20.8438 5.4375H8.15625Z" fill="#4F4F4F"/>
<rect x="7" y="7" width="7" height="15" rx="2" fill="#4F4F4F"/>
</svg>

        </div>
        <div class="button-label">
            <span>Manage Files</span>
        </div>
    </div>
</div>


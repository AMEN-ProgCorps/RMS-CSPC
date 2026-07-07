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

<div id="nav-chat-id" class="button-section-container">
    <div class="button-container" onclick="proccedto('/open-chat')">
        <div class="button-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="29" height="29" viewBox="0 0 29 29" fill="none">
                <path d="M24.1667 4.83331H4.83333C3.4925 4.83331 2.41667 5.90915 2.41667 7.24998V24.1666L7.25 19.3333H24.1667C25.5075 19.3333 26.5833 18.2575 26.5833 16.9166V7.24998C26.5833 5.90915 25.5075 4.83331 24.1667 4.83331ZM24.1667 16.9166H6.24167L4.83333 18.325V7.24998H24.1667V16.9166Z" fill="#4F4F4F"/>
            </svg>
        </div>
        <div class="button-label">
            <span>Chat</span>
        </div>
    </div>
</div>

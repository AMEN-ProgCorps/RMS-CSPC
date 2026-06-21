<div id="admin-dashboard-id" class="button-section-container">
    <div class="button-container {{ request()->routeIs('admin.console') ? 'force-active' : '' }}" onclick="proccedto('{{ route('admin.console') }}')">
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

<div id="admin-accounts-id" class="button-section-container {{ request()->routeIs('admin.accounts.*') ? 'show' : '' }}">
    <div class="button-container {{ request()->routeIs('admin.accounts.*') ? 'force-active' : '' }}" onclick="showButtonSection('admin-accounts-id')">
        <div class="button-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="29" height="29" viewBox="0 0 29 29" fill="none">
                <path d="M19.3333 14.5C21.1 14.5 22.5183 13.0696 22.5183 11.3029C22.5183 9.53625 21.1 8.10583 19.3333 8.10583C17.5667 8.10583 16.1363 9.53625 16.1363 11.3029C16.1363 13.0696 17.5667 14.5 19.3333 14.5ZM10.875 13.2917C12.9696 13.2917 14.6538 11.5954 14.6538 9.50083C14.6538 7.40625 12.9696 5.72083 10.875 5.72083C8.78042 5.72083 7.08417 7.40625 7.08417 9.50083C7.08417 11.5954 8.78042 13.2917 10.875 13.2917ZM10.875 15.7083C8.29458 15.7083 3.14583 16.9046 3.14583 19.4729V21.8796H18.6042V19.4729C18.6042 16.9046 13.4554 15.7083 10.875 15.7083ZM19.3333 16.9167C19.0192 16.9167 18.6688 16.9408 18.2942 16.9771C19.4542 17.8204 20.2733 18.9563 20.2733 19.4729V21.8796H25.8542V19.4729C25.8542 16.9046 20.7054 16.9167 19.3333 16.9167Z" fill="#4F4F4F"/>
            </svg>
        </div>
        <div class="button-label">
            <span>Management</span>
        </div>
        <div class="spacer"></div>
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
        <div class="function-button {{ request()->routeIs('admin.accounts.users') ? 'force-active' : '' }}" onclick="proccedto('{{ route('admin.accounts.users') }}')">Users</div>
        <div class="function-button {{ request()->routeIs('admin.accounts.roles') ? 'force-active' : '' }}" onclick="proccedto('{{ route('admin.accounts.roles') }}')">Roles</div>
        <div class="function-button {{ request()->routeIs('admin.accounts.offices') ? 'force-active' : '' }}" onclick="proccedto('{{ route('admin.accounts.offices') }}')">Offices</div>
    </div>
</div>

<div id="admin-activity-id" class="button-section-container {{ request()->routeIs('admin.activity.*') ? 'show' : '' }}">
    <div class="button-container {{ request()->routeIs('admin.activity.*') ? 'force-active' : '' }}" onclick="showButtonSection('admin-activity-id')">
        <div class="button-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="29" height="29" viewBox="0 0 29 29" fill="none">
                <path d="M14.4879 2.41669C7.82958 2.41669 2.41667 7.84169 2.41667 14.5C2.41667 21.1584 7.82958 26.5834 14.4879 26.5834C21.1583 26.5834 26.5833 21.1584 26.5833 14.5C26.5833 7.84169 21.1583 2.41669 14.4879 2.41669ZM14.5 24.1667C9.16458 24.1667 4.83333 19.8354 4.83333 14.5C4.83333 9.16461 9.16458 4.83335 14.5 4.83335C19.8354 4.83335 24.1667 9.16461 24.1667 14.5C24.1667 19.8354 19.8354 24.1667 14.5 24.1667ZM15.1042 8.45835H13.2917V15.7084L19.6979 19.4896L20.6042 17.9988L15.1042 14.7938V8.45835Z" fill="#4F4F4F"/>
            </svg>
        </div>
        <div class="button-label">
            <span>Activity Logs</span>
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
        <div class="function-button {{ request()->routeIs('admin.activity.logins') ? 'force-active' : '' }}" onclick="proccedto('{{ route('admin.activity.logins') }}')">Logins</div>
        <div class="function-button {{ request()->routeIs('admin.activity.account-changes') ? 'force-active' : '' }}" onclick="proccedto('{{ route('admin.activity.account-changes') }}')">Account Changes</div>
    </div>
</div>

<div id="admin-subsystems-id" class="button-section-container {{ request()->routeIs('admin.subsystems.*') ? 'show' : '' }}">
    <div class="button-container {{ request()->routeIs('admin.subsystems.*') ? 'force-active' : '' }}" onclick="showButtonSection('admin-subsystems-id')">
        <div class="button-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="29" height="29" viewBox="0 0 29 29" fill="none">
                <path d="M23.6538 15.7567C23.7021 15.3446 23.7263 14.9204 23.7263 14.5C23.7263 14.0917 23.7021 13.6554 23.6417 13.2433L26.2867 11.1771C26.5267 10.9854 26.5992 10.6458 26.4558 10.3667L23.9538 5.9629C23.7988 5.6717 23.4833 5.5767 23.1921 5.6717L20.0821 6.8925C19.4071 6.3996 18.6958 5.9871 17.9117 5.6717L17.4629 2.3617C17.4146 2.0463 17.1488 1.8125 16.8213 1.8125H11.8175C11.49 1.8125 11.2363 2.0463 11.1879 2.3617L10.7392 5.6717C9.95499 5.9871 9.23166 6.4117 8.56874 6.8925L5.45874 5.6717C5.16749 5.5646 4.85208 5.6717 4.69708 5.9629L2.20708 10.3667C2.05208 10.6579 2.11249 10.9854 2.37666 11.1771L5.02166 13.2433C4.96124 13.6554 4.91291 14.1038 4.91291 14.5C4.91291 14.8963 4.93708 15.3446 4.99749 15.7567L2.35249 17.8229C2.11249 18.0146 2.03999 18.3542 2.18332 18.6333L4.68541 23.0371C4.84041 23.3283 5.15582 23.4233 5.44707 23.3283L8.55707 22.1075C9.23207 22.6004 9.94332 23.0129 10.7275 23.3283L11.1762 26.6383C11.2363 26.9538 11.49 27.1875 11.8175 27.1875H16.8213C17.1488 27.1875 17.4146 26.9538 17.4508 26.6383L17.8996 23.3283C18.6837 23.0129 19.4071 22.6004 20.07 22.1075L23.18 23.3283C23.4712 23.4354 23.7867 23.3283 23.9417 23.0371L26.4437 18.6333C26.5987 18.3421 26.5263 18.0146 26.2742 17.8229L23.6538 15.7567ZM14.3192 19.3333C11.9554 19.3333 10.0358 17.4138 10.0358 15.05C10.0358 12.6863 11.9554 10.7667 14.3192 10.7667C16.6829 10.7667 18.6025 12.6863 18.6025 15.05C18.6025 17.4138 16.6829 19.3333 14.3192 19.3333Z" fill="#4F4F4F"/>
            </svg>
        </div>
        <div class="button-label">
            <span>Subsystems</span>
        </div>
        <div class="spacer"></div>
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
        <div class="function-button {{ request()->routeIs('admin.subsystems.add') ? 'force-active' : '' }}" onclick="proccedto('{{ route('admin.subsystems.add') }}')">Add</div>
        <div class="function-button {{ request()->routeIs('admin.subsystems.activate') ? 'force-active' : '' }}" onclick="proccedto('{{ route('admin.subsystems.activate') }}')">Activate</div>
        <div class="function-button {{ request()->routeIs('admin.subsystems.deactivate') ? 'force-active' : '' }}" onclick="proccedto('{{ route('admin.subsystems.deactivate') }}')">Deactivate</div>
        <div class="function-button {{ request()->routeIs('admin.subsystems.changes-logs') ? 'force-active' : '' }}" onclick="proccedto('{{ route('admin.subsystems.changes-logs') }}')">Changes Logs</div>
    </div>
</div>

<hr>

<div id="admin-rms-id" class="button-section-container {{ request()->routeIs('admin.rms.*') ? 'show' : '' }}">
    <div class="button-container {{ request()->routeIs('admin.rms.*') ? 'force-active' : '' }}" onclick="showButtonSection('admin-rms-id')">
        <div class="button-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="29" height="29" viewBox="0 0 29 29" fill="none">
                <path d="M22.9167 5.41669H7.20833C6.54099 5.41669 6 5.95768 6 6.62502V25.9584C6 26.6257 6.54099 27.1667 7.20833 27.1667H22.9167C23.584 27.1667 24.125 26.6257 24.125 25.9584V6.62502C24.125 5.95768 23.584 5.41669 22.9167 5.41669ZM10.2292 21.7292H19.7708V23.5417H10.2292V21.7292ZM10.2292 17.5H19.7708V19.3125H10.2292V17.5ZM10.2292 13.2709H19.7708V15.0834H10.2292V13.2709ZM18.6875 3V6.625H11.3125V3H18.6875Z" fill="#4F4F4F"/>
            </svg>
        </div>
        <div class="button-label">
            <span>Records Management Systems</span>
        </div>
        <div class="spacer"></div>
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
        <div class="function-button {{ request()->routeIs('admin.rms.transaction-logs') ? 'force-active' : '' }}" onclick="proccedto('{{ route('admin.rms.transaction-logs') }}')">Transactions Logs</div>
        <div class="function-button {{ request()->routeIs('admin.rms.update-logs') ? 'force-active' : '' }}" onclick="proccedto('{{ route('admin.rms.update-logs') }}')">Update Logs</div>
    </div>
</div>

<div id="admin-rdp-id" class="button-section-container {{ request()->routeIs('admin.rdp.*') ? 'show' : '' }}">
    <div class="button-container {{ request()->routeIs('admin.rdp.*') ? 'force-active' : '' }}" onclick="showButtonSection('admin-rdp-id')">
        <div class="button-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="29" height="29" viewBox="0 0 29 29" fill="none">
                <path d="M22.9167 3.625H6.08333C4.76667 3.625 3.625 4.76667 3.625 6.08333V22.9167C3.625 24.2333 4.76667 25.375 6.08333 25.375H22.9167C24.2333 25.375 25.375 24.2333 25.375 22.9167V6.08333C25.375 4.76667 24.2333 3.625 22.9167 3.625ZM11 19.6875H8.54167V12.0833H11V19.6875ZM15.7292 19.6875H13.2708V9.375H15.7292V19.6875ZM20.4583 19.6875H18V14.5417H20.4583V19.6875Z" fill="#4F4F4F"/>
            </svg>
        </div>
        <div class="button-label">
            <span>Records Disposition Program</span>
        </div>
        <div class="spacer"></div>
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
        <div class="function-button {{ request()->routeIs('admin.rdp.records-logs') ? 'force-active' : '' }}" onclick="proccedto('{{ route('admin.rdp.records-logs') }}')">Records Logs</div>
        <div class="function-button {{ request()->routeIs('admin.rdp.update-logs') ? 'force-active' : '' }}" onclick="proccedto('{{ route('admin.rdp.update-logs') }}')">Update Logs</div>
    </div>
</div>
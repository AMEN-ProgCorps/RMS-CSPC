<div id="profile-details-id" class="button-section-container">
    <div class="button-container {{ request()->routeIs('profile') ? 'force-active' : '' }}" onclick="proccedto('{{ route('profile') }}')">
        <div class="button-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="29" height="29" viewBox="0 0 29 29" fill="none">
                <path d="M14.5 4.83331C12.0147 4.83331 10 6.84798 10 9.33331C10 11.8186 12.0147 13.8333 14.5 13.8333C16.9853 13.8333 19 11.8186 19 9.33331C19 6.84798 16.9853 4.83331 14.5 4.83331ZM14.5 15.8333C9.80667 15.8333 6 17.7373 6 20.0833V21.8333C6 22.3856 6.44772 22.8333 7 22.8333H22C22.5523 22.8333 23 22.3856 23 21.8333V20.0833C23 17.7373 19.1933 15.8333 14.5 15.8333Z" fill="#4F4F4F"/>
            </svg>
        </div>
        <div class="button-label">
            <span>Personal Details</span>
        </div>
    </div>
</div>

<div id="profile-settings-id" class="button-section-container">
    <div class="button-container {{ request()->routeIs('profile.settings') ? 'force-active' : '' }}" onclick="proccedto('{{ route('profile.settings') }}')">
        <div class="button-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="29" height="29" viewBox="0 0 29 29" fill="none">
                <path d="M4 20.75v2.5h6.25v-2.5H4zm0-15v2.5h11.25v-2.5H4zm11.25 17.5v-2.5H25v-2.5h-9.75v-2.5H12.5v7.5h2.75zm-6.25-10v2.5H4v2.5h5v2.5h2.75v-7.5H9zm16 2.5v-2.5H14.75v2.5H25zm-6.25-5v2.5H25v-2.5h-6.25v-2.5H16v7.5h2.75z" fill="#4F4F4F"/>
            </svg>
        </div>
        <div class="button-label">
            <span>Preferences & Settings</span>
        </div>
    </div>
</div>

<div id="profile-security-id" class="button-section-container">
    <div class="button-container {{ request()->routeIs('profile.security-logs') ? 'force-active' : '' }}" onclick="proccedto('{{ route('profile.security-logs') }}')">
        <div class="button-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="29" height="29" viewBox="0 0 29 29" fill="none">
                <path d="M14.5 3.625L5.4375 7.83331V14.5C5.4375 19.5209 9.40208 24.2063 14.5 25.375C19.5979 24.2063 23.5625 19.5209 23.5625 14.5V7.83331L14.5 3.625ZM13.2917 18.9583L9.66667 15.3333L10.9958 14.0042L13.2917 16.2958L18.0042 11.5833L19.3333 12.9167L13.2917 18.9583Z" fill="#4F4F4F"/>
            </svg>
        </div>
        <div class="button-label">
            <span>Security Logs</span>
        </div>
    </div>
</div>

<div id="profile-notifications-id" class="button-section-container">
    <div class="button-container {{ request()->routeIs('profile.notification-manager') ? 'force-active' : '' }}" onclick="proccedto('{{ route('profile.notification-manager') }}')">
        <div class="button-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="29" height="29" viewBox="0 0 29 29" fill="none">
                <path d="M14.5 26.125C15.8083 26.125 16.875 25.0583 16.875 23.75H12.125C12.125 25.0583 13.1917 26.125 14.5 26.125ZM21.625 19V13.0833C21.625 9.47748 19.7067 6.46081 16.2917 5.65831V4.85581C16.2917 3.84748 15.5083 3.02081 14.5 3.02081C13.4917 3.02081 12.7083 3.84748 12.7083 4.85581V5.65831C9.28167 6.46081 7.375 9.46581 7.375 13.0833V19L5 21.375V22.5625H24V21.375L21.625 19Z" fill="#4F4F4F"/>
            </svg>
        </div>
        <div class="button-label">
            <span>Notification Manager</span>
        </div>
    </div>
</div>

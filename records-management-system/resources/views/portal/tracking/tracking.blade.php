@extends('portal.login_head')

@section('styles')
    @vite('resources/css/td.css')
    <style>
    </style>
@endsection

@section('content')
    <header>
        <div class="logo">
            <img src="{{ asset('images/cspc.png') }}" alt="CSPC Logo">
        </div>
        <span>Records and Freedom of Information Office</span>
    </header>
    <section>
        <div class="login"><!-- Back to login page -->
            <button class="log" onclick="window.location.href='{{ route('login') }}'">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="24" viewBox="0 0 48 24">
                    <path fill="currentColor" d="M46,13V11H8L13.5,5.5L12.08,4.08L4.16,12L12.08,19.92L13.5,18.5L8,13H46Z" />
                </svg>
                Back
            </button>
        </div>
        <div class="container">
            <div class="td-label">
                <span class="top">Track Document</span>
                <span class="subtitle">Enter your tracking number to view your document status without login</span>
            </div>
            <div class="td-search">
                <div class="search-container">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                        <path fill="currentColor" d="M9.5,3A6.5,6.5,0,1,0,16,9.5,6.51,6.51,0,0,0,9.5,3Zm0,11A4.5,4.5,0,1,1,14,9.5,4.51,4.51,0,0,1,9.5,14ZM20.71,19.29l-3.4-3.39a1,1,0,1,0-1.42,1.42l3.4,3.39a1,1,0,0,0,1.42-1.42Z" />
                    </svg>
                    <input type="text" placeholder="Enter Tracking Number">
                </div>
                <button class="search-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                        <path fill="currentColor" d="M9.5,3A6.5,6.5,0,1,0,16,9.5,6.51,6.51,0,0,0,9.5,3Zm0,11A4.5,4.5,0,1,1,14,9.5,4.51,4.51,0,0,1,9.5,14ZM20.71,19.29l-3.4-3.39a1,1,0,1,0-1.42,1.42l3.4,3.39a1,1,0,0,0,1.42-1.42Z" />
                    </svg>
                    Track
                </button>
            </div>            
<!-- The process before showing the document data will be:
    1. User input the tracking number and click the track button
    2. System will check if the tracking number is valid and if the document is found
    3. before showing the document data, the user need to input their email address for logging purposes,
    4.1 if the email address is uses the intitute domain, then the user no longer needed to input the pass word, 
    4.2 if the email address is not using the intitute domain, then the user need to input the password for the document
    to acccess it
    5. document-data as the final output on data-containers class, the data will be display according to the document that is being searched
-->
            <div class="td-items">
                <div class="doc-ifs">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                        <path fill="currentColor" d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20M8,12V14H16V12H8M8,16V18H13V16H8Z" />
                    </svg>
                    <span>Document </span>
                    <span>Found</span><!--if no document found will be change to "Not Found"-->
                </div>
                <div class="doc-tracking-number">
                    <!-- The Tracking Data that the used inputed in the search bar will show up in here -->
                    <span>0ABF-0000-0000-0000-0000-0000</span>
                </div>
                <!-- No. 4 -->
                <div id="doc-verification" class="data-containers">
                    <form class="doc-verification">
                         <span class="subtitle">Email</span>
                         <!-- it takes the email that is loged in the chrome, without using the google api -->
                         <input type="email" autocomplete="email" name="email" id="email-input" readonly placeholder="Waiting for Google sign-in..." required>
                         <div id="email-status" class="email-status">Initializing Google sign-in...</div>
                         <button class="verification-btn">Verify</button>   
                    </form>
                </div>
                <!-- No. 4.2 -->
                <div id="doc-password" class="data-containers" style="display: none;">
                    <form class="doc-password">
                        <span class="subtitle">Enter Document Password:</span>
                        <input type="password" placeholder="Document Password" required>
                        <button class="password-btn">Submit</button>
                    </form>
                </div>
                <!-- No. 5 -->
                <div id="document-data" class="data-containers" style="display: none;">
                    <div class="doc-status" style="background-color: #33A04B">
                        <div class="status-box">
                            <span>Onging</span><!--Document status: Ongoing, Completed, or Rejected-->
                        </div>
                    </div>
                    <div class="doc-details">
                        <div class="ddc-item">
                            <div class="ddc-question">Document Type</div>
                            <div class="ddc-answer">{doc_type}</div><!--Document type: Memorandum, Letter, Report, etc.-->
                        </div>
                        <div class="ddc-item">
                            <div class="ddc-question">Date Received</div>
                            <div class="ddc-answer">{Month-Date-Year}</div>
                        </div>
                        <div class="ddc-item">
                            <div class="ddc-question">Sender</div>
                            <div class="ddc-answer">{Office}</div><!--or if its a external, then the name-->
                        </div>
                        <div class="ddc-item">
                            <div class="ddc-question">Current Location</div>
                            <div class="ddc-answer">{current_office}</div><!-- Current location of the document -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
@section('scripts')
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const emailInput = document.getElementById('email-input');
            const statusText = document.getElementById('email-status');

            function setEmail(value) {
                if (value && value.includes('@')) {
                    emailInput.value = value;
                    emailInput.placeholder = '';
                    statusText.textContent = 'Email loaded from Google account.';
                }
            }

            function setStatus(message) {
                if (statusText) {
                    statusText.textContent = message;
                }
            }

            // Initialize Google One Tap
            window.onload = function () {
                google.accounts.id.initialize({
                    client_id: '{{ config("services.google.client_id") }}',
                    callback: function (response) {
                        // Decode the JWT to get email
                        const responsePayload = decodeJwtResponse(response.credential);
                        setEmail(responsePayload.email);
                    },
                    auto_select: true, // Attempt automatic sign-in if user has previously consented
                    cancel_on_tap_outside: false,
                });

                google.accounts.id.prompt(); // Display the One Tap prompt
            };

            function decodeJwtResponse(token) {
                const base64Url = token.split('.')[1];
                const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
                const jsonPayload = decodeURIComponent(atob(base64).split('').map(function(c) {
                    return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
                }).join(''));
                return JSON.parse(jsonPayload);
            }

            // Fallback to localStorage if no Google sign-in
            setTimeout(function () {
                if (!emailInput.value) {
                    const stored = window.localStorage.getItem('rms_email_autofill');
                    if (stored) {
                        setEmail(stored);
                    } else {
                        setStatus('Please sign in with Google or enter email manually.');
                        emailInput.readOnly = false; // Allow manual entry if no auto-fill
                    }
                }
            }, 3000); // Wait for Google prompt
        });
    </script>
@endsection
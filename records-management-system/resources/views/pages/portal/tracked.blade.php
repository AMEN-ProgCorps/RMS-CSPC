<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
/*
        Filestorage_data = data: { 
*             device_id: string, 
*             document_tracked_within_10_minutes: int / 3, //this one starts on 0
*             last_document_tracked_at: timestamp,
*             email_used_on_verification: string,
*             is_email_not_cspc: boolean,
*             device_blocked_until: timestamp     
*          }
*   since this page is only continuation of track-document while being in the same url, it will revolve around this phases like track-document:
*   Phase 1: after going throught the verification on track-document, the url value will be passed on this page eg /track-document=?{traacking_number} and the document will be searched, 
*        L if found which already been before they encounter the page here, the email verification form will be shown and wll proceed to next phaase
*        L if not found or just maniputalted the url, it will check the browser local storage for the document_tracked_within_10_minutes value or the whole data if present in the browser file storage, 
*                L if its present and the value of document_tracked_within_10_minutes is less than 3, then add one to the value because they forcing to access the page and return to track-document... 
*                L if not then return back to track-document
*   Phase 2: after the email verification it will rewrite the email value on the browser file storage
                 L   if the email is a cspc email, then it will skip the document password step
                 L   if the email is not a cspc email, then it will show the document password step, and after the correct password is inputed, it will proceed to the next phase
*   Phase 3: after the document password verification, it will show the document data, while at it the backend will now send a value to the tracking_device_log for audit purpose and security purposes
            update the last_document_tracked_at to the current timestamp, and upload data to the backend: the date will be like this:           
*                data: {
*                      tracked_id: <the id of the documeent that the user inputed>
*                      device_id: <the unique device id generated in phase 1.3> 
*                      email: null, // due to the fact that the email verification is only for the documents that exist, the email_used_on_verification will be null if the tracking number does not exist,
*                      current_timestamp: <the current timestamp when the user inputed the tracking number>
*                      status: <document_tracked_within_10_minutes rated as if 1: warning, 2: danger, 3: blocked>
*                    }                            
*/


new #[Layout('layouts.portal')] #[Title('Track Document — Results')] class extends Component
{

}
?>

@push('styles')
@endpush

<header>
    <div class="logo">
        <img src="{{ asset('images/cspc.png') }}" alt="CSPC Logo">
    </div>
    <span>Records and Freedom of Information Office</span>
</header>
<section>
    <div class="login">
        <a href="{{ route('login') }}" class="log">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="24" viewBox="0 0 48 24">
                <path fill="currentColor" d="M46,13V11H8L13.5,5.5L12.08,4.08L4.16,12L12.08,19.92L13.5,18.5L8,13H46Z" />
            </svg>
            Back
        </a>
    </div>
    <div class="container">
        <div class="td-label">
            <span class="top">Track Document</span>
            <span class="subtitle">Enter your tracking number to view your document status without login</span>
        </div>
        <div class="td-items">
            <div class="doc-ifs">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                    <path fill="currentColor" d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20M8,12V14H16V12H8M8,16V18H13V16H8Z" />
                </svg>
                <span>Document </span>
                <span>Found</span>
            </div>
            <div class="doc-tracking-number">
                <span>{{ $trackingNumber }}</span>
            </div>
            @if (! $showPasswordStep && ! $showDocumentData)
                <div id="doc-verification" class="data-containers" wire:ignore.self>
                    <form wire:submit="verifyEmail" class="doc-verification">
                        <span class="subtitle">Email</span>
                        <input
                            wire:model="email"
                            type="email"
                            autocomplete="email"
                            name="email"
                            id="email-input"
                            @if ($emailReadonly) readonly @endif
                            placeholder="Waiting for Google sign-in..."
                            required
                        >
                        <div id="email-status" class="email-status">{{ $emailStatus }}</div>
                        <button type="submit" class="verification-btn">Verify</button>
                    </form>
                </div>
            @endif
            @if ($showPasswordStep && ! $showDocumentData)
                <div id="doc-password" class="data-containers">
                    <form wire:submit="submitPassword" class="doc-password">
                        <span class="subtitle">Enter Document Password:</span>
                        <input wire:model="documentPassword" type="password" placeholder="Document Password" required>
                        @error('documentPassword')
                            <span class="form-error">{{ $message }}</span>
                        @enderror
                        <button type="submit" class="password-btn">Submit</button>
                    </form>
                </div>
            @endif
            @if ($showDocumentData)
                <div id="document-data" class="data-containers">
                    <div class="doc-status" style="background-color: #33A04B">
                        <div class="status-box">
                            <span>Ongoing</span>
                        </div>
                    </div>
                    <div class="doc-details">
                        <div class="ddc-item">
                            <div class="ddc-question">Document Type</div>
                            <div class="ddc-answer">{doc_type}</div>
                        </div>
                        <div class="ddc-item">
                            <div class="ddc-question">Date Received</div>
                            <div class="ddc-answer">{Month-Date-Year}</div>
                        </div>
                        <div class="ddc-item">
                            <div class="ddc-question">Sender</div>
                            <div class="ddc-answer">{Office}</div>
                        </div>
                        <div class="ddc-item">
                            <div class="ddc-question">Current Location</div>
                            <div class="ddc-answer">{current_office}</div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</section>

@push('scripts')
  
@endpush

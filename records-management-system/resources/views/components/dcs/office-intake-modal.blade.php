{{--
DCS file: Modal shell for starting or reviewing an office intake from another DCS screen.
--}}
<div
    id="ofi-intake-modal"
    class="ofi-intake-modal-overlay ofi-intake-modal-overlay--review"
    role="dialog"
    aria-modal="true"
    aria-labelledby="ofi-intake-modal-title"
    hidden
>
    <div class="ofi-intake-modal ofi-intake-modal--review" @click.stop>
        <div class="ofi-intake-modal-header">
            <div class="ofi-intake-modal-heading">
                <span class="ofi-intake-modal-kicker" data-ofi-modal-kicker>RFIO review</span>
                <h2 id="ofi-intake-modal-title" data-ofi-modal-title>Office submission</h2>
                <p class="ofi-intake-modal-subtitle" data-ofi-modal-subtitle></p>
            </div>
            <div class="ofi-intake-modal-actions">
                <button type="button" class="ofi-intake-modal-close" data-ofi-modal-close aria-label="Close">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </div>
        <div class="ofi-intake-modal-body" data-ofi-modal-body></div>
        <div class="ofi-intake-modal-footer" data-ofi-modal-footer hidden>
            <p class="ofi-receive-registered" data-ofi-registered-banner hidden>
                This submission is already registered / controlled.
            </p>

            <label class="ofi-receive-check" data-ofi-receive-label>
                <input type="checkbox" data-ofi-receive-check>
                <span>I have received the signed printed form and verified that the data matches this submission.</span>
            </label>
            <p class="ofi-receive-meta" data-ofi-receive-meta hidden></p>

            <div class="ofi-unlock-block" data-ofi-unlock-block hidden>
                <label class="ofi-unlock-label" for="ofi-unlock-reason">Return for correction (enable office edit)</label>
                <textarea
                    id="ofi-unlock-reason"
                    class="ofi-unlock-reason"
                    data-ofi-unlock-reason
                    rows="2"
                    maxlength="1000"
                    placeholder="Describe what is wrong so the office can fix it…"
                ></textarea>
                <div class="ofi-alert err ofi-unlock-error" data-ofi-unlock-error hidden role="alert"></div>
                <button type="button" class="ofi-unlock-btn" data-ofi-unlock-btn>
                    <i class="fa-solid fa-unlock"></i>
                    <span data-ofi-unlock-btn-label>Enable edit for office</span>
                </button>
                <div class="ofi-unlock-sent" data-ofi-unlock-sent hidden role="status">
                    <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                    <div class="ofi-unlock-sent-body">
                        <strong>Correction already sent to the office</strong>
                        <span data-ofi-unlock-sent-detail>They can edit and resubmit this form.</span>
                    </div>
                </div>
                <p class="ofi-receive-meta" data-ofi-unlock-meta hidden></p>
            </div>

            <div class="ofi-intake-modal-footer-actions">
                <button type="button" class="ofi-receive-proceed" data-ofi-receive-proceed disabled>
                    Proceed to Registration
                    <i class="fa-solid fa-arrow-right"></i>
                </button>
            </div>
        </div>
    </div>
</div>

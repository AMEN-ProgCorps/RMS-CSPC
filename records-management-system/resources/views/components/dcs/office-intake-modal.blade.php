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
                <span>I already received the document and reviewed the data's inputed are correct.</span>
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
                <button type="button" class="ofi-unlock-btn" data-ofi-unlock-btn>
                    <i class="fa-solid fa-unlock"></i>
                    Enable edit for office
                </button>
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

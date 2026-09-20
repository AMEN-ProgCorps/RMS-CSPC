{{-- Draft confirm + leave-guard modals (Register create / edit) --}}
<template x-teleport="body">
    <div class="reg-modal-overlay" id="regDraftConfirmModal" aria-hidden="true" onclick="if(event.target===this)closeDraftConfirmModal()">
        <div class="reg-modal reg-modal--draft" role="dialog" aria-modal="true" aria-labelledby="regDraftConfirmTitle">
            <div class="reg-modal-header reg-modal-header--draft">
                <i class="fa-regular fa-floppy-disk"></i>
                <h3 id="regDraftConfirmTitle">Save as draft</h3>
                <button type="button" class="reg-modal-close" onclick="closeDraftConfirmModal()" aria-label="Close">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="reg-modal-body reg-modal-body--draft">
                <p class="reg-draft-lead">Save this registration as a draft?</p>
                <p class="reg-draft-note">
                    You can finish it later from
                    <strong>Document Registration → Drafts</strong>.
                </p>
            </div>
            <div class="reg-modal-footer">
                <button type="button" class="reg-btn reg-btn-cancel" onclick="closeDraftConfirmModal()">
                    <i class="fa-solid fa-xmark"></i> Cancel
                </button>
                <button type="button" class="reg-btn reg-btn-draft" onclick="confirmDraftSaveFromModal()">
                    <i class="fa-regular fa-floppy-disk"></i> Save Draft
                </button>
            </div>
        </div>
    </div>
</template>

<template x-teleport="body">
    <div class="reg-modal-overlay" id="regLeaveDraftModal" aria-hidden="true" onclick="if(event.target===this)closeLeaveDraftModal()">
        <div class="reg-modal reg-modal--draft" role="dialog" aria-modal="true" aria-labelledby="regLeaveDraftTitle">
            <div class="reg-modal-header reg-modal-header--draft">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <h3 id="regLeaveDraftTitle">Unsaved work</h3>
                <button type="button" class="reg-modal-close" onclick="closeLeaveDraftModal()" aria-label="Close">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="reg-modal-body reg-modal-body--draft">
                <p class="reg-draft-lead">You entered data that isn’t saved yet.</p>
                <p class="reg-draft-note" id="regLeaveDraftHint">
                    Save it as a draft so you can continue later from Document Registration → Drafts.
                </p>
            </div>
            <div class="reg-modal-footer reg-modal-footer--draft-leave">
                <button type="button" class="reg-btn reg-btn-cancel" onclick="closeLeaveDraftModal()">
                    Stay on page
                </button>
                <button type="button" class="reg-btn reg-btn-leave" onclick="leaveWithoutSaving()">
                    Leave without saving
                </button>
                <button type="button" id="btnLeaveSaveDraft" class="reg-btn reg-btn-draft" onclick="saveDraftAndLeave()">
                    <i class="fa-regular fa-floppy-disk"></i> Save draft &amp; leave
                </button>
            </div>
        </div>
    </div>
</template>

<template x-teleport="body">
    <div class="reg-modal-overlay" id="regDraftNoticeModal" aria-hidden="true" onclick="if(event.target===this)closeDraftNoticeModal()">
        <div class="reg-modal reg-modal--draft" role="dialog" aria-modal="true" aria-labelledby="regDraftNoticeTitle">
            <div class="reg-modal-header reg-modal-header--notice">
                <i class="fa-solid fa-circle-info"></i>
                <h3 id="regDraftNoticeTitle">More details needed</h3>
                <button type="button" class="reg-modal-close" onclick="closeDraftNoticeModal()" aria-label="Close">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="reg-modal-body reg-modal-body--draft">
                <p class="reg-draft-lead" id="regDraftNoticeLead">To save a draft, add more masterlist details.</p>
                <p class="reg-draft-note" id="regDraftNoticeBody">
                    Fill <strong>Document No</strong> plus at least one other field such as
                    Title, Effectivity Date, Pages, Keywords, Originator, or Source Unit.
                </p>
            </div>
            <div class="reg-modal-footer">
                <button type="button" class="reg-btn reg-btn-save" onclick="closeDraftNoticeModal()">
                    <i class="fa-solid fa-check"></i> Got it
                </button>
            </div>
        </div>
    </div>
</template>

{{-- Shared toolbar + modal for distribution office multi-select / saved groups --}}
<div class="reg-dist-toolbar" id="distOfficeToolbar">
    <div class="reg-dist-toolbar-actions">
        <button type="button" class="reg-dist-tool-btn" id="distSelectAllBtn" title="Select all offices" onclick="toggleSelectAllDistOffices()">
            <i class="fa-solid fa-check-double"></i> Select all
        </button>
        <button type="button" class="reg-dist-tool-btn" id="distMoveUpBtn" title="Move selected up" onclick="moveSelectedDistOffices(-1)">
            <i class="fa-solid fa-arrow-up"></i> Up
        </button>
        <button type="button" class="reg-dist-tool-btn" id="distMoveDownBtn" title="Move selected down" onclick="moveSelectedDistOffices(1)">
            <i class="fa-solid fa-arrow-down"></i> Down
        </button>
        <button type="button" class="reg-dist-tool-btn reg-dist-tool-btn--accent" id="distSaveGroupBtn" title="Save current list as a reusable group" onclick="openSaveDistOfficeGroupModal()">
            <i class="fa-solid fa-floppy-disk"></i> Save group
        </button>
    </div>
    <div class="reg-dist-groups" id="distOfficeGroups">
        <span class="reg-dist-groups-label">Saved groups</span>
        <div class="reg-dist-group-chips" id="distOfficeGroupChips"></div>
    </div>
</div>

<template x-teleport="body">
<div class="reg-modal-overlay" id="distOfficeGroupModal" aria-hidden="true" onclick="if(event.target===this)closeSaveDistOfficeGroupModal()">
    <div class="reg-modal" style="max-width:420px;">
        <div class="reg-modal-header">
            <i class="fa-solid fa-layer-group"></i>
            <h3>Save office group</h3>
            <button type="button" class="reg-modal-close" onclick="closeSaveDistOfficeGroupModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="reg-modal-body">
            <p class="reg-field-hint" style="margin-top:0;">Name this distribution list so you can reuse it on the next registration.</p>
            <div class="reg-field">
                <label for="distOfficeGroupName">Group name</label>
                <input type="text" id="distOfficeGroupName" maxlength="120" placeholder="e.g. Academic cluster pack" autocomplete="off">
            </div>
            <div id="distOfficeGroupModalError" class="reg-file-error" style="display:none;"></div>
        </div>
        <div class="reg-modal-footer">
            <button type="button" class="reg-btn reg-btn-cancel" onclick="closeSaveDistOfficeGroupModal()">Cancel</button>
            <button type="button" class="reg-btn reg-btn-save" id="distOfficeGroupSaveBtn" onclick="submitSaveDistOfficeGroup(false)">
                <i class="fa-solid fa-check"></i> Save
            </button>
        </div>
    </div>
</div>
</template>

<template x-teleport="body">
<div class="reg-modal-overlay" id="distOfficeGroupDeleteModal" aria-hidden="true" onclick="if(event.target===this)closeDeleteDistOfficeGroupModal()">
    <div class="reg-modal reg-modal--dist-delete" role="dialog" aria-modal="true" aria-labelledby="distOfficeGroupDeleteTitle">
        <div class="reg-modal-header reg-modal-header--danger">
            <i class="fa-solid fa-trash-can"></i>
            <h3 id="distOfficeGroupDeleteTitle">Delete saved group</h3>
            <button type="button" class="reg-modal-close" onclick="closeDeleteDistOfficeGroupModal()" aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="reg-modal-body">
            <p class="reg-dist-delete-lead">
                Remove <strong id="distOfficeGroupDeleteName">this group</strong> from your saved distribution lists?
            </p>
            <p class="reg-field-hint reg-dist-delete-hint">
                The offices currently on this form stay as they are. Only the reusable group is deleted.
            </p>
            <div id="distOfficeGroupDeleteError" class="reg-file-error" style="display:none;"></div>
        </div>
        <div class="reg-modal-footer">
            <button type="button" class="reg-btn reg-btn-cancel" onclick="closeDeleteDistOfficeGroupModal()">Cancel</button>
            <button type="button" class="reg-btn reg-btn-danger" id="distOfficeGroupDeleteConfirmBtn" onclick="confirmDeleteDistOfficeGroup()">
                <i class="fa-solid fa-trash-can"></i> Delete group
            </button>
        </div>
    </div>
</div>
</template>

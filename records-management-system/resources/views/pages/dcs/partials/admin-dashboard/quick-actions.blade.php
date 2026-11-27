{{--
  DCS PARTIAL: Admin dashboard quick actions
  FILE: resources/views/pages/dcs/partials/admin-dashboard/quick-actions.blade.php
--}}
@if(!empty($canRegister))
<section class="actions-section" aria-label="Quick actions">
    <div class="section-header">
        <h2>Quick Actions</h2>
        <span class="section-subtitle">Frequently used operations</span>
    </div>
    <div class="actions-row">
        <a href="{{ route('dcs.register.create', ['type' => 'new'], absolute: false) }}" class="action-box">
            <div class="action-icon-wrap"><i class="fa-solid fa-file-circle-plus" aria-hidden="true"></i></div>
            <div class="action-content">
                <h4>Register New Document</h4>
                <p>Register a document that is not in the system yet</p>
            </div>
            <i class="fa-solid fa-arrow-right action-arrow" aria-hidden="true"></i>
        </a>
        <a href="{{ route('dcs.register.create', ['type' => 'revised'], absolute: false) }}" class="action-box">
            <div class="action-icon-wrap"><i class="fa-solid fa-file-pen" aria-hidden="true"></i></div>
            <div class="action-content">
                <h4>Register Revised Document</h4>
                <p>Register the next revision of a document already on file</p>
            </div>
            <i class="fa-solid fa-arrow-right action-arrow" aria-hidden="true"></i>
        </a>
        <a href="{{ route('dcs.register.update', absolute: false) }}" class="action-box">
            <div class="action-icon-wrap"><i class="fa-solid fa-rotate" aria-hidden="true"></i></div>
            <div class="action-content">
                <h4>Update Document</h4>
                <p>Change the details of a document that is already registered</p>
            </div>
            <i class="fa-solid fa-arrow-right action-arrow" aria-hidden="true"></i>
        </a>
    </div>
</section>
@endif

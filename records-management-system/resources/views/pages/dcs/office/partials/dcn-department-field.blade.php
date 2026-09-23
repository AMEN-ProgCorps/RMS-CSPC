@php
    $officeId = (int) ($userOfficeId ?? 0);
    $officeCode = trim((string) ($userOfficeCode ?? ''));
    $officeName = trim((string) ($userOfficeName ?? ''));
    $officeLabel = trim((string) ($userOfficeLabel ?? ''));
    if ($officeName === '' && $officeLabel !== '') {
        $officeName = $officeLabel;
    }
@endphp
<div class="reg-field ofi-dept-field">
    <label for="ofiDepartmentDisplay">Department</label>
    <div class="ofi-dept-control" aria-readonly="true">
        <span class="ofi-dept-icon" aria-hidden="true">
            <i class="fa-solid fa-building"></i>
        </span>
        <div class="ofi-dept-body">
            @if($officeCode !== '')
                <span class="ofi-dept-code">{{ $officeCode }}</span>
            @endif
            <input
                type="text"
                id="ofiDepartmentDisplay"
                class="ofi-dept-name"
                value="{{ $officeName !== '' ? $officeName : 'Your office' }}"
                readonly
                tabindex="-1"
                aria-label="Department from your office"
            >
        </div>
        <span class="ofi-dept-lock" title="Taken from your account office">
            <i class="fa-solid fa-lock" aria-hidden="true"></i>
            <span>Your office</span>
        </span>
    </div>
    <input type="hidden" name="departmentOfficeId" value="{{ $officeId }}">
    <p class="ofi-hint">This stays the office on your account. It cannot be changed here.</p>
</div>

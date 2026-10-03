@php
    $officeName = trim((string) ($userOfficeName ?? ''));
    $officeLabel = trim((string) ($userOfficeLabel ?? ''));
    $officeCode = trim((string) ($userOfficeCode ?? ''));
    if ($officeName === '') {
        $officeName = $officeLabel !== '' ? $officeLabel : $officeCode;
    }
@endphp
<div class="reg-field">
    <label for="ofiDepartmentDisplay">Department</label>
    <input
        type="text"
        id="ofiDepartmentDisplay"
        value="{{ $officeName !== '' ? $officeName : 'Your office' }}"
        readonly
        tabindex="-1"
    >
    <input type="hidden" name="departmentOfficeId" value="{{ (int) ($userOfficeId ?? 0) }}">
</div>

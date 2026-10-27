{{--
DCS file: Locked Department / source unit on office DCN and DRF. Always the signed-in office.
--}}
@php
    $officeName = trim((string) ($userOfficeName ?? ''));
    $officeLabel = trim((string) ($userOfficeLabel ?? ''));
    $officeCode = trim((string) ($userOfficeCode ?? ''));
    if ($officeName === '') {
        $officeName = $officeLabel !== '' ? $officeLabel : $officeCode;
    }
    $departmentFieldLabel = trim((string) ($departmentFieldLabel ?? 'Department'));
    if ($departmentFieldLabel === '') {
        $departmentFieldLabel = 'Department';
    }
@endphp
<div class="reg-field">
    <label for="ofiDepartmentDisplay">{{ $departmentFieldLabel }}</label>
    <input
        type="text"
        id="ofiDepartmentDisplay"
        class="ofi-input-locked"
        value="{{ $officeName !== '' ? $officeName : 'Your office' }}"
        readonly
        disabled
        tabindex="-1"
    >
    <input type="hidden" name="departmentOfficeId" value="{{ (int) ($userOfficeId ?? 0) }}">
</div>

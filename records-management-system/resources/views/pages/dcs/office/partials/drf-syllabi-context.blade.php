{{--
  DCS PARTIAL: Office Intake partial (drf-syllabi-context.blade.php)
  FILE: resources/views/pages/dcs/office/partials/drf-syllabi-context.blade.php
  DEBUG: OfficeIntakeHelper + office-intake.js / office-intake.css
--}}
{{--
DCS file: Syllabi/TOS context fields on office DRF. Shown after Internal Forms + syllabi-like sub-type.
All course types require college + program (catalog is per program).
--}}
@php
    $sylOldCollege = (string) old('collegeId', (string) ($oldCollegeId ?? ''));
    $sylOldProgram = (string) old('programId', (string) ($oldProgramId ?? ''));
    $sylOldSemester = (string) old('semesterId', (string) ($oldSemesterId ?? ''));
    $sylOldCourse = (string) old('courseId', (string) ($oldCourseId ?? ''));
    $sylOldSchoolYear = (string) old('schoolYearId', (string) ($oldSchoolYearId ?? ''));
    $sylOldCourseType = (string) old('courseType', (string) ($oldCourseType ?? ''));
    $sylVisible = (bool) ($syllabiContextVisible ?? false);
@endphp
<div
    class="ofi-syllabi-context{{ $sylVisible ? '' : ' is-ofi-hidden' }}"
    id="ofiDrfSyllabiContext"
    data-sub-name="{{ $subTypeName ?? '' }}"
    @if(! $sylVisible) hidden @endif
>
    <div class="reg-grid-3 ofi-syllabi-grid">
        <div class="reg-field" style="margin:0;">
            <label for="ofiDrfCourseType">Course type <span class="ofi-req">*</span></label>
            <select id="ofiDrfCourseType" name="courseType" @disabled(! $sylVisible) @required($sylVisible)>
                <option value="" disabled selected hidden>Select course type…</option>
                @foreach (\App\Helpers\CourseTypeRules::ALL_TYPES as $type)
                    <option value="{{ $type }}" @selected($sylOldCourseType === $type)>{{ $type }}</option>
                @endforeach
            </select>
        </div>
        <div class="reg-field" style="margin:0;">
            <label for="ofiDrfCollegeId">College <span class="ofi-req">*</span></label>
            <select id="ofiDrfCollegeId" name="collegeId" @disabled(! $sylVisible || $sylOldCourseType === '') @required($sylVisible)>
                <option value="" disabled selected hidden>Select college…</option>
            </select>
        </div>
        <div class="reg-field{{ $sylOldCourseType === '' ? ' is-ofi-hidden' : '' }}" style="margin:0;" id="ofiDrfProgramWrap" @if($sylOldCourseType === '') hidden @endif>
            <label for="ofiDrfProgramId">Program <span class="ofi-req">*</span></label>
            <select id="ofiDrfProgramId" name="programId" @disabled(! $sylVisible || $sylOldCollege === '') @required($sylVisible && $sylOldCourseType !== '')>
                <option value="" disabled selected hidden>Select program…</option>
            </select>
        </div>
        <div class="reg-field" style="margin:0;">
            <label for="ofiDrfSemesterId">Semester <span class="ofi-req">*</span></label>
            <select id="ofiDrfSemesterId" name="semesterId" @disabled(! $sylVisible || $sylOldProgram === '') @required($sylVisible)>
                <option value="" disabled selected hidden>Select semester…</option>
            </select>
        </div>
        <div class="reg-field" style="margin:0;">
            <label for="ofiDrfCourseId">Course name <span class="ofi-req">*</span></label>
            <select id="ofiDrfCourseId" name="courseId" @disabled(! $sylVisible || $sylOldSemester === '') @required($sylVisible)>
                <option value="" disabled selected hidden>Select course…</option>
            </select>
        </div>
        <div class="reg-field" style="margin:0;">
            <label for="ofiDrfSchoolYearId">School year <span class="ofi-req">*</span></label>
            <select id="ofiDrfSchoolYearId" name="schoolYearId" @disabled(! $sylVisible || $sylOldCourse === '') @required($sylVisible)>
                <option value="" disabled selected hidden>Select school year…</option>
            </select>
        </div>
    </div>
    <input type="hidden" id="ofiDrfSyllabiSeed" value="{{ $sylOldCourseType }},{{ $sylOldCollege }},{{ $sylOldProgram }},{{ $sylOldSemester }},{{ $sylOldCourse }},{{ $sylOldSchoolYear }}">
</div>

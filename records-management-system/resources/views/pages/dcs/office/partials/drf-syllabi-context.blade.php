{{--
DCS file: Syllabi/TOS context fields on office DRF. Shown after Internal Forms + syllabi-like sub-type.
--}}
@php
    $sylOldCollege = (string) old('collegeId', (string) ($oldCollegeId ?? ''));
    $sylOldProgram = (string) old('programId', (string) ($oldProgramId ?? ''));
    $sylOldSemester = (string) old('semesterId', (string) ($oldSemesterId ?? ''));
    $sylOldCourse = (string) old('courseId', (string) ($oldCourseId ?? ''));
    $sylOldSchoolYear = (string) old('schoolYearId', (string) ($oldSchoolYearId ?? ''));
    $sylVisible = (bool) ($syllabiContextVisible ?? false);
@endphp
<div
    class="ofi-syllabi-context{{ $sylVisible ? '' : ' is-ofi-hidden' }}"
    id="ofiDrfSyllabiContext"
    data-sub-name="{{ $subTypeName ?? '' }}"
    @if(! $sylVisible) hidden @endif
>
    <p class="ofi-hint" style="margin:0 0 10px;">
        Syllabi / TOS requests need college context. RFIO will finish faculty, scans, and the syllabi DRF pack in Register.
    </p>
    <div class="reg-grid-3 ofi-syllabi-grid">
        <div class="reg-field" style="margin:0;">
            <label for="ofiDrfCollegeId">College <span class="ofi-req">*</span></label>
            <select id="ofiDrfCollegeId" name="collegeId" @disabled(! $sylVisible) @required($sylVisible)>
                <option value="">Select college…</option>
            </select>
        </div>
        <div class="reg-field" style="margin:0;">
            <label for="ofiDrfProgramId">Program <span class="ofi-req">*</span></label>
            <select id="ofiDrfProgramId" name="programId" @disabled(! $sylVisible || $sylOldCollege === '') @required($sylVisible)>
                <option value="">Select program…</option>
            </select>
        </div>
        <div class="reg-field" style="margin:0;">
            <label for="ofiDrfSemesterId">Semester <span class="ofi-req">*</span></label>
            <select id="ofiDrfSemesterId" name="semesterId" @disabled(! $sylVisible || $sylOldProgram === '') @required($sylVisible)>
                <option value="">Select semester…</option>
            </select>
        </div>
        <div class="reg-field" style="margin:0;">
            <label for="ofiDrfCourseId">Course name <span class="ofi-req">*</span></label>
            <select id="ofiDrfCourseId" name="courseId" @disabled(! $sylVisible || $sylOldSemester === '') @required($sylVisible)>
                <option value="">Select course…</option>
            </select>
        </div>
        <div class="reg-field" style="margin:0;">
            <label for="ofiDrfSchoolYearId">School year <span class="ofi-req">*</span></label>
            <select id="ofiDrfSchoolYearId" name="schoolYearId" @disabled(! $sylVisible || $sylOldCourse === '') @required($sylVisible)>
                <option value="">Select school year…</option>
            </select>
        </div>
    </div>
    <input type="hidden" id="ofiDrfSyllabiSeed" value="{{ $sylOldCollege }},{{ $sylOldProgram }},{{ $sylOldSemester }},{{ $sylOldCourse }},{{ $sylOldSchoolYear }}">
</div>

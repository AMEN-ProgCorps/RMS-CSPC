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
    $sylOldCourseIds = array_values(array_unique(array_filter(array_map('strval', (array) old('courseIds', $oldCourseIds ?? [])))));
    $sylOldSchoolYear = (string) old('schoolYearId', (string) ($oldSchoolYearId ?? ''));
    $sylOldCourseType = (string) old('courseType', (string) ($oldCourseType ?? ''));
    $sylAllowBatch = (bool) ($allowBatchMode ?? true);
    $sylOldMode = $sylAllowBatch
        ? strtolower(trim((string) old('syllabiMode', (string) ($oldSyllabiMode ?? 'single'))))
        : 'single';
    if (! in_array($sylOldMode, ['single', 'batch'], true)) {
        $sylOldMode = 'single';
    }
    $sylOldBatchItems = is_array($oldBatchItems ?? null) ? $oldBatchItems : (array) old('batchItems', []);
    $sylVisible = (bool) ($syllabiContextVisible ?? false);
@endphp
<div
    class="ofi-syllabi-context{{ $sylVisible ? '' : ' is-ofi-hidden' }}"
    id="ofiDrfSyllabiContext"
    data-sub-name="{{ $subTypeName ?? '' }}"
    @if(! $sylVisible) hidden @endif
>
    <div class="ofi-syllabi-head">
        <div class="ofi-syllabi-head-title">
            <span class="ofi-syllabi-head-icon"><i class="fa-solid fa-book-open"></i></span>
            <div>
                <strong>Syllabi / TOS Course Details</strong>
                <span class="ofi-syllabi-head-sub">
                    @if($sylAllowBatch)
                        Single creates 1 DRF; Batch creates a separate DRF per selected course (with its own title, originator, and description).
                    @else
                        Course context for this Syllabi / TOS-Rubrics request.
                    @endif
                </span>
            </div>
        </div>
        @if($sylAllowBatch)
            <div class="ofi-mode-switch" id="ofiDrfSyllabiModeSwitch" role="tablist" aria-label="Request mode">
                <button type="button" class="ofi-mode-btn{{ $sylOldMode === 'single' ? ' is-active' : '' }}" data-syllabi-mode="single" role="tab" aria-selected="{{ $sylOldMode === 'single' ? 'true' : 'false' }}">
                    <i class="fa-regular fa-file"></i> Single
                </button>
                <button type="button" class="ofi-mode-btn{{ $sylOldMode === 'batch' ? ' is-active' : '' }}" data-syllabi-mode="batch" role="tab" aria-selected="{{ $sylOldMode === 'batch' ? 'true' : 'false' }}">
                    <i class="fa-solid fa-layer-group"></i> Batch
                </button>
            </div>
        @endif
        <input type="hidden" id="ofiDrfSyllabiMode" name="syllabiMode" value="{{ $sylOldMode }}" @disabled(! $sylVisible)>
    </div>

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
            <label for="ofiDrfSchoolYearId">School year <span class="ofi-req">*</span></label>
            <select id="ofiDrfSchoolYearId" name="schoolYearId" @disabled(! $sylVisible || $sylOldSemester === '') @required($sylVisible)>
                <option value="" disabled selected hidden>Select school year…</option>
            </select>
        </div>
        <div class="reg-field{{ $sylOldMode === 'batch' ? ' is-ofi-hidden' : '' }}" style="margin:0;" id="ofiDrfSingleCourseWrap" @if($sylOldMode === 'batch') hidden @endif>
            <label for="ofiDrfCourseId">Course name <span class="ofi-req">*</span></label>
            <select id="ofiDrfCourseId" name="courseId" @disabled(! $sylVisible || $sylOldSchoolYear === '' || $sylOldMode === 'batch') @required($sylVisible && $sylOldMode !== 'batch')>
                <option value="" disabled selected hidden>Select course…</option>
            </select>
        </div>
    </div>

    <div class="ofi-batch-courses{{ $sylOldMode === 'batch' ? '' : ' is-ofi-hidden' }}" id="ofiDrfBatchCoursesWrap" @if($sylOldMode !== 'batch') hidden @endif>
        <div class="ofi-batch-courses-bar">
            <div class="ofi-batch-courses-title">
                <label style="margin:0;">Courses in this batch <span class="ofi-req">*</span></label>
                <span class="ofi-batch-count" id="ofiDrfBatchCount">0 selected</span>
            </div>
            <div class="ofi-batch-courses-actions">
                <input type="search" id="ofiDrfBatchSearch" class="ofi-batch-search" placeholder="Filter courses…" autocomplete="off">
                <button type="button" class="ofi-batch-action-btn" id="ofiDrfBatchSelectAll">Select all</button>
                <button type="button" class="ofi-batch-action-btn" id="ofiDrfBatchClear">Clear</button>
            </div>
        </div>
        <div class="ofi-batch-courses-list" id="ofiDrfBatchCoursesList">
            <div class="ofi-batch-empty">Select course type, college, program, semester, and school year to load courses.</div>
        </div>
    </div>

    <div class="ofi-batch-drfs{{ $sylOldMode === 'batch' ? '' : ' is-ofi-hidden' }}" id="ofiDrfBatchDrfsWrap" @if($sylOldMode !== 'batch') hidden @endif>
        <div class="ofi-batch-drfs-head">
            <div>
                <strong>Individual DRFs to create</strong>
                <span class="ofi-batch-drfs-sub" id="ofiDrfBatchDrfsSub">Select courses above — each course gets its own DRF with a separate title, originator, and description.</span>
            </div>
            <button type="button" class="ofi-batch-action-btn" id="ofiDrfBatchCopyDescBtn" hidden>
                <i class="fa-regular fa-copy"></i> Copy DRF #1 description to all
            </button>
        </div>
        <div class="ofi-batch-drf-cards" id="ofiDrfBatchDrfCards"></div>
    </div>

    <input type="hidden" id="ofiDrfSyllabiSeed" value="{{ $sylOldCourseType }},{{ $sylOldCollege }},{{ $sylOldProgram }},{{ $sylOldSemester }},{{ $sylOldCourse }},{{ $sylOldSchoolYear }}">
    <input type="hidden" id="ofiDrfBatchCoursesSeed" value="{{ implode(',', $sylOldCourseIds) }}">
    <script type="application/json" id="ofiDrfBatchItemsSeed">@json($sylOldBatchItems)</script>
</div>
<style>
#ofiDrfSyllabiContext.ofi-syllabi-context {
    margin-top: 14px;
    padding: 16px 18px;
    border: 1.5px solid #dbe6f7;
    border-radius: 12px;
    background: linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
    box-shadow: 0 2px 8px rgba(13, 42, 122, 0.04);
}
#ofiDrfSyllabiContext.is-ofi-hidden,
#ofiDrfSyllabiContext [hidden],
#ofiDrfSyllabiContext .is-ofi-hidden {
    display: none !important;
}
#ofiDrfSyllabiContext .ofi-syllabi-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    padding-bottom: 12px;
    margin-bottom: 14px;
    border-bottom: 1px solid #e2ecf8;
}
#ofiDrfSyllabiContext .ofi-syllabi-head-title {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}
#ofiDrfSyllabiContext .ofi-syllabi-head-icon {
    width: 34px;
    height: 34px;
    border-radius: 9px;
    background: #e8f0fe;
    color: #0d2a7a;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
    flex-shrink: 0;
}
#ofiDrfSyllabiContext .ofi-syllabi-head-title strong {
    display: block;
    font-size: 0.88rem;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.25;
}
#ofiDrfSyllabiContext .ofi-syllabi-head-sub {
    display: block;
    font-size: 0.74rem;
    color: #64748b;
    margin-top: 2px;
}
#ofiDrfSyllabiContext .ofi-mode-switch {
    display: inline-flex;
    align-items: center;
    padding: 3px;
    border-radius: 9px;
    background: #e9eff8;
    border: 1px solid #d5e0f2;
    gap: 3px;
    flex-shrink: 0;
}
#ofiDrfSyllabiContext .ofi-mode-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 13px;
    border: none;
    border-radius: 7px;
    background: transparent;
    color: #475569;
    font-size: 0.76rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s ease;
}
#ofiDrfSyllabiContext .ofi-mode-btn:hover {
    color: #0d2a7a;
}
#ofiDrfSyllabiContext .ofi-mode-btn.is-active {
    background: #0d2a7a;
    color: #ffffff;
    box-shadow: 0 2px 6px rgba(13, 42, 122, 0.22);
}
#ofiDrfSyllabiContext .ofi-syllabi-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px 16px;
}
#ofiDrfSyllabiContext .ofi-syllabi-grid > .reg-field {
    min-width: 0;
}
#ofiDrfSyllabiContext .ofi-syllabi-grid > .reg-field label {
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    color: #334155;
    margin-bottom: 5px;
}
#ofiDrfSyllabiContext .ofi-syllabi-grid > .reg-field select {
    width: 100%;
    min-width: 0;
    height: 40px;
    border-radius: 8px;
    border: 1.5px solid #cbd5e1;
    background-color: #ffffff;
    font-size: 0.84rem;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
#ofiDrfSyllabiContext .ofi-syllabi-grid > .reg-field select:focus {
    border-color: #0d2a7a;
    box-shadow: 0 0 0 3px rgba(13, 42, 122, 0.1);
}
#ofiDrfSyllabiContext .ofi-syllabi-grid > .reg-field select:disabled {
    background-color: #f1f5f9;
    color: #94a3b8;
    border-color: #e2e8f0;
}
#ofiDrfSyllabiContext .ofi-batch-courses {
    margin-top: 14px;
    padding: 12px 14px;
    border-radius: 10px;
    border: 1.5px solid #dbe6f7;
    background: #ffffff;
}
#ofiDrfSyllabiContext .ofi-batch-courses-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 10px;
}
#ofiDrfSyllabiContext .ofi-batch-courses-title {
    display: flex;
    align-items: center;
    gap: 8px;
}
#ofiDrfSyllabiContext .ofi-batch-count {
    display: inline-flex;
    align-items: center;
    padding: 2px 9px;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 700;
    background: #f1f5f9;
    color: #64748b;
}
#ofiDrfSyllabiContext .ofi-batch-count.has-items {
    background: #dbeafe;
    color: #1d4ed8;
}
#ofiDrfSyllabiContext .ofi-batch-count.is-error {
    background: #fee2e2;
    color: #dc2626;
}
#ofiDrfSyllabiContext .ofi-batch-courses-actions {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
#ofiDrfSyllabiContext .ofi-batch-search {
    height: 30px;
    width: 170px;
    padding: 0 10px;
    border-radius: 7px;
    border: 1px solid #cbd5e1;
    font-size: 0.76rem;
    color: #0f172a;
    background: #f8fafc;
}
#ofiDrfSyllabiContext .ofi-batch-search:focus {
    outline: none;
    border-color: #0d2a7a;
    background: #fff;
}
#ofiDrfSyllabiContext .ofi-batch-action-btn {
    height: 30px;
    padding: 0 10px;
    border-radius: 7px;
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #334155;
    font-size: 0.72rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s ease;
}
#ofiDrfSyllabiContext .ofi-batch-action-btn:hover {
    border-color: #0d2a7a;
    color: #0d2a7a;
    background: #eff6ff;
}
#ofiDrfSyllabiContext .ofi-batch-courses-list {
    max-height: 240px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 5px;
    padding-right: 2px;
}
#ofiDrfSyllabiContext .ofi-batch-row {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 8px 11px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    cursor: pointer;
    font-size: 0.81rem;
    transition: all 0.14s ease;
}
#ofiDrfSyllabiContext .ofi-batch-row:hover:not(.is-blocked) {
    border-color: #93c5fd;
    background: #eff6ff;
}
#ofiDrfSyllabiContext .ofi-batch-row.is-checked {
    border-color: #2563eb;
    background: #eff6ff;
}
#ofiDrfSyllabiContext .ofi-batch-row.is-blocked {
    opacity: 0.6;
    cursor: not-allowed;
    background: #f1f5f9;
}
#ofiDrfSyllabiContext .ofi-batch-row input[type="checkbox"] {
    width: 15px;
    height: 15px;
    accent-color: #0d2a7a;
    flex-shrink: 0;
    cursor: pointer;
}
#ofiDrfSyllabiContext .ofi-batch-code {
    display: inline-flex;
    align-items: center;
    padding: 2px 7px;
    border-radius: 5px;
    background: #e2e8f0;
    color: #1e293b;
    font-size: 0.7rem;
    font-weight: 700;
    flex-shrink: 0;
}
#ofiDrfSyllabiContext .ofi-batch-row.is-checked .ofi-batch-code {
    background: #dbeafe;
    color: #1e40af;
}
#ofiDrfSyllabiContext .ofi-batch-name {
    flex: 1;
    min-width: 0;
    color: #0f172a;
    font-weight: 600;
}
#ofiDrfSyllabiContext .ofi-batch-year {
    font-size: 0.69rem;
    font-weight: 700;
    color: #475569;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    padding: 2px 7px;
    border-radius: 999px;
    flex-shrink: 0;
}
#ofiDrfSyllabiContext .ofi-batch-taken {
    font-size: 0.68rem;
    font-weight: 700;
    color: #b45309;
    background: #fef3c7;
    padding: 2px 7px;
    border-radius: 999px;
    flex-shrink: 0;
}
#ofiDrfSyllabiContext .ofi-batch-empty {
    padding: 16px 12px;
    text-align: center;
    font-size: 0.78rem;
    color: #64748b;
    background: #f8fafc;
    border-radius: 8px;
    border: 1px dashed #cbd5e1;
}
#ofiDrfSyllabiContext .ofi-batch-drfs {
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid #dbe6f7;
}
#ofiDrfSyllabiContext .ofi-batch-drfs-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 10px;
}
#ofiDrfSyllabiContext .ofi-batch-drfs-head strong {
    display: block;
    font-size: 0.84rem;
    font-weight: 700;
    color: #0f172a;
}
#ofiDrfSyllabiContext .ofi-batch-drfs-sub {
    display: block;
    font-size: 0.74rem;
    color: #64748b;
    margin-top: 2px;
}
#ofiDrfSyllabiContext .ofi-batch-drf-cards {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
#ofiDrfSyllabiContext .ofi-batch-drf-card {
    border: 1.5px solid #cbd9ee;
    border-left: 4px solid #0d2a7a;
    border-radius: 10px;
    background: #ffffff;
    padding: 13px 15px;
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.04);
}
#ofiDrfSyllabiContext .ofi-batch-drf-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    flex-wrap: wrap;
    padding-bottom: 9px;
    margin-bottom: 10px;
    border-bottom: 1px solid #edf2f7;
}
#ofiDrfSyllabiContext .ofi-batch-drf-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 3px 9px;
    border-radius: 999px;
    background: #e8f0fe;
    color: #0d2a7a;
    font-size: 0.7rem;
    font-weight: 800;
    letter-spacing: 0.02em;
    text-transform: uppercase;
}
#ofiDrfSyllabiContext .ofi-batch-drf-course {
    font-size: 0.83rem;
    font-weight: 700;
    color: #0f172a;
    flex: 1;
    min-width: 0;
}
#ofiDrfSyllabiContext .ofi-batch-drf-fields {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px 14px;
}
#ofiDrfSyllabiContext .ofi-batch-drf-fields .ofi-batch-drf-full {
    grid-column: 1 / -1;
}
#ofiDrfSyllabiContext .ofi-batch-drf-fields label {
    display: block;
    font-size: 0.69rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #334155;
    margin-bottom: 4px;
}
#ofiDrfSyllabiContext .ofi-batch-drf-fields input,
#ofiDrfSyllabiContext .ofi-batch-drf-fields textarea {
    width: 100%;
    border: 1.5px solid #cbd5e1;
    border-radius: 8px;
    padding: 8px 11px;
    font-size: 0.83rem;
    color: #0f172a;
    background: #ffffff;
    box-sizing: border-box;
}
#ofiDrfSyllabiContext .ofi-batch-drf-fields input:focus,
#ofiDrfSyllabiContext .ofi-batch-drf-fields textarea:focus {
    outline: none;
    border-color: #0d2a7a;
    box-shadow: 0 0 0 3px rgba(13, 42, 122, 0.1);
}
@media (max-width: 720px) {
    #ofiDrfSyllabiContext .ofi-batch-drf-fields {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 900px) {
    #ofiDrfSyllabiContext .ofi-syllabi-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 600px) {
    #ofiDrfSyllabiContext .ofi-syllabi-grid {
        grid-template-columns: 1fr;
    }
}
</style>

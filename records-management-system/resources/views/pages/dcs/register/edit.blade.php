{{--
  DCS PAGE: Edit registration
  FILE: resources/views/pages/dcs/register/edit.blade.php
  DEBUG: RegisterUpdateHelper::update; RegisterQueryHelper::editPayload
--}}
<?php

/**
 * DCS file: Document Registration edit screen. Same subtype and revision-history behavior as the create screen.
 */

use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('CSPC - Document Control System')] class extends Component {
    public int $requestId = 0;

    /**
     * Initializes component state, authorization gates, and default configurations.
     *
     * @param mixed $id
     *
     * @return void
     */
    public function mount($id): void
    {
        $payload = RegisterQueryHelper::editPayload((int) $id);
        if (!empty($payload['blocked'])) {
            session()->flash('error', $payload['error']);
            $this->redirectRoute(! empty($payload['docRequest']->is_draft ?? false) ? 'dcs.register.drafts' : 'dcs.register.update');
            return;
        }
        $this->requestId = (int) $id;
    }

    /**
     * Supplies view data, query collections, and metrics to the Blade template.
     * @return array
     */
    public function with(): array
    {
        if ($this->requestId < 1) {
            return ['catalog' => [], 'docRequest' => null];
        }
        $payload = RegisterQueryHelper::editPayload($this->requestId);
        $payload['catalog'] = RegisterQueryHelper::jsCatalog();
        return $payload;
    }
}; ?>

@if($docRequest)
@php
    $reviseNo = (int) (($masterlist->revise_no ?? 0));
    $versionName = strtolower((string) \Illuminate\Support\Facades\DB::table('dcs_version_type')->where('id', $docRequest->version_id ?? 0)->value('version_name'));
    $allowsRetrieval = str_contains($versionName, 'revis');
    $canEditDocument = (bool) ($can_edit ?? false);
    $readOnly = (bool) ($read_only ?? ! $canEditDocument);
    $isDraftDoc = ! empty($docRequest->is_draft);
    $recurPreviousDocuments = collect($recurPreviousDocuments ?? []);
    $recurSource = $recurPreviousDocuments->first(fn ($row) => ($row['status'] ?? '') === 'Superseded')
        ?? $recurPreviousDocuments->first();
    $supersedeFinderLocked = $recurPreviousDocuments->isNotEmpty()
        || ! empty($recurSource)
        || trim((string) (optional($masterlist)->revised_from_doc_no ?? '')) !== '';
    $listRoute = $isDraftDoc ? 'dcs.register.drafts' : 'dcs.register.update';
    $listLabel = $isDraftDoc
        ? 'Back to Drafts'
        : ($readOnly ? 'Back to Update Documents' : 'Back to List');
    $appraisalPending = ! $isDraftDoc
        && \App\Services\RdpIntakeService::adminAppraisalPending((int) $docRequest->id);
@endphp
<script>
window.APP_CONFIG = {
    CURRENT_VERSION_ID: {{ $docRequest->version_id }},
    CURRENT_DOC_TYPE_ID: {{ $docRequest->doc_type_id }},
    CURRENT_SUB_TYPE_ID: {{ $docRequest->sub_type_id ?: 'null' }},
    CURRENT_APPROVAL_BODY: '{{ $approval?->approval_body_id ?? '' }}',
    IS_DRAFT: {{ !empty($docRequest->is_draft) ? 'true' : 'false' }},
};
window.__supersedeHistoryRestored = {{ $recurPreviousDocuments->isNotEmpty() ? 'true' : 'false' }};
window.__recurFromDatabase = {{ $supersedeFinderLocked ? 'true' : 'false' }};
window.__recurSelectionLocked = {{ $supersedeFinderLocked ? 'true' : 'false' }};
window.__excludeSyllabiRequestId = {{ (int) $docRequest->id }};
window.__existingRelatedDocs = @json($relatedDocsData);
window.__existingDrfOffices = @json($drfOfficesSeed);
window.__existingDcnOffices = @json($dcnOfficesSeed);
window.__existingMasterlistSource = @json($masterlistSourceSeed);
window.__existingMasterlistOriginator = @json($masterlistOriginatorSeed);
window.__existingSyllabiGroups = @json($syllabiGroupsSeed);
{{-- Registered (non-draft) syllabi: lock Course Type / College / Semester / School Year; Year Level stays editable. --}}
window.__syllabiEditLocked = {{ empty($docRequest->is_draft) ? 'true' : 'false' }};
window.__allowsRetrieval = @json($allowsRetrieval);
window.__registerCatalog = @json($catalog);
window.__editReadOnly = @json($readOnly);
window.__canEditDocument = @json($canEditDocument);
window.__timeSpentNonWorkingDates = @json(\App\Helpers\CalendarHelper::nonWorkingDatesForTimeSpent());
window.__timeSpentNonWorkingDateSet = Object.create(null);
(window.__timeSpentNonWorkingDates || []).forEach(function (iso) {
    window.__timeSpentNonWorkingDateSet[iso] = true;
});
</script>
<div class="reg-container main-content {{ $readOnly ? 'is-readonly-edit' : '' }}" id="dcsEditRoot" wire:ignore
    x-data="{
        syllabiStep: 1,
        reviewOpen: false,
        setSyllabiStep(step) { this.syllabiStep = Number(step) === 2 ? 2 : 1; window.syllabiCurrentStep = this.syllabiStep; },
        closeReview() {
            if (document.getElementById('confirmModal')?.classList.contains('is-saving')) return;
            window.__openAppraisalAfterSave = false;
            const appraisalFlag = document.getElementById('openAppraisal');
            if (appraisalFlag) appraisalFlag.value = '0';
            this.reviewOpen = false;
            const el = document.getElementById('dcsEditRoot');
            if (el) el.style.overflow = '';
        },
        addSyllabiRow() { if (typeof window.addSyllabiRow === 'function') window.addSyllabiRow(); },
    }">
        <!-- Header -->
        <div class="reg-header">
            <div>
                <div class="reg-breadcrumb">Document Control System / Update / <span>{{ $readOnly ? 'View' : 'Edit' }}</span></div>
                <div style="display:flex; align-items:center; gap:12px;">
                    <div class="reg-title">{{ trim((string) ($masterlist->doc_no ?? '')) !== '' ? $masterlist->doc_no : 'Document #' . $docRequest->id }}</div>
                    @if($readOnly)
                        <span class="edit-badge" style="background:#e2e8f0;color:#334155;"><i class="fa-solid fa-eye"></i> View only</span>
                    @else
                        <span class="edit-badge"><i class="fa-solid fa-pen"></i> Editing</span>
                    @endif
                </div>
                @if($readOnly)
                    <p style="margin:8px 0 0;color:#64748b;font-size:0.92rem;">
                        Fields are locked. Generate the distribution template if needed, then return to Update Documents.
                    </p>
                @endif
            </div>
            <a href="{{ route($listRoute) }}" class="reg-btn reg-btn-cancel">
                <i class="fa-solid fa-arrow-left"></i> {{ $listLabel }}
            </a>
        </div>

        <form id="masterForm" method="POST" action="{{ route('dcs.register.updateDoc', $docRequest->id) }}" enctype="multipart/form-data" class="{{ $readOnly ? 'is-readonly' : '' }}">
            <input type="hidden" id="requestId" value="{{ $docRequest->id }}">
            <input type="hidden" id="registrationMode" name="registration_mode" value="new">
            <input type="hidden" id="revisedFromDocNo" name="revised_from_doc_no" value="{{ optional($masterlist)->revised_from_doc_no }}">
            <input type="hidden" id="supersedeMasterlistId" name="supersede_masterlist_id" value="">
            <input type="hidden" id="insertShiftConfirmed" name="insert_shift_confirmed" value="0">
            <input type="hidden" id="allowDuplicateDocNo" name="allow_duplicate_doc_no" value="0">
            <input type="hidden" id="stackWithRequestId" name="stack_with_request_id" value="">
            <input type="hidden" id="insertShiftDocNo" name="insert_shift_doc_no" value="">
            <input type="hidden" id="insertShiftRenameLetters" name="insert_shift_rename_letters" value="0">
            <input type="hidden" id="insertShiftRequestIds" name="insert_shift_request_ids" value="">
            <input type="hidden" id="saveAsDraft" name="save_as_draft" value="0">
            <input type="hidden" id="openAppraisal" name="open_appraisal" value="0">
            <input type="hidden" name="has_existing_masterlist_scan" value="{{ !empty($masterlist?->scanned_masterlist) ? '1' : '0' }}">
            <input type="hidden" id="officeIntakeType" name="office_intake_type" value="{{ in_array(request()->query('intake'), ['drf', 'dcn'], true) ? request()->query('intake') : '' }}">
            <input type="hidden" id="officeIntakeId" name="office_intake_id" value="{{ ctype_digit((string) request()->query('intake_id')) ? request()->query('intake_id') : '' }}">
            <input type="hidden" id="officeIntakePeerIds" name="office_intake_peer_ids" value="">
            @csrf
            @method('PUT')

            <!-- ═══ TOP PANEL ═══ -->
            <div class="reg-panel">
                <div class="reg-panel-grid">
                    <div class="reg-field">
                        <label>Version Type</label>
                        <select id="versionType" name="version_id" autocomplete="off" disabled data-last-valid="{{ $docRequest->version_id }}">
                            <option value="" disabled>Select version</option>
                        </select>
                        <input type="hidden" name="version_id" value="{{ $docRequest->version_id }}">
                    </div>
                    <div class="reg-field">
                        <label>Document Type</label>
                        <select id="docType" name="doc_type_id" autocomplete="off" disabled data-last-valid="{{ $docRequest->doc_type_id }}">
                            <option value="" disabled>Select document type</option>
                        </select>
                        <input type="hidden" name="doc_type_id" value="{{ $docRequest->doc_type_id }}">
                    </div>
                    <div class="reg-field" id="subTypeField" hidden>
                        <label>Sub-Type Document</label>
                        {{-- Drafts saved without a sub-type stay editable so it can be filled later --}}
                        <select id="subType" name="sub_type_id" autocomplete="off"
                            @if(!empty($docRequest->is_draft) && empty($docRequest->sub_type_id))
                                data-draft-unlock="1"
                            @else
                                disabled
                            @endif
                        >
                            <option value="" selected disabled>Select sub-type</option>
                        </select>
                        @if($docRequest->sub_type_id)
                            <input type="hidden" name="sub_type_id" value="{{ $docRequest->sub_type_id }}">
                        @endif
                    </div>
                </div>

                <div class="reg-panel-bottom">
                    <div id="dynamicCheckboxes" class="reg-checklist"></div>
                </div>
            </div>

            <!-- ═══ SYLLABI / TOS-Rubrics (mirrors register — context editable on edit) ═══ -->
            <section class="reg-card" id="section-syllabi" style="display: {{ $isSyllabiLikeEdit ? 'block' : 'none' }};">
                <div class="reg-card-header">
                    <span>Syllabi</span>
                </div>
                <div class="reg-card-body">

                    <div class="reg-grid-3 reg-grid-syllabi-context">
                        <div class="reg-field">
                            <label>College</label>
                            <select id="syllabiCollege">
                                <option value="" selected disabled>Select college</option>
                            </select>
                        </div>
                        <div class="reg-field">
                            <label>Course Type</label>
                            <select id="syllabiCourseType">
                                <option value="" selected disabled>Select course type</option>
                                <option value="GE Courses">GE Courses</option>
                                <option value="PE Courses">PE Courses</option>
                                <option value="NSTP">NSTP</option>
                                <option value="Major">Major</option>
                            </select>
                        </div>
                        <div class="reg-field reg-field--programs">
                            <label>College / Program</label>
                            <div class="reg-cp-picker" id="syllabiCollegeProgramPicker">
                                <button type="button" class="reg-cp-trigger" id="syllabiCpTrigger" aria-haspopup="listbox" aria-expanded="false">
                                    <span id="syllabiCpTriggerLabel">Select college / program</span>
                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                </button>
                                <div class="reg-cp-panel" id="syllabiCpPanel" hidden>
                                    <div class="reg-cp-colleges" id="syllabiCpColleges" role="list"></div>
                                    <div class="reg-cp-programs" id="syllabiCpPrograms" role="group" aria-label="Programs">
                                        <div class="reg-cp-programs-empty">Hover or click a college</div>
                                    </div>
                                </div>
                            </div>
                            <div class="reg-cp-selected" id="syllabiCpSelected" hidden></div>
                            <div id="syllabiProgramIdsHiddens"></div>
                            <select id="syllabiProgram" hidden aria-hidden="true" tabindex="-1">
                                <option value="" selected disabled>Select program</option>
                            </select>
                            <div id="syllabiProgramPicker" hidden aria-hidden="true"></div>
                            <select id="syllabiCollegeProgramMulti" hidden aria-hidden="true" multiple tabindex="-1"></select>
                        </div>
                        <div class="reg-field">
                            <label>Semester</label>
                            <select id="syllabiSemester" disabled>
                                <option value="" selected disabled>Select semester</option>
                            </select>
                        </div>
                        <div class="reg-field reg-field--year-level">
                            <label>Year Level</label>
                            <div id="syllabiYearLevelPicker" class="reg-syllabi-years is-empty" role="group" aria-label="Year levels to include">Select college, course type, program(s), and semester</div>
                            <div id="syllabiYearLevelHiddens"></div>
                        </div>
                        <div class="reg-field">
                            <label>School Year</label>
                            <select id="syllabiSchoolYear" disabled>
                                <option value="" selected disabled>Select school year</option>
                            </select>
                        </div>
                    </div>
                    <div id="syllabiContextHint" class="reg-field-hint" style="display:none; margin: -4px 0 12px;" role="status" aria-live="polite"></div>
                    @if($syllabiContextSeed)
                        <input type="hidden" name="college_id" id="syllabiCollegeHidden" value="{{ $syllabiContextSeed->college_id }}">
                        <input type="hidden" name="program_id" id="syllabiProgramHidden" value="{{ $syllabiContextSeed->program_id }}">
                        <input type="hidden" name="semester_id" id="syllabiSemesterHidden" value="{{ $syllabiContextSeed->semester_id }}">
                        <input type="hidden" name="course_type" id="syllabiCourseTypeHidden" value="{{ $syllabiContextSeed->course_type ?? '' }}">
                        <input type="hidden" name="school_year_id" id="syllabiSchoolYearHidden" value="{{ $syllabiContextSeed->school_year_id }}">
                    @else
                        <input type="hidden" name="college_id" id="syllabiCollegeHidden" value="">
                        <input type="hidden" name="program_id" id="syllabiProgramHidden" value="">
                        <input type="hidden" name="semester_id" id="syllabiSemesterHidden" value="">
                        <input type="hidden" name="course_type" id="syllabiCourseTypeHidden" value="">
                        <input type="hidden" name="school_year_id" id="syllabiSchoolYearHidden" value="">
                    @endif

                    <div class="reg-field" style="margin-bottom: 16px;">
                        <label>Document Title</label>
                        <input type="text" id="syllabiDocTitle" name="syllabiDocTitle" placeholder="Enter Document Title" value="{{ $masterlist->doc_title ?? '' }}">
                    </div>

                    <div class="reg-wizard-steps" id="syllabiStepIndicator" role="tablist" aria-label="Syllabi sections">
                        <button type="button" role="tab" class="reg-wizard-step" :class="{ 'is-active': syllabiStep === 1 }" :aria-selected="syllabiStep === 1 ? 'true' : 'false'" data-step="1" @click="setSyllabiStep(1)"><span>1</span> Course Info</button>
                        <button type="button" role="tab" class="reg-wizard-step" :class="{ 'is-active': syllabiStep === 2 }" :aria-selected="syllabiStep === 2 ? 'true' : 'false'" data-step="2" @click="setSyllabiStep(2)"><span>2</span> DRF</button>
                    </div>

                    <div class="reg-field">
                        <div class="reg-table-wrap">
                            <table class="reg-table reg-wizard-table" id="syllabiWizardTable" :data-active-step="syllabiStep">
                                <thead>
                                    <tr>
                                        <th class="col-pinned">Course Name</th>
                                        <th class="col-pinned col-code">Course Code</th>
                                        <th class="col-step1 col-avail" id="syllabiAvailabilityHeader">Syllabi Availability</th>
                                        <th class="col-step1 col-copies">Copies</th>
                                        <th class="col-shared">Faculty</th>
                                        <th class="col-step1 col-pages">Pages</th>
                                        <th class="col-step1 col-date">Date Received</th>
                                        <th class="col-step1 col-time">Time Received</th>

                                        <th class="col-step2">DRF Availability</th>
                                        <th class="col-step2">DRF No.</th>
                                        <th class="col-step2">DRF Date</th>
                                        <th class="col-step2">DRF Received Date</th>
                                        <th class="col-step2">Scanned DRF</th>

                                        <th class="col-pinned col-actions"></th>
                                    </tr>
                                </thead>
                                <tbody id="syllabiTableBody"></tbody>
                            </table>
                        </div>
                        <button type="button" id="btnAddSyllabiRow" x-show="syllabiStep === 1" @click="addSyllabiRow()">
                            <i class="fa-solid fa-plus"></i> Add Course
                        </button>
                        <div class="reg-syllabi-copies-total">
                            Total No. of Copies: <span id="totalSyllabiCopies">0</span>
                            &nbsp;·&nbsp;
                            Total No. of Pages: <span id="totalSyllabiPages">0</span>
                        </div>
                    </div>

                    <div class="reg-wizard-nav" x-show="syllabiStep === 2">
                        <span id="syllabiStep2Hint" class="reg-wizard-hint">
                            Scroll down and click <strong>Update Document</strong> to submit.
                        </span>
                    </div>
                </div>
            </section>

            <section class="reg-card{{ $supersedeFinderLocked ? ' is-finder-locked' : '' }}" id="section-supersede" style="display: none;">
                <div class="reg-card-header">
                    <span>Document to be Superseded</span>
                </div>
                <div class="reg-card-body">
                    <div class="reg-supersede-finder" id="supersedeFinderBlock">
                        <p class="reg-number-hint" id="supersedeFinderHint" @if($supersedeFinderLocked) hidden @endif>Enter the document number and title. The originator picks the exact latest copy when more than one document shares that number.</p>
                        <div class="reg-supersede-finder-anchor">
                            <div class="reg-grid-3 reg-supersede-finder-fields">
                                <div class="reg-field">
                                    <label for="supersedeDocNo">Document No.</label>
                                    <input type="text" id="supersedeDocNo" placeholder="Document number" autocomplete="off" value="{{ $recurSource['doc_no'] ?? '' }}" @if($supersedeFinderLocked) readonly disabled tabindex="-1" @endif>
                                </div>
                                <div class="reg-field">
                                    <label for="supersedeDocTitle">Document Title</label>
                                    <input type="text" id="supersedeDocTitle" placeholder="Title on the current document" autocomplete="off" value="{{ $recurSource['doc_title'] ?? '' }}" @if($supersedeFinderLocked) readonly disabled tabindex="-1" @endif>
                                </div>
                                <div class="reg-field">
                                    <label for="supersedeDocOriginator">Originator</label>
                                    <input type="text" id="supersedeDocOriginator" placeholder="Originator of that copy" autocomplete="off" value="{{ $recurSource['originator_name'] ?? '' }}" @if($supersedeFinderLocked) readonly disabled tabindex="-1" @endif>
                                </div>
                            </div>
                            <div id="supersedeDocResults" class="reg-reldocs-dropdown" style="display:none;"></div>
                        </div>
                        {{-- Selection summary removed: Previous documents table already shows the superseded copy. --}}
                        <p id="supersedeDocPicked" class="reg-supersede-selected-text" hidden>@if($recurSource){{ $recurSource['doc_no'] }} — {{ $recurSource['doc_title'] }} (Rev {{ $recurSource['revise_no'] }}, {{ $recurSource['status'] }}) {{ strtolower((string) ($recurSource['status'] ?? '')) === 'latest' ? 'stays the current copy until this recurring document is saved.' : 'was superseded.' }}@endif</p>
                        <button type="button" id="supersedeReselect" class="reg-supersede-change" hidden><i class="fa-solid fa-arrow-right-arrow-left"></i> Change document</button>
                    </div>
                    <div class="reg-field reg-revision-table" id="supersedeHistoryField" @if($recurPreviousDocuments->isEmpty()) hidden @endif>
                        <label>Previous documents</label>
                        <div class="reg-table-wrap">
                            <table class="reg-table">
                                <thead>
                                    <tr>
                                        <th>Document No.</th>
                                        <th>Document Title</th>
                                        <th>Effectivity Date</th>
                                        <th>Revision No.</th>
                                        <th>Status</th>
                                        <th>Scanned Copy</th>
                                        <th>Brief Purpose</th>
                                    </tr>
                                </thead>
                                <tbody id="supersedeHistoryBody">
                                    @foreach($recurPreviousDocuments as $prev)
                                        <tr>
                                            <td><span class="reg-rev-title-text">{{ $prev['doc_no'] }}</span></td>
                                            <td><span class="reg-rev-title-text{{ mb_strlen((string) $prev['doc_title']) > 28 ? ' is-wrap' : '' }}">{{ $prev['doc_title'] }}</span></td>
                                            <td><span class="reg-rev-title-text">{{ $prev['effectivity'] }}</span></td>
                                            <td class="reg-supersede-rev"><span class="reg-rev-title-text">{{ $prev['revise_no'] }}</span></td>
                                            <td><span class="reg-rev-status is-{{ $prev['status_class'] }}">{{ $prev['status'] }}</span></td>
                                            <td style="text-align:center;">
                                                @if(!empty($prev['scanned_copy_url']))
                                                    <button type="button" class="reg-revrow-viewfile reg-revrow-viewfile-pdf" data-scan-url="{{ $prev['scanned_copy_url'] }}" title="View PDF"><i class="fa-solid fa-file-pdf"></i></button>
                                                @else
                                                    <span class="reg-rev-title-text">—</span>
                                                @endif
                                            </td>
                                            <td><span class="reg-rev-title-text">{{ $prev['brief_purpose'] !== '' ? $prev['brief_purpose'] : '—' }}</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <section class="reg-card" id="section-2" style="display: {{ $dcn ? 'block' : 'none' }};">
                <div class="reg-card-header">
                    <span>Document Change Notice</span>
                </div>
                <div class="reg-card-body">
                    <div class="reg-field reg-revision-table {{ $isDraftDoc ? '' : 'is-registered' }}" id="revisionTableField">
                        <label>Document Revisions</label>
                        <div class="reg-revision-loading" id="revisionTableLoading" aria-live="polite" aria-busy="false">
                            <div class="dcs-loading-spinner" aria-hidden="true"></div>
                            <p class="reg-revision-loading-text">Loading document…</p>
                            <p class="reg-revision-loading-sub">Fetching revision history</p>
                        </div>
                        <div class="reg-table-wrap">
                            <table class="reg-table">
                                <thead>
                                    <tr>
                                        <th>Document No.</th>
                                        <th>Document Title</th>
                                        <th>Effectivity Date</th>
                                        <th>Revision No.</th>
                                        <th>Scanned Copy</th>
                                        <th>Brief Purpose</th>
                                        <th class="reg-rev-del-col"></th>
                                    </tr>
                                </thead>
                                <tbody id="revisionTableBody">
                                    @php
                                        $revisionLatestRev = [];
                                        foreach ($revisions as $revRow) {
                                            $revKey = mb_strtolower(trim((string) ($revRow->document_no ?? ''))).'|'.mb_strtolower(trim((string) ($revRow->title ?? '')));
                                            $revNum = (int) ($revRow->revision_no ?? 0);
                                            if (! isset($revisionLatestRev[$revKey]) || $revNum > $revisionLatestRev[$revKey]) {
                                                $revisionLatestRev[$revKey] = $revNum;
                                            }
                                        }
                                    @endphp
                                    @forelse($revisions as $rev)
                                    @php
                                        $revKey = mb_strtolower(trim((string) ($rev->document_no ?? ''))).'|'.mb_strtolower(trim((string) ($rev->title ?? '')));
                                        $revIsLatest = (int) ($rev->revision_no ?? 0) === (int) ($revisionLatestRev[$revKey] ?? 0);
                                    @endphp
                                    <tr data-linked="true" data-linked-family="{{ $revKey }}" @unless($isDraftDoc) data-saved="1" @endunless @unless($isDraftDoc && $revIsLatest) data-history-locked="true" @endunless>
                                        <td>
                                            <input type="text" name="documentNo[]" placeholder="Search or enter document no." value="{{ $rev->document_no }}" readonly class="reg-revrow-locked">
                                            <input type="hidden" name="revisionScannedPath[]" value="{{ $rev->scanned_copy }}">
                                        </td>
                                        <td class="reg-rev-title-cell">
                                            <input type="hidden" name="documentTitle[]" value="{{ $rev->title }}">
                                            <span class="reg-rev-title-text {{ strlen(trim((string) ($rev->title ?? ''))) > 28 ? 'is-wrap' : '' }}">{{ $rev->title !== null && $rev->title !== '' ? $rev->title : '—' }}</span>
                                        </td>
                                        <td><input type="date" name="effectiveDate[]" value="{{ \App\Helpers\RegisterQueryHelper::formatDate($rev->effectivity_date) }}" readonly class="reg-revrow-locked" tabindex="-1"></td>
                                        <td><input type="number" name="revisionNo[]" placeholder="—" value="{{ $rev->revision_no }}" readonly class="reg-revrow-locked" tabindex="-1"></td>
                                        <td class="reg-rev-scan-cell" style="text-align:center;">
                                            @if($rev->scanned_copy)
                                                <a href="{{ RegisterQueryHelper::scanUrl($rev->scanned_copy) }}" target="_blank" class="reg-revrow-viewfile reg-revrow-viewfile-pdf" title="View PDF">
                                                    <i class="fa-solid fa-file-pdf"></i>
                                                </a>
                                            @else
                                                <div class="reg-file-error" style="margin:0;">
                                                    <i class="fa-solid fa-circle-exclamation"></i> No scanned copy on file
                                                </div>
                                            @endif
                                        </td>
                                        <td class="reg-rev-purpose-cell">
                                            <input type="hidden" name="revisionPurpose[]" value="{{ $rev->brief_purpose }}">
                                            <span class="reg-rev-purpose-text {{ strlen(trim((string) ($rev->brief_purpose ?? ''))) > 42 ? 'is-wrap' : '' }}">{{ $rev->brief_purpose !== null && $rev->brief_purpose !== '' ? $rev->brief_purpose : '—' }}</span>
                                        </td>
                                        <td class="reg-rev-del-col">
                                            @if($isDraftDoc && $revIsLatest)
                                                <button type="button" class="reg-row-del" onclick="removeRevisionRow(this)"><i class="fa-solid fa-trash-can"></i></button>
                                            @endif
                                        </td>
                                    </tr>
                                    @empty
                                    <tr>
                                        <td>
                                            <input type="text" name="documentNo[]" placeholder="Search or enter document no." autocomplete="off">
                                            <input type="hidden" name="revisionScannedPath[]" value="">
                                        </td>
                                        <td><input type="text" name="documentTitle[]" placeholder="Search or enter document title" autocomplete="off"></td>
                                        <td><input type="date" name="effectiveDate[]" readonly class="reg-revrow-locked" tabindex="-1"></td>
                                        <td><input type="number" name="revisionNo[]" placeholder="—" readonly class="reg-revrow-locked" tabindex="-1"></td>
                                        <td class="reg-rev-scan-cell" style="text-align:center;color:#475569;">—</td>
                                        <td class="reg-rev-purpose-cell">
                                            <input type="hidden" name="revisionPurpose[]" value="">
                                            <span class="reg-rev-purpose-text">—</span>
                                        </td>
                                        <td class="reg-rev-del-col"><button type="button" class="reg-row-del" onclick="removeRevisionRow(this)"><i class="fa-solid fa-trash-can"></i></button></td>
                                    </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <button type="button" class="reg-add-row" onclick="addRevisionRow()">
                            <i class="fa-solid fa-plus"></i> Add Revision Row
                        </button>
                    </div>

                    <div class="reg-field">
                        <label>Justification</label>
                        <input type="text" id="dcnJustification" name="dcnJustification" placeholder="Enter justification for this change notice..." value="{{ $dcn->brief_purpose ?? '' }}">
                    </div>

                    <div class="reg-grid-3">
                        <div class="reg-field">
                            <label>DCN No.</label>
                            <input type="text" id="dcnNumber" name="dcnNumber" placeholder="{{ now('Asia/Manila')->format('Y-m') }}-046" value="{{ $dcn->dcn_no ?? '' }}" autocomplete="off">
                            <span id="dcnNoHint" class="reg-number-hint" style="display:none;"></span>
                            <span id="dcnNoSuggestHint" class="reg-number-hint" style="display:none;"></span>
                        </div>
                        <div class="reg-field">
                            <label>DCN Date</label>
                            <input type="date" id="noticeDate" name="noticeDate" value="{{ \App\Helpers\RegisterQueryHelper::formatDate($dcn->dcn_date ?? '') }}">
                        </div>
                        <div class="reg-field">
                            <label>DCN Receipt</label>
                            <div class="reg-dual">
                                <input type="date" id="receiptDate" name="receiptDate" value="{{ \App\Helpers\RegisterQueryHelper::formatDate($dcn->dcn_receipt_date ?? '') }}">
                                <input type="time" id="receiptTime" name="receiptTime" value="{{ \App\Helpers\RegisterQueryHelper::formatTime($dcn->dcn_receipt_time ?? '') }}">
                            </div>
                        </div>
                    </div>
                    <div class="reg-grid-2-1">
                        <div class="reg-field">
                            <label>Upload Scanned DCN</label>
                            @if($dcn && $dcn->scanned_dcn)
                                <div class="reg-current-file">
                                    <i class="fa-solid fa-file-pdf"></i>
                                    <span>{{ basename($dcn->scanned_dcn) }}</span>
                                    <a href="{{ RegisterQueryHelper::scanUrl($dcn->scanned_dcn) }}" target="_blank">View</a>
                                </div>
                            @endif
                            <label class="reg-upload">
                                <input type="file" id="dcnFile" name="dcnFile" accept=".pdf">
                                <i class="fa-solid fa-cloud-arrow-up"></i>
                                <span>{{ $dcn && $dcn->scanned_dcn ? 'Replace file' : 'Choose scanned PDF' }}</span>
                            </label>
                            <p class="reg-scan-name-preview" data-scan-preview="dcn">
                                <strong>{{ $dcn && $dcn->scanned_dcn ? 'Replacement will be saved as' : 'Will be saved as' }}</strong>
                                <code data-scan-preview-name>—</code>
                            </p>
                        </div>
                        <div class="reg-field">
                            <label>Source Unit</label>
                            <div class="reg-reldocs" id="dcnSourceUnitWidget">
                                <div class="reg-reldocs-inputwrap">
                                    <input type="text" id="dcnSourceUnitSearch" class="reg-reldocs-input"
                                        placeholder="Type to search offices..." autocomplete="off">
                                    <button type="button" class="reg-reldocs-arrow-btn" id="dcnSourceArrowBtn">
                                        <i class="fa-solid fa-chevron-down"></i>
                                    </button>
                                </div>
                                <div id="dcnSourceResults" class="reg-reldocs-dropdown" style="display:none;"></div>
                                <div id="dcnSourceInlineChips" class="reg-reldocs-dropdown reg-reldocs-selected-panel" style="display:none;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ═══ SECTION 1 — DRF ═══ -->
            <section class="reg-card" id="section-1" style="display: {{ $drf ? 'block' : 'none' }};">
                <div class="reg-card-header">
                    <span>Document Request Form</span>
                </div>
                <div class="reg-card-body">
                    <div class="reg-grid-3">
                        <div class="reg-field">
                            <label>DRF No.</label>
                            <input type="text" id="drfNo" name="drfNo" placeholder="{{ now('Asia/Manila')->format('Y') }}-001" value="{{ $drf->drf_no ?? '' }}" autocomplete="off">
                            <span id="drfNoHint" class="reg-number-hint" style="display:none;"></span>
                            <span id="drfNoSuggestHint" class="reg-number-hint" style="display:none;"></span>
                        </div>
                        <div class="reg-field">
                            <label>DRF Date</label>
                            <input type="date" id="drfDate" name="drfDate" value="{{ \App\Helpers\RegisterQueryHelper::formatDate($drf->drf_date ?? '') }}">
                        </div>
                        <div class="reg-field">
                            <label>Date Receipt</label>
                            <div class="reg-dual">
                                <input type="date" id="drfReceiptDate" name="drfReceiptDate" value="{{ \App\Helpers\RegisterQueryHelper::formatDate($drf->drf_receipt_date ?? '') }}">
                                <input type="time" id="drfTime" name="drfTime" value="{{ \App\Helpers\RegisterQueryHelper::formatTime($drf->drf_receipt_time ?? '') }}">
                            </div>
                        </div>
                    </div>
                    <div class="reg-grid-2-1">
                        <div class="reg-field">
                            <label>Document Title</label>
                            <input type="text" id="drfTitle" name="drfTitle" placeholder="Title" value="{{ $drf->doc_title ?? '' }}">
                        </div>
                        <div class="reg-field">
                            <label>Source Unit</label>
                            <div class="reg-reldocs" id="drfSourceUnitWidget">
                                <div class="reg-reldocs-inputwrap">
                                    <input type="text" id="drfSourceUnitSearch" class="reg-reldocs-input"
                                        placeholder="Type to search offices..." autocomplete="off">
                                    <button type="button" class="reg-reldocs-arrow-btn" id="drfSourceArrowBtn">
                                        <i class="fa-solid fa-chevron-down"></i>
                                    </button>
                                </div>
                                <div id="drfSourceResults" class="reg-reldocs-dropdown" style="display:none;"></div>
                                <div id="drfSourceInlineChips" class="reg-reldocs-dropdown reg-reldocs-selected-panel" style="display:none;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="reg-field">
                        <label>Upload Scanned DRF</label>
                        @if($drf && $drf->scanned_drf)
                            <div class="reg-current-file">
                                <i class="fa-solid fa-file-pdf"></i>
                                <span>{{ basename($drf->scanned_drf) }}</span>
                                <a href="{{ RegisterQueryHelper::scanUrl($drf->scanned_drf) }}" target="_blank">View</a>
                            </div>
                        @endif
                        <label class="reg-upload">
                            <input type="file" id="drfFile" name="drfFile" accept=".pdf">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <span>{{ $drf && $drf->scanned_drf ? 'Replace file' : 'Choose scanned PDF' }}</span>
                        </label>
                        <p class="reg-scan-name-preview" data-scan-preview="drf">
                            <strong>{{ $drf && $drf->scanned_drf ? 'Replacement will be saved as' : 'Will be saved as' }}</strong>
                            <code data-scan-preview-name>—</code>
                        </p>
                    </div>
                </div>
            </section>

            <!-- ═══ APPROVAL (before Masterlist) ═══ -->
            <section class="reg-card reg-approval-bar" id="section-approval-toggle" style="display: {{ $masterlist ? 'block' : 'none' }};">
                <div class="reg-card-body reg-approval-bar-body">
                    <div class="reg-approval-toggle">
                        <span class="reg-toggle-label">Approval</span>
                        <div class="reg-toggle-options">
                            <label class="reg-radio">
                                <input type="radio" name="approval_status" value="applicable"
                                    {{ $docRequest->approval_status === 'applicable' ? 'checked' : '' }}
                                    onchange="handleApprovalToggle(true)">
                                <span>Applicable</span>
                            </label>
                            <label class="reg-radio">
                                <input type="radio" name="approval_status" value="not_applicable"
                                    {{ $docRequest->approval_status !== 'applicable' ? 'checked' : '' }}
                                    onchange="handleApprovalToggle(false)">
                                <span>Not Applicable</span>
                            </label>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ═══ APPROVAL ═══ -->
            <section class="reg-card" id="section-approval" style="display: {{ ($approval || ($docRequest->approval_status ?? '') === 'applicable') ? 'block' : 'none' }};">
                <div class="reg-card-header">
                    <span>Approval Details</span>
                </div>
                <div class="reg-card-body">
                    <div class="reg-grid-3">
                        <div class="reg-field">
                            <label>Approving Body</label>
                            <select id="approvalBody" name="approvalBody" autocomplete="off">
                                <option value="" selected disabled>Select approving body</option>
                            </select>
                        </div>
                        <div class="reg-field">
                            <label>Approval Date</label>
                            <input type="date" id="approvalDate" name="approvalDate" value="{{ \App\Helpers\RegisterQueryHelper::formatDate($approval->approval_date ?? '') }}">
                        </div>
                        <div class="reg-field">
                            <label>Approval No.</label>
                            <input type="text" id="approvalNo" name="approvalNo" placeholder="Approval number" value="{{ $approval->approval_no ?? '' }}">
                        </div>
                    </div>
                </div>
            </section>

            <!-- ═══ SECTION 3 — MASTERLIST ═══ -->
            <section class="reg-card" id="section-3" style="display: {{ $masterlist ? 'block' : 'none' }};">
                <div class="reg-card-header">
                    <span>Masterlist Registration</span>
                </div>
                <div class="reg-card-body">
                    <div class="reg-ml-grid">
                        <div class="reg-field">
                            <label>Document No.</label>
                            <input type="text" id="masterlistDocNo" name="masterlistDocNo" placeholder="CSPC-F-YYY-XX" value="{{ $masterlist->doc_no ?? '' }}" data-original-doc-no="{{ $masterlist->doc_no ?? '' }}" autocomplete="off">
                            <span id="docNoPrefixHint" style="display:none;margin-top:4px;font-size:12px;color:#475569;"></span>
                            <span id="docNoSuggestHint" class="reg-number-hint" style="display:none;"></span>
                            <span id="docNoHint" style="display:block;margin-top:4px;font-size:12px;"></span>
                        </div>
                        <div class="reg-field">
                            <label>Deadline of Submission</label>
                            <div class="reg-deadline-row">
                                <input type="date" id="deadlineOfSubmission" name="deadlineOfSubmission" value="{{ \App\Helpers\RegisterQueryHelper::formatDate($masterlist->deadline ?? '') }}">
                                <label class="reg-na-check" for="deadlineNotApplicable">
                                    <input type="checkbox" id="deadlineNotApplicable" name="deadlineNotApplicable" value="1">
                                    N/A
                                </label>
                            </div>
                        </div>
                        <div class="reg-field reg-ml-receipt">
                            <label>Document Receipt</label>
                            <div class="reg-dual">
                                <input type="date" id="masterlistReceiptDate" name="masterlistReceiptDate" value="{{ \App\Helpers\RegisterQueryHelper::formatDate($masterlist->doc_receipt_date ?? '') }}" oninput="calcMasterlistTimeSpent()">
                                <input type="time" id="masterlistReceiptTime" name="masterlistReceiptTime" value="{{ \App\Helpers\RegisterQueryHelper::formatTime($masterlist->doc_receipt_time ?? '') }}" oninput="calcMasterlistTimeSpent()">
                            </div>
                        </div>
                        <div class="reg-field reg-ml-timespent">
                            <label>Time Spent</label>
                            <input type="text" id="masterlistTimeSpentDisplay" readonly placeholder="--"
                                style="background: #f8fafc; cursor: default; font-weight: 700; text-align: center; font-size: 18px; height: 100%; min-height: 80px;">
                            <input type="hidden" id="masterlistTimeSpent" name="masterlistTimeSpent">
                        </div>
                        <div class="reg-field reg-ml-title-span">
                            <label>Document Title</label>
                            <input type="text" id="masterlistDocTitle" name="masterlistDocTitle" placeholder="Document title" value="{{ $masterlist->doc_title ?? '' }}">
                        </div>
                        <div class="reg-field reg-ml-registered">
                            <label>Document Registered</label>
                            <div class="reg-dual">
                                <input type="date" id="masterlistRegisteredDate" name="masterlistRegisteredDate" value="{{ \App\Helpers\RegisterQueryHelper::formatDate($masterlist->doc_registered_date ?? '') }}" oninput="calcMasterlistTimeSpent()">
                                <input type="time" id="masterlistRegisteredTime" name="masterlistRegisteredTime" value="{{ \App\Helpers\RegisterQueryHelper::formatTime($masterlist->doc_registered_time ?? '') }}" oninput="calcMasterlistTimeSpent()">
                            </div>
                        </div>
                    </div>
                    <div class="reg-ml-mid">
                        <div class="reg-field">
                            <label>Effectivity Date <span class="reg-req" aria-hidden="true">*</span></label>
                            <input type="date" id="masterlistEffectivityDate" name="masterlistEffectivityDate" value="{{ \App\Helpers\RegisterQueryHelper::formatDate($masterlist->effectivity_date ?? '') }}">
                        </div>
                        <div class="reg-field">
                            <label>Revision No.</label>
                            @php
                                $revEditable = ! empty($docRequest->is_draft) && empty($read_only);
                            @endphp
                            <input type="number" id="masterlistRevisionNo"
                                @if($revEditable) name="masterlistRevisionNo" @endif
                                placeholder="0"
                                value="{{ $masterlist->revise_no ?? '' }}"
                                @if(! $revEditable) disabled @endif
                                @if(! $revEditable) style="background:#f1f5f9; cursor:not-allowed; opacity:0.7;" @endif
                                min="0">
                            @unless($revEditable)
                                <input type="hidden" name="masterlistRevisionNo" value="{{ $masterlist->revise_no ?? '0' }}">
                            @endunless
                            <div id="revNoHint" class="reg-field-hint" style="font-size:0.78rem; margin-top:4px;"></div>
                        </div>
                        <div class="reg-field">
                            <label>No. of Pages</label>
                            <input type="number" id="masterlistNoOfPages" name="masterlistNoOfPages" min="0" placeholder="0" value="{{ $masterlist->no_pages ?? '' }}">
                        </div>
                        <div class="reg-field">
                            <label>Originator</label>
                            <div class="reg-reldocs" id="masterlistOriginatorWidget">
                                <div class="reg-reldocs-inputwrap">
                                    <input type="text" id="masterlistOriginatorSearch" class="reg-reldocs-input"
                                        placeholder="Type names, separated by a comma" autocomplete="off">
                                    <button type="button" class="reg-reldocs-arrow-btn" id="masterlistOriginatorArrowBtn">
                                        <i class="fa-solid fa-chevron-down"></i>
                                    </button>
                                </div>
                                <div id="masterlistOriginatorResults" class="reg-reldocs-dropdown" style="display:none;"></div>
                                <div id="masterlistOriginatorInlineChips" class="reg-reldocs-dropdown reg-reldocs-selected-panel" style="display:none;"></div>
                            </div>
                        </div>
                        <div class="reg-field">
                            <label>Source Unit</label>
                            <div class="reg-reldocs" id="masterlistSourceWidget">
                                <div class="reg-reldocs-inputwrap">
                                    <input type="text" id="masterlistSourceSearch" class="reg-reldocs-input"
                                        placeholder="Type office name" autocomplete="off">
                                    <button type="button" class="reg-reldocs-arrow-btn" id="masterlistSourceArrowBtn">
                                        <i class="fa-solid fa-chevron-down"></i>
                                    </button>
                                </div>
                                <div id="masterlistSourceSuggestions" class="reg-reldocs-dropdown" style="display:none;"></div>
                                <div id="masterlistSourceInlineChips" class="reg-reldocs-dropdown reg-reldocs-selected-panel" style="display:none;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="reg-grid-2">
                        <div class="reg-field">
                            <label>Keywords</label>
                            <div class="reg-keywords" id="keywordsWidget" data-keywords-widget>
                                <div class="reg-keywords-box" data-keywords-box>
                                    <div class="reg-keywords-chips" data-keywords-chips></div>
                                    <input type="text" class="reg-keywords-entry" data-keywords-entry
                                        placeholder="Type a keyword and press Enter..."
                                        autocomplete="off">
                                </div>
                                <input type="hidden" id="keywords" name="keywords" value="{{ $masterlist->keywords ?? '' }}">
                                <p class="reg-keywords-hint">Press Enter or comma to add. Click × to remove.</p>
                            </div>
                        </div>
                        <div class="reg-field">
                            <label>Related Documents</label>
                            <div class="reg-reldocs" id="relatedDocsWidget">
                                <div class="reg-reldocs-inputwrap">
                                    <input type="text" id="relatedDocsSearch" class="reg-reldocs-input"
                                        placeholder="Type a document title, then pick from latest documents..."
                                        autocomplete="off"
                                        oninput="handleRelatedDocSearch(this)"
                                        onfocus="handleRelatedDocFocus()">
                                    <button type="button" class="reg-reldocs-arrow-btn" onclick="toggleRelatedDocsSelected(event)">
                                        <i class="fa-solid fa-chevron-down" id="relatedDocsArrowIcon"></i>
                                    </button>
                                </div>
                                <div id="relatedDocsResults" class="reg-reldocs-dropdown" style="display:none;"></div>
                                <div id="relatedDocsSelectedPanel" class="reg-reldocs-dropdown reg-reldocs-selected-panel" style="display:none;">
                                    <div id="relatedDocsChips" class="reg-reldocs-chips"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="reg-field">
                        <label>Upload Scanned Copy</label>
                        @if($masterlist && $masterlist->scanned_masterlist)
                            @php
                                $mlScanLabel = trim((string) ($masterlist->scanned_masterlist_original_name ?? ''));
                                if ($mlScanLabel === '') {
                                    $mlScanLabel = basename((string) $masterlist->scanned_masterlist);
                                }
                            @endphp
                            <div class="reg-current-file">
                                <i class="fa-solid fa-file-pdf"></i>
                                <span>{{ $mlScanLabel }}</span>
                                <button type="button" class="reg-current-file-view"
                                    data-preview-url="{{ RegisterQueryHelper::scanUrl($masterlist->scanned_masterlist) }}"
                                    data-preview-path="{{ $masterlist->scanned_masterlist }}"
                                    data-preview-title="{{ $mlScanLabel }}">View</button>
                            </div>
                        @endif
                        <label class="reg-upload">
                            <input type="file" id="uploadScannedCopy" name="uploadScannedCopy" accept=".pdf">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <span>{{ $masterlist && $masterlist->scanned_masterlist ? 'Replace file' : 'Choose scanned PDF' }}</span>
                        </label>
                        <p class="reg-scan-name-preview" data-scan-preview="masterlist">
                            <strong>{{ $masterlist && $masterlist->scanned_masterlist ? 'Replacement / rename will be saved as' : 'Will be saved as' }}</strong>
                            <code data-scan-preview-name>—</code>
                        </p>
                    </div>
                </div>
            </section>

            <!-- ═══ SECTION 4 — RETRIEVAL (only when the version type is Revise) ═══ -->
            <section class="reg-card" id="section-4" style="display: {{ ($allowsRetrieval && ($retrieval || $retrievalOffices->isNotEmpty() || ($retrievedOfficesHidden ?? collect())->isNotEmpty())) ? 'block' : 'none' }};">
                <div class="reg-card-header">
                    <span>Document Retrieval</span>
                </div>
                <div class="reg-card-body">
                        <div class="reg-field">
                            <label>Office retrieval status</label>
                            <p class="reg-field-hint">These offices come from the previous document's distribution. Mark Retrieved when the office has returned the copy, then set the retrieval date and time. Monitoring shows which offices have retrieved and which have not.</p>
                        </div>
                        <div class="reg-office-table-wrap" id="retrievalOfficeWrap" data-office-body="retrievalBody">
                            <table class="reg-dist-table">
                                <thead>
                                    <tr>
                                        <th>Receiving Office(s)</th>
                                        <th class="reg-ret-status">Status</th>
                                        <th class="reg-ret-when">Retrieved date &amp; time</th>
                                        <th style="width:110px; text-align:center;">No. of Copies</th>
                                    </tr>
                                </thead>
                                <tbody id="retrievalBody">
                                    @forelse($retrievalOffices as $retOff)
                                    @php
                                        $retStatus = ($retOff->retrieval_status ?? 'pending') === 'retrieved' ? 'retrieved' : 'pending';
                                        $retDate = \App\Helpers\RegisterQueryHelper::formatDate($retOff->retrieval_date ?? '');
                                        $retTime = \App\Helpers\RegisterQueryHelper::formatTime($retOff->retrieval_time ?? '');
                                    @endphp
                                    <tr class="reg-office-added">
                                        <td>
                                            <input type="hidden" name="retrievalOffice[]" value="{{ $retOff->office_id }}">
                                            <div class="reg-office-name">
                                                <div class="reg-office-icon"><i class="fa-solid fa-building"></i></div>
                                                <span class="reg-office-text">{{ $retOff->office_name ?? 'Unknown' }}</span>
                                            </div>
                                        </td>
                                        <td class="reg-ret-status">
                                            <select name="retrievalStatus[]" class="reg-retrieval-status" onchange="handleRetrievalStatusChange(this)">
                                                <option value="pending" @selected($retStatus === 'pending')>Pending</option>
                                                <option value="retrieved" @selected($retStatus === 'retrieved')>Retrieved</option>
                                            </select>
                                        </td>
                                        <td class="reg-ret-when">
                                            <div class="reg-ret-when-fields" style="{{ $retStatus === 'retrieved' ? '' : 'display:none' }}">
                                                <input type="date" name="retrievalOfficeDate[]" value="{{ $retDate }}">
                                                <input type="time" name="retrievalOfficeTime[]" value="{{ $retTime }}">
                                            </div>
                                        </td>
                                        <td style="text-align:center;">
                                            <input type="number" name="retrievalCopies[]" value="{{ $retOff->copies }}" min="1" oninput="updateTotal('retrievalTotal', 'retrievalBody')">
                                        </td>
                                    </tr>
                                    @empty
                                    <tr class="reg-empty-row">
                                        <td colspan="4">
                                            <div class="reg-empty-state">
                                                <i class="fa-solid fa-building-circle-xmark"></i>
                                                <span>No offices added yet</span>
                                            </div>
                                        </td>
                                    </tr>
                                    @endforelse
                                </tbody>
                                {{-- Retrieved offices leave the visible list but stay posted so status is preserved. --}}
                                <tbody id="retrievalRetrievedHidden" style="display:none;" aria-hidden="true">
                                    @foreach(($retrievedOfficesHidden ?? collect()) as $retOff)
                                    <tr>
                                        <td>
                                            <input type="hidden" name="retrievalOffice[]" value="{{ $retOff->office_id }}">
                                            <input type="hidden" name="retrievalStatus[]" value="retrieved">
                                            <input type="hidden" name="retrievalCopies[]" value="{{ $retOff->copies ?? 1 }}">
                                            <input type="hidden" name="retrievalOfficeDate[]" value="{{ \App\Helpers\RegisterQueryHelper::formatDate($retOff->retrieval_date ?? '') }}">
                                            <input type="hidden" name="retrievalOfficeTime[]" value="{{ \App\Helpers\RegisterQueryHelper::formatTime($retOff->retrieval_time ?? '') }}">
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="3">Total No. of Copies</td>
                                        <td id="retrievalTotal" style="text-align:center; font-weight:700;">{{ $retrievalOffices->sum('copies') }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <button type="button" class="reg-office-see-more" id="retrievalSeeMore" data-office-wrap="retrievalOfficeWrap" onclick="toggleOfficeSeeMore(this)">
                            See more
                        </button>
                </div>
            </section>

            <!-- ═══ SECTION 5 — DISTRIBUTION ═══ -->
            <section class="reg-card" id="section-5" style="display: {{ $distributionOffices->isNotEmpty() || $distribution ? 'block' : 'none' }};">
                <div class="reg-card-header">
                    <span>Document Distribution</span>
                </div>
                <div class="reg-card-body reg-split">
                    <div class="reg-split-left">
                        <div class="reg-split-form-grid">
                            <div class="reg-split-form-stack">
                                <div class="reg-field">
                                    <label>Distribution Form Date</label>
                                    <div class="reg-dual">
                                        <input type="date" id="distributionFormDate" name="distributionFormDate" value="{{ \App\Helpers\RegisterQueryHelper::formatDate($distribution->doc_distribution_date_file ?? '') }}" oninput="calcDistributionTimeSpent(); if (window.DCSScanNamePreview) DCSScanNamePreview.update();">
                                        <input type="time" id="distributionFormTime" name="distributionFormTime" value="{{ \App\Helpers\RegisterQueryHelper::formatTime($distribution->doc_distribution_time_file ?? '') }}" oninput="calcDistributionTimeSpent()">
                                    </div>
                                </div>
                                <div class="reg-field">
                                    <label>Distribution Date & Time</label>
                                    <div class="reg-dual">
                                        <input type="date" id="distributionDate" name="distributionDate" value="{{ \App\Helpers\RegisterQueryHelper::formatDate($distribution->doc_distribution_date_actual ?? '') }}" oninput="calcDistributionTimeSpent()">
                                        <input type="time" id="distributionTime" name="distributionTime" value="{{ \App\Helpers\RegisterQueryHelper::formatTime($distribution->doc_distribution_time_actual ?? '') }}" oninput="calcDistributionTimeSpent()">
                                    </div>
                                </div>
                            </div>
                            <div class="reg-field">
                                <label>Time Spent/Minute(s)</label>
                                <input type="text" id="distributionTimeSpentDisplay" readonly placeholder="--" style="background: #f8fafc; cursor: default;">
                                <input type="hidden" id="distributionTimeSpent" name="distributionTimeSpent">
                            </div>
                        </div>
                        <div class="reg-field">
                            <label>Remarks</label>
                            <input type="text" id="distributionRemarks" name="distributionRemarks" placeholder="Optional remarks" value="{{ $distribution->remarks ?? '' }}">
                        </div>
                        <div class="reg-field">
                            <label>Upload Scanned D&amp;R</label>
                            @if($distribution && $distribution->scanned_distribution)
                                <div class="reg-current-file">
                                    <i class="fa-solid fa-file-pdf"></i>
                                    <span>{{ basename($distribution->scanned_distribution) }}</span>
                                    <a href="{{ RegisterQueryHelper::scanUrl($distribution->scanned_distribution) }}" target="_blank">View</a>
                                </div>
                            @endif
                            <label class="reg-upload">
                                <input type="file" id="scanneddist" name="scanneddist" accept=".pdf">
                                <i class="fa-solid fa-cloud-arrow-up"></i>
                                <span>{{ $distribution && $distribution->scanned_distribution ? 'Replace file' : 'Choose scanned PDF' }}</span>
                            </label>
                            <p class="reg-scan-name-preview" data-scan-preview="distribution">
                                <strong>{{ $distribution && $distribution->scanned_distribution ? 'Replacement will be saved as' : 'Will be saved as' }}</strong>
                                <code data-scan-preview-name>—</code>
                            </p>
                        </div>
                    </div>
                    <div class="reg-split-right">
                        <div class="reg-field">
                            <label>Select office(s) for distribution</label>
                            <div class="reg-cluster-chips" id="distClusterChips"></div>
                            <div class="reg-search">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="11" cy="11" r="8"/>
                                    <path d="M21 21l-4.35-4.35"/>
                                </svg>
                                <input type="text" id="distSearch" placeholder="Search and add office..."
                                    oninput="handleSearch(this, 'distResults', 'distBody', 'distTotal')"
                                    autocomplete="off">
                                <div id="distResults" class="reg-search-dropdown" style="display:none;"></div>
                            </div>
                            @include('pages.dcs.register.partials.dist-office-toolbar')
                        </div>
                        <div class="reg-office-table-wrap" id="distOfficeWrap" data-office-body="distBody">
                            <table class="reg-dist-table">
                                <thead>
                                    <tr>
                                        <th class="reg-dist-check-head" style="width:36px;">
                                            <input type="checkbox" id="distSelectAllHeader" title="Select all" onchange="toggleSelectAllDistOffices()">
                                        </th>
                                        <th>Receiving Office(s)</th>
                                        <th style="width:110px; text-align:center;">No. of Copies</th>
                                        <th style="width:40px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="distBody">
                                    @forelse($distributionOffices as $distOff)
                                    
                                    <tr class="reg-office-added" draggable="true">
                                        <td class="reg-dist-check-cell">
                                            <input type="checkbox" class="dist-office-check" onchange="onDistOfficeCheckChange()" title="Select to reorder">
                                        </td>
                                        <td>
                                            <input type="hidden" name="distOffice[]" value="{{ $distOff->office_id }}">
                                            <div class="reg-office-name">
                                                <span class="reg-dist-drag-handle" title="Drag to reorder"><i class="fa-solid fa-grip-vertical"></i></span>
                                                <div class="reg-office-icon"><i class="fa-solid fa-building"></i></div>
                                                <span class="reg-office-text">{{ $distOff->office_name ?? 'Unknown' }}</span>
                                            </div>
                                        </td>
                                        <td style="text-align:center;">
                                            <input type="number" name="distCopies[]" value="{{ $distOff->copies }}" min="1" oninput="updateTotal('distTotal', 'distBody')">
                                        </td>
                                        <td>
                                            <button type="button" class="btn-remove" onclick="removeOffice(this, 'distTotal', 'distBody')">
                                                <i class="fa-solid fa-xmark"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    @empty
                                    <tr class="reg-empty-row">
                                        <td colspan="4">
                                            <div class="reg-empty-state">
                                                <i class="fa-solid fa-building-circle-xmark"></i>
                                                <span>No offices added yet</span>
                                            </div>
                                        </td>
                                    </tr>
                                    @endforelse
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td class="reg-dist-check-foot"></td>
                                        <td>Total No. of Copies</td>
                                        <td id="distTotal" style="text-align:center; font-weight:700;">{{ $distributionOffices->sum('copies') }}</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <button type="button" class="reg-office-see-more" id="distSeeMore" data-office-wrap="distOfficeWrap" onclick="toggleOfficeSeeMore(this)">
                            See more
                        </button>
                    </div>
                </div>
            </section>

            <!-- ═══ FORM ACTIONS ═══ -->
            <div class="reg-actions" id="formActions" style="display: flex;">
                <div class="reg-actions-left">
                    <a href="{{ route($listRoute) }}" class="reg-btn reg-btn-cancel">
                        <i class="fa-solid fa-arrow-left"></i> {{ $isDraftDoc ? 'Back to Drafts' : ($readOnly ? 'Back to Update Documents' : 'Cancel') }}
                    </a>
                </div>
                <div class="reg-actions-right">
                    @unless($readOnly || !empty($isDraftDoc))
                    <p id="regNoChangesHint" class="reg-no-changes-hint" hidden>
                        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                        No changes detected — edit a field to enable Update Document.
                    </p>
                    @endunless
                    <button type="button" id="btnGenerateDistribution" class="reg-btn reg-btn-generate" onclick="generateDistributionTemplate()"
                        @if(!empty($isDraftDoc)) disabled title="Register the document first before generating the distribution template." @else title="Generate distribution template" @endif>
                        <i class="fa-solid fa-file-lines"></i> Generate
                    </button>
                    @unless($readOnly)
                    @if(!empty($isDraftDoc))
                    <button type="button" id="btnSaveDraft" class="reg-btn reg-btn-draft" onclick="confirmSaveDraft()">
                        <i class="fa-regular fa-floppy-disk"></i> Save Draft
                    </button>
                    <button type="button" id="btnSaveAndAppraise" class="reg-btn reg-btn-appraise" onclick="window.__openAppraisalAfterSave=true; confirmSave()" title="Save, then open Appraisal. Every document needs appraisal.">
                        <i class="fa-solid fa-clipboard-check"></i> Save and Appraise
                    </button>
                    @else
                    <button type="button" id="btnUpdateDocument" class="reg-btn reg-btn-save" onclick="window.__openAppraisalAfterSave=false; confirmSave()" title="Update this document. Appraisal is not changed.">
                        <i class="fa-solid fa-floppy-disk"></i> Update Document
                    </button>
                    @if($appraisalPending)
                    <button type="button" id="btnSaveAndAppraise" class="reg-btn reg-btn-appraise" onclick="window.__openAppraisalAfterSave=true; confirmSave()" title="Save changes, then open Appraisal. Every document needs appraisal.">
                        <i class="fa-solid fa-clipboard-check"></i> Update and Appraise
                    </button>
                    @endif
                    @endif
                    @endunless
                </div>
            </div>
        </form>

    <template x-teleport="body">
    <div class="reg-modal-overlay" id="confirmModal" :class="{ 'is-open': reviewOpen }" :aria-hidden="reviewOpen ? 'false' : 'true'" @click.self="closeReview()">
        <div class="reg-modal">
            <div class="reg-modal-header">
                <i class="fa-solid fa-clipboard-check"></i>
                <h3>Review & Confirm</h3>
                <button type="button" class="reg-modal-close" @click="closeReview()">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div id="regReviewSavingBanner" class="reg-review-saving-banner" hidden role="status" aria-live="polite">
                <i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>
                <span>Updating document… Please wait.</span>
            </div>
            <div class="reg-modal-body" id="reviewContent">
                <!-- Populated by JS -->
            </div>
            <div class="reg-modal-footer">
                <button type="button" class="reg-btn reg-btn-cancel" @click="closeReview()">
                    <i class="fa-solid fa-xmark"></i> Go Back
                </button>
                <button type="button" id="btnConfirmSaveModal" class="reg-btn reg-btn-save" onclick="submitForm()">
                    <i class="fa-solid fa-check"></i> Confirm Update
                </button>
            </div>
        </div>
    </div>
    </template>

    @include('pages.dcs.register.partials.draft-modals')

    <template x-teleport="body">
    <div class="reg-modal-overlay" id="filePreviewModal" aria-hidden="true" onclick="if(event.target===this)closeFilePreviewModal()">
        <div class="reg-modal reg-modal--preview">
            <div class="reg-modal-header">
                <i class="fa-solid fa-file-pdf"></i>
                <h3 id="filePreviewModalTitle">File preview</h3>
                <button type="button" class="reg-modal-close" onclick="closeFilePreviewModal()">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="reg-modal-body reg-modal-body--preview">
                <div class="reg-preview-loading" id="filePreviewModalLoading" hidden>
                    <div class="dcs-loading-spinner" aria-hidden="true"></div>
                    <p>Loading document…</p>
                </div>
                <iframe id="filePreviewModalFrame" title="Uploaded file preview"></iframe>
            </div>
        </div>
    </div>
    </template>

    <template x-teleport="body">
    <div class="reg-modal-overlay reg-modal-overlay--wide" id="distributionPrintModal" aria-hidden="true" onclick="if(event.target===this)closeDistributionPrintModal()">
        <div class="reg-modal reg-modal--wide">
            <div class="reg-modal-header">
                <i class="fa-solid fa-print"></i>
                <h3>Distribution and Retrieval</h3>
                <button type="button" class="reg-modal-close" onclick="closeDistributionPrintModal()">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="reg-modal-body reg-modal-body--preview">
                <iframe id="distributionPrintFrame" title="Distribution and Retrieval"></iframe>
            </div>
            <div class="reg-modal-footer">
                <button type="button" class="reg-btn reg-btn-cancel" onclick="closeDistributionPrintModal()">
                    <i class="fa-solid fa-xmark"></i> Close
                </button>
                <button type="button" class="reg-btn reg-btn-save" onclick="printDistributionPreview()">
                    <i class="fa-solid fa-print"></i> Print
                </button>
            </div>
        </div>
    </div>
    </template>

    <div class="reg-modal-overlay" id="docNoInsertModal" aria-hidden="true">
        <div class="reg-modal reg-modal--insert">
            <div class="reg-modal-header">
                <i class="fa-solid fa-hashtag"></i>
                <h3>Document number already exists</h3>
                <button type="button" class="reg-modal-close" id="docNoInsertClose" aria-label="Close">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="reg-modal-body">
                <p id="docNoInsertLead" class="reg-insert-lead"></p>
                <div class="reg-insert-choices" id="docNoInsertChoices">
                    <button type="button" class="reg-insert-choice" id="docNoInsertNext">
                        <strong>Use next free number</strong>
                        <span id="docNoInsertNextLabel">Use the next number in this group.</span>
                    </button>
                    <button type="button" class="reg-insert-choice" id="docNoInsertShift">
                        <strong>Free this number (renumber selected)</strong>
                        <span>Choose which registration(s) on this exact number to move. Everything else stays put.</span>
                    </button>
                    <button type="button" class="reg-insert-choice" id="docNoInsertKeep">
                        <strong>Register another copy with this number</strong>
                        <span>Keep this number as its own registration. It will not become a revision, and other numbers stay where they are.</span>
                    </button>
                </div>
                <label class="reg-insert-letters" id="docNoInsertLettersWrap" hidden>
                    <input type="checkbox" id="docNoInsertRenameLetters">
                    Also rename letter variants in this group (e.g. 11A → 12A)
                </label>
                <div id="docNoInsertPreview" class="reg-insert-preview" hidden>
                    <button type="button" class="reg-insert-back" id="docNoInsertShiftBack">Back to choices</button>
                    <div class="reg-insert-preview-head">
                        <h4 id="docNoInsertPreviewTitle">Free this number</h4>
                        <p id="docNoInsertPreviewHint">Select the registration(s) you want to renumber. Nothing is moved until you check a row and confirm.</p>
                    </div>
                    <div class="reg-insert-preview-table-wrap">
                        <table class="reg-insert-select-table">
                            <thead>
                                <tr>
                                    <th class="col-check"></th>
                                    <th>Document</th>
                                    <th>From</th>
                                    <th>To</th>
                                </tr>
                            </thead>
                            <tbody id="docNoInsertPreviewBody"></tbody>
                        </table>
                    </div>
                    <p id="docNoInsertDateWarning" class="reg-insert-error" hidden></p>
                    <p id="docNoInsertPreviewError" class="reg-insert-error" hidden></p>
                    <div class="reg-insert-preview-actions">
                        <button type="button" class="reg-btn reg-btn-save" id="docNoInsertConfirmShift">Confirm renumber</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>


<script>
/* Livewire can re-inject this script via Element.replaceWith; skip redeclare. */
if (window.__dcsEditRegisterScriptLoaded) {
    /* already booted */
} else {
window.__dcsEditRegisterScriptLoaded = true;

/** Safe Alpine root state — Livewire can race ahead of Alpine init. */
function getRegisterAlpineData(rootId) {
    const root = document.getElementById(rootId || 'dcsEditRoot');
    if (!root || !window.Alpine || typeof Alpine.$data !== 'function') return null;
    try {
        const data = Alpine.$data(root);
        return data != null && typeof data === 'object' ? data : null;
    } catch (_) {
        return null;
    }
}

/**
 * Sets the register alpine prop value.
 *
 * @param {*} key
 * @param {*} value
 * @param {*} rootId
 */
function setRegisterAlpineProp(key, value, rootId) {
    const data = getRegisterAlpineData(rootId || 'dcsEditRoot');
    if (!data) return false;
    try {
        data[key] = value;
        return true;
    } catch (_) {
        return false;
    }
}

let allOffices = [];
let allDocTypes = [];
window.allDocTypes = allDocTypes;
let allOriginators = [];
let allFaculties = [];
let syllabiGroupCounter = 0;
let syllabiCurrentStep = 1;
let syllabiRowUidCounter = 0;
let relatedDocsCache = [];
let relatedDocsSelected = window.__existingRelatedDocs || [];
let relatedDocsSelectedPanelOpen = false;
let docNoDuplicate = false;
let revisionRowUidCounter = 0;
let revSearchCache = {};
let revSearchTimers = {};
let syllabiTitleManuallyEdited = false;
window.__isSyllabiMode = false;
window.__syllabiModeLabel = 'Syllabi';
window.__syllabiFaculty = window.__syllabiFaculty || {};

/**
 * Checks whether syllabi like sub type condition is met.
 *
 * @param {*} subTypeId
 */
function isSyllabiLikeSubType(subTypeId) {
    const t = (allDocTypes || []).find(d => String(d.doc_type_id) === String(subTypeId));
    return !!(t && t.is_syllabi_like);
}

/**
 * Types allows revision.
 *
 * @param {*} docTypeId
 * @param {*} subTypeId
 */
function typeAllowsRevision(docTypeId, subTypeId) {
    const sid = subTypeId ? String(subTypeId) : '';
    const tid = docTypeId ? String(docTypeId) : '';
    const row = (allDocTypes || []).find(d => String(d.doc_type_id) === (sid || tid));
    if (!row) return true;
    return !row.is_syllabi_like;
}

const ALLOWED_EXTENSIONS = ['pdf'];
const MAX_FILE_SIZE = 200 * 1024 * 1024;
const OCR_MAX_FILE_SIZE = 10 * 1024 * 1024;

const CFG = window.APP_CONFIG || {};
const CURRENT_VERSION_ID = CFG.CURRENT_VERSION_ID || null;
const CURRENT_DOC_TYPE_ID = CFG.CURRENT_DOC_TYPE_ID || null;
const CURRENT_SUB_TYPE_ID = CFG.CURRENT_SUB_TYPE_ID || null;
const CURRENT_APPROVAL_BODY = CFG.CURRENT_APPROVAL_BODY || '';
const IS_EDIT_MODE = true;
window.__requireDraftForDocNoAutofill = true;
window.__isDraftDoc = !!CFG.IS_DRAFT;

// ══════════════════════════════════════════════
// SHARED HELPERS
// ══════════════════════════════════════════════
function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

/**
 * Formats, parses, or sanitizes form fields.
 *
 * @param {*} form
 */
function sanitizeFormFields(form) {
    if (!form) return;
    form.querySelectorAll('input, textarea').forEach(function (el) {
        const type = String(el.type || '').toLowerCase();
        if (el.disabled || typeof el.value !== 'string') return;
        if (['password', 'hidden', 'file', 'checkbox', 'radio', 'submit', 'button'].includes(type)) return;
        const stripped = String(el.value).replace(/[<>]/g, '');
        const next = el.tagName === 'TEXTAREA'
            ? stripped.replace(/\r\n|\r/g, '\n').replace(/[ \t]+/g, ' ').replace(/\n{3,}/g, '\n\n').trim()
            : stripped.replace(/\s+/g, ' ').trim();
        if (next !== el.value) el.value = next;
    });
}

/**
 * Validates and verifies file.
 *
 * @param {*} file
 */
function checkFile(file) {
    const ext = file.name.split('.').pop().toLowerCase();
    if (!ALLOWED_EXTENSIONS.includes(ext)) return { valid: false, ext, reason: 'type' };
    if (file.size > MAX_FILE_SIZE) return { valid: false, ext, reason: 'size', sizeMB: (file.size / (1024 * 1024)).toFixed(1) };
    return { valid: true, ext };
}

/**
 * Files type error message.
 *
 * @param {*} check
 * @param {*} file
 */
function fileTypeErrorMessage(check, file) {
    return check.reason === 'type'
        ? '"' + check.ext + '" is not allowed. Only scanned PDF files are accepted.'
        : '"' + file.name + '" is ' + check.sizeMB + 'MB. Maximum file size is 200MB.';
}

/**
 * Sets the file icon value.
 *
 * @param {*} icon
 * @param {*} ext
 */
function setFileIcon(icon, ext) {
    icon.className = 'fa-solid fa-file-pdf';
    icon.style.color = 'var(--reg-error)';
}

/**
 * Clears and resets file icon.
 *
 * @param {*} icon
 * @param {*} label
 * @param {*} originalText
 */
function clearFileIcon(icon, label, originalText) {
    icon.className = 'fa-solid fa-cloud-arrow-up';
    icon.style.color = '';
    label.textContent = originalText;
    label.style.color = '';
    label.style.fontWeight = '';
}

/**
 * Calculates and returns duration.
 *
 * @param {*} startDate
 * @param {*} startTime
 * @param {*} endDate
 * @param {*} endTime
 * @param {*} excludeNonWorkingDays
 */
function computeDuration(startDate, startTime, endDate, endTime, excludeNonWorkingDays) {
    if (!startDate || !startTime || !endDate || !endTime) return null;
    const start = new Date(startDate + "T" + startTime);
    const end = new Date(endDate + "T" + endTime);
    const diffMs = end - start;
    if (diffMs < 0) return { invalid: true };
    if (excludeNonWorkingDays) {
        return { invalid: false, totalMinutes: workingMinutesBetween(start, end) };
    }
    return { invalid: false, totalMinutes: Math.floor(diffMs / 60000) };
}

/**
 * Checks whether date local condition is met.
 *
 * @param {*} d
 */
function isoDateLocal(d) {
    return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
}

/**
 * Checks whether excluded non working day condition is met.
 *
 * @param {*} day
 */
function isExcludedNonWorkingDay(day) {
    const dow = day.getDay();
    if (dow === 0 || dow === 6) return true;
    return !!(window.__timeSpentNonWorkingDateSet && window.__timeSpentNonWorkingDateSet[isoDateLocal(day)]);
}

/**
 * Workings minutes between.
 *
 * @param {*} start
 * @param {*} end
 */
function workingMinutesBetween(start, end) {
    let total = 0;
    const startDay = new Date(start.getFullYear(), start.getMonth(), start.getDate());
    const endDay = new Date(end.getFullYear(), end.getMonth(), end.getDate());
    const cur = new Date(startDay);
    while (cur <= endDay) {
        const isStartDay = cur.getTime() === startDay.getTime();
        const isEndDay = cur.getTime() === endDay.getTime();
        // Always count the start/end days (staff timestamped work then), even on
        // weekends/holidays. Only skip fully intervening non-working days.
        if (isStartDay || isEndDay || !isExcludedNonWorkingDay(cur)) {
            const dayStart = new Date(cur.getFullYear(), cur.getMonth(), cur.getDate());
            const nextMidnight = new Date(cur.getFullYear(), cur.getMonth(), cur.getDate() + 1);
            const from = isStartDay ? start : dayStart;
            const to = isEndDay ? end : nextMidnight;
            total += Math.max(0, Math.floor((to - from) / 60000));
        }
        cur.setDate(cur.getDate() + 1);
    }
    return total;
}

/**
 * Formats, parses, or sanitizes duration.
 *
 * @param {*} totalMinutes
 */
function formatDuration(totalMinutes) {
    const days = Math.floor(totalMinutes / 1440);
    const hours = Math.floor((totalMinutes % 1440) / 60);
    const minutes = totalMinutes % 60;
    if (days > 0) return days + "d " + hours + "hr " + minutes + "min";
    if (hours > 0) return hours + "hr " + minutes + "min";
    return minutes + " min";
}

/**
 * Offices matches query.
 *
 * @param {*} o
 * @param {*} q
 */
function officeMatchesQuery(o, q) {
    const needle = (q || '').trim().toLowerCase();
    if (!needle) return false;
    const name = (o.office_name || '').toLowerCase();
    const code = (o.office_code || '').toLowerCase();
    return name.includes(needle) || code.includes(needle);
}

/**
 * Retrieves and returns office by label or code.
 *
 * @param {*} part
 * @param {*} list
 */
function findOfficeByLabelOrCode(part, list) {
    const n = String(part ?? '').trim().toLowerCase();
    if (!n) return null;
    const haystack = list || allOffices;
    return haystack.find(o =>
        (o.office_name || '').toLowerCase() === n ||
        (o.office_code || '').toLowerCase() === n ||
        String(o.office_id ?? o.id ?? '') === n
    ) || null;
}

/**
 * Filters offices.
 *
 * @param {*} query
 */
function filterOffices(query) {
    const q = query.trim().toLowerCase();
    if (q.length < 1) return [];
    return allOffices.filter(o => officeMatchesQuery(o, q));
}

/**
 * Empties office row html.
 *
 * @param {*} tbodyId
 */
function emptyOfficeRowHTML(tbodyId) {
    const isRetrieval = tbodyId === 'retrievalBody';
    const table = document.getElementById(tbodyId)?.closest('table');
    const receiptCol = table?.querySelector('.reg-dist-receipt-head') ? 1 : 0;
    const cols = (isRetrieval || tbodyId === 'distBody') ? (4 + receiptCol) : 3;
    return '<tr class="reg-empty-row"><td colspan="' + cols + '"><div class="reg-empty-state"><i class="fa-solid fa-building-circle-xmark"></i><span>No offices added yet</span></div></td></tr>';
}

/**
 * Pad2s action.
 *
 * @param {*} n
 */
function pad2(n) {
    return String(n).padStart(2, '0');
}

/**
 * Locals date value.
 */
function localDateValue() {
    const d = new Date();
    return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
}

/**
 * Locals time value.
 */
function localTimeValue() {
    const d = new Date();
    return pad2(d.getHours()) + ':' + pad2(d.getMinutes());
}

/**
 * Retrievals when cell html.
 *
 * @param {*} dateVal
 * @param {*} timeVal
 * @param {*} visible
 */
function retrievalWhenCellHTML(dateVal, timeVal, visible) {
    const display = visible ? '' : ' style="display:none"';
    return '<td class="reg-ret-when"><div class="reg-ret-when-fields"' + display + '>' +
        '<input type="date" name="retrievalOfficeDate[]" value="' + String(dateVal || '').replace(/"/g, '') + '">' +
        '<input type="time" name="retrievalOfficeTime[]" value="' + String(timeVal || '').replace(/"/g, '') + '">' +
        '</div></td>';
}

/**
 * Renders and synchronizes retrieval when fields in the DOM.
 *
 * @param {*} tr
 * @param {*} isRetrieved
 */
function syncRetrievalWhenFields(tr, isRetrieved) {
    const wrap = tr?.querySelector('.reg-ret-when-fields');
    if (!wrap) return;
    wrap.style.display = isRetrieved ? '' : 'none';
    if (!isRetrieved) return;
    const dateInp = wrap.querySelector('input[name="retrievalOfficeDate[]"]');
    const timeInp = wrap.querySelector('input[name="retrievalOfficeTime[]"]');
    if (dateInp && !dateInp.value) dateInp.value = localDateValue();
    if (timeInp && !timeInp.value) timeInp.value = localTimeValue();
}

/**
 * Retrievals status select html.
 *
 * @param {*} status
 */
function retrievalStatusSelectHTML(status) {
    const value = status === 'retrieved' ? 'retrieved' : 'pending';
    return '<select name="retrievalStatus[]" class="reg-retrieval-status" onchange="handleRetrievalStatusChange(this)">' +
        '<option value="pending"' + (value === 'pending' ? ' selected' : '') + '>Pending</option>' +
        '<option value="retrieved"' + (value === 'retrieved' ? ' selected' : '') + '>Retrieved</option>' +
    '</select>';
}

/**
 * Dists body has office.
 *
 * @param {*} officeId
 */
function distBodyHasOffice(officeId) {
    const tbody = document.getElementById('distBody');
    if (!tbody) return false;
    return [...tbody.querySelectorAll('input[type="hidden"][name="distOffice[]"]')]
        .some(inp => String(inp.value) === String(officeId));
}

/**
 * Adds retrieved office to distribution.
 *
 * @param {*} officeId
 * @param {*} officeName
 * @param {*} copies
 */
function addRetrievedOfficeToDistribution(officeId, officeName, copies) {
    if (distBodyHasOffice(officeId)) return;
    seedOfficeRow('distBody', 'distTotal', officeId, officeName, copies);
    const tbody = document.getElementById('distBody');
    const inp = [...tbody.querySelectorAll('input[type="hidden"][name="distOffice[]"]')]
        .find(i => String(i.value) === String(officeId));
    if (inp?.closest('tr')) inp.closest('tr').dataset.fromRetrieval = '1';
    updateTotal('distTotal', 'distBody');
    if (typeof syncDistClusterChipState === 'function') syncDistClusterChipState();
}

/**
 * Removes specified retrieved office from distribution.
 *
 * @param {*} officeId
 */
function removeRetrievedOfficeFromDistribution(officeId) {
    const tbody = document.getElementById('distBody');
    if (!tbody) return;
    for (const inp of tbody.querySelectorAll('input[type="hidden"][name="distOffice[]"]')) {
        const tr = inp.closest('tr');
        if (String(inp.value) === String(officeId) && tr?.dataset.fromRetrieval === '1') {
            tr.remove();
            break;
        }
    }
    updateTotal('distTotal', 'distBody');
    if (tbody.querySelectorAll('tr.reg-office-added').length === 0) {
        tbody.innerHTML = emptyOfficeRowHTML('distBody');
    }
    if (typeof syncDistClusterChipState === 'function') syncDistClusterChipState();
}

/**
 * Checks whether office in retrieved hidden condition is met.
 *
 * @param {*} officeId
 */
function isOfficeInRetrievedHidden(officeId) {
    const hidden = document.getElementById('retrievalRetrievedHidden');
    if (!hidden) return false;
    return [...hidden.querySelectorAll('input[name="retrievalOffice[]"]')]
        .some(inp => String(inp.value) === String(officeId));
}

/**
 * Removes specified office from retrieved hidden.
 *
 * @param {*} officeId
 */
function removeOfficeFromRetrievedHidden(officeId) {
    const hidden = document.getElementById('retrievalRetrievedHidden');
    if (!hidden) return 1;
    let copies = 1;
    for (const inp of hidden.querySelectorAll('input[name="retrievalOffice[]"]')) {
        if (String(inp.value) !== String(officeId)) continue;
        const row = inp.closest('tr');
        const copiesInp = row?.querySelector('input[name="retrievalCopies[]"]');
        copies = parseInt(copiesInp?.value, 10) || 1;
        row?.remove();
        break;
    }
    return copies;
}

/** Undo Mark-as-Retrieved: drop hidden retrieved row and show office as Pending again. */
function restoreOfficeToRetrievalPending(officeId, officeName, copies) {
    if (!officeId) return;
    const hiddenCopies = removeOfficeFromRetrievedHidden(officeId);
    const useCopies = parseInt(copies, 10) || hiddenCopies || 1;
    const tbody = document.getElementById('retrievalBody');
    if (!tbody) return;

    const already = [...tbody.querySelectorAll('input[type="hidden"][name="retrievalOffice[]"]')]
        .some(inp => String(inp.value) === String(officeId));
    if (!already) {
        const emptyRow = tbody.querySelector('.reg-empty-row');
        if (emptyRow) emptyRow.remove();
        const tr = document.createElement('tr');
        tr.className = 'reg-office-added';
        tr.innerHTML =
            '<td><input type="hidden" name="retrievalOffice[]" value="' + String(officeId).replace(/"/g, '') + '">' +
            '<div class="reg-office-name"><div class="reg-office-icon"><i class="fa-solid fa-building"></i></div>' +
            '<span class="reg-office-text">' + escapeHtml(officeName || 'Office') + '</span></div></td>' +
            '<td class="reg-ret-status">' + retrievalStatusSelectHTML('pending') + '</td>' +
            retrievalWhenCellHTML('', '', false) +
            '<td style="text-align:center;"><input type="number" name="retrievalCopies[]" value="' + useCopies +
            '" min="1" oninput="updateTotal(\'retrievalTotal\', \'retrievalBody\')"></td>';
        tbody.appendChild(tr);
    }
    updateTotal('retrievalTotal', 'retrievalBody');
    const retSection = document.getElementById('section-4');
    if (retSection && (typeof allowsDocumentRetrieval !== 'function' || allowsDocumentRetrieval())) {
        retSection.style.display = 'block';
    }
}

/**
 * Maybes restore dist office to retrieval.
 *
 * @param {*} tr
 */
function maybeRestoreDistOfficeToRetrieval(tr) {
    if (!tr) return;
    const officeId = tr.querySelector('input[type="hidden"][name="distOffice[]"]')?.value;
    if (!officeId) return;
    const fromRetrieval = tr.dataset.fromRetrieval === '1' || isOfficeInRetrievedHidden(officeId);
    if (!fromRetrieval) return;
    const officeName = tr.querySelector('.reg-office-text')?.textContent?.trim() || 'Office';
    const copies = tr.querySelector('input[type="number"][name="distCopies[]"]')?.value || 1;

    // Prefer flipping the visible Retrieval row back to Pending (office stays listed).
    const retTbody = document.getElementById('retrievalBody');
    const existingInp = [...(retTbody?.querySelectorAll('input[type="hidden"][name="retrievalOffice[]"]') || [])]
        .find(inp => String(inp.value) === String(officeId));
    if (existingInp) {
        const row = existingInp.closest('tr');
        const select = row?.querySelector('.reg-retrieval-status');
        if (select) select.value = 'pending';
        removeOfficeFromRetrievedHidden(officeId);
        return;
    }
    restoreOfficeToRetrievalPending(officeId, officeName, copies);
}

/**
 * Handles retrieval status change event or response.
 *
 * @param {*} select
 */
window.handleRetrievalStatusChange = function (select) {
    const tr = select?.closest('tr');
    if (!tr) return;
    const officeId = tr.querySelector('input[type="hidden"][name="retrievalOffice[]"]')?.value;
    const officeName = tr.querySelector('.reg-office-text')?.textContent?.trim() || 'Office';
    const copies = tr.querySelector('input[type="number"][name="retrievalCopies[]"]')?.value || 1;
    if (select.value === 'retrieved') {
        addRetrievedOfficeToDistribution(officeId, officeName, copies);
        syncRetrievalWhenFields(tr, true);
        // Keep the office visible in Retrieval with status Retrieved.
        const distSection = document.getElementById('section-5');
        if (distSection) distSection.style.display = 'block';
    } else {
        removeRetrievedOfficeFromDistribution(officeId);
        syncRetrievalWhenFields(tr, false);
    }
};

/**
 * Moves retrieval row to hidden.
 *
 * @param {*} tr
 * @param {*} officeId
 * @param {*} copies
 */
function moveRetrievalRowToHidden(tr, officeId, copies) {
    const hiddenBody = document.getElementById('retrievalRetrievedHidden')
        || ensureRetrievalHiddenBody();
    if (!hiddenBody) {
        tr.remove();
        return;
    }
    // Avoid duplicate hidden rows for the same office.
    const existing = [...hiddenBody.querySelectorAll('input[name="retrievalOffice[]"]')]
        .find(inp => String(inp.value) === String(officeId));
    if (!existing) {
        const dateVal = tr.querySelector('input[name="retrievalOfficeDate[]"]')?.value || '';
        const timeVal = tr.querySelector('input[name="retrievalOfficeTime[]"]')?.value || '';
        const row = document.createElement('tr');
        row.innerHTML = '<td>' +
            '<input type="hidden" name="retrievalOffice[]" value="' + String(officeId).replace(/"/g, '') + '">' +
            '<input type="hidden" name="retrievalStatus[]" value="retrieved">' +
            '<input type="hidden" name="retrievalCopies[]" value="' + String(copies).replace(/"/g, '') + '">' +
            '<input type="hidden" name="retrievalOfficeDate[]" value="' + String(dateVal).replace(/"/g, '') + '">' +
            '<input type="hidden" name="retrievalOfficeTime[]" value="' + String(timeVal).replace(/"/g, '') + '">' +
            '</td>';
        hiddenBody.appendChild(row);
    }
    const tbody = tr.closest('tbody');
    tr.remove();
    if (tbody && tbody.querySelectorAll('tr.reg-office-added').length === 0) {
        tbody.innerHTML = emptyOfficeRowHTML('retrievalBody');
    }
    updateTotal('retrievalTotal', 'retrievalBody');
}

/**
 * Ensures retrieval hidden body.
 */
function ensureRetrievalHiddenBody() {
    let hidden = document.getElementById('retrievalRetrievedHidden');
    if (hidden) return hidden;
    const table = document.querySelector('#retrievalBody')?.closest('table');
    if (!table) return null;
    hidden = document.createElement('tbody');
    hidden.id = 'retrievalRetrievedHidden';
    hidden.style.display = 'none';
    hidden.setAttribute('aria-hidden', 'true');
    table.appendChild(hidden);
    return hidden;
}

/**
 * Seed retrieval office row.
 *
 * @param {*} tbodyId
 * @param {*} totalId
 * @param {*} officeId
 * @param {*} officeName
 * @param {*} copies
 * @param {*} status
 * @param {*} options
 */
function seedRetrievalOfficeRow(tbodyId, totalId, officeId, officeName, copies, status, options = {}) {
    const tbody = document.getElementById(tbodyId);
    if (!tbody) return;
    const emptyRow = tbody.querySelector('.reg-empty-row');
    if (emptyRow) emptyRow.remove();
    const existing = [...tbody.querySelectorAll('input[type="hidden"][name="retrievalOffice[]"]')]
        .find(inp => String(inp.value) === String(officeId));
    if (existing) return;
    const keepInRetrieval = !!options.keepInRetrieval;
    const tr = document.createElement('tr');
    tr.className = 'reg-office-added';
    if (keepInRetrieval) tr.dataset.fromPriorRetrieval = '1';
    tr.innerHTML = `
        <td><input type="hidden" name="retrievalOffice[]" value="${officeId}"><div class="reg-office-name"><div class="reg-office-icon"><i class="fa-solid fa-building"></i></div><span class="reg-office-text">${escapeHtml(officeName)}</span></div></td>
        <td class="reg-ret-status">${retrievalStatusSelectHTML(status || 'pending')}</td>
        ${retrievalWhenCellHTML(options.retrievalDate || '', options.retrievalTime || '', (status || 'pending') === 'retrieved')}
        <td style="text-align:center;"><input type="number" name="retrievalCopies[]" value="${copies}" min="1" oninput="updateTotal('${totalId}', '${tbodyId}')"></td>
    `;
    tbody.appendChild(tr);
    if (typeof refreshOfficeSeeMore === 'function') refreshOfficeSeeMore(tbodyId);
    if ((status || 'pending') === 'retrieved') {
        addRetrievedOfficeToDistribution(officeId, officeName, copies);
        // Retrieved offices stay visible in Retrieval.
    } else {
        removeRetrievedOfficeFromDistribution(officeId);
    }
}

/**
 * Filters items.
 *
 * @param {*} list
 * @param {*} labelKey
 * @param {*} query
 */
function filterItems(list, labelKey, query) {
    const q = query.trim().toLowerCase();
    if (q.length < 1) return [];
    return list.filter(o => {
        if ((o[labelKey] || '').toLowerCase().includes(q)) return true;
        if ((o.office_code || '').toLowerCase().includes(q)) return true;
        return false;
    });
}

/**
 * Seed office row.
 *
 * @param {*} tbodyId
 * @param {*} totalId
 * @param {*} officeId
 * @param {*} officeName
 * @param {*} copies
 */
function seedOfficeRow(tbodyId, totalId, officeId, officeName, copies) {
    if (tbodyId === 'distBody' && typeof isDistOfficesLockedFromDrf === 'function' && isDistOfficesLockedFromDrf()) {
        return;
    }
    if (tbodyId === 'retrievalBody') {
        seedRetrievalOfficeRow(tbodyId, totalId, officeId, officeName, copies, 'pending');
        return;
    }
    const tbody = document.getElementById(tbodyId);
    if (!tbody) return;
    const officeNameAttr = "distOffice[]";
    const copiesNameAttr = "distCopies[]";
    const emptyRow = tbody.querySelector(".reg-empty-row");
    if (emptyRow) emptyRow.remove();
    const existing = [...tbody.querySelectorAll('input[type="hidden"]')].find(inp => inp.value == officeId);
    if (existing) return;
    const tr = document.createElement("tr");
    tr.className = "reg-office-added";
    tr.draggable = true;
    if (typeof buildDistOfficeRowHTML === 'function') {
        tr.innerHTML = buildDistOfficeRowHTML(officeId, officeName, copies, totalId);
    } else {
        const receiptCell = document.querySelector('#distBody')?.closest('table')?.querySelector('.reg-dist-receipt-head')
            ? '<td class="reg-dist-receipt-cell"><div class="reg-dist-receipt-status is-pending">Pending</div></td>'
            : '';
        tr.innerHTML = `
        <td class="reg-dist-check-cell"><input type="checkbox" class="dist-office-check" onchange="onDistOfficeCheckChange()" title="Select to reorder"></td>
        <td><input type="hidden" name="${officeNameAttr}" value="${officeId}"><div class="reg-office-name"><span class="reg-dist-drag-handle" title="Drag to reorder"><i class="fa-solid fa-grip-vertical"></i></span><div class="reg-office-icon"><i class="fa-solid fa-building"></i></div><span class="reg-office-text">${escapeHtml(officeName)}</span></div></td>
        <td style="text-align:center;"><input type="number" name="${copiesNameAttr}" value="${copies}" min="1" oninput="updateTotal('${totalId}', '${tbodyId}')"></td>
        ${receiptCell}
        <td><button type="button" class="btn-remove" onclick="removeOffice(this, '${totalId}', '${tbodyId}')"><i class="fa-solid fa-xmark"></i></button></td>
    `;
    }
    tbody.appendChild(tr);
}

/**
 * Tables is empty.
 *
 * @param {*} tbodyId
 */
function tableIsEmpty(tbodyId) {
    const tbody = document.getElementById(tbodyId);
    if (!tbody) return true;
    return tbody.querySelectorAll('input[type="hidden"]').length === 0;
}

/**
 * Positions fixed dropdown.
 *
 * @param {*} dropdownEl
 * @param {*} inputEl
 */
function positionFixedDropdown(dropdownEl, inputEl) {
    const rect = inputEl.getBoundingClientRect();
    dropdownEl.style.position = 'fixed';
    dropdownEl.style.top = (rect.bottom + 4) + 'px';
    dropdownEl.style.left = rect.left + 'px';
    dropdownEl.style.width = Math.max(rect.width, 160) + 'px';
    dropdownEl.style.maxHeight = '220px';
    dropdownEl.style.overflowY = 'auto';
    dropdownEl.style.zIndex = 9999;
}

/**
 * Injects hidden for disabled.
 *
 * @param {*} selectId
 * @param {*} hiddenName
 */
function injectHiddenForDisabled(selectId, hiddenName) {
    const sel = document.getElementById(selectId);
    if (!sel || !sel.disabled) return;
    const existingByName = sel.parentElement.querySelector('input[type="hidden"][name="' + hiddenName + '"]');
    if (existingByName) { existingByName.value = sel.value; return; }
    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = hiddenName;
    hidden.value = sel.value;
    hidden.dataset.for = selectId;
    sel.parentElement.appendChild(hidden);
}

/**
 * Clears and resets text like inputs.
 *
 * @param {*} el
 */
function resetTextLikeInputs(el) {
    el.querySelectorAll('input[type="text"], input[type="number"], input[type="date"], input[type="time"], textarea').forEach(input => { input.value = ''; });
    el.querySelectorAll('select').forEach(sel => { sel.selectedIndex = 0; });
}

/**
 * Clears and resets file widgets in.
 *
 * @param {*} el
 */
function resetFileWidgetsIn(el) {
    el.querySelectorAll('input[type="file"]').forEach(file => {
        file.value = '';
        const container = file.closest('.reg-upload');
        if (container) {
            const icon = container.querySelector('i');
            const label = container.querySelector('span');
            if (icon && label) resetUploadArea(container, icon, label, 'Choose scanned PDF');
        }
    });
    el.querySelectorAll('.reg-upload-cell').forEach(cell => {
        const icon = cell.querySelector('i');
        const label = cell.querySelector('span');
        if (icon && label) resetUploadCell(cell, icon, label, 'No file chosen');
    });
}

/**
 * Clears and resets upload cell.
 *
 * @param {*} cell
 * @param {*} icon
 * @param {*} label
 * @param {*} originalText
 */
function resetUploadCell(cell, icon, label, originalText) {
    cell.classList.remove('reg-upload-cell-success', 'reg-upload-cell-error');
    cell.style.borderColor = '';
    cell.style.background = '';
    clearFileIcon(icon, label, originalText);
    removeExistingError(cell);
}

/**
 * Retrieves and returns select text with code.
 *
 * @param {*} id
 */
function getSelectTextWithCode(id) {
    const el = document.getElementById(id);
    if (!el || el.selectedIndex < 0) return "";
    const opt = el.options[el.selectedIndex];
    if (!opt || opt.disabled || opt.value === "") return "";
    const text = opt.text || "";
    const code = opt.dataset ? opt.dataset.code : "";
    return code ? `${text} (${code})` : text;
}

// ══════════════════════════════════════════════
// INIT
// ══════════════════════════════════════════════
document.addEventListener("DOMContentLoaded", async function () {

    document.querySelectorAll("select").forEach(sel => {
        sel.setAttribute("autocomplete", "off");
        sel.setAttribute("autofill", "off");
    });
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.reg-current-file-view');
        if (!btn) return;
        e.preventDefault();
        const title = btn.dataset.previewTitle || 'File preview';
        const path = btn.dataset.previewPath || '';
        // Always mint a fresh same-host signed URL via the auth API so the
        // iframe does not reuse a stale/cross-host baked link.
        let url = '';
        if (path && typeof dcsScanHref === 'function') {
            url = dcsScanHref(path);
        }
        if (!url) url = btn.dataset.previewUrl || '';
        if (url) openFilePreviewModal(url, title);
    });
    document.querySelectorAll('#retrievalBody tr.reg-office-added').forEach(tr => {
        if (!allowsDocumentRetrieval()) return;
        const status = tr.querySelector('.reg-retrieval-status')?.value;
        if (status !== 'retrieved') return;
        // Keep retrieved offices visible in Retrieval; also ensure they appear in Distribution.
        const officeId = tr.querySelector('input[type="hidden"][name="retrievalOffice[]"]')?.value;
        const officeName = tr.querySelector('.reg-office-text')?.textContent?.trim() || 'Office';
        const copies = tr.querySelector('input[type="number"][name="retrievalCopies[]"]')?.value || 1;
        if (officeId && typeof addRetrievedOfficeToDistribution === 'function') {
            addRetrievedOfficeToDistribution(officeId, officeName, copies);
        }
    });
    document.querySelectorAll('#retrievalRetrievedHidden input[name="retrievalOffice[]"]').forEach(inp => {
        if (!allowsDocumentRetrieval()) return;
        const distInp = [...document.querySelectorAll('#distBody input[name="distOffice[]"]')]
            .find(i => String(i.value) === String(inp.value));
        if (distInp?.closest('tr')) {
            distInp.closest('tr').dataset.fromRetrieval = '1';
        }
    });
    if (!allowsDocumentRetrieval()) {
        const retSection = document.getElementById('section-4');
        if (retSection) {
            retSection.style.display = 'none';
            retSection.querySelectorAll('input, select, textarea').forEach(el => {
                el.disabled = true;
            });
        }
    }
    const form = document.getElementById("masterForm");
    if (form) form.setAttribute("autocomplete", "off");

    // Generate is only for registered documents — never enable on drafts.
    syncGenerateDistributionButtonState();

    if (window.__editReadOnly) {
        const keepEnabled = new Set();
        if (!window.__isDraftDoc) keepEnabled.add('btnGenerateDistribution');
        form?.querySelectorAll('input, select, textarea, button').forEach((el) => {
            if (!el || el.type === 'hidden') return;
            if (keepEnabled.has(el.id)) return;
            if (el.classList.contains('reg-office-see-more') || el.id === 'distSeeMore' || el.id === 'retrievalSeeMore') return;
            el.disabled = true;
            el.setAttribute('aria-disabled', 'true');
        });
        // Source-unit / office widgets and checklist toggles
        form?.querySelectorAll('.reg-su-remove, .reg-chip-remove, .reg-office-remove, .reg-add-office, .reg-check, [data-office-picker], .su-dropdown, .originator-dropdown').forEach((el) => {
            el.style.pointerEvents = 'none';
            el.setAttribute('aria-disabled', 'true');
            if (el.tagName === 'BUTTON' || el.tagName === 'INPUT') el.disabled = true;
        });
        form?.addEventListener('submit', (e) => e.preventDefault());
        window.__regDraftShouldAutosaveOnLeave = () => false;
        window.__regDraftCanSave = () => false;
    }

    try {
        const catalog = window.__registerCatalog || {};
        const offices = catalog.offices || [];
        const docTypes = catalog.docTypes || [];
        const versionTypes = catalog.versionTypes || [];
        const approvalBodies = catalog.approvalBodies || [];
        const originators = catalog.originators || [];

        allOffices = Array.isArray(offices) ? offices : [];
        allDocTypes = Array.isArray(docTypes) ? docTypes : [];
        window.allDocTypes = allDocTypes;
        allOriginators = Array.isArray(originators) ? originators : [];
        allFaculties = [];
        window.DCSScanNamePreview?.update();

        const versionSelect = document.getElementById("versionType");
        while (versionSelect.options.length > 1) versionSelect.remove(1);
        (versionTypes || []).forEach(v => {
            const opt = new Option(v.version_name, v.version_id);
            if (v.version_id == CURRENT_VERSION_ID) opt.selected = true;
            versionSelect.add(opt);
        });
        versionSelect.dataset.lastValid = CURRENT_VERSION_ID;
        versionSelect.disabled = true;

        const docTypeSelect = document.getElementById("docType");
        while (docTypeSelect.options.length > 1) docTypeSelect.remove(1);
        allDocTypes.filter(d => !d.parent_id).forEach(d => {
            const opt = new Option(d.doc_type_name, d.doc_type_id);
            if (d.doc_type_id == CURRENT_DOC_TYPE_ID) opt.selected = true;
            docTypeSelect.add(opt);
        });
        docTypeSelect.dataset.lastValid = CURRENT_DOC_TYPE_ID;
        docTypeSelect.disabled = true;

        const subTypeSelect = document.getElementById("subType");
        const subTypeField = document.getElementById("subTypeField");
        const children = allDocTypes.filter(d => d.parent_id == CURRENT_DOC_TYPE_ID);
        const draftSubTypeUnlock = !!subTypeSelect?.dataset?.draftUnlock
            && !CURRENT_SUB_TYPE_ID
            && !!(window.__isDraftDoc);
        if (subTypeField) {
            const showSubType = children.length > 0;
            subTypeField.hidden = !showSubType;
            subTypeField.style.display = showSubType ? "" : "none";
        }
        if (children.length > 0) {
            children.forEach(c => {
                const opt = new Option(c.doc_type_name, c.doc_type_id);
                if (c.doc_type_id == CURRENT_SUB_TYPE_ID) opt.selected = true;
                subTypeSelect.add(opt);
            });
            subTypeSelect.dataset.lastValid = CURRENT_SUB_TYPE_ID || "";
            if (draftSubTypeUnlock) {
                subTypeSelect.disabled = false;
                subTypeSelect.removeAttribute('disabled');
            } else {
                subTypeSelect.disabled = true;
            }
        } else {
            subTypeSelect.dataset.lastValid = "";
            subTypeSelect.disabled = true;
        }

        injectHiddenForDisabled('versionType', 'version_id');
        injectHiddenForDisabled('docType', 'doc_type_id');
        injectHiddenForDisabled('subType', 'sub_type_id');

        const approvalSelect = document.getElementById("approvalBody");
        if (approvalSelect) {
            while (approvalSelect.options.length > 1) approvalSelect.remove(1);
            (approvalBodies || []).forEach(a => {
                const opt = new Option(a.approval_name, a.approval_body_id);
                if (a.approval_body_id == CURRENT_APPROVAL_BODY) opt.selected = true;
                approvalSelect.add(opt);
            });
        }

        loadChecklists(CURRENT_VERSION_ID);

        // ── Wire doc no lookup ──
        const revField = document.getElementById('masterlistRevisionNo');
        initDocNoLookup(revField);
        initDraftRevNoLookup(revField);
        wireSyllabiMasterlistSync();
        wireApprovalDeadlineSync();
        wireDeadlineNotApplicable();
        enableApproval();
        const applicableOnLoad = document.querySelector('input[name="approval_status"][value="applicable"]');
        if (typeof window.handleApprovalToggle === 'function') {
            window.handleApprovalToggle(!!applicableOnLoad?.checked);
        }

        // ── Auto-copy DRF title to Masterlist title ──
        const drfTitle = document.getElementById('drfTitle');
        const mlTitle = document.getElementById('masterlistDocTitle');
        if (drfTitle && mlTitle) {
            drfTitle.addEventListener('input', () => { mlTitle.value = drfTitle.value; });
            mlTitle.addEventListener('input', () => { drfTitle.value = mlTitle.value; });
        }

        renderDistClusterChips();
        bindDistBodyDrag();

        // ── Wire initial DCN revision row search ──
        document.querySelectorAll('#revisionTableBody tr').forEach(tr => bindRevisionRowSearch(tr));
        const savedRevGroups = {};
        document.querySelectorAll('#revisionTableBody tr').forEach(tr => {
            const no = (tr.querySelector('input[name="documentNo[]"]')?.value || '').trim().toLowerCase();
            if (!no) return;
            tr.dataset.linked = 'true';
            tr.dataset.linkedDocNo = no;
            const rev = parseInt(tr.querySelector('input[name="revisionNo[]"]')?.value || '0', 10) || 0;
            if (!savedRevGroups[no] || rev >= savedRevGroups[no].rev) savedRevGroups[no] = { rev, tr };
        });
        document.querySelectorAll('#revisionTableBody tr').forEach(tr => {
            const no = tr.dataset.linkedDocNo || '';
            if (no && savedRevGroups[no] && savedRevGroups[no].tr !== tr) lockRevisionHistoryRow(tr);
        });

        // ── Version type change → apply revision mode ──
        const versionTypeEl = document.getElementById("versionType");
        if (versionTypeEl) {
            versionTypeEl.addEventListener('change', () => { applyRevisionMode(); });
        }
        updateRegistrationMode();

        // Draft with empty Document No → fill CSPC type prefix (editable).
        maybeAutofillDocNo();

        // ── Chip widgets ──
        createSourceUnitWidget({
            key: 'drf',
            widgetId: 'drfSourceUnitWidget',
            inputId: 'drfSourceUnitSearch',
            arrowId: 'drfSourceArrowBtn',
            resultsId: 'drfSourceResults',
            chipsId: 'drfSourceInlineChips',
            allowFreeText: false,
            officeFieldName: 'drfSourceUnit[]',
            nameFieldName: null,
            initial: (window.__existingDrfOffices || []).map(o => ({ type: 'office', id: o.id, label: o.label })),
        });

        createSourceUnitWidget({
            key: 'dcn',
            widgetId: 'dcnSourceUnitWidget',
            inputId: 'dcnSourceUnitSearch',
            arrowId: 'dcnSourceArrowBtn',
            resultsId: 'dcnSourceResults',
            chipsId: 'dcnSourceInlineChips',
            allowFreeText: false,
            officeFieldName: 'dcnSourceUnit[]',
            nameFieldName: null,
            initial: (window.__existingDcnOffices || []).map(o => ({ type: 'office', id: o.id, label: o.label })),
        });

        createSourceUnitWidget({
            key: 'masterlist',
            widgetId: 'masterlistSourceWidget',
            inputId: 'masterlistSourceSearch',
            arrowId: 'masterlistSourceArrowBtn',
            resultsId: 'masterlistSourceSuggestions',
            chipsId: 'masterlistSourceInlineChips',
            allowFreeText: false,
            officeFieldName: 'masterlistOfficeIds[]',
            nameFieldName: 'masterlistOriginatorNames[]',
            initial: (window.__existingMasterlistSource || []).map(o => ({
                type: o.type || 'office',
                id: o.id,
                label: o.label,
            })),
        });

        createSourceUnitWidget({
            key: 'masterlistOriginator',
            widgetId: 'masterlistOriginatorWidget',
            inputId: 'masterlistOriginatorSearch',
            arrowId: 'masterlistOriginatorArrowBtn',
            resultsId: 'masterlistOriginatorResults',
            chipsId: 'masterlistOriginatorInlineChips',
            allowFreeText: true,
            fieldName: 'masterlistOriginator',
            dataListGetter: () => allOriginators,
            idKey: 'originator_id',
            labelKey: 'originator_name',
            itemLabelPlural: 'originators',
            overlayTitle: 'Originator',
            initial: (window.__existingMasterlistOriginator || []).map((o, i) => ({ type: 'name', id: 'seed' + i, label: o.label })),
        });

        renderRelatedDocsChips();

        // ── Syllabi: rebuild wizard rows from existing grouped data ──
        await seedExistingSyllabiGroups();
        if (typeof applyOfficeIntakeStampOnEdit === 'function') {
            await applyOfficeIntakeStampOnEdit();
        }

        // Wire cascade listeners; registered docs keep context selects locked (Year Level stays editable).
        initSyllabiContextWiring();

        // ── Syllabi title tracking ──
        const titleInput = document.getElementById("syllabiDocTitle");
        if (titleInput) {
            titleInput.addEventListener("input", () => { syllabiTitleManuallyEdited = true; });
        }

    } catch (err) {
        console.error("Failed to load data:", err);
        showApiError("Failed to load form data. Please refresh the page.");
    }

    // ── Dirty state detection ──
    // Leave warning / Update button only when the user actually changed something
    // vs the loaded document — not merely because saved fields already have values.
    (function () {
        const updateBtn = document.getElementById('btnUpdateDocument')
            || document.querySelector('#formActions .reg-btn-save');
        if (!updateBtn) return;
        const initialState = {};
        let stateCaptured = false;
        let userInteracted = false;

        /**
         * Shoulds ignore field.
         *
         * @param {*} el
         */
        function shouldIgnoreField(el) {
            if (!el) return true;
            const type = (el.type || '').toLowerCase();
            if (type === 'hidden') {
                const name = el.name || el.id || '';
                // Track checklist / office / meaningful hiddens; skip CSRF & draft plumbing.
                if (name === '_token' || name === 'save_as_draft' || name === 'draft_leave_to') return true;
                if (el.id === 'saveAsDraft' || el.id === 'draftLeaveTo') return true;
                return false;
            }
            if (type === 'button' || type === 'submit' || type === 'reset') return true;
            const id = el.id || '';
            const name = el.name || '';
            // Search boxes / readonly computed displays are not user "document" edits.
            if (/Search$/i.test(id) || /Search$/i.test(name)) return true;
            if (/TimeSpentDisplay$/i.test(id)) return true;
            if (el.readOnly && /TimeSpent/i.test(id)) return true;
            if (el.classList?.contains('reg-reldocs-input')) return true;
            return false;
        }

        /**
         * Fields key.
         *
         * @param {*} el
         */
        function fieldKey(el) {
            const name = el.name || '';
            if (name.endsWith('[]')) return '';
            const id = el.id || '';
            if (el.type === 'checkbox') {
                return 'cb|' + name + '|' + (el.value || '') + '|' + id;
            }
            if (el.type === 'radio') {
                return 'radio|' + name + '|' + id;
            }
            if (el.type === 'file') {
                return 'file|' + (id || name);
            }
            return 'val|' + (id || name);
        }

        /**
         * Reads field value.
         *
         * @param {*} el
         */
        function readFieldValue(el) {
            if (el.type === 'checkbox') return el.checked ? '1' : '0';
            if (el.type === 'radio') {
                return document.querySelector('input[name="' + CSS.escape(el.name) + '"]:checked')?.value || '';
            }
            if (el.type === 'file') return '';
            return String(el.value ?? '').replace(/\s+/g, ' ').trim();
        }

        /**
         * Collections signature.
         *
         * @param {*} form
         */
        function collectionSignature(form) {
            const groups = {};
            form.querySelectorAll('input, select, textarea').forEach((el) => {
                const name = el.name || '';
                if (!name.endsWith('[]') || shouldIgnoreField(el)) return;
                if (!groups[name]) groups[name] = [];
                groups[name].push(readFieldValue(el));
            });
            const sig = {};
            Object.keys(groups).sort().forEach((name) => {
                sig[name] = groups[name].slice().sort().join('\u0001');
            });
            return sig;
        }

        /**
         * Captures initial state.
         */
        function captureInitialState() {
            const form = document.getElementById('masterForm');
            if (!form) return;
            Object.keys(initialState).forEach((k) => { delete initialState[k]; });
            form.querySelectorAll('input, select, textarea').forEach((el) => {
                if (shouldIgnoreField(el)) return;
                const key = fieldKey(el);
                if (!key || key === 'val|' || key === 'cb||' || key === 'radio|') return;
                initialState[key] = readFieldValue(el);
            });
            ['section-1', 'section-2', 'section-3', 'section-4', 'section-5', 'section-approval', 'section-approval-toggle', 'section-syllabi'].forEach((id) => {
                const el = document.getElementById(id);
                if (el) initialState['visible|' + id] = el.style.display !== 'none' ? '1' : '0';
            });
            const retBody = document.getElementById('retrievalBody');
            const distBody = document.getElementById('distBody');
            const retIds = retBody
                ? [...retBody.querySelectorAll('input[type="hidden"][name="retrievalOffice[]"]')].map((i) => String(i.value)).filter(Boolean).sort()
                : [];
            const distIds = distBody
                ? [...distBody.querySelectorAll('input[type="hidden"][name="distOffice[]"]')].map((i) => String(i.value)).filter(Boolean).sort()
                : [];
            initialState['officeCount|retrievalBody'] = String(retIds.length);
            initialState['officeCount|distBody'] = String(distIds.length);
            initialState['officeIds|retrievalBody'] = retIds.join(',');
            initialState['officeIds|distBody'] = distIds.join(',');
            ['masterlistOfficeIds[]', 'drfSourceUnit[]', 'dcnSourceUnit[]', 'masterlistOriginatorNames[]'].forEach((name) => {
                initialState['source|' + name] = sourceHiddenIds(name).join(',');
            });
            const collections = collectionSignature(form);
            Object.keys(collections).forEach((name) => {
                initialState['collection|' + name] = collections[name];
            });
        }

        /**
         * Sources hidden ids.
         *
         * @param {*} name
         */
        function sourceHiddenIds(name) {
            const form = document.getElementById('masterForm');
            if (!form) return [];
            return [...form.querySelectorAll('input[type="hidden"][name="' + name + '"]')]
                .map((inp) => String(inp.value))
                .filter(Boolean)
                .sort();
        }

        /**
         * Checks whether dirty condition is met.
         */
        function isDirty() {
            // No baseline yet → treat as clean (loaded data is not "unsaved").
            if (!stateCaptured) return false;
            // Require a real user edit before blocking leave on published docs.
            if (!userInteracted && !window.__isDraftDoc) return false;

            const form = document.getElementById('masterForm');
            if (!form) return false;

            let dirty = false;
            form.querySelectorAll('input, select, textarea').forEach((el) => {
                if (dirty || shouldIgnoreField(el)) return;
                const key = fieldKey(el);
                if (initialState[key] === undefined) return;
                if (el.type === 'file') {
                    if (el.files && el.files.length > 0) dirty = true;
                    return;
                }
                if (readFieldValue(el) !== initialState[key]) dirty = true;
            });

            ['section-1', 'section-2', 'section-3', 'section-4', 'section-5', 'section-approval', 'section-approval-toggle', 'section-syllabi'].forEach((id) => {
                if (dirty) return;
                const el = document.getElementById(id);
                if (!el || initialState['visible|' + id] === undefined) return;
                const now = el.style.display !== 'none' ? '1' : '0';
                if (now !== initialState['visible|' + id]) dirty = true;
            });

            [
                ['retrievalBody', 'retrievalOffice[]'],
                ['distBody', 'distOffice[]'],
            ].forEach(([bodyId, name]) => {
                if (dirty) return;
                const tbody = document.getElementById(bodyId);
                if (!tbody || initialState['officeCount|' + bodyId] === undefined) return;
                const ids = [...tbody.querySelectorAll('input[type="hidden"][name="' + name + '"]')]
                    .map((inp) => String(inp.value))
                    .filter(Boolean)
                    .sort();
                const currentCount = String(ids.length);
                if (currentCount !== initialState['officeCount|' + bodyId]) {
                    dirty = true;
                    return;
                }
                const baselineIds = initialState['officeIds|' + bodyId];
                if (baselineIds !== undefined && baselineIds !== ids.join(',')) {
                    dirty = true;
                }
            });

            ['masterlistOfficeIds[]', 'drfSourceUnit[]', 'dcnSourceUnit[]', 'masterlistOriginatorNames[]'].forEach((name) => {
                if (dirty) return;
                const baseline = initialState['source|' + name];
                if (baseline === undefined) return;
                if (baseline !== sourceHiddenIds(name).join(',')) dirty = true;
            });

            const collections = collectionSignature(form);
            Object.keys(initialState).forEach((key) => {
                if (dirty || !key.startsWith('collection|')) return;
                const name = key.slice('collection|'.length);
                if ((collections[name] || '') !== initialState[key]) dirty = true;
            });
            Object.keys(collections).forEach((name) => {
                if (dirty) return;
                if (initialState['collection|' + name] === undefined && collections[name] !== '') dirty = true;
            });

            return dirty;
        }

        window.__regEditIsDirty = isDirty;
        /**
         * Regs edit mark clean.
         */
        window.__regEditMarkClean = function () {
            captureInitialState();
            stateCaptured = true;
            userInteracted = false;
            updateButtonState();
        };
        /** Cluster chips / office add-remove don't fire input events — mark dirty explicitly. */
        window.__regEditMarkDirty = function () {
            if (!stateCaptured) {
                captureInitialState();
                stateCaptured = true;
            }
            userInteracted = true;
            updateButtonState();
        };

        /**
         * Renders and synchronizes button state in the DOM.
         */
        function updateButtonState() {
            const hint = document.getElementById('regNoChangesHint');
            const appraiseBtn = document.getElementById('btnSaveAndAppraise');
            const isDraft = !!window.__isDraftDoc;
            const enabled = isDraft || isDirty();
            if (updateBtn) {
                updateBtn.disabled = !enabled;
                updateBtn.style.opacity = enabled ? '' : '0.45';
                updateBtn.style.cursor = enabled ? '' : 'not-allowed';
                updateBtn.style.pointerEvents = enabled ? '' : 'none';
                if (enabled) {
                    updateBtn.title = '';
                    updateBtn.removeAttribute('aria-disabled');
                } else {
                    updateBtn.title = 'No changes detected — edit a field to enable Update Document.';
                    updateBtn.setAttribute('aria-disabled', 'true');
                }
            }
            if (appraiseBtn) {
                appraiseBtn.disabled = false;
                appraiseBtn.style.opacity = '';
                appraiseBtn.style.cursor = '';
                appraiseBtn.style.pointerEvents = '';
                appraiseBtn.removeAttribute('aria-disabled');
            }
            if (hint) hint.hidden = enabled;
        }

        /**
         * Renders and synchronizes baseline if pristine in the DOM.
         */
        function refreshBaselineIfPristine() {
            if (userInteracted) return;
            captureInitialState();
            stateCaptured = true;
            updateButtonState();
        }

        updateButtonState();
        setTimeout(refreshBaselineIfPristine, 400);
        setTimeout(refreshBaselineIfPristine, 1200);
        setTimeout(refreshBaselineIfPristine, 2500);

        const form = document.getElementById('masterForm');
        if (form) {
            const onUserEdit = (event) => {
                // Ignore script-driven input/change during hydration.
                if (event && event.isTrusted === false) return;
                if (event?.target && shouldIgnoreField(event.target)) return;
                if (!stateCaptured) {
                    captureInitialState();
                    stateCaptured = true;
                }
                userInteracted = true;
                updateButtonState();
            };
            form.addEventListener('input', onUserEdit, true);
            form.addEventListener('change', onUserEdit, true);
        }

        // Widget re-renders before the user edits → refresh baseline, don't warn.
        const observer = new MutationObserver(() => {
            if (!userInteracted) {
                setTimeout(refreshBaselineIfPristine, 50);
                return;
            }
            setTimeout(updateButtonState, 100);
        });
        if (form) observer.observe(form, { childList: true, subtree: true });

        const originalConfirmSave = window.confirmSave;
        /**
         * Confirms save.
         */
        window.confirmSave = function () {
            if (!window.__isDraftDoc && !isDirty() && !window.__openAppraisalAfterSave) return;
            if (originalConfirmSave) originalConfirmSave();
        };
    })();

    initSelectProtection();
    initFileInputs();
    initKeywordsWidget();

    setTimeout(() => {
        calcMasterlistTimeSpent();
        calcRetrievalTimeSpent();
        calcDistributionTimeSpent();
        updateSyllabiTotals();
    }, 150);

    setTimeout(() => { revertAutofill(); }, 500);
    setTimeout(() => { revertAutofill(); }, 1500);
});

/**
 * Reverts autofill.
 */
function revertAutofill() {
    const selectIds = ["versionType", "docType", "subType", "approvalBody"];
    selectIds.forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        const lastValid = el.dataset.lastValid;
        if (lastValid !== undefined && lastValid !== "" && el.value !== lastValid) el.value = lastValid;
    });
}

/**
 * Displays the api error overlay or dialog.
 *
 * @param {*} message
 */
function showApiError(message) {
    if (window.dcsShowToast) {
        window.dcsShowToast(message, 'error');
        return;
    }
}

// ══════════════════════════════════════════════
// CHECKLISTS
// ══════════════════════════════════════════════
async function loadChecklists(versionId) {
    if (!versionId) return;
    try {
        const byVersion = (window.__registerCatalog && window.__registerCatalog.checklistsByVersion) || {};
        const checklists = byVersion[String(versionId)] || [];
        renderChecklists(checklists, true);
        initializeEditState();
    } catch (err) {
        console.error("Failed to load checklists:", err);
        showApiError("Failed to load checklists. Please refresh.");
    }
}

const SECTION_MAP = { 1: "section-1", 2: "section-2", 3: "section-3", 4: "section-4", 5: "section-5" };

/**
 * Allows document retrieval.
 */
function allowsDocumentRetrieval() {
    return window.__allowsRetrieval === true;
}

/**
 * Versions looks recurring.
 */
function versionLooksRecurring() {
    const hidden = document.getElementById('registrationMode');
    if (hidden && hidden.value === 'recurring') return true;
    const sel = document.getElementById('versionType');
    const text = sel?.options[sel.selectedIndex]?.text?.toLowerCase() || '';
    return text.includes('recur');
}

/**
 * Filters checklists for mode.
 *
 * @param {*} checklists
 */
function filterChecklistsForMode(checklists) {
    let list = Array.isArray(checklists) ? checklists : [];
    if (versionLooksRecurring()) {
        const names = {
            1: 'Document Request Form',
            3: 'Masterlist Registration',
            5: 'Document Distribution',
        };
        const byId = {};
        list.forEach(c => { byId[parseInt(c.checklist_id, 10)] = c; });
        return [1, 3, 5].map(id => byId[id] || { checklist_id: id, checklist_name: names[id] });
    }
    if (!allowsDocumentRetrieval()) {
        list = list.filter(c => parseInt(c.checklist_id, 10) !== 4);
    }
    const docTypeId = document.getElementById('docType')?.value;
    const subTypeId = document.getElementById('subType')?.value;
    if (typeof typeAllowsRevision === 'function' && !typeAllowsRevision(docTypeId, subTypeId)) {
        list = list.filter(c => parseInt(c.checklist_id, 10) !== 2);
    }
    return list;
}

/**
 * Renders and synchronizes checklists in the DOM.
 *
 * @param {*} checklists
 * @param {*} disabled
 */
function renderChecklists(checklists, disabled) {
    const container = document.getElementById("dynamicCheckboxes");
    container.innerHTML = "";

    filterChecklistsForMode(checklists).forEach(c => {
        const label = document.createElement("label");
        label.className = "reg-check-item";

        const cb = document.createElement("input");
        cb.type = "checkbox";
        cb.name = "checklists[]";
        cb.value = c.checklist_id;
        cb.autocomplete = "off";
        const isMasterlist = parseInt(c.checklist_id, 10) === 3;
        if (isMasterlist) label.classList.add("reg-check-locked");
        if (disabled) {
            cb.disabled = true;
        } else if (isMasterlist) {
            cb.disabled = false;
        } else {
            cb.disabled = false;
        }

        const sectionId = SECTION_MAP[c.checklist_id];
        const section = sectionId ? document.getElementById(sectionId) : null;
        let shouldCheck = section ? (section.style.display !== "none") : false;
        // Syllabi/TOS: DRF lives in the syllabi wizard, not standalone section-1
        if (parseInt(c.checklist_id, 10) === 1) {
            const syllabiSection = document.getElementById("section-syllabi");
            if (syllabiSection && syllabiSection.style.display !== "none") shouldCheck = true;
        }
        // First registration (revise_no 0): never show Document Retrieval
        if (parseInt(c.checklist_id, 10) === 4 && !allowsDocumentRetrieval()) {
            shouldCheck = false;
        }
        // Masterlist always on
        if (isMasterlist) shouldCheck = true;
        cb.checked = shouldCheck;
        cb.dataset.lastChecked = cb.checked ? "true" : "false";

        const span = document.createElement("span");
        span.textContent = c.checklist_name;

        label.appendChild(cb);
        label.appendChild(span);

        let userTouched = false;
        label.addEventListener("pointerdown", function (e) {
            if (e.target.disabled) return;
            userTouched = true;
        });
        label.addEventListener("keydown", function () {
            if (cb.disabled) return;
            userTouched = true;
        });

        cb.addEventListener("change", function () {
            if (parseInt(this.value, 10) === 3) {
                this.checked = true;
                this.dataset.lastChecked = "true";
                toggleSection(3, true);
                syncMasterlistChecklistHidden();
                return;
            }
            if (!userTouched) { this.checked = (this.dataset.lastChecked === "true"); return; }
            userTouched = false;
            this.dataset.lastChecked = this.checked ? "true" : "false";
            toggleSection(parseInt(this.value), this.checked);
        });

        container.appendChild(label);
    });

    if (!allowsDocumentRetrieval()) {
        const ret = document.getElementById('section-4');
        if (ret) ret.style.display = 'none';
    }

    lockMasterlistChecklistOn();
}

/**
 * Renders and synchronizes masterlist checklist hidden in the DOM.
 */
function syncMasterlistChecklistHidden() {
    const container = document.getElementById("dynamicCheckboxes");
    if (!container) return;
    container.querySelectorAll('input[type="hidden"][data-masterlist-checklist]').forEach(el => el.remove());
    const cb = container.querySelector('input[type="checkbox"][value="3"]');
    if (!cb || !cb.checked) return;
    if (!cb.disabled) return;
    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'checklists[]';
    hidden.value = '3';
    hidden.dataset.masterlistChecklist = 'true';
    container.appendChild(hidden);
}

/**
 * Locks masterlist checklist on.
 */
function lockMasterlistChecklistOn() {
    const container = document.getElementById("dynamicCheckboxes");
    if (!container) return;
    const cb = container.querySelector('input[type="checkbox"][value="3"]');
    if (!cb) return;
    cb.checked = true;
    // Keep enabled so the check matches Distribution (native accent-color).
    cb.disabled = false;
    cb.dataset.lastChecked = "true";
    cb.closest("label")?.classList.add("reg-check-locked");
    toggleSection(3, true);
    syncMasterlistChecklistHidden();
}

/**
 * Renders and synchronizes checklist hidden inputs in the DOM.
 */
function syncChecklistHiddenInputs() {
    const container = document.getElementById('dynamicCheckboxes');
    if (!container) return;
    container.querySelectorAll('input[type="hidden"][data-checklist-hidden]').forEach(el => el.remove());
    container.querySelectorAll('input[type="checkbox"]').forEach(cb => {
        if (!cb.checked) return;
        // Masterlist uses its own hidden when disabled
        if (parseInt(cb.value, 10) === 3) return;
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'checklists[]';
        hidden.value = cb.value;
        hidden.dataset.checklistHidden = 'true';
        container.appendChild(hidden);
    });
    syncMasterlistChecklistHidden();
}

/**
 * Initializes and sets up edit state.
 */
function initializeEditState() {
    const container = document.getElementById("dynamicCheckboxes");
    const subTypeId = document.getElementById("subType")?.value;

    // Syllabi mode must be set before toggleSection, or checking DRF would open section-1
    if (subTypeId && isSyllabiLikeSubType(subTypeId)) {
        const subTypeData = allDocTypes.find(d => d.doc_type_id == subTypeId);
        window.__isSyllabiMode = true;
        window.__syllabiModeLabel = subTypeData ? subTypeData.doc_type_name : 'Syllabi';
        const syllabiSection = document.getElementById("section-syllabi");
        if (syllabiSection) {
            syllabiSection.style.display = "block";
            applySyllabiSectionLabel();
            syncSyllabiToMasterlistFields();
        }
        container.querySelectorAll("input[type='checkbox']").forEach(cb => {
            if (cb.value === "1") {
                cb.checked = true;
                cb.dataset.lastChecked = "true";
            }
        });
    }

    container.querySelectorAll("input[type='checkbox']").forEach(cb => {
        const on = !!cb.checked;
        cb.dataset.lastChecked = on ? "true" : "false";
        // Edit: keep checked boxes enabled so native accent matches Masterlist/Distribution;
        // change handlers + lock prevent toggling off saved sections.
        cb.disabled = false;
        if (on) {
            cb.closest("label")?.classList.add("reg-check-locked");
        }
        toggleSection(parseInt(cb.value), on);
    });
    lockMasterlistChecklistOn();
    // Re-disable unchecked boxes only
    container.querySelectorAll("input[type='checkbox']").forEach(cb => {
        if (!cb.checked) {
            cb.disabled = true;
            cb.closest("label")?.classList.remove("reg-check-locked");
        }
    });
    syncChecklistHiddenInputs();
    if (window.__isSyllabiMode) {
        const drfSection = document.getElementById('section-1');
        if (drfSection) drfSection.style.display = 'none';
    }
    showFormActions();
    enableApproval();
    setTimeout(initFileInputs, 100);
    syncRecurFinder();
}

/**
 * Locks checklist.
 */
function lockChecklist() {
    const container = document.getElementById("dynamicCheckboxes");
    container.querySelectorAll("input[type='checkbox']").forEach(cb => {
        cb.disabled = true;
        cb.checked = false;
        cb.dataset.lastChecked = "false";
        toggleSection(parseInt(cb.value), false);
    });
    hideFormActions();
    disableApproval();
}

/**
 * Displays the form actions overlay or dialog.
 */
function showFormActions() {
    const el = document.getElementById("formActions");
    if (el) el.style.display = "flex";
}

/**
 * Closes or dismisses the form actions.
 */
function hideFormActions() {
    const el = document.getElementById("formActions");
    if (el) el.style.display = "none";
}

// ══════════════════════════════════════════════
// DOCUMENT NO. LOOKUP
// ══════════════════════════════════════════════
function isSharedSyllabiForm() {
    if (window.__isSyllabiMode) return true;
    const subTypeId = document.getElementById('subType')?.value;
    if (subTypeId && typeof isSyllabiLikeSubType === 'function' && isSyllabiLikeSubType(subTypeId)) return true;
    const typed = String(document.getElementById('masterlistDocNo')?.value || '').trim();
    return /^CSPC-F-COL-13$/i.test(typed) || /^CSPC-F-COL-16$/i.test(typed);
}

/**
 * Accepts duplicate doc no copy.
 */
window.acceptDuplicateDocNoCopy = function () {
    docNoDuplicate = false;
};

/**
 * Initializes and sets up doc no lookup.
 *
 * @param {*} revField
 */
function initDocNoLookup(revField) {
    const docNoInput = document.getElementById('masterlistDocNo');
    const hintEl = document.getElementById('docNoHint');
    if (!docNoInput) return;

    let docNoTimer = null;
    const scheduleLookup = () => {
        clearTimeout(docNoTimer);
        const docNo = docNoInput.value.trim();
        if (!docNo) { handleEmptyDocNo(hintEl, revField); return; }
        if (hintEl && document.getElementById('allowDuplicateDocNo')?.value !== '1') {
            hintEl.innerHTML = '<span style="color:#475569"><i class="fa-solid fa-spinner fa-spin"></i> Checking document...</span>';
            hintEl.style.color = '';
            hintEl.dataset.valid = '';
        }
        docNoTimer = setTimeout(() => runDocNoLookup(docNo, hintEl, revField), 160);
    };
    docNoInput.addEventListener('input', scheduleLookup);
    if (window.__isDraftDoc && docNoInput.value.trim()) {
        scheduleLookup();
    }
}

/** Drafts may change Revision No; published edits keep it locked. */
function initDraftRevNoLookup(revField) {
    if (!revField || revField.disabled || !window.__isDraftDoc) return;

    let revNoTimer = null;
    const schedule = () => {
        clearTimeout(revNoTimer);
        revNoTimer = setTimeout(() => runDraftRevNoCheck(revField), 400);
    };

    revField.addEventListener('input', function () {
        if (typeof versionLooksRecurring === 'function' && versionLooksRecurring()) {
            this.value = this.dataset.recurRev ?? this.value;
            this.dataset.userEdited = '';
            return;
        }
        this.dataset.userEdited = 'true';
        schedule();
    });
    revField.addEventListener('change', schedule);
    revField.addEventListener('blur', schedule);
    document.getElementById('masterlistDocNo')?.addEventListener('input', schedule);
    schedule();
}

/**
 * Runs draft rev no check.
 *
 * @param {*} revField
 */
async function runDraftRevNoCheck(revField) {
    const hint = document.getElementById('revNoHint');
    const docNo = (document.getElementById('masterlistDocNo')?.value || '').trim();
    if (!revField || revField.disabled || !docNo) {
        if (hint) hint.innerHTML = '';
        return;
    }

    const reviseNo = revField.value === '' ? '0' : revField.value;
    const docTypeId = document.getElementById('docType')?.value || '';
    const subTypeId = document.getElementById('subType')?.value || '';
    const excludeRequestId = document.getElementById('requestId')?.value || '';

    try {
        const allowDuplicate = document.getElementById('allowDuplicateDocNo')?.value === '1' ? '1' : '0';
        const insertShift = document.getElementById('insertShiftConfirmed')?.value === '1' ? '1' : '0';
        const url = '/dcs/register/check-revno?doc_no=' + encodeURIComponent(docNo) +
            '&revise_no=' + encodeURIComponent(reviseNo) +
            (docTypeId ? '&doc_type_id=' + encodeURIComponent(docTypeId) : '') +
            (subTypeId ? '&sub_type_id=' + encodeURIComponent(subTypeId) : '') +
            (excludeRequestId ? '&exclude_request_id=' + encodeURIComponent(excludeRequestId) : '') +
            '&insert_shift_confirmed=' + encodeURIComponent(insertShift) +
            '&allow_duplicate_doc_no=' + encodeURIComponent(allowDuplicate);
        const res = await fetch(url);
        const data = await res.json();

        if (data.needs_doc_no || data.needs_doc_type) {
            if (hint) hint.innerHTML = '';
            return;
        }

        if (allowDuplicate === '1' || data.duplicate_copy) {
            if (hint) {
                hint.innerHTML = '';
                hint.style.color = '';
            }
            revField.style.borderColor = '';
            revField.classList.remove('reg-input-invalid');
            return;
        }

        if (typeof versionLooksRecurring === 'function' && versionLooksRecurring()) {
            const keep = revField.dataset.recurRev || revField.value || '0';
            revField.value = keep;
            revField.readOnly = true;
            revField.classList.add('reg-rev-locked');
            revField.classList.remove('reg-input-invalid');
            revField.style.background = '#f1f5f9';
            revField.style.borderColor = '';
            if (hint) {
                hint.innerHTML = '';
                hint.style.color = '';
            }
            return;
        }

        if (data.allows_revision === false) {
            if (revField.value === '' || revField.value == null) revField.value = '0';
            revField.readOnly = false;
            revField.classList.remove('reg-rev-locked');
            revField.style.background = '';
            revField.style.borderColor = '';
            revField.classList.remove('reg-input-invalid');
            if (hint) {
                // Keep Rev 0 quiet — no "Suggested Rev 0…" tip.
                hint.innerHTML = '';
                hint.style.color = '';
                hint.dataset.valid = 'stackable';
            }
            return;
        }

        if (data.taken) {
            if (hint) {
                hint.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' +
                    escapeHtml(data.message || 'This revision already exists.');
                hint.style.color = '#dc2626';
            }
            revField.style.borderColor = '#dc2626';
            revField.classList.add('reg-input-invalid');
            return;
        }

        if (hint) {
            if (Array.isArray(data.taken_revs) && data.taken_revs.length > 0) {
                hint.innerHTML = '<i class="fa-solid fa-circle-check"></i> ' +
                    escapeHtml(data.message || 'Revision available.');
                hint.style.color = '#16a34a';
            } else {
                hint.innerHTML = '';
            }
        }
        revField.style.borderColor = '';
        revField.classList.remove('reg-input-invalid');
    } catch (e) {
        console.error('Draft RevNo check failed:', e);
    }
}

/**
 * Handles empty doc no event or response.
 *
 * @param {*} hintEl
 * @param {*} revField
 */
function handleEmptyDocNo(hintEl, revField) {
    docNoDuplicate = false;
    if (hintEl) { hintEl.innerHTML = ''; hintEl.dataset.valid = ''; }
}

/**
 * Runs doc no lookup.
 *
 * @param {*} docNo
 * @param {*} hintEl
 * @param {*} revField
 */
async function runDocNoLookup(docNo, hintEl, revField) {
    try {
        const docTypeId = document.getElementById('docType').value;
        const subTypeId = document.getElementById('subType').value;
        const excludeRequestId = document.getElementById('requestId')?.value || '';
        const url = '/dcs/register/check-docno?doc_no=' + encodeURIComponent(docNo) +
                    (docTypeId ? '&doc_type_id=' + docTypeId : '') +
                    (subTypeId ? '&sub_type_id=' + subTypeId : '') +
                    (excludeRequestId ? '&exclude_request_id=' + excludeRequestId : '') +
                    '&duplicate_only=1';
        const res = await fetch(url);
        const data = await res.json();

        if (data.exists && !data.is_self && document.getElementById('allowDuplicateDocNo')?.value === '1') {
            docNoDuplicate = false;
            return;
        }
        if (data.exists && !data.is_self) {
            // Draft publish colliding with a registered number must always show the modal
            // (even when the draft already had that number saved).
            docNoDuplicate = true;
            if (hintEl) {
                hintEl.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> This document number is already registered. Choose the next free number, insert into this group, or register another copy.';
                hintEl.style.color = '#dc2626';
                hintEl.dataset.valid = 'duplicate';
            }
            /**
             * Marks doc no insert ready.
             */
            window.markDocNoInsertReady = function () {
                docNoDuplicate = false;
            };
            if (typeof window.openDocNoInsertModal === 'function') {
                window.openDocNoInsertModal(data);
            }
        } else if (data.wrong_type) {
            docNoDuplicate = false;
            if (hintEl) {
                hintEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ' + escapeHtml(data.message || '') +
                    '<br><span style="font-weight:400;font-size:11px;">This number is registered under a different document type. You may continue.</span>';
                hintEl.style.color = '#d97706';
                hintEl.dataset.valid = 'different_type';
            }
        } else {
            docNoDuplicate = false;
            if (hintEl) {
                hintEl.innerHTML = '<i class="fa-solid fa-circle-check"></i> ' + escapeHtml(data.is_self ? (data.message || 'This is the current document number.') : 'Document number is available.');
                hintEl.style.color = '#16a34a';
                hintEl.dataset.valid = 'available';
            }
            // Free / current number — clear stale "Rev 0 is taken" from a prior lookup.
            const revHint = document.getElementById('revNoHint');
            const revField = document.getElementById('masterlistRevisionNo');
            if (revHint) {
                revHint.innerHTML = '';
                revHint.style.color = '';
            }
            if (revField) {
                revField.style.borderColor = '';
                revField.classList.remove('reg-input-invalid');
            }
            if (typeof window.scheduleRevNoCheck === 'function') {
                window.scheduleRevNoCheck();
            }
        }
    } catch (e) {
        console.error('DocNo lookup failed:', e);
        docNoDuplicate = false;
    }
}

/**
 * Applies revised document context.
 *
 * @param {*} data
 * @param {*} options
 */
function applyRevisedDocumentContext(data, options = {}) {
    const { docNo, hintEl, revField } = options;
    const revFieldEl = revField || document.getElementById('masterlistRevisionNo');
    const hint = hintEl !== undefined ? hintEl : document.getElementById('docNoHint');

    if (docNo !== undefined && docNo !== null) {
        const docNoInput = document.getElementById('masterlistDocNo');
        if (docNoInput) docNoInput.value = docNo;
        window.__revisedFromDocNo = String(docNo).trim();
    }

    if (revFieldEl && !revFieldEl.disabled) {
        if (typeof isRecurringMode === 'function' && isRecurringMode()) {
            const keep = data.latest_rev ?? revFieldEl.value ?? 0;
            revFieldEl.value = keep;
            revFieldEl.readOnly = true;
            revFieldEl.style.background = '#f1f5f9';
        } else {
        // Keep a manually typed Rev; only suggest next when the field is empty / not user-pinned.
        const userPinned = revFieldEl.dataset.userEdited === 'true'
            && String(revFieldEl.value || '').trim() !== '';
        if (!userPinned && (options.forceNextRev || !String(revFieldEl.value || '').trim())) {
            revFieldEl.value = data.next_rev;
            revFieldEl.dataset.userEdited = '';
        }
        revFieldEl.readOnly = false;
        revFieldEl.style.background = '';
        revFieldEl.style.cursor = '';
        revFieldEl.removeAttribute('min');
        revFieldEl.setAttribute('title', 'Suggested: Rev ' + data.next_rev + ' (family latest is ' + data.latest_rev + '). Gap fills are allowed only if that Rev is unused in the family.');
        }
    }

    const titleField = document.getElementById('masterlistDocTitle');
    if (titleField && data.latest_title) {
        titleField.value = data.latest_title;
        titleField.readOnly = false;
    }
    const drfTitleField = document.getElementById('drfTitle');
    if (drfTitleField && data.latest_title && !String(drfTitleField.value || '').trim()) {
        drfTitleField.value = data.latest_title;
    }

    const pagesField = document.getElementById('masterlistNoOfPages');
    if (pagesField && (data.latest_no_pages !== null && data.latest_no_pages !== undefined)
        && !String(pagesField.value || '').trim()) {
        pagesField.value = data.latest_no_pages;
    }

    // Keywords are NOT copied on revise — user enters new keywords for this revision

    window.__syncingSourceUnits = true;
    try {
        if (window.__sourceWidgets.masterlist) {
            window.__sourceWidgets.masterlist.reset();
            if (data.latest_source_unit) {
                window.__sourceWidgets.masterlist.seedFromString(data.latest_source_unit, true);
            }
        }

        if (window.__sourceWidgets.masterlistOriginator) {
            window.__sourceWidgets.masterlistOriginator.reset();
            if (data.latest_originator) {
                window.__sourceWidgets.masterlistOriginator.seedFromString(data.latest_originator, true);
            }
        }
    } finally {
        window.__syncingSourceUnits = false;
    }

    if (window.__sourceWidgets.masterlist) {
        syncSourceUnitsAcrossSections([...window.__sourceWidgets.masterlist.selected], 'masterlist');
    }

    if (data.latest_related_documents && data.latest_related_documents.length > 0 && relatedDocsSelected.length === 0) {
        relatedDocsSelected = data.latest_related_documents.slice();
        renderRelatedDocsChips();
    }

    if (typeof versionLooksRecurring === 'function' && versionLooksRecurring()) {
        seedRecurDistributionOffices(data.latest_distribution_offices || []);
    } else if (data.latest_distribution_offices && data.latest_distribution_offices.length > 0) {
        seedRevisedDistributionOffices(data.latest_distribution_offices, 'distBody', 'distTotal');
        if (tableIsEmpty('retrievalBody')) {
            data.latest_distribution_offices.forEach(o => {
                seedRetrievalOfficeRow('retrievalBody', 'retrievalTotal', o.office_id, o.office_name, o.copies, 'pending');
            });
            updateTotal('retrievalTotal', 'retrievalBody');
        }
    }

    if (!(typeof versionLooksRecurring === 'function' && versionLooksRecurring())
        && data.already_retrieved_offices && data.already_retrieved_offices.length > 0) {
        data.already_retrieved_offices.forEach(o => {
            seedRetrievalOfficeRow(
                'retrievalBody', 'retrievalTotal',
                o.office_id, o.office_name, o.copies || 1, 'pending'
            );
        });
        updateTotal('retrievalTotal', 'retrievalBody');
        const retSection = document.getElementById('section-4');
        if (retSection && (typeof allowsDocumentRetrieval !== 'function' || allowsDocumentRetrieval())) {
            retSection.style.display = 'block';
        }
    }

    applyRevisedApprovalFromPrevious(data);

    if (hint) {
        hint.innerHTML = '<i class="fa-solid fa-circle-check"></i> Document found. Keep or renumber — details prefilled (dates blank).';
        hint.style.color = '#16a34a';
        hint.dataset.valid = 'true';
    }
    window.DCSScanNamePreview?.update();
}

/**
 * Sync Approval radios + Approval Details section from the previous registration.
 * - Previous Applicable  → Applicable checked, Approval Details shown (body/no filled, date blank)
 * - Previous Not Applicable / unknown → Not Applicable, Approval Details hidden
 * User may still switch Applicable ↔ Not Applicable manually after that.
 */
function applyRevisedApprovalFromPrevious(data) {
    if (!data) return;

    window.__revisedApprovalContext = {
        latest_approval_status: data.latest_approval_status ?? null,
        latest_approval_body_id: data.latest_approval_body_id ?? null,
        latest_approval_no: data.latest_approval_no ?? null,
    };

    const status = String(data.latest_approval_status || '').toLowerCase();
    const isApplicable = status === 'applicable';

    const applicableRadio = document.querySelector('input[name="approval_status"][value="applicable"]');
    const notApplicableRadio = document.querySelector('input[name="approval_status"][value="not_applicable"]');
    if (!applicableRadio || !notApplicableRadio) return;

    applicableRadio.disabled = false;
    notApplicableRadio.disabled = false;

    applicableRadio.checked = isApplicable;
    notApplicableRadio.checked = !isApplicable;

    if (typeof window.handleApprovalToggle === 'function') {
        window.handleApprovalToggle(isApplicable);
    } else {
        const approval = document.getElementById('section-approval');
        if (approval) approval.style.display = isApplicable ? 'block' : 'none';
    }

    const bodySelect = document.getElementById('approvalBody');
    const approvalNo = document.getElementById('approvalNo');
    const approvalDate = document.getElementById('approvalDate');

    if (isApplicable) {
        if (bodySelect && data.latest_approval_body_id != null && data.latest_approval_body_id !== '') {
            const want = String(data.latest_approval_body_id);
            const hasOption = Array.from(bodySelect.options).some(o => String(o.value) === want);
            if (hasOption) {
                bodySelect.value = want;
                bodySelect.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
        if (approvalNo && data.latest_approval_no) {
            approvalNo.value = data.latest_approval_no;
        }
        if (approvalDate && !document.getElementById('requestId')?.value) {
            approvalDate.value = '';
        }
    } else {
        if (bodySelect) bodySelect.selectedIndex = 0;
        if (approvalNo) approvalNo.value = '';
        if (approvalDate && !document.getElementById('requestId')?.value) {
            approvalDate.value = '';
        }
    }
}

/**
 * Clears and resets revised approval context.
 */
function clearRevisedApprovalContext() {
    window.__revisedApprovalContext = null;
}

/**
 * Checks whether dcn section visible condition is met.
 */
function isDcnSectionVisible() {
    const section = document.getElementById('section-2');
    return section && section.style.display !== 'none';
}

/**
 * Bridges dcn pick to masterlist.
 *
 * @param {*} docNo
 * @param {*} options
 */
async function bridgeDcnPickToMasterlist(docNo, options = {}) {
    const hintEl = document.getElementById('docNoHint');
    const revField = document.getElementById('masterlistRevisionNo');
    try {
        const docTypeId = document.getElementById('docType').value;
        const subTypeId = document.getElementById('subType').value;
        const excludeRequestId = document.getElementById('requestId')?.value || '';
        const params = new URLSearchParams({ include_drafts: '1' });
        if (docNo) params.set('doc_no', docNo);
        if (options.requestId) params.set('request_id', String(options.requestId));
        if (options.masterlistId) params.set('masterlist_id', String(options.masterlistId));
        if (docTypeId) params.set('doc_type_id', docTypeId);
        if (subTypeId) params.set('sub_type_id', subTypeId);
        if (excludeRequestId) params.set('exclude_request_id', excludeRequestId);
        const res = await fetch('/dcs/register/check-docno?' + params.toString());
        const data = await res.json();
        if (data.exists && (!docNo || !otherLinkedRevisionDocuments(docNo))) {
            applyRevisedDocumentContext(data, { docNo: docNo || '', hintEl, revField, forceNextRev: true });
            if (options.masterlistId) {
                const idInput = document.getElementById('supersedeMasterlistId');
                if (idInput && !idInput.value) idInput.value = String(options.masterlistId);
            }
        }
        return data;
    } catch (e) {
        console.error('DCN pick check-docno failed:', e);
        return null;
    }
}

/**
 * Applies recur pick to masterlist.
 *
 * @param {*} doc
 */
async function applyRecurPickToMasterlist(doc) {
    if (!doc) return null;
    const title = String(doc.doc_title || doc.title || '').trim();
    const originator = String(doc.originator_name || '').trim();
    const effectivity = doc.effectivity_date || doc.effectivity || '';
    const titleField = document.getElementById('masterlistDocTitle');
    if (titleField && title) titleField.value = title;
    const effectivityField = document.getElementById('masterlistEffectivityDate');
    if (effectivityField && effectivity) {
        const raw = String(effectivity);
        effectivityField.value = /^\d{4}-\d{2}-\d{2}/.test(raw) ? raw.slice(0, 10) : raw;
    }
    if (originator && window.__sourceWidgets?.masterlistOriginator) {
        window.__sourceWidgets.masterlistOriginator.reset();
        window.__sourceWidgets.masterlistOriginator.seedFromString(originator, true);
    }
    const idInput = document.getElementById('supersedeMasterlistId');
    if (idInput && doc.masterlist_id) idInput.value = String(doc.masterlist_id);
    const fromInput = document.getElementById('revisedFromDocNo');
    if (fromInput) fromInput.value = doc.doc_no || '';
    return bridgeDcnPickToMasterlist(doc.doc_no || '', {
        requestId: doc.request_id,
        masterlistId: doc.masterlist_id,
    });
}

// ══════════════════════════════════════════════
// REVISION MODE HELPERS
// ══════════════════════════════════════════════
function isRecurringMode() {
    const hidden = document.getElementById('registrationMode');
    return hidden && hidden.value === 'recurring';
}

/**
 * Checks whether revised mode condition is met.
 */
function isRevisedMode() {
    const hidden = document.getElementById('registrationMode');
    return hidden && (hidden.value === 'revised' || hidden.value === 'recurring');
}

/** CSPC + type-code prefixes (dept/control # left for the user). Manuals/Policy skipped. */
const DOC_NO_PREFIX_BY_NAME = {
    'forms': 'CSPC-F-',
    'logbooks': 'CSPC-LB-',
    'work instructions': 'CSPC-WI-',
    'quality objectives': 'CSPC-QO-',
    'fmea': 'CSPC-FMEA-',
};

/**
 * Resolves selected doc type name.
 */
function resolveSelectedDocTypeName() {
    const subTypeId = document.getElementById('subType')?.value || CURRENT_SUB_TYPE_ID;
    const docTypeId = document.getElementById('docType')?.value || CURRENT_DOC_TYPE_ID;
    if (subTypeId) {
        const sub = (allDocTypes || []).find(d => String(d.doc_type_id) === String(subTypeId));
        return (sub?.doc_type_name || '').trim();
    }
    if (!docTypeId) return '';
    const hasChildren = (allDocTypes || []).some(d => String(d.parent_id) === String(docTypeId));
    if (hasChildren) return '';
    const parent = (allDocTypes || []).find(d => String(d.doc_type_id) === String(docTypeId));
    return (parent?.doc_type_name || '').trim();
}

const SYLLABI_FORM_DOC_NO = 'CSPC-F-COL-13';
const TOS_FORM_DOC_NO = 'CSPC-F-COL-16';

/**
 * Checks whether fixed form doc no condition is met.
 *
 * @param {*} value
 */
function isFixedFormDocNo(value) {
    const text = String(value || '').trim().toUpperCase();
    return text === SYLLABI_FORM_DOC_NO || text === TOS_FORM_DOC_NO;
}

/**
 * Docs no prefix for selection.
 */
function docNoPrefixForSelection() {
    const name = resolveSelectedDocTypeName().toLowerCase();
    if (name.includes('tos') || name.includes('rubric')) return TOS_FORM_DOC_NO;
    const subTypeId = document.getElementById('subType')?.value || CURRENT_SUB_TYPE_ID;
    if (subTypeId && typeof isSyllabiLikeSubType === 'function' && isSyllabiLikeSubType(subTypeId)) {
        return SYLLABI_FORM_DOC_NO;
    }
    if (!name) return null;
    if (name.includes('syllab')) return SYLLABI_FORM_DOC_NO;
    return DOC_NO_PREFIX_BY_NAME[name] || null;
}

/**
 * Renders and synchronizes doc no prefix hint in the DOM.
 */
function updateDocNoPrefixHint() {
    const hint = document.getElementById('docNoPrefixHint');
    if (!hint) return;
    hint.textContent = '';
    hint.style.display = 'none';
}

/**
 * Clears and resets doc no availability hint.
 */
function clearDocNoAvailabilityHint() {
    const hintEl = document.getElementById('docNoHint');
    if (!hintEl) return;
    hintEl.innerHTML = '';
    hintEl.style.color = '';
    hintEl.dataset.valid = '';
    if (typeof docNoDuplicate !== 'undefined') docNoDuplicate = false;
}

/**
 * Checks whether autofill doc no condition is met.
 */
function canAutofillDocNo() {
    if (isRevisedMode()) return false;
    if (window.__requireDraftForDocNoAutofill) return !!window.__isDraftDoc;
    return true;
}

/**
 * Maybes autofill doc no.
 */
function maybeAutofillDocNo() {
    if (!canAutofillDocNo()) return;
    const input = document.getElementById('masterlistDocNo');
    if (!input) return;

    const prefix = docNoPrefixForSelection();
    const current = input.value.trim();
    // COL-13 / COL-16 stay editable (shared form number); autofill still suggests them.
    input.readOnly = false;
    input.style.background = '';
    input.style.cursor = '';
    const priorAuto = (input.dataset.autodocno || '').trim();
    const canOverwrite = current === '' || (priorAuto !== '' && current === priorAuto);

    if (!prefix) {
        if (canOverwrite) {
            if (current !== '' || priorAuto) {
                input.value = '';
                delete input.dataset.autodocno;
                updateDocNoPrefixHint('');
            }
            clearDocNoAvailabilityHint();
        } else if (current === '') {
            clearDocNoAvailabilityHint();
        }
        return;
    }

    if (!canOverwrite) return;

    input.value = prefix;
    input.dataset.autodocno = prefix;
    updateDocNoPrefixHint(prefix);
    if (isFixedFormDocNo(prefix)) {
        input.dispatchEvent(new Event('input'));
        return;
    }
    clearDocNoAvailabilityHint();
}

/**
 * Recurs finder ready.
 */
function recurFinderReady() {
    if (!versionLooksRecurring()) return false;
    const docTypeId = document.getElementById('docType')?.value;
    if (!docTypeId) return false;
    const hasChildren = (typeof allDocTypes !== 'undefined' ? allDocTypes : []).some(d => String(d.parent_id) === String(docTypeId));
    if (!hasChildren) return true;
    return !!(document.getElementById('subType')?.value);
}

/**
 * Renders and synchronizes recur finder in the DOM.
 */
function syncRecurFinder() {
    const on = recurFinderReady();
    const panel = document.getElementById('section-supersede');
    if (panel) panel.style.display = on ? 'block' : 'none';
    if (!on) return;
    const dcn = document.getElementById('section-2');
    if (dcn) dcn.style.display = 'none';
    const retrieval = document.getElementById('section-4');
    if (retrieval) retrieval.style.display = 'none';
    bindSupersedeFinder();
    const fromNo = (document.getElementById('revisedFromDocNo')?.value || '').trim();
    const picked = document.getElementById('supersedeDocPicked');
    const search = document.getElementById('supersedeDocNo');
    if (fromNo && picked && picked.hidden) {
        picked.hidden = false;
        picked.textContent = fromNo + ' will be superseded.';
        if (search && !search.value) search.value = fromNo;
        if (typeof syncSupersedeSelectedBar === 'function') syncSupersedeSelectedBar();
    }
    const hasSavedSelection = !!(window.__recurFromDatabase
        || window.__recurSelectionLocked
        || document.querySelector('#supersedeHistoryBody tr')
        || fromNo);
    if (hasSavedSelection) {
        window.__recurSelectionLocked = true;
        lockSupersedeFinderInputs(true);
    }
    if (!window.__supersedeHistoryRestored) {
        window.__supersedeHistoryRestored = true;
        restoreSupersedeHistory();
    }
}

/**
 * Supersedes finder parts.
 */
function supersedeFinderParts() {
    return {
        no: (document.getElementById('supersedeDocNo')?.value || '').trim(),
        title: (document.getElementById('supersedeDocTitle')?.value || '').trim(),
        originator: (document.getElementById('supersedeDocOriginator')?.value || '').trim(),
    };
}

/**
 * Supersedes doc matches.
 *
 * @param {*} doc
 * @param {*} parts
 */
function supersedeDocMatches(doc, parts) {
    const no = String(doc.doc_no || '').toLowerCase();
    const title = String(doc.doc_title || '').toLowerCase();
    const origin = String(doc.originator_name || '').toLowerCase();
    if (parts.no && !no.includes(parts.no.toLowerCase())) return false;
    if (parts.title && !title.includes(parts.title.toLowerCase())) return false;
    if (parts.originator && !origin.includes(parts.originator.toLowerCase())) return false;
    return true;
}

/**
 * Supersedes result html.
 *
 * @param {*} doc
 * @param {*} index
 */
function supersedeResultHtml(doc, index) {
    const origin = (doc.originator_name || '').trim();
    const effectivity = (doc.effectivity_date || '').trim();
    const pin = [
        origin ? ('Originator: ' + origin) : 'No originator',
        'Rev ' + (doc.revise_no ?? 0),
        revisionStatusText(doc.revision_status),
        effectivity ? ('Effectivity ' + effectivity) : '',
    ].filter(Boolean).join(' · ');
    return '<div data-supersede-idx="' + index + '">'
        + '<span style="display:block;font-weight:600;">' + escapeHtml(doc.doc_no || 'No number') + '</span>'
        + '<span style="display:block;">' + escapeHtml(doc.doc_title || 'Untitled') + '</span>'
        + '<span style="display:block;color:#64748b;font-size:0.78rem;">' + escapeHtml(pin) + '</span>'
        + '</div>';
}

/**
 * Revisions status text.
 *
 * @param {*} status
 */
function revisionStatusText(status) {
    const key = String(status || 'latest').toLowerCase();
    if (key === 'superseded') return 'Superseded';
    if (key === 'obsolete') return 'Obsolete';
    if (key === 'archived') return 'Archived';
    return 'Latest';
}

/**
 * Supersedes history text cell.
 *
 * @param {*} value
 * @param {*} cellClass
 */
function supersedeHistoryTextCell(value, cellClass) {
    const text = String(value || '').trim();
    const wrap = text.length > 28 ? ' is-wrap' : '';
    return '<td class="' + (cellClass || '') + '"><span class="reg-rev-title-text' + wrap + '">' + escapeHtml(text || '—') + '</span></td>';
}

/**
 * Displays the supersede document history overlay or dialog.
 *
 * @param {*} doc
 * @param {*} options
 */
async function showSupersedeDocumentHistory(doc, options) {
    options = options || {};
    const field = document.getElementById('supersedeHistoryField');
    const body = document.getElementById('supersedeHistoryBody');
    const idInput = document.getElementById('supersedeMasterlistId');
    const reselect = document.getElementById('supersedeReselect');
    if (idInput && !options.keepMasterlistId) {
        idInput.value = doc && doc.masterlist_id ? String(doc.masterlist_id) : '';
    }
    if (!field || !body || !doc) return;

    let revisions = Array.isArray(options.revisions) ? options.revisions.slice() : [];
    if (!revisions.length) {
        try {
            if (doc.request_id) {
                const params = new URLSearchParams({ request_id: String(doc.request_id) });
                if (doc.doc_no) params.set('doc_no', doc.doc_no);
                const raw = await fetch('/dcs/api/documents/revisions?' + params.toString()).then(r => r.json());
                if (Array.isArray(raw)) revisions = raw;
            }
        } catch (e) {
            revisions = [];
        }
    }
    if (!revisions.length) revisions = [doc];
    const pickId = String(doc.masterlist_id || '');
    const pickReq = String(doc.request_id || '');
    const pickTitle = String(doc.doc_title || doc.title || '').trim().toLowerCase();
    if (pickReq || pickId || pickTitle) {
        revisions = revisions.filter(function (row) {
            if (pickReq && String(row.request_id || '') === pickReq) return true;
            if (pickId && String(row.masterlist_id || '') === pickId) return true;
            const rowTitle = String(row.doc_title || row.title || '').trim().toLowerCase();
            return pickTitle !== '' && rowTitle === pickTitle;
        });
        if (!revisions.length) revisions = [doc];
    }
    if (!options.excludeRequestId) {
        const openId = document.getElementById('requestId')?.value;
        if (openId) options.excludeRequestId = openId;
    }
    if (options.excludeRequestId) {
        const currentId = String(options.excludeRequestId);
        revisions = revisions.filter(function (row) {
            return String(row.request_id || '') !== currentId;
        });
    }
    if (options.hideLatest) {
        revisions = revisions.filter(function (row) {
            return revisionStatusText(row.revision_status) !== 'Latest';
        });
    }
    if (!revisions.length && (doc.doc_no || doc.doc_title || doc.masterlist_id)) {
        revisions = [doc];
    }

    const selectedId = String(doc.masterlist_id || '');
    body.innerHTML = revisions.map(function (row) {
        const status = revisionStatusText(row.revision_status);
        const isTarget = selectedId !== '' && String(row.masterlist_id || '') === selectedId;
        const purpose = String(row.brief_purpose || '').trim();
        const title = String(row.doc_title || '').trim();
        const effectivity = row.effectivity_date_label || row.effectivity_date || '—';
        const scan = row.scanned_copy_url
            ? '<button type="button" class="reg-revrow-viewfile reg-revrow-viewfile-pdf" data-scan-url="' + escapeHtml(row.scanned_copy_url) + '" title="View PDF"><i class="fa-solid fa-file-pdf"></i></button>'
            : '<span class="reg-rev-title-text">—</span>';
        return '<tr' + (isTarget ? ' class="is-supersede-target"' : '') + '>'
            + supersedeHistoryTextCell(row.doc_no || '—')
            + supersedeHistoryTextCell(title || '—')
            + supersedeHistoryTextCell(effectivity)
            + supersedeHistoryTextCell(String(row.revise_no ?? 0), 'reg-supersede-rev')
            + '<td><span class="reg-rev-status is-' + status.toLowerCase() + '">' + escapeHtml(status) + '</span></td>'
            + '<td style="text-align:center;">' + scan + '</td>'
            + supersedeHistoryTextCell(purpose || '—')
            + '</tr>';
    }).join('');
    field.hidden = revisions.length === 0;
    if (typeof syncSupersedeSelectedBar === 'function') {
        syncSupersedeSelectedBar();
    } else if (reselect) {
        reselect.hidden = !!window.__recurFromDatabase;
    }
    if (options.markSuperseded) {
        body.querySelectorAll('.reg-rev-status.is-latest').forEach(function (badge) {
            badge.textContent = 'Superseded';
            badge.classList.remove('is-latest');
            badge.classList.add('is-superseded');
        });
    }
    if (window.__recurSelectionLocked && typeof lockSupersedeFinderInputs === 'function') {
        lockSupersedeFinderInputs(true);
    }
}

/**
 * Renders and synchronizes supersede selected bar in the DOM.
 */
function syncSupersedeSelectedBar() {
    const picked = document.getElementById('supersedeDocPicked');
    const reselect = document.getElementById('supersedeReselect');
    const history = document.getElementById('supersedeHistoryField');
    const historyVisible = !!(history && !history.hidden);
    const hasPick = !!(picked && (picked.textContent || '').trim());
    if (picked) picked.hidden = true;
    // Keep Change document only before save, and only when the table is not already showing the pick.
    if (reselect) {
        reselect.hidden = !!window.__recurFromDatabase || historyVisible || !hasPick;
    }
}

/**
 * Locks supersede finder inputs.
 *
 * @param {*} locked
 */
function lockSupersedeFinderInputs(locked) {
    const section = document.getElementById('section-supersede');
    if (section) section.classList.toggle('is-finder-locked', !!locked);
    ['supersedeDocNo', 'supersedeDocTitle', 'supersedeDocOriginator'].forEach(function (id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.readOnly = !!locked;
        el.disabled = !!locked;
        if (locked) {
            el.setAttribute('tabindex', '-1');
            el.blur();
        } else {
            el.removeAttribute('tabindex');
        }
    });
    const hint = document.getElementById('supersedeFinderHint');
    if (hint) {
        hint.hidden = !!locked;
        hint.textContent = locked
            ? ''
            : 'Enter the document number and title. The originator picks the exact latest copy when more than one document shares that number.';
    }
    syncSupersedeSelectedBar();
    if (locked) {
        const reselect = document.getElementById('supersedeReselect');
        if (reselect) {
            // Saved registrations cannot change the superseded document; in-session picks can.
            reselect.hidden = !!window.__recurFromDatabase;
        }
    }
}

/**
 * Clears and resets supersede selection.
 */
function clearSupersedeSelection() {
    if (window.__recurFromDatabase) {
        lockSupersedeFinderInputs(true);
        return;
    }
    ['supersedeDocNo', 'supersedeDocTitle', 'supersedeDocOriginator', 'supersedeMasterlistId', 'revisedFromDocNo'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    const picked = document.getElementById('supersedeDocPicked');
    if (picked) {
        picked.hidden = true;
        picked.textContent = '';
    }
    const field = document.getElementById('supersedeHistoryField');
    const body = document.getElementById('supersedeHistoryBody');
    if (field) field.hidden = true;
    if (body) body.innerHTML = '';
    const reselect = document.getElementById('supersedeReselect');
    if (reselect) reselect.hidden = true;
    window.__recurSelectionLocked = false;
    lockSupersedeFinderInputs(false);
    document.getElementById('supersedeDocNo')?.focus();
}

/**
 * Restores supersede history.
 */
async function restoreSupersedeHistory() {
    if (document.querySelector('#supersedeHistoryBody tr')) {
        lockSupersedeFinderInputs(true);
        return;
    }
    const requestId = document.getElementById('requestId')?.value;
    const fromNo = (document.getElementById('revisedFromDocNo')?.value || document.getElementById('masterlistDocNo')?.value || '').trim();
    if (!requestId || !fromNo) return;
    let revisions = [];
    try {
        const params = new URLSearchParams({ request_id: String(requestId), doc_no: fromNo });
        const raw = await fetch('/dcs/api/documents/revisions?' + params.toString()).then(r => r.json());
        if (Array.isArray(raw)) revisions = raw;
    } catch (e) {
        revisions = [];
    }
    const selfRow = revisions.find(function (row) {
        return String(row.request_id || '') === String(requestId);
    });
    const previous = revisions.filter(function (row) {
        return String(row.request_id || '') !== String(requestId);
    });
    const source = previous.find(function (row) {
        return revisionStatusText(row.revision_status) === 'Superseded';
    }) || previous[0] || null;
    if (source) {
        const no = document.getElementById('supersedeDocNo');
        const title = document.getElementById('supersedeDocTitle');
        const origin = document.getElementById('supersedeDocOriginator');
        if (no && !no.value) no.value = source.doc_no || fromNo;
        if (title && !title.value) title.value = source.doc_title || '';
        if (origin && !origin.value) origin.value = source.originator_name || '';
        const picked = document.getElementById('supersedeDocPicked');
        if (picked) {
            picked.hidden = false;
            var sourceStatus = revisionStatusText(source.revision_status);
            picked.textContent = (source.doc_no || fromNo) + ' — ' + (source.doc_title || 'Untitled')
                + ' (Rev ' + (source.revise_no ?? 0) + ', ' + sourceStatus + ') '
                + (sourceStatus === 'Latest'
                    ? 'stays the current copy until this recurring document is saved.'
                    : 'was superseded.');
            if (typeof syncSupersedeSelectedBar === 'function') syncSupersedeSelectedBar();
        }
    }
    await showSupersedeDocumentHistory(selfRow || {
        request_id: requestId,
        doc_no: fromNo,
        masterlist_id: source ? source.masterlist_id : '',
    }, {
        revisions: revisions,
        keepMasterlistId: true,
    });
    lockSupersedeFinderInputs(true);
}

/**
 * Binds and registers supersede finder event listeners.
 */
function bindSupersedeFinder() {
    const noInput = document.getElementById('supersedeDocNo');
    const titleInput = document.getElementById('supersedeDocTitle');
    const originInput = document.getElementById('supersedeDocOriginator');
    const dd = document.getElementById('supersedeDocResults');
    if (!noInput || !titleInput || !originInput || !dd || noInput.dataset.bound === '1') return;
    noInput.dataset.bound = '1';
    let timer = null;

    /**
     * Runs search.
     *
     * @param {*} activeValue
     */
    async function runSearch(activeValue) {
        if (noInput.readOnly || window.__recurSelectionLocked || window.__recurFromDatabase) {
            dd.style.display = 'none';
            dd.innerHTML = '';
            return;
        }
        const parts = supersedeFinderParts();
        const q = (activeValue || parts.no || parts.title || parts.originator).trim();
        if (q.length < 1) {
            dd.style.display = 'none';
            return;
        }
        const docTypeId = document.getElementById('docType')?.value;
        const subTypeId = document.getElementById('subType')?.value;
        if (!docTypeId) {
            dd.innerHTML = '<div class="reg-reldocs-noresult">Select the document type first</div>';
            dd.style.display = 'block';
            return;
        }
        dd.innerHTML = '<div class="reg-reldocs-noresult">Searching…</div>';
        dd.style.display = 'block';
        try {
            const params = new URLSearchParams({
                q,
                field: 'supersede',
                doc_type_id: docTypeId,
                for_revision: '1',
            });
            if (subTypeId) params.set('sub_type_id', subTypeId);
            const raw = await fetch('/dcs/api/documents/search?' + params.toString()).then(r => r.json());
            if (noInput.readOnly || window.__recurSelectionLocked) {
                dd.style.display = 'none';
                return;
            }
            const data = (Array.isArray(raw) ? raw : []).filter(function (doc) {
                const status = String(doc.revision_status || 'latest').toLowerCase();
                return (status === 'latest' || status === '') && supersedeDocMatches(doc, parts);
            });
            window.__supersedeSearch = data;
            dd.innerHTML = data.length
                ? data.map(function (doc, i) { return supersedeResultHtml(doc, i); }).join('')
                : '<div class="reg-reldocs-noresult">No registered latest document matches that number, title, and originator</div>';
        } catch (e) {
            dd.innerHTML = '<div class="reg-reldocs-noresult">Search failed</div>';
        }
    }

    [noInput, titleInput, originInput].forEach(function (input) {
        input.addEventListener('input', function () {
            if (input.readOnly || window.__recurSelectionLocked || window.__recurFromDatabase) {
                clearTimeout(timer);
                dd.style.display = 'none';
                return;
            }
            clearTimeout(timer);
            timer = setTimeout(function () { runSearch(input.value.trim()); }, 250);
        });
        input.addEventListener('focus', function () {
            if (input.readOnly || window.__recurSelectionLocked || window.__recurFromDatabase) {
                dd.style.display = 'none';
            }
        });
    });
    dd.addEventListener('click', async function (e) {
        const btn = e.target.closest('[data-supersede-idx]');
        if (!btn) return;
        const doc = (window.__supersedeSearch || [])[Number(btn.dataset.supersedeIdx)];
        if (!doc) return;
        const row = document.querySelector('#revisionTableBody tr');
        if (row) {
            const no = row.querySelector('input[name="documentNo[]"]');
            const title = row.querySelector('input[name="documentTitle[]"]');
            const rev = row.querySelector('input[name="revisionNo[]"]');
            if (no) no.value = doc.doc_no || '';
            if (title) title.value = doc.doc_title || '';
            if (rev) rev.value = doc.revise_no ?? 0;
            row.dataset.linked = 'true';
            row.dataset.linkedDocNo = doc.doc_no || '';
        }
        const fromInput = document.getElementById('revisedFromDocNo');
        if (fromInput) fromInput.value = doc.doc_no || '';
        const idInput = document.getElementById('supersedeMasterlistId');
        if (idInput) idInput.value = doc.masterlist_id ? String(doc.masterlist_id) : '';
        const mlRev = document.getElementById('masterlistRevisionNo');
        if (mlRev) {
            mlRev.dataset.recurRev = String(doc.revise_no ?? 0);
            mlRev.value = mlRev.dataset.recurRev;
            mlRev.readOnly = true;
            mlRev.classList.add('reg-rev-locked');
            mlRev.style.background = '#f1f5f9';
            mlRev.style.borderColor = '';
            mlRev.classList.remove('reg-input-invalid');
        }
        noInput.value = doc.doc_no || '';
        titleInput.value = doc.doc_title || '';
        originInput.value = doc.originator_name || '';
        const picked = document.getElementById('supersedeDocPicked');
        if (picked) {
            const originLabel = (doc.originator_name || '').trim() || 'No originator';
            picked.hidden = false;
            picked.textContent = (doc.doc_no || '') + ' — ' + (doc.doc_title || 'Untitled')
                + ' · ' + originLabel
                + ' (Rev ' + (doc.revise_no ?? 0) + ') will be superseded.';
            if (typeof syncSupersedeSelectedBar === 'function') syncSupersedeSelectedBar();
        }
        if (typeof applyRecurPickToMasterlist === 'function') {
            await applyRecurPickToMasterlist(doc);
        }
        await showSupersedeDocumentHistory(doc);
        window.__recurSelectionLocked = true;
        lockSupersedeFinderInputs(true);
        dd.style.display = 'none';
    });
    const historyBody = document.getElementById('supersedeHistoryBody');
    if (historyBody && historyBody.dataset.bound !== '1') {
        historyBody.dataset.bound = '1';
        historyBody.addEventListener('click', function (e) {
            const scan = e.target.closest('[data-scan-url]');
            if (!scan) return;
            const url = scan.getAttribute('data-scan-url');
            if (url) window.open(url, '_blank', 'noopener');
        });
    }
    const reselect = document.getElementById('supersedeReselect');
    if (reselect && reselect.dataset.bound !== '1') {
        reselect.dataset.bound = '1';
        reselect.addEventListener('click', clearSupersedeSelection);
    }
    if (window.__recurFromDatabase || window.__recurSelectionLocked
        || document.querySelector('#supersedeHistoryBody tr')
        || (document.getElementById('revisedFromDocNo')?.value || '').trim()) {
        window.__recurSelectionLocked = true;
        lockSupersedeFinderInputs(true);
    }
}

/**
 * Checks whether dist offices locked from drf condition is met.
 */
function isDistOfficesLockedFromDrf() {
    return !!window.__distOfficesLockedFromRecur && !window.__seedingDrfDistOffices;
}

/**
 * Locks dist offices from recur.
 */
function lockDistOfficesFromRecur() {
    window.__distOfficesLockedFromRecur = true;
    window.__distOfficesLockMessage = 'Distribution offices are the same as the previous document and cannot be added.';
    const search = document.getElementById('distSearch');
    if (search) {
        search.disabled = true;
        search.value = '';
        search.placeholder = 'Offices locked from the previous document';
        const wrap = search.closest('.reg-search');
        if (wrap) wrap.style.display = 'none';
    }
    const chips = document.getElementById('distClusterChips');
    if (chips) chips.style.display = 'none';
    const groups = document.getElementById('distOfficeGroups');
    if (groups) groups.style.display = 'none';
    let hint = document.getElementById('distOfficesLockedHint');
    if (!hint && search) {
        hint = document.createElement('p');
        hint.id = 'distOfficesLockedHint';
        hint.className = 'reg-field-hint';
        search.closest('.reg-field')?.insertBefore(hint, search.closest('.reg-search') || search);
    }
    if (hint) {
        hint.hidden = false;
        hint.textContent = window.__distOfficesLockMessage;
    }
    document.querySelectorAll('#distBody tr.reg-office-added .btn-remove').forEach((btn) => btn.remove());
}

/**
 * Displays the checklist section overlay or dialog.
 *
 * @param {*} id
 */
function openChecklistSection(id) {
    const cb = document.querySelector('#dynamicCheckboxes input[name="checklists[]"][value="' + id + '"]');
    const section = document.getElementById('section-' + id);
    if (cb) {
        cb.checked = true;
        cb.dataset.lastChecked = 'true';
        if (!cb.disabled && typeof toggleSection === 'function') {
            toggleSection(id, true);
            return;
        }
    }
    if (section) section.style.display = 'block';
}

/**
 * Seed revised distribution offices.
 *
 * @param {*} offices
 * @param {*} bodyId
 * @param {*} totalId
 */
function seedRevisedDistributionOffices(offices, bodyId, totalId) {
    const list = Array.isArray(offices) ? offices : [];
    if (!list.length || !tableIsEmpty(bodyId || 'distBody')) return;
    window.__seedingDrfDistOffices = true;
    try {
        list.forEach((o) => {
            if (!o || !o.office_id) return;
            seedOfficeRow(bodyId || 'distBody', totalId || 'distTotal', o.office_id, o.office_name || 'Office', o.copies || 1);
        });
        if (typeof updateTotal === 'function') updateTotal(totalId || 'distTotal', bodyId || 'distBody');
        if (typeof refreshOfficeSeeMore === 'function') refreshOfficeSeeMore(bodyId || 'distBody');
    } finally {
        window.__seedingDrfDistOffices = false;
    }
    openChecklistSection(5);
}

/**
 * Seed recur distribution offices.
 *
 * @param {*} offices
 */
function seedRecurDistributionOffices(offices) {
    const list = Array.isArray(offices) ? offices : [];
    window.__seedingDrfDistOffices = true;
    window.__distOfficesLockedFromRecur = false;
    try {
        const tbody = document.getElementById('distBody');
        if (tbody) {
            tbody.querySelectorAll('tr.reg-office-added').forEach((tr) => tr.remove());
            if (!tbody.querySelector('tr')) {
                tbody.innerHTML = emptyOfficeRowHTML('distBody');
            }
        }
        list.forEach((o) => {
            if (!o || !o.office_id) return;
            seedOfficeRow('distBody', 'distTotal', o.office_id, o.office_name || 'Office', o.copies || 1);
        });
        if (typeof updateTotal === 'function') updateTotal('distTotal', 'distBody');
        if (typeof refreshOfficeSeeMore === 'function') refreshOfficeSeeMore('distBody');
    } finally {
        window.__seedingDrfDistOffices = false;
    }
    lockDistOfficesFromRecur();
}

/**
 * Applies revision mode.
 */
function applyRevisionMode() {
    syncRecurFinder();
    const revField = document.getElementById('masterlistRevisionNo');
    const hintEl = document.getElementById('docNoHint');

    if (versionLooksRecurring()) {
        if (revField) {
            if (!revField.dataset.recurRev && String(revField.value || '').trim() !== '') {
                revField.dataset.recurRev = String(revField.value);
            }
            revField.readOnly = true;
            revField.classList.add('reg-rev-locked');
            revField.style.background = '#f1f5f9';
            revField.style.cursor = 'default';
            revField.style.borderColor = '';
        }
        if (hintEl) {
            hintEl.innerHTML = '';
            hintEl.style.color = '';
            hintEl.dataset.valid = '';
        }
        lockDistOfficesFromRecur();
        return;
    }

    if (isRevisedMode()) {
        if (revField) {
            revField.dataset.userEdited = '';
            revField.readOnly = false;
            revField.style.background = '';
            revField.style.cursor = '';
            revField.removeAttribute('min');
            revField.removeAttribute('title');
        }
        if (hintEl) {
            hintEl.innerHTML = '';
            hintEl.style.color = '';
            hintEl.dataset.valid = '';
        }
    } else {
        docNoDuplicate = false;
        if (revField) {
            revField.readOnly = false;
            revField.style.background = '';
            revField.style.cursor = '';
        }
        if (hintEl) { hintEl.innerHTML = ''; hintEl.dataset.valid = ''; }
    }
}

/**
 * Renders and synchronizes registration mode in the DOM.
 */
function updateRegistrationMode() {
    const sel = document.getElementById('versionType');
    const hidden = document.getElementById('registrationMode');
    if (!sel || !hidden) return;
    const text = sel.options[sel.selectedIndex]?.text?.toLowerCase() || '';
    if (text.includes('recur')) {
        hidden.value = 'recurring';
    } else if (text.includes('revised') || text.includes('revision') || text.includes('revise')) {
        hidden.value = 'revised';
    } else {
        hidden.value = 'new';
    }
    applyRevisionMode();
    if (hidden.value === 'recurring') {
        const revField = document.getElementById('masterlistRevisionNo');
        if (revField) {
            revField.readOnly = true;
            revField.style.background = '#f1f5f9';
        }
        sel.disabled = true;
    }
}

// ══════════════════════════════════════════════
// DCN — REVISION TABLE: DOCUMENT SEARCH + AUTOFILL
// ══════════════════════════════════════════════
function getOrCreateRevSearchDropdown(key) {
    let dd = document.getElementById('revSearchDropdown_' + key);
    if (!dd) {
        dd = document.createElement('div');
        dd.id = 'revSearchDropdown_' + key;
        dd.className = 'reg-reldocs-dropdown';
        dd.style.display = 'none';
        document.body.appendChild(dd);
    }
    return dd;
}

/**
 * Closes or dismisses the rev search dropdown.
 *
 * @param {*} key
 */
function closeRevSearchDropdown(key) {
    const dd = document.getElementById('revSearchDropdown_' + key);
    if (dd) dd.style.display = 'none';
}

/**
 * Removes specified rev search dropdown.
 *
 * @param {*} key
 */
function removeRevSearchDropdown(key) {
    const dd = document.getElementById('revSearchDropdown_' + key);
    if (dd) dd.remove();
}

/**
 * Takens revision doc nos.
 *
 * @param {*} exceptInput
 */
function takenRevisionDocNos(exceptInput) {
    const taken = new Set();
    document.querySelectorAll('#revisionTableBody tr').forEach(function (tr) {
        const input = tr.querySelector('input[name="documentNo[]"]');
        if (!input || input === exceptInput) return;
        const id = String(tr.dataset.linkedMasterlist || '').trim();
        const no = String(input.value || '').trim().toLowerCase();
        const title = String(tr.querySelector('input[name="documentTitle[]"]')?.value || '').trim().toLowerCase();
        if (id) taken.add('id:' + id);
        if (no && title) taken.add('nt:' + no + '|' + title);
    });
    return taken;
}

/**
 * Revisions doc is taken.
 *
 * @param {*} doc
 * @param {*} taken
 */
function revisionDocIsTaken(doc, taken) {
    const id = String(doc?.masterlist_id || '').trim();
    const no = String(doc?.doc_no || '').trim().toLowerCase();
    const title = String(doc?.doc_title || '').trim().toLowerCase();
    if (id && taken.has('id:' + id)) return true;
    if (no && title && taken.has('nt:' + no + '|' + title)) return true;
    return false;
}

/**
 * Handles revision search input event or response.
 *
 * @param {*} input
 * @param {*} key
 * @param {*} field
 */
function handleRevisionSearchInput(input, key, field) {
    if (input.readOnly) return;
    clearTimeout(revSearchTimers[key]);
    const dd = getOrCreateRevSearchDropdown(key);
    const q = input.value.trim();
    if (q.length < 1) { dd.style.display = 'none'; return; }

    const docTypeId = document.getElementById('docType')?.value || CURRENT_DOC_TYPE_ID;
    const subTypeId = document.getElementById('subType')?.value || CURRENT_SUB_TYPE_ID;
    const hasChildren = allDocTypes.some(d => String(d.parent_id) === String(docTypeId));

    if (!docTypeId) {
        dd.innerHTML = '<div class="reg-reldocs-noresult">Select a Document Type first</div>';
        positionFixedDropdown(dd, input);
        dd.style.display = 'block';
        return;
    }
    if (hasChildren && !subTypeId) {
        dd.innerHTML = '<div class="reg-reldocs-noresult">Select a Sub-Type first</div>';
        positionFixedDropdown(dd, input);
        dd.style.display = 'block';
        return;
    }

    dd.innerHTML = '<div class="reg-reldocs-noresult">Searching…</div>';
    positionFixedDropdown(dd, input);
    dd.style.display = 'block';

    revSearchTimers[key] = setTimeout(async () => {
        try {
            const params = new URLSearchParams({
                q,
                field: field || '',
                doc_type_id: docTypeId,
                for_revision: '1',
            });
            if (subTypeId) params.set('sub_type_id', subTypeId);
            const excludeId = document.getElementById('requestId')?.value;
            if (excludeId) params.set('exclude_request_id', excludeId);

            const raw = await fetch('/dcs/api/documents/search?' + params.toString()).then(r => r.json());
            const taken = takenRevisionDocNos(input);
            const data = (Array.isArray(raw) ? raw : []).filter(function (doc) {
                return !revisionDocIsTaken(doc, taken);
            });
            revSearchCache[key] = data;

            dd.innerHTML = data.length === 0
                ? '<div class="reg-reldocs-noresult">' + (taken.size && Array.isArray(raw) && raw.length
                    ? 'That document is already in Documents for Revision.'
                    : 'No matching documents. Save the New registration first, then search the full Document No.') + '</div>'
                : data.map((d, idx) => `<div onmousedown="pickRevisionDocument('${key}', ${idx})">${escapeHtml(d.label)}</div>`).join('');

            positionFixedDropdown(dd, input);
            dd.style.display = 'block';
        } catch (e) { console.error('Revision doc search failed:', e); }
    }, 300);
}

/**
 * Populates revision row from doc in the interface.
 *
 * @param {*} row
 * @param {*} doc
 */
function populateRevisionRowFromDoc(row, doc) {
    if (!row || !doc) return;
    const titleInput   = row.querySelector('input[name="documentTitle[]"]');
    const noInput      = row.querySelector('input[name="documentNo[]"]');
    const effField     = row.querySelector('input[name="effectiveDate[]"]');
    const revField     = row.querySelector('input[name="revisionNo[]"]');
    const pathInput    = row.querySelector('input[name="revisionScannedPath[]"]');
    const purposeField = row.querySelector('input[name="revisionPurpose[]"]');
    const purposeText  = row.querySelector('.reg-rev-purpose-text');

    if (titleInput) titleInput.value = doc.doc_title || '';
    if (noInput) noInput.value = doc.doc_no || '';
    row.dataset.revisionStatus = String(doc.revision_status || '').toLowerCase();
    if (effField) effField.value = doc.effectivity_date || '';
    if (revField) revField.value = (doc.revise_no !== null && doc.revise_no !== undefined) ? doc.revise_no : '';
    if (pathInput) pathInput.value = doc.scanned_copy_path || '';

    // Rev 0 has no DCN → no Brief Purpose. Later revs use DCN justification only.
    const reviseNo = Number(doc.revise_no ?? 0);
    const purpose = reviseNo > 0 ? String(doc.brief_purpose || '').trim() : '';
    if (purposeField) purposeField.value = purpose;
    if (purposeText) {
        purposeText.textContent = purpose || '—';
        purposeText.classList.toggle('is-wrap', purpose.length > 42);
    }
    const titleText = row.querySelector('.reg-rev-title-text');
    if (titleText) {
        const title = String(doc.doc_title || '').trim();
        titleText.textContent = title || '—';
        titleText.classList.toggle('is-wrap', title.length > 28);
    }

    lockRevisionRowFields(row);
    lockRevisionScannedCopyCell(row, doc.scanned_copy_url);
}

/**
 * Retrieves and returns revised from doc no.
 */
function getRevisedFromDocNo() {
    return (window.__revisedFromDocNo || '').trim();
}

/**
 * Clears and resets linked rows for document.
 *
 * @param {*} tbody
 * @param {*} docNo
 * @param {*} exceptRow
 * @param {*} familyKey
 */
function clearLinkedRowsForDocument(tbody, docNo, exceptRow, familyKey) {
    if (!tbody || !docNo) return;
    [...tbody.querySelectorAll('tr')].forEach(tr => {
        if (tr === exceptRow) return;
        const sameFamily = familyKey && (tr.dataset.linkedFamily || '') === familyKey;
        const sameNumber = !familyKey && (tr.dataset.linkedDocNo || '') === docNo;
        if (!sameFamily && !sameNumber) return;
        removeRevisionRowDropdowns(tr);
        tr.remove();
    });
}

/**
 * Locks revision history row.
 *
 * @param {*} tr
 */
function lockRevisionHistoryRow(tr) {
    if (!tr) return;
    tr.dataset.historyLocked = 'true';
    const btn = tr.querySelector('.reg-row-del');
    if (btn) btn.remove();
}

/**
 * Others linked revision documents.
 *
 * @param {*} exceptDocNo
 */
function otherLinkedRevisionDocuments(exceptDocNo) {
    const except = String(exceptDocNo || '').trim().toLowerCase();
    let found = false;
    document.querySelectorAll('#revisionTableBody tr').forEach(tr => {
        const no = (tr.dataset.linkedDocNo || '').trim().toLowerCase();
        if (no && no !== except) found = true;
    });
    return found;
}

/**
 * Revisions history rows for dcn.
 *
 * @param {*} docs
 * @param {*} latestRev
 */
function revisionHistoryRowsForDcn(docs, latestRev) {
    const list = Array.isArray(docs) ? docs.slice() : [];
    const cap = latestRev !== null && latestRev !== undefined && latestRev !== ''
        ? Number(latestRev)
        : NaN;
    let rows = Number.isFinite(cap)
        ? list.filter((d) => Number(d?.revise_no) <= cap)
        : list.slice();
    if (!rows.length && list.length) {
        const first = { ...list[0] };
        if (Number.isFinite(cap)) first.revise_no = cap;
        rows = [first];
    }
    return rows;
}

/**
 * Populates revision table with document history in the interface.
 *
 * @param {*} anchorRow
 * @param {*} docs
 * @param {*} options
 */
async function fillRevisionTableWithDocumentHistory(anchorRow, docs, options = {}) {
    const tbody = document.getElementById('revisionTableBody');
    if (!tbody || !anchorRow || !docs.length) return;

    const pickedDocNo = String((options.docNo || docs[0].doc_no) || '').trim().toLowerCase();
    const rowsToFill = revisionHistoryRowsForDcn(docs, options.latestRev);
    const familyKey = String(rowsToFill[0].masterlist_id || rowsToFill[0].request_id || pickedDocNo);

    clearLinkedRowsForDocument(tbody, pickedDocNo, anchorRow, familyKey);

    populateRevisionRowFromDoc(anchorRow, rowsToFill[0]);
    anchorRow.dataset.linked = 'true';
    anchorRow.dataset.linkedDocNo = pickedDocNo;
    anchorRow.dataset.linkedFamily = familyKey;
    anchorRow.dataset.linkedMasterlist = String(rowsToFill[0].masterlist_id || '');
    delete anchorRow.dataset.historyLocked;

    let insertAfter = anchorRow;
    for (let i = 1; i < rowsToFill.length; i++) {
        const tr = document.createElement('tr');
        tr.innerHTML = revisionRowCellsHTML();
        insertAfter.after(tr);
        bindRevisionRowSearch(tr);
        populateRevisionRowFromDoc(tr, rowsToFill[i]);
        tr.dataset.linked = 'true';
        tr.dataset.linkedDocNo = pickedDocNo;
        tr.dataset.linkedFamily = familyKey;
        tr.dataset.linkedMasterlist = String(rowsToFill[i].masterlist_id || '');
        lockRevisionHistoryRow(tr);
        insertAfter = tr;
    }
    if (typeof suggestRevisionForSources === 'function') suggestRevisionForSources();
}

/**
 * Picks revision document.
 *
 * @param {*} key
 * @param {*} idx
 */
window.pickRevisionDocument = async function (key, idx) {
    const doc = (revSearchCache[key] || [])[idx];
    if (!doc) return;
    const uid = key.split('_')[0];
    const row = document.querySelector(`#revisionTableBody tr[data-uid="${uid}"]`);
    if (!row) return;

    closeRevSearchDropdown(key);

    // Immediate feedback so the pick doesn't look empty while history loads.
    populateRevisionRowFromDoc(row, doc);
    setRevisionTableLoading(true);

    const pickedNo = String(doc.doc_no || '').trim().toLowerCase();
    const revisionPromise = doc.request_id
        ? fetch('/dcs/api/documents/revisions?' + new URLSearchParams({
            request_id: String(doc.request_id),
            ...(doc.doc_no ? { doc_no: doc.doc_no } : {}),
        }).toString()).then((r) => r.json()).catch((e) => {
            console.error('Failed to load document revisions:', e);
            return [];
        })
        : Promise.resolve([]);
    const checkPromise = isDcnSectionVisible()
        ? (doc.doc_no
            ? bridgeDcnPickToMasterlist(doc.doc_no)
            : applyRecurPickToMasterlist(doc))
        : Promise.resolve(null);

    try {
        const [revisionPayload, checkData] = await Promise.all([revisionPromise, checkPromise]);
        let revisions = Array.isArray(revisionPayload) ? revisionPayload : [];
        if (!revisions.length) revisions = [doc];
        const latestRev = checkData?.latest_rev ?? doc.revise_no;
        await fillRevisionTableWithDocumentHistory(row, revisions, { docNo: pickedNo, latestRev });
    } finally {
        setRevisionTableLoading(false);
    }
};

/**
 * Sets the revision table loading value.
 *
 * @param {*} on
 */
function setRevisionTableLoading(on) {
    const field = document.getElementById('revisionTableField')
        || document.querySelector('#section-2 .reg-revision-table');
    const overlay = document.getElementById('revisionTableLoading');
    if (field) field.classList.toggle('is-loading', !!on);
    if (overlay) overlay.setAttribute('aria-busy', on ? 'true' : 'false');
}

/**
 * Locks revision populated field.
 *
 * @param {*} el
 */
function lockRevisionPopulatedField(el) {
    if (!el) return;
    el.readOnly = true;
    el.classList.add('reg-revrow-locked');
    el.style.background = '#f8fafc';
    el.style.cursor = 'not-allowed';
    el.style.color = '#475569';
}

/** Show full title as wrapping text (no horizontal scroll) while keeping form value. */
function lockRevisionTitleAsWrap(row) {
    const input = row.querySelector('input[name="documentTitle[]"]');
    if (!input || input.type === 'hidden') return;
    const cell = input.closest('td');
    if (!cell) return;

    const title = String(input.value || '').trim();
    input.type = 'hidden';
    input.value = title;
    input.classList.remove('reg-revrow-locked');
    input.removeAttribute('style');

    cell.classList.add('reg-rev-title-cell');
    let span = cell.querySelector('.reg-rev-title-text');
    if (!span) {
        span = document.createElement('span');
        span.className = 'reg-rev-title-text';
        cell.appendChild(span);
    }
    span.textContent = title || '—';
    span.classList.toggle('is-wrap', title.length > 28);
}

/**
 * Locks revision row fields.
 *
 * @param {*} row
 */
function lockRevisionRowFields(row) {
    row.dataset.linked = "true";
    lockRevisionTitleAsWrap(row);
    row.querySelectorAll(
        'input[name="documentNo[]"], input[name="effectiveDate[]"], input[name="revisionNo[]"], input[name="revisionPurpose[]"]'
    ).forEach((el) => {
        if (el.type !== 'hidden') lockRevisionPopulatedField(el);
    });
}

/**
 * Checks whether dcs scan pdf url condition is met.
 *
 * @param {*} urlOrPath
 */
function isDcsScanPdfUrl(urlOrPath) {
    const s = String(urlOrPath || '');
    if (!s) return true;
    try {
        const u = new URL(s, window.location.origin);
        const pathParam = (u.searchParams.get('path') || '').toLowerCase();
        if (pathParam.endsWith('.pdf') || pathParam.includes('.pdf')) return true;
    } catch (_) { /* relative / plain path */ }
    return /\.pdf(?:$|[?#&])/i.test(s) || s.toLowerCase().includes('.pdf');
}

/**
 * Locks revision scanned copy cell.
 *
 * @param {*} row
 * @param {*} scannedCopyUrl
 */
function lockRevisionScannedCopyCell(row, scannedCopyUrl) {
    const cell = row.querySelector('.reg-rev-scan-cell');
    if (!cell) return;

    if (scannedCopyUrl) {
        // DCS revision scanned copies are PDF-only — always use the red PDF icon.
        cell.style.textAlign = 'center';
        cell.innerHTML = `<button type="button" class="reg-revrow-viewfile reg-revrow-viewfile-pdf" onclick="window.open('${scannedCopyUrl}', '_blank')" title="View PDF"><i class="fa-solid fa-file-pdf"></i></button>`;
    } else {
        cell.style.textAlign = 'center';
        cell.innerHTML = `<div class="reg-file-error" style="margin:0;"><i class="fa-solid fa-circle-exclamation"></i> No scanned copy on file</div>`;
    }
}

/**
 * Binds and registers revision search input event listeners.
 *
 * @param {*} input
 * @param {*} uid
 * @param {*} field
 */
function bindRevisionSearchInput(input, uid, field) {
    if (!input || input.dataset.searchBound) return;
    input.dataset.searchBound = 'true';
    const key = uid + '_' + field;
    if (!input.id) input.id = 'revSearchInput_' + key;

    input.addEventListener('input', () => handleRevisionSearchInput(input, key, field));
    input.addEventListener('focus', () => {
        if (!input.readOnly && input.value.trim().length >= 1) handleRevisionSearchInput(input, key, field);
    });

    const reposition = () => {
        const dd = document.getElementById('revSearchDropdown_' + key);
        if (dd && dd.style.display === 'block') positionFixedDropdown(dd, input);
    };
    window.addEventListener('scroll', reposition, true);
    window.addEventListener('resize', reposition);
}

/**
 * Binds and registers revision row search event listeners.
 *
 * @param {*} tr
 */
function bindRevisionRowSearch(tr) {
    if (!tr.dataset.uid) tr.dataset.uid = ++revisionRowUidCounter;
    const uid = tr.dataset.uid;
    bindRevisionSearchInput(tr.querySelector('input[name="documentNo[]"]'), uid, 'no');
    bindRevisionSearchInput(tr.querySelector('input[name="documentTitle[]"]'), uid, 'title');
    if (tr.dataset.linked === 'true') lockRevisionRowFields(tr);
}

/**
 * Removes specified revision row dropdowns.
 *
 * @param {*} tr
 */
function removeRevisionRowDropdowns(tr) {
    if (!tr.dataset.uid) return;
    removeRevSearchDropdown(tr.dataset.uid + '_title');
    removeRevSearchDropdown(tr.dataset.uid + '_no');
}

/**
 * Suggests revision for sources.
 */
function suggestRevisionForSources() {
    const revField = document.getElementById('masterlistRevisionNo');
    const master = (document.getElementById('masterlistDocNo')?.value || '').trim().toLowerCase();
    if (!revField || !master || !isRevisedMode()) return;
    const byDoc = {};
    document.querySelectorAll('#revisionTableBody tr').forEach(tr => {
        const no = (tr.querySelector('input[name="documentNo[]"]')?.value || '').trim();
        if (!no) return;
        const rev = parseInt(tr.querySelector('input[name="revisionNo[]"]')?.value || '0', 10) || 0;
        const key = no.toLowerCase();
        if (!Object.prototype.hasOwnProperty.call(byDoc, key) || rev > byDoc[key]) byDoc[key] = rev;
    });
    if (typeof isRecurringMode === 'function' && isRecurringMode()) {
        if (Object.prototype.hasOwnProperty.call(byDoc, master)) {
            revField.value = String(byDoc[master]);
            revField.readOnly = true;
        }
        return;
    }
    const userPinned = revField.dataset.userEdited === 'true' && String(revField.value || '').trim() !== '';
    if (!userPinned) {
        revField.value = Object.prototype.hasOwnProperty.call(byDoc, master) ? String(byDoc[master] + 1) : '0';
    }
}

/**
 * Removes specified revision row.
 *
 * @param {*} btn
 */
window.removeRevisionRow = function (btn) {
    const tr = btn.closest('tr');
    if (!tr || tr.dataset.historyLocked === 'true' || tr.dataset.saved === '1') return;
    const family = tr.dataset.linkedFamily || '';
    const docNo = tr.dataset.linkedDocNo || '';
    const tbody = tr.parentElement;
    if (tbody && (family || docNo)) {
        [...tbody.querySelectorAll('tr')].forEach(row => {
            if (row.dataset.saved === '1') return;
            if (family) {
                if ((row.dataset.linkedFamily || '') !== family) return;
            } else if ((row.dataset.linkedDocNo || '') !== docNo) {
                return;
            }
            removeRevisionRowDropdowns(row);
            row.remove();
        });
        return;
    }
    removeRevisionRowDropdowns(tr);
    tr.remove();
};

document.addEventListener('click', function (e) {
    document.querySelectorAll('[id^="revSearchDropdown_"]').forEach(dd => {
        const key = dd.id.replace('revSearchDropdown_', '');
        const input = document.getElementById('revSearchInput_' + key);
        if (dd.style.display === 'block' && !dd.contains(e.target) && e.target !== input) {
            dd.style.display = 'none';
        }
    });
});

// ══════════════════════════════════════════════
// SHARED SOURCE UNIT WIDGET FACTORY
// ══════════════════════════════════════════════
window.__sourceWidgets = {};
window.__syncingSourceUnits = false;

const SYNCED_SOURCE_UNIT_KEYS = ['drf', 'dcn', 'masterlist'];

/**
 * Checks whether synced source unit key condition is met.
 *
 * @param {*} key
 */
function isSyncedSourceUnitKey(key) {
    return SYNCED_SOURCE_UNIT_KEYS.includes(key);
}

/**
 * Sources section is open.
 *
 * @param {*} key
 */
function sourceSectionIsOpen(key) {
    const ids = { drf: 'section-1', dcn: 'section-2', masterlist: 'section-3' };
    const el = document.getElementById(ids[key] || '');
    return !!el && el.style.display !== 'none';
}

/**
 * Renders and synchronizes source units across sections in the DOM.
 *
 * @param {*} selection
 * @param {*} sourceKey
 */
function syncSourceUnitsAcrossSections(selection, sourceKey) {
    if (window.__syncingSourceUnits || !isSyncedSourceUnitKey(sourceKey)) return;

    window.__syncingSourceUnits = true;
    try {
        const items = selection.map(i => ({ type: i.type, id: i.id, label: i.label }));
        SYNCED_SOURCE_UNIT_KEYS.forEach(key => {
            if (key === sourceKey) return;
            if (!sourceSectionIsOpen(key)) return;
            const widget = window.__sourceWidgets[key];
            if (widget?.setSelectedItems) widget.setSelectedItems(items);
        });
    } finally {
        window.__syncingSourceUnits = false;
    }
}

/**
 * Creates source unit widget.
 *
 * @param {*} opts
 */
function createSourceUnitWidget(opts) {
    let selected = opts.initial || [];
    let idCounter = 0;
    let panelOpen = false;
    let scrollResizeHandler = null;

    const idKey = opts.idKey || 'office_id';
    const labelKey = opts.labelKey || 'office_name';
    const getList = opts.dataListGetter || (() => allOffices);
    const itemLabelPlural = opts.itemLabelPlural || 'offices';

    /**
     * Checks whether office selected condition is met.
     *
     * @param {*} itemId
     */
    function isOfficeSelected(itemId) {
        return selected.some(i => i.type === 'office' && String(i.id) === String(itemId));
    }

    /**
     * Adds office.
     *
     * @param {*} itemId
     */
    function addOffice(itemId) {
        const item = getList().find(o => o[idKey] == itemId);
        if (!item) return;
        // ── FIX: reject offices with falsy or zero IDs
        if (!item[idKey] && item[idKey] !== 0) return;
        if (String(item[idKey]) === '0' || String(item[idKey]) === '0') return;
        if (opts.singleSelect) selected = [];
        else if (isOfficeSelected(itemId)) return;
        selected.push({ type: 'office', id: item[idKey], label: item[labelKey] });
        render();
        maybeSyncSourceUnits();
        markSourceEdited();
    }

    /**
     * Adds free text.
     *
     * @param {*} val
     */
    function addFreeText(val) {
        if (!opts.allowFreeText || !val) return;
        if (opts.singleSelect) selected = [];
        else if (selected.some(i => i.label.toLowerCase() === val.toLowerCase())) return;
        idCounter++;
        selected.push({ type: 'name', id: 'n' + idCounter, label: val });
        render();
        maybeSyncSourceUnits();
        markSourceEdited();
    }

    /**
     * Marks source edited.
     */
    function markSourceEdited() {
        if (window.__syncingSourceUnits) return;
        if (typeof window.__regEditMarkDirty === 'function') window.__regEditMarkDirty();
    }

    /**
     * Removes specified item.
     *
     * @param {*} type
     * @param {*} id
     */
    function removeItem(type, id) {
        selected = selected.filter(i => !(i.type === type && String(i.id) === String(id)));
        render();
        maybeSyncSourceUnits();
        markSourceEdited();
    }

    /**
     * Maybes sync source units.
     */
    function maybeSyncSourceUnits() {
        if (isSyncedSourceUnitKey(opts.key)) {
            syncSourceUnitsAcrossSections(selected.slice(), opts.key);
        }
    }

    /**
     * Renders and synchronizes input text in the DOM.
     */
    function syncInputText() {
        const inputEl = document.getElementById(opts.inputId);
        if (!inputEl) return;
        if (selected.length === 0) { inputEl.value = ''; return; }
        const joined = selected.map(i => i.label).join(', ');
        inputEl.value = opts.singleSelect ? joined : joined + ', ';
        const len = inputEl.value.length;
        inputEl.setSelectionRange(len, len);
    }

    /**
     * Synchronizes DOM state.
     */
    function render() {
        const widget = document.getElementById(opts.widgetId);

        // ── FIX: remove ALL hidden inputs for these field names,
        //    including stale blade-rendered ones without data-source-hidden
        const fieldNames = new Set([opts.officeFieldName, opts.nameFieldName, opts.fieldName].filter(Boolean));
        if (opts.fieldName) {
            fieldNames.add(String(opts.fieldName).endsWith('[]') ? String(opts.fieldName).slice(0, -2) : String(opts.fieldName) + '[]');
        }
        fieldNames.forEach(name => {
            widget.querySelectorAll('input[type="hidden"][name="' + name + '"]').forEach(el => el.remove());
        });

        selected.forEach(item => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.dataset.sourceHidden = 'true';
            let name = opts.fieldName || (item.type === 'office' ? opts.officeFieldName : opts.nameFieldName);
            if (opts.fieldName && name && !String(name).endsWith('[]')) name = name + '[]';
            input.name = name;
            input.value = opts.fieldName ? item.label : (item.type === 'office' ? item.id : item.label);
            widget.appendChild(input);
        });

        const chipsEl = document.getElementById(opts.chipsId);
        if (chipsEl) {
            chipsEl.innerHTML = selected.length === 0
                ? '<div class="reg-reldocs-empty">Nothing selected yet</div>'
                : selected.map(item => `
                    <div class="reg-inline-chip">
                        <span>${escapeHtml(item.label)}</span>
                        <button type="button" onclick="event.stopPropagation(); window.__sourceWidgets['${opts.key}'].removeItem('${item.type}','${item.id}')"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                `).join('');
        }

        if (window.__sourceOverlayConfigs[opts.key] && document.getElementById('universalSourceOverlay')) {
            refreshSourceOverlay(window.__sourceOverlayConfigs[opts.key]);
        }

        syncInputText();
    }

    /**
     * Retrieves and returns current query.
     *
     * @param {*} input
     */
    function getCurrentQuery(input) {
        const raw = input.value;
        const lastComma = raw.lastIndexOf(',');
        return (lastComma === -1 ? raw : raw.slice(lastComma + 1)).trim();
    }

    /**
     * Handles search event or response.
     *
     * @param {*} input
     */
    function handleSearch(input) {
        const dropdown = document.getElementById(opts.resultsId);
        const q = opts.singleSelect ? input.value.trim() : getCurrentQuery(input);
        if (q.length < 1) { dropdown.style.display = 'none'; return; }
        const filtered = filterItems(getList(), labelKey, q).filter(o => !isOfficeSelected(o[idKey]));
        if (filtered.length === 0) {
            dropdown.innerHTML = opts.allowFreeText
                ? `<div class="reg-reldocs-noresult">No matching ${itemLabelPlural} found — press Enter to add "${escapeHtml(q)}"</div>`
                : `<div class="reg-reldocs-noresult">No matching ${itemLabelPlural} found</div>`;
            dropdown.style.display = 'block';
            return;
        }
        dropdown.innerHTML = filtered.map(o => {
            const extra = o.office_code ? ' (' + o.office_code + ')' : '';
            return `<div onmousedown="window.__sourceWidgets['${opts.key}'].pick(${o[idKey]})">${escapeHtml(o[labelKey])}${extra}</div>`;
        }).join('');
        dropdown.style.display = 'block';
    }

    /**
     * Handles keydown event or response.
     *
     * @param {*} e
     * @param {*} input
     */
    function handleKeydown(e, input) {
        if (e.key !== 'Enter' && e.key !== ',') return;
        e.preventDefault();
        const q = opts.singleSelect ? input.value.trim() : getCurrentQuery(input);
        if (!q) return;

        const exactOffice = getList().find(o =>
            !isOfficeSelected(o[idKey]) && (
                o[labelKey].toLowerCase() === q.toLowerCase() ||
                (o.office_code || '').toLowerCase() === q.toLowerCase()
            )
        );
        if (exactOffice) {
            pick(exactOffice[idKey]);
            return;
        }

        if (opts.allowFreeText) {
            addFreeText(q);
            if (opts.singleSelect) {
                input.value = '';
            } else {
                const raw = input.value;
                const lastComma = raw.lastIndexOf(',');
                input.value = lastComma === -1 ? '' : raw.slice(0, lastComma + 1) + ' ';
            }
            syncInputText();
            document.getElementById(opts.resultsId).style.display = 'none';
        }
    }

    /**
     * Picks action.
     *
     * @param {*} itemId
     */
    function pick(itemId) {
        addOffice(itemId);
        const inputEl = document.getElementById(opts.inputId);
        if (inputEl) inputEl.focus();
        syncInputText();
        document.getElementById(opts.resultsId).style.display = 'none';
        if (typeof window.__regEditMarkDirty === 'function') window.__regEditMarkDirty();
    }

    /**
     * Positions panel.
     *
     * @param {*} panelEl
     * @param {*} anchorEl
     */
    function positionPanel(panelEl, anchorEl) {
        const rect = anchorEl.getBoundingClientRect();
        panelEl.style.position = 'fixed';
        panelEl.style.top = (rect.bottom + 6) + 'px';
        panelEl.style.left = rect.left + 'px';
        panelEl.style.width = rect.width + 'px';
        panelEl.style.zIndex = 9500;
    }

    /**
     * Toggles or switches panel.
     *
     * @param {*} e
     */
    function togglePanel(e) {
        if (e) e.stopPropagation();
        document.getElementById(opts.resultsId).style.display = 'none';
        panelOpen = !panelOpen;
        const chipsEl = document.getElementById(opts.chipsId);
        if (!chipsEl) return;

        if (panelOpen) {
            document.body.appendChild(chipsEl);
            positionPanel(chipsEl, document.getElementById(opts.widgetId));
            chipsEl.style.display = 'block';
            scrollResizeHandler = () => positionPanel(chipsEl, document.getElementById(opts.widgetId));
            window.addEventListener('scroll', scrollResizeHandler, true);
            window.addEventListener('resize', scrollResizeHandler);
        } else {
            chipsEl.style.display = 'none';
            if (scrollResizeHandler) {
                window.removeEventListener('scroll', scrollResizeHandler, true);
                window.removeEventListener('resize', scrollResizeHandler);
                scrollResizeHandler = null;
            }
        }
    }

    /**
     * Closes or dismisses the panel.
     */
    function closePanel() {
        panelOpen = false;
        const chipsEl = document.getElementById(opts.chipsId);
        if (chipsEl) chipsEl.style.display = 'none';
        if (scrollResizeHandler) {
            window.removeEventListener('scroll', scrollResizeHandler, true);
            window.removeEventListener('resize', scrollResizeHandler);
            scrollResizeHandler = null;
        }
    }

    /**
     * Resets state.
     */
    function reset() {
        selected = [];
        const inputEl = document.getElementById(opts.inputId);
        if (inputEl) inputEl.value = '';
        render();
        maybeSyncSourceUnits();
    }

    /**
     * Removes specified item and sync.
     *
     * @param {*} type
     * @param {*} id
     */
    function removeItemAndSync(type, id) {
        removeItem(type, id);
        syncInputText();
        if (typeof window.__regEditMarkDirty === 'function') window.__regEditMarkDirty();
    }

    /**
     * Sets the selected items value.
     *
     * @param {*} items
     */
    function setSelectedItems(items) {
        selected = items.map(i => ({ type: i.type, id: i.id, label: i.label }));
        render();
        syncInputText();
    }

    /**
     * Seed from string.
     *
     * @param {*} str
     * @param {*} force
     */
    function seedFromString(str, force) {
        if (!str) return;
        if (!force && selected.length > 0) return;
        if (force) selected = [];
        str.split(',').map(s => s.trim()).filter(Boolean).forEach(part => {
            const office = findOfficeByLabelOrCode(part, getList())
                || getList().find(o => o[labelKey].toLowerCase() === part.toLowerCase());
            if (office && office[idKey]) {
                selected.push({ type: 'office', id: office[idKey], label: office[labelKey] });
            } else if (!office && opts.allowFreeText) {
                idCounter++;
                selected.push({ type: 'name', id: 'n' + idCounter, label: part });
            }
        });
        render();
        syncInputText();
        maybeSyncSourceUnits();
    }

    /**
     * Jumps caret to end.
     */
    function jumpCaretToEnd() {
        setTimeout(() => { const len = inputEl.value.length; inputEl.setSelectionRange(len, len); }, 0);
    }

    const inputEl = document.getElementById(opts.inputId);
    const arrowEl = document.getElementById(opts.arrowId);
    inputEl.addEventListener('input', function () { closePanel(); handleSearch(this); });
    inputEl.addEventListener('keydown', function (e) { handleKeydown(e, this); });
    inputEl.addEventListener('focus', jumpCaretToEnd);
    inputEl.addEventListener('click', jumpCaretToEnd);
    arrowEl.addEventListener('click', togglePanel);

    document.addEventListener('click', function (e) {
        const widget = document.getElementById(opts.widgetId);
        const chipsEl = document.getElementById(opts.chipsId);
        const insideWidget = widget && widget.contains(e.target);
        const insidePanel = chipsEl && chipsEl.contains(e.target);
        if (!insideWidget && !insidePanel) {
            document.getElementById(opts.resultsId).style.display = 'none';
            closePanel();
        }
    });

    /**
     * Commits pending query.
     */
    function commitPendingQuery() {
        if (!inputEl || opts.singleSelect || !opts.allowFreeText) return;
        const q = getCurrentQuery(inputEl);
        if (!q) return;
        const already = selected.some(i => String(i.label).toLowerCase() === q.toLowerCase());
        if (already) return;
        addFreeText(q);
        syncInputText();
        render();
    }
    const parentForm = inputEl ? inputEl.closest('form') : null;
    if (parentForm) parentForm.addEventListener('submit', commitPendingQuery);

    render();

    const api = {
        pick, removeItem: removeItemAndSync, reset, seedFromString, setSelectedItems, commitPendingQuery,
        openPanel: togglePanel, get selected() { return selected; }
    };
    window.__sourceWidgets[opts.key] = api;
    return api;
}

// ══════════════════════════════════════════════
// UNIVERSAL SOURCE UNIT OVERLAY
// ══════════════════════════════════════════════
window.__sourceOverlayConfigs = window.__sourceOverlayConfigs || {};

/**
 * Closes or dismisses the source overlay.
 */
function closeSourceOverlay() {
    const overlay = document.getElementById('universalSourceOverlay');
    const backdrop = document.getElementById('universalSourceBackdrop');
    if (overlay) overlay.remove();
    if (backdrop) backdrop.remove();
    document.removeEventListener('keydown', handleSourceOverlayEsc);
}
window.closeSourceOverlay = closeSourceOverlay;

/**
 * Handles source overlay esc event or response.
 *
 * @param {*} e
 */
function handleSourceOverlayEsc(e) { if (e.key === 'Escape') closeSourceOverlay(); }

/**
 * Displays the source overlay overlay or dialog.
 *
 * @param {*} config
 */
function openSourceOverlay(config) {
    closeSourceOverlay();
    window.__sourceOverlayConfigs[config.key] = config;

    const backdrop = document.createElement('div');
    backdrop.id = 'universalSourceBackdrop';
    backdrop.className = 'drf-overlay-backdrop';
    backdrop.onclick = closeSourceOverlay;
    document.body.appendChild(backdrop);

    const overlay = document.createElement('div');
    overlay.id = 'universalSourceOverlay';
    overlay.innerHTML = `
        <div class="drf-overlay-header">
            <span>${config.title}</span>
            <button type="button" onclick="closeSourceOverlay()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="drf-overlay-search">
            <div class="reg-search">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                <input type="text" id="universalSourceOverlaySearch" placeholder="${config.searchPlaceholder}" autocomplete="off">
            </div>
        </div>
        <div id="universalSourceOverlaySuggestions" class="drf-overlay-suggestions"></div>
        <div class="drf-overlay-selected">
            <div class="drf-overlay-selected-label">Selected (<span id="universalSourceOverlayCount">0</span>)</div>
            <div id="universalSourceOverlayChips" class="drf-overlay-chips"></div>
        </div>
    `;
    document.body.appendChild(overlay);

    const searchInput = document.getElementById('universalSourceOverlaySearch');
    searchInput.addEventListener('input', () => handleSourceOverlaySearch(config, searchInput));
    document.addEventListener('keydown', handleSourceOverlayEsc);
    searchInput.focus();
    refreshSourceOverlay(config);
}

/**
 * Renders and synchronizes source overlay in the DOM.
 *
 * @param {*} config
 */
function refreshSourceOverlay(config) {
    const container = document.getElementById('universalSourceOverlayChips');
    const countEl = document.getElementById('universalSourceOverlayCount');
    if (!container) return;
    const items = config.getSelected();
    countEl.textContent = items.length;
    container.innerHTML = items.length === 0
        ? '<div class="drf-overlay-empty">No offices selected yet</div>'
        : items.map(item => `
            <div class="drf-overlay-chip">
                <i class="fa-solid fa-building"></i>
                <span>${escapeHtml(item.label)}</span>
                <button type="button" onclick="removeSourceOverlayItem('${config.key}', '${item.id}')"><i class="fa-solid fa-xmark"></i></button>
            </div>
        `).join('');
    if (config.onChange) config.onChange();
}

/**
 * Handles source overlay search event or response.
 *
 * @param {*} config
 * @param {*} input
 */
function handleSourceOverlaySearch(config, input) {
    const dropdown = document.getElementById('universalSourceOverlaySuggestions');
    const q = input.value.trim();
    if (q.length < 1) { dropdown.style.display = 'none'; return; }
    const selectedIds = config.getSelected().map(i => i.id.split(':').pop());
    const filtered = filterItems(config.getList(), config.labelKey, q).filter(o => !selectedIds.includes(String(o[config.idKey])));
    if (filtered.length === 0) {
        dropdown.innerHTML = `<div class="drf-overlay-noresult">No matching ${config.itemLabelPlural} found</div>`;
        dropdown.style.display = 'block';
        return;
    }
    dropdown.innerHTML = filtered.map(o =>
        `<div onmousedown="pickSourceOverlayOffice('${config.key}', ${o[config.idKey]})">${escapeHtml(o[config.labelKey])}</div>`
    ).join('');
    dropdown.style.display = 'block';
}

/**
 * Picks source overlay office.
 *
 * @param {*} key
 * @param {*} officeId
 */
window.pickSourceOverlayOffice = function (key, officeId) {
    const config = window.__sourceOverlayConfigs[key];
    if (!config) return;
    config.addOffice(officeId);
    document.getElementById('universalSourceOverlaySearch').value = '';
    document.getElementById('universalSourceOverlaySuggestions').style.display = 'none';
    refreshSourceOverlay(config);
};

/**
 * Removes specified source overlay item.
 *
 * @param {*} key
 * @param {*} id
 */
window.removeSourceOverlayItem = function (key, id) {
    const config = window.__sourceOverlayConfigs[key];
    if (!config) return;
    config.removeItem(id);
    refreshSourceOverlay(config);
};

// ══════════════════════════════════════════════
// RELATED DOCUMENTS
// ══════════════════════════════════════════════
function relatedDocsQuery(input) {
    const raw = String(input?.value || '');
    const lastComma = raw.lastIndexOf(',');
    return (lastComma === -1 ? raw : raw.slice(lastComma + 1)).trim();
}

/**
 * Renders and synchronizes related docs input in the DOM.
 */
function syncRelatedDocsInput() {
    const input = document.getElementById('relatedDocsSearch');
    if (!input) return;
    if (relatedDocsSelected.length === 0) {
        input.value = '';
        return;
    }
    input.value = relatedDocsSelected.map(d => d.doc_title || d.doc_no || '').join(', ') + ', ';
    const len = input.value.length;
    try { input.setSelectionRange(len, len); } catch (e) {}
}

/**
 * Handles related doc focus event or response.
 *
 * @param {*} input
 */
window.handleRelatedDocFocus = function (input) {
    closeRelatedDocsSelectedPanel();
    syncRelatedDocsInput();
};

/**
 * Handles related doc search event or response.
 *
 * @param {*} input
 */
window.handleRelatedDocSearch = function (input) {
    clearTimeout(window.__relatedDocsTimer);
    if (window.__relatedDocsAbort) {
        try { window.__relatedDocsAbort.abort(); } catch (e) {}
    }
    const q = relatedDocsQuery(input);
    const dropdown = document.getElementById('relatedDocsResults');
    closeRelatedDocsSelectedPanel();
    if (q.length < 1) { dropdown.style.display = 'none'; return; }

    window.__relatedDocsTimer = setTimeout(async () => {
        const abort = new AbortController();
        window.__relatedDocsAbort = abort;
        try {
            const excludeId = document.getElementById('requestId')?.value || '';
            const url = '/dcs/api/documents/search?latest_only=1&q=' + encodeURIComponent(q)
                + (excludeId ? '&exclude_request_id=' + excludeId : '');
            const res = await fetch(url, { signal: abort.signal, headers: { 'Accept': 'application/json' } });
            if (!res.ok) {
                dropdown.innerHTML = '<div class="reg-reldocs-noresult">Could not search documents. Try again.</div>';
                dropdown.style.display = 'block';
                return;
            }
            const data = await res.json();
            relatedDocsCache = (Array.isArray(data) ? data : []).filter(d => !relatedDocsSelected.some(s => s.masterlist_id === d.masterlist_id));
            if (relatedDocsCache.length === 0) {
                dropdown.innerHTML = '<div class="reg-reldocs-noresult">No latest document matches "' + escapeHtml(q) + '".</div>';
                dropdown.style.display = 'block';
                return;
            }
            dropdown.innerHTML = relatedDocsCache
                .map(d => `<div onmousedown="pickRelatedDoc(${Number(d.masterlist_id)})">${escapeHtml(d.label || d.doc_title || '')}</div>`)
                .join('');
            dropdown.style.display = 'block';
        } catch (e) {
            if (e && e.name === 'AbortError') return;
            dropdown.innerHTML = '<div class="reg-reldocs-noresult">Could not search documents. Try again.</div>';
            dropdown.style.display = 'block';
        }
    }, 80);
};

/**
 * Picks related doc.
 *
 * @param {*} id
 */
window.pickRelatedDoc = function (id) {
    const doc = relatedDocsCache.find(d => d.masterlist_id === id);
    if (!doc || relatedDocsSelected.some(d => d.masterlist_id === id)) return;
    relatedDocsSelected.push(doc);
    renderRelatedDocsChips();
    syncRelatedDocsInput();
    document.getElementById('relatedDocsResults').style.display = 'none';
    if (typeof window.__regEditMarkDirty === 'function') window.__regEditMarkDirty();
};

/**
 * Removes specified related doc.
 *
 * @param {*} id
 * @param {*} event
 */
window.removeRelatedDoc = function (id, event) {
    if (event) event.stopPropagation();
    relatedDocsSelected = relatedDocsSelected.filter(d => d.masterlist_id !== id);
    renderRelatedDocsChips();
    syncRelatedDocsInput();
    if (typeof window.__regEditMarkDirty === 'function') window.__regEditMarkDirty();
};

/**
 * Toggles or switches related docs selected.
 *
 * @param {*} e
 */
window.toggleRelatedDocsSelected = function (e) {
    if (e) e.stopPropagation();
    document.getElementById('relatedDocsResults').style.display = 'none';
    relatedDocsSelectedPanelOpen = !relatedDocsSelectedPanelOpen;
    const panel = document.getElementById('relatedDocsSelectedPanel');
    const icon = document.getElementById('relatedDocsArrowIcon');
    panel.style.display = relatedDocsSelectedPanelOpen ? 'block' : 'none';
    icon.style.transform = relatedDocsSelectedPanelOpen ? 'rotate(180deg)' : '';
};

/**
 * Closes or dismisses the related docs selected panel.
 */
function closeRelatedDocsSelectedPanel() {
    relatedDocsSelectedPanelOpen = false;
    const panel = document.getElementById('relatedDocsSelectedPanel');
    const icon = document.getElementById('relatedDocsArrowIcon');
    if (panel) panel.style.display = 'none';
    if (icon) icon.style.transform = '';
}

/**
 * Renders and synchronizes related docs chips in the DOM.
 */
function renderRelatedDocsChips() {
    const container = document.getElementById('relatedDocsChips');
    if (!container) return;
    if (relatedDocsSelected.length === 0) {
        container.innerHTML = '<div class="reg-reldocs-empty">No documents selected yet</div>';
        return;
    }
    container.innerHTML = relatedDocsSelected.map(d => `
        <div class="reg-reldocs-chip">
            <input type="hidden" name="relatedDocumentIds[]" value="${d.masterlist_id}">
            <span class="reg-reldocs-chip-title">${escapeHtml(d.doc_title)}</span>
            <span class="reg-reldocs-chip-no">${escapeHtml(d.doc_no || '')}</span>
            <button type="button" onclick="removeRelatedDoc(${d.masterlist_id}, event)"><i class="fa-solid fa-xmark"></i></button>
        </div>`).join('');
    syncRelatedDocsInput();
}

document.addEventListener('click', function (e) {
    const widget = document.getElementById('relatedDocsWidget');
    if (widget && !widget.contains(e.target)) {
        document.getElementById('relatedDocsResults').style.display = 'none';
        closeRelatedDocsSelectedPanel();
    }
});

// ══════════════════════════════════════════════
// KEYWORDS CHIP INPUT
// ══════════════════════════════════════════════
function initKeywordsWidget(root = document) {
    root.querySelectorAll('[data-keywords-widget]').forEach((widget) => {
        if (widget.dataset.bound === '1') return;
        widget.dataset.bound = '1';

        const chipsEl = widget.querySelector('[data-keywords-chips]');
        const entry = widget.querySelector('[data-keywords-entry]');
        const hidden = widget.querySelector('input[type="hidden"][name="keywords"]');
        const box = widget.querySelector('[data-keywords-box]');
        if (!chipsEl || !entry || !hidden) return;

        const normalize = (raw) => String(raw || '')
            .split(/[,;\n]+/)
            .map((part) => part.trim())
            .filter(Boolean);

        const uniquePush = (list, value) => {
            const exists = list.some((item) => item.toLowerCase() === value.toLowerCase());
            if (!exists) list.push(value);
            return list;
        };

        const readTokens = () => normalize(hidden.value);

        const writeTokens = (tokens) => {
            hidden.value = tokens.join(', ');
            chipsEl.innerHTML = tokens.map((token) => (
                '<span class="reg-keyword-chip">' +
                    '<span title="' + escapeHtml(token) + '">' + escapeHtml(token) + '</span>' +
                    '<button type="button" aria-label="Remove keyword" data-keyword-remove="' + escapeHtml(token) + '">' +
                        '<i class="fa-solid fa-xmark"></i>' +
                    '</button>' +
                '</span>'
            )).join('');
        };

        const addTokens = (raw) => {
            const next = readTokens();
            normalize(raw).forEach((token) => uniquePush(next, token));
            writeTokens(next);
            entry.value = '';
        };

        const removeToken = (token) => {
            writeTokens(readTokens().filter((item) => item.toLowerCase() !== String(token).toLowerCase()));
        };

        writeTokens(readTokens());
        widget.__seedKeywords = (raw) => {
            writeTokens(normalize(raw));
        };

        box?.addEventListener('click', (e) => {
            if (e.target.closest('[data-keyword-remove]')) return;
            entry.focus();
        });

        chipsEl.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-keyword-remove]');
            if (!btn) return;
            e.preventDefault();
            removeToken(btn.getAttribute('data-keyword-remove') || '');
            entry.focus();
        });

        entry.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ',' || e.key === 'Tab') {
                if (!entry.value.trim()) {
                    if (e.key === ',') e.preventDefault();
                    return;
                }
                e.preventDefault();
                addTokens(entry.value);
                return;
            }
            if (e.key === 'Backspace' && !entry.value) {
                const tokens = readTokens();
                if (!tokens.length) return;
                tokens.pop();
                writeTokens(tokens);
            }
        });

        entry.addEventListener('blur', () => {
            if (entry.value.trim()) addTokens(entry.value);
        });

        entry.addEventListener('paste', (e) => {
            const text = e.clipboardData?.getData('text') || '';
            if (!text.includes(',') && !text.includes(';') && !text.includes('\n')) return;
            e.preventDefault();
            addTokens(text);
        });
    });
}

// ══════════════════════════════════════════════
// SELECT PROTECTION
// ══════════════════════════════════════════════
function initSelectProtection() {
    const selects = {
        versionType: { el: document.getElementById("versionType"), handler: handleVersionChange },
        docType: { el: document.getElementById("docType"), handler: handleDocTypeChange },
        subType: { el: document.getElementById("subType"), handler: validateChecklistState },
    };
    const userTouched = {};
    Object.keys(selects).forEach(key => {
        userTouched[key] = false;
        const { el, handler } = selects[key];
        if (!el) return;
        el.addEventListener("pointerdown", () => { userTouched[key] = true; });
        el.addEventListener("keydown", () => { userTouched[key] = true; });
        el.addEventListener("change", function () {
            if (!userTouched[key]) { this.value = this.dataset.lastValid || ""; return; }
            userTouched[key] = false;
            this.dataset.lastValid = this.value;
            handler();
        });
        el.addEventListener("input", function () {
            if (!userTouched[key]) this.value = this.dataset.lastValid || "";
        });
    });
}

// ══════════════════════════════════════════════
// FILE INPUTS (with drag & drop)
// ══════════════════════════════════════════════
function assignDroppedFiles(input, files) {
    if (!input || !files || files.length === 0) return false;
    try {
        input.files = files;
    } catch (err) {
        try {
            const dt = new DataTransfer();
            dt.items.add(files[0]);
            input.files = dt.files;
        } catch (err2) {
            return false;
        }
    }
    return !!(input.files && input.files.length > 0);
}

/**
 * Initializes and sets up file inputs.
 */
function initFileInputs() {
    document.querySelectorAll('.reg-upload').forEach(container => {
        const input = container.querySelector('input[type="file"]');
        if (!input || input.dataset.bound) return;
        input.setAttribute('accept', '.pdf');
        input.dataset.bound = "true";
        const icon = container.querySelector('i');
        const label = container.querySelector('span');
        const originalText = label ? label.textContent : 'Choose scanned PDF';
        input.addEventListener('change', function () { processUploadAreaFile(this, container, icon, label, originalText); });

        container.addEventListener('dragover', function (e) { e.preventDefault(); container.classList.add('reg-upload-drag'); });
        container.addEventListener('dragleave', function () { container.classList.remove('reg-upload-drag'); });
        container.addEventListener('drop', function (e) {
            e.preventDefault();
            container.classList.remove('reg-upload-drag');
            if (assignDroppedFiles(input, e.dataTransfer.files)) {
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    });

    document.querySelectorAll('.reg-upload-cell').forEach(cell => {
        const input = cell.querySelector('input[type="file"]');
        if (!input || input.dataset.bound) return;
        input.setAttribute('accept', '.pdf');
        input.dataset.bound = "true";
        input.addEventListener('change', function () { processUploadCellFile(this, cell); });
    });

    document.querySelectorAll('#revisionTableBody input[type="file"]').forEach(input => {
        if (input.dataset.bound) return;
        input.setAttribute('accept', '.pdf');
        input.dataset.bound = "true";
        input.addEventListener('change', function () { validateTableFile(this); });
    });
}

/**
 * Process upload area file.
 *
 * @param {*} input
 * @param {*} container
 * @param {*} icon
 * @param {*} label
 * @param {*} originalText
 */
function processUploadAreaFile(input, container, icon, label, originalText) {
    container.classList.remove('reg-upload-success', 'reg-upload-error', 'reg-upload-drag');
    removeExistingError(container);
    removeUploadFieldActions(container);

    if (!input.files || !input.files[0]) { resetUploadArea(container, icon, label, originalText); return; }

    const file = input.files[0];
    const check = checkFile(file);

    if (!check.valid) {
        showUploadFieldError(container, fileTypeErrorMessage(check, file));
        container.classList.add('reg-upload-error');
        clearFileIcon(icon, label, originalText);
        input.value = '';
        return;
    }

    container.classList.add('reg-upload-success');
    setFileIcon(icon, check.ext);
    label.textContent = file.name;
    label.style.color = 'var(--reg-success)';
    label.style.fontWeight = '600';
    attachUploadFieldActions(container, input, file, icon, label, originalText);

    if (check.ext === 'pdf' && file.size <= OCR_MAX_FILE_SIZE && (input.id === 'drfFile' || input.id === 'scanneddist')) {
        triggerScanExtraction(input, file, input.id === 'scanneddist' ? 'distribution' : 'drf');
    }
}

/**
 * Triggers scan extraction.
 *
 * @param {*} input
 * @param {*} file
 * @param {*} section
 */
function triggerScanExtraction(input, file, section) {
    section = section || 'drf';
    const formData = new FormData();
    formData.append('scan', file);
    formData.append('section', section);
    formData.append('_token', document.querySelector('meta[name="csrf-token"]')?.content
        || document.querySelector('input[name="_token"]')?.value);

    const container = input.closest('.reg-upload');
    const label = container?.querySelector('span');
    const originalLabelText = label ? label.textContent : '';
    if (label) label.textContent = 'Reading scanned document...';

    fetch('/dcs/register/extract-scan', { method: 'POST', body: formData })
        .then(async r => {
            const data = await r.json().catch(() => ({}));
            if (label) label.textContent = originalLabelText;
            container?.classList.remove('reg-upload-error');
            container?.classList.add('reg-upload-success');

            if (data.extracted) {
                const fields = data.fields || {};
                const filled = section === 'distribution'
                    ? applyDistributionOffices(fields)
                    : (autofillDrfFields(fields) || applyDistributionOffices(fields));
                if (filled) {
                    removeExistingError(container);
                    return;
                }
                if (fields.drfNo || fields.drfTitle || fields.drfDate
                    || (fields.distributionOffices || []).length
                    || (fields.sourceOffices || []).length) {
                    removeExistingError(container);
                    return;
                }
            }

            if (data.diagnostics) {
                console.warn('[DRF OCR diagnostics]', data.diagnostics);
            }
            showOcrSoftHint(
                container,
                formatDrfOcrHint(data)
            );
        })
        .catch(err => {
            if (label) label.textContent = originalLabelText;
            container?.classList.remove('reg-upload-error');
            container?.classList.add('reg-upload-success');
            showOcrSoftHint(
                container,
                'Could not auto-fill DRF fields from this scan. Upload kept — fill them in manually.'
            );
            console.error('Extraction request failed:', err);
        });
}

/**
 * Autofills drf fields.
 *
 * @param {*} fields
 */
function autofillDrfFields(fields) {
    let filled = false;
    const map = { drfNo: 'drfNo', drfDate: 'drfDate', drfTitle: 'drfTitle' };
    Object.entries(map).forEach(([fieldKey, elId]) => {
        const value = fields[fieldKey];
        const el = document.getElementById(elId);
        if (value && el && (!el.value || el.classList.contains('reg-autofilled'))) {
            el.value = value;
            el.classList.add('reg-autofilled');
            filled = true;
            if (elId === 'drfTitle') {
                const ml = document.getElementById('masterlistDocTitle');
                if (ml) ml.value = value;
            }
        }
    });
    if (window.__sourceWidgets?.drf) {
        try {
            const items = resolveActiveSourceUnitItems(fields);
            if (items.length > 0) {
                window.__sourceWidgets.drf.setSelectedItems(items);
                syncSourceUnitsAcrossSections(items, 'drf');
                filled = true;
            }
        } catch (err) {
            console.error('Source Unit autofill failed:', err);
        }
    }
    window.DCSScanNamePreview?.update();
    return filled;
}

/**
 * Reveals distribution section.
 */
function revealDistributionSection() {
    const cb = document.querySelector('#dynamicCheckboxes input[name="checklists[]"][value="5"]');
    if (cb && !cb.checked) {
        cb.checked = true;
        cb.dataset.lastChecked = 'true';
    }
    if (typeof toggleSection === 'function') toggleSection(5, true);
    const section = document.getElementById('section-5');
    if (section) section.style.display = 'block';
}

/**
 * Applies distribution offices.
 *
 * @param {*} fields
 */
function applyDistributionOffices(fields) {
    const offices = Array.isArray(fields?.distributionOffices) ? fields.distributionOffices : [];
    if (!offices.length || typeof addOffice !== 'function') return false;
    revealDistributionSection();
    let added = false;
    offices.forEach((office) => {
        const id = office?.id ?? office?.office_id;
        const name = office?.office_name || office?.office_code;
        if (!id || !name) return;
        addOffice(id, name, 'distBody', 'distTotal', 'distResults');
        added = true;
    });
    if (typeof syncDistClusterChipState === 'function') syncDistClusterChipState();
    return added;
}

/** Map OCR office payload → active catalog offices only (no free-text / inactive). */
function resolveActiveSourceUnitItems(fields) {
    const items = [];
    const seen = new Set();
    const pushFromCatalog = (office) => {
        if (!office) return;
        const id = office.office_id ?? office.id;
        const key = String(id);
        if (!key || seen.has(key)) return;
        seen.add(key);
        items.push({ type: 'office', id: office.office_id ?? office.id, label: office.office_name });
    };
    const findActive = (needle) => findOfficeByLabelOrCode(needle, allOffices)
        || allOffices.find(o => String(o.office_id) === String(needle));

    const offices = Array.isArray(fields.sourceOffices) ? fields.sourceOffices : [];
    offices.forEach((office) => {
        const id = office?.id ?? office?.sourceOfficeId;
        const code = office?.office_code || office?.office_name;
        pushFromCatalog(findActive(id) || (code ? findActive(code) : null));
    });

    if (items.length === 0 && Array.isArray(fields.sourceOfficeCodes)) {
        fields.sourceOfficeCodes.forEach((code) => pushFromCatalog(findActive(code)));
    }
    if (items.length === 0 && fields.sourceOfficeId) {
        pushFromCatalog(findActive(fields.sourceOfficeId));
    }
    if (items.length === 0 && (fields.sourceOfficeCode || fields.sourceUnit)) {
        String(fields.sourceOfficeCode || fields.sourceUnit)
            .split(/[,;\/|]+/)
            .map(s => s.trim())
            .filter(Boolean)
            .forEach((part) => pushFromCatalog(findActive(part)));
    }
    return items;
}

/**
 * Displays the upload field error overlay or dialog.
 *
 * @param {*} container
 * @param {*} message
 */
function showUploadFieldError(container, message) {
    removeExistingError(container);
    const parent = container.closest('.reg-field') || container.parentElement;
    if (parent) {
        const err = document.createElement('div');
        err.className = 'reg-file-error';
        err.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + escapeHtml(message);
        parent.appendChild(err);
    }
    container.style.animation = 'none';
    container.offsetHeight;
    container.style.animation = 'shake 0.4s ease';
}

/**
 * Displays the ocr soft hint overlay or dialog.
 *
 * @param {*} container
 * @param {*} message
 */
function showOcrSoftHint(container, message) {
    removeExistingError(container);
    const parent = container?.closest('.reg-field') || container?.parentElement;
    if (!parent) return;
    const hint = document.createElement('div');
    hint.className = 'reg-ocr-hint';
    hint.innerHTML = '<i class="fa-solid fa-circle-info"></i> ' + escapeHtml(message);
    parent.appendChild(hint);
}

/** Prefer server message; append missing-tool hints from diagnostics (no SSH needed). */
function formatDrfOcrHint(data) {
    let msg = data?.message
        || 'Could not auto-fill DRF fields from this scan. Upload kept — fill them in manually.';
    const stack = data?.diagnostics?.stack;
    if (!stack || typeof stack !== 'object') return msg;
    const missing = [];
    if (!stack.ghostscript) missing.push('Ghostscript');
    if (!stack.pdftoppm) missing.push('pdftoppm');
    if (!stack.imagick_ext) missing.push('Imagick');
    if (missing.length) {
        msg += ' Missing on server: ' + missing.join(', ') + '.';
    }
    return msg;
}

/**
 * Clears and resets upload area.
 *
 * @param {*} container
 * @param {*} icon
 * @param {*} label
 * @param {*} originalText
 */
function resetUploadArea(container, icon, label, originalText) {
    container.classList.remove('reg-upload-success', 'reg-upload-error', 'reg-upload-drag', 'reg-upload-has-file');
    container.style.borderColor = ''; container.style.background = ''; container.style.borderStyle = ''; container.style.animation = '';
    clearFileIcon(icon, label, originalText);
    removeUploadFieldActions(container);
    removeExistingError(container);
}

/**
 * Removes specified upload field actions.
 *
 * @param {*} container
 */
function removeUploadFieldActions(container) {
    if (!container) return;
    const actions = container.querySelector('.reg-upload-field-actions');
    if (actions?.dataset.blobUrl) {
        URL.revokeObjectURL(actions.dataset.blobUrl);
    }
    actions?.remove();
    container.querySelectorAll('.reg-file-remove').forEach(el => el.remove());
    container.classList.remove('reg-upload-has-file');
}

/**
 * Removes specified existing preview.
 *
 * @param {*} container
 */
function removeExistingPreview(container) {
    removeUploadFieldActions(container);
}

/**
 * Binds and registers upload field actions event listeners.
 *
 * @param {*} container
 * @param {*} input
 * @param {*} file
 * @param {*} icon
 * @param {*} label
 * @param {*} originalText
 */
function attachUploadFieldActions(container, input, file, icon, label, originalText) {
    removeUploadFieldActions(container);
    if (!container || !file) return;

    const check = checkFile(file);
    if (!check.valid) return;

    container.classList.add('reg-upload-has-file');

    const actions = document.createElement('div');
    actions.className = 'reg-upload-field-actions';

    if (check.ext === 'pdf') {
        const url = URL.createObjectURL(file);
        actions.dataset.blobUrl = url;
        actions.innerHTML =
            '<button type="button" class="reg-upload-view-btn">' +
                '<i class="fa-solid fa-eye"></i> View' +
            '</button>' +
            '<button type="button" class="reg-upload-clear" title="Remove file">' +
                '<i class="fa-solid fa-xmark"></i>' +
            '</button>';
        actions.querySelector('.reg-upload-view-btn')?.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            openFilePreviewModal(url, file.name);
        });
    } else {
        actions.innerHTML =
            '<button type="button" class="reg-upload-clear" title="Remove file">' +
                '<i class="fa-solid fa-xmark"></i>' +
            '</button>';
    }

    actions.querySelector('.reg-upload-clear')?.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        input.value = '';
        resetUploadArea(container, icon, label, originalText);
    });

    container.appendChild(actions);
}

/**
 * Displays the file preview modal.
 *
 * @param {*} url
 * @param {*} title
 */
function openFilePreviewModal(url, title) {
    const overlay = document.getElementById('filePreviewModal');
    const frame = document.getElementById('filePreviewModalFrame');
    const titleEl = document.getElementById('filePreviewModalTitle');
    const loading = document.getElementById('filePreviewModalLoading');
    if (!overlay || !frame) return;
    if (loading) loading.hidden = false;
    frame.onload = () => { if (loading) loading.hidden = true; };
    frame.onerror = () => { if (loading) loading.hidden = true; };
    frame.src = url;
    if (titleEl) titleEl.textContent = title || 'File preview';
    overlay.classList.add('is-open');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

/**
 * Closes and hides the file preview modal.
 */
function closeFilePreviewModal() {
    const overlay = document.getElementById('filePreviewModal');
    const frame = document.getElementById('filePreviewModalFrame');
    const loading = document.getElementById('filePreviewModalLoading');
    if (!overlay) return;
    overlay.classList.remove('is-open');
    overlay.setAttribute('aria-hidden', 'true');
    if (loading) loading.hidden = true;
    if (frame) {
        frame.onload = null;
        frame.onerror = null;
        frame.src = 'about:blank';
    }
    document.body.style.overflow = '';
}

/**
 * Removes specified existing remove btn.
 *
 * @param {*} c
 */
function removeExistingRemoveBtn(c) { const o = c.querySelector('.reg-file-remove'); if (o) o.remove(); }
/**
 * Removes specified existing error.
 *
 * @param {*} c
 */
function removeExistingError(c) {
    if (!c) return;
    c.querySelectorAll('.reg-file-error, .reg-ocr-hint').forEach(el => el.remove());
    const parent = c.closest('.reg-field') || c.parentElement;
    parent?.querySelectorAll(':scope > .reg-file-error, :scope > .reg-ocr-hint').forEach(el => el.remove());
}

/**
 * Process upload cell file.
 *
 * @param {*} input
 * @param {*} cell
 */
function processUploadCellFile(input, cell) {
    const label = cell.querySelector('span');
    const icon = cell.querySelector('i');
    const originalText = 'No file chosen';
    resetUploadCell(cell, icon, label, originalText);

    if (!input.files || !input.files[0]) return;

    const file = input.files[0];
    const check = checkFile(file);
    if (!check.valid) {
        showUploadFieldError(cell, 'Only scanned PDF files are accepted.');
        clearFileIcon(icon, label, originalText);
        input.value = ''; return;
    }

    cell.classList.add('reg-upload-cell-success');
    cell.style.borderColor = 'var(--reg-success-border)';
    cell.style.background = 'var(--reg-success-bg)';
    setFileIcon(icon, check.ext);
    label.textContent = file.name; label.style.color = 'var(--reg-success)'; label.style.fontWeight = '600';
}

/**
 * Validates and verifies table file.
 *
 * @param {*} input
 */
function validateTableFile(input) {
    const td = input.closest('td');
    removeExistingError(td);
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    const check = checkFile(file);
    if (!check.valid) {
        const e = document.createElement('div'); e.className = 'reg-file-error';
        e.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' +
            (check.reason === 'type' ? 'Only scanned PDF files are accepted.' : 'Max 10MB');
        td.appendChild(e); input.value = '';
    }
}

/**
 * Binds and registers table file input event listeners.
 *
 * @param {*} fileInput
 */
function bindTableFileInput(fileInput) {
    if (!fileInput) return;
    fileInput.dataset.bound = "true";
    fileInput.addEventListener('change', function () { validateTableFile(this); });
}

// ══════════════════════════════════════════════
// VERSION / DOC TYPE (locked in edit mode, implementations kept for completeness)
// ══════════════════════════════════════════════
async function handleVersionChange() {
    // In edit mode, version type is locked — this handler is a no-op.
    // Implementation kept for completeness if unlocked in the future.
}

/**
 * Handles doc type change event or response.
 */
function handleDocTypeChange() {
    // In edit mode, doc type is locked — this handler is a no-op.
    // Implementation kept for completeness if unlocked in the future.
}

/**
 * Validates and verifies checklist state.
 */
function validateChecklistState() {
    const subTypeId = document.getElementById("subType").value;
    const subTypeData = allDocTypes.find(d => d.doc_type_id == subTypeId);
    const syllabiSection = document.getElementById("section-syllabi");

    const isSyllabiLike = isSyllabiLikeSubType(subTypeId);

    // Clear stale syllabi state when sub-type changes
    if (subTypeId !== window.__lastSubTypeId) {
        resetSyllabiSection();
    }
    window.__lastSubTypeId = subTypeId;

    window.__isSyllabiMode = isSyllabiLike;
    window.__syllabiModeLabel = subTypeData ? subTypeData.doc_type_name : 'Syllabi';

    if (subTypeId) {
        if (!window.__syllabiEditLocked) {
            unlockChecklist();
        }
        if (isSyllabiLike && syllabiSection) {
            applySyllabiSectionLabel();
            syllabiSection.style.display = "block";
            setSyllabiStep(1);
            loadSyllabiContextDropdowns();
            if (document.getElementById("syllabiTableBody").children.length === 0) {
                addSyllabiRow();
            }
            setTimeout(() => {
                syllabiSection.querySelectorAll('.reg-upload-cell').forEach(cell => {
                    const input = cell.querySelector('input[type="file"]');
                    if (input && !input.dataset.bound) {
                        input.setAttribute('accept', '.pdf');
                        input.dataset.bound = "true";
                        input.addEventListener('change', function () { processUploadCellFile(this, cell); });
                    }
                });
            }, 50);
        } else {
            if (syllabiSection) syllabiSection.style.display = "none";
            resetMasterlistNoOfPagesField();
        }
    }
}

/**
 * Clears and resets masterlist no of pages field.
 */
function resetMasterlistNoOfPagesField() {
    const mlPages = document.getElementById('masterlistNoOfPages');
    if (!mlPages) return;
    mlPages.readOnly = false;
    mlPages.style.background = '';
    mlPages.style.cursor = '';
}

/**
 * Clears and resets syllabi section.
 */
function resetSyllabiSection() {
    const syllabiBody = document.getElementById('syllabiTableBody');
    if (syllabiBody) {
        syllabiBody.querySelectorAll('tr[data-uid]').forEach(tr => removeSyllabiFacultyPicker(tr.dataset.uid));
        syllabiBody.innerHTML = '';
        syllabiGroupCounter = 0;
    }
    ['syllabiDocTitle'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    syllabiTitleManuallyEdited = false;
    const collegeSel = document.getElementById('syllabiCollege');
    const programSel = document.getElementById('syllabiProgram');
    const comboSel = document.getElementById('syllabiCollegeProgramCombo');
    const semSel = document.getElementById('syllabiSemester');
    const courseTypeSel = document.getElementById('syllabiCourseType');
    const sySel = document.getElementById('syllabiSchoolYear');
    const cpTrigger = document.getElementById('syllabiCpTrigger');
    const locked = typeof syllabiContextFieldsLocked === 'function' && syllabiContextFieldsLocked();

    // College is first in the cascade — enable unless this edit is context-locked.
    if (collegeSel) {
        collegeSel.selectedIndex = 0;
        collegeSel.disabled = !!locked;
        if (!locked) {
            collegeSel.removeAttribute('disabled');
            collegeSel.classList.remove('reg-field-locked');
        }
    }
    if (courseTypeSel) {
        courseTypeSel.selectedIndex = 0;
        courseTypeSel.disabled = true;
        courseTypeSel.classList.remove('reg-field-locked');
    }
    if (comboSel) { comboSel.selectedIndex = 0; comboSel.value = ''; }
    if (programSel) { programSel.innerHTML = '<option value="" selected disabled>Select program</option>'; programSel.disabled = true; }
    if (semSel) { semSel.selectedIndex = 0; semSel.disabled = true; }
    if (sySel) { sySel.selectedIndex = 0; sySel.disabled = true; }
    if (cpTrigger) {
        // Locked edits stay disabled; otherwise wait for College + Course Type.
        cpTrigger.disabled = true;
        const labelEl = document.getElementById('syllabiCpTriggerLabel');
        if (labelEl) labelEl.textContent = 'Select college first';
    }
    if (typeof refreshSyllabiProgramPicker === 'function') {
        refreshSyllabiProgramPicker('', []);
    }
    if (typeof setSyllabiCpOpen === 'function') {
        setSyllabiCpOpen(false);
    }
    if (typeof applySyllabiCourseTypeLayout === 'function') applySyllabiCourseTypeLayout('');
    if (typeof refreshSyllabiYearLevelDisplay === 'function') refreshSyllabiYearLevelDisplay([]);
    if (typeof syncSyllabiContextCascade === 'function') syncSyllabiContextCascade();

    // Keep hidden mirrors in sync on edit (visible selects may be locked).
    ['syllabiCollegeHidden', 'syllabiProgramHidden', 'syllabiSemesterHidden', 'syllabiCourseTypeHidden', 'syllabiSchoolYearHidden'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });

    if (typeof setSyllabiContextHint === 'function') {
        setSyllabiContextHint('', false);
    } else {
        const hint = document.getElementById('syllabiContextHint');
        if (hint) {
            hint.style.display = 'none';
            hint.textContent = '';
            hint.classList.remove('is-error', 'is-ok');
        }
    }

    if (typeof updateSyllabiTotals === 'function') updateSyllabiTotals();
}

/**
 * Applies syllabi section label.
 */
function applySyllabiSectionLabel() {
    const label = window.__syllabiModeLabel || 'Syllabi';
    const header = document.querySelector('#section-syllabi .reg-card-header span');
    if (header) header.textContent = label;
    const availHeader = document.getElementById('syllabiAvailabilityHeader');
    if (availHeader) {
        availHeader.textContent = /tos|rubric/i.test(label) ? (label + ' Availability') : 'Syllabi Availability';
    }
    document.querySelectorAll('#syllabiTableBody input[name="syllabiCourseName[]"], #syllabiTableBody textarea[name="syllabiCourseName[]"]').forEach(inp => {
        inp.placeholder = /tos/i.test(label) ? 'Enter course/exam name' : 'Enter course name';
    });
}

/**
 * Unlocks checklist.
 */
function unlockChecklist() {
    const container = document.getElementById("dynamicCheckboxes");
    const suggested = suggestedChecklistIdsForCurrentDocType();
    container.querySelectorAll("input[type='checkbox']").forEach(cb => {
        const id = parseInt(cb.value, 10);
        const isMasterlist = id === 3;
        cb.disabled = isMasterlist ? true : false;
        cb.checked = suggested ? suggested.has(id) : true;
        cb.dataset.lastChecked = cb.checked ? "true" : "false";
        toggleSection(id, cb.checked);
    });
    lockMasterlistChecklistOn();
    showFormActions();
    enableApproval();
    setTimeout(initFileInputs, 100);
    ['btnUpdateDocument', 'btnSaveDocument', 'btnSaveAndAppraise'].forEach((id) => {
        const saveBtn = document.getElementById(id);
        if (saveBtn) saveBtn.style.display = "";
    });
}

/**
 * Suggested checklist ids for current doc type.
 */
function suggestedChecklistIdsForCurrentDocType() {
    const docTypeId = document.getElementById('docType')?.value || CURRENT_DOC_TYPE_ID;
    if (!docTypeId) return null;
    const t = (allDocTypes || []).find(d => String(d.doc_type_id) === String(docTypeId));
    if (!t || t.parent_id) return null;
    const name = String(t.doc_type_name || '').trim().toLowerCase();
    if (name === 'external' || name.startsWith('external')
        || name === 'forms' || name.startsWith('form')
        || name === 'logbooks' || name.includes('logbook')) {
        return new Set([3, 5]);
    }
    return null;
}

/**
 * Checks whether masterlist scanned copy condition is met.
 */
function hasMasterlistScannedCopy() {
    const file = document.getElementById('uploadScannedCopy');
    if (file && file.files && file.files.length > 0) return true;
    if (document.querySelector('#section-3 .reg-current-file')) return true;
    const existing = document.querySelector('input[name="has_existing_masterlist_scan"]');
    if (existing && String(existing.value) === '1') return true;
    return false;
}

/**
 * Masterlists filled fields.
 */
function masterlistFilledFields() {
    const docNo = (document.getElementById('masterlistDocNo')?.value || '').trim();
    const title = (
        document.getElementById('masterlistDocTitle')?.value
        || document.getElementById('drfTitle')?.value
        || document.getElementById('syllabiDocTitle')?.value
        || ''
    ).trim();
    const effectivity = (document.getElementById('masterlistEffectivityDate')?.value || '').trim();
    const pages = (document.getElementById('masterlistNoOfPages')?.value || '').trim();
    const keywords = (document.getElementById('keywords')?.value || '').trim();
    const deadline = (document.getElementById('deadlineOfSubmission')?.value || '').trim();
    const receiptDate = (document.getElementById('masterlistReceiptDate')?.value || '').trim();
    const hasOriginator = !!(window.__sourceWidgets?.masterlistOriginator?.selected?.length);
    const hasSource = !!(window.__sourceWidgets?.masterlist?.selected?.length);

    return {
        docNo: !!docNo,
        title: !!title,
        effectivity: !!effectivity,
        pages: !!(pages && pages !== '0'),
        keywords: !!keywords,
        deadline: !!deadline,
        receiptDate: !!receiptDate,
        originator: hasOriginator,
        sourceUnit: hasSource,
        scannedCopy: hasMasterlistScannedCopy(),
    };
}

/**
 * Masterlists has data.
 */
function masterlistHasData() {
    const f = masterlistFilledFields();
    return Object.values(f).some(Boolean);
}

/** Drafts need more than Document No alone — at least one other masterlist input. */
function masterlistHasDraftSubstance() {
    const f = masterlistFilledFields();
    const filledCount = Object.values(f).filter(Boolean).length;
    if (filledCount < 2) return false;
    if (f.docNo && filledCount === 1) return false;
    return true;
}

/**
 * Validates and verifies masterlist required.
 *
 * @param {*} errors
 * @param {*} forDraft
 */
function validateMasterlistRequired(errors, { requireScan = false, forDraft = false } = {}) {
    if (forDraft) {
        if (!masterlistHasDraftSubstance()) {
            errors.push({
                field: 'masterlistDocTitle',
                message: 'To save a draft, fill Document No plus at least one other masterlist field (e.g. Title, Effectivity Date, Pages, Keywords, Originator, or Source Unit).',
            });
            return;
        }
    } else if (!masterlistHasData()) {
        errors.push({
            field: 'masterlistDocNo',
            message: 'Masterlist Registration needs data (Document No, Title, Effectivity Date, or a scanned master copy).',
        });
        return;
    }
    if (requireScan && !window.__isSyllabiMode && !hasMasterlistScannedCopy()) {
        errors.push({
            field: 'uploadScannedCopy',
            message: 'Upload the scanned master copy in Masterlist Registration before saving.',
            type: 'file',
        });
    }
    if (!forDraft) {
        const effectivity = (document.getElementById('masterlistEffectivityDate')?.value || '').trim();
        if (!effectivity) {
            errors.push({
                field: 'masterlistEffectivityDate',
                message: 'Effectivity Date is required.',
            });
        }
    }
}

/**
 * Toggles or switches section.
 *
 * @param {*} checklistId
 * @param {*} show
 */
window.toggleSection = function (checklistId, show) {
    if (checklistId === 1 && window.__isSyllabiMode) {
        const drfSection = document.getElementById('section-1');
        if (drfSection) drfSection.style.display = 'none';
        return;
    }
    // Retrieval is only for a Revise registration, not New or Recur.
    if (checklistId === 4 && !allowsDocumentRetrieval()) {
        const ret = document.getElementById('section-4');
        if (ret) ret.style.display = 'none';
        return;
    }
    const el = document.getElementById(SECTION_MAP[checklistId]);
    if (el) {
        el.style.display = show ? "block" : "none";
        if (show) setTimeout(initFileInputs, 50);
    }
    if (checklistId === 3) {
        const toggleBar = document.getElementById("section-approval-toggle");
        if (toggleBar) toggleBar.style.display = show ? "block" : "none";
        if (!show) {
            const approval = document.getElementById("section-approval");
            if (approval) approval.style.display = "none";
        } else {
            const applicable = document.querySelector('input[name="approval_status"][value="applicable"]');
            if (applicable?.checked) {
                const approval = document.getElementById("section-approval");
                if (approval) approval.style.display = "block";
            }
        }
    }
    if (checklistId === 3 && window.__isSyllabiMode) {
        const syllabiSection = document.getElementById("section-syllabi");
        if (syllabiSection) syllabiSection.style.display = show ? "block" : "none";
    }
};

/**
 * Handles approval toggle event or response.
 *
 * @param {*} applicable
 */
window.handleApprovalToggle = function (applicable) {
    const el = document.getElementById("section-approval");
    if (el) el.style.display = applicable ? "block" : "none";
};

/**
 * Applies deadline not applicable state.
 */
function applyDeadlineNotApplicableState() {
    const deadline = document.getElementById('deadlineOfSubmission');
    const na = document.getElementById('deadlineNotApplicable');
    if (!deadline || !na) return;
    if (na.checked) {
        deadline.value = '';
        deadline.disabled = true;
    } else {
        deadline.disabled = false;
    }
}

/**
 * Wires deadline not applicable.
 */
function wireDeadlineNotApplicable() {
    const na = document.getElementById('deadlineNotApplicable');
    if (!na || na.dataset.bound === '1') return;
    na.dataset.bound = '1';
    na.addEventListener('change', applyDeadlineNotApplicableState);
    applyDeadlineNotApplicableState();
}

/**
 * Wires approval deadline sync.
 */
function wireApprovalDeadlineSync() {
    const approval = document.getElementById('approvalDate');
    const deadline = document.getElementById('deadlineOfSubmission');
    const na = document.getElementById('deadlineNotApplicable');
    if (!approval || !deadline) return;
    let syncing = false;
    approval.addEventListener('change', () => {
        if (syncing || na?.checked) return;
        syncing = true;
        deadline.value = approval.value;
        syncing = false;
    });
    deadline.addEventListener('change', () => {
        if (syncing || na?.checked) return;
        syncing = true;
        approval.value = deadline.value;
        syncing = false;
    });
}

/**
 * Enables approval.
 */
function enableApproval() {
    document.querySelectorAll('input[name="approval_status"]').forEach(r => {
        r.disabled = false;
    });
    ['approvalBody', 'approvalDate', 'approvalNo'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.disabled = false;
    });
    const toggle = document.querySelector('.reg-approval-toggle');
    if (toggle) toggle.classList.remove('is-locked');
}

/**
 * Disables approval.
 */
function disableApproval() {
    document.querySelectorAll('input[name="approval_status"]').forEach(r => {
        r.disabled = true;
    });
    ['approvalBody', 'approvalDate', 'approvalNo'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.disabled = true;
    });
    const approval = document.getElementById("section-approval");
    if (approval) approval.style.display = "none";
    const toggleBar = document.getElementById("section-approval-toggle");
    if (toggleBar) toggleBar.style.display = "none";
}

// ══════════════════════════════════════════════
// SYLLABI CONTEXT DROPDOWNS
// ══════════════════════════════════════════════
async function loadSyllabiContextDropdowns() {
    try {
        const catalog = window.__registerCatalog || {};
        const colleges = catalog.colleges || [];
        const semesters = catalog.semesters || [];
        const schoolYears = catalog.schoolYears || [];
        const collegeSel = document.getElementById("syllabiCollege");
        const semSel = document.getElementById("syllabiSemester");
        const sySel = document.getElementById("syllabiSchoolYear");

        if (collegeSel && collegeSel.options.length <= 1) {
            colleges.forEach(c => collegeSel.add(new Option(c.college_name, c.college_id)));
        }
        if (semSel && semSel.options.length <= 1) {
            semesters.forEach(s => semSel.add(new Option(s.semester_name, s.semester_id)));
        }
        if (sySel && sySel.options.length <= 1) {
            schoolYears.forEach(y => sySel.add(new Option(y.school_year, y.school_year_id)));
        }
    } catch (err) {
        console.error("Failed to load syllabi context dropdowns:", err);
    }
}

const SYLLABI_YEAR_LEVELS = ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year'];

/**
 * Years level sort index.
 *
 * @param {*} year
 */
function yearLevelSortIndex(year) {
    const i = SYLLABI_YEAR_LEVELS.indexOf(year);
    return i === -1 ? 99 : i;
}

/**
 * Syllabis year level options html.
 *
 * @param {*} selected
 */
function syllabiYearLevelOptionsHtml(selected) {
    const sel = String(selected || '');
    const courseType = document.getElementById('syllabiCourseType')?.value || '';
    const fromCatalog = yearLevelsFromCourses(catalogCoursesForContext());
    const capped = allowedYearLevelsForCourseType(courseType);
    const levels = [...new Set(fromCatalog.filter((y) => capped.includes(y)))]
        .sort((a, b) => yearLevelSortIndex(a) - yearLevelSortIndex(b));
    const opts = levels.length ? levels : capped;
    return '<option value="">Year</option>' + opts.map((y) =>
        `<option value="${y}"${y === sel ? ' selected' : ''}>${y}</option>`
    ).join('');
}

function allowedYearLevelsForCourseType(type) {
    if (type === 'GE Courses') return ['1st Year', '2nd Year', '3rd Year'];
    if (type === 'PE Courses') return ['1st Year', '2nd Year'];
    if (type === 'NSTP') return ['1st Year'];
    return [...SYLLABI_YEAR_LEVELS];
}

function isInstitutionalSyllabiCourseType(type) {
    return false;
}

/** @type {Map<string, {program_id: string, program_name: string, program_code: string, college_id: string, college_name: string}>} */
window.__syllabiSelectedPrograms = window.__syllabiSelectedPrograms || new Map();
window.__syllabiCpActiveCollegeId = window.__syllabiCpActiveCollegeId || '';

function getSelectedSyllabiProgramIds() {
    return [...(window.__syllabiSelectedPrograms || new Map()).keys()];
}

function syncSyllabiProgramIdsHiddens() {
    const host = document.getElementById('syllabiProgramIdsHiddens');
    if (!host) return;
    host.innerHTML = getSelectedSyllabiProgramIds().map((id) =>
        `<input type="hidden" name="program_ids[]" value="${escapeHtml(id)}">`
    ).join('');
}

function syncSyllabiProgramIdMirror() {
    const programSel = document.getElementById('syllabiProgram');
    if (!programSel) return;
    const ids = getSelectedSyllabiProgramIds();
    const first = ids[0] || '';
    const meta = first ? window.__syllabiSelectedPrograms.get(first) : null;
    const collegeId = meta?.college_id || document.getElementById('syllabiCollege')?.value || '';
    if (first && ![...programSel.options].some((o) => String(o.value) === String(first))) {
        fillSyllabiProgramsForCollege(collegeId, first);
    }
    programSel.value = first;
}

function getSyllabiProgramsTitlePhrase() {
    return getSelectedSyllabiProgramIds().map((id) => {
        const p = window.__syllabiSelectedPrograms.get(id);
        if (!p) return '';
        return String(p.program_name || '') + (p.program_code ? ` (${p.program_code})` : '');
    }).filter(Boolean).join(', ');
}

function catalogCollegesList() {
    return ((window.__registerCatalog || {}).colleges || []).slice();
}

function catalogProgramsForCollege(collegeId) {
    return ((window.__registerCatalog || {}).programsByCollege || {})[String(collegeId)] || [];
}

function collegeNameById(collegeId) {
    const hit = catalogCollegesList().find((c) => String(c.college_id) === String(collegeId));
    return hit ? String(hit.college_name || '') : '';
}

function setSyllabiCpOpen(open) {
    const panel = document.getElementById('syllabiCpPanel');
    const trigger = document.getElementById('syllabiCpTrigger');
    if (!panel || !trigger) return;
    panel.hidden = !open;
    trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    trigger.classList.toggle('is-open', open);
}

/** Prefer the college of current program selections when opening the picker. */
function syllabiCpPreferredCollegeId() {
    return getSyllabiSelectedProgramCollegeId()
        || window.__syllabiCpActiveCollegeId
        || document.getElementById('syllabiCollege')?.value
        || '';
}

function setSyllabiCpTriggerEnabled(enabled) {
    const trigger = document.getElementById('syllabiCpTrigger');
    if (!trigger || syllabiContextFieldsLocked()) return;
    if (enabled) {
        trigger.disabled = false;
        trigger.removeAttribute('disabled');
        trigger.classList.remove('reg-field-locked');
    } else {
        trigger.disabled = true;
        setSyllabiCpOpen(false);
    }
}

function updateSyllabiCpTriggerLabel() {
    const labelEl = document.getElementById('syllabiCpTriggerLabel');
    const selectedWrap = document.getElementById('syllabiCpSelected');
    if (!labelEl) return;
    const selected = [...(window.__syllabiSelectedPrograms || new Map()).values()];
    // Chips stay hidden — unselect only via checkboxes inside the dropdown.
    if (selectedWrap) {
        selectedWrap.hidden = true;
        selectedWrap.innerHTML = '';
    }
    if (!selected.length) {
        labelEl.textContent = 'Select college / program';
        labelEl.title = '';
        return;
    }
    const text = selected.length === 1
        ? `${selected[0].college_name || 'College'} — ${selected[0].program_name || 'Program'}`
        : `${selected.length} programs selected`;
    labelEl.textContent = text;
    labelEl.title = text;
}

function renderSyllabiCpPrograms(collegeId) {
    const host = document.getElementById('syllabiCpPrograms');
    if (!host) return;
    window.__syllabiCpActiveCollegeId = String(collegeId || '');
    const programs = catalogProgramsForCollege(collegeId);
    const collegeName = collegeNameById(collegeId);
    if (!collegeId) {
        host.innerHTML = '<div class="reg-cp-programs-empty">Hover or click a college</div>';
        return;
    }
    if (!programs.length) {
        host.innerHTML = `<div class="reg-cp-programs-empty">No programs in ${escapeHtml(collegeName || 'this college')}</div>`;
        return;
    }
    const lockedCollegeId = getSyllabiSelectedProgramCollegeId();
    const lockedOut = !!(lockedCollegeId && String(collegeId) !== String(lockedCollegeId));
    const lockedName = lockedOut
        ? ([...window.__syllabiSelectedPrograms.values()].find((p) => String(p.college_id) === lockedCollegeId)?.college_name
            || collegeNameById(lockedCollegeId)
            || 'another college')
        : '';
    const note = lockedOut
        ? `<div class="reg-cp-programs-empty">Programs already selected from ${escapeHtml(lockedName)}. Uncheck those first to choose from this college.</div>`
        : '';
    host.innerHTML = `<div class="reg-cp-programs-heading">${escapeHtml(collegeName || 'Programs')}</div>${note}` + programs.map((p) => {
        const id = String(p.program_id);
        const checked = window.__syllabiSelectedPrograms.has(id) ? 'checked' : '';
        const label = String(p.program_name || '') + (p.program_code ? ` (${p.program_code})` : '');
        const disabledAttr = lockedOut ? 'disabled' : '';
        return `<label class="reg-cp-program${lockedOut ? ' is-locked-out' : ''}">
            <input type="checkbox" value="${escapeHtml(id)}" data-college-id="${escapeHtml(String(collegeId))}" data-college-name="${escapeHtml(collegeName)}" data-program-name="${escapeHtml(p.program_name || '')}" data-program-code="${escapeHtml(p.program_code || '')}" ${checked} ${disabledAttr}>
            <span>${escapeHtml(label)}</span>
        </label>`;
    }).join('');
    host.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
        cb.addEventListener('change', () => {
            toggleSyllabiProgramSelection(cb.value, cb.checked, {
                program_id: cb.value,
                program_name: cb.dataset.programName || '',
                program_code: cb.dataset.programCode || '',
                college_id: cb.dataset.collegeId || '',
                college_name: cb.dataset.collegeName || '',
            });
        });
    });
}

function renderSyllabiCpColleges(preferCollegeId = '') {
    const host = document.getElementById('syllabiCpColleges');
    if (!host) return;
    const colleges = catalogCollegesList();
    if (!colleges.length) {
        host.innerHTML = '<div class="reg-cp-programs-empty">No colleges found</div>';
        return;
    }
    const active = String(preferCollegeId || window.__syllabiCpActiveCollegeId || document.getElementById('syllabiCollege')?.value || colleges[0].college_id || '');
    host.innerHTML = colleges.map((c) => {
        const id = String(c.college_id);
        const count = catalogProgramsForCollege(id).length;
        const isActive = id === String(active) ? ' is-active' : '';
        return `<button type="button" class="reg-cp-college${isActive}" data-college-id="${escapeHtml(id)}">
            <span>${escapeHtml(c.college_name || ('College #' + id))}</span>
            <span class="reg-cp-college-count">${count}</span>
            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
        </button>`;
    }).join('');

    host.querySelectorAll('.reg-cp-college').forEach((btn) => {
        const id = btn.getAttribute('data-college-id');
        const activate = () => {
            host.querySelectorAll('.reg-cp-college').forEach((b) => b.classList.remove('is-active'));
            btn.classList.add('is-active');
            renderSyllabiCpPrograms(id);
        };
        btn.addEventListener('mouseenter', activate);
        btn.addEventListener('focus', activate);
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            activate();
        });
    });

    if (active) renderSyllabiCpPrograms(active);
    else {
        const programsHost = document.getElementById('syllabiCpPrograms');
        if (programsHost) programsHost.innerHTML = '<div class="reg-cp-programs-empty">Hover or click a college</div>';
    }
}

function getSyllabiSelectedProgramCollegeId() {
    for (const p of (window.__syllabiSelectedPrograms || new Map()).values()) {
        const cid = String(p.college_id || '').trim();
        if (cid) return cid;
    }
    return '';
}

function syllabiNotifyOneCollegeOnly(collegeName) {
    const msg = collegeName
        ? `Programs can only be selected from one college. Uncheck programs from ${collegeName} first.`
        : 'Programs can only be selected from one college.';
    if (typeof window.dcsShowToast === 'function') {
        window.dcsShowToast(msg, 'error', 'College / Program');
    } else if (typeof showToast === 'function') {
        showToast(msg, 'error');
    }
}

function toggleSyllabiProgramSelection(programId, selected, meta = null) {
    const id = String(programId || '');
    if (!id) return;
    if (!selected) {
        window.__syllabiSelectedPrograms.delete(id);
        onSyllabiProgramToggle();
        return;
    }

    const info = meta || window.__syllabiSelectedPrograms.get(id);
    if (!info) return;

    const nextCollegeId = String(info.college_id || '').trim();
    const lockedCollegeId = getSyllabiSelectedProgramCollegeId();
    if (lockedCollegeId && nextCollegeId && lockedCollegeId !== nextCollegeId) {
        const lockedName = [...window.__syllabiSelectedPrograms.values()]
            .find((p) => String(p.college_id) === lockedCollegeId)?.college_name
            || collegeNameById(lockedCollegeId)
            || 'another college';
        syllabiNotifyOneCollegeOnly(lockedName);
        const host = document.getElementById('syllabiCpPrograms');
        host?.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
            if (String(cb.value) === id) cb.checked = false;
        });
        return;
    }

    window.__syllabiSelectedPrograms.set(id, {
        program_id: id,
        program_name: info.program_name || '',
        program_code: info.program_code || '',
        college_id: nextCollegeId,
        college_name: info.college_name || collegeNameById(info.college_id),
    });
    onSyllabiProgramToggle();
}

function refreshSyllabiProgramPicker(collegeId, selectedIds = null) {
    if (Array.isArray(selectedIds)) {
        const next = new Map();
        let keepCollegeId = '';
        selectedIds.map(String).filter(Boolean).forEach((id) => {
            let meta = window.__syllabiSelectedPrograms.get(id) || null;
            if (!meta) {
                const colleges = catalogCollegesList();
                for (const c of colleges) {
                    const hit = catalogProgramsForCollege(c.college_id).find((p) => String(p.program_id) === id);
                    if (hit) {
                        meta = {
                            program_id: id,
                            program_name: hit.program_name || '',
                            program_code: hit.program_code || '',
                            college_id: String(c.college_id),
                            college_name: c.college_name || '',
                        };
                        break;
                    }
                }
            }
            if (!meta) return;
            const cid = String(meta.college_id || '');
            if (!keepCollegeId && cid) keepCollegeId = cid;
            if (keepCollegeId && cid && cid !== keepCollegeId) return; // one college only
            next.set(id, meta);
        });
        window.__syllabiSelectedPrograms = next;
    }

    if (!syllabiContextFieldsLocked()) {
        setSyllabiCpTriggerEnabled(syllabiCollegeProgramReady());
    }

    renderSyllabiCpColleges(collegeId || syllabiCpPreferredCollegeId());
    updateSyllabiCpTriggerLabel();
    syncSyllabiProgramIdsHiddens();
    syncSyllabiProgramIdMirror();
    wireSyllabiCpPickerOnce();
}

function wireSyllabiCpPickerOnce() {
    const trigger = document.getElementById('syllabiCpTrigger');
    const picker = document.getElementById('syllabiCollegeProgramPicker');
    if (!trigger || !picker || trigger.dataset.bound === '1') return;
    trigger.dataset.bound = '1';
    trigger.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (trigger.disabled || !syllabiCollegeProgramReady() || syllabiContextFieldsLocked()) return;
        const panel = document.getElementById('syllabiCpPanel');
        const willOpen = !!(panel && panel.hidden);
        if (willOpen) {
            renderSyllabiCpColleges(syllabiCpPreferredCollegeId());
        }
        setSyllabiCpOpen(willOpen);
    });
    document.addEventListener('click', (e) => {
        if (!picker.contains(e.target)) setSyllabiCpOpen(false);
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') setSyllabiCpOpen(false);
    });
}

function editSyllabiCtxStorageKey() {
    const reqId = document.getElementById('requestId')?.value || window.__excludeSyllabiRequestId || '';
    return reqId ? ('dcs_edit_syllabi_ctx_v1_' + String(reqId)) : '';
}

function persistEditSyllabiContextSelection() {
    if (syllabiContextFieldsLocked()) return;
    const key = editSyllabiCtxStorageKey();
    if (!key) return;
    try {
        const programs = [...(window.__syllabiSelectedPrograms || new Map()).values()];
        const payload = {
            college_id: document.getElementById('syllabiCollege')?.value || '',
            course_type: document.getElementById('syllabiCourseType')?.value || '',
            semester_id: document.getElementById('syllabiSemester')?.value || '',
            school_year_id: document.getElementById('syllabiSchoolYear')?.value || '',
            year_levels: typeof getSelectedSyllabiYearLevels === 'function' ? getSelectedSyllabiYearLevels() : [],
            programs,
        };
        sessionStorage.setItem(key, JSON.stringify(payload));
    } catch (_) {
        // ignore quota / private mode
    }
}

function readEditSyllabiContextSelection() {
    if (syllabiContextFieldsLocked()) return null;
    const key = editSyllabiCtxStorageKey();
    try {
        if (key) {
            const raw = sessionStorage.getItem(key);
            if (raw) {
                const parsed = JSON.parse(raw);
                if (parsed && typeof parsed === 'object') return parsed;
            }
        }
        // Fallback: draft just auto-saved from Create (/dcs/register) before redirecting to Edit.
        if (window.__isDraftDoc) {
            const createRaw = sessionStorage.getItem('dcs_reg_syllabi_ctx_v1');
            if (createRaw) {
                const parsed = JSON.parse(createRaw);
                const curSub = String((window.APP_CONFIG && window.APP_CONFIG.CURRENT_SUB_TYPE_ID) || '');
                if (parsed && typeof parsed === 'object' && (!parsed.sub_type_id || !curSub || String(parsed.sub_type_id) === curSub)) {
                    if (key) sessionStorage.setItem(key, JSON.stringify(parsed));
                    return parsed;
                }
            }
        }
        return null;
    } catch (_) {
        return null;
    }
}

function clearEditSyllabiContextSelection() {
    const key = editSyllabiCtxStorageKey();
    if (!key) return;
    try {
        sessionStorage.removeItem(key);
    } catch (_) {
        // ignore
    }
}

function syllabiRowHasUserEntry(firstRow) {
    if (!firstRow) return false;
    const avail = firstRow.querySelector('.syllabi-merged-availability');
    if (avail && avail.checked) return true;
    const copies = Number(firstRow.querySelector('.syllabi-merged-copies')?.value || 1);
    if (copies > 1) return true;
    const groupId = firstRow.dataset.group;
    const rows = groupId
        ? [...document.querySelectorAll(`#syllabiTableBody tr[data-group="${groupId}"]`)]
        : [firstRow];
    return rows.some((tr) => {
        const fac = (tr.querySelector('input[name="syllabiFaculty[]"]')?.value || '').trim();
        const pages = Number(tr.querySelector('input[name="syllabiNoPages[]"]')?.value || 0);
        const dateRec = (tr.querySelector('input[name="syllabiDateReceived[]"]')?.value || '').trim();
        const timeRec = (tr.querySelector('input[name="syllabiTimeReceived[]"]')?.value || '').trim();
        const drfAvail = !!tr.querySelector('.syllabi-drf-availability')?.checked;
        const drfNo = (tr.querySelector('input[name="syllabiDrfNo[]"]')?.value || '').trim();
        const drfDate = (tr.querySelector('input[name="syllabiDrfDate[]"]')?.value || '').trim();
        const drfRecv = (tr.querySelector('input[name="syllabiDrfReceived[]"]')?.value || '').trim();
        const scan = (tr.querySelector('input[name="syllabiExistingScannedDrf[]"]')?.value || '').trim();
        return !!(fac || pages > 0 || dateRec || timeRec || drfAvail || drfNo || drfDate || drfRecv || scan);
    });
}

function pruneUnselectedProgramSyllabiRows() {
    const tbody = document.getElementById('syllabiTableBody');
    if (!tbody) return;
    const selectedSet = new Set(getSelectedSyllabiProgramIds().map(String));
    [...tbody.querySelectorAll('tr[data-is-first="true"]')].forEach((row) => {
        const pid = String(row.dataset.programId || '').trim();
        if (!pid || selectedSet.has(pid)) return;
        if (syllabiRowHasUserEntry(row)) return;
        const groupId = row.dataset.group;
        if (groupId) {
            document.querySelectorAll(`#syllabiTableBody tr[data-group="${groupId}"]`).forEach((r) => {
                if (typeof removeSyllabiFacultyPicker === 'function') removeSyllabiFacultyPicker(r.dataset.uid);
                r.remove();
            });
        } else {
            row.remove();
        }
    });
}

function onSyllabiProgramToggle() {
    syncSyllabiProgramIdsHiddens();
    syncSyllabiProgramIdMirror();
    updateSyllabiCpTriggerLabel();
    setSyllabiCpTriggerEnabled(syllabiCollegeProgramReady());
    if (window.__syllabiCpActiveCollegeId) {
        renderSyllabiCpPrograms(window.__syllabiCpActiveCollegeId);
    }
    const semSel = document.getElementById('syllabiSemester');
    const hasPrograms = getSelectedSyllabiProgramIds().length > 0;
    if (semSel && !syllabiContextFieldsLocked()) {
        semSel.disabled = !hasPrograms;
        if (!hasPrograms) semSel.value = '';
    }
    syncSyllabiContextCascade();
    refreshSyllabiYearLevelDisplay(yearLevelsFromCourses(catalogCoursesForContext()));
    updateSyllabiTitle();
    syncSyllabiContextHidden();
    persistEditSyllabiContextSelection();
    if (typeof window.__regEditMarkDirty === 'function') {
        window.__regEditMarkDirty();
    }
    window.__facultiesCacheKey = null;
    reloadFacultiesForCollege();
    if (!hasPrograms) {
        clearSyllabiCourseRows();
        return;
    }
    pruneUnselectedProgramSyllabiRows();
    if (!syllabiContextFieldsLocked() && typeof syllabiContextComplete === 'function' && syllabiContextComplete()) {
        const tbody = document.getElementById('syllabiTableBody');
        const hasExistingRows = !!(tbody && tbody.querySelector('tr[data-is-first="true"]'));
        if (!hasExistingRows && typeof autoPopulateSyllabiCourses === 'function') {
            autoPopulateSyllabiCourses();
        } else if (typeof applySyllabiYearLevelFilterToTable === 'function') {
            applySyllabiYearLevelFilterToTable();
        }
    }
}

function syllabiCollegeProgramReady() {
    const collegeId = document.getElementById('syllabiCollege')?.value || '';
    const courseType = document.getElementById('syllabiCourseType')?.value || '';
    return !!(collegeId && courseType) && catalogCollegesList().length > 0;
}

function syncSyllabiContextCascade() {
    const collegeSel = document.getElementById('syllabiCollege');
    const courseTypeSel = document.getElementById('syllabiCourseType');
    const semSel = document.getElementById('syllabiSemester');
    const sySel = document.getElementById('syllabiSchoolYear');
    const locked = syllabiContextFieldsLocked();
    const hasCollege = !!(collegeSel && collegeSel.value);
    const hasType = !!(courseTypeSel && courseTypeSel.value);
    const hasPrograms = getSelectedSyllabiProgramIds().length > 0;
    const hasSemester = !!(semSel && semSel.value);
    if (courseTypeSel && !locked) {
        courseTypeSel.disabled = !hasCollege;
        if (!courseTypeSel.disabled) courseTypeSel.removeAttribute('disabled');
    }
    if (!locked) {
        setSyllabiCpTriggerEnabled(syllabiCollegeProgramReady());
        const labelEl = document.getElementById('syllabiCpTriggerLabel');
        if (labelEl && !getSelectedSyllabiProgramIds().length) {
            if (!hasCollege) labelEl.textContent = 'Select college first';
            else if (!hasType) labelEl.textContent = 'Select course type first';
            else labelEl.textContent = 'Select college / program';
        }
    }
    if (semSel && !locked) {
        semSel.disabled = !hasPrograms;
        if (!semSel.disabled) semSel.removeAttribute('disabled');
    }
    if (sySel && !locked) {
        sySel.disabled = !hasSemester;
        if (!sySel.disabled) sySel.removeAttribute('disabled');
    }
}

/**
 * Catalogs courses for context.
 */
function compareSyllabiCourseCode(a, b) {
    const ca = String(a || '');
    const cb = String(b || '');
    return ca.localeCompare(cb, undefined, { numeric: true, sensitivity: 'base' });
}

function catalogCoursesForContext() {
    const semesterId = document.getElementById('syllabiSemester')?.value;
    const courseType = document.getElementById('syllabiCourseType')?.value || '';
    const schoolYearId = document.getElementById('syllabiSchoolYear')?.value
        || document.getElementById('syllabiSchoolYearHidden')?.value
        || '';
    const programIds = getSelectedSyllabiProgramIds();
    if (!semesterId || !programIds.length) return [];
    const byKey = ((window.__registerCatalog || {}).coursesByProgramSemester || {});
    const merged = [];
    const seen = new Set();
    programIds.forEach((programId) => {
        const meta = (window.__syllabiSelectedPrograms || new Map()).get(String(programId)) || {};
        (byKey[programId + ':' + semesterId] || []).forEach((c) => {
            const dedupe = String(c.id || '') + '|' + String(c.course_name || '').toLowerCase() + '|' + String(programId);
            if (seen.has(dedupe)) return;
            seen.add(dedupe);
            if (courseType && String(c.course_type || '') !== courseType) return;
            // Only courses tagged with a School Year in Settings appear.
            // Rows with no school year are excluded from registration.
            const courseSy = Number(c.school_year_id || 0);
            if (courseSy <= 0) return;
            if (schoolYearId && String(courseSy) !== String(schoolYearId)) return;
            merged.push({
                ...c,
                program_id: String(programId),
                program_name: meta.program_name || c.program_name || '',
                program_code: meta.program_code || c.program_code || '',
            });
        });
    });
    merged.sort((a, b) => {
        const ai = programIds.indexOf(String(a.program_id));
        const bi = programIds.indexOf(String(b.program_id));
        if (ai !== bi) return ai - bi;
        const yi = yearLevelSortIndex(String(a.year_level || '').trim()) - yearLevelSortIndex(String(b.year_level || '').trim());
        if (yi !== 0) return yi;
        const byCode = compareSyllabiCourseCode(a.course_code, b.course_code);
        if (byCode !== 0) return byCode;
        return String(a.course_name || '').localeCompare(String(b.course_name || ''), undefined, { sensitivity: 'base' });
    });
    return merged;
}

/**
 * Years levels from courses.
 *
 * @param {*} courses
 */
function yearLevelsFromCourses(courses) {
    const seen = new Set();
    (courses || []).forEach((c) => {
        const y = String(c.year_level || '').trim();
        if (y) seen.add(y);
    });
    return [...seen].sort((a, b) => yearLevelSortIndex(a) - yearLevelSortIndex(b));
}

/**
 * Collects syllabi year levels from rows.
 */
function collectSyllabiYearLevelsFromRows() {
    const years = new Set();
    document.querySelectorAll('#syllabiTableBody .syllabi-merged-year').forEach((sel) => {
        const y = (sel.value || '').trim();
        if (y) years.add(y);
    });
    return [...years].sort((a, b) => yearLevelSortIndex(a) - yearLevelSortIndex(b));
}

/**
 * Retrieves and returns selected syllabi year levels.
 */
function getSelectedSyllabiYearLevels() {
    return [...document.querySelectorAll('#syllabiYearLevelPicker input[type="checkbox"]:checked')]
        .map((cb) => String(cb.value || '').trim())
        .filter(Boolean)
        .sort((a, b) => yearLevelSortIndex(a) - yearLevelSortIndex(b));
}

/**
 * Renders and synchronizes syllabi year level hiddens in the DOM.
 */
function syncSyllabiYearLevelHiddens() {
    const host = document.getElementById('syllabiYearLevelHiddens');
    if (!host) return;
    const list = getSelectedSyllabiYearLevels();
    host.innerHTML = list.map((y) =>
        `<input type="hidden" name="year_levels[]" value="${escapeHtml(y)}">`
    ).join('');
}

/** @param {string[]} available @param {string[]|null} selected null = select all available */
function refreshSyllabiYearLevelDisplay(available, selected = null) {
    const picker = document.getElementById('syllabiYearLevelPicker');
    if (!picker) return;

    const years = Array.isArray(available) ? available.filter(Boolean) : [];
    if (!years.length) {
        picker.classList.add('is-empty');
        const type = document.getElementById('syllabiCourseType')?.value || '';
        const institutional = isInstitutionalSyllabiCourseType(type);
        const hasScope = institutional
            ? !!document.getElementById('syllabiCollege')?.value
            : !!document.getElementById('syllabiProgram')?.value;
        picker.innerHTML = hasScope
            && document.getElementById('syllabiSemester')?.value
            && type
            ? (institutional
                ? 'No year levels in Settings for this course type and semester yet'
                : 'No year levels in Settings for this program yet')
            : (institutional
                ? 'Select course type, college, and semester'
                : 'Select course type, college/program, and semester');
        syncSyllabiYearLevelHiddens();
        return;
    }

    const previous = getSelectedSyllabiYearLevels();
    let chosen;
    if (Array.isArray(selected)) {
        chosen = selected.filter((y) => years.includes(y));
        if (!chosen.length) chosen = years.slice();
    } else if (previous.length) {
        chosen = previous.filter((y) => years.includes(y));
        if (!chosen.length) chosen = years.slice();
    } else {
        chosen = years.slice();
    }
    const chosenSet = new Set(chosen);

    picker.classList.remove('is-empty');
    picker.innerHTML = years.map((y) => `
        <label class="reg-syllabi-year-chip">
            <input type="checkbox" value="${escapeHtml(y)}" ${chosenSet.has(y) ? 'checked' : ''}>
            <span>${escapeHtml(y)}</span>
        </label>
    `).join('');

    picker.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
        cb.addEventListener('change', onSyllabiYearLevelToggle);
    });
    syncSyllabiYearLevelHiddens();
}

/**
 * Handles syllabi year level toggle event or response.
 */
function onSyllabiYearLevelToggle() {
    syncSyllabiYearLevelHiddens();
    applySyllabiYearLevelFilterToTable();
    persistEditSyllabiContextSelection();
}

/**
 * Locks syllabi year from settings.
 *
 * @param {*} sel
 */
function lockSyllabiYearFromSettings(sel) {
    if (!sel) return;
    const value = String(sel.value || '').trim();
    if (!value) return;
    sel.disabled = true;
    sel.removeAttribute('name');
    sel.classList.add('is-locked');
    const cell = sel.parentElement;
    if (!cell) return;
    let hidden = cell.querySelector('input.syllabi-year-posted');
    if (!hidden) {
        hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'syllabiYearLevel[]';
        hidden.className = 'syllabi-year-posted';
        cell.appendChild(hidden);
    }
    hidden.value = value;
}

/**
 * Appends syllabi catalog course row.
 *
 * @param {*} c
 */
function appendSyllabiCatalogCourseRow(c) {
    const tbody = document.getElementById('syllabiTableBody');
    if (!tbody || !c) return null;

    tbody.querySelectorAll('tr.syllabi-empty-hint').forEach((tr) => tr.remove());

    syllabiGroupCounter++;
    const groupId = 'g' + syllabiGroupCounter;
    const newRow = buildSyllabiGroupFirstRow(groupId, 1);
    tbody.appendChild(newRow);
    if (c.id != null && c.id !== '') {
        newRow.dataset.courseId = String(c.id);
    }
    if (c.program_id != null && c.program_id !== '') {
        newRow.dataset.programId = String(c.program_id);
        newRow.dataset.programName = String(c.program_name || '');
        newRow.dataset.programCode = String(c.program_code || '');
        const pidHidden = newRow.querySelector('.syllabi-merged-program-id');
        if (pidHidden) pidHidden.value = String(c.program_id);
    }

    fillSyllabiCatalogCourseFields(newRow, c);
    const yearSel = newRow.querySelector('.syllabi-merged-year');
    if (yearSel) {
        yearSel.value = c.year_level || '';
        lockSyllabiYearFromSettings(yearSel);
    }

    if (typeof cascadeDrfToNewRow === 'function') cascadeDrfToNewRow(newRow);
    syncSyllabiMergedFields(groupId);
    syncSyllabiAvailability(groupId);
    return newRow;
}

/** Write catalog course name/code into both the visible display and posted hidden inputs. */
function fillSyllabiCatalogCourseFields(row, c) {
    if (!row || !c) return;
    const name = String(c.course_name || c.name || '').trim();
    const code = String(c.course_code || c.code || '').trim();
    const courseHidden = row.querySelector('.syllabi-merged-course');
    const courseDisplay = row.querySelector('.syllabi-merged-course-display');
    const codeHidden = row.querySelector('.syllabi-merged-code');
    const codeDisplay = row.querySelector('.syllabi-merged-code-display');
    if (courseHidden) {
        courseHidden.value = name;
        courseHidden.dataset.autoFilled = 'true';
    }
    if (courseDisplay) {
        courseDisplay.value = name;
        courseDisplay.setAttribute('value', name);
        courseDisplay.title = name || 'From Settings → Course Names';
    }
    if (codeHidden) codeHidden.value = code;
    if (codeDisplay) {
        codeDisplay.value = code;
        codeDisplay.setAttribute('value', code);
        codeDisplay.title = code || 'Code';
    }
}

/** Hide/show a course group by year-level checkbox without destroying entered data. */
function setSyllabiGroupExcludedByYear(groupId, excluded) {
    if (!groupId) return;
    const rows = [...document.querySelectorAll(`#syllabiTableBody tr[data-group="${groupId}"]`)];
    rows.forEach((tr) => {
        if (excluded) {
            tr.classList.add('is-year-filtered-out');
            tr.hidden = true;
            if (tr.dataset.uid && typeof removeSyllabiFacultyPicker === 'function') {
                removeSyllabiFacultyPicker(tr.dataset.uid);
            }
            tr.querySelectorAll('input, select, textarea').forEach((el) => {
                if (el.dataset.yearFilterNameSaved !== undefined) return;
                el.dataset.yearFilterWasDisabled = el.disabled ? '1' : '0';
                if (el.name) {
                    el.dataset.yearFilterNameSaved = el.name;
                    el.removeAttribute('name');
                }
                el.disabled = true;
            });
            return;
        }
        tr.classList.remove('is-year-filtered-out');
        tr.hidden = false;
        tr.querySelectorAll('input, select, textarea').forEach((el) => {
            if (el.dataset.yearFilterNameSaved !== undefined) {
                el.setAttribute('name', el.dataset.yearFilterNameSaved);
                delete el.dataset.yearFilterNameSaved;
            }
            if (el.dataset.yearFilterWasDisabled === '0') {
                el.disabled = false;
                el.removeAttribute('disabled');
            }
            delete el.dataset.yearFilterWasDisabled;
        });
        const yearSel = tr.querySelector('.syllabi-merged-year');
        if (yearSel && yearSel.classList.contains('is-locked') && typeof lockSyllabiYearFromSettings === 'function') {
            lockSyllabiYearFromSettings(yearSel);
        }
    });
}

/**
 * Applies syllabi year level filter to table.
 */
function applySyllabiYearLevelFilterToTable() {
    const selected = new Set(getSelectedSyllabiYearLevels());
    const tbody = document.getElementById('syllabiTableBody');
    if (!tbody) return;

    // Keep course rows in the DOM — only exclude unchecked year levels from view/submit.
    [...tbody.querySelectorAll('tr[data-is-first="true"]')].forEach((row) => {
        const year = (row.querySelector('.syllabi-merged-year')?.value || '').trim();
        const groupId = row.dataset.group;
        if (!groupId) return;
        setSyllabiGroupExcludedByYear(groupId, !!(year && !selected.has(year)));
    });

    const existingKeys = new Set(
        [...tbody.querySelectorAll('tr[data-is-first="true"]')].map((row) => {
            const programId = String(row.dataset.programId || '').trim();
            const name = (row.querySelector('.syllabi-merged-course')?.value || '').trim().toLowerCase();
            const code = (row.querySelector('.syllabi-merged-code')?.value || '').trim().toLowerCase();
            const year = (row.querySelector('.syllabi-merged-year')?.value || '').trim();
            return `${programId}|${name}|${code}|${year}`;
        })
    );

    const toAdd = catalogCoursesForContext()
        .filter((c) => {
            const year = String(c.year_level || '').trim();
            if (!year || !selected.has(year)) return false;
            const pid = String(c.program_id || '').trim();
            const name = String(c.course_name || '').trim().toLowerCase();
            const code = String(c.course_code || '').trim().toLowerCase();
            const key = `${pid}|${name}|${code}|${year}`;
            const legacyKey = `|${name}|${code}|${year}`;
            return !existingKeys.has(key) && !existingKeys.has(legacyKey);
        })
        .sort((a, b) => {
            const yi = yearLevelSortIndex(String(a.year_level || '').trim()) - yearLevelSortIndex(String(b.year_level || '').trim());
            if (yi !== 0) return yi;
            return String(a.course_name || '').localeCompare(String(b.course_name || ''), undefined, { sensitivity: 'base' });
        });

    toAdd.forEach((c) => appendSyllabiCatalogCourseRow(c));

    tbody.querySelectorAll('tr.syllabi-empty-hint').forEach((tr) => tr.remove());
    const visibleFirst = tbody.querySelectorAll('tr[data-is-first="true"]:not(.is-year-filtered-out)');
    if (!visibleFirst.length) {
        if (selected.size === 0 && yearLevelsFromCourses(catalogCoursesForContext()).length) {
            const hint = document.createElement('tr');
            hint.className = 'syllabi-empty-hint';
            hint.innerHTML = '<td colspan="16">Select at least one year level above to include courses in this registration.</td>';
            tbody.appendChild(hint);
        } else if (!catalogCoursesForContext().length && !tbody.querySelector('tr[data-is-first="true"]')) {
            showSyllabiEmptyCatalogHint();
        }
    }

    refreshSyllabiYearSectionHeaders();
    if (typeof updateSyllabiTotals === 'function') updateSyllabiTotals();
    if (typeof applySyllabiSectionLabel === 'function') applySyllabiSectionLabel();
}

/**
 * Renders and synchronizes syllabi year levels from rows in the DOM.
 */
function refreshSyllabiYearLevelsFromRows() {
    syncSyllabiYearLevelHiddens();
    refreshSyllabiYearSectionHeaders();
}

/**
 * Handles syllabi row year changed event or response.
 *
 * @param {*} selectEl
 */
function onSyllabiRowYearChanged(selectEl) {
    if (!selectEl) return;
    selectEl.disabled = false;
    const groupId = selectEl.closest('tr')?.dataset.group;
    if (groupId && typeof syncSyllabiMergedFields === 'function') syncSyllabiMergedFields(groupId);
    const year = (selectEl.value || '').trim();
    if (year) {
        const box = document.querySelector('#syllabiYearLevelPicker input[type="checkbox"][value="' + CSS.escape(year) + '"]');
        if (box && !box.checked) {
            box.checked = true;
            syncSyllabiYearLevelHiddens();
        }
    }
    refreshSyllabiYearSectionHeaders();
}

/**
 * Syllabis table colspan.
 */
function syllabiTableColspan() {
    const ths = document.querySelectorAll('#syllabiWizardTable thead th');
    return ths.length || 16;
}

/**
 * Builds syllabi year header row.
 *
 * @param {*} year
 */
function buildSyllabiYearHeaderRow(year) {
    const tr = document.createElement('tr');
    tr.className = 'syllabi-year-header';
    tr.dataset.yearHeader = year || 'unassigned';
    const label = year || 'No year level';
    tr.innerHTML = `<td colspan="${syllabiTableColspan()}">Year Level · ${escapeHtml(label)}</td>`;
    return tr;
}

function buildSyllabiProgramHeaderRow(programId, programName, programCode) {
    const tr = document.createElement('tr');
    tr.className = 'syllabi-program-header';
    tr.dataset.programHeader = programId || 'unassigned';
    const name = String(programName || '').trim() || 'Program';
    const code = String(programCode || '').trim();
    const label = code ? `${name} (${code})` : name;
    tr.innerHTML = `<td colspan="${syllabiTableColspan()}">Program · ${escapeHtml(label)}</td>`;
    return tr;
}

/**
 * Renders section headers: Program → Year Level, courses ordered by course code.
 */
function refreshSyllabiYearSectionHeaders() {
    const tbody = document.getElementById('syllabiTableBody');
    if (!tbody) return;
    tbody.querySelectorAll('tr.syllabi-year-header, tr.syllabi-program-header').forEach((tr) => tr.remove());

    const firstRows = [...tbody.querySelectorAll('tr[data-is-first="true"]:not(.is-year-filtered-out)')];
    if (!firstRows.length) return;

    const selectedProgramIds = getSelectedSyllabiProgramIds();
    const groups = firstRows.map((first) => {
        const year = (first.querySelector('.syllabi-merged-year')?.value || '').trim();
        const code = (first.querySelector('.syllabi-merged-code')?.value || '').trim();
        const name = (first.querySelector('.syllabi-merged-course')?.value || '').trim();
        const programId = String(first.dataset.programId || '');
        const programName = String(first.dataset.programName || '');
        const programCode = String(first.dataset.programCode || '');
        const groupId = first.dataset.group;
        const rows = [...tbody.querySelectorAll(`tr[data-group="${groupId}"]`)]
            .sort((a, b) => (parseInt(a.dataset.copyNo, 10) || 0) - (parseInt(b.dataset.copyNo, 10) || 0));
        return { year, code, name, programId, programName, programCode, rows };
    });

    groups.sort((a, b) => {
        const ai = selectedProgramIds.indexOf(a.programId);
        const bi = selectedProgramIds.indexOf(b.programId);
        const aOrd = ai === -1 ? 9999 : ai;
        const bOrd = bi === -1 ? 9999 : bi;
        if (aOrd !== bOrd) return aOrd - bOrd;
        if (a.programName !== b.programName) {
            return a.programName.localeCompare(b.programName, undefined, { sensitivity: 'base' });
        }
        const yi = yearLevelSortIndex(a.year) - yearLevelSortIndex(b.year);
        if (yi !== 0) return yi;
        const byCode = compareSyllabiCourseCode(a.code, b.code);
        if (byCode !== 0) return byCode;
        return a.name.localeCompare(b.name, undefined, { sensitivity: 'base' });
    });

    const multiProgram = selectedProgramIds.length > 1
        || new Set(groups.map((g) => g.programId).filter(Boolean)).size > 1;
    let lastProgram = null;
    let lastYear = null;
    groups.forEach((g) => {
        const programKey = g.programId || g.programName || '';
        if (multiProgram && programKey !== lastProgram) {
            tbody.appendChild(buildSyllabiProgramHeaderRow(g.programId, g.programName, g.programCode));
            lastProgram = programKey;
            lastYear = null;
        }
        if (g.year !== lastYear) {
            tbody.appendChild(buildSyllabiYearHeaderRow(g.year));
            lastYear = g.year;
        }
        g.rows.forEach((row) => tbody.appendChild(row));
    });
}

/**
 * Syllabis context complete.
 * GE / PE / NSTP are college-scoped only (no program).
 */
function syllabiContextComplete() {
    const courseType = document.getElementById('syllabiCourseType')?.value || '';
    const programsOk = getSelectedSyllabiProgramIds().length > 0 || !!document.getElementById('syllabiProgram')?.value;
    return !!document.getElementById('syllabiCollege')?.value
        && programsOk
        && !!document.getElementById('syllabiSemester')?.value
        && !!courseType
        && !!document.getElementById('syllabiSchoolYear')?.value;
}

/**
 * Sets the syllabi context hint value.
 *
 * @param {*} message
 * @param {*} isError
 */
function setSyllabiContextHint(message, isError) {
    const hint = document.getElementById('syllabiContextHint');
    if (!hint) return;
    if (!message) {
        hint.style.display = 'none';
        hint.textContent = '';
        hint.classList.remove('is-error', 'is-ok');
        return;
    }
    hint.style.display = 'block';
    hint.textContent = message;
    hint.classList.toggle('is-error', !!isError);
    hint.classList.toggle('is-ok', !isError);
}

/**
 * Sets the syllabi context taken value.
 *
 * @param {*} taken
 * @param {*} message
 */
function setSyllabiContextTaken(taken, message) {
    window.syllabiContextTaken = !!taken;
    const actionBtns = [
        document.getElementById('btnUpdateDocument') || document.getElementById('btnSaveDocument'),
        document.getElementById('btnSaveAndAppraise'),
        document.getElementById('btnSaveDraft'),
    ];
    if (taken) {
        setSyllabiContextHint(message || 'This semester and school year are already registered.', true);
        actionBtns.forEach((btn) => {
            if (!btn) return;
            btn.disabled = true;
            btn.setAttribute('disabled', 'disabled');
            btn.style.opacity = '0.4';
            btn.style.cursor = 'not-allowed';
            btn.style.pointerEvents = 'none';
        });
        return;
    }
    setSyllabiContextHint('', false);
    actionBtns.forEach((btn) => {
        if (!btn) return;
        btn.disabled = false;
        btn.removeAttribute('disabled');
        btn.style.opacity = '';
        btn.style.cursor = '';
        btn.style.pointerEvents = '';
    });
}

/**
 * Validates and verifies syllabi context taken.
 */
async function checkSyllabiContextTaken() {
    if (!syllabiContextComplete()) {
        setSyllabiContextTaken(false);
        return false;
    }

    const params = new URLSearchParams({
        college_id: document.getElementById('syllabiCollege').value,
        program_id: document.getElementById('syllabiProgram').value,
        semester_id: document.getElementById('syllabiSemester').value,
        school_year_id: document.getElementById('syllabiSchoolYear').value,
        course_type: document.getElementById('syllabiCourseType').value,
    });
    const subType = document.getElementById('subType')?.value;
    if (subType) params.set('sub_type_id', subType);
    const excludeId = document.getElementById('requestId')?.value || window.__excludeSyllabiRequestId;
    if (excludeId) params.set('exclude_request_id', String(excludeId));

    try {
        const data = await fetch('/dcs/register/check-syllabi-context?' + params.toString(), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        }).then((r) => r.json());

        if (data.taken) {
            setSyllabiContextTaken(true, data.message || 'This semester and school year are already registered.');
            clearSyllabiCourseRows();
            return true;
        }

        setSyllabiContextTaken(false);
        return false;
    } catch (err) {
        console.error('Failed to check syllabi context:', err);
        setSyllabiContextTaken(false);
        return false;
    }
}

/**
 * Autos populate syllabi courses.
 */
async function autoPopulateSyllabiCourses() {
    if (!syllabiContextComplete()) return;
    const taken = await checkSyllabiContextTaken();
    if (taken) return;

    try {
        const allCourses = catalogCoursesForContext().slice();
        const availableYears = yearLevelsFromCourses(allCourses);
        refreshSyllabiYearLevelDisplay(availableYears, getSelectedSyllabiYearLevels());
        const selected = new Set(getSelectedSyllabiYearLevels());
        const courses = allCourses.filter((c) => {
            const year = String(c.year_level || '').trim();
            return !year || selected.has(year);
        });

        const tbody = document.getElementById('syllabiTableBody');
        if (!tbody) return;
        const hasManualData = [...tbody.querySelectorAll('.syllabi-merged-course, textarea.syllabi-merged-course')]
            .some(inp => inp.value.trim() !== '' && inp.dataset.autoFilled !== 'true');
        const hasIntakeStamp = !!tbody.querySelector('tr[data-intake-course="1"]');
        const hasExistingRows = !!tbody.querySelector('tr[data-is-first="true"]');
        // Never rebuild over an office-intake stamp, existing registration rows, or locked context.
        if (hasManualData || hasIntakeStamp || hasExistingRows || window.__officeIntakeSyllabiLocked) {
            if (typeof applySyllabiYearLevelFilterToTable === 'function') {
                applySyllabiYearLevelFilterToTable();
            }
            return;
        }

        if (!allCourses || allCourses.length === 0) {
            showSyllabiEmptyCatalogHint();
            return;
        }

        tbody.querySelectorAll('tr[data-uid]').forEach(tr => removeSyllabiFacultyPicker(tr.dataset.uid));
        tbody.innerHTML = '';
        syllabiGroupCounter = 0;

        if (!courses.length) {
            tbody.innerHTML = '<tr class="syllabi-empty-hint"><td colspan="16">Select at least one year level above to include courses in this registration.</td></tr>';
            updateSyllabiTotals();
            applySyllabiSectionLabel();
            refreshSyllabiYearSectionHeaders();
            return;
        }

        courses.forEach((c) => appendSyllabiCatalogCourseRow(c));
        updateSyllabiTotals();
        applySyllabiSectionLabel();
        refreshSyllabiYearLevelsFromRows();
        // Only lock when this is a registered/office-intake context — not after picking programs on a draft.
        if (syllabiContextFieldsLocked() && typeof lockSyllabiContextDropdowns === 'function') {
            lockSyllabiContextDropdowns();
        } else if (typeof syncSyllabiContextCascade === 'function') {
            syncSyllabiContextCascade();
        }
    } catch (err) {
        console.error('Failed to auto-populate syllabi courses:', err);
    }
}

/**
 * Displays the syllabi empty catalog hint overlay or dialog.
 */
function showSyllabiEmptyCatalogHint() {
    const tbody = document.getElementById('syllabiTableBody');
    if (!tbody) return;
    tbody.querySelectorAll('tr[data-uid]').forEach(tr => removeSyllabiFacultyPicker(tr.dataset.uid));
    tbody.innerHTML = '<tr class="syllabi-empty-hint"><td colspan="16">No courses in Settings for this program, semester, and course type. Add them under Settings → Course Names, or click Add Course.</td></tr>';
    refreshSyllabiYearLevelDisplay(yearLevelsFromCourses(catalogCoursesForContext()));
    syllabiGroupCounter = 0;
    if (typeof updateSyllabiTotals === 'function') updateSyllabiTotals();
}

/**
 * Applies catalog faculties to row.
 *
 * @param {*} row
 * @param {*} faculties
 * @param {*} options
 */
function applyCatalogFacultiesToRow(row, faculties, options = {}) {
    if (!row || !Array.isArray(faculties) || faculties.length === 0) return;
    const names = faculties.map(f => f.faculty_name || f.name).filter(Boolean);
    if (!names.length) return;
    row.dataset.catalogFaculty = JSON.stringify(names);
    row.dataset.catalogSplit = options.splitCopies ? '1' : '0';
    restoreCatalogFaculties(row);
}

/**
 * Clears and resets syllabi course rows.
 */
function clearSyllabiCourseRows() {
    if (window.__officeIntakeSyllabiLocked) return;
    const tbody = document.getElementById('syllabiTableBody');
    if (!tbody) return;
    tbody.querySelectorAll('tr[data-uid]').forEach(tr => removeSyllabiFacultyPicker(tr.dataset.uid));
    tbody.innerHTML = '';
    syllabiGroupCounter = 0;
    updateSyllabiTotals();
}

/** @deprecated Alias — institutional GE/PE/NSTP (no program). */
function isSharedSyllabiCourseType(type) {
    return isInstitutionalSyllabiCourseType(type);
}

function populateSyllabiCollegeOnlyCombo() {
    const combo = document.getElementById('syllabiCollegeProgramCombo');
    if (!combo) return;
    const colleges = (window.__registerCatalog || {}).colleges || [];
    combo.innerHTML = '<option value="" selected disabled>Select college</option>';
    colleges.forEach((c) => {
        const opt = new Option(c.college_name || ('College #' + c.college_id), String(c.college_id));
        opt.dataset.collegeId = String(c.college_id);
        opt.dataset.programId = '';
        combo.appendChild(opt);
    });
}

function populateSyllabiCollegeProgramCombo() {
    const combo = document.getElementById('syllabiCollegeProgramCombo');
    if (!combo) return;
    const catalog = window.__registerCatalog || {};
    const colleges = catalog.colleges || [];
    const byCollege = catalog.programsByCollege || {};
    combo.innerHTML = '<option value="" selected disabled>Select college / program</option>';
    colleges.forEach((c) => {
        const programs = byCollege[String(c.college_id)] || [];
        if (!programs.length) return;
        const group = document.createElement('optgroup');
        group.label = c.college_name || ('College #' + c.college_id);
        programs.forEach((p) => {
            const opt = new Option(p.program_name, String(c.college_id) + '|' + String(p.program_id));
            opt.dataset.collegeId = String(c.college_id);
            opt.dataset.programId = String(p.program_id);
            opt.dataset.code = p.program_code || '';
            group.appendChild(opt);
        });
        combo.appendChild(group);
    });
}

function fillSyllabiProgramsForCollege(collegeId, selectedProgramId = '') {
    const programSel = document.getElementById('syllabiProgram');
    if (!programSel) return;
    programSel.innerHTML = '<option value="" selected disabled>Select program</option>';
    const programs = ((window.__registerCatalog || {}).programsByCollege || {})[String(collegeId)] || [];
    programs.forEach((p) => {
        const opt = new Option(p.program_name, p.program_id);
        opt.dataset.code = p.program_code || '';
        if (String(p.program_id) === String(selectedProgramId)) opt.selected = true;
        programSel.add(opt);
    });
    programSel.disabled = !collegeId;
}

async function syncSyllabiCollegeProgramFromCombo() {
    const combo = document.getElementById('syllabiCollegeProgramCombo');
    const collegeSel = document.getElementById('syllabiCollege');
    const programSel = document.getElementById('syllabiProgram');
    const opt = combo?.selectedOptions?.[0];
    const collegeId = opt?.dataset?.collegeId || (isInstitutionalSyllabiCourseType(document.getElementById('syllabiCourseType')?.value || '') ? (combo?.value || '') : '');
    const programId = opt?.dataset?.programId || '';
    if (!collegeSel || !programSel) return;

    if (collegeSel.options.length <= 1) {
        await loadSyllabiContextDropdowns();
    }
    if (collegeId) {
        collegeSel.value = collegeId;
        collegeSel.disabled = false;
        if (isInstitutionalSyllabiCourseType(document.getElementById('syllabiCourseType')?.value || '')) {
            programSel.innerHTML = '<option value="" selected>All programs</option>';
            programSel.value = '';
            programSel.disabled = true;
        } else {
            fillSyllabiProgramsForCollege(collegeId, programId);
            if (programId) {
                programSel.value = programId;
                programSel.disabled = false;
            }
        }
        allFaculties = [];
        window.__facultiesCacheKey = null;
        await reloadFacultiesForCollege();
    } else {
        collegeSel.value = '';
        programSel.innerHTML = '<option value="" selected disabled>Select program</option>';
        programSel.disabled = true;
    }
}

function syllabiContextFieldsLocked() {
    return !!(window.__officeIntakeSyllabiLocked || window.__syllabiEditLocked);
}

function applySyllabiCourseTypeLayout(courseType) {
    const collegeId = document.getElementById('syllabiCollege')?.value || '';
    refreshSyllabiProgramPicker(collegeId, getSelectedSyllabiProgramIds());
    syncSyllabiContextCascade();
    void courseType;
}

/**
 * Initializes and sets up syllabi context wiring.
 */
async function initSyllabiContextWiring() {
    const collegeSel = document.getElementById("syllabiCollege");
    const semSel = document.getElementById("syllabiSemester");
    const courseTypeSel = document.getElementById("syllabiCourseType");
    const sySel = document.getElementById("syllabiSchoolYear");

    function resetDownstreamFrom(level) {
        if (syllabiContextFieldsLocked()) return;
        if (level <= 1 && collegeSel) collegeSel.value = '';
        if (level <= 2 && courseTypeSel) {
            courseTypeSel.value = '';
            courseTypeSel.disabled = !collegeSel?.value;
        }
        if (level <= 3) {
            refreshSyllabiProgramPicker(collegeSel?.value || '', []);
            setSyllabiCpOpen(false);
        }
        if (level <= 4 && semSel) { semSel.value = ''; semSel.disabled = true; }
        if (level <= 5 && sySel) { sySel.value = ''; sySel.disabled = true; }
        if (level <= 3) {
            syllabiTitleManuallyEdited = false;
            const titleInput = document.getElementById('syllabiDocTitle');
            if (titleInput) titleInput.value = '';
        }
        syncSyllabiContextCascade();
        updateSyllabiTitle();
        clearSyllabiCourseRows();
        refreshSyllabiYearLevelDisplay([]);
        syncSyllabiContextHidden();
        setSyllabiContextHint('', false);
    }

    if (collegeSel && !collegeSel.dataset.wired) {
        collegeSel.dataset.wired = 'true';
        collegeSel.addEventListener('change', async function () {
            resetDownstreamFrom(2);
            if (courseTypeSel) courseTypeSel.disabled = !this.value;
            refreshSyllabiProgramPicker(this.value, []);
            syncSyllabiContextCascade();
            syncSyllabiContextHidden();
            persistEditSyllabiContextSelection();
            window.__facultiesCacheKey = null;
            if (this.value) await reloadFacultiesForCollege();
        });
    }
    if (courseTypeSel && !courseTypeSel.dataset.wired) {
        courseTypeSel.dataset.wired = 'true';
        courseTypeSel.addEventListener('change', function () {
            resetDownstreamFrom(3);
            refreshSyllabiProgramPicker(collegeSel?.value || '', []);
            syncSyllabiContextCascade();
            syncSyllabiContextHidden();
            persistEditSyllabiContextSelection();
            window.__facultiesCacheKey = null;
        });
    }
    if (semSel && !semSel.dataset.wired) {
        semSel.dataset.wired = "true";
        semSel.addEventListener("change", function () {
            resetDownstreamFrom(5);
            if (sySel && !syllabiContextFieldsLocked()) sySel.disabled = !this.value;
            refreshSyllabiYearLevelDisplay(yearLevelsFromCourses(catalogCoursesForContext()));
            syncSyllabiContextCascade();
            window.__facultiesCacheKey = null;
            reloadFacultiesForCollege();
            syncSyllabiContextHidden();
            persistEditSyllabiContextSelection();
        });
    }
    if (sySel && !sySel.dataset.wired) {
        sySel.dataset.wired = "true";
        sySel.addEventListener("change", function () {
            updateSyllabiTitle();
            syncSyllabiContextHidden();
            persistEditSyllabiContextSelection();
            autoPopulateSyllabiCourses();
        });
    }

    if (typeof refreshSyllabiProgramPicker === 'function') {
        refreshSyllabiProgramPicker(collegeSel?.value || '', getSelectedSyllabiProgramIds());
    }
    if (typeof wireSyllabiCpPickerOnce === 'function') {
        wireSyllabiCpPickerOnce();
    }
    syncSyllabiContextCascade();
    if (syllabiContextFieldsLocked()) lockSyllabiContextDropdowns();
}

// ══════════════════════════════════════════════
// SYLLABI — AUTO-GENERATED DOCUMENT TITLE
// ══════════════════════════════════════════════
function formatSchoolYearText(text) {
    if (!text) return '';
    const m = text.match(/^(\d{4})\s*-\s*(\d{4})$/);
    if (m) return 'S/Y ' + m[1] + ' – ' + m[2];
    return /^s\/y/i.test(text) ? text : 'S/Y ' + text;
}

/**
 * Syllabis course type title phrase.
 *
 * @param {*} raw
 */
function syllabiCourseTypeTitlePhrase(raw) {
    const v = String(raw || '').trim();
    if (v === 'PE Courses') return 'P.E Courses';
    if (v === 'GE Courses') return 'GE Courses';
    if (v === 'NSTP') return 'NSTP';
    if (v === 'Major') return 'Major Courses';
    return v;
}

/**
 * Renders and synchronizes syllabi title in the DOM.
 */
function getSyllabiForCollegeTitlePhrase() {
    // "for …" uses college(s) from College / Program selections, not the first College field.
    const names = [];
    const seen = new Set();
    (window.__syllabiSelectedPrograms || new Map()).forEach((p) => {
        const name = String(p.college_name || '').trim();
        if (!name) return;
        const key = name.toLowerCase();
        if (seen.has(key)) return;
        seen.add(key);
        names.push(name);
    });
    return names.join(', ');
}

function updateSyllabiTitle() {
    const titleInput = document.getElementById('syllabiDocTitle');
    if (!titleInput || syllabiTitleManuallyEdited) return;
    // Lead college = first College (syllabi source).
    // "for …" college = College / Program selection(s).
    const college  = getSelectText('syllabiCollege');
    const forCollege = getSyllabiForCollegeTitlePhrase();
    const semester = getSelectText('syllabiSemester');
    const schoolYr = getSelectText('syllabiSchoolYear');
    const rawType = document.getElementById('syllabiCourseType')?.value || '';
    const courseType = syllabiCourseTypeTitlePhrase(rawType);
    const label    = window.__syllabiModeLabel || 'Syllabi';
    if (!college || !forCollege || !semester || !schoolYr || !courseType) {
        titleInput.value = '';
        syncSyllabiToMasterlistFields();
        return;
    }
    titleInput.value = college + ' ' + label + ' in ' + courseType + ' for ' + forCollege + ', ' + semester + ', ' + formatSchoolYearText(schoolYr);
    syncSyllabiToMasterlistFields();
}

/** Keep masterlist title in sync when in syllabi / TOS-Rubrics mode.
 *  Doc No / Effectivity / Deadline live only on Masterlist now. */
function syncSyllabiToMasterlistFields() {
    if (!window.__isSyllabiMode) return;

    const titleInput = document.getElementById('syllabiDocTitle');
    const mlTitle = document.getElementById('masterlistDocTitle');
    if (titleInput && mlTitle) mlTitle.value = titleInput.value;
}

/**
 * Wires syllabi masterlist sync.
 */
function wireSyllabiMasterlistSync() {
    const titleInput = document.getElementById('syllabiDocTitle');
    if (titleInput) {
        titleInput.addEventListener('input', () => {
            if (window.__isSyllabiMode) syncSyllabiToMasterlistFields();
        });
    }
}

// ══════════════════════════════════════════════
// SYLLABI — FACULTY PICKER
// ══════════════════════════════════════════════
function bindSyllabiFacultyReposition() {
    if (window.__syllabiFacultyRepositionBound) return;
    window.__syllabiFacultyRepositionBound = true;
    const repositionOpen = () => {
        document.querySelectorAll('[id^="syllabiFacultyDropdown_"]').forEach(dd => {
            if (dd.style.display !== 'block') return;
            const uid = dd.id.replace('syllabiFacultyDropdown_', '');
            const input = document.getElementById('syllabiFacultyInput_' + uid);
            if (input) positionFixedDropdown(dd, input);
        });
    };
    window.addEventListener('scroll', repositionOpen, true);
    window.addEventListener('resize', repositionOpen);
}

/**
 * Builds syllabi faculty cell html.
 *
 * @param {*} uid
 */
function buildSyllabiFacultyCellHTML(uid) {
    return `
        <div class="syllabi-faculty-wrap" id="syllabiFacultyWrap_${uid}" style="position:relative;">
            <input type="hidden" name="syllabiFaculty[]" id="syllabiFacultyHidden_${uid}">
            <input type="text" id="syllabiFacultyInput_${uid}" class="syllabi-faculty-input"
                placeholder="Search faculty name" autocomplete="off">
        </div>
    `;
}

/**
 * Initializes and sets up syllabi faculty picker.
 *
 * @param {*} uid
 * @param {*} mode
 * @param {*} rootEl
 */
function initSyllabiFacultyPicker(uid, mode, rootEl) {
    window.__syllabiFaculty[uid] = { mode, selected: [] };
    bindSyllabiFacultyInput(uid, rootEl);
    renderSyllabiFacultyChips(uid);
}

/**
 * Sets the syllabi faculty mode value.
 *
 * @param {*} uid
 * @param {*} mode
 */
function setSyllabiFacultyMode(uid, mode) {
    const rootEl = document.querySelector(`#syllabiTableBody tr[data-uid="${uid}"]`);
    if (!window.__syllabiFaculty[uid]) { initSyllabiFacultyPicker(uid, mode, rootEl); return; }
    window.__syllabiFaculty[uid].mode = mode;
    if (mode === 'single' && window.__syllabiFaculty[uid].selected.length > 1) {
        window.__syllabiFaculty[uid].selected = window.__syllabiFaculty[uid].selected.slice(0, 1);
    }
    bindSyllabiFacultyInput(uid, rootEl);
    renderSyllabiFacultyChips(uid);
}

/**
 * Retrieves and returns syllabi college id.
 */
function getSyllabiCollegeId() {
    const hidden = document.getElementById('syllabiCollegeHidden');
    if (hidden && hidden.value) return hidden.value;
    const select = document.getElementById('syllabiCollege');
    return select && select.value ? select.value : '';
}

/**
 * Retrieves and returns syllabi course type.
 */
function getSyllabiCourseType() {
    const hidden = document.getElementById('syllabiCourseTypeHidden');
    if (hidden && hidden.value) return hidden.value.trim();
    const select = document.getElementById('syllabiCourseType');
    return (select && select.value ? select.value : '').trim();
}

/**
 * Cas college id.
 */
function casCollegeId() {
    const colleges = (window.__registerCatalog || {}).colleges || [];
    const cas = colleges.find((c) => {
        const code = String(c.college_code || '').toUpperCase();
        const name = String(c.college_name || '');
        return code === 'CAS'
            || /college of arts and sciences/i.test(name)
            || /arts\s*&\s*sciences/i.test(name);
    });
    return cas ? String(cas.college_id) : '';
}

/**
 * Reloads faculties for college.
 */
async function reloadFacultiesForCollege() {
    window.__facultiesCacheKey = null;
    allFaculties = ((window.__registerCatalog || {}).faculties || []).slice();
    window.__facultiesCacheKey = 'all';
}

/**
 * Ensures faculty state.
 *
 * @param {*} uid
 */
function ensureFacultyState(uid) {
    if (window.__syllabiFaculty[uid]) return;
    const row = document.querySelector(`#syllabiTableBody tr[data-uid="${uid}"]`);
    let mode = 'multi';
    if (row) {
        const group = row.dataset.group;
        const firstRow = document.querySelector(`#syllabiTableBody tr[data-group="${group}"][data-is-first="true"]`);
        const copies = parseInt(firstRow?.querySelector('.syllabi-merged-copies')?.value || '1', 10);
        mode = copies > 1 ? 'single' : 'multi';
    }
    initSyllabiFacultyPicker(uid, mode, row);
}

/**
 * Ensures faculties loaded.
 */
async function ensureFacultiesLoaded() {
    if (window.__facultiesCacheKey === 'all' && Array.isArray(allFaculties) && allFaculties.length > 0) {
        return;
    }
    await reloadFacultiesForCollege();
}

/**
 * Faculties belongs to college.
 *
 * @param {*} f
 * @param {*} collegeId
 */
function facultyBelongsToCollege(f, collegeId) {
    if (!collegeId) return false;
    const want = String(collegeId);
    if (Array.isArray(f.college_ids) && f.college_ids.length) {
        return f.college_ids.some((id) => String(id) === want);
    }
    return String(f.college_id || '') === want;
}

/**
 * Retrieves and returns faculty candidates.
 */
function getFacultyCandidates() {
    if (!Array.isArray(allFaculties)) return [];
    const type = getSyllabiCourseType();
    if (type === 'GE Courses') {
        const casId = casCollegeId();
        const collegeId = getSyllabiCollegeId();
        return allFaculties.filter((f) =>
            (casId && facultyBelongsToCollege(f, casId))
            || (collegeId && facultyBelongsToCollege(f, collegeId))
        );
    }
    if (type === 'PE Courses' || type === 'NSTP' || type === 'Major') {
        const collegeId = getSyllabiCollegeId();
        if (!collegeId) return [];
        return allFaculties.filter((f) => facultyBelongsToCollege(f, collegeId));
    }
    return allFaculties;
}

/**
 * Faculties empty message.
 */
function facultyEmptyMessage() {
    const type = getSyllabiCourseType();
    if (type === 'GE Courses') {
        if (!casCollegeId()) return 'CAS faculty list is not set up yet';
        return 'No matching faculty in CAS or this college';
    }
    if (type === 'PE Courses' || type === 'NSTP' || type === 'Major') {
        return getSyllabiCollegeId()
            ? 'No matching faculty for this college'
            : 'Select a college first';
    }
    return 'No matching faculty';
}

/**
 * Retrieves and returns or create syllabi faculty dropdown.
 *
 * @param {*} uid
 */
function getOrCreateSyllabiFacultyDropdown(uid) {
    let dd = document.getElementById('syllabiFacultyDropdown_' + uid);
    if (!dd) {
        dd = document.createElement('div');
        dd.id = 'syllabiFacultyDropdown_' + uid;
        dd.className = 'reg-reldocs-dropdown syllabi-faculty-dropdown';
        dd.style.display = 'none';
        document.body.appendChild(dd);
    }
    return dd;
}

/**
 * Checks whether add more syllabi faculty condition is met.
 *
 * @param {*} state
 */
function canAddMoreSyllabiFaculty(state) {
    if (!state) return false;
    if (state.mode === 'single') return state.selected.length < 1;
    return state.selected.length < 2;
}

/**
 * Retrieves and returns syllabi faculty display value.
 *
 * @param {*} state
 */
function getSyllabiFacultyDisplayValue(state) {
    return state.selected.map(s => s.label).join(', ');
}

/**
 * Retrieves and returns syllabi faculty search query.
 *
 * @param {*} input
 * @param {*} state
 */
function getSyllabiFacultySearchQuery(input, state) {
    if (!input || !state) return '';

    const val = input.value;
    if (state.mode === 'multi' && state.selected.length > 0) {
        const lastComma = val.lastIndexOf(',');
        if (lastComma >= 0) return val.slice(lastComma + 1).trim();
        const display = getSyllabiFacultyDisplayValue(state);
        if (val.trim() === display) return '';
        return val.trim();
    }

    return val.trim();
}

/**
 * Renders and synchronizes syllabi faculty input display in the DOM.
 *
 * @param {*} uid
 * @param {*} force
 */
function syncSyllabiFacultyInputDisplay(uid, { focusForNext = false, force = false } = {}) {
    const state = window.__syllabiFaculty[uid];
    const hidden = document.getElementById('syllabiFacultyHidden_' + uid);
    const input = document.getElementById('syllabiFacultyInput_' + uid);
    if (!state || !hidden || !input) return;

    const display = getSyllabiFacultyDisplayValue(state);
    hidden.value = display;
    input.placeholder = state.mode === 'multi'
        ? 'Search faculty (shared copy)'
        : 'Search faculty name';

    if (focusForNext && canAddMoreSyllabiFaculty(state) && state.mode === 'multi' && state.selected.length > 0) {
        input.value = display ? `${display}, ` : '';
        input.focus();
        input.setSelectionRange(input.value.length, input.value.length);
        return;
    }

    if (force || document.activeElement !== input) {
        input.value = display;
    }
}

/**
 * Upserts a faculty into the in-memory catalog after Settings create/link.
 *
 * @param {*} faculty
 */
function upsertLocalFaculty(faculty) {
    if (!faculty || !faculty.faculty_name) return;
    if (!Array.isArray(allFaculties)) allFaculties = [];
    const id = faculty.id != null ? String(faculty.id) : '';
    const idx = allFaculties.findIndex((f) =>
        (id && String(f.id) === id)
        || String(f.faculty_name || '').toLowerCase() === String(faculty.faculty_name).toLowerCase()
    );
    const row = {
        id: faculty.id,
        faculty_name: faculty.faculty_name,
        college_ids: Array.isArray(faculty.college_ids) ? faculty.college_ids : [],
    };
    if (idx >= 0) {
        allFaculties[idx] = { ...allFaculties[idx], ...row };
    } else {
        allFaculties.push(row);
    }
    if (window.__registerCatalog) {
        window.__registerCatalog.faculties = allFaculties.slice();
    }
}

/**
 * Creates or links faculty in Settings for the selected college.
 *
 * @param {*} name
 */
async function ensureFacultyInSettings(name) {
    const want = String(name || '').trim();
    if (want.length < 2) {
        if (typeof showToast === 'function') showToast('Enter at least 2 characters for the faculty name.', 'error');
        return null;
    }
    const collegeId = getSyllabiCollegeId();
    if (!collegeId) {
        if (typeof showToast === 'function') showToast('Select a college first.', 'error');
        return null;
    }
    const token = document.querySelector('meta[name="csrf-token"]')?.content
        || document.querySelector('input[name="_token"]')?.value
        || '';
    const res = await fetch('/dcs/register/ensure-faculty', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': token,
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            faculty_name: want,
            college_id: Number(collegeId),
            course_type: getSyllabiCourseType() || '',
        }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok || !data.faculty) {
        if (typeof showToast === 'function') {
            showToast(data.message || 'Could not save faculty to Settings.', 'error');
        }
        return null;
    }
    upsertLocalFaculty(data.faculty);
    if (typeof showToast === 'function' && data.created) {
        showToast('Faculty added to Settings.', 'success');
    }
    return data.faculty;
}

/**
 * Ensures faculty exists in Settings, then selects it on the row.
 *
 * @param {*} uid
 * @param {*} name
 * @param {*} focusNext
 */
async function ensureAndAddSyllabiFaculty(uid, name, focusNext) {
    const want = String(name || '').trim();
    if (!want) return;
    const known = getFacultyCandidates().find((f) => f.faculty_name.toLowerCase() === want.toLowerCase())
        || (Array.isArray(allFaculties)
            ? allFaculties.find((f) => String(f.faculty_name || '').toLowerCase() === want.toLowerCase())
            : null);
    if (!known) {
        const saved = await ensureFacultyInSettings(want);
        if (!saved) return;
        addSyllabiFaculty(uid, saved.faculty_name || want, focusNext);
        return;
    }
    if (!getFacultyCandidates().some((f) => f.faculty_name.toLowerCase() === known.faculty_name.toLowerCase())) {
        await ensureFacultyInSettings(known.faculty_name);
    }
    addSyllabiFaculty(uid, known.faculty_name, focusNext);
}

/**
 * Picks syllabi faculty from query.
 *
 * @param {*} uid
 * @param {*} query
 */
async function pickSyllabiFacultyFromQuery(uid, query) {
    const candidates = getFacultyCandidates();
    const q = String(query || '').trim().toLowerCase();
    const state = window.__syllabiFaculty[uid];
    if (!state || !q) return;

    const exact = candidates.find(f => f.faculty_name.toLowerCase() === q);
    if (exact) {
        addSyllabiFaculty(uid, exact.faculty_name, true);
        return;
    }

    const partial = candidates.filter(f =>
        f.faculty_name.toLowerCase().includes(q) &&
        !state.selected.some(s => s.label.toLowerCase() === f.faculty_name.toLowerCase())
    );
    if (partial.length === 1) {
        addSyllabiFaculty(uid, partial[0].faculty_name, true);
        return;
    }

    await ensureAndAddSyllabiFaculty(uid, query, true);
}

/**
 * Binds and registers syllabi faculty input event listeners.
 *
 * @param {*} uid
 * @param {*} rootEl
 */
function bindSyllabiFacultyInput(uid, rootEl) {
    const input = (rootEl && rootEl.querySelector('#syllabiFacultyInput_' + uid))
        || document.getElementById('syllabiFacultyInput_' + uid);
    if (!input || input.dataset.bound) return;
    input.dataset.bound = 'true';

    input.addEventListener('input', () => {
        ensureFacultyState(uid);
        const state = window.__syllabiFaculty[uid];
        if (!state) return;

        if (input.value.trim() === '') {
            state.selected = [];
            syncSyllabiFacultyInputDisplay(uid);
            closeSyllabiFacultyDropdown(uid);
            return;
        }

        renderSyllabiFacultyDropdown(uid, input);
    });

    input.addEventListener('focus', () => {
        ensureFacultyState(uid);
        const state = window.__syllabiFaculty[uid];
        if (!state) return;

        if (canAddMoreSyllabiFaculty(state) && state.mode === 'multi' && state.selected.length > 0) {
            const display = getSyllabiFacultyDisplayValue(state);
            if (input.value.trim() === display) {
                input.value = `${display}, `;
                input.setSelectionRange(input.value.length, input.value.length);
            }
        }

        renderSyllabiFacultyDropdown(uid, input);
    });

    input.addEventListener('blur', () => {
        setTimeout(() => syncSyllabiFacultyInputDisplay(uid), 150);
    });

    input.addEventListener('keydown', (e) => {
        ensureFacultyState(uid);
        const state = window.__syllabiFaculty[uid];
        if (!state) return;

        if (e.key === 'Backspace') {
            const atEnd = input.selectionStart === input.value.length && input.selectionEnd === input.value.length;
            const query = getSyllabiFacultySearchQuery(input, state);
            if (state.selected.length > 0 && atEnd && !query) {
                e.preventDefault();
                if (state.mode === 'multi' && state.selected.length > 1) {
                    state.selected.pop();
                } else {
                    state.selected = [];
                }
                syncSyllabiFacultyInputDisplay(uid, {
                    focusForNext: state.mode === 'multi' && state.selected.length > 0,
                });
                closeSyllabiFacultyDropdown(uid);
                return;
            }
        }

        if (e.key !== 'Enter' && e.key !== ',') return;
        if (!canAddMoreSyllabiFaculty(state)) return;
        e.preventDefault();
        const val = getSyllabiFacultySearchQuery(input, state);
        if (!val) return;
        pickSyllabiFacultyFromQuery(uid, val);
    });

    bindSyllabiFacultyReposition();
}

/**
 * Renders and synchronizes syllabi faculty dropdown in the DOM.
 *
 * @param {*} uid
 * @param {*} input
 */
async function renderSyllabiFacultyDropdown(uid, input) {
    ensureFacultyState(uid);
    const state = window.__syllabiFaculty[uid];
    if (!state || !canAddMoreSyllabiFaculty(state)) {
        closeSyllabiFacultyDropdown(uid);
        return;
    }

    const dd = getOrCreateSyllabiFacultyDropdown(uid);
    const q = getSyllabiFacultySearchQuery(input, state).toLowerCase();
    if (q.length < 1) {
        dd.style.display = 'none';
        return;
    }

    positionFixedDropdown(dd, input);
    dd.style.zIndex = '100000';
    dd.innerHTML = '<div class="reg-reldocs-noresult">Loading faculty...</div>';
    dd.style.display = 'block';

    await ensureFacultiesLoaded();

    const usedOnSiblings = siblingFacultyNames(uid);
    const candidates = getFacultyCandidates();
    const matches = candidates.filter(f =>
        f.faculty_name.toLowerCase().includes(q) &&
        !state.selected.some(s => s.label.toLowerCase() === f.faculty_name.toLowerCase()) &&
        !usedOnSiblings.has(f.faculty_name.toLowerCase())
    );

    const typed = getSyllabiFacultySearchQuery(input, state).trim();
    const hasExact = matches.some((f) => f.faculty_name.toLowerCase() === typed.toLowerCase());
    let html = matches.map((f) =>
        `<div data-faculty-name="${escapeHtml(f.faculty_name)}">${escapeHtml(f.faculty_name)}</div>`
    ).join('');
    if (typed.length >= 2 && !hasExact && !usedOnSiblings.has(typed.toLowerCase())) {
        html += `<div class="reg-reldocs-add" data-add-faculty="${escapeHtml(typed)}">Add “${escapeHtml(typed)}” to Settings</div>`;
    }
    if (!html) {
        html = `<div class="reg-reldocs-noresult">${facultyEmptyMessage()}</div>`;
    }
    dd.innerHTML = html;

    dd.querySelectorAll('[data-faculty-name]').forEach(el => {
        el.addEventListener('mousedown', (ev) => {
            ev.preventDefault();
            addSyllabiFaculty(uid, el.dataset.facultyName, true);
        });
    });
    dd.querySelectorAll('[data-add-faculty]').forEach((el) => {
        el.addEventListener('mousedown', (ev) => {
            ev.preventDefault();
            ensureAndAddSyllabiFaculty(uid, el.dataset.addFaculty, true);
        });
    });

    positionFixedDropdown(dd, input);
    dd.style.display = 'block';
}

/**
 * Closes or dismisses the syllabi faculty dropdown.
 *
 * @param {*} uid
 */
function closeSyllabiFacultyDropdown(uid) {
    const dd = document.getElementById('syllabiFacultyDropdown_' + uid);
    if (dd) dd.style.display = 'none';
}

/**
 * Adds syllabi faculty.
 *
 * @param {*} uid
 * @param {*} name
 * @param {*} focusNext
 */
window.addSyllabiFaculty = function (uid, name, focusNext) {
    const state = window.__syllabiFaculty[uid];
    if (!state || !name) return;
    const row = document.querySelector(`#syllabiTableBody tr[data-uid="${uid}"]`);
    const want = String(name).trim();
    if (!want) return;

    // Prefer college-scoped candidates, then full catalog, then free-text (office originator).
    const match = getFacultyCandidates().find(f => f.faculty_name.toLowerCase() === want.toLowerCase())
        || (Array.isArray(allFaculties)
            ? allFaculties.find(f => String(f.faculty_name || '').toLowerCase() === want.toLowerCase())
            : null);
    const facultyName = match ? match.faculty_name : want;
    if (siblingFacultyNames(uid).has(facultyName.toLowerCase())) {
        if (typeof showToast === 'function') {
            showToast('This faculty is already assigned to another copy of this syllabi.', 'error');
        }
        return;
    }
    if (state.mode === 'single') {
        state.selected = [{ label: facultyName }];
    } else {
        if (state.selected.length >= 2) return;
        if (!state.selected.some(s => s.label.toLowerCase() === facultyName.toLowerCase())) {
            state.selected.push({ label: facultyName });
        }
    }
    closeSyllabiFacultyDropdown(uid);

    const focusForNext = !!focusNext && state.mode === 'multi' && canAddMoreSyllabiFaculty(state);
    syncSyllabiFacultyInputDisplay(uid, { focusForNext, force: true });
    if (row) fillReceivedFromGroup(row);
};

/**
 * Siblings faculty names.
 *
 * @param {*} uid
 */
function siblingFacultyNames(uid) {
    const used = new Set();
    const row = document.querySelector(`#syllabiTableBody tr[data-uid="${uid}"]`);
    if (!row) return used;
    const firstRow = document.querySelector(`#syllabiTableBody tr[data-group="${row.dataset.group}"][data-is-first="true"]`);
    const copies = parseInt(firstRow?.querySelector('.syllabi-merged-copies')?.value || '1', 10);
    if (copies < 2) return used;
    document.querySelectorAll(`#syllabiTableBody tr[data-group="${row.dataset.group}"]`).forEach(tr => {
        if (String(tr.dataset.uid) === String(uid)) return;
        const state = window.__syllabiFaculty[tr.dataset.uid];
        (state?.selected || []).forEach(s => {
            if (s.label) used.add(s.label.toLowerCase());
        });
    });
    return used;
}

/**
 * Renders and synchronizes syllabi faculty chips in the DOM.
 *
 * @param {*} uid
 */
function renderSyllabiFacultyChips(uid) {
    syncSyllabiFacultyInputDisplay(uid);
}

/**
 * Removes specified syllabi faculty picker.
 *
 * @param {*} uid
 */
function removeSyllabiFacultyPicker(uid) {
    delete window.__syllabiFaculty[uid];
    const dd = document.getElementById('syllabiFacultyDropdown_' + uid);
    if (dd) dd.remove();
}

document.addEventListener('click', function (e) {
    document.querySelectorAll('[id^="syllabiFacultyDropdown_"]').forEach(dd => {
        const uid = dd.id.replace('syllabiFacultyDropdown_', '');
        const wrap = document.getElementById('syllabiFacultyWrap_' + uid);
        const input = document.getElementById('syllabiFacultyInput_' + uid);
        const inside = (wrap && wrap.contains(e.target)) || dd.contains(e.target) || e.target === input;
        if (dd.style.display === 'block' && !inside) {
            dd.style.display = 'none';
        }
    });
});

/**
 * Builds syllabi faculty td.
 *
 * @param {*} uid
 * @param {*} mirrorHiddenHTML
 */
function buildSyllabiFacultyTd(uid, mirrorHiddenHTML = '') {
    return `<td class="col-shared">${mirrorHiddenHTML}${buildSyllabiFacultyCellHTML(uid)}</td>`;
}

// ══════════════════════════════════════════════
// SEED EXISTING SYLLABI GROUPS INTO THE WIZARD
// ══════════════════════════════════════════════
function syncSyllabiContextHidden() {
    const map = [
        ['syllabiCollege', 'syllabiCollegeHidden'],
        ['syllabiProgram', 'syllabiProgramHidden'],
        ['syllabiSemester', 'syllabiSemesterHidden'],
        ['syllabiCourseType', 'syllabiCourseTypeHidden'],
        ['syllabiSchoolYear', 'syllabiSchoolYearHidden'],
    ];
    map.forEach(([selId, hidId]) => {
        const sel = document.getElementById(selId);
        const hid = document.getElementById(hidId);
        if (!sel || !hid) return;
        // Always mirror, including empty program_id for institutional GE/PE/NSTP.
        hid.value = String(sel.value || '');
    });
}

/**
 * Retrieves and returns syllabi row for intake course.
 *
 * @param {*} prefill
 */
function findSyllabiRowForIntakeCourse(prefill) {
    const syl = prefill?.syllabi || {};
    const courseId = String(syl.course_id || '').trim();
    const courseName = String(syl.course_name || '').trim().toLowerCase();
    const rows = [...document.querySelectorAll('#syllabiTableBody tr[data-is-first="true"]')];
    if (!rows.length) return null;
    if (courseId) {
        const byId = rows.find((tr) => String(tr.dataset.courseId || '') === courseId);
        if (byId) return byId;
    }
    if (courseName) {
        const byName = rows.find((tr) => {
            const name = String(tr.querySelector('.syllabi-merged-course')?.value || '').trim().toLowerCase();
            return name === courseName;
        });
        if (byName) return byName;
    }
    return null;
}

/**
 * Ensures syllabi row for intake course.
 *
 * @param {*} prefill
 */
function ensureSyllabiRowForIntakeCourse(prefill) {
    let row = findSyllabiRowForIntakeCourse(prefill);
    if (row) return row;
    const syl = prefill?.syllabi || {};
    const courseId = String(syl.course_id || '').trim();
    const courseName = String(syl.course_name || '').trim().toLowerCase();
    if (!courseId && !courseName) return null;
    const catalog = typeof catalogCoursesForContext === 'function' ? catalogCoursesForContext() : [];
    const match = catalog.find((c) =>
        (courseId && String(c.id) === courseId)
        || (courseName && String(c.course_name || '').trim().toLowerCase() === courseName)
    );
    if (!match || typeof appendSyllabiCatalogCourseRow !== 'function') return null;
    return appendSyllabiCatalogCourseRow(match);
}

/**
 * Resolve display name for an office-originator faculty stamp.
 *
 * @param {string} name
 * @returns {string}
 */
function resolveSyllabiFacultyStampLabel(name) {
    const label = String(name || '').trim();
    if (!label) return '';
    const match = (typeof getFacultyCandidates === 'function' ? getFacultyCandidates() : [])
        .find((f) => String(f.faculty_name || '').toLowerCase() === label.toLowerCase())
        || (Array.isArray(window.allFaculties)
            ? window.allFaculties.find((f) => String(f.faculty_name || '').toLowerCase() === label.toLowerCase())
            : null)
        || (typeof allFaculties !== 'undefined' && Array.isArray(allFaculties)
            ? allFaculties.find((f) => String(f.faculty_name || '').toLowerCase() === label.toLowerCase())
            : null);
    return match ? String(match.faculty_name || label) : label;
}

/**
 * Whether a course-group row already has this faculty selected.
 *
 * @param {string} uid
 * @param {string} label
 * @returns {boolean}
 */
function syllabiRowHasFaculty(uid, label) {
    const want = String(label || '').trim().toLowerCase();
    if (!want || !uid) return false;
    const state = window.__syllabiFaculty?.[uid];
    if (state?.selected?.some((s) => String(s.label || '').toLowerCase() === want)) {
        return true;
    }
    const hidden = document.getElementById('syllabiFacultyHidden_' + uid);
    const raw = String(hidden?.value || '').toLowerCase();
    return raw !== '' && (raw === want || raw.split(/[,;|]/).map((p) => p.trim()).includes(want));
}

/**
 * Seed syllabi faculty from originator — append, never replace.
 * 1 copy (multi): add onto the shared row (max 2).
 * 2+ copies: place on the first empty copy row; leave filled rows alone.
 *
 * @param {*} name
 * @param {*} row
 */
function seedSyllabiFacultyFromOriginator(name, row) {
    const label = resolveSyllabiFacultyStampLabel(name);
    if (!label || !row) return;

    const groupId = row.dataset.group;
    const groupRows = groupId
        ? [...document.querySelectorAll(`#syllabiTableBody tr[data-group="${groupId}"]`)]
            .sort((a, b) => (parseInt(a.dataset.copyNo, 10) || 0) - (parseInt(b.dataset.copyNo, 10) || 0))
        : [row];
    const firstRow = groupRows[0] || row;
    const copies = Math.max(1, parseInt(firstRow.querySelector('.syllabi-merged-copies')?.value
        || firstRow.querySelector('input[name="syllabiCopies[]"]')?.value
        || String(groupRows.length), 10) || 1);

    if (groupRows.some((tr) => syllabiRowHasFaculty(tr.dataset.uid, label))) {
        return;
    }

    if (copies <= 1) {
        const uid = firstRow.dataset.uid;
        if (!uid) return;
        if (typeof ensureFacultyState === 'function') ensureFacultyState(uid);
        if (typeof setSyllabiFacultyMode === 'function') setSyllabiFacultyMode(uid, 'multi');
        const state = window.__syllabiFaculty[uid];
        if (!state) return;
        if (state.selected.length >= 2) return;
        if (typeof window.addSyllabiFaculty === 'function') {
            window.addSyllabiFaculty(uid, label, false);
        } else {
            state.selected.push({ label });
            if (typeof syncSyllabiFacultyInputDisplay === 'function') {
                syncSyllabiFacultyInputDisplay(uid, { force: true });
            }
        }
        if (typeof setSyllabiFacultyRowEnabled === 'function') {
            setSyllabiFacultyRowEnabled(firstRow, true);
        }
        return;
    }

    const empty = groupRows.find((tr) => {
        const uid = tr.dataset.uid;
        if (!uid) return false;
        if (typeof ensureFacultyState === 'function') ensureFacultyState(uid);
        const state = window.__syllabiFaculty[uid];
        return !(state?.selected?.length) && !syllabiRowHasFaculty(uid, label);
    });
    if (!empty) return;
    const uid = empty.dataset.uid;
    if (typeof ensureFacultyState === 'function') ensureFacultyState(uid);
    if (typeof setSyllabiFacultyMode === 'function') setSyllabiFacultyMode(uid, 'single');
    if (typeof window.addSyllabiFaculty === 'function') {
        window.addSyllabiFaculty(uid, label, false);
    } else {
        const state = window.__syllabiFaculty[uid];
        if (state) {
            state.selected = [{ label }];
            if (typeof syncSyllabiFacultyInputDisplay === 'function') {
                syncSyllabiFacultyInputDisplay(uid, { force: true });
            }
        }
    }
    if (typeof setSyllabiFacultyRowEnabled === 'function') {
        setSyllabiFacultyRowEnabled(empty, true);
    }
}

/**
 * Originator names to stamp for this office intake (self + peer pending DRFs).
 *
 * @param {*} prefill
 * @returns {string[]}
 */
function officeIntakeSyllabiOriginatorNames(prefill) {
    const names = [];
    const seen = new Set();
    const push = (raw) => {
        const n = String(raw || '').trim();
        if (!n) return;
        const key = n.toLowerCase();
        if (seen.has(key)) return;
        seen.add(key);
        names.push(n);
    };
    push(prefill?.originatorName);
    const peers = prefill?.syllabi?.peer_originators;
    if (Array.isArray(peers)) {
        peers.forEach(push);
    }
    return names;
}

/**
 * Persist peer intake ids so save can mark them registered with this pack.
 *
 * @param {*} prefill
 */
function seedOfficeIntakePeerIds(prefill) {
    const el = document.getElementById('officeIntakePeerIds');
    if (!el) return;
    const ids = Array.isArray(prefill?.peerIntakeIds)
        ? prefill.peerIntakeIds
        : (Array.isArray(prefill?.syllabi?.peer_intake_ids) ? prefill.syllabi.peer_intake_ids : []);
    const clean = [...new Set(ids.map((id) => parseInt(id, 10)).filter((id) => id > 0))];
    el.value = clean.join(',');
}

/**
 * Applies office intake syllabi drf defaults.
 *
 * @param {*} prefill
 */
function applyOfficeIntakeSyllabiDrfDefaults(prefill) {
    const syl = prefill?.syllabi || {};
    const drfDate = String(syl.drf_date || prefill?.drfDate || '').trim();
    const originators = officeIntakeSyllabiOriginatorNames(prefill);
    seedOfficeIntakePeerIds(prefill);
    const tr = ensureSyllabiRowForIntakeCourse(prefill) || findSyllabiRowForIntakeCourse(prefill);
    if (!tr) return;

    window.__syllabiIntakeStampLock = true;
    try {
        tr.dataset.intakeCourse = '1';

        document.querySelectorAll('#syllabiTableBody tr[data-is-first="true"]').forEach((other) => {
            if (other === tr) return;
            const cb = other.querySelector('.syllabi-drf-availability');
            if (!cb || !cb.checked) return;
            const date = String(other.querySelector('input[name="syllabiDrfDate[]"]')?.value || '').trim();
            const fac = String(document.getElementById('syllabiFacultyHidden_' + other.dataset.uid)?.value || '').trim();
            if (date || fac) return;
            cb.checked = false;
            if (typeof window.syncSyllabiDrfRow === 'function') window.syncSyllabiDrfRow(other);
        });

        const availCb = tr.querySelector('.syllabi-merged-availability');
        if (availCb && !availCb.checked) {
            availCb.checked = true;
            if (typeof window.syncSyllabiMergedFields === 'function') {
                window.syncSyllabiMergedFields(tr.dataset.group);
            }
            if (typeof window.syncSyllabiAvailability === 'function') {
                window.syncSyllabiAvailability(tr.dataset.group);
            }
        }

        const checkbox = tr.querySelector('.syllabi-drf-availability');
        if (checkbox) {
            checkbox.checked = true;
            if (typeof window.syncSyllabiDrfRow === 'function') {
                window.syncSyllabiDrfRow(tr);
            }
        }
        if (drfDate) {
            const dateInput = tr.querySelector('input[name="syllabiDrfDate[]"]');
            if (dateInput) {
                dateInput.disabled = false;
                dateInput.readOnly = false;
                dateInput.classList.remove('is-locked');
                dateInput.value = drfDate;
            }
        }
        originators.forEach((name) => seedSyllabiFacultyFromOriginator(name, tr));
    } finally {
        window.__syllabiIntakeStampLock = false;
    }
}

/** Same-context office intake → stamp only the request course on an existing registration. */
async function applyOfficeIntakeStampOnEdit() {
    const params = new URLSearchParams(location.search);
    const intake = (params.get('intake') || '').toLowerCase();
    const intakeId = params.get('intake_id');
    if (!intakeId || (intake !== 'drf' && intake !== 'dcn')) return;
    if (window.__officeIntakeEditStampApplied) return;
    window.__officeIntakeEditStampApplied = true;

    const typeEl = document.getElementById('officeIntakeType');
    const idEl = document.getElementById('officeIntakeId');
    if (typeEl) typeEl.value = intake;
    if (idEl) idEl.value = String(intakeId);

    try {
        const response = await fetch(
            '/dcs/api/office-intake/' + encodeURIComponent(intake) + '/' + encodeURIComponent(intakeId),
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            }
        );
        if (!response.ok) throw new Error('Failed to load intake');
        const data = await response.json();
        const prefill = data?.registerPrefill;
        if (!prefill || !(prefill.isSyllabiLike && prefill.syllabi)) return;
        window.__officeIntakePrefill = prefill;
        window.__officeIntakeSyllabiLocked = true;
        if (typeof lockSyllabiContextDropdowns === 'function') {
            lockSyllabiContextDropdowns();
        }
        // Do not change document title or Source Unit — only stamp the request course.
        applyOfficeIntakeSyllabiDrfDefaults(prefill);
        setTimeout(() => {
            applyOfficeIntakeSyllabiDrfDefaults(prefill);
            if (typeof lockSyllabiContextDropdowns === 'function') {
                lockSyllabiContextDropdowns();
            }
            if (typeof window.__regEditMarkDirty === 'function') {
                window.__regEditMarkDirty();
            }
        }, 300);
        if (typeof window.__regEditMarkDirty === 'function') {
            window.__regEditMarkDirty();
        }
    } catch (err) {
        console.warn('Office intake edit stamp failed:', err);
        window.__officeIntakeEditStampApplied = false;
    }
}

/**
 * Seed existing syllabi groups.
 */
function syllabiSeedGroupHasData(group) {
    if (!group) return false;
    if (group.availability) return true;
    if (group.no_pages !== null && group.no_pages !== undefined && String(group.no_pages) !== '' && Number(group.no_pages) > 0) {
        return true;
    }
    return (group.rows || []).some((r) => !!(
        r.faculty
        || r.date_received
        || r.time_received
        || r.drf_available
        || r.drf_no
        || r.drf_date
        || r.drf_received_date
        || r.scanned_drf
    ));
}

async function seedExistingSyllabiGroups() {
    const groups = window.__existingSyllabiGroups || [];
    await loadSyllabiContextDropdowns();

    const tbody = document.getElementById("syllabiTableBody");
    if (!tbody) return;
    tbody.innerHTML = "";
    syllabiGroupCounter = 0;

    const restoredDraftCtx = (window.__isDraftDoc && !window.__officeIntakeSyllabiLocked)
        ? readEditSyllabiContextSelection()
        : null;

    if (groups.length === 0) {
        if (restoredDraftCtx) {
            const collegeSel = document.getElementById("syllabiCollege");
            const semSel = document.getElementById("syllabiSemester");
            const courseTypeSel = document.getElementById("syllabiCourseType");
            const sySel = document.getElementById("syllabiSchoolYear");
            if (collegeSel && restoredDraftCtx.college_id) {
                collegeSel.value = String(restoredDraftCtx.college_id);
                collegeSel.disabled = false;
            }
            if (courseTypeSel && restoredDraftCtx.course_type) {
                courseTypeSel.value = String(restoredDraftCtx.course_type);
                courseTypeSel.disabled = false;
            }
            const restoredIds = Array.isArray(restoredDraftCtx.programs)
                ? restoredDraftCtx.programs.map((p) => String(p.program_id || '')).filter(Boolean)
                : [];
            refreshSyllabiProgramPicker(String(restoredDraftCtx.college_id || ''), restoredIds);
            syncSyllabiContextCascade();
            if (semSel && restoredDraftCtx.semester_id) {
                semSel.value = String(restoredDraftCtx.semester_id);
                semSel.disabled = false;
                semSel.removeAttribute('disabled');
            }
            if (sySel && restoredDraftCtx.school_year_id) {
                sySel.value = String(restoredDraftCtx.school_year_id);
                sySel.disabled = false;
                sySel.removeAttribute('disabled');
            }
            window.__syllabiEditLocked = false;
            unlockSyllabiContextDropdowns();
            syncSyllabiContextHidden();
            if (typeof refreshSyllabiYearLevelDisplay === 'function') {
                refreshSyllabiYearLevelDisplay(
                    yearLevelsFromCourses(catalogCoursesForContext()),
                    Array.isArray(restoredDraftCtx.year_levels) ? restoredDraftCtx.year_levels : null
                );
            }
            if (typeof updateSyllabiTitle === 'function') updateSyllabiTitle();
            if (restoredDraftCtx.college_id) await reloadFacultiesForCollege();
            setSyllabiStep(1);
            if (syllabiContextComplete() && typeof autoPopulateSyllabiCourses === 'function') {
                await autoPopulateSyllabiCourses();
            }
            return;
        }
        addSyllabiRow();
        setSyllabiStep(1);
        unlockSyllabiContextDropdowns();
        return;
    }

    const first = groups[0];
    const collegeSel = document.getElementById("syllabiCollege");
    const semSel = document.getElementById("syllabiSemester");
    const courseTypeSel = document.getElementById("syllabiCourseType");
    const sySel = document.getElementById("syllabiSchoolYear");

    const courseType = String((restoredDraftCtx?.course_type || first.course_type || '')).trim();
    const seedCollegeId = String(restoredDraftCtx?.college_id || first.college_id || '');
    if (collegeSel && seedCollegeId) {
        collegeSel.value = seedCollegeId;
        collegeSel.disabled = false;
    }
    if (courseTypeSel && courseType) {
        courseTypeSel.value = courseType;
        courseTypeSel.disabled = false;
    }
    const dbProgramIds = [...new Set(groups.map((g) => String(g.program_id || '')).filter(Boolean))];
    const restoredProgramIds = Array.isArray(restoredDraftCtx?.programs)
        ? restoredDraftCtx.programs.map((p) => String(p.program_id || '')).filter(Boolean)
        : [];
    const programIds = restoredProgramIds.length ? restoredProgramIds : dbProgramIds;
    const appendRaw = new URLSearchParams(window.location.search).get('append_program_ids') || '';
    appendRaw.split(',').map((s) => s.trim()).filter(Boolean).forEach((id) => {
        if (!programIds.includes(id)) programIds.push(id);
    });
    refreshSyllabiProgramPicker(seedCollegeId, programIds);
    syncSyllabiContextCascade();
    const seedSemesterId = String(restoredDraftCtx?.semester_id || first.semester_id || '');
    if (semSel && seedSemesterId) {
        semSel.value = seedSemesterId;
        semSel.disabled = false;
        semSel.removeAttribute('disabled');
    }
    const seedSchoolYearId = String(restoredDraftCtx?.school_year_id || first.school_year_id || '');
    if (sySel && seedSchoolYearId) {
        sySel.value = seedSchoolYearId;
        sySel.disabled = false;
        sySel.removeAttribute('disabled');
    }

    if (window.__isDraftDoc) {
        window.__syllabiEditLocked = false;
        unlockSyllabiContextDropdowns();
    } else {
        window.__syllabiEditLocked = true;
        lockSyllabiContextDropdowns();
    }
    syncSyllabiContextHidden();
    if (typeof refreshSyllabiYearLevelDisplay === 'function') {
        refreshSyllabiYearLevelDisplay(yearLevelsFromCourses(catalogCoursesForContext()));
    }
    // Re-assert after year chips render — layout/wiring must not re-enable context selects.
    if (!window.__isDraftDoc) {
        lockSyllabiContextDropdowns();
    }
    if (typeof updateSyllabiTitle === 'function') updateSyllabiTitle();
    if (first.college_id) await reloadFacultiesForCollege();

    // Exclude empty seeded rows whose course in Settings has no school year.
    const renderGroups = groups.filter((group) => {
        const hasCourseId = Number(group.course_id || 0) > 0;
        const courseSy = Number(group.course_school_year_id || 0);
        if (hasCourseId && courseSy <= 0 && !syllabiSeedGroupHasData(group)) {
            return false;
        }
        return true;
    });

    if (renderGroups.length === 0) {
        setSyllabiStep(1);
        if (typeof autoPopulateSyllabiCourses === 'function' && syllabiContextComplete()) {
            await autoPopulateSyllabiCourses();
        } else if (typeof showSyllabiEmptyCatalogHint === 'function') {
            showSyllabiEmptyCatalogHint();
        }
        return;
    }

    renderGroups.forEach(group => {
        syllabiGroupCounter++;
        const groupId = "g" + syllabiGroupCounter;
        const rowCount = Math.max(1, group.copies || group.rows?.length || 1);
        const firstRow = buildSyllabiGroupFirstRow(groupId, rowCount);
        tbody.appendChild(firstRow);
        if (group.course_id) {
            firstRow.dataset.courseId = String(group.course_id);
        }
        if (group.program_id) {
            const pid = String(group.program_id);
            const meta = (window.__syllabiSelectedPrograms || new Map()).get(pid) || {};
            firstRow.dataset.programId = pid;
            firstRow.dataset.programName = String(group.program_name || meta.program_name || '');
            firstRow.dataset.programCode = String(group.program_code || meta.program_code || '');
            const pidHidden = firstRow.querySelector('.syllabi-merged-program-id');
            if (pidHidden) pidHidden.value = pid;
        }

        const availCheckbox = firstRow.querySelector('.syllabi-merged-availability');
        const availHidden = firstRow.querySelector('.syllabi-merged-availability-hidden');
        const copiesInput = firstRow.querySelector('.syllabi-merged-copies');
        const pagesInput = firstRow.querySelector('.syllabi-merged-pages');

        fillSyllabiCatalogCourseFields(firstRow, {
            course_name: group.course_name || '',
            course_code: group.course_code || '',
        });
        const courseInput = firstRow.querySelector('.syllabi-merged-course');
        if (courseInput && typeof autosizeSyllabiCourse === 'function') autosizeSyllabiCourse(courseInput);
        const yearSel = firstRow.querySelector('.syllabi-merged-year');
        if (yearSel) {
            yearSel.value = group.year_level || '';
            lockSyllabiYearFromSettings(yearSel);
        }
        if (availCheckbox) { availCheckbox.checked = !!group.availability; if (availHidden) availHidden.value = group.availability ? 'available' : 'not available'; }
        if (copiesInput) copiesInput.value = rowCount;
        if (pagesInput) pagesInput.value = group.no_pages ?? group.rows?.[0]?.no_pages ?? '';

        let lastRow = firstRow;
        for (let i = 2; i <= rowCount; i++) {
            const contRow = buildSyllabiContinuationRow(groupId, i);
            lastRow.after(contRow);
            lastRow = contRow;
        }

        syncSyllabiMergedFields(groupId);

        const groupRows = [...document.querySelectorAll(`#syllabiTableBody tr[data-group="${groupId}"]`)]
            .sort((a, b) => parseInt(a.dataset.copyNo) - parseInt(b.dataset.copyNo));

        const mode = rowCount === 1 ? 'multi' : 'single';
        groupRows.forEach(row => setSyllabiFacultyMode(row.dataset.uid, mode));

        groupRows.forEach((tr, idx) => {
            const data = group.rows[idx];
            if (!data) return;

            setVal(tr, 'syllabiDateReceived[]', data.date_received);
            setVal(tr, 'syllabiTimeReceived[]', data.time_received);
            setVal(tr, 'syllabiDrfNo[]', data.drf_no);
            setVal(tr, 'syllabiDrfDate[]', data.drf_date);
            setVal(tr, 'syllabiDrfReceived[]', data.drf_received_date);

            if (data.faculty) {
                data.faculty.split(',').forEach(name => {
                    const trimmed = name.trim();
                    if (trimmed) window.addSyllabiFaculty(tr.dataset.uid, trimmed);
                });
            }

            const drfHidden = tr.querySelector('.syllabi-hidden-toggle[name="syllabiDrfAvailability[]"]');
            const drfCheckbox = tr.querySelector('.syllabi-drf-availability')
                || tr.querySelector('td.col-step2.syllabi-check-cell input[type="checkbox"]');
            if (drfCheckbox) {
                drfCheckbox.checked = !!data.drf_available;
                if (drfHidden) drfHidden.value = data.drf_available ? 'available' : 'not available';
            }
            syncSyllabiDrfRow(tr);

            const existingHidden = tr.querySelector('input[name="syllabiExistingScannedDrf[]"]');
            if (existingHidden && data.scanned_drf) existingHidden.value = data.scanned_drf;

            if (data.scanned_drf) {
                showExistingSyllabiScannedFile(tr, data.scanned_drf);
            }
        });

        syncSyllabiAvailability(groupId);
    });

    setSyllabiStep(1);
    updateSyllabiTotals();
    const availableYears = yearLevelsFromCourses(catalogCoursesForContext());
    const selectedYears = collectSyllabiYearLevelsFromRows();
    const restoredYears = Array.isArray(restoredDraftCtx?.year_levels) && restoredDraftCtx.year_levels.length
        ? restoredDraftCtx.year_levels
        : null;
    refreshSyllabiYearLevelDisplay(
        availableYears.length ? availableYears : selectedYears,
        restoredYears || (selectedYears.length ? selectedYears : null)
    );
    pruneUnselectedProgramSyllabiRows();
    if (!window.__officeIntakeSyllabiLocked && typeof syllabiContextComplete === 'function' && syllabiContextComplete()
        && typeof applySyllabiYearLevelFilterToTable === 'function') {
        applySyllabiYearLevelFilterToTable();
    } else {
        refreshSyllabiYearSectionHeaders();
    }
}

/**
 * Locks syllabi context dropdowns.
 */
function lockSyllabiContextDropdowns() {
    // Keep request context fixed (Course Type / College / Semester / School Year).
    // Year Level chips stay editable so courses can still be included/excluded.
    ['syllabiCollege', 'syllabiProgram', 'syllabiCollegeProgramCombo', 'syllabiSemester', 'syllabiCourseType', 'syllabiSchoolYear'].forEach((id) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.disabled = true;
        el.classList.add('reg-field-locked');
    });
    const cpTrigger = document.getElementById('syllabiCpTrigger');
    if (cpTrigger) {
        cpTrigger.disabled = true;
        cpTrigger.classList.add('reg-field-locked');
        if (typeof setSyllabiCpOpen === 'function') setSyllabiCpOpen(false);
    }
    if (typeof syncSyllabiContextHidden === 'function') {
        syncSyllabiContextHidden();
    }
    const titleInput = document.getElementById('syllabiDocTitle');
    if (titleInput && window.__officeIntakeSyllabiLocked) {
        titleInput.readOnly = true;
        titleInput.classList.add('reg-field-locked');
    }
    const yearPicker = document.getElementById('syllabiYearLevelPicker');
    if (yearPicker) {
        yearPicker.classList.remove('reg-field-locked');
        yearPicker.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
            cb.disabled = false;
            cb.removeAttribute('disabled');
        });
    }
}

/**
 * Unlocks syllabi context dropdowns.
 */
function unlockSyllabiContextDropdowns() {
    // Registered / office-intake context must stay locked (Year Level remains editable).
    if (syllabiContextFieldsLocked()) {
        lockSyllabiContextDropdowns();
        return;
    }

    const collegeSel = document.getElementById('syllabiCollege');
    const programSel = document.getElementById('syllabiProgram');
    const comboSel = document.getElementById('syllabiCollegeProgramCombo');
    const semSel = document.getElementById('syllabiSemester');
    const courseTypeSel = document.getElementById('syllabiCourseType');
    const sySel = document.getElementById('syllabiSchoolYear');
    const cpTrigger = document.getElementById('syllabiCpTrigger');
    const courseType = courseTypeSel?.value || '';
    const hasPrograms = typeof getSelectedSyllabiProgramIds === 'function'
        && getSelectedSyllabiProgramIds().length > 0;

    if (collegeSel) {
        collegeSel.disabled = false;
        collegeSel.removeAttribute('disabled');
        collegeSel.classList.remove('reg-field-locked');
    }
    if (courseTypeSel) {
        courseTypeSel.disabled = !(collegeSel && collegeSel.value);
        if (!courseTypeSel.disabled) courseTypeSel.removeAttribute('disabled');
        courseTypeSel.classList.remove('reg-field-locked');
    }
    if (cpTrigger) {
        cpTrigger.classList.remove('reg-field-locked');
        if (typeof setSyllabiCpTriggerEnabled === 'function') {
            setSyllabiCpTriggerEnabled(typeof syllabiCollegeProgramReady === 'function' && syllabiCollegeProgramReady());
        } else {
            const ready = typeof syllabiCollegeProgramReady === 'function' && syllabiCollegeProgramReady();
            cpTrigger.disabled = !ready;
            if (!cpTrigger.disabled) cpTrigger.removeAttribute('disabled');
        }
    }
    applySyllabiCourseTypeLayout(courseType);

    if (comboSel) {
        comboSel.disabled = !(collegeSel && collegeSel.value);
        if (!comboSel.disabled) comboSel.removeAttribute('disabled');
        comboSel.classList.remove('reg-field-locked');
    }
    if (programSel) {
        programSel.classList.remove('reg-field-locked');
    }
    if (semSel) {
        semSel.disabled = !hasPrograms;
        if (!semSel.disabled) semSel.removeAttribute('disabled');
        semSel.classList.remove('reg-field-locked');
    }
    if (sySel) {
        sySel.disabled = !(semSel && semSel.value);
        if (!sySel.disabled) sySel.removeAttribute('disabled');
        sySel.classList.remove('reg-field-locked');
    }
    if (typeof syncSyllabiContextCascade === 'function') {
        syncSyllabiContextCascade();
    }
}

/**
 * Sets the val value.
 *
 * @param {*} tr
 * @param {*} name
 * @param {*} value
 */
function setVal(tr, name, value) {
    if (value === null || value === undefined || value === '') return;
    const el = tr.querySelector(`[name="${name}"]`);
    if (el) el.value = value;
}

/**
 * Dcs scan href.
 *
 * @param {*} path
 */
function dcsScanHref(path) {
    if (!path) return '#';
    const normalized = String(path).replace(/^\/+/, '').replace(/^storage\//, '');
    if (normalized.startsWith('scans/')) {
        return '/storage/' + normalized;
    }
    return '/dcs/api/signed-scan-url?path=' + encodeURIComponent(normalized) + '&redirect=1';
}

/**
 * Displays the existing syllabi scanned file overlay or dialog.
 *
 * @param {*} tr
 * @param {*} path
 */
function showExistingSyllabiScannedFile(tr, path) {
    if (!tr || !path) return;
    const cell = tr.querySelector('.reg-upload-cell');
    if (!cell) return;

    const url = dcsScanHref(path);
    const ext = (String(path).split('.').pop() || '').toLowerCase();
    const isPdf = ext === 'pdf';
    const iconClass = isPdf ? 'fa-solid fa-file-pdf' : 'fa-solid fa-file-word';
    const label = isPdf ? 'View PDF' : 'View document';
    const linkClass = isPdf ? 'reg-upload-viewfile reg-revrow-viewfile-pdf' : 'reg-upload-viewfile reg-revrow-viewfile-doc';

    const wrap = document.createElement('div');
    wrap.className = 'reg-upload-cell reg-upload-cell-success';
    wrap.innerHTML =
        '<input type="file" name="syllabiScannedDrf[]" accept=".pdf" disabled style="display:none">' +
        '<a href="' + url + '" target="_blank" rel="noopener" class="' + linkClass + '" title="' + label + '">' +
            '<i class="' + iconClass + '"></i><span>' + label + '</span>' +
        '</a>';
    cell.replaceWith(wrap);
}

// ══════════════════════════════════════════════
// SYLLABI WIZARD — STEP NAVIGATION
// ══════════════════════════════════════════════
function setSyllabiStep(step) {
    step = Number(step) === 2 ? 2 : 1;
    syllabiCurrentStep = step;
    window.syllabiCurrentStep = step;
    if (setRegisterAlpineProp('syllabiStep', step, 'dcsEditRoot')) return;
    const retry = () => setRegisterAlpineProp('syllabiStep', step, 'dcsEditRoot');
    document.addEventListener('alpine:initialized', retry, { once: true });
    queueMicrotask(retry);
    setTimeout(retry, 0);
    setTimeout(retry, 50);
}

/**
 * Syllabis step next.
 */
window.syllabiStepNext = function () {
    if (syllabiCurrentStep < 2) setSyllabiStep(syllabiCurrentStep + 1);
};
/**
 * Syllabis step back.
 */
window.syllabiStepBack = function () {
    if (syllabiCurrentStep > 1) setSyllabiStep(syllabiCurrentStep - 1);
};

// ══════════════════════════════════════════════
// SYLLABI ROW BUILDER
// ══════════════════════════════════════════════
window.syncSyllabiDrfRow = function (tr) {
    if (!tr) return;
    const checkbox = tr.querySelector('.syllabi-drf-availability')
        || tr.querySelector('td.col-step2.syllabi-check-cell input[type="checkbox"]');
    const hidden = tr.querySelector('.syllabi-hidden-toggle[name="syllabiDrfAvailability[]"]');
    if (!checkbox || !hidden) return;

    hidden.value = checkbox.checked ? 'available' : 'not available';
    const enabled = checkbox.checked;

    // Use readOnly (not disabled) so empty DRF fields still POST and keep
    // syllabiDrfDate[] / syllabiDrfNo[] indexes aligned with course rows.
    tr.querySelectorAll('input[name="syllabiDrfNo[]"], input[name="syllabiDrfDate[]"], input[name="syllabiDrfReceived[]"]').forEach(el => {
        el.disabled = false;
        el.readOnly = !enabled;
        el.classList.toggle('is-locked', !enabled);
        if (!enabled) el.value = '';
    });

    const uploadCell = tr.querySelector('.reg-upload-cell');
    const fileInput = tr.querySelector('input[name="syllabiScannedDrf[]"]');
    if (fileInput) {
        fileInput.disabled = false;
        if (!enabled) {
            fileInput.value = '';
            if (uploadCell && !tr.querySelector('input[name="syllabiExistingScannedDrf[]"]')?.value) {
                uploadCell.classList.remove('reg-upload-cell-success');
                const span = uploadCell.querySelector('span');
                const icon = uploadCell.querySelector('i');
                if (span) span.textContent = 'No file chosen';
                if (icon) icon.className = 'fa-solid fa-cloud-arrow-up';
            }
        }
    }

    if (enabled) {
        const peer = findFirstFilledDrf(tr);
        if (peer) {
            stampEmpty(tr.querySelector('input[name="syllabiDrfDate[]"]'), peer.drfDate);
            stampEmpty(tr.querySelector('input[name="syllabiDrfReceived[]"]'), peer.drfReceived);
            propagateDrfToEmptyRows();
        }
    }
};

/**
 * Builds syllabi per row cells.
 *
 * @param {*} uid
 */
function buildSyllabiPerRowCells(uid) {
    return `
        <td class="col-step1 col-date"><input type="date" name="syllabiDateReceived[]" oninput="cascadeSyllabiReceived(this, 'syllabiDateReceived[]')"></td>
        <td class="col-step1 col-time"><input type="time" name="syllabiTimeReceived[]" oninput="cascadeSyllabiReceived(this, 'syllabiTimeReceived[]')"></td>

        <td class="col-step2 syllabi-check-cell">
            <input type="hidden" name="syllabiDrfAvailability[]" value="not available" class="syllabi-hidden-toggle">
            <input type="checkbox" class="syllabi-drf-availability" onchange="syncSyllabiDrfRow(this.closest('tr'))">
        </td>
        <td class="col-step2"><input type="text" name="syllabiDrfNo[]" placeholder="Enter DRF No." readonly class="is-locked"></td>
        <td class="col-step2"><input type="date" name="syllabiDrfDate[]" oninput="cascadeSyllabiField(this, 'syllabiDrfDate[]')" readonly class="is-locked"></td>
        <td class="col-step2"><input type="date" name="syllabiDrfReceived[]" oninput="cascadeSyllabiField(this, 'syllabiDrfReceived[]')" readonly class="is-locked"></td>
        <td class="col-step2">
            <input type="hidden" name="syllabiExistingScannedDrf[]" value="">
            <label class="reg-upload-cell">
                <input type="file" name="syllabiScannedDrf[]" accept=".pdf">
                <i class="fa-solid fa-cloud-arrow-up"></i>
                <span>No file chosen</span>
            </label>
        </td>
    `;
}

/** Set value only when the field is empty and editable. Never overwrites existing values. */
function stampEmpty(el, value) {
    if (!el || el.disabled || el.readOnly) return false;
    if ((el.value || '').trim() !== '') return false;
    if (value == null || String(value).trim() === '') return false;
    el.value = value;
    return true;
}

/**
 * Retrieves and returns first filled received.
 *
 * @param {*} excludeRow
 */
function findFirstFilledReceived(excludeRow) {
    const tbody = document.getElementById('syllabiTableBody');
    if (!tbody) return null;
    for (const tr of tbody.querySelectorAll('tr[data-uid]')) {
        if (excludeRow && tr === excludeRow) continue;
        const date = (tr.querySelector('input[name="syllabiDateReceived[]"]')?.value || '').trim();
        const time = (tr.querySelector('input[name="syllabiTimeReceived[]"]')?.value || '').trim();
        if (date || time) return { date, time, row: tr };
    }
    return null;
}

/**
 * Retrieves and returns first filled drf.
 *
 * @param {*} excludeRow
 */
function findFirstFilledDrf(excludeRow) {
    const tbody = document.getElementById('syllabiTableBody');
    if (!tbody) return null;
    for (const tr of tbody.querySelectorAll('tr[data-uid]')) {
        if (excludeRow && tr === excludeRow) continue;
        const drfDate = (tr.querySelector('input[name="syllabiDrfDate[]"]')?.value || '').trim();
        const drfReceived = (tr.querySelector('input[name="syllabiDrfReceived[]"]')?.value || '').trim();
        if (drfDate || drfReceived) return { drfDate, drfReceived, row: tr };
    }
    return null;
}

/**
 * Propagates received to empty rows.
 *
 * @param {*} source
 */
function propagateReceivedToEmptyRows(source) {
    const filled = source && (source.date || source.time)
        ? source
        : findFirstFilledReceived(source?.row || null);
    if (!filled || (!filled.date && !filled.time)) return;

    const tbody = document.getElementById('syllabiTableBody');
    if (!tbody) return;

    tbody.querySelectorAll('tr[data-uid]').forEach(tr => {
        if (filled.row && tr === filled.row) return;
        if (!syllabiGroupIsAvailable(tr.dataset.group)) return;
        if (filled.date) stampEmpty(tr.querySelector('input[name="syllabiDateReceived[]"]'), filled.date);
        if (filled.time) stampEmpty(tr.querySelector('input[name="syllabiTimeReceived[]"]'), filled.time);
    });
}

/**
 * Propagates drf to empty rows.
 *
 * @param {*} source
 */
function propagateDrfToEmptyRows(source) {
    if (window.__syllabiIntakeStampLock) return;
    const filled = source && (source.drfDate || source.drfReceived)
        ? source
        : findFirstFilledDrf(source?.row || null);
    if (!filled || (!filled.drfDate && !filled.drfReceived)) return;

    const tbody = document.getElementById('syllabiTableBody');
    if (!tbody) return;

    tbody.querySelectorAll('tr[data-uid]').forEach(tr => {
        if (filled.row && tr === filled.row) return;
        const cb = tr.querySelector('.syllabi-drf-availability');
        if (cb && !cb.checked) return;
        if (filled.drfDate) stampEmpty(tr.querySelector('input[name="syllabiDrfDate[]"]'), filled.drfDate);
        if (filled.drfReceived) stampEmpty(tr.querySelector('input[name="syllabiDrfReceived[]"]'), filled.drfReceived);
    });
}

/**
 * Stamps group received from peer.
 *
 * @param {*} groupId
 */
function stampGroupReceivedFromPeer(groupId) {
    stampAvailableRowsFromFirstFilled();
}

/**
 * Stamps available rows from first filled.
 */
function stampAvailableRowsFromFirstFilled() {
    const peer = findFirstFilledReceived();
    const date = (peer?.date || '').trim();
    const time = (peer?.time || '').trim();
    if (!date && !time) return;

    const tbody = document.getElementById('syllabiTableBody');
    if (!tbody) return;
    tbody.querySelectorAll('tr[data-uid]').forEach(tr => {
        if (peer.row && tr === peer.row) return;
        if (!syllabiGroupIsAvailable(tr.dataset.group)) return;
        if (date) stampEmpty(tr.querySelector('input[name="syllabiDateReceived[]"]'), date);
        if (time) stampEmpty(tr.querySelector('input[name="syllabiTimeReceived[]"]'), time);
    });
}

/**
 * Cascades syllabi field.
 *
 * @param {*} input
 * @param {*} fieldName
 */
window.cascadeSyllabiField = function (input, fieldName) {
    const sourceRow = input?.closest('tr');
    if (!sourceRow) return;
    const val = (input.value || '').trim();
    if (!val) return;

    const tbody = document.getElementById('syllabiTableBody');
    if (!tbody) return;

    tbody.querySelectorAll('tr[data-uid]').forEach(tr => {
        if (tr === sourceRow) return;
        const cb = tr.querySelector('.syllabi-drf-availability');
        if (cb && !cb.checked) return;
        stampEmpty(tr.querySelector(`input[name="${fieldName}"]`), val);
    });
};

/**
 * Cascades drf to new row.
 *
 * @param {*} newRow
 */
function cascadeDrfToNewRow(newRow) {
    if (!newRow) return;
    const peer = findFirstFilledDrf(newRow);
    if (!peer) return;
    stampEmpty(newRow.querySelector('input[name="syllabiDrfDate[]"]'), peer.drfDate);
    stampEmpty(newRow.querySelector('input[name="syllabiDrfReceived[]"]'), peer.drfReceived);
}

/**
 * Syllabis group is available.
 *
 * @param {*} groupId
 */
function syllabiGroupIsAvailable(groupId) {
    const firstRow = document.querySelector(`#syllabiTableBody tr[data-group="${groupId}"][data-is-first="true"]`);
    return !!firstRow?.querySelector('.syllabi-merged-availability')?.checked;
}

/**
 * Syllabis row has faculty.
 *
 * @param {*} tr
 */
function syllabiRowHasFaculty(tr) {
    const uid = tr?.dataset?.uid;
    const state = uid && window.__syllabiFaculty[uid];
    return !!(state && state.selected && state.selected.length > 0);
}

/**
 * Clears and resets syllabi faculty row.
 *
 * @param {*} tr
 */
function clearSyllabiFacultyRow(tr) {
    const uid = tr.dataset.uid;
    if (!window.__syllabiFaculty[uid]) return;
    window.__syllabiFaculty[uid].selected = [];
    syncSyllabiFacultyInputDisplay(uid, { force: true });
}

/**
 * Sets the syllabi faculty row enabled value.
 *
 * @param {*} tr
 * @param {*} enabled
 */
function setSyllabiFacultyRowEnabled(tr, enabled) {
    const input = tr.querySelector('.syllabi-faculty-input');
    if (input) {
        input.readOnly = false;
        if (tr.dataset.uid) syncSyllabiFacultyInputDisplay(tr.dataset.uid, { force: true });
    }
    tr.querySelectorAll('input[name="syllabiDateReceived[]"], input[name="syllabiTimeReceived[]"]').forEach(el => {
        el.readOnly = !enabled;
        el.classList.toggle('is-locked', !enabled);
        if (!enabled) el.value = '';
    });
}

/**
 * Restores catalog faculties.
 *
 * @param {*} firstRow
 */
function restoreCatalogFaculties(firstRow) {
    if (!firstRow) return;
    let names = [];
    try { names = JSON.parse(firstRow.dataset.catalogFaculty || '[]'); } catch (e) { names = []; }
    if (!names.length) return;

    const copiesInput = firstRow.querySelector('input[name="syllabiCopies[]"]');
    const copies = Math.max(1, parseInt(copiesInput?.value || '1', 10));
    const group = firstRow.dataset.group;

    const preferSplit = firstRow.dataset.catalogSplit === '1' || copies > 1;

    if (preferSplit && names.length >= 2) {
        const needed = Math.min(Math.max(copies, names.length > 2 ? names.length : 2), names.length);
        if (copiesInput && (parseInt(copiesInput.value, 10) || 1) !== needed) {
            copiesInput.value = String(needed);
            handleCopiesChange(copiesInput);
            return;
        }
        assignSyllabiFacultiesToGroup(group, names, needed);
        return;
    }

    if (!syllabiRowHasFaculty(firstRow)) {
        ensureFacultyState(firstRow.dataset.uid);
        setSyllabiFacultyMode(firstRow.dataset.uid, 'multi');
        names.slice(0, 2).forEach(name => addSyllabiFaculty(firstRow.dataset.uid, name, false));
    }
}

/**
 * Collects syllabi group faculty names.
 *
 * @param {*} groupId
 */
function collectSyllabiGroupFacultyNames(groupId) {
    const names = [];
    const seen = new Set();
    [...document.querySelectorAll(`#syllabiTableBody tr[data-group="${groupId}"]`)]
        .sort((a, b) => parseInt(a.dataset.copyNo) - parseInt(b.dataset.copyNo))
        .forEach(tr => {
            const state = window.__syllabiFaculty[tr.dataset.uid];
            (state?.selected || []).forEach(s => {
                const label = (s.label || '').trim();
                if (!label) return;
                const key = label.toLowerCase();
                if (seen.has(key)) return;
                seen.add(key);
                names.push(label);
            });
        });

    if (names.length) return names;

    const firstRow = document.querySelector(`#syllabiTableBody tr[data-group="${groupId}"][data-is-first="true"]`);
    try {
        return JSON.parse(firstRow?.dataset.catalogFaculty || '[]').filter(Boolean);
    } catch (e) {
        return [];
    }
}

/**
 * Sets the syllabi faculties to group value.
 *
 * @param {*} groupId
 * @param {*} names
 * @param {*} copies
 */
function assignSyllabiFacultiesToGroup(groupId, names, copies) {
    const rows = [...document.querySelectorAll(`#syllabiTableBody tr[data-group="${groupId}"]`)]
        .sort((a, b) => parseInt(a.dataset.copyNo) - parseInt(b.dataset.copyNo));
    if (!rows.length) return;

    const list = (names || []).map(n => String(n).trim()).filter(Boolean);
    const mode = copies <= 1 ? 'multi' : 'single';

    rows.forEach(tr => {
        ensureFacultyState(tr.dataset.uid);
        const state = window.__syllabiFaculty[tr.dataset.uid];
        if (state) state.selected = [];
        setSyllabiFacultyMode(tr.dataset.uid, mode);
    });

    if (mode === 'multi') {
        list.slice(0, 2).forEach(name => addSyllabiFaculty(rows[0].dataset.uid, name, false));
        return;
    }

    rows.forEach((tr, i) => {
        if (list[i]) addSyllabiFaculty(tr.dataset.uid, list[i], false);
    });
}

/**
 * Renders and synchronizes syllabi availability in the DOM.
 *
 * @param {*} groupId
 */
window.syncSyllabiAvailability = function (groupId) {
    const firstRow = document.querySelector(`#syllabiTableBody tr[data-group="${groupId}"][data-is-first="true"]`);
    if (!firstRow) return;
    const enabled = syllabiGroupIsAvailable(groupId);
    restoreCatalogFaculties(firstRow);
    document.querySelectorAll(`#syllabiTableBody tr[data-group="${groupId}"]`).forEach(tr => {
        setSyllabiFacultyRowEnabled(tr, enabled);
    });
    if (enabled) {
        stampAvailableRowsFromFirstFilled();
    }
};

/** When a date/time changes, copy into other empty available rows only — never overwrite. */
window.cascadeSyllabiReceived = function (input) {
    const sourceRow = input?.closest('tr');
    if (!sourceRow) return;
    const date = (sourceRow.querySelector('input[name="syllabiDateReceived[]"]')?.value || '').trim();
    const time = (sourceRow.querySelector('input[name="syllabiTimeReceived[]"]')?.value || '').trim();
    if (!date && !time) return;
    propagateReceivedToEmptyRows({ date, time, row: sourceRow });
};

/**
 * Populates received from group in the interface.
 *
 * @param {*} tr
 */
function fillReceivedFromGroup(tr) {
    if (!tr || !syllabiGroupIsAvailable(tr.dataset.group)) return;
    stampAvailableRowsFromFirstFilled();
}

/**
 * Autosizes syllabi course.
 *
 * @param {*} el
 */
function autosizeSyllabiCourse(el) {
    el.style.height = 'auto';
    el.style.height = el.scrollHeight + 'px';
}

/**
 * Builds syllabi group first row.
 *
 * @param {*} groupId
 * @param {*} rowspan
 */
function buildSyllabiGroupFirstRow(groupId, rowspan) {
    const tr = document.createElement("tr");
    tr.style.animation = "fadeSlideUp 0.25s ease";
    tr.dataset.group = groupId;
    tr.dataset.copyNo = 1;
    tr.dataset.isFirst = "true";
    const uid = ++syllabiRowUidCounter;
    tr.dataset.uid = uid;

    tr.innerHTML = `
        <td class="col-pinned" rowspan="${rowspan}">
            <input type="text" class="syllabi-merged-course-display" readonly disabled tabindex="-1"
                placeholder="Course name" title="From Settings → Course Names">
            <input type="hidden" name="syllabiCourseName[]" class="syllabi-merged-course"
                oninput="syncSyllabiMergedFields('${groupId}')">
            <input type="hidden" name="syllabiRowProgramId[]" class="syllabi-merged-program-id" value="">
            <select name="syllabiYearLevel[]" class="syllabi-merged-year" hidden aria-hidden="true" tabindex="-1"
                onchange="onSyllabiRowYearChanged(this)">
                ${syllabiYearLevelOptionsHtml('')}
            </select>
        </td>
        <td class="col-pinned col-code" rowspan="${rowspan}">
            <input type="text" class="syllabi-merged-code-display" readonly disabled tabindex="-1" placeholder="Code">
            <input type="hidden" name="syllabiCourseCode[]" class="syllabi-merged-code"
                oninput="syncSyllabiMergedFields('${groupId}')">
        </td>
        <td class="col-step1 col-avail" rowspan="${rowspan}">
            <input type="hidden" name="syllabiAvailability[]" value="not available" class="syllabi-merged-availability-hidden">
            <label class="reg-checkbox-wrap">
                <input type="checkbox" class="syllabi-merged-availability" onchange="syncSyllabiMergedFields('${groupId}'); syncSyllabiAvailability('${groupId}')">
            </label>
        </td>
        <td class="col-step1 col-copies" rowspan="${rowspan}">
            <input type="number" name="syllabiCopies[]" min="1" value="${rowspan}"
                class="syllabi-merged-copies" oninput="handleCopiesChange(this)">
        </td>
        ${buildSyllabiFacultyTd(uid)}
        <td class="col-step1 col-pages" rowspan="${rowspan}">
            <input type="number" name="syllabiNoPages[]" min="0" placeholder="0"
                class="syllabi-merged-pages" oninput="syncSyllabiMergedFields('${groupId}')">
        </td>
        ${buildSyllabiPerRowCells(uid)}
        <td class="col-pinned col-actions" rowspan="${rowspan}">
            <button type="button" class="reg-row-del" onclick="removeSyllabiGroup('${groupId}')" title="Remove course">
                <i class="fa-solid fa-trash-can"></i>
            </button>
        </td>
    `;

    bindSyllabiRowFileInputs(tr);
    initSyllabiFacultyPicker(uid, 'multi', tr);
    setSyllabiFacultyRowEnabled(tr, false);
    return tr;
}

/**
 * Builds syllabi continuation row.
 *
 * @param {*} groupId
 * @param {*} copyNo
 */
function buildSyllabiContinuationRow(groupId, copyNo) {
    const tr = document.createElement("tr");
    tr.style.animation = "fadeSlideUp 0.25s ease";
    tr.dataset.group = groupId;
    tr.dataset.copyNo = copyNo;
    const uid = ++syllabiRowUidCounter;
    tr.dataset.uid = uid;

    const mirrors = `
        <input type="hidden" name="syllabiCourseName[]" class="syllabi-mirror-course">
        <input type="hidden" name="syllabiRowProgramId[]" class="syllabi-mirror-program-id">
        <input type="hidden" name="syllabiCourseCode[]" class="syllabi-mirror-code">
        <input type="hidden" name="syllabiYearLevel[]" class="syllabi-mirror-year">
        <input type="hidden" name="syllabiAvailability[]" value="0" class="syllabi-mirror-availability">
        <input type="hidden" name="syllabiCopies[]" class="syllabi-mirror-copies">
        <input type="hidden" name="syllabiNoPages[]" class="syllabi-mirror-pages">
    `;

    tr.innerHTML = buildSyllabiFacultyTd(uid, mirrors) + buildSyllabiPerRowCells(uid);
    bindSyllabiRowFileInputs(tr);
    initSyllabiFacultyPicker(uid, 'single', tr);
    setSyllabiFacultyRowEnabled(tr, false);
    return tr;
}

/**
 * Renders and synchronizes syllabi merged fields in the DOM.
 *
 * @param {*} groupId
 */
window.syncSyllabiMergedFields = function (groupId) {
    const firstRow = document.querySelector(`#syllabiTableBody tr[data-group="${groupId}"][data-is-first="true"]`);
    if (!firstRow) return;

    const courseVal = firstRow.querySelector('.syllabi-merged-course')?.value || firstRow.querySelector('.syllabi-merged-course-display')?.value || '';
    const programIdVal = firstRow.querySelector('.syllabi-merged-program-id')?.value || firstRow.dataset.programId || '';
    const codeVal = firstRow.querySelector('.syllabi-merged-code')?.value || firstRow.querySelector('.syllabi-merged-code-display')?.value || '';
    const yearVal = firstRow.querySelector('.syllabi-merged-year')?.value || '';
    const pidHidden = firstRow.querySelector('.syllabi-merged-program-id');
    if (pidHidden && programIdVal) pidHidden.value = programIdVal;
    const availCheckbox = firstRow.querySelector('.syllabi-merged-availability');
    const availHidden = firstRow.querySelector('.syllabi-merged-availability-hidden');
    availHidden.value = availCheckbox.checked ? 'available' : 'not available';
    const copiesVal = firstRow.querySelector('.syllabi-merged-copies').value;
    const pagesVal = firstRow.querySelector('.syllabi-merged-pages')?.value || '';

    const courseDisplay = firstRow.querySelector('.syllabi-merged-course-display');
    const codeDisplay = firstRow.querySelector('.syllabi-merged-code-display');
    if (courseDisplay && courseVal) {
        courseDisplay.value = courseVal;
        courseDisplay.setAttribute('value', courseVal);
        courseDisplay.title = courseVal;
    }
    if (codeDisplay && codeVal) {
        codeDisplay.value = codeVal;
        codeDisplay.setAttribute('value', codeVal);
        codeDisplay.title = codeVal;
    }

    document.querySelectorAll(`#syllabiTableBody tr[data-group="${groupId}"]:not([data-is-first="true"])`)
        .forEach(row => {
            const mc = row.querySelector('.syllabi-mirror-course');
            const mpid = row.querySelector('.syllabi-mirror-program-id');
            const mcode = row.querySelector('.syllabi-mirror-code');
            const my = row.querySelector('.syllabi-mirror-year');
            const ma = row.querySelector('.syllabi-mirror-availability');
            const mp = row.querySelector('.syllabi-mirror-copies');
            const mpg = row.querySelector('.syllabi-mirror-pages');
            if (mc) mc.value = courseVal;
            if (mpid) mpid.value = programIdVal;
            if (mcode) mcode.value = codeVal;
            if (my) my.value = yearVal;
            if (ma) ma.value = availHidden.value;
            if (mp) mp.value = copiesVal;
            if (mpg) mpg.value = pagesVal;
        });

    updateSyllabiTotals();
};

/**
 * Adds syllabi row.
 */
window.addSyllabiRow = function () {
    const tbody = document.getElementById("syllabiTableBody");
    if (!tbody) return;
    tbody.querySelectorAll('tr.syllabi-empty-hint').forEach(tr => tr.remove());
    syllabiGroupCounter++;
    const newRow = buildSyllabiGroupFirstRow("g" + syllabiGroupCounter, 1);
    tbody.appendChild(newRow);
    cascadeDrfToNewRow(newRow);
    syncSyllabiAvailability(newRow.dataset.group);
    updateSyllabiTotals();
    applySyllabiSectionLabel();
    refreshSyllabiYearLevelsFromRows();
};

/**
 * Removes specified syllabi group.
 *
 * @param {*} groupId
 */
window.removeSyllabiGroup = function (groupId) {
    document.querySelectorAll(`#syllabiTableBody tr[data-group="${groupId}"]`).forEach(r => {
        removeSyllabiFacultyPicker(r.dataset.uid);
        r.remove();
    });
    updateSyllabiTotals();
    refreshSyllabiYearLevelsFromRows();
};

/**
 * Handles copies change event or response.
 *
 * @param {*} input
 */
window.handleCopiesChange = function (input) {
    const firstRow = input.closest("tr");
    const group = firstRow.dataset.group;
    const desired = Math.max(1, parseInt(input.value) || 1);
    input.value = desired;

    const preservedFaculty = collectSyllabiGroupFacultyNames(group);

    let groupRows = [...document.querySelectorAll(`#syllabiTableBody tr[data-group="${group}"]`)]
        .sort((a, b) => parseInt(a.dataset.copyNo) - parseInt(b.dataset.copyNo));

    if (desired > groupRows.length) {
        let lastRow = groupRows[groupRows.length - 1];
        for (let i = groupRows.length + 1; i <= desired; i++) {
            const newRow = buildSyllabiContinuationRow(group, i);
            lastRow.after(newRow);
            cascadeDrfToNewRow(newRow);
            lastRow = newRow;
        }
    } else if (desired < groupRows.length) {
        for (let i = groupRows.length; i > desired; i--) {
            removeSyllabiFacultyPicker(groupRows[i - 1].dataset.uid);
            groupRows[i - 1].remove();
        }
    }

    firstRow.querySelectorAll('[rowspan]').forEach(td => td.setAttribute('rowspan', desired));

    firstRow.dataset.catalogSplit = desired > 1 ? '1' : '0';
    assignSyllabiFacultiesToGroup(group, preservedFaculty, desired);

    syncSyllabiMergedFields(group);
    syncSyllabiAvailability(group);
    updateSyllabiTotals();
};

/**
 * Binds and registers syllabi row file inputs event listeners.
 *
 * @param {*} tr
 */
function bindSyllabiRowFileInputs(tr) {
    tr.querySelectorAll('.reg-upload-cell input[type="file"]').forEach(fileInput => {
        const cell = fileInput.closest('.reg-upload-cell');
        fileInput.dataset.bound = "true";
        fileInput.addEventListener('change', function () {
            if (this.files && this.files.length > 0) {
                const existing = tr.querySelector('input[name="syllabiExistingScannedDrf[]"]');
                if (existing) existing.value = '';
                const cb = tr.querySelector('.syllabi-drf-availability');
                if (cb && !cb.checked) {
                    cb.checked = true;
                    syncSyllabiDrfRow(tr);
                }
            }
            processUploadCellFile(this, cell);
        });
    });
    syncSyllabiDrfRow(tr);
}

// ══════════════════════════════════════════════
// SYLLABI TOTALS (copies + pages)
// ══════════════════════════════════════════════
function updateSyllabiTotals() {
    let totalCopies = 0;
    document.querySelectorAll('#syllabiTableBody tr:not(.is-year-filtered-out) .syllabi-merged-copies').forEach(inp => {
        totalCopies += parseInt(inp.value) || 0;
    });
    const copiesEl = document.getElementById('totalSyllabiCopies');
    if (copiesEl) copiesEl.textContent = totalCopies;

    let totalPages = 0;
    document.querySelectorAll('#syllabiTableBody tr:not(.is-year-filtered-out) .syllabi-merged-pages').forEach(inp => {
        totalPages += parseInt(inp.value) || 0;
    });
    const pagesEl = document.getElementById('totalSyllabiPages');
    if (pagesEl) pagesEl.textContent = totalPages;

    if (window.__isSyllabiMode) {
        const mlPages = document.getElementById('masterlistNoOfPages');
        if (mlPages) {
            mlPages.value = totalPages;
            mlPages.readOnly = true;
            mlPages.style.background = '#f8fafc';
            mlPages.style.cursor = 'not-allowed';
        }
    }
}

// ══════════════════════════════════════════════
// TIME SPENT
// ══════════════════════════════════════════════
function calcTimeDiff(sDateId, sTimeId, eDateId, eTimeId, dispId, hidId, excludeNonWorkingDays) {
    const sdEl = document.getElementById(sDateId);
    const stEl = document.getElementById(sTimeId);
    const edEl = document.getElementById(eDateId);
    const etEl = document.getElementById(eTimeId);
    const disp = document.getElementById(dispId);
    const hid = document.getElementById(hidId);
    if (!sdEl || !stEl || !edEl || !etEl || !disp || !hid) return;
    const sd = sdEl.value;
    const st = stEl.value;
    const ed = edEl.value;
    const et = etEl.value;
    const result = computeDuration(sd, st, ed, et, !!excludeNonWorkingDays);
    if (!result) { disp.value = "--"; disp.style.color = ""; hid.value = ""; return; }
    if (result.invalid) { disp.value = "Invalid"; disp.style.color = "var(--reg-error)"; hid.value = ""; return; }
    disp.style.color = "";
    disp.value = formatDuration(result.totalMinutes);
    hid.value = String(result.totalMinutes);
}

window.calcMasterlistTimeSpent = () => {
    calcTimeDiff("masterlistReceiptDate", "masterlistReceiptTime", "masterlistRegisteredDate", "masterlistRegisteredTime", "masterlistTimeSpentDisplay", "masterlistTimeSpent", true);
    // Distribution time spent also starts from masterlist document receipt.
    if (typeof window.calcDistributionTimeSpent === 'function') {
        window.calcDistributionTimeSpent();
    }
};
window.calcRetrievalTimeSpent = () => calcTimeDiff("retrievalFormDate", "retrievalFormTime", "retrievalDate", "retrievalTime", "retrievalTimeSpentDisplay", "retrievalTimeSpent", false);
window.calcDistributionTimeSpent = () => calcTimeDiff("masterlistReceiptDate", "masterlistReceiptTime", "distributionDate", "distributionTime", "distributionTimeSpentDisplay", "distributionTimeSpent", true);

/**
 * Generate stays disabled on drafts; enabled only after the document is registered.
 */
function syncGenerateDistributionButtonState() {
    const btn = document.getElementById('btnGenerateDistribution');
    if (!btn) return;
    const isDraft = !!window.__isDraftDoc;
    btn.disabled = isDraft;
    if (isDraft) {
        btn.setAttribute('disabled', 'disabled');
        btn.title = 'Register the document first before generating the distribution template.';
    } else {
        btn.removeAttribute('disabled');
        btn.title = 'Generate distribution template';
    }
}

/**
 * Shows a distribution-template validation notice (toast + focus office picker).
 */
function notifyDistributionTemplateBlocked(message, focusOffices) {
    if (typeof window.dcsShowToast === 'function') {
        window.dcsShowToast(message, 'error', 'Distribution template');
    } else if (typeof showToast === 'function') {
        showToast(message, 'error');
    }
    if (!focusOffices) return;
    const section = document.getElementById('section-5');
    const wrap = document.getElementById('distOfficeWrap');
    const search = document.getElementById('distSearch');
    if (section) section.scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (wrap) {
        wrap.classList.add('is-attention');
        clearTimeout(wrap.__attentionTimer);
        wrap.__attentionTimer = setTimeout(() => wrap.classList.remove('is-attention'), 2200);
    }
    if (search) {
        try { search.focus({ preventScroll: true }); } catch (_) { search.focus(); }
    }
}

/**
 * Generates distribution template.
 */
window.generateDistributionTemplate = function () {
    const btn = document.getElementById('btnGenerateDistribution');
    if (btn?.disabled || window.__isDraftDoc) {
        notifyDistributionTemplateBlocked(
            window.__isDraftDoc
                ? 'Register the document first before generating the distribution template.'
                : 'Save the document first before generating the distribution template.',
            false
        );
        return;
    }
    const offices = [];
    const copies = [];
    document.querySelectorAll('#distBody tr.reg-office-added').forEach((tr) => {
        const name = tr.querySelector('.reg-office-text')?.textContent.trim() || '';
        if (!name) return;
        const copyVal = tr.querySelector('input[name="distCopies[]"]')?.value || '';
        offices.push(name);
        copies.push(copyVal);
    });
    if (offices.length === 0) {
        notifyDistributionTemplateBlocked('Add at least one receiving office before generating the distribution template.', true);
        return;
    }
    // Always use Masterlist Registration fields for the printed form header/footer.
    const docTitle = (document.getElementById('masterlistDocTitle')?.value || '').trim()
        || (document.getElementById('syllabiDocTitle')?.value || '').trim();
    const effectivityDate = (document.getElementById('masterlistEffectivityDate')?.value || '').trim();
    const revisionNo = (document.getElementById('masterlistRevisionNo')?.value || '').trim()
        || (document.querySelector('input[name="masterlistRevisionNo"]')?.value || '').trim();

    const params = new URLSearchParams();
    params.set('date', document.getElementById('distributionDate')?.value || '');
    params.set('document_title', docTitle);
    params.set('effectivity_date', effectivityDate);
    params.set('revision_no', revisionNo);
    offices.forEach((name) => params.append('offices[]', name));
    copies.forEach((n) => params.append('copies[]', n));
    openDistributionPrintModal(params);
};

/**
 * Displays the distribution print modal.
 *
 * @param {*} params
 */
window.openDistributionPrintModal = function (params) {
    const overlay = document.getElementById('distributionPrintModal');
    const frame = document.getElementById('distributionPrintFrame');
    if (!overlay || !frame) return;
    params.set('embed', '1');
    frame.onload = function () {
        frame.onload = null;
        setTimeout(function () { window.printDistributionPreview(); }, 250);
    };
    frame.src = '{{ route('dcs.reports.distributionTemplate') }}?' + params.toString();
    overlay.classList.add('is-open');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
};

/**
 * Closes and hides the distribution print modal.
 */
window.closeDistributionPrintModal = function () {
    const overlay = document.getElementById('distributionPrintModal');
    const frame = document.getElementById('distributionPrintFrame');
    if (overlay) {
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
    }
    if (frame) {
        frame.onload = null;
        frame.src = 'about:blank';
    }
    document.body.style.overflow = '';
};

/**
 * Prints distribution preview.
 */
window.printDistributionPreview = function () {
    const frame = document.getElementById('distributionPrintFrame');
    try {
        frame?.contentWindow?.focus();
        frame?.contentWindow?.print();
    } catch (e) {
        alert('Could not open the print dialog.');
    }
};

/**
 * Renders and synchronizes dist cluster chips in the DOM.
 */
function renderDistClusterChips() {
    const wrap = document.getElementById('distClusterChips');
    if (!wrap) return;
    const clusters = (window.__registerCatalog || {}).clusters || [];
    wrap.innerHTML = clusters.map((c) => (
        '<button type="button" class="reg-cluster-chip" data-cluster="' + escapeHtml(c.cluster_code) + '" data-cluster-name="' + escapeHtml(c.cluster_name) + '">' +
        'Select all ' + escapeHtml(c.cluster_name) + '</button>'
    )).join('');
    wrap.querySelectorAll('.reg-cluster-chip').forEach((btn) => {
        btn.addEventListener('click', () => toggleOfficesByCluster(btn.getAttribute('data-cluster')));
    });
    syncDistClusterChipState();
}

/**
 * Retrieves and returns selected office ids.
 *
 * @param {*} bodyId
 */
function getSelectedOfficeIds(bodyId) {
    const tbody = document.getElementById(bodyId);
    if (!tbody) return [];
    return [...tbody.querySelectorAll('input[type="hidden"][name="distOffice[]"], input[type="hidden"][name="retrievalOffice[]"]')]
        .map((inp) => String(inp.value))
        .filter(Boolean);
}

/**
 * Clusters offices.
 *
 * @param {*} clusterCode
 */
function clusterOffices(clusterCode) {
    return allOffices.filter((o) => String(o.cluster) === String(clusterCode));
}

/**
 * Checks whether cluster fully selected condition is met.
 *
 * @param {*} clusterCode
 */
function isClusterFullySelected(clusterCode) {
    const offices = clusterOffices(clusterCode);
    if (!offices.length) return false;
    const selected = new Set(getSelectedOfficeIds('distBody'));
    return offices.every((o) => selected.has(String(o.office_id)));
}

/**
 * Adds offices by cluster.
 *
 * @param {*} clusterCode
 */
function addOfficesByCluster(clusterCode) {
    if (typeof window.__regEditMarkDirty === 'function') window.__regEditMarkDirty();
    clusterOffices(clusterCode).forEach((o) => {
        addOffice(o.office_id, o.office_name, 'distBody', 'distTotal', 'distResults');
    });
    syncDistClusterChipState();
    if (typeof window.__regEditMarkDirty === 'function') window.__regEditMarkDirty();
}

/**
 * Removes specified offices by cluster.
 *
 * @param {*} clusterCode
 */
function removeOfficesByCluster(clusterCode) {
    const tbody = document.getElementById('distBody');
    if (!tbody) return;

    if (typeof window.__regEditMarkDirty === 'function') window.__regEditMarkDirty();
    const ids = new Set(clusterOffices(clusterCode).map((o) => String(o.office_id)));
    tbody.querySelectorAll('tr.reg-office-added').forEach((tr) => {
        const inp = tr.querySelector('input[type="hidden"][name="distOffice[]"]');
        if (inp && ids.has(String(inp.value))) {
            maybeRestoreDistOfficeToRetrieval(tr);
            tr.remove();
        }
    });

    updateTotal('distTotal', 'distBody');
    if (tbody.querySelectorAll('tr.reg-office-added').length === 0) {
        tbody.innerHTML = emptyOfficeRowHTML('distBody');
    }
    syncDistClusterChipState();
    if (typeof window.__regEditMarkDirty === 'function') window.__regEditMarkDirty();
}

/**
 * Toggles or switches offices by cluster.
 *
 * @param {*} clusterCode
 */
function toggleOfficesByCluster(clusterCode) {
    if (typeof window.__regEditMarkDirty === 'function') window.__regEditMarkDirty();
    if (isClusterFullySelected(clusterCode)) {
        removeOfficesByCluster(clusterCode);
    } else {
        addOfficesByCluster(clusterCode);
    }
    syncDistClusterChipState();
    if (typeof window.__regEditMarkDirty === 'function') window.__regEditMarkDirty();
}

/**
 * Renders and synchronizes dist cluster chip state in the DOM.
 */
function syncDistClusterChipState() {
    const selected = new Set(getSelectedOfficeIds('distBody'));
    document.querySelectorAll('#distClusterChips .reg-cluster-chip').forEach((btn) => {
        const offices = clusterOffices(btn.getAttribute('data-cluster'));
        const hits = offices.filter((office) => selected.has(String(office.office_id))).length;
        const complete = offices.length > 0 && hits === offices.length;
        const name = btn.getAttribute('data-cluster-name') || 'cluster';
        btn.classList.toggle('is-active', complete);
        btn.classList.remove('is-partial');
        btn.textContent = (complete ? 'Remove ' : 'Select all ') + name;
    });
}

/**
 * Binds and registers dist body drag event listeners.
 */
function bindDistBodyDrag() {
    const tbody = document.getElementById('distBody');
    if (!tbody || tbody.dataset.dragBound) return;
    tbody.dataset.dragBound = 'true';
    let dragEl = null;
    tbody.addEventListener('mousedown', (e) => {
        const tr = e.target.closest('tr.reg-office-added');
        if (!tr) return;
        tr.draggable = !e.target.closest('input, button');
    });
    tbody.addEventListener('dragstart', (e) => {
        const tr = e.target.closest('tr.reg-office-added');
        if (!tr) return;
        dragEl = tr;
        tr.classList.add('is-dragging');
        e.dataTransfer.effectAllowed = 'move';
    });
    tbody.addEventListener('dragend', () => {
        if (dragEl) dragEl.classList.remove('is-dragging');
        dragEl = null;
    });
    tbody.addEventListener('dragover', (e) => {
        e.preventDefault();
        const tr = e.target.closest('tr.reg-office-added');
        if (!tr || !dragEl || tr === dragEl) return;
        const rect = tr.getBoundingClientRect();
        const after = (e.clientY - rect.top) > (rect.height / 2);
        tr.parentNode.insertBefore(dragEl, after ? tr.nextSibling : tr);
    });
}

// ══════════════════════════════════════════════
// VALIDATION
// ══════════════════════════════════════════════
function clearValidation() {
    document.querySelectorAll(".reg-input-error").forEach(el => el.classList.remove("reg-input-error"));
    document.querySelectorAll(".reg-field-error").forEach(el => el.remove());
}

/**
 * Marks field error.
 *
 * @param {*} fieldId
 * @param {*} message
 */
function markFieldError(fieldId, message) {
    const field = fieldId
        ? (document.getElementById(fieldId) || document.querySelector('[name="' + fieldId + '"]'))
        : null;
    if (field) {
        field.classList.add("reg-input-error");
        const parent = field.closest(".reg-field") || field.closest("td") || field.parentElement;
        if (parent && !parent.querySelector(".reg-field-error")) {
            const err = document.createElement("div"); err.className = "reg-field-error";
            err.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + escapeHtml(message);
            parent.appendChild(err);
        }
    } else {
        const syllabiSection = document.getElementById("section-syllabi");
        if (syllabiSection && !syllabiSection.querySelector('.reg-field-error')) {
            const err = document.createElement("div"); err.className = "reg-field-error";
            err.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + escapeHtml(message);
            syllabiSection.appendChild(err);
        }
    }
}

/**
 * Marks table error.
 *
 * @param {*} tableId
 * @param {*} message
 */
function markTableError(tableId, message) {
    const table = document.getElementById(tableId);
    if (!table) return;
    const parent = table.closest(".reg-field") || table.closest(".reg-table-wrap")?.parentElement;
    if (parent && !parent.querySelector(".reg-field-error")) {
        const err = document.createElement("div"); err.className = "reg-field-error";
        err.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + escapeHtml(message);
        parent.appendChild(err);
    }
}

/**
 * Marks search error.
 *
 * @param {*} inputId
 * @param {*} message
 */
function markSearchError(inputId, message) {
    const field = document.getElementById(inputId);
    if (!field) return;
    field.classList.add("reg-input-error");
    const parent = field.closest(".reg-search")?.parentElement;
    if (parent && !parent.querySelector(".reg-field-error")) {
        const err = document.createElement("div"); err.className = "reg-field-error";
        err.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + escapeHtml(message);
        parent.appendChild(err);
    }
}

/**
 * Marks checklist error.
 *
 * @param {*} message
 */
function markChecklistError(message) {
    const container = document.getElementById("dynamicCheckboxes");
    if (!container) return;
    const parent = container.closest(".reg-panel-bottom") || container.parentElement;
    if (parent && !parent.querySelector(".reg-field-error")) {
        const err = document.createElement("div"); err.className = "reg-field-error";
        err.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + escapeHtml(message);
        parent.appendChild(err);
    }
}

/**
 * Sections visible.
 *
 * @param {*} id
 */
function sectionVisible(id) {
    const el = document.getElementById(id);
    return el && el.style.display !== "none";
}

/**
 * Syllabis selection errors.
 */
function syllabiSelectionErrors() {
    if (!window.__isSyllabiMode) return [];
    const courseType = document.getElementById('syllabiCourseType')?.value
        || document.getElementById('syllabiCourseTypeHidden')?.value
        || '';
    const institutional = typeof isInstitutionalSyllabiCourseType === 'function'
        && isInstitutionalSyllabiCourseType(courseType);
    const required = [
        ['syllabiCollege', 'College'],
        ...(institutional ? [] : [['syllabiProgram', 'Program']]),
        ['syllabiSemester', 'Semester'],
        ['syllabiCourseType', 'Course type'],
        ['syllabiSchoolYear', 'School year'],
    ];
    return required.flatMap(([id, label]) => {
        const el = document.getElementById(id);
        const hidden = document.getElementById(id + 'Hidden') || document.getElementById(id + '__locked');
        const value = (el && String(el.value || '').trim())
            || (hidden && String(hidden.value || '').trim())
            || '';
        if (value !== '') return [];
        return [{ field: id, message: label + ' is required before registering.' }];
    });
}

/**
 * Syllabis registration has courses.
 */
function syllabiRegistrationHasCourses() {
    return [...document.querySelectorAll('#syllabiTableBody .syllabi-merged-course, #syllabiTableBody [name="syllabiCourseName[]"]')]
        .some((el) => String(el.value || '').trim() !== '');
}

/**
 * Syllabis courses missing from settings.
 */
function syllabiCoursesMissingFromSettings() {
    const known = new Set(
        (typeof catalogCoursesForContext === 'function' ? catalogCoursesForContext() : [])
            .map((c) => String(c.course_name || '').trim().toLowerCase())
            .filter(Boolean)
    );
    return [...document.querySelectorAll('#syllabiTableBody .syllabi-merged-course, #syllabiTableBody [name="syllabiCourseName[]"]')]
        .map((el) => String(el.value || '').trim())
        .filter(Boolean)
        .filter((name) => !known.has(name.toLowerCase()));
}

/**
 * Validates and verifies form.
 */
function validateForm() {
    clearValidation();
    const errors = [];

    if (window.drfNoDuplicate) {
        errors.push({ field: "drfNo", message: "This DRF number is already used. Please enter a unique DRF number." });
        return errors;
    }

    if (window.__isSyllabiMode && window.syllabiContextTaken) {
        const hint = document.getElementById('syllabiContextHint');
        errors.push({
            field: "syllabiSchoolYear",
            message: (hint && hint.textContent.trim())
                || "This semester and school year are already registered. You cannot save another copy.",
        });
        return errors;
    }

    if (window.__isSyllabiMode) {
        const selectionErrors = syllabiSelectionErrors();
        if (selectionErrors.length) return selectionErrors;
    }

    if (window.__isSyllabiMode && typeof catalogCoursesForContext === 'function' && !catalogCoursesForContext().length) {
        const institutional = typeof isInstitutionalSyllabiCourseType === 'function'
            && isInstitutionalSyllabiCourseType(document.getElementById('syllabiCourseType')?.value || '');
        errors.push({
            field: "btnAddSyllabiRow",
            message: institutional
                ? "There are no institutional course names in Settings for this semester and course type. Add them in Settings before registering."
                : "There are no course names in Settings for this program, semester, and course type. Add them in Settings before registering.",
        });
        return errors;
    }

    if (window.__isSyllabiMode && !syllabiRegistrationHasCourses()) {
        errors.push({
            field: "btnAddSyllabiRow",
            message: "Add at least one course from Settings before registering this document.",
        });
        return errors;
    }

    if (window.__isSyllabiMode) {
        const missingCourses = syllabiCoursesMissingFromSettings();
        if (missingCourses.length) {
            errors.push({
                field: "btnAddSyllabiRow",
                message: "Course names must come from Settings. These are not in Settings for this selection: " + missingCourses.join(", ") + ".",
            });
            return errors;
        }
    }

    if (docNoDuplicate) {
        errors.push({ field: "masterlistDocNo", message: "This document number is already registered. Please use a unique number." });
        return errors;
    }

    const docTypeId = document.getElementById("docType")?.value || CURRENT_DOC_TYPE_ID;
    const subTypeId = document.getElementById("subType")?.value || CURRENT_SUB_TYPE_ID || '';
    const hasChildren = (allDocTypes || []).some(d => String(d.parent_id) === String(docTypeId));
    if (hasChildren && !subTypeId) {
        errors.push({ field: "subType", message: "Sub-Type is required." });
        document.getElementById("subType")?.classList.add("reg-input-error");
        document.getElementById("subType")?.focus();
        return errors;
    }

    const checkedAny = document.querySelectorAll("#dynamicCheckboxes input[type='checkbox']:checked");
    if (checkedAny.length === 0) {
        errors.push({ field: "dynamicCheckboxes", message: "Please check at least one checklist.", type: "checklist" });
        return errors;
    }

    validateTimeSpentFields(errors);
    validateRevisionRowsLinked(errors);
    validateMasterlistRequired(errors, { requireScan: false });

    return errors;
}

/**
 * Validates and verifies time spent fields.
 *
 * @param {*} errors
 */
function validateTimeSpentFields(errors) {
    if (sectionVisible("section-3")) {
        const ml = document.getElementById("masterlistTimeSpentDisplay");
        if (ml && ml.value === "Invalid") {
            errors.push({ field: "masterlistRegisteredDate", message: "Masterlist: Document Registered must be after Document Receipt." });
            ["masterlistReceiptDate", "masterlistReceiptTime", "masterlistRegisteredDate", "masterlistRegisteredTime"]
                .forEach(id => document.getElementById(id)?.classList.add("reg-input-error"));
        }
    }
    if (sectionVisible("section-5")) {
        const dist = document.getElementById("distributionTimeSpentDisplay");
        if (dist && dist.value === "Invalid") {
            errors.push({
                field: "distributionDate",
                message: "Distribution: Distribution Date/Time must be after Masterlist Document Receipt."
            });
            ["masterlistReceiptDate", "masterlistReceiptTime", "distributionDate", "distributionTime"]
                .forEach(id => document.getElementById(id)?.classList.add("reg-input-error"));
        }
    }
}

/**
 * Validates and verifies revision rows linked.
 *
 * @param {*} errors
 */
function validateRevisionRowsLinked(errors) {
    if (!sectionVisible("section-2")) return;
    document.querySelectorAll("#revisionTableBody tr").forEach((row, idx) => {
        const titleInput = row.querySelector('input[name="documentTitle[]"]');
        const noInput = row.querySelector('input[name="documentNo[]"]');
        const hasTypedText = (titleInput && titleInput.value.trim()) || (noInput && noInput.value.trim());
        const isLinked = row.dataset.linked === "true";
        if (hasTypedText && !isLinked) {
            errors.push({
                field: "revisionTableBody",
                message: "DCN Row " + (idx + 1) + ": Please select an existing registered document from suggestions.",
                type: "table"
            });
            row.querySelectorAll('input[name="documentTitle[]"], input[name="documentNo[]"]')
                .forEach(el => el.classList.add("reg-input-error"));
        }
    });
}

/**
 * Displays the validation errors overlay or dialog.
 *
 * @param {*} errors
 */
function showValidationErrors(errors) {
    errors.forEach(err => {
        if (err.type === "table") markTableError(err.field, err.message);
        else if (err.type === "search") markSearchError(err.field, err.message);
        else if (err.type === "checklist") markChecklistError(err.message);
        else markFieldError(err.field, err.message);
    });
    scrollToField(errors[0].field);
}

/**
 * Scrolls to field.
 *
 * @param {*} fieldId
 */
window.scrollToField = function (fieldId) {
    const field = document.getElementById(fieldId);
    if (!field) return;
    field.scrollIntoView({ behavior: "smooth", block: "center" });
    setTimeout(() => { if (field.tagName === "SELECT" || field.tagName === "INPUT" || field.tagName === "TEXTAREA") field.focus(); }, 400);
};

document.addEventListener("input", function (e) {
    if (e.target.classList.contains("reg-input-error")) {
        e.target.classList.remove("reg-input-error");
        const errDiv = e.target.closest(".reg-field")?.querySelector(".reg-field-error");
        if (errDiv) errDiv.remove();
    }
});

document.addEventListener("change", function (e) {
    if (e.target.classList.contains("reg-input-error")) {
        e.target.classList.remove("reg-input-error");
        const errDiv = e.target.closest(".reg-field")?.querySelector(".reg-field-error");
        if (errDiv) errDiv.remove();
    }
    if (e.target.type === "file") {
        const parent = e.target.closest(".reg-field") || e.target.closest(".reg-upload-cell") || e.target.closest("td");
        if (parent) { const err = parent.querySelector(".reg-file-error"); if (err) err.remove(); }
    }
});

// ══════════════════════════════════════════════
// COLLECT MISSING FIELDS (for review modal)
// ══════════════════════════════════════════════
function collectMissingFields() {
    const missing = [];
    const checkText = (sectionLabel, id, label) => {
        const el = document.getElementById(id);
        if (!el) return;
        if (!el.value || !el.value.trim()) missing.push(sectionLabel + ": " + label);
    };

    const sectionSyllabi = document.getElementById("section-syllabi");
    if (sectionSyllabi && sectionSyllabi.style.display !== "none") {
        const institutional = typeof isInstitutionalSyllabiCourseType === 'function'
            && isInstitutionalSyllabiCourseType(document.getElementById('syllabiCourseType')?.value || '');
        checkText("Syllabi", "syllabiCollege", "College");
        if (!institutional) checkText("Syllabi", "syllabiProgram", "Program");
        checkText("Syllabi", "syllabiSemester", "Semester");
        checkText("Syllabi", "syllabiCourseType", "Course Type");
        checkText("Syllabi", "syllabiSchoolYear", "School Year");
        checkText("Syllabi", "syllabiDocTitle", "Document Title");

        const contextHint = document.getElementById('syllabiContextHint');
        if (contextHint && contextHint.classList.contains('is-error') && contextHint.textContent.trim()) {
            missing.push("Syllabi: " + contextHint.textContent.trim());
        }

        const syllabiRows = document.querySelectorAll("#syllabiTableBody tr[data-is-first='true']");
        if (syllabiRows.length === 0) {
            missing.push("Syllabi: At least one course row");
        }

        syllabiRows.forEach((row) => {
            const groupId = row.dataset.group;
            const syllabiOn = !!row.querySelector('.syllabi-merged-availability')?.checked;
            const anyDrfOn = [...document.querySelectorAll(
                `#syllabiTableBody tr[data-group="${groupId}"] .syllabi-hidden-toggle[name="syllabiDrfAvailability[]"]`
            )].some((h) => h.value === 'available');

            if (!syllabiOn && !anyDrfOn) return;

            const courseInput = row.querySelector('.syllabi-merged-course');
            const courseVal = courseInput ? courseInput.value.trim() : '';
            const groupLabel = courseVal || ("Syllabi — Course " + (groupId || ''));

            if (!courseVal) missing.push("Syllabi: Course Name (" + (groupId || 'unnamed') + ")");
            if (courseVal && !(row.querySelector('.syllabi-merged-year')?.value || '').trim()) {
                missing.push(groupLabel + ": Year Level");
            }

            if (syllabiOn) {
                const pages = row.querySelector('.syllabi-merged-pages');
                if (!pages || !pages.value) missing.push(groupLabel + ": No. of Pages");
            }
        });

        document.querySelectorAll("#syllabiTableBody tr[data-uid]").forEach(row => {
            const group = row.dataset.group;
            const firstRow = document.querySelector(
                `#syllabiTableBody tr[data-group="${group}"][data-is-first="true"]`
            );
            const syllabiOn = !!firstRow?.querySelector('.syllabi-merged-availability')?.checked;
            const drfAvail = row.querySelector('.syllabi-hidden-toggle[name="syllabiDrfAvailability[]"]');
            const drfAvailable = drfAvail && drfAvail.value === 'available';

            if (!syllabiOn && !drfAvailable) return;

            const copyNo = row.dataset.copyNo || "1";
            const courseInput = firstRow?.querySelector('.syllabi-merged-course');
            const courseVal = courseInput ? courseInput.value.trim() : '';
            if (!courseVal) return;

            const rowLabel = courseVal + " (Copy " + copyNo + ")";

            if (syllabiOn) {
                const dateReceived = row.querySelector('input[name="syllabiDateReceived[]"]');
                if (!dateReceived || !dateReceived.value) missing.push(rowLabel + ": Date Received");
                const timeReceived = row.querySelector('input[name="syllabiTimeReceived[]"]');
                if (!timeReceived || !timeReceived.value) missing.push(rowLabel + ": Time Received");
                const facultyHidden = document.getElementById('syllabiFacultyHidden_' + row.dataset.uid);
                if (!facultyHidden || !facultyHidden.value.trim()) missing.push(rowLabel + ": Faculty");
            }

            if (drfAvailable) {
                const drfNo = row.querySelector('input[name="syllabiDrfNo[]"]');
                if (!drfNo || !drfNo.value.trim()) missing.push(rowLabel + ": DRF No.");
                const drfDate = row.querySelector('input[name="syllabiDrfDate[]"]');
                if (!drfDate || !drfDate.value) missing.push(rowLabel + ": DRF Date");
                const drfReceived = row.querySelector('input[name="syllabiDrfReceived[]"]');
                if (!drfReceived || !drfReceived.value) missing.push(rowLabel + ": DRF Received Date");

                const scannedDrf = row.querySelector('input[name="syllabiScannedDrf[]"]');
                const existingScanned = row.querySelector('input[name="syllabiExistingScannedDrf[]"]');
                const hasExisting = existingScanned && existingScanned.value;
                if ((!scannedDrf || !scannedDrf.files || scannedDrf.files.length === 0) && !hasExisting) {
                    missing.push(rowLabel + ": Scanned DRF");
                }
            }
        });
    }

    if (sectionVisible("section-1")) {
        checkText("DRF", "drfNo", "DRF No.");
        checkText("DRF", "drfDate", "DRF Date");
        checkText("DRF", "drfTitle", "Document Title");
        if (!window.__sourceWidgets.drf || window.__sourceWidgets.drf.selected.length === 0) missing.push("DRF: Source Unit");
    }

    if (sectionVisible("section-2")) {
        checkText("DCN", "dcnNumber", "DCN No.");
        checkText("DCN", "noticeDate", "DCN Date");
        if (!window.__sourceWidgets.dcn || window.__sourceWidgets.dcn.selected.length === 0) missing.push("DCN: Source Unit");
    }

    if (sectionVisible("section-3")) {
        if (!window.__isSyllabiMode) {
            // Document No. stays optional for New and Recur (and Revised of a no-number source).
            checkText("Masterlist", "masterlistDocTitle", "Document Title");
        }
        if (typeof isRecurringMode === 'function' && isRecurringMode()) {
            const supersedeId = (document.getElementById('supersedeMasterlistId')?.value || '').trim();
            const fromNo = (document.getElementById('revisedFromDocNo')?.value || '').trim();
            const hasRevisionRow = [...document.querySelectorAll('#revisionTableBody tr')].some((row) => {
                const title = row.querySelector('input[name="documentTitle[]"]');
                const no = row.querySelector('input[name="documentNo[]"]');
                return (title && title.value.trim()) || (no && no.value.trim());
            });
            if (!supersedeId && !fromNo && !hasRevisionRow) {
                missing.push('Recur: pick the document to continue');
            }
        }
        checkText("Masterlist", "masterlistEffectivityDate", "Effectivity Date");
        checkText("Masterlist", "masterlistNoOfPages", "No. of Pages");
        // Keywords are optional (search aid only) — never flag as missing.
        if (!window.__sourceWidgets.masterlistOriginator || window.__sourceWidgets.masterlistOriginator.selected.length === 0) missing.push("Masterlist: Originator");
        if (!window.__sourceWidgets.masterlist || window.__sourceWidgets.masterlist.selected.length === 0) missing.push("Masterlist: Source Unit");
        if (!window.__isSyllabiMode && !hasMasterlistScannedCopy()) {
            missing.push("Masterlist: Scanned Copy");
        }
    }

    if (sectionVisible("section-4")) {
        if (document.querySelectorAll("#retrievalBody input[type='hidden']").length === 0) missing.push("Retrieval: At least one office");
    }

    if (sectionVisible("section-5")) {
        checkText("Distribution", "distributionFormDate", "Form Date");
        checkText("Distribution", "distributionDate", "Distribution Date");
        if (document.querySelectorAll("#distBody input[type='hidden']").length === 0) missing.push("Distribution: At least one office");
    }

    return missing;
}

/**
 * Renders and synchronizes missing fields warning in the DOM.
 *
 * @param {*} container
 * @param {*} missing
 */
function renderMissingFieldsWarning(container, missing) {
    const confirmBtn = document.getElementById("btnConfirmSaveModal");
    if (missing.length === 0) {
        if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.style.opacity = ''; confirmBtn.style.cursor = ''; }
        return;
    }

    const grouped = {};
    missing.forEach(m => {
        const idx = m.indexOf(":");
        const section = idx > -1 ? m.slice(0, idx).trim() : "Other";
        const field = idx > -1 ? m.slice(idx + 1).trim() : m;
        if (!grouped[section]) grouped[section] = [];
        grouped[section].push(field);
    });

    const groupsHtml = Object.entries(grouped).map(([section, fields]) => `
        <div class="missing-group">
            <div class="missing-group-title">${escapeHtml(section)}</div>
            <div class="missing-group-chips">
                ${fields.map(f => `<span class="missing-chip"><i class="fa-solid fa-circle-minus"></i>${escapeHtml(f)}</span>`).join('')}
            </div>
        </div>
    `).join('');

    const warn = document.createElement("div");
    warn.className = "review-missing-warning";
    warn.innerHTML = `
        <div class="review-missing-header">
            <div class="review-missing-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div class="review-missing-heading">
                <span class="review-missing-title">Missing Information</span>
                <span class="review-missing-count">${missing.length} field${missing.length === 1 ? '' : 's'} left blank</span>
            </div>
        </div>
        <div class="review-missing-body">${groupsHtml}</div>
    `;
    container.appendChild(warn);

    const confirmWrap = document.createElement("label");
    confirmWrap.className = "review-missing-confirm";
    confirmWrap.innerHTML = `
        <input type="checkbox" id="confirmSaveAnyway" onchange="handleConfirmSaveAnywayToggle(this)">
        <span>I have reviewed the entries above. Some required information is still missing, and I want to proceed with saving anyway.</span>
    `;
    container.appendChild(confirmWrap);

    if (confirmBtn) {
        confirmBtn.disabled = true;
        confirmBtn.setAttribute('aria-disabled', 'true');
        confirmBtn.style.opacity = '0.5';
        confirmBtn.style.cursor = 'not-allowed';
        confirmBtn.style.pointerEvents = 'none';
    }
}

/**
 * Handles confirm save anyway toggle event or response.
 *
 * @param {*} checkbox
 */
window.handleConfirmSaveAnywayToggle = function (checkbox) {
    const confirmBtn = document.getElementById('btnConfirmSaveModal');
    if (!confirmBtn) return;
    confirmBtn.disabled = !checkbox.checked;
    confirmBtn.setAttribute('aria-disabled', checkbox.checked ? 'false' : 'true');
    if (checkbox.checked) {
        confirmBtn.style.opacity = '';
        confirmBtn.style.cursor = '';
        confirmBtn.style.pointerEvents = '';
    } else {
        confirmBtn.style.opacity = '0.5';
        confirmBtn.style.cursor = 'not-allowed';
        confirmBtn.style.pointerEvents = 'none';
    }
};

// ══════════════════════════════════════════════
// CONFIRM SAVE / REVIEW
// ══════════════════════════════════════════════
function getInputVal(id) { const el = document.getElementById(id); return el ? el.value.trim() : ""; }
/**
 * Retrieves and returns select text.
 *
 * @param {*} id
 */
function getSelectText(id) {
    const el = document.getElementById(id);
    if (!el || el.selectedIndex < 0) return "";
    const opt = el.options[el.selectedIndex];
    if (!opt || opt.disabled || opt.value === "") return "";
    return opt.text || "";
}
/**
 * Formats, parses, or sanitizes smart date value.
 *
 * @param {*} val
 */
function formatSmartDateValue(val) {
    if (!val) return "";
    const date = new Date(val + "T00:00:00");
    if (isNaN(date.getTime())) return val;
    const months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
    const mon = months[date.getMonth()];
    const day = date.getDate();
    const yyyy = date.getFullYear();
    // Day 1 → "Jan 2020"; other days → "Feb 20, 2020"
    if (day === 1) return mon + " " + yyyy;
    return mon + " " + day + ", " + yyyy;
}
/**
 * Formats, parses, or sanitizes input date.
 *
 * @param {*} id
 */
function formatInputDate(id) {
    return formatSmartDateValue(getInputVal(id));
}
/**
 * Fmts date value.
 *
 * @param {*} val
 */
function fmtDateValue(val) {
    return formatSmartDateValue(val);
}
/**
 * Checks whether deadline not applicable condition is met.
 */
function isDeadlineNotApplicable() {
    return !!document.getElementById("deadlineNotApplicable")?.checked;
}
/**
 * Retrieves and returns office list.
 *
 * @param {*} tbodyId
 */
function getOfficeList(tbodyId) {
    const tbody = document.getElementById(tbodyId);
    if (!tbody) return [];
    const offices = [];
    tbody.querySelectorAll("tr").forEach(row => {
        const name = row.querySelector(".reg-office-text")?.textContent?.trim();
        const copies = row.querySelector('input[type="number"]')?.value || "1";
        if (name) offices.push({ name, copies: String(copies) });
    });
    return offices;
}

/**
 * Adds review section.
 *
 * @param {*} container
 * @param {*} title
 * @param {*} fields
 */
/** Optional review labels — shown when empty, but never counted as missing. */
const REVIEW_OPTIONAL_LABELS = new Set(['Keywords', 'Related Docs']);

function isReviewPlaceholderEmpty(raw) {
    return raw === '' || raw === '--' || raw === '—' || raw === '-' || raw === '–';
}

function addReviewSection(container, title, fields) {
    if (fields.length === 0) return;
    const section = document.createElement("div");
    section.className = "review-section";
    let html = '<div class="review-section-title">' + escapeHtml(title) + '</div>';
    fields.forEach(f => {
        const raw = f.value == null ? "" : String(f.value).trim();
        const unavailable = /^not\s*available$/i.test(raw);
        const naValue = /^n\/a$/i.test(raw);
        const optional = !!f.optional || REVIEW_OPTIONAL_LABELS.has(f.label);
        const empty = isReviewPlaceholderEmpty(raw);
        let rowClass = '';
        if (unavailable) rowClass = ' review-row-unavailable';
        else if (empty && !optional) rowClass = ' review-row-empty';
        html += '<div class="review-row' + rowClass + '">';
        html += '<span class="review-label">' + escapeHtml(f.label) + '</span>';
        if (unavailable) {
            html += '<span class="review-value review-value-missing"><i class="fa-solid fa-circle-minus"></i> Not available</span>';
        } else if (naValue) {
            html += '<span class="review-value">N/A</span>';
        } else if (!empty) {
            html += f.isFile
                ? '<span class="review-value review-file"><i class="fa-solid fa-paperclip"></i> ' + escapeHtml(f.value) + '</span>'
                : '<span class="review-value">' + escapeHtml(String(f.value)) + '</span>';
        } else if (optional) {
            html += '<span class="review-value">—</span>';
        } else {
            html += '<span class="review-value review-value-missing"><i class="fa-solid fa-circle-minus"></i> Not provided</span>';
        }
        html += '</div>';
    });
    section.innerHTML = html;
    container.appendChild(section);
}

/**
 * Builds missing-info chips from every "Not provided" row in the review.
 */
function collectMissingFromReview(container) {
    const missing = [];
    if (!container) return missing;
    container.querySelectorAll('.review-section').forEach((section) => {
        const title = section.querySelector('.review-section-title')?.textContent?.trim() || 'Other';
        section.querySelectorAll('.review-row-empty').forEach((row) => {
            const label = row.querySelector('.review-label')?.textContent?.trim();
            if (!label || REVIEW_OPTIONAL_LABELS.has(label)) return;
            missing.push(title + ': ' + label);
        });
    });
    return missing;
}

/** Combine date + time for review; empty pair → null (Not provided). */
function reviewDateTime(dateId, timeId) {
    const d = formatInputDate(dateId);
    const t = getInputVal(timeId);
    if (!d && !t) return null;
    return (d + ' ' + t).trim();
}

/**
 * Adds review list.
 *
 * @param {*} container
 * @param {*} title
 * @param {*} items
 */
function addReviewList(container, title, items) {
    const section = document.createElement("div");
    section.className = "review-section";
    let html = '<div class="review-section-title">' + escapeHtml(title) + '</div><ul class="review-list">';
    items.forEach(item => { html += '<li>' + escapeHtml(item) + '</li>'; });
    html += '</ul>';
    section.innerHTML = html;
    container.appendChild(section);
}

/**
 * Adds review office list.
 *
 * @param {*} container
 * @param {*} title
 * @param {*} offices
 */
function addReviewOfficeList(container, title, offices) {
    const section = document.createElement("div");
    section.className = "review-section";
    let html = '<div class="review-section-title">' + escapeHtml(title) + '</div>';
    html += '<div class="review-office-table">';
    html += '<div class="review-office-head"><span>Office</span><span>Copies</span></div>';
    offices.forEach((office) => {
        const count = Number(office.copies) || 1;
        const label = count === 1 ? '1 copy' : (count + ' copies');
        html += '<div class="review-office-row">';
        html += '<span class="review-office-name">' + escapeHtml(office.name) + '</span>';
        html += '<span class="review-office-copies">' + escapeHtml(label) + '</span>';
        html += '</div>';
    });
    html += '</div>';
    section.innerHTML = html;
    container.appendChild(section);
}

/**
 * Confirms save.
 */
window.confirmSave = function () {
    if (window.__isSyllabiMode && window.syllabiContextTaken) {
        scrollToField('syllabiSchoolYear');
        document.getElementById('syllabiSchoolYear')?.focus();
        showValidationErrors([{
            field: "syllabiSchoolYear",
            message: (document.getElementById('syllabiContextHint')?.textContent || '').trim()
                || "This semester and school year are already registered. You cannot save another copy.",
        }]);
        return;
    }
    if (window.drfNoDuplicate) {
        scrollToField('drfNo');
        document.getElementById('drfNo')?.focus();
        return;
    }
    if (docNoDuplicate) {
        const fieldId = 'masterlistDocNo';
        scrollToField(fieldId);
        document.getElementById(fieldId)?.focus();
        return;
    }
    const errors = validateForm();
    if (errors.length > 0) { showValidationErrors(errors); return; }
    clearValidation();

    const confirmBtn = document.getElementById('btnConfirmSaveModal');
    if (confirmBtn) {
        const isDraft = !!document.getElementById('btnSaveDraft');
        if (window.__openAppraisalAfterSave) {
            confirmBtn.innerHTML = '<i class="fa-solid fa-clipboard-check"></i> '
                + (isDraft ? 'Confirm Save and Appraise' : 'Confirm Update and Appraise');
        } else {
            confirmBtn.innerHTML = '<i class="fa-solid fa-check"></i> '
                + (isDraft ? 'Confirm Save' : 'Confirm Update');
        }
    }

    const reviewContent = document.getElementById("reviewContent");
    reviewContent.innerHTML = "";

    buildSyllabiInfoReview(reviewContent);
    buildSyllabiRowsReview(reviewContent);
    appendControlledFormReviews(reviewContent);
    buildApprovalReview(reviewContent);
    buildMasterlistReview(reviewContent);
    buildRetrievalReview(reviewContent);
    buildDistributionReview(reviewContent);

    if (!reviewContent.children.length) {
        reviewContent.innerHTML = '<div class="review-empty">No data to review.</div>';
    }

    const fromReview = collectMissingFromReview(reviewContent);
    const coveredFields = new Set(fromReview.map((m) => {
        const i = m.indexOf(':');
        return (i > -1 ? m.slice(i + 1) : m).trim().toLowerCase();
    }));
    const fromForm = collectMissingFields().filter((m) => {
        const i = m.indexOf(':');
        const field = (i > -1 ? m.slice(i + 1) : m).trim();
        if (/^keywords$/i.test(field)) return false;
        if (/at least one|pick the document|context/i.test(field)) return true;
        return !coveredFields.has(field.toLowerCase());
    });
    const missing = [...new Set([...fromReview, ...fromForm])];
    renderMissingFieldsWarning(reviewContent, missing);

    const root = document.getElementById("dcsEditRoot");
    if (root) root.style.overflow = "hidden";
    if (!setRegisterAlpineProp('reviewOpen', true, 'dcsEditRoot')) {
        [0, 30, 100].forEach((ms) => setTimeout(() => setRegisterAlpineProp('reviewOpen', true, 'dcsEditRoot'), ms));
    }
};

/**
 * Builds syllabi info review.
 *
 * @param {*} reviewContent
 */
function buildSyllabiInfoReview(reviewContent) {
    const ss = document.getElementById("section-syllabi");
    if (!ss || ss.style.display === "none") return;
    const courseType = getSelectText("syllabiCourseType");
    const institutional = typeof isInstitutionalSyllabiCourseType === 'function'
        && isInstitutionalSyllabiCourseType(document.getElementById('syllabiCourseType')?.value || '');
    const infoRows = [
        { label: "College", value: getSelectText("syllabiCollege") },
    ];
    if (!institutional) {
        infoRows.push({ label: "Program", value: getSelectText("syllabiProgram") });
    }
    infoRows.push(
        { label: "Semester", value: getSelectText("syllabiSemester") },
        { label: "Course Type", value: courseType },
        { label: "Year Level", value: (typeof getSelectedSyllabiYearLevels === 'function' ? getSelectedSyllabiYearLevels() : []).join(', ') || (document.getElementById("syllabiYearLevelPicker")?.textContent || "").trim() },
        { label: "School Year", value: getSelectText("syllabiSchoolYear") },
        { label: "Document Title", value: getInputVal("syllabiDocTitle") },
    );
    addReviewSection(reviewContent, "Syllabi — Document Info", infoRows);
}

/**
 * Builds syllabi rows review.
 *
 * @param {*} reviewContent
 */
function buildSyllabiRowsReview(reviewContent) {
    const ss = document.getElementById("section-syllabi");
    if (!ss || ss.style.display === "none") return;

    document.querySelectorAll("#syllabiTableBody tr[data-is-first='true']").forEach((r) => {
        const course = r.querySelector('textarea.syllabi-merged-course, input[name="syllabiCourseName[]"]');
        if (!course || !course.value.trim()) return;

        const pages = r.querySelector('.syllabi-merged-pages');
        const copies = r.querySelector('.syllabi-merged-copies');
        const availHidden = r.querySelector('.syllabi-merged-availability-hidden');
        const drfAvail = r.querySelector('.syllabi-hidden-toggle[name="syllabiDrfAvailability[]"]');
        const drfNo = r.querySelector('input[name="syllabiDrfNo[]"]');
        const drfDate = r.querySelector('input[name="syllabiDrfDate[]"]');
        const drfReceived = r.querySelector('input[name="syllabiDrfReceived[]"]');

        const group = r.dataset.group;
        const facultyNames = [...document.querySelectorAll(`#syllabiTableBody tr[data-group="${group}"]`)]
            .map(row => document.getElementById('syllabiFacultyHidden_' + row.dataset.uid)?.value)
            .filter(Boolean)
            .join('; ');

        addReviewSection(reviewContent, "Syllabi — " + course.value.trim(), [
            { label: "Course Code", value: r.querySelector('.syllabi-merged-code')?.value || "" },
            { label: "Year Level", value: r.querySelector('.syllabi-merged-year')?.value || "" },
            { label: "No. of Copies", value: copies?.value || "1" },
            { label: "No. of Pages", value: pages?.value || "" },
            { label: "Faculty", value: facultyNames || null },
            { label: "Syllabi Availability", value: availHidden?.value === 'available' ? 'Available' : 'Not Available' },
            { label: "DRF Availability", value: drfAvail?.value === 'available' ? 'Available' : 'Not Available' },
            { label: "DRF No.", value: drfNo?.value?.trim() || "" },
            { label: "DRF Date", value: fmtDateValue(drfDate?.value) },
            { label: "DRF Received", value: fmtDateValue(drfReceived?.value) },
        ]);
    });
}

/**
 * Appends controlled form reviews.
 *
 * @param {*} reviewContent
 */
function appendControlledFormReviews(reviewContent) {
    const revised = typeof isRevisedMode === 'function' && isRevisedMode();
    if (revised) {
        buildDcnReview(reviewContent);
        buildDrfReview(reviewContent);
        return;
    }
    buildDrfReview(reviewContent);
    buildDcnReview(reviewContent);
}

/**
 * Builds drf review.
 *
 * @param {*} reviewContent
 */
function buildDrfReview(reviewContent) {
    const s1 = document.getElementById("section-1");
    if (!s1 || s1.style.display === "none") return;
    const f = document.getElementById("drfFile")?.files;
    addReviewSection(reviewContent, "Document Request Form", [
        { label: "DRF No.", value: getInputVal("drfNo") },
        { label: "DRF Date", value: formatInputDate("drfDate") },
        { label: "Receipt", value: formatInputDate("drfReceiptDate") + " " + getInputVal("drfTime") },
        { label: "Title", value: getInputVal("drfTitle") },
        { label: "Source Unit", value: window.__sourceWidgets.drf?.selected.length > 0 ? window.__sourceWidgets.drf.selected.map(o => o.label).join(', ') : null },
        { label: "File", value: f && f.length > 0 ? f[0].name : null, isFile: true },
    ]);
}

/**
 * Builds dcn review.
 *
 * @param {*} reviewContent
 */
function buildDcnReview(reviewContent) {
    const s2 = document.getElementById("section-2");
    if (!s2 || s2.style.display === "none") return;
    const f = document.getElementById("dcnFile")?.files;
    addReviewSection(reviewContent, "Document Change Notice", [
        { label: "DCN No.", value: getInputVal("dcnNumber") },
        { label: "DCN Date", value: formatInputDate("noticeDate") },
        { label: "Receipt", value: formatInputDate("receiptDate") + " " + getInputVal("receiptTime") },
        { label: "Source Unit", value: window.__sourceWidgets.dcn?.selected.length > 0 ? window.__sourceWidgets.dcn.selected.map(i => i.label).join(', ') : null },
        { label: "File", value: f && f.length > 0 ? f[0].name : null, isFile: true },
    ]);
    const rev = [];
    document.querySelectorAll("#revisionTableBody tr").forEach((r, i) => {
        const t = r.querySelector('input[name="documentTitle[]"]');
        const n = r.querySelector('input[name="documentNo[]"]');
        const no = n && n.value.trim() ? n.value.trim() : '';
        const title = t && t.value.trim() ? t.value.trim() : '';
        const label = (no ? no + ' — ' : '') + title;
        if (!label) return;
        const revField = r.querySelector('input[name="revisionNo[]"]');
        const revNo = revField && String(revField.value).trim() !== '' ? String(revField.value).trim() : String(i);
        rev.push('Rev ' + revNo + ': ' + label);
    });
    if (rev.length) addReviewList(reviewContent, "Revisions", rev);
}

/**
 * Builds masterlist review.
 *
 * @param {*} reviewContent
 */
function buildMasterlistReview(reviewContent) {
    const s3 = document.getElementById("section-3");
    if (!s3 || s3.style.display === "none") return;
    const f = document.getElementById("uploadScannedCopy")?.files;
    addReviewSection(reviewContent, "Masterlist Registration", [
        { label: "Doc No.", value: getInputVal("masterlistDocNo") },
        { label: "Title", value: getInputVal("masterlistDocTitle") },
        { label: "Deadline", value: isDeadlineNotApplicable() ? "N/A" : (formatInputDate("deadlineOfSubmission") || null) },
        { label: "Receipt", value: reviewDateTime("masterlistReceiptDate", "masterlistReceiptTime") },
        { label: "Registered", value: reviewDateTime("masterlistRegisteredDate", "masterlistRegisteredTime") },
        { label: "Time Spent", value: document.getElementById("masterlistTimeSpentDisplay")?.value || null },
        { label: "Effectivity", value: formatInputDate("masterlistEffectivityDate") || null },
        { label: "Revision No.", value: getInputVal("masterlistRevisionNo") || "0" },
        { label: "Pages", value: getInputVal("masterlistNoOfPages") },
        { label: "Originator", value: window.__sourceWidgets.masterlistOriginator?.selected.length > 0 ? window.__sourceWidgets.masterlistOriginator.selected.map(o => o.label).join(', ') : null },
        { label: "Source Unit", value: window.__sourceWidgets.masterlist?.selected.length > 0 ? window.__sourceWidgets.masterlist.selected.map(i => i.label).join(', ') : null },
        { label: "Keywords", value: getInputVal("keywords"), optional: true },
        { label: "Related Docs", value: relatedDocsSelected.length > 0 ? relatedDocsSelected.map(d => d.doc_title).join(', ') : null, optional: true },
        { label: "File", value: f && f.length > 0 ? f[0].name : null, isFile: true },
    ]);
}

/**
 * Builds approval review.
 *
 * @param {*} reviewContent
 */
function buildApprovalReview(reviewContent) {
    const sa = document.getElementById("section-approval");
    if (!sa || sa.style.display === "none") return;
    addReviewSection(reviewContent, "Approval Details", [
        { label: "Body", value: getSelectText("approvalBody") },
        { label: "Date", value: formatInputDate("approvalDate") },
        { label: "No.", value: getInputVal("approvalNo") },
    ]);
}

/**
 * Builds retrieval review.
 *
 * @param {*} reviewContent
 */
function buildRetrievalReview(reviewContent) {
    const s4 = document.getElementById("section-4");
    if (!s4 || s4.style.display === "none") return;
    const off = getOfficeList("retrievalBody");
    if (off.length) addReviewOfficeList(reviewContent, "Receiving Offices (Retrieval)", off);
}

/**
 * Builds distribution review.
 *
 * @param {*} reviewContent
 */
function buildDistributionReview(reviewContent) {
    const s5 = document.getElementById("section-5");
    if (!s5 || s5.style.display === "none") return;
    const f = document.getElementById("scanneddist")?.files;
    addReviewSection(reviewContent, "Document Distribution", [
        { label: "Form Date", value: reviewDateTime("distributionFormDate", "distributionFormTime") },
        { label: "Distribution Date", value: reviewDateTime("distributionDate", "distributionTime") },
        { label: "Time Spent", value: document.getElementById("distributionTimeSpentDisplay")?.value || null },
        { label: "Remarks", value: getInputVal("distributionRemarks") || null },
        { label: "File", value: f && f.length > 0 ? f[0].name : null, isFile: true },
    ]);
    const off = getOfficeList("distBody");
    if (off.length) addReviewOfficeList(reviewContent, "Receiving Offices (Distribution)", off);
}

/**
 * Closes and hides the confirm modal.
 */
window.closeConfirmModal = function () {
    if (document.getElementById('confirmModal')?.classList.contains('is-saving')) return;
    const root = document.getElementById("dcsEditRoot");
    setRegisterAlpineProp('reviewOpen', false, 'dcsEditRoot');
    if (root) root.style.overflow = "";
};

document.getElementById("confirmModal")?.addEventListener("click", function (e) {
    if (e.target === this) closeConfirmModal();
});
document.addEventListener("keydown", function (e) {
    if (e.key !== "Escape") return;
    const modal = document.getElementById("confirmModal");
    if (modal?.classList.contains("is-open")) closeConfirmModal();
});
/**
 * Submits and sends form request.
 */
window.submitForm = function () {
    if (window.__isSyllabiMode && window.syllabiContextTaken) {
        showValidationErrors([{
            field: "syllabiSchoolYear",
            message: (document.getElementById('syllabiContextHint')?.textContent || '').trim()
                || "This semester and school year are already registered. You cannot save another copy.",
        }]);
        return;
    }
    if (typeof unlockSyllabiContextForSubmit === 'function') unlockSyllabiContextForSubmit();
    const ack = document.getElementById('confirmSaveAnyway');
    if (ack && !ack.checked) {
        alert('Please confirm that you reviewed the missing information before saving.');
        return;
    }
    const draftFlag = document.getElementById('saveAsDraft');
    if (draftFlag) draftFlag.value = '0';
    const appraisalFlag = document.getElementById('openAppraisal');
    if (appraisalFlag) appraisalFlag.value = window.__openAppraisalAfterSave ? '1' : '0';
    syncChecklistHiddenInputs();
    if (typeof syncSyllabiContextHidden === 'function') {
        syncSyllabiContextHidden();
    }
    window.__regFormSubmitting = true;
    if (typeof clearEditSyllabiContextSelection === 'function') {
        clearEditSyllabiContextSelection();
    }
    showSavingDocumentOverlay();
    const form = document.getElementById("masterForm");
    if (form && typeof sanitizeFormFields === 'function') sanitizeFormFields(form);
    form.submit();
};

/**
 * Displays the saving document overlay overlay or dialog.
 */
function showSavingDocumentOverlay() {
    const modal = document.getElementById('confirmModal');
    const banner = document.getElementById('regReviewSavingBanner');
    const confirmBtn = document.getElementById('btnConfirmSaveModal');
    const isUpdate = !!document.getElementById('btnUpdateDocument');
    const label = isUpdate ? 'Updating document…' : 'Saving document…';

    if (modal?.classList.contains('is-open')) {
        modal.classList.add('is-saving');
        if (banner) {
            const text = banner.querySelector('span');
            if (text) text.textContent = label + ' Please wait.';
            banner.hidden = false;
        }
        modal.querySelectorAll('.reg-modal-close, .reg-modal-footer .reg-btn-cancel').forEach((el) => {
            el.disabled = true;
            el.setAttribute('aria-disabled', 'true');
        });
        if (confirmBtn) {
            confirmBtn.disabled = true;
            confirmBtn.setAttribute('aria-disabled', 'true');
            confirmBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> '
                + (isUpdate ? 'Updating…' : 'Saving…');
        }
        return;
    }

    // Fallback when review modal is not open (e.g. draft leave autosave).
    let overlay = document.getElementById('regSavingOverlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'regSavingOverlay';
        overlay.className = 'reg-saving-overlay';
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-live', 'polite');
        overlay.innerHTML = `
            <div class="reg-saving-card">
                <i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>
                <div>
                    <strong></strong>
                    <p>Please wait while your document is being written.</p>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);
    }
    const title = overlay.querySelector('strong');
    if (title) title.textContent = label;
    overlay.classList.add('is-visible');
    if (confirmBtn) {
        confirmBtn.disabled = true;
        confirmBtn.setAttribute('aria-disabled', 'true');
    }
}

/**
 * Unlocks syllabi context for submit.
 */
function unlockSyllabiContextForSubmit() {
    ['syllabiCollege', 'syllabiProgram', 'syllabiSemester', 'syllabiCourseType', 'syllabiSchoolYear']
        .forEach((id) => {
            const el = document.getElementById(id);
            if (el) el.disabled = false;
        });
}

/**
 * Prepares draft submit defaults.
 */
function prepareDraftSubmitDefaults() {
    unlockSyllabiContextForSubmit();
    if (typeof persistEditSyllabiContextSelection === 'function') {
        persistEditSyllabiContextSelection();
    }
    const approvalChecked = document.querySelector('input[name="approval_status"]:checked');
    if (!approvalChecked) {
        const na = document.querySelector('input[name="approval_status"][value="not_applicable"]');
        if (na) na.checked = true;
    }
    if (typeof syncChecklistHiddenInputs === 'function') syncChecklistHiddenInputs();
}

/**
 * Regs draft can save.
 */
window.__regDraftCanSave = function () {
    if (typeof syncSyllabiToMasterlistFields === 'function') syncSyllabiToMasterlistFields();
    if (window.__isSyllabiMode && window.syllabiContextTaken) return false;
    const version = document.getElementById('versionType')?.value;
    const docType = document.getElementById('docType')?.value;
    if (!version || !docType) return false;
    const draftErrors = [];
    validateMasterlistRequired(draftErrors, { requireScan: false, forDraft: true });
    return draftErrors.length === 0;
};

/**
 * Regs draft has progress.
 */
window.__regDraftHasProgress = function () {
    if (window.__isSyllabiMode) {
        const ids = ['syllabiDocTitle', 'syllabiCollege', 'syllabiProgram', 'syllabiSemester', 'syllabiCourseType', 'syllabiSchoolYear'];
        const syllabiStarted = ids.some((id) => (document.getElementById(id)?.value || '').trim() !== '')
            || [...document.querySelectorAll('#syllabiTableBody .syllabi-merged-course, #syllabiTableBody .syllabi-merged-code')]
                .some((el) => (el.value || '').trim() !== '');
        if (syllabiStarted && window.__isDraftDoc && typeof window.__regEditIsDirty === 'function') {
            try {
                return !!window.__regEditIsDirty();
            } catch (_) {
                return true;
            }
        }
    }
    // Update/Edit: only block leave when the user actually changed loaded data.
    if (typeof window.__regEditIsDirty === 'function') {
        try {
            return !!window.__regEditIsDirty();
        } catch (_) {
            return false;
        }
    }
    // Drafts without dirty helper: any started registration counts as progress.
    if (window.__isDraftDoc) {
        return !!document.getElementById('versionType')?.value
            || !!document.getElementById('docType')?.value
            || (typeof masterlistHasData === 'function' && masterlistHasData());
    }
    // Published edit page should never warn just because saved fields have values.
    return false;
};

/**
 * Regs draft should autosave on leave.
 */
window.__regDraftShouldAutosaveOnLeave = function () {
    // Autosave drafts only. Published document edits keep beforeunload warning via hasProgress.
    return !!window.__isDraftDoc;
};

/**
 * Regs draft prepare submit.
 */
window.__regDraftPrepareSubmit = function () {
    prepareDraftSubmitDefaults();
};

/**
 * Regs draft show errors.
 *
 * @param {*} message
 */
window.__regDraftShowErrors = function (message) {
    const draftErrors = [];
    validateMasterlistRequired(draftErrors, { requireScan: false, forDraft: true });
    if (draftErrors.length > 0) {
        showValidationErrors(draftErrors);
        if (window.__regDraftHighlightErrors) return;
        if (typeof window.showDraftNoticeModal === 'function') {
            window.showDraftNoticeModal(draftErrors[0].message);
            return;
        }
        if (window.dcsShowToast) {
            window.dcsShowToast(draftErrors[0].message, 'error');
            return;
        }
    }
    if (window.__regDraftHighlightErrors) return;
    if (typeof window.showDraftNoticeModal === 'function') {
        window.showDraftNoticeModal(message || 'Unable to save draft.');
        return;
    }
    if (window.dcsShowToast) {
        window.dcsShowToast(message || 'Unable to save draft.', 'error');
        return;
    }
};

// ══════════════════════════════════════════════
// REVISION TABLE
// ══════════════════════════════════════════════
function revisionRowCellsHTML() {
    return `
        <td>
            <input type="text" name="documentNo[]" placeholder="Search or enter document no." autocomplete="off">
            <input type="hidden" name="revisionScannedPath[]" value="">
        </td>
        <td><input type="text" name="documentTitle[]" placeholder="Search or enter document title" autocomplete="off"></td>
        <td><input type="date" name="effectiveDate[]" readonly class="reg-revrow-locked" tabindex="-1"></td>
        <td><input type="number" name="revisionNo[]" placeholder="—" readonly class="reg-revrow-locked" tabindex="-1"></td>
        <td class="reg-rev-scan-cell" style="text-align:center;color:#475569;">—</td>
        <td class="reg-rev-purpose-cell">
            <input type="hidden" name="revisionPurpose[]" value="">
            <span class="reg-rev-purpose-text">—</span>
        </td>
        <td class="reg-rev-del-col"><button type="button" class="reg-row-del" onclick="removeRevisionRow(this)"><i class="fa-solid fa-trash-can"></i></button></td>
    `;
}

/**
 * Adds revision row.
 */
window.addRevisionRow = function () {
    const tbody = document.getElementById("revisionTableBody"); if (!tbody) return;
    const tr = document.createElement("tr");
    tr.innerHTML = revisionRowCellsHTML();
    tbody.appendChild(tr);
    bindRevisionRowSearch(tr);
};

// ══════════════════════════════════════════════
// OFFICE SEARCH (Retrieval / Distribution)
// ══════════════════════════════════════════════
window.handleSearch = function (input, resultsId, bodyId, totalId) {
    const dropdown = document.getElementById(resultsId); if (!dropdown) return;
    const selected = new Set(getSelectedOfficeIds(bodyId));
    const filtered = filterOffices(input.value).filter((o) => !selected.has(String(o.office_id)));
    if (input.value.trim().length < 1 || filtered.length === 0) { dropdown.style.display = "none"; return; }
    dropdown.innerHTML = filtered.map(o =>
        '<div onclick="addOffice(' + Number(o.office_id) + ", '" + escapeHtml(o.office_name).replace(/'/g, '&#39;') + "', '" + bodyId + "', '" + totalId + "', '" + resultsId + "')\">" + escapeHtml(o.office_name) + '</div>'
    ).join("");
    dropdown.style.display = "block";
};

/**
 * Adds office.
 *
 * @param {*} officeId
 * @param {*} officeName
 * @param {*} bodyId
 * @param {*} totalId
 * @param {*} resultsId
 */
window.addOffice = function (officeId, officeName, bodyId, totalId, resultsId) {
    if (bodyId === 'distBody' && typeof isDistOfficesLockedFromDrf === 'function' && isDistOfficesLockedFromDrf()) {
        if (typeof showToast === 'function') {
            showToast(window.__distOfficesLockMessage || 'Distribution offices cannot be added.', 'warning');
        }
        return;
    }
    const tbody = document.getElementById(bodyId); const dropdown = document.getElementById(resultsId); if (!tbody) return;
    const isRetrieval = bodyId === "retrievalBody";

    const emptyRow = tbody.querySelector(".reg-empty-row"); if (emptyRow) emptyRow.remove();
    for (const inp of tbody.querySelectorAll('input[type="hidden"]')) {
        if (inp.value == officeId) {
            if (dropdown) {
                dropdown.style.display = "none";
                dropdown.parentElement.querySelector("input[type='text']").value = "";
            }
            const row = inp.closest("tr"); row.style.animation = "none"; row.offsetHeight; row.style.animation = "flashRow 0.6s ease";
            return;
        }
    }

    if (typeof window.__regEditMarkDirty === 'function') window.__regEditMarkDirty();

    if (isRetrieval) {
        seedRetrievalOfficeRow(bodyId, totalId, officeId, officeName, 1, 'pending');
        updateTotal(totalId, bodyId);
        if (dropdown) {
            dropdown.style.display = "none";
            const searchInput = dropdown.parentElement?.querySelector("input[type='text']");
            if (searchInput) searchInput.value = "";
        }
        refreshOfficeSeeMore(bodyId);
        if (typeof window.__regEditMarkDirty === 'function') window.__regEditMarkDirty();
        return;
    }

    const officeNameAttr = "distOffice[]";
    const copiesNameAttr = "distCopies[]";
    const safeDisplay = escapeHtml(officeName);
    const tr = document.createElement("tr");
    tr.className = "reg-office-added";
    tr.draggable = true;
    if (typeof buildDistOfficeRowHTML === 'function') {
        tr.innerHTML = buildDistOfficeRowHTML(officeId, officeName, 1, totalId);
    } else {
        tr.innerHTML = `<td class="reg-dist-check-cell"><input type="checkbox" class="dist-office-check" onchange="onDistOfficeCheckChange()" title="Select to reorder"></td><td><input type="hidden" name="${officeNameAttr}" value="${officeId}"><div class="reg-office-name"><span class="reg-dist-drag-handle" title="Drag to reorder"><i class="fa-solid fa-grip-vertical"></i></span><div class="reg-office-icon"><i class="fa-solid fa-building"></i></div><span class="reg-office-text">${safeDisplay}</span></div></td><td style="text-align:center;"><input type="number" name="${copiesNameAttr}" value="1" min="1" oninput="updateTotal('${totalId}', '${bodyId}')"></td><td><button type="button" class="btn-remove" onclick="removeOffice(this, '${totalId}', '${bodyId}')"><i class="fa-solid fa-xmark"></i></button></td>`;
    }
    tbody.appendChild(tr);
    updateTotal(totalId, bodyId);
    if (dropdown) {
        dropdown.style.display = "none";
        const searchInput = dropdown.parentElement?.querySelector("input[type='text']");
        if (searchInput) searchInput.value = "";
    }
    if (bodyId === 'distBody') syncDistClusterChipState();
    refreshOfficeSeeMore(bodyId);
    if (typeof window.__regEditMarkDirty === 'function') window.__regEditMarkDirty();
};

/**
 * Removes specified office.
 *
 * @param {*} btn
 * @param {*} totalId
 * @param {*} bodyId
 */
window.removeOffice = function (btn, totalId, bodyId) {
    if (bodyId === 'retrievalBody') {
        return;
    }
    if (bodyId === 'distBody' && typeof isDistOfficesLockedFromDrf === 'function' && isDistOfficesLockedFromDrf()) {
        if (typeof showToast === 'function') {
            showToast(window.__distOfficesLockMessage || 'Distribution offices cannot be removed.', 'warning');
        }
        return;
    }
    if (typeof window.__regEditMarkDirty === 'function') window.__regEditMarkDirty();
    const tr = btn.closest("tr");
    if (bodyId === 'distBody') {
        maybeRestoreDistOfficeToRetrieval(tr);
    }
    tr.style.opacity = "0"; tr.style.transform = "translateX(20px)"; tr.style.transition = "all 0.2s ease";
    setTimeout(() => {
        tr.remove(); updateTotal(totalId, bodyId);
        const tbody = document.getElementById(bodyId);
        if (tbody && tbody.querySelectorAll("tr").length === 0) {
            tbody.innerHTML = emptyOfficeRowHTML(bodyId);
        }
        if (bodyId === 'distBody') syncDistClusterChipState();
        refreshOfficeSeeMore(bodyId);
        if (typeof window.__regEditMarkDirty === 'function') window.__regEditMarkDirty();
    }, 200);
};

/**
 * Renders and synchronizes total in the DOM.
 *
 * @param {*} totalId
 * @param {*} bodyId
 */
window.updateTotal = function (totalId, bodyId) {
    const totalEl = document.getElementById(totalId);
    if (!totalEl) return;
    let sum = 0;
    if (bodyId) {
        const tbody = document.getElementById(bodyId);
        if (tbody) tbody.querySelectorAll('input[type="number"]').forEach(input => { sum += parseInt(input.value) || 0; });
    } else {
        const table = totalEl.closest("table");
        if (table) table.querySelectorAll('tbody input[type="number"]').forEach(input => { sum += parseInt(input.value) || 0; });
    }
    totalEl.textContent = sum;
};

// ══════════════════════════════════════════════
// OFFICE TABLE SEE MORE (Distribution / Retrieval)
// ══════════════════════════════════════════════
const OFFICE_SEE_MORE_MIN = 2;

/**
 * Offices see more btn for body.
 *
 * @param {*} bodyId
 */
function officeSeeMoreBtnForBody(bodyId) {
    if (bodyId === 'distBody') return document.getElementById('distSeeMore');
    if (bodyId === 'retrievalBody') return document.getElementById('retrievalSeeMore');
    return null;
}

/**
 * Offices wrap for body.
 *
 * @param {*} bodyId
 */
function officeWrapForBody(bodyId) {
    if (bodyId === 'distBody') return document.getElementById('distOfficeWrap');
    if (bodyId === 'retrievalBody') return document.getElementById('retrievalOfficeWrap');
    return null;
}

/**
 * Renders and synchronizes office see more in the DOM.
 *
 * @param {*} bodyId
 */
window.refreshOfficeSeeMore = function (bodyId) {
    const bodies = bodyId ? [bodyId] : ['distBody', 'retrievalBody'];
    bodies.forEach((id) => {
        const tbody = document.getElementById(id);
        const wrap = officeWrapForBody(id);
        const btn = officeSeeMoreBtnForBody(id);
        if (!tbody || !wrap || !btn) return;
        const count = tbody.querySelectorAll('tr.reg-office-added').length;
        if (count > OFFICE_SEE_MORE_MIN) {
            btn.classList.add('is-visible');
            const expanded = wrap.classList.contains('is-expanded');
            if (!expanded) wrap.classList.add('is-collapsed');
            btn.innerHTML = expanded
                ? '<i class="fa-solid fa-chevron-up"></i> See less'
                : ('<i class="fa-solid fa-chevron-down"></i> See more (' + (count - OFFICE_SEE_MORE_MIN) + ' more)');
        } else {
            btn.classList.remove('is-visible');
            wrap.classList.remove('is-collapsed', 'is-expanded');
            btn.innerHTML = 'See more';
        }
    });
};

/**
 * Toggles or switches office see more.
 *
 * @param {*} btn
 */
window.toggleOfficeSeeMore = function (btn) {
    const wrapId = btn.getAttribute('data-office-wrap');
    const wrap = document.getElementById(wrapId);
    if (!wrap) return;
    const bodyId = wrap.getAttribute('data-office-body');
    if (wrap.classList.contains('is-expanded')) {
        wrap.classList.remove('is-expanded');
        wrap.classList.add('is-collapsed');
    } else {
        wrap.classList.add('is-expanded');
        wrap.classList.remove('is-collapsed');
    }
    refreshOfficeSeeMore(bodyId);
};

document.addEventListener("click", function (e) {
    document.querySelectorAll(".reg-search-dropdown").forEach(dd => {
        if (!dd.parentElement.contains(e.target)) dd.style.display = "none";
    });
});

document.addEventListener('DOMContentLoaded', function () {
    refreshOfficeSeeMore();
});

} /* end __dcsEditRegisterScriptLoaded guard */
</script>
@include('pages.dcs.register.partials.dist-office-groups-script')
@include('pages.dcs.register.partials.scan-name-preview')
<script src="{{ asset('js/dcs/register-draft-guard.js') }}?v={{ filemtime(public_path('js/dcs/register-draft-guard.js')) }}"></script>
<script src="{{ asset('js/dcs/register-drf-no-unique.js') }}"></script>
<script src="{{ asset('js/dcs/register-number-suggest.js') }}?v={{ filemtime(public_path('js/dcs/register-number-suggest.js')) }}"></script>
@endif

<?php

use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('CSPC - Document Control System')] class extends Component {
    public int $requestId = 0;

    public function mount($id): void
    {
        $payload = RegisterQueryHelper::editPayload((int) $id);
        if (!empty($payload['blocked'])) {
            session()->flash('error', $payload['error']);
            $this->redirectRoute('dcs.register.update');
            return;
        }
        $this->requestId = (int) $id;
    }

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
    $allowsRetrieval = $reviseNo > 0;
@endphp
<script>
window.APP_CONFIG = {
    CURRENT_VERSION_ID: {{ $docRequest->version_id }},
    CURRENT_DOC_TYPE_ID: {{ $docRequest->doc_type_id }},
    CURRENT_SUB_TYPE_ID: {{ $docRequest->sub_type_id ?: 'null' }},
    CURRENT_APPROVAL_BODY: '{{ $approval?->approval_body_id ?? '' }}',
};
window.__existingRelatedDocs = @json($relatedDocsData);
window.__existingDrfOffices = @json($drfOfficesSeed);
window.__existingDcnOffices = @json($dcnOfficesSeed);
window.__existingMasterlistSource = @json($masterlistSourceSeed);
window.__existingMasterlistOriginator = @json($masterlistOriginatorSeed);
window.__existingSyllabiGroups = @json($syllabiGroupsSeed);
window.__syllabiEditLocked = true;
window.__allowsRetrieval = @json($allowsRetrieval);
window.__registerCatalog = @json($catalog);
</script>
<div class="reg-container main-content" id="dcsEditRoot" wire:ignore x-data="dcsRegisterPage()">
        <!-- Header -->
        <div class="reg-header">
            <div>
                <div class="reg-breadcrumb">Document Control System / Update / <span>Edit</span></div>
                <div style="display:flex; align-items:center; gap:12px;">
                    <div class="reg-title">Edit Document #{{ $docRequest->id }}</div>
                    <span class="edit-badge"><i class="fa-solid fa-pen"></i> Editing</span>
                </div>
            </div>
            <a href="{{ route('dcs.register.update') }}" class="reg-btn reg-btn-cancel">
                <i class="fa-solid fa-arrow-left"></i> Back to List
            </a>
        </div>

        <form id="masterForm" method="POST" action="{{ route('dcs.register.updateDoc', $docRequest->id) }}" enctype="multipart/form-data">
            <input type="hidden" id="requestId" value="{{ $docRequest->id }}">
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
                    <div class="reg-field">
                        <label>Sub-Type Document</label>
                        <select id="subType" name="sub_type_id" autocomplete="off" disabled>
                            <option value="" selected disabled>Select sub-type</option>
                        </select>
                        @if($docRequest->sub_type_id)
                            <input type="hidden" name="sub_type_id" value="{{ $docRequest->sub_type_id }}">
                        @endif
                    </div>
                </div>

                <div class="reg-panel-bottom">
                    <div id="dynamicCheckboxes" class="reg-checklist"></div>
                    <div class="reg-approval-toggle">
                        <span class="reg-toggle-label">Approval</span>
                        <div class="reg-toggle-options">
                            <label class="reg-radio">
                                <input type="radio" name="approval_status" value="applicable"
                                    {{ $docRequest->approval_status === 'applicable' ? 'checked' : '' }}
                                    onchange="handleApprovalToggle(true)">
                                Applicable
                            </label>
                            <label class="reg-radio">
                                <input type="radio" name="approval_status" value="not_applicable"
                                    {{ $docRequest->approval_status !== 'applicable' ? 'checked' : '' }}
                                    onchange="handleApprovalToggle(false)">
                                Not Applicable
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ SYLLABI / TOS-Rubrics (mirrors register — context locked on edit) ═══ -->
            <section class="reg-card" id="section-syllabi" style="display: {{ $isSyllabiLikeEdit ? 'block' : 'none' }};">
                <div class="reg-card-header">
                    <span>Syllabi</span>
                </div>
                <div class="reg-card-body">

                    <div class="reg-grid-4">
                        <div class="reg-field">
                            <label>College</label>
                            <select id="syllabiCollege" disabled>
                                <option value="" selected disabled>Select college</option>
                            </select>
                        </div>
                        <div class="reg-field">
                            <label>Program</label>
                            <select id="syllabiProgram" disabled>
                                <option value="" selected disabled>Select program</option>
                            </select>
                        </div>
                        <div class="reg-field">
                            <label>Semester</label>
                            <select id="syllabiSemester" disabled>
                                <option value="" selected disabled>Select semester</option>
                            </select>
                        </div>
                        <div class="reg-field">
                            <label>School Year</label>
                            <select id="syllabiSchoolYear" disabled>
                                <option value="" selected disabled>Select school year</option>
                            </select>
                        </div>
                    </div>
                    @if($syllabiContextSeed)
                        <input type="hidden" name="college_id" id="syllabiCollegeHidden" value="{{ $syllabiContextSeed->college_id }}">
                        <input type="hidden" name="program_id" id="syllabiProgramHidden" value="{{ $syllabiContextSeed->program_id }}">
                        <input type="hidden" name="semester_id" id="syllabiSemesterHidden" value="{{ $syllabiContextSeed->semester_id }}">
                        <input type="hidden" name="school_year_id" id="syllabiSchoolYearHidden" value="{{ $syllabiContextSeed->school_year_id }}">
                    @else
                        <input type="hidden" name="college_id" id="syllabiCollegeHidden" value="">
                        <input type="hidden" name="program_id" id="syllabiProgramHidden" value="">
                        <input type="hidden" name="semester_id" id="syllabiSemesterHidden" value="">
                        <input type="hidden" name="school_year_id" id="syllabiSchoolYearHidden" value="">
                    @endif

                    <div class="reg-grid-3">
                        <div class="reg-field">
                            <label>Document No.</label>
                            <input type="text" id="syllabiDocNo" name="syllabiDocNo" placeholder="Enter Document No." value="{{ $masterlist->doc_no ?? '' }}">
                        </div>
                        <div class="reg-field">
                            <label>Effectivity Date</label>
                            <input type="date" id="syllabiEffectivityDate" name="syllabiEffectivityDate" value="{{ \App\Helpers\RegisterQueryHelper::formatDate($masterlist->effectivity_date ?? '') }}">
                        </div>
                        <div class="reg-field">
                            <label>Deadline of Submission</label>
                            <input type="date" id="syllabiDeadline" name="syllabiDeadline" value="{{ \App\Helpers\RegisterQueryHelper::formatDate($masterlist->deadline ?? '') }}">
                        </div>
                    </div>
                    <div class="reg-field" style="margin-bottom: 16px;">
                        <label>Document Title</label>
                        <input type="text" id="syllabiDocTitle" name="syllabiDocTitle" placeholder="Enter Document Title" value="{{ $masterlist->doc_title ?? '' }}">
                    </div>

                    <div class="reg-wizard-steps" id="syllabiStepIndicator">
                        <div class="reg-wizard-step" :class="{ 'is-active': syllabiStep === 1, 'is-done': syllabiStep > 1 }" data-step="1"><span>1</span> Course Info</div>
                        <div class="reg-wizard-step" :class="{ 'is-active': syllabiStep === 2 }" data-step="2"><span>2</span> DRF</div>
                    </div>

                    <div class="reg-field">
                        <div class="reg-table-wrap">
                            <table class="reg-table reg-wizard-table" id="syllabiWizardTable" :data-active-step="syllabiStep">
                                <thead>
                                    <tr>
                                        <th class="col-pinned">Course Name</th>
                                        <th class="col-pinned">Course Code</th>
                                        <th class="col-step1" id="syllabiAvailabilityHeader">Syllabi Availability</th>
                                        <th class="col-step1">No. Copies</th>
                                        <th class="col-shared">Faculty</th>
                                        <th class="col-step1">No. Pages</th>
                                        <th class="col-step1">Date Received</th>
                                        <th class="col-step1">Time Received</th>

                                        <th class="col-step2">DRF Availability</th>
                                        <th class="col-step2">DRF No.</th>
                                        <th class="col-step2">DRF Date</th>
                                        <th class="col-step2">DRF Received Date</th>
                                        <th class="col-step2">Scanned DRF</th>

                                        <th class="col-pinned"></th>
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

                    <div class="reg-wizard-nav">
                        <button type="button" class="reg-btn reg-btn-cancel" id="syllabiBackBtn" x-show="syllabiStep === 2" @click="setSyllabiStep(1)">
                            <i class="fa-solid fa-arrow-left"></i> Back
                        </button>
                        <button type="button" class="reg-btn reg-btn-save" id="syllabiNextBtn" x-show="syllabiStep === 1" @click="setSyllabiStep(2)">
                            Next <i class="fa-solid fa-arrow-right"></i>
                        </button>
                        <span id="syllabiStep2Hint" class="reg-wizard-hint" x-show="syllabiStep === 2">
                            Scroll down and click <strong>Update Document</strong> to submit.
                        </span>
                    </div>
                </div>
            </section>

            <section class="reg-card" id="section-2" style="display: {{ $dcn ? 'block' : 'none' }};">
                <div class="reg-card-header">
                    <span>Document Change Notice</span>
                </div>
                <div class="reg-card-body">
                    <div class="reg-field reg-revision-table">
                        <label>Document Revisions</label>
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
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="revisionTableBody">
                                    @forelse($revisions as $rev)
                                    <tr data-linked="true">
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
                                                <a href="{{ asset('storage/' . $rev->scanned_copy) }}" target="_blank" class="reg-revrow-viewfile" title="View scanned copy">
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
                                        <td><button type="button" class="reg-row-del" onclick="removeRevisionRow(this)"><i class="fa-solid fa-trash-can"></i></button></td>
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
                                        <td class="reg-rev-scan-cell" style="text-align:center;color:#94a3b8;">—</td>
                                        <td class="reg-rev-purpose-cell">
                                            <input type="hidden" name="revisionPurpose[]" value="">
                                            <span class="reg-rev-purpose-text">—</span>
                                        </td>
                                        <td><button type="button" class="reg-row-del" onclick="removeRevisionRow(this)"><i class="fa-solid fa-trash-can"></i></button></td>
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
                            <input type="text" id="dcnNumber" name="dcnNumber" placeholder="DCN-001" value="{{ $dcn->dcn_no ?? '' }}">
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
                                    <a href="{{ asset('storage/' . $dcn->scanned_dcn) }}" target="_blank">View</a>
                                </div>
                            @endif
                            <label class="reg-upload">
                                <input type="file" id="dcnFile" name="dcnFile" accept=".pdf">
                                <i class="fa-solid fa-cloud-arrow-up"></i>
                                <span>{{ $dcn && $dcn->scanned_dcn ? 'Replace file' : 'Choose scanned PDF' }}</span>
                            </label>
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
                            <input type="text" id="drfNo" name="drfNo" placeholder="DRF-001" value="{{ $drf->drf_no ?? '' }}">
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
                                <a href="{{ asset('storage/' . $drf->scanned_drf) }}" target="_blank">View</a>
                            </div>
                        @endif
                        <label class="reg-upload">
                            <input type="file" id="drfFile" name="drfFile" accept=".pdf">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <span>{{ $drf && $drf->scanned_drf ? 'Replace file' : 'Choose scanned PDF' }}</span>
                        </label>
                    </div>
                </div>
            </section>

            <!-- ═══ APPROVAL ═══ -->
            <section class="reg-card" id="section-approval" style="display: {{ $approval ? 'block' : 'none' }};">
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
                            <input type="text" id="masterlistDocNo" name="masterlistDocNo" placeholder="CSPC-INT.DOC-137" value="{{ $masterlist->doc_no ?? '' }}">
                            <span id="docNoHint" style="display:block;margin-top:4px;font-size:12px;"></span>
                        </div>
                        <div class="reg-field">
                            <label>Deadline of Submission</label>
                            <input type="date" id="deadlineOfSubmission" name="deadlineOfSubmission" value="{{ \App\Helpers\RegisterQueryHelper::formatDate($masterlist->deadline ?? '') }}">
                        </div>
                        <div class="reg-field">
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
                        <div class="reg-field">
                            <label>Document Registered</label>
                            <div class="reg-dual">
                                <input type="date" id="masterlistRegisteredDate" name="masterlistRegisteredDate" value="{{ \App\Helpers\RegisterQueryHelper::formatDate($masterlist->doc_registered_date ?? '') }}" oninput="calcMasterlistTimeSpent()">
                                <input type="time" id="masterlistRegisteredTime" name="masterlistRegisteredTime" value="{{ \App\Helpers\RegisterQueryHelper::formatTime($masterlist->doc_registered_time ?? '') }}" oninput="calcMasterlistTimeSpent()">
                            </div>
                        </div>
                    </div>
                    <div class="reg-ml-mid">
                        <div class="reg-field">
                            <label>Effectivity Date</label>
                            <input type="date" id="masterlistEffectivityDate" name="masterlistEffectivityDate" value="{{ \App\Helpers\RegisterQueryHelper::formatDate($masterlist->effectivity_date ?? '') }}">
                        </div>
                        <div class="reg-field">
                            <label>Revision No.</label>
                            <input type="number" id="masterlistRevisionNo" placeholder="0"
                                value="{{ $masterlist->revise_no ?? '' }}" disabled
                                style="background:#f1f5f9; cursor:not-allowed; opacity:0.7;">
                            <input type="hidden" name="masterlistRevisionNo" value="{{ $masterlist->revise_no ?? '0' }}">
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
                                        placeholder="Type a name" autocomplete="off">
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
                                <input type="hidden" id="keywords" name="keywords" value="{{ $masterlist->keywords ?? $masterlist->brief_purpose ?? '' }}">
                                <p class="reg-keywords-hint">Press Enter or comma to add. Click × to remove.</p>
                            </div>
                        </div>
                        <div class="reg-field">
                            <label>Related Documents</label>
                            <div class="reg-reldocs" id="relatedDocsWidget">
                                <div class="reg-reldocs-inputwrap">
                                    <input type="text" id="relatedDocsSearch" class="reg-reldocs-input"
                                        placeholder="Type a document title to search..."
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
                                <button type="button" class="reg-current-file-view" data-preview-url="{{ asset('storage/' . $masterlist->scanned_masterlist) }}" data-preview-title="{{ $mlScanLabel }}">View</button>
                            </div>
                        @endif
                        <label class="reg-upload">
                            <input type="file" id="uploadScannedCopy" name="uploadScannedCopy" accept=".pdf">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <span>{{ $masterlist && $masterlist->scanned_masterlist ? 'Replace file' : 'Choose scanned PDF' }}</span>
                        </label>
                    </div>
                </div>
            </section>

            <!-- ═══ SECTION 4 — RETRIEVAL (only for revise_no > 0) ═══ -->
            <section class="reg-card" id="section-4" style="display: {{ ($allowsRetrieval && ($retrieval || $retrievalOffices->isNotEmpty() || ($retrievedOfficesHidden ?? collect())->isNotEmpty())) ? 'block' : 'none' }};">
                <div class="reg-card-header">
                    <span>Document Retrieval</span>
                </div>
                <div class="reg-card-body reg-split">
                    <div class="reg-split-left">
                        <div class="reg-split-form-grid">
                            <div class="reg-split-form-stack">
                                <div class="reg-field">
                                    <label>Retrieval Form Date</label>
                                    <div class="reg-dual">
                                        <input type="date" id="retrievalFormDate" name="retrievalFormDate" value="{{ \App\Helpers\RegisterQueryHelper::formatDate($retrieval->doc_retrieval_date_file ?? '') }}" oninput="calcRetrievalTimeSpent()">
                                        <input type="time" id="retrievalFormTime" name="retrievalFormTime" value="{{ \App\Helpers\RegisterQueryHelper::formatTime($retrieval->doc_retrieval_time_file ?? '') }}" oninput="calcRetrievalTimeSpent()">
                                    </div>
                                </div>
                                <div class="reg-field">
                                    <label>Retrieval Date & Time</label>
                                    <div class="reg-dual">
                                        <input type="date" id="retrievalDate" name="retrievalDate" value="{{ \App\Helpers\RegisterQueryHelper::formatDate($retrieval->doc_retrieval_date_actual ?? '') }}" oninput="calcRetrievalTimeSpent()">
                                        <input type="time" id="retrievalTime" name="retrievalTime" value="{{ \App\Helpers\RegisterQueryHelper::formatTime($retrieval->doc_retrieval_time_actual ?? '') }}" oninput="calcRetrievalTimeSpent()">
                                    </div>
                                </div>
                            </div>
                            <div class="reg-field">
                                <label>Time Spent/Minute(s)</label>
                                <input type="text" id="retrievalTimeSpentDisplay" readonly placeholder="--" style="background: #f8fafc; cursor: default;">
                                <input type="hidden" id="retrievalTimeSpent" name="retrievalTimeSpent">
                            </div>
                        </div>
                        <div class="reg-field">
                            <label>Remarks</label>
                            <input type="text" id="retrievalRemarks" name="retrievalRemarks" placeholder="Optional remarks" value="{{ $retrieval->remarks ?? '' }}">
                        </div>
                        <div class="reg-field">
                            <label>Upload Scanned D&amp;R</label>
                            @if($retrieval && $retrieval->scanned_retrieval)
                                <div class="reg-current-file">
                                    <i class="fa-solid fa-file-pdf"></i>
                                    <span>{{ basename($retrieval->scanned_retrieval) }}</span>
                                    <a href="{{ asset('storage/' . $retrieval->scanned_retrieval) }}" target="_blank">View</a>
                                </div>
                            @endif
                            <label class="reg-upload">
                                <input type="file" id="scannedRet" name="scannedRet" accept=".pdf">
                                <i class="fa-solid fa-cloud-arrow-up"></i>
                                <span>{{ $retrieval && $retrieval->scanned_retrieval ? 'Replace file' : 'Choose scanned PDF' }}</span>
                            </label>
                        </div>
                    </div>
                    <div class="reg-split-right">
                        <div class="reg-field">
                            <label>Office retrieval status</label>
                            <p class="reg-field-hint">Mark as Retrieved to move an office into Distribution on this form. Remove it from Distribution to put it back as Pending in Retrieval. On the next revision, retrieved offices start again as Pending in Retrieval.</p>
                            <div class="reg-search">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="11" cy="11" r="8"/>
                                    <path d="M21 21l-4.35-4.35"/>
                                </svg>
                                <input type="text" id="retrievalSearch" placeholder="Search and add office..."
                                    oninput="handleSearch(this, 'retrievalResults', 'retrievalBody', 'retrievalTotal')"
                                    autocomplete="off">
                                <div id="retrievalResults" class="reg-search-dropdown" style="display:none;"></div>
                            </div>
                        </div>
                        <div class="reg-office-table-wrap">
                            <table class="reg-dist-table">
                                <thead>
                                    <tr>
                                        <th>Receiving Office(s)</th>
                                        <th style="width:130px;">Status</th>
                                        <th style="width:110px; text-align:center;">No. of Copies</th>
                                        <th style="width:40px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="retrievalBody">
                                    @forelse($retrievalOffices as $retOff)
                                    @php $retStatus = ($retOff->retrieval_status ?? 'pending') === 'retrieved' ? 'retrieved' : 'pending'; @endphp
                                    <tr class="reg-office-added">
                                        <td>
                                            <input type="hidden" name="retrievalOffice[]" value="{{ $retOff->office_id }}">
                                            <div class="reg-office-name">
                                                <div class="reg-office-icon"><i class="fa-solid fa-building"></i></div>
                                                <span class="reg-office-text">{{ $retOff->office_name ?? 'Unknown' }}</span>
                                            </div>
                                        </td>
                                        <td>
                                            <select name="retrievalStatus[]" class="reg-retrieval-status" onchange="handleRetrievalStatusChange(this)">
                                                <option value="pending" @selected($retStatus === 'pending')>Pending</option>
                                                <option value="retrieved" @selected($retStatus === 'retrieved')>Retrieved</option>
                                            </select>
                                        </td>
                                        <td style="text-align:center;">
                                            <input type="number" name="retrievalCopies[]" value="{{ $retOff->copies }}" min="1" oninput="updateTotal('retrievalTotal', 'retrievalBody')">
                                        </td>
                                        <td>
                                            <button type="button" class="btn-remove" onclick="removeOffice(this, 'retrievalTotal', 'retrievalBody')">
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
                                {{-- Retrieved offices leave the visible list but stay posted so status is preserved. --}}
                                <tbody id="retrievalRetrievedHidden" style="display:none;" aria-hidden="true">
                                    @foreach(($retrievedOfficesHidden ?? collect()) as $retOff)
                                    <tr>
                                        <td>
                                            <input type="hidden" name="retrievalOffice[]" value="{{ $retOff->office_id }}">
                                            <input type="hidden" name="retrievalStatus[]" value="retrieved">
                                            <input type="hidden" name="retrievalCopies[]" value="{{ $retOff->copies ?? 1 }}">
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="2">Total No. of Copies</td>
                                        <td id="retrievalTotal" style="text-align:center; font-weight:700;">{{ $retrievalOffices->sum('copies') }}</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
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
                                        <input type="date" id="distributionFormDate" name="distributionFormDate" value="{{ \App\Helpers\RegisterQueryHelper::formatDate($distribution->doc_distribution_date_file ?? '') }}" oninput="calcDistributionTimeSpent()">
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
                                    <a href="{{ asset('storage/' . $distribution->scanned_distribution) }}" target="_blank">View</a>
                                </div>
                            @endif
                            <label class="reg-upload">
                                <input type="file" id="scanneddist" name="scanneddist" accept=".pdf">
                                <i class="fa-solid fa-cloud-arrow-up"></i>
                                <span>{{ $distribution && $distribution->scanned_distribution ? 'Replace file' : 'Choose scanned PDF' }}</span>
                            </label>
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
                        </div>
                        <div class="reg-office-table-wrap">
                            <table class="reg-dist-table">
                                <thead>
                                    <tr>
                                        <th>Receiving Office(s)</th>
                                        <th style="width:110px; text-align:center;">No. of Copies</th>
                                        <th style="width:40px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="distBody">
                                    @forelse($distributionOffices as $distOff)
                                    
                                    <tr class="reg-office-added" draggable="true">
                                        <td>
                                            <input type="hidden" name="distOffice[]" value="{{ $distOff->office_id }}">
                                            <div class="reg-office-name">
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
                                        <td colspan="3">
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
                                        <td>Total No. of Copies</td>
                                        <td id="distTotal" style="text-align:center; font-weight:700;">{{ $distributionOffices->sum('copies') }}</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ═══ FORM ACTIONS ═══ -->
            <div class="reg-actions" id="formActions" style="display: flex;">
                <div class="reg-actions-left">
                    <a href="{{ route('dcs.register.update') }}" class="reg-btn reg-btn-cancel">
                        <i class="fa-solid fa-xmark"></i> Cancel
                    </a>
                </div>
                <div class="reg-actions-right">
                    <button type="button" id="btnGenerateDistribution" class="reg-btn reg-btn-generate" onclick="generateDistributionTemplate()">
                        <i class="fa-solid fa-file-lines"></i> Generate
                    </button>
                    <button type="button" class="reg-btn reg-btn-save" onclick="confirmSave()">
                        <i class="fa-solid fa-floppy-disk"></i> Update Document
                    </button>
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
            <div class="reg-modal-body" id="reviewContent">
                <!-- Populated by JS -->
            </div>
            <div class="reg-modal-footer">
                <button type="button" class="reg-btn reg-btn-cancel" @click="closeReview()">
                    <i class="fa-solid fa-xmark"></i> Go Back
                </button>
                <button type="button" class="reg-btn reg-btn-save" onclick="submitForm()">
                    <i class="fa-solid fa-check"></i> Confirm Update
                </button>
            </div>
        </div>
    </div>
    </template>

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
                <iframe id="filePreviewModalFrame" title="Uploaded file preview"></iframe>
            </div>
        </div>
    </div>
    </template>
    </div>


<script>

document.addEventListener('alpine:init', () => {
    Alpine.data('dcsRegisterPage', () => ({
        syllabiStep: 1,
        reviewOpen: false,
        setSyllabiStep(step) { this.syllabiStep = step; window.syllabiCurrentStep = step; },
        closeReview() {
            this.reviewOpen = false;
            const el = document.getElementById('dcsEditRoot');
            if (el) el.style.overflow = '';
        },
        addSyllabiRow() { if (typeof window.addSyllabiRow === 'function') window.addSyllabiRow(); },
    }));
});
let allOffices = [];
let allDocTypes = [];
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

function isSyllabiLikeSubType(subTypeId) {
    const t = (allDocTypes || []).find(d => String(d.doc_type_id) === String(subTypeId));
    return !!(t && t.is_syllabi_like);
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

// ══════════════════════════════════════════════
// SHARED HELPERS
// ══════════════════════════════════════════════
function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function checkFile(file) {
    const ext = file.name.split('.').pop().toLowerCase();
    if (!ALLOWED_EXTENSIONS.includes(ext)) return { valid: false, ext, reason: 'type' };
    if (file.size > MAX_FILE_SIZE) return { valid: false, ext, reason: 'size', sizeMB: (file.size / (1024 * 1024)).toFixed(1) };
    return { valid: true, ext };
}

function fileTypeErrorMessage(check, file) {
    return check.reason === 'type'
        ? '"' + check.ext + '" is not allowed. Only scanned PDF files are accepted.'
        : '"' + file.name + '" is ' + check.sizeMB + 'MB. Maximum file size is 200MB.';
}

function setFileIcon(icon, ext) {
    icon.className = 'fa-solid fa-file-pdf';
    icon.style.color = 'var(--reg-error)';
}

function clearFileIcon(icon, label, originalText) {
    icon.className = 'fa-solid fa-cloud-arrow-up';
    icon.style.color = '';
    label.textContent = originalText;
    label.style.color = '';
    label.style.fontWeight = '';
}

function computeDuration(startDate, startTime, endDate, endTime) {
    if (!startDate || !startTime || !endDate || !endTime) return null;
    const start = new Date(startDate + "T" + startTime);
    const end = new Date(endDate + "T" + endTime);
    const diffMs = end - start;
    if (diffMs < 0) return { invalid: true };
    return { invalid: false, totalMinutes: Math.floor(diffMs / 60000) };
}

function formatDuration(totalMinutes) {
    const days = Math.floor(totalMinutes / 1440);
    const hours = Math.floor((totalMinutes % 1440) / 60);
    const minutes = totalMinutes % 60;
    if (days > 0) return days + "d " + hours + "hr " + minutes + "min";
    if (hours > 0) return hours + "hr " + minutes + "min";
    return minutes + " min";
}

function officeMatchesQuery(o, q) {
    const needle = (q || '').trim().toLowerCase();
    if (!needle) return false;
    const name = (o.office_name || '').toLowerCase();
    const code = (o.office_code || '').toLowerCase();
    return name.includes(needle) || code.includes(needle);
}

function findOfficeByLabelOrCode(part, list) {
    const n = (part || '').trim().toLowerCase();
    if (!n) return null;
    const haystack = list || allOffices;
    return haystack.find(o =>
        (o.office_name || '').toLowerCase() === n ||
        (o.office_code || '').toLowerCase() === n
    ) || null;
}

function filterOffices(query) {
    const q = query.trim().toLowerCase();
    if (q.length < 1) return [];
    return allOffices.filter(o => officeMatchesQuery(o, q));
}

function emptyOfficeRowHTML(tbodyId) {
    const isRetrieval = tbodyId === 'retrievalBody';
    const cols = isRetrieval ? 4 : 3;
    return '<tr class="reg-empty-row"><td colspan="' + cols + '"><div class="reg-empty-state"><i class="fa-solid fa-building-circle-xmark"></i><span>No offices added yet</span></div></td></tr>';
}

function retrievalStatusSelectHTML(status) {
    const value = status === 'retrieved' ? 'retrieved' : 'pending';
    return '<select name="retrievalStatus[]" class="reg-retrieval-status" onchange="handleRetrievalStatusChange(this)">' +
        '<option value="pending"' + (value === 'pending' ? ' selected' : '') + '>Pending</option>' +
        '<option value="retrieved"' + (value === 'retrieved' ? ' selected' : '') + '>Retrieved</option>' +
    '</select>';
}

function distBodyHasOffice(officeId) {
    const tbody = document.getElementById('distBody');
    if (!tbody) return false;
    return [...tbody.querySelectorAll('input[type="hidden"][name="distOffice[]"]')]
        .some(inp => String(inp.value) === String(officeId));
}

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

function isOfficeInRetrievedHidden(officeId) {
    const hidden = document.getElementById('retrievalRetrievedHidden');
    if (!hidden) return false;
    return [...hidden.querySelectorAll('input[name="retrievalOffice[]"]')]
        .some(inp => String(inp.value) === String(officeId));
}

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
            '<td>' + retrievalStatusSelectHTML('pending') + '</td>' +
            '<td style="text-align:center;"><input type="number" name="retrievalCopies[]" value="' + useCopies +
            '" min="1" oninput="updateTotal(\'retrievalTotal\', \'retrievalBody\')"></td>' +
            '<td><button type="button" class="btn-remove" onclick="removeOffice(this, \'retrievalTotal\', \'retrievalBody\')">' +
            '<i class="fa-solid fa-xmark"></i></button></td>';
        tbody.appendChild(tr);
    }
    updateTotal('retrievalTotal', 'retrievalBody');
    const retSection = document.getElementById('section-4');
    if (retSection) retSection.style.display = 'block';
}

function maybeRestoreDistOfficeToRetrieval(tr) {
    if (!tr) return;
    const officeId = tr.querySelector('input[type="hidden"][name="distOffice[]"]')?.value;
    if (!officeId) return;
    const fromRetrieval = tr.dataset.fromRetrieval === '1' || isOfficeInRetrievedHidden(officeId);
    if (!fromRetrieval) return;
    const officeName = tr.querySelector('.reg-office-text')?.textContent?.trim() || 'Office';
    const copies = tr.querySelector('input[type="number"][name="distCopies[]"]')?.value || 1;
    restoreOfficeToRetrievalPending(officeId, officeName, copies);
}

window.handleRetrievalStatusChange = function (select) {
    const tr = select?.closest('tr');
    if (!tr) return;
    const officeId = tr.querySelector('input[type="hidden"][name="retrievalOffice[]"]')?.value;
    const officeName = tr.querySelector('.reg-office-text')?.textContent?.trim() || 'Office';
    const copies = tr.querySelector('input[type="number"][name="retrievalCopies[]"]')?.value || 1;
    if (select.value === 'retrieved') {
        addRetrievedOfficeToDistribution(officeId, officeName, copies);
        moveRetrievalRowToHidden(tr, officeId, copies);
        const distSection = document.getElementById('section-5');
        if (distSection) distSection.style.display = 'block';
    } else {
        removeRetrievedOfficeFromDistribution(officeId);
    }
};

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
        const row = document.createElement('tr');
        row.innerHTML = '<td>' +
            '<input type="hidden" name="retrievalOffice[]" value="' + String(officeId).replace(/"/g, '') + '">' +
            '<input type="hidden" name="retrievalStatus[]" value="retrieved">' +
            '<input type="hidden" name="retrievalCopies[]" value="' + String(copies).replace(/"/g, '') + '">' +
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
        <td>${retrievalStatusSelectHTML(status || 'pending')}</td>
        <td style="text-align:center;"><input type="number" name="retrievalCopies[]" value="${copies}" min="1" oninput="updateTotal('${totalId}', '${tbodyId}')"></td>
        <td><button type="button" class="btn-remove" onclick="removeOffice(this, '${totalId}', '${tbodyId}')"><i class="fa-solid fa-xmark"></i></button></td>
    `;
    tbody.appendChild(tr);
    if ((status || 'pending') === 'retrieved') {
        addRetrievedOfficeToDistribution(officeId, officeName, copies);
        if (!keepInRetrieval) {
            moveRetrievalRowToHidden(tr, officeId, copies);
        }
    } else {
        removeRetrievedOfficeFromDistribution(officeId);
    }
}

function filterItems(list, labelKey, query) {
    const q = query.trim().toLowerCase();
    if (q.length < 1) return [];
    return list.filter(o => {
        if ((o[labelKey] || '').toLowerCase().includes(q)) return true;
        if ((o.office_code || '').toLowerCase().includes(q)) return true;
        return false;
    });
}

function seedOfficeRow(tbodyId, totalId, officeId, officeName, copies) {
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
    tr.innerHTML = `
        <td><input type="hidden" name="${officeNameAttr}" value="${officeId}"><div class="reg-office-name"><div class="reg-office-icon"><i class="fa-solid fa-building"></i></div><span class="reg-office-text">${escapeHtml(officeName)}</span></div></td>
        <td style="text-align:center;"><input type="number" name="${copiesNameAttr}" value="${copies}" min="1" oninput="updateTotal('${totalId}', '${tbodyId}')"></td>
        <td><button type="button" class="btn-remove" onclick="removeOffice(this, '${totalId}', '${tbodyId}')"><i class="fa-solid fa-xmark"></i></button></td>
    `;
    tbody.appendChild(tr);
}

function tableIsEmpty(tbodyId) {
    const tbody = document.getElementById(tbodyId);
    if (!tbody) return true;
    return tbody.querySelectorAll('input[type="hidden"]').length === 0;
}

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

function resetTextLikeInputs(el) {
    el.querySelectorAll('input[type="text"], input[type="number"], input[type="date"], input[type="time"], textarea').forEach(input => { input.value = ''; });
    el.querySelectorAll('select').forEach(sel => { sel.selectedIndex = 0; });
}

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

function resetUploadCell(cell, icon, label, originalText) {
    cell.classList.remove('reg-upload-cell-success', 'reg-upload-cell-error');
    cell.style.borderColor = '';
    cell.style.background = '';
    clearFileIcon(icon, label, originalText);
    removeExistingError(cell);
}

function getSelectTextWithCode(id) {
    const el = document.getElementById(id);
    if (!el || el.selectedIndex < 0) return "";
    const opt = el.options[el.selectedIndex];
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
        const url = btn.dataset.previewUrl;
        const title = btn.dataset.previewTitle || 'File preview';
        if (url) openFilePreviewModal(url, title);
    });
    document.querySelectorAll('#retrievalBody tr.reg-office-added').forEach(tr => {
        if (!allowsDocumentRetrieval()) return;
        const status = tr.querySelector('.reg-retrieval-status')?.value;
        if (status !== 'retrieved') return;
        // Own mark-as-retrieved on this form: move out of visible Retrieval into Distribution.
        const select = tr.querySelector('.reg-retrieval-status');
        if (select && typeof handleRetrievalStatusChange === 'function') {
            handleRetrievalStatusChange(select);
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

    try {
        const catalog = window.__registerCatalog || {};
        const offices = catalog.offices || [];
        const docTypes = catalog.docTypes || [];
        const versionTypes = catalog.versionTypes || [];
        const approvalBodies = catalog.approvalBodies || [];
        const originators = catalog.originators || [];

        allOffices = Array.isArray(offices) ? offices : [];
        allDocTypes = Array.isArray(docTypes) ? docTypes : [];
        allOriginators = Array.isArray(originators) ? originators : [];
        allFaculties = [];

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
        const children = allDocTypes.filter(d => d.parent_id == CURRENT_DOC_TYPE_ID);
        if (children.length > 0) {
            children.forEach(c => {
                const opt = new Option(c.doc_type_name, c.doc_type_id);
                if (c.doc_type_id == CURRENT_SUB_TYPE_ID) opt.selected = true;
                subTypeSelect.add(opt);
            });
            subTypeSelect.dataset.lastValid = CURRENT_SUB_TYPE_ID || "";
        } else {
            subTypeSelect.dataset.lastValid = "";
        }
        subTypeSelect.disabled = true;

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
        wireSyllabiMasterlistSync();
        wireApprovalDeadlineSync();

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

        // ── Version type change → apply revision mode ──
        const versionTypeEl = document.getElementById("versionType");
        if (versionTypeEl) {
            versionTypeEl.addEventListener('change', () => { applyRevisionMode(); });
        }
        applyRevisionMode();

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
            singleSelect: true,
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
        seedExistingSyllabiGroups();

        // Register page wires context dropdowns for auto-fill; edit locks them read-only.
        if (!window.__syllabiEditLocked) {
            initSyllabiContextWiring();
        }

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
    (function () {
        const updateBtn = document.querySelector('.reg-btn-save');
        if (!updateBtn) return;
        const initialState = {};

        function captureInitialState() {
            const form = document.getElementById('masterForm');
            if (!form) return;
            form.querySelectorAll('input, select, textarea').forEach(el => {
                const key = el.name || el.id;
                if (!key) return;
                if (el.type === 'checkbox') {
                    initialState[key + '|' + (el.value || '')] = el.checked;
                } else if (el.type === 'radio') {
                    initialState[key] = document.querySelector('input[name="' + el.name + '"]:checked')?.value || '';
                } else if (el.type === 'file') {
                    initialState['file|' + (el.name || el.id)] = '';
                } else {
                    initialState[key] = el.value;
                }
            });
            ['section-1', 'section-2', 'section-3', 'section-4', 'section-5', 'section-approval', 'section-syllabi'].forEach(id => {
                const el = document.getElementById(id);
                if (el) initialState['visible|' + id] = el.style.display !== 'none';
            });
            const retBody = document.getElementById('retrievalBody');
            const distBody = document.getElementById('distBody');
            initialState['officeCount|retrievalBody'] = retBody ? retBody.querySelectorAll('input[type="hidden"]').length : 0;
            initialState['officeCount|distBody'] = distBody ? distBody.querySelectorAll('input[type="hidden"]').length : 0;
        }

        let stateCaptured = false;
        function isDirty() {
            if (!stateCaptured) return true;
            const form = document.getElementById('masterForm');
            if (!form) return false;
            let dirty = false;
            form.querySelectorAll('input, select, textarea').forEach(el => {
                if (dirty) return;
                const key = el.name || el.id;
                if (!key) return;
                if (el.type === 'checkbox') {
                    const initVal = initialState[key + '|' + (el.value || '')];
                    if (initVal !== undefined && el.checked !== initVal) dirty = true;
                } else if (el.type === 'radio') {
                    const current = document.querySelector('input[name="' + el.name + '"]:checked')?.value || '';
                    if (initialState[key] !== undefined && current !== initialState[key]) dirty = true;
                } else if (el.type === 'file') {
                    if (el.files && el.files.length > 0) dirty = true;
                } else {
                    if (initialState[key] !== undefined && el.value !== initialState[key]) dirty = true;
                }
            });
            ['section-1', 'section-2', 'section-3', 'section-4', 'section-5', 'section-approval', 'section-syllabi'].forEach(id => {
                if (dirty) return;
                const el = document.getElementById(id);
                if (el && initialState['visible|' + id] !== undefined) {
                    if ((el.style.display !== 'none') !== initialState['visible|' + id]) dirty = true;
                }
            });
            ['retrievalBody', 'distBody'].forEach(bodyId => {
                if (dirty) return;
                const tbody = document.getElementById(bodyId);
                if (!tbody) return;
                const currentCount = tbody.querySelectorAll('input[type="hidden"]').length;
                if (initialState['officeCount|' + bodyId] !== undefined && currentCount !== initialState['officeCount|' + bodyId]) dirty = true;
            });
            return dirty;
        }

        function updateButtonState() {
            if (isDirty()) {
                updateBtn.disabled = false;
                updateBtn.style.opacity = '';
                updateBtn.style.cursor = '';
                updateBtn.title = '';
            } else {
                updateBtn.disabled = true;
                updateBtn.style.opacity = '0.45';
                updateBtn.style.cursor = 'not-allowed';
                updateBtn.title = 'No changes detected';
            }
        }

        setTimeout(() => { captureInitialState(); stateCaptured = true; updateButtonState(); }, 2000);

        const form = document.getElementById('masterForm');
        if (form) {
            form.addEventListener('input', updateButtonState);
            form.addEventListener('change', updateButtonState);
        }
        const observer = new MutationObserver(() => { setTimeout(updateButtonState, 100); });
        if (form) observer.observe(form, { childList: true, subtree: true });

        const originalConfirmSave = window.confirmSave;
        window.confirmSave = function () {
            if (!isDirty()) return;
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

function revertAutofill() {
    const selectIds = ["versionType", "docType", "subType", "approvalBody"];
    selectIds.forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        const lastValid = el.dataset.lastValid;
        if (lastValid !== undefined && lastValid !== "" && el.value !== lastValid) el.value = lastValid;
    });
}

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

function allowsDocumentRetrieval() {
    return window.__allowsRetrieval === true;
}

function filterChecklistsForMode(checklists) {
    const list = Array.isArray(checklists) ? checklists : [];
    if (allowsDocumentRetrieval()) return list;
    return list.filter(c => parseInt(c.checklist_id, 10) !== 4);
}

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
        if (disabled) cb.disabled = true;

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
}

function syncChecklistHiddenInputs() {
    const container = document.getElementById('dynamicCheckboxes');
    if (!container) return;
    container.querySelectorAll('input[type="hidden"][data-checklist-hidden]').forEach(el => el.remove());
    container.querySelectorAll('input[type="checkbox"]').forEach(cb => {
        if (!cb.checked) return;
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'checklists[]';
        hidden.value = cb.value;
        hidden.dataset.checklistHidden = 'true';
        container.appendChild(hidden);
    });
}

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
        cb.disabled = true;
        cb.dataset.lastChecked = cb.checked ? "true" : "false";
        toggleSection(parseInt(cb.value), cb.checked);
    });
    syncChecklistHiddenInputs();
    showFormActions();
    enableApproval();
    setTimeout(initFileInputs, 100);
}

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

function showFormActions() {
    const el = document.getElementById("formActions");
    if (el) el.style.display = "flex";
}

function hideFormActions() {
    const el = document.getElementById("formActions");
    if (el) el.style.display = "none";
}

// ══════════════════════════════════════════════
// DOCUMENT NO. LOOKUP
// ══════════════════════════════════════════════
function initDocNoLookup(revField) {
    const docNoInput = document.getElementById('masterlistDocNo');
    const hintEl = document.getElementById('docNoHint');
    if (!docNoInput) return;

    let docNoTimer = null;
    docNoInput.addEventListener('input', () => {
        clearTimeout(docNoTimer);
        const docNo = docNoInput.value.trim();
        if (!docNo) { handleEmptyDocNo(hintEl, revField); return; }
        if (hintEl) {
            hintEl.innerHTML = '<span style="color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> Checking document...</span>';
            hintEl.style.color = '';
            hintEl.dataset.valid = '';
        }
        docNoTimer = setTimeout(() => runDocNoLookup(docNo, hintEl, revField), 500);
    });
}

function handleEmptyDocNo(hintEl, revField) {
    docNoDuplicate = false;
    if (hintEl) { hintEl.innerHTML = ''; hintEl.dataset.valid = ''; }
}

async function runDocNoLookup(docNo, hintEl, revField) {
    try {
        const docTypeId = document.getElementById('docType').value;
        const subTypeId = document.getElementById('subType').value;
        const excludeRequestId = document.getElementById('requestId')?.value || '';
        const url = '/dcs/register/check-docno?doc_no=' + encodeURIComponent(docNo) +
                    (docTypeId ? '&doc_type_id=' + docTypeId : '') +
                    (subTypeId ? '&sub_type_id=' + subTypeId : '') +
                    (excludeRequestId ? '&exclude_request_id=' + excludeRequestId : '');
        const res = await fetch(url);
        const data = await res.json();

        if (data.exists && !data.is_self) {
            docNoDuplicate = true;
            if (hintEl) {
                hintEl.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' +
                    'This document number is already registered under <strong>' +
                    escapeHtml(data.existing_type_name || 'this document type') +
                    '</strong>. Please use a unique document number.' +
                    '<br><span style="font-weight:400;font-size:11px;">You cannot save until you enter a unique document number.</span>';
                hintEl.style.color = '#dc2626';
                hintEl.dataset.valid = 'duplicate';
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
        }
    } catch (e) {
        console.error('DocNo lookup failed:', e);
        docNoDuplicate = false;
    }
}

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
        if (options.forceNextRev || !revFieldEl.value || revFieldEl.dataset.userEdited !== 'true') {
            revFieldEl.value = data.next_rev;
            revFieldEl.dataset.userEdited = '';
        }
        revFieldEl.readOnly = false;
        revFieldEl.style.background = '';
        revFieldEl.style.cursor = '';
        revFieldEl.removeAttribute('min');
        revFieldEl.setAttribute('title', 'Suggested: Rev ' + data.next_rev + ' (family latest is ' + data.latest_rev + '). Gap fills are allowed only if that Rev is unused in the family.');
    }

    const titleField = document.getElementById('masterlistDocTitle');
    if (titleField && data.latest_title) {
        titleField.value = data.latest_title;
        titleField.readOnly = false;
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

    if (data.latest_distribution_offices && data.latest_distribution_offices.length > 0
        && tableIsEmpty('retrievalBody')) {
        data.latest_distribution_offices.forEach(o => {
            seedRetrievalOfficeRow('retrievalBody', 'retrievalTotal', o.office_id, o.office_name, o.copies, 'pending');
        });
        updateTotal('retrievalTotal', 'retrievalBody');
    }

    if (data.already_retrieved_offices && data.already_retrieved_offices.length > 0) {
        data.already_retrieved_offices.forEach(o => {
            seedRetrievalOfficeRow(
                'retrievalBody', 'retrievalTotal',
                o.office_id, o.office_name, o.copies || 1, 'pending'
            );
        });
        updateTotal('retrievalTotal', 'retrievalBody');
        const retSection = document.getElementById('section-4');
        if (retSection) retSection.style.display = 'block';
    }

    applyRevisedApprovalFromPrevious(data);

    if (hint) {
        hint.innerHTML = '<i class="fa-solid fa-circle-check"></i> Document found. Keep or renumber — details prefilled (dates blank).';
        hint.style.color = '#16a34a';
        hint.dataset.valid = 'true';
    }
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

function clearRevisedApprovalContext() {
    window.__revisedApprovalContext = null;
}

function isDcnSectionVisible() {
    const section = document.getElementById('section-2');
    return section && section.style.display !== 'none';
}

async function bridgeDcnPickToMasterlist(docNo, options = {}) {
    const hintEl = document.getElementById('docNoHint');
    const revField = document.getElementById('masterlistRevisionNo');
    try {
        const docTypeId = document.getElementById('docType').value;
        const subTypeId = document.getElementById('subType').value;
        const excludeRequestId = document.getElementById('requestId')?.value || '';
        const url = '/dcs/register/check-docno?doc_no=' + encodeURIComponent(docNo) +
                    (docTypeId ? '&doc_type_id=' + docTypeId : '') +
                    (subTypeId ? '&sub_type_id=' + subTypeId : '') +
                    (excludeRequestId ? '&exclude_request_id=' + excludeRequestId : '');
        const res = await fetch(url);
        const data = await res.json();
        if (data.exists) {
            applyRevisedDocumentContext(data, { docNo, hintEl, revField, forceNextRev: true });
        }
    } catch (e) {
        console.error('DCN pick check-docno failed:', e);
    }
}

// ══════════════════════════════════════════════
// REVISION MODE HELPERS
// ══════════════════════════════════════════════
function isRevisedMode() {
    const hidden = document.getElementById('registrationMode');
    return hidden && hidden.value === 'revised';
}

function applyRevisionMode() {
    const revField = document.getElementById('masterlistRevisionNo');
    const hintEl = document.getElementById('docNoHint');

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
            hintEl.innerHTML = '<span style="color:#94a3b8"><i class="fa-solid fa-circle-info"></i> Enter an existing document number to continue</span>';
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

function updateRegistrationMode() {
    const sel = document.getElementById('versionType');
    const hidden = document.getElementById('registrationMode');
    if (!sel || !hidden) return;
    const text = sel.options[sel.selectedIndex]?.text?.toLowerCase() || '';
    hidden.value = (text.includes('revised') || text.includes('revision') || text.includes('revise')) ? 'revised' : 'new';
    applyRevisionMode();
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

function closeRevSearchDropdown(key) {
    const dd = document.getElementById('revSearchDropdown_' + key);
    if (dd) dd.style.display = 'none';
}

function removeRevSearchDropdown(key) {
    const dd = document.getElementById('revSearchDropdown_' + key);
    if (dd) dd.remove();
}

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

    revSearchTimers[key] = setTimeout(async () => {
        try {
            const params = new URLSearchParams({
                q,
                field: field || '',
                doc_type_id: docTypeId,
            });
            if (subTypeId) params.set('sub_type_id', subTypeId);
            const excludeId = document.getElementById('requestId')?.value;
            if (excludeId) params.set('exclude_request_id', excludeId);

            const data = await fetch('/dcs/api/documents/search?' + params.toString()).then(r => r.json());
            revSearchCache[key] = data;

            dd.innerHTML = data.length === 0
                ? '<div class="reg-reldocs-noresult">No matching documents of this type</div>'
                : data.map((d, idx) => `<div onmousedown="pickRevisionDocument('${key}', ${idx})">${escapeHtml(d.label)}</div>`).join('');

            positionFixedDropdown(dd, input);
            dd.style.display = 'block';
        } catch (e) { console.error('Revision doc search failed:', e); }
    }, 300);
}

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
    if (effField) effField.value = doc.effectivity_date || '';
    if (revField) revField.value = (doc.revise_no !== null && doc.revise_no !== undefined) ? doc.revise_no : '';
    if (pathInput) pathInput.value = doc.scanned_copy_path || '';
    if (purposeField) purposeField.value = doc.brief_purpose || '';
    if (purposeText) {
        const purpose = String(doc.brief_purpose || '').trim();
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

function getRevisedFromDocNo() {
    return (window.__revisedFromDocNo || '').trim();
}

function clearAllLinkedRevisionRows(tbody, exceptRow) {
    if (!tbody) return;
    [...tbody.querySelectorAll('tr')].forEach(tr => {
        if (tr === exceptRow) return;
        if (tr.dataset.linked === 'true') {
            removeRevisionRowDropdowns(tr);
            tr.remove();
        }
    });
}

async function fillRevisionTableWithDocumentHistory(anchorRow, docs, options = {}) {
    const tbody = document.getElementById('revisionTableBody');
    if (!tbody || !anchorRow || !docs.length) return;

    const pickedDocNo = String((options.docNo || docs[0].doc_no) || '').trim().toLowerCase();

    clearAllLinkedRevisionRows(tbody, anchorRow);

    const rowsToFill = docs.slice();

    populateRevisionRowFromDoc(anchorRow, rowsToFill[0]);
    anchorRow.dataset.linked = 'true';
    anchorRow.dataset.linkedDocNo = pickedDocNo;

    let insertAfter = anchorRow;
    for (let i = 1; i < rowsToFill.length; i++) {
        const tr = document.createElement('tr');
        tr.innerHTML = revisionRowCellsHTML();
        insertAfter.after(tr);
        bindRevisionRowSearch(tr);
        populateRevisionRowFromDoc(tr, rowsToFill[i]);
        tr.dataset.linkedDocNo = pickedDocNo;
        insertAfter = tr;
    }
}

window.pickRevisionDocument = async function (key, idx) {
    const doc = (revSearchCache[key] || [])[idx];
    if (!doc) return;
    const uid = key.split('_')[0];
    const row = document.querySelector(`#revisionTableBody tr[data-uid="${uid}"]`);
    if (!row) return;

    closeRevSearchDropdown(key);

    let revisions = [];
    try {
        if (doc.request_id) {
            const params = new URLSearchParams({ request_id: String(doc.request_id) });
            if (doc.doc_no) params.set('doc_no', doc.doc_no);
            revisions = await fetch('/dcs/api/documents/revisions?' + params.toString())
                .then(r => r.json());
        }
    } catch (e) {
        console.error('Failed to load document revisions:', e);
    }

    if (!Array.isArray(revisions) || revisions.length === 0) {
        revisions = [doc];
    }

    const pickedNo = String(doc.doc_no || '').trim().toLowerCase();

    await fillRevisionTableWithDocumentHistory(row, revisions, { docNo: pickedNo });

    if (isDcnSectionVisible() && doc.doc_no) {
        await bridgeDcnPickToMasterlist(doc.doc_no);
    }
};

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

function lockRevisionRowFields(row) {
    row.dataset.linked = "true";
    lockRevisionTitleAsWrap(row);
    row.querySelectorAll(
        'input[name="documentNo[]"], input[name="effectiveDate[]"], input[name="revisionNo[]"], input[name="revisionPurpose[]"]'
    ).forEach((el) => {
        if (el.type !== 'hidden') lockRevisionPopulatedField(el);
    });
}

function lockRevisionScannedCopyCell(row, scannedCopyUrl) {
    const cell = row.querySelector('.reg-rev-scan-cell');
    if (!cell) return;

    if (scannedCopyUrl) {
        const ext = scannedCopyUrl.split('.').pop().toLowerCase();
        const isPdf = ext === 'pdf';
        const iconClass = isPdf ? 'fa-solid fa-file-pdf' : 'fa-solid fa-file-word';
        const linkClass = isPdf ? 'reg-revrow-viewfile reg-revrow-viewfile-pdf' : 'reg-revrow-viewfile reg-revrow-viewfile-doc';
        const label = isPdf ? 'View PDF' : 'View Word document';
        cell.style.textAlign = 'center';
        cell.innerHTML = `<button type="button" class="${linkClass}" onclick="window.open('${scannedCopyUrl}', '_blank')" title="${label}"><i class="${iconClass}"></i></button>`;
    } else {
        cell.style.textAlign = 'center';
        cell.innerHTML = `<div class="reg-file-error" style="margin:0;"><i class="fa-solid fa-circle-exclamation"></i> No scanned copy on file</div>`;
    }
}

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

function bindRevisionRowSearch(tr) {
    if (!tr.dataset.uid) tr.dataset.uid = ++revisionRowUidCounter;
    const uid = tr.dataset.uid;
    bindRevisionSearchInput(tr.querySelector('input[name="documentNo[]"]'), uid, 'no');
    bindRevisionSearchInput(tr.querySelector('input[name="documentTitle[]"]'), uid, 'title');
    if (tr.dataset.linked === 'true') lockRevisionRowFields(tr);
}

function removeRevisionRowDropdowns(tr) {
    if (!tr.dataset.uid) return;
    removeRevSearchDropdown(tr.dataset.uid + '_title');
    removeRevSearchDropdown(tr.dataset.uid + '_no');
}

window.removeRevisionRow = function (btn) {
    const tr = btn.closest('tr');
    if (tr) { removeRevisionRowDropdowns(tr); tr.remove(); }
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

function isSyncedSourceUnitKey(key) {
    return SYNCED_SOURCE_UNIT_KEYS.includes(key);
}

function syncSourceUnitsAcrossSections(selection, sourceKey) {
    if (window.__syncingSourceUnits || !isSyncedSourceUnitKey(sourceKey)) return;

    window.__syncingSourceUnits = true;
    try {
        const items = selection.map(i => ({ type: i.type, id: i.id, label: i.label }));
        SYNCED_SOURCE_UNIT_KEYS.forEach(key => {
            if (key === sourceKey) return;
            const widget = window.__sourceWidgets[key];
            if (widget?.setSelectedItems) widget.setSelectedItems(items);
        });
    } finally {
        window.__syncingSourceUnits = false;
    }
}

function createSourceUnitWidget(opts) {
    let selected = opts.initial || [];
    let idCounter = 0;
    let panelOpen = false;
    let scrollResizeHandler = null;

    const idKey = opts.idKey || 'office_id';
    const labelKey = opts.labelKey || 'office_name';
    const getList = opts.dataListGetter || (() => allOffices);
    const itemLabelPlural = opts.itemLabelPlural || 'offices';

    function isOfficeSelected(itemId) {
        return selected.some(i => i.type === 'office' && String(i.id) === String(itemId));
    }

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
    }

    function addFreeText(val) {
        if (!opts.allowFreeText || !val) return;
        if (opts.singleSelect) selected = [];
        else if (selected.some(i => i.label.toLowerCase() === val.toLowerCase())) return;
        idCounter++;
        selected.push({ type: 'name', id: 'n' + idCounter, label: val });
        render();
        maybeSyncSourceUnits();
    }

    function removeItem(type, id) {
        selected = selected.filter(i => !(i.type === type && String(i.id) === String(id)));
        render();
        maybeSyncSourceUnits();
    }

    function maybeSyncSourceUnits() {
        if (isSyncedSourceUnitKey(opts.key)) {
            syncSourceUnitsAcrossSections(selected.slice(), opts.key);
        }
    }

    function syncInputText() {
        const inputEl = document.getElementById(opts.inputId);
        if (!inputEl) return;
        if (selected.length === 0) { inputEl.value = ''; return; }
        const joined = selected.map(i => i.label).join(', ');
        inputEl.value = opts.singleSelect ? joined : joined + ', ';
        const len = inputEl.value.length;
        inputEl.setSelectionRange(len, len);
    }

    function render() {
        const widget = document.getElementById(opts.widgetId);

        // ── FIX: remove ALL hidden inputs for these field names,
        //    including stale blade-rendered ones without data-source-hidden
        const fieldNames = new Set([opts.officeFieldName, opts.nameFieldName, opts.fieldName].filter(Boolean));
        fieldNames.forEach(name => {
            widget.querySelectorAll('input[type="hidden"][name="' + name + '"]').forEach(el => el.remove());
        });

        selected.forEach(item => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.dataset.sourceHidden = 'true';
            input.name = opts.fieldName || (item.type === 'office' ? opts.officeFieldName : opts.nameFieldName);
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

    function getCurrentQuery(input) {
        const raw = input.value;
        const lastComma = raw.lastIndexOf(',');
        return (lastComma === -1 ? raw : raw.slice(lastComma + 1)).trim();
    }

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

    function pick(itemId) {
        addOffice(itemId);
        const inputEl = document.getElementById(opts.inputId);
        if (inputEl) inputEl.focus();
        syncInputText();
        document.getElementById(opts.resultsId).style.display = 'none';
    }

    function positionPanel(panelEl, anchorEl) {
        const rect = anchorEl.getBoundingClientRect();
        panelEl.style.position = 'fixed';
        panelEl.style.top = (rect.bottom + 6) + 'px';
        panelEl.style.left = rect.left + 'px';
        panelEl.style.width = rect.width + 'px';
        panelEl.style.zIndex = 9500;
    }

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

    function reset() {
        selected = [];
        const inputEl = document.getElementById(opts.inputId);
        if (inputEl) inputEl.value = '';
        render();
        maybeSyncSourceUnits();
    }

    function removeItemAndSync(type, id) {
        removeItem(type, id);
        syncInputText();
    }

    function setSelectedItems(items) {
        selected = items.map(i => ({ type: i.type, id: i.id, label: i.label }));
        render();
        syncInputText();
    }

    function seedFromString(str, force) {
        if (!str) return;
        if (!force && selected.length > 0) return;
        if (force) selected = [];
        str.split(',').map(s => s.trim()).filter(Boolean).forEach(part => {
            const office = findOfficeByLabelOrCode(part, getList())
                || getList().find(o => o[labelKey].toLowerCase() === part.toLowerCase());
            if (office && office[idKey]) {
                selected.push({ type: 'office', id: office[idKey], label: office[labelKey] });
            } else if (!office) {
                idCounter++;
                selected.push({ type: 'name', id: 'n' + idCounter, label: part });
            }
        });
        render();
        syncInputText();
        maybeSyncSourceUnits();
    }

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

    render();

    const api = {
        pick, removeItem: removeItemAndSync, reset, seedFromString, setSelectedItems,
        openPanel: togglePanel, get selected() { return selected; }
    };
    window.__sourceWidgets[opts.key] = api;
    return api;
}

// ══════════════════════════════════════════════
// UNIVERSAL SOURCE UNIT OVERLAY
// ══════════════════════════════════════════════
window.__sourceOverlayConfigs = window.__sourceOverlayConfigs || {};

function closeSourceOverlay() {
    const overlay = document.getElementById('universalSourceOverlay');
    const backdrop = document.getElementById('universalSourceBackdrop');
    if (overlay) overlay.remove();
    if (backdrop) backdrop.remove();
    document.removeEventListener('keydown', handleSourceOverlayEsc);
}
window.closeSourceOverlay = closeSourceOverlay;

function handleSourceOverlayEsc(e) { if (e.key === 'Escape') closeSourceOverlay(); }

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

window.pickSourceOverlayOffice = function (key, officeId) {
    const config = window.__sourceOverlayConfigs[key];
    if (!config) return;
    config.addOffice(officeId);
    document.getElementById('universalSourceOverlaySearch').value = '';
    document.getElementById('universalSourceOverlaySuggestions').style.display = 'none';
    refreshSourceOverlay(config);
};

window.removeSourceOverlayItem = function (key, id) {
    const config = window.__sourceOverlayConfigs[key];
    if (!config) return;
    config.removeItem(id);
    refreshSourceOverlay(config);
};

// ══════════════════════════════════════════════
// RELATED DOCUMENTS
// ══════════════════════════════════════════════
window.handleRelatedDocFocus = function () { closeRelatedDocsSelectedPanel(); };

window.handleRelatedDocSearch = function (input) {
    clearTimeout(window.__relatedDocsTimer);
    const q = input.value.trim();
    const dropdown = document.getElementById('relatedDocsResults');
    closeRelatedDocsSelectedPanel();
    if (q.length < 1) { dropdown.style.display = 'none'; return; }

    window.__relatedDocsTimer = setTimeout(async () => {
        try {
            const excludeId = document.getElementById('requestId')?.value || '';
            const url = '/dcs/api/documents/search?q=' + encodeURIComponent(q)
                + (excludeId ? '&exclude_request_id=' + excludeId : '');
            const data = await fetch(url).then(r => r.json());
            relatedDocsCache = data.filter(d => !relatedDocsSelected.some(s => s.masterlist_id === d.masterlist_id));
            if (relatedDocsCache.length === 0) {
                dropdown.innerHTML = '<div class="reg-reldocs-noresult">No matching documents found</div>';
                dropdown.style.display = 'block';
                return;
            }
            dropdown.innerHTML = relatedDocsCache
                .map(d => `<div onmousedown="pickRelatedDoc(${d.masterlist_id})">${escapeHtml(d.label)}</div>`)
                .join('');
            dropdown.style.display = 'block';
        } catch (e) { console.error('Related doc search failed:', e); }
    }, 300);
};

window.pickRelatedDoc = function (id) {
    const doc = relatedDocsCache.find(d => d.masterlist_id === id);
    if (!doc || relatedDocsSelected.some(d => d.masterlist_id === id)) return;
    relatedDocsSelected.push(doc);
    renderRelatedDocsChips();
    const input = document.getElementById('relatedDocsSearch');
    input.value = '';
    document.getElementById('relatedDocsResults').style.display = 'none';
};

window.removeRelatedDoc = function (id, event) {
    if (event) event.stopPropagation();
    relatedDocsSelected = relatedDocsSelected.filter(d => d.masterlist_id !== id);
    renderRelatedDocsChips();
};

window.toggleRelatedDocsSelected = function (e) {
    if (e) e.stopPropagation();
    document.getElementById('relatedDocsResults').style.display = 'none';
    relatedDocsSelectedPanelOpen = !relatedDocsSelectedPanelOpen;
    const panel = document.getElementById('relatedDocsSelectedPanel');
    const icon = document.getElementById('relatedDocsArrowIcon');
    panel.style.display = relatedDocsSelectedPanelOpen ? 'block' : 'none';
    icon.style.transform = relatedDocsSelectedPanelOpen ? 'rotate(180deg)' : '';
};

function closeRelatedDocsSelectedPanel() {
    relatedDocsSelectedPanelOpen = false;
    const panel = document.getElementById('relatedDocsSelectedPanel');
    const icon = document.getElementById('relatedDocsArrowIcon');
    if (panel) panel.style.display = 'none';
    if (icon) icon.style.transform = '';
}

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
            if (e.dataTransfer.files.length > 0) {
                input.files = e.dataTransfer.files;
                processUploadAreaFile(input, container, icon, label, originalText);
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

    if (input.id === 'drfFile' && check.ext === 'pdf' && file.size <= OCR_MAX_FILE_SIZE) {
        triggerScanExtraction(input, file);
    }
}

function triggerScanExtraction(input, file) {
    const formData = new FormData();
    formData.append('scan', file);
    formData.append('section', 'drf');
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
            if (!r.ok || !data.extracted) {
                showUploadFieldError(container, 'Could not read DRF fields from this scan. Fill them in manually.');
                return;
            }
            const filled = autofillDrfFields(data.fields || {});
            if (!filled) {
                showUploadFieldError(container, 'Could not read DRF No, date, title, or source unit from this scan.');
            }
        })
        .catch(err => {
            if (label) label.textContent = originalLabelText;
            showUploadFieldError(container, 'Could not read DRF fields from this scan. Fill them in manually.');
            console.error('Extraction request failed:', err);
        });
}

function autofillDrfFields(fields) {
    let filled = false;
    const map = { drfNo: 'drfNo', drfDate: 'drfDate', drfTitle: 'drfTitle' };
    Object.entries(map).forEach(([fieldKey, elId]) => {
        const value = fields[fieldKey];
        const el = document.getElementById(elId);
        if (value && el && !el.value) {
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
        if (fields.sourceOfficeId) {
            window.__sourceWidgets.drf.pick(fields.sourceOfficeId);
            filled = true;
        } else if (fields.sourceUnit) {
            window.__sourceWidgets.drf.seedFromString(fields.sourceUnit);
            filled = true;
        }
    }
    return filled;
}

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

function resetUploadArea(container, icon, label, originalText) {
    container.classList.remove('reg-upload-success', 'reg-upload-error', 'reg-upload-drag', 'reg-upload-has-file');
    container.style.borderColor = ''; container.style.background = ''; container.style.borderStyle = ''; container.style.animation = '';
    clearFileIcon(icon, label, originalText);
    removeUploadFieldActions(container);
    removeExistingError(container);
}

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

function removeExistingPreview(container) {
    removeUploadFieldActions(container);
}

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

function openFilePreviewModal(url, title) {
    const overlay = document.getElementById('filePreviewModal');
    const frame = document.getElementById('filePreviewModalFrame');
    const titleEl = document.getElementById('filePreviewModalTitle');
    if (!overlay || !frame) return;
    frame.src = url;
    if (titleEl) titleEl.textContent = title || 'File preview';
    overlay.classList.add('is-open');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

function closeFilePreviewModal() {
    const overlay = document.getElementById('filePreviewModal');
    const frame = document.getElementById('filePreviewModalFrame');
    if (!overlay) return;
    overlay.classList.remove('is-open');
    overlay.setAttribute('aria-hidden', 'true');
    if (frame) frame.src = 'about:blank';
    document.body.style.overflow = '';
}

function removeExistingRemoveBtn(c) { const o = c.querySelector('.reg-file-remove'); if (o) o.remove(); }
function removeExistingError(c) { const o = c.querySelector('.reg-file-error'); if (o) o.remove(); }

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

function handleDocTypeChange() {
    // In edit mode, doc type is locked — this handler is a no-op.
    // Implementation kept for completeness if unlocked in the future.
}

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

function resetMasterlistNoOfPagesField() {
    const mlPages = document.getElementById('masterlistNoOfPages');
    if (!mlPages) return;
    mlPages.readOnly = false;
    mlPages.style.background = '';
    mlPages.style.cursor = '';
}

function resetSyllabiSection() {
    const syllabiBody = document.getElementById('syllabiTableBody');
    if (syllabiBody) {
        syllabiBody.querySelectorAll('tr[data-uid]').forEach(tr => removeSyllabiFacultyPicker(tr.dataset.uid));
        syllabiBody.innerHTML = '';
        syllabiGroupCounter = 0;
    }
    ['syllabiDocNo', 'syllabiDocTitle', 'syllabiEffectivityDate', 'syllabiDeadline'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    syllabiTitleManuallyEdited = false;
    const collegeSel = document.getElementById('syllabiCollege');
    const programSel = document.getElementById('syllabiProgram');
    const semSel = document.getElementById('syllabiSemester');
    const sySel = document.getElementById('syllabiSchoolYear');
    if (collegeSel) collegeSel.selectedIndex = 0;
    if (programSel) { programSel.innerHTML = '<option value="" selected disabled>Select program</option>'; programSel.disabled = true; }
    if (semSel) { semSel.selectedIndex = 0; semSel.disabled = true; }
    if (sySel) { sySel.selectedIndex = 0; sySel.disabled = true; }
}

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

function unlockChecklist() {
    const container = document.getElementById("dynamicCheckboxes");
    container.querySelectorAll("input[type='checkbox']").forEach(cb => {
        cb.disabled = false;
        cb.checked = true;
        cb.dataset.lastChecked = "true";
        toggleSection(parseInt(cb.value), true);
    });
    showFormActions();
    enableApproval();
    setTimeout(initFileInputs, 100);
    const saveBtn = document.querySelector('.reg-btn-save');
    if (saveBtn) saveBtn.style.display = "";
}

window.toggleSection = function (checklistId, show) {
    if (checklistId === 1 && window.__isSyllabiMode) return;
    // Retrieval only exists once the document has been revised (revise_no > 0)
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
    if (checklistId === 3 && window.__isSyllabiMode) {
        const syllabiSection = document.getElementById("section-syllabi");
        if (syllabiSection) syllabiSection.style.display = show ? "block" : "none";
    }
};

window.handleApprovalToggle = function (applicable) {
    const el = document.getElementById("section-approval");
    if (el) el.style.display = applicable ? "block" : "none";
};

function wireApprovalDeadlineSync() {
    const approval = document.getElementById('approvalDate');
    const deadline = document.getElementById('deadlineOfSubmission');
    if (!approval || !deadline) return;
    let syncing = false;
    approval.addEventListener('change', () => {
        if (syncing) return;
        syncing = true;
        deadline.value = approval.value;
        syncing = false;
    });
    deadline.addEventListener('change', () => {
        if (syncing) return;
        syncing = true;
        approval.value = deadline.value;
        syncing = false;
    });
}

function enableApproval() {
    document.querySelectorAll('input[name="approval_status"]').forEach(r => r.disabled = false);
    if (isRevisedMode() && window.__revisedApprovalContext) {
        applyRevisedApprovalFromPrevious(window.__revisedApprovalContext);
    }
}

function disableApproval() {
    document.querySelectorAll('input[name="approval_status"]').forEach(r => {
        r.disabled = true; r.checked = r.value === "not_applicable";
    });
    const approval = document.getElementById("section-approval");
    if (approval) approval.style.display = "none";
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

function syllabiContextComplete() {
    return document.getElementById('syllabiCollege')?.value
        && document.getElementById('syllabiProgram')?.value
        && document.getElementById('syllabiSemester')?.value
        && document.getElementById('syllabiSchoolYear')?.value;
}

async function autoPopulateSyllabiCourses() {
    if (!syllabiContextComplete()) return;
    const programId = document.getElementById('syllabiProgram').value;
    const semesterId = document.getElementById('syllabiSemester').value;
    try {
        const courses = ((window.__registerCatalog || {}).coursesByProgramSemester || {})[programId + ':' + semesterId] || [];
        const tbody = document.getElementById('syllabiTableBody');
        if (!tbody) return;
        const hasManualData = [...tbody.querySelectorAll('.syllabi-merged-course, textarea.syllabi-merged-course')]
            .some(inp => inp.value.trim() !== '' && inp.dataset.autoFilled !== 'true');
        if (hasManualData) return;

        if (!courses || courses.length === 0) {
            showSyllabiEmptyCatalogHint();
            return;
        }

        tbody.querySelectorAll('tr[data-uid]').forEach(tr => removeSyllabiFacultyPicker(tr.dataset.uid));
        tbody.innerHTML = '';
        syllabiGroupCounter = 0;

        courses.forEach((c, courseIndex) => {
            syllabiGroupCounter++;
            const groupId = 'g' + syllabiGroupCounter;
            const newRow = buildSyllabiGroupFirstRow(groupId, 1);
            tbody.appendChild(newRow);
            const courseInput = newRow.querySelector('.syllabi-merged-course');
            if (courseInput) {
                courseInput.value = c.course_name;
                courseInput.dataset.autoFilled = 'true';
                courseInput.title = 'Loaded from Settings';
                autosizeSyllabiCourse(courseInput);
                courseInput.addEventListener('input', () => { courseInput.dataset.autoFilled = 'false'; });
            }
            const codeInput = newRow.querySelector('.syllabi-merged-code');
            if (codeInput) {
                codeInput.value = c.course_code || '';
            }
            const faculties = c.faculties || [];
            const splitCopies = faculties.length >= 2 && (courseIndex % 2 === 1);
            applyCatalogFacultiesToRow(newRow, faculties, { splitCopies });
            cascadeDrfToNewRow(newRow);
            syncSyllabiMergedFields(groupId);
            syncSyllabiAvailability(groupId);
        });
        updateSyllabiTotals();
        applySyllabiSectionLabel();
    } catch (err) {
        console.error('Failed to auto-populate syllabi courses:', err);
    }
}

function showSyllabiEmptyCatalogHint() {
    const tbody = document.getElementById('syllabiTableBody');
    if (!tbody) return;
    tbody.querySelectorAll('tr[data-uid]').forEach(tr => removeSyllabiFacultyPicker(tr.dataset.uid));
    tbody.innerHTML = '<tr class="syllabi-empty-hint"><td colspan="15">No courses in Settings for this program and semester. Add them under Settings → Course Names, or click Add Course.</td></tr>';
    syllabiGroupCounter = 0;
    if (typeof updateSyllabiTotals === 'function') updateSyllabiTotals();
}

function applyCatalogFacultiesToRow(row, faculties, options = {}) {
    if (!row || !Array.isArray(faculties) || faculties.length === 0) return;
    const names = faculties.map(f => f.faculty_name || f.name).filter(Boolean);
    if (!names.length) return;
    row.dataset.catalogFaculty = JSON.stringify(names);
    row.dataset.catalogSplit = options.splitCopies ? '1' : '0';
    restoreCatalogFaculties(row);
}

function clearSyllabiCourseRows() {
    const tbody = document.getElementById('syllabiTableBody');
    if (!tbody) return;
    tbody.querySelectorAll('tr[data-uid]').forEach(tr => removeSyllabiFacultyPicker(tr.dataset.uid));
    tbody.innerHTML = '';
    syllabiGroupCounter = 0;
    updateSyllabiTotals();
}

async function initSyllabiContextWiring() {
    const collegeSel = document.getElementById("syllabiCollege");
    const programSel = document.getElementById("syllabiProgram");
    const semSel = document.getElementById("syllabiSemester");
    const sySel = document.getElementById("syllabiSchoolYear");

    if (collegeSel && !collegeSel.dataset.wired) {
        collegeSel.dataset.wired = "true";
        collegeSel.addEventListener("change", async function () {
            if (programSel) {
                programSel.innerHTML = '<option value="" selected disabled>Select program</option>';
                programSel.disabled = true;
            }
            if (semSel) { semSel.value = ""; semSel.disabled = true; }
            if (sySel) { sySel.value = ""; sySel.disabled = true; }
            updateSyllabiTitle();
            clearSyllabiCourseRows();
            allFaculties = [];
            window.__facultiesCacheKey = null;

            if (!this.value) return;
            await reloadFacultiesForCollege(this.value);

            try {
                const programs = ((window.__registerCatalog || {}).programsByCollege || {})[String(this.value)] || [];
                if (!programSel) return;
                programs.forEach(p => {
                    const opt = new Option(p.program_name, p.program_id);
                    opt.dataset.code = p.program_code || "";
                    programSel.add(opt);
                });
                programSel.disabled = false;
            } catch (err) { console.error("Failed to load programs:", err); }
        });
    }
    if (programSel && !programSel.dataset.wired) {
        programSel.dataset.wired = "true";
        programSel.addEventListener("change", function () {
            if (semSel) { semSel.value = ""; semSel.disabled = !this.value; }
            if (sySel) { sySel.value = ""; sySel.disabled = true; }
            updateSyllabiTitle();
            clearSyllabiCourseRows();
        });
    }
    if (semSel && !semSel.dataset.wired) {
        semSel.dataset.wired = "true";
        semSel.addEventListener("change", function () {
            if (sySel) { sySel.value = ""; sySel.disabled = !this.value; }
            updateSyllabiTitle();
            clearSyllabiCourseRows();
        });
    }
    if (sySel && !sySel.dataset.wired) {
        sySel.dataset.wired = "true";
        sySel.addEventListener("change", function () {
            updateSyllabiTitle();
            autoPopulateSyllabiCourses();
        });
    }
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

function updateSyllabiTitle() {
    const titleInput = document.getElementById('syllabiDocTitle');
    if (!titleInput || syllabiTitleManuallyEdited) return;
    const college  = getSelectText('syllabiCollege');
    const program  = getSelectTextWithCode('syllabiProgram');
    const semester = getSelectText('syllabiSemester');
    const schoolYr = getSelectText('syllabiSchoolYear');
    const label    = window.__syllabiModeLabel || 'Syllabi';
    if (!college || !program || !semester || !schoolYr) return;
    titleInput.value = college + ' ' + label + ' for ' + program + ', ' + semester + ', ' + formatSchoolYearText(schoolYr);
    syncSyllabiToMasterlistFields();
}

function syncSyllabiToMasterlistFields() {
    if (!window.__isSyllabiMode) return;

    const pairs = [
        ['syllabiDocNo', 'masterlistDocNo'],
        ['syllabiEffectivityDate', 'masterlistEffectivityDate'],
        ['syllabiDeadline', 'deadlineOfSubmission'],
    ];
    pairs.forEach(([srcId, destId]) => {
        const src = document.getElementById(srcId);
        const dest = document.getElementById(destId);
        if (src && dest) dest.value = src.value;
    });

    const titleInput = document.getElementById('syllabiDocTitle');
    const mlTitle = document.getElementById('masterlistDocTitle');
    if (titleInput && mlTitle) mlTitle.value = titleInput.value;
}

function wireSyllabiMasterlistSync() {
    const syllabiNo = document.getElementById('syllabiDocNo');
    const masterNo = document.getElementById('masterlistDocNo');
    if (syllabiNo && masterNo) {
        syllabiNo.addEventListener('input', () => {
            if (!window.__isSyllabiMode) return;
            masterNo.value = syllabiNo.value;
            masterNo.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }

    ['syllabiEffectivityDate', 'syllabiDeadline'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', syncSyllabiToMasterlistFields);
        if (el) el.addEventListener('change', syncSyllabiToMasterlistFields);
    });

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

function buildSyllabiFacultyCellHTML(uid) {
    return `
        <div class="syllabi-faculty-wrap" id="syllabiFacultyWrap_${uid}" style="position:relative;">
            <input type="hidden" name="syllabiFaculty[]" id="syllabiFacultyHidden_${uid}">
            <input type="text" id="syllabiFacultyInput_${uid}" class="syllabi-faculty-input"
                placeholder="Search faculty name" autocomplete="off">
        </div>
    `;
}

function initSyllabiFacultyPicker(uid, mode, rootEl) {
    window.__syllabiFaculty[uid] = { mode, selected: [] };
    bindSyllabiFacultyInput(uid, rootEl);
    renderSyllabiFacultyChips(uid);
}

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

function getSyllabiCollegeId() {
    const hidden = document.getElementById('syllabiCollegeHidden');
    if (hidden && hidden.value) return hidden.value;
    const select = document.getElementById('syllabiCollege');
    return select && select.value ? select.value : '';
}

async function reloadFacultiesForCollege(collegeId) {
    window.__facultiesCacheKey = null;
    allFaculties = [];
    if (!collegeId) return;
    try {
        const data = ((window.__registerCatalog || {}).faculties || []).filter(f => String(f.college_id) === String(collegeId));
        allFaculties = Array.isArray(data) ? data : [];
        window.__facultiesCacheKey = String(collegeId);
    } catch (err) {
        console.error('Failed to load faculties for college:', err);
    }
}

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

async function ensureFacultiesLoaded() {
    const collegeId = getSyllabiCollegeId();
    if (!collegeId) {
        allFaculties = [];
        window.__facultiesCacheKey = null;
        return;
    }
    const cacheKey = String(collegeId);
    if (window.__facultiesCacheKey === cacheKey && Array.isArray(allFaculties) && allFaculties.length > 0) {
        return;
    }
    await reloadFacultiesForCollege(collegeId);
}

function getFacultyCandidates() {
    if (!Array.isArray(allFaculties)) return [];
    const collegeId = getSyllabiCollegeId();
    if (!collegeId) return [];
    return allFaculties.filter(f => String(f.college_id) === String(collegeId));
}

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

function canAddMoreSyllabiFaculty(state) {
    if (!state) return false;
    if (state.mode === 'single') return state.selected.length < 1;
    return state.selected.length < 2;
}

function getSyllabiFacultyDisplayValue(state) {
    return state.selected.map(s => s.label).join(', ');
}

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

function pickSyllabiFacultyFromQuery(uid, query) {
    const candidates = getFacultyCandidates();
    const q = query.toLowerCase();
    const state = window.__syllabiFaculty[uid];
    if (!state) return;

    const exact = candidates.find(f => f.faculty_name.toLowerCase() === q);
    if (exact) {
        addSyllabiFaculty(uid, exact.faculty_name, true);
        return;
    }

    const partial = candidates.filter(f =>
        f.faculty_name.toLowerCase().includes(q) &&
        !state.selected.some(s => s.label.toLowerCase() === f.faculty_name.toLowerCase())
    );
    if (partial.length === 1) addSyllabiFaculty(uid, partial[0].faculty_name, true);
}

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

    const collegeId = getSyllabiCollegeId();
    if (!collegeId) {
        dd.innerHTML = '<div class="reg-reldocs-noresult">Select a college first</div>';
        return;
    }

    await ensureFacultiesLoaded();

    const candidates = getFacultyCandidates();
    const matches = candidates.filter(f =>
        f.faculty_name.toLowerCase().includes(q) &&
        !state.selected.some(s => s.label.toLowerCase() === f.faculty_name.toLowerCase())
    );

    dd.innerHTML = matches.length === 0
        ? '<div class="reg-reldocs-noresult">No matching faculty for this college</div>'
        : matches.map(f =>
            `<div data-faculty-name="${escapeHtml(f.faculty_name)}">${escapeHtml(f.faculty_name)}</div>`
        ).join('');

    dd.querySelectorAll('[data-faculty-name]').forEach(el => {
        el.addEventListener('mousedown', (ev) => {
            ev.preventDefault();
            addSyllabiFaculty(uid, el.dataset.facultyName, true);
        });
    });

    positionFixedDropdown(dd, input);
    dd.style.display = 'block';
}

function closeSyllabiFacultyDropdown(uid) {
    const dd = document.getElementById('syllabiFacultyDropdown_' + uid);
    if (dd) dd.style.display = 'none';
}

window.addSyllabiFaculty = function (uid, name, focusNext) {
    const state = window.__syllabiFaculty[uid];
    if (!state || !name) return;
    const row = document.querySelector(`#syllabiTableBody tr[data-uid="${uid}"]`);

    const match = getFacultyCandidates().find(f => f.faculty_name.toLowerCase() === name.toLowerCase());
    if (!match) return;

    const facultyName = match.faculty_name;
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

function renderSyllabiFacultyChips(uid) {
    syncSyllabiFacultyInputDisplay(uid);
}

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
        ['syllabiSchoolYear', 'syllabiSchoolYearHidden'],
    ];
    map.forEach(([selId, hidId]) => {
        const sel = document.getElementById(selId);
        const hid = document.getElementById(hidId);
        if (sel && hid && sel.value) hid.value = sel.value;
    });
}

async function seedExistingSyllabiGroups() {
    const groups = window.__existingSyllabiGroups || [];
    await loadSyllabiContextDropdowns();

    const tbody = document.getElementById("syllabiTableBody");
    if (!tbody) return;
    tbody.innerHTML = "";
    syllabiGroupCounter = 0;

    if (groups.length === 0) {
        addSyllabiRow();
        setSyllabiStep(1);
        if (window.__syllabiEditLocked) lockSyllabiContextDropdowns();
        return;
    }

    const first = groups[0];
    const collegeSel = document.getElementById("syllabiCollege");
    const programSel = document.getElementById("syllabiProgram");
    const semSel = document.getElementById("syllabiSemester");
    const sySel = document.getElementById("syllabiSchoolYear");

    if (collegeSel && first.college_id) {
        collegeSel.value = first.college_id;
        if (programSel) {
            try {
                const programs = ((window.__registerCatalog || {}).programsByCollege || {})[String(first.college_id)] || [];
                programs.forEach(p => {
                    const opt = new Option(p.program_name, p.program_id);
                    opt.dataset.code = p.program_code || "";
                    programSel.add(opt);
                });
                if (first.program_id) programSel.value = first.program_id;
            } catch (e) { console.error(e); }
        }
    }
    if (semSel && first.semester_id) semSel.value = first.semester_id;
    if (sySel && first.school_year_id) sySel.value = first.school_year_id;

    if (window.__syllabiEditLocked) lockSyllabiContextDropdowns();
    syncSyllabiContextHidden();
    if (first.college_id) await reloadFacultiesForCollege(first.college_id);

    groups.forEach(group => {
        syllabiGroupCounter++;
        const groupId = "g" + syllabiGroupCounter;
        const rowCount = Math.max(1, group.copies || group.rows?.length || 1);
        const firstRow = buildSyllabiGroupFirstRow(groupId, rowCount);
        tbody.appendChild(firstRow);

        const courseInput = firstRow.querySelector('.syllabi-merged-course');
        const availCheckbox = firstRow.querySelector('.syllabi-merged-availability');
        const availHidden = firstRow.querySelector('.syllabi-merged-availability-hidden');
        const copiesInput = firstRow.querySelector('.syllabi-merged-copies');
        const pagesInput = firstRow.querySelector('.syllabi-merged-pages');

        if (courseInput) { courseInput.value = group.course_name || ''; autosizeSyllabiCourse(courseInput); }
        const codeInput = firstRow.querySelector('.syllabi-merged-code');
        if (codeInput) codeInput.value = group.course_code || '';
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
}

function lockSyllabiContextDropdowns() {
    ['syllabiCollege', 'syllabiProgram', 'syllabiSemester', 'syllabiSchoolYear'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.disabled = true;
            el.classList.add('reg-field-locked');
        }
    });
}

function setVal(tr, name, value) {
    if (value === null || value === undefined || value === '') return;
    const el = tr.querySelector(`[name="${name}"]`);
    if (el) el.value = value;
}

function showExistingSyllabiScannedFile(tr, path) {
    if (!tr || !path) return;
    const cell = tr.querySelector('.reg-upload-cell');
    if (!cell) return;

    const url = '/storage/' + String(path).replace(/^\/+/, '').replace(/^storage\//, '');
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
    syllabiCurrentStep = step;
    const root = document.getElementById("dcsEditRoot");
    if (root && window.Alpine) Alpine.$data(root).syllabiStep = step;
}

window.syllabiStepNext = function () {
    if (syllabiCurrentStep < 2) setSyllabiStep(syllabiCurrentStep + 1);
};
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

    tr.querySelectorAll('input[name="syllabiDrfNo[]"], input[name="syllabiDrfDate[]"], input[name="syllabiDrfReceived[]"]').forEach(el => {
        el.disabled = !enabled;
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

function buildSyllabiPerRowCells(uid) {
    return `
        <td class="col-step1"><input type="date" name="syllabiDateReceived[]" oninput="cascadeSyllabiReceived(this, 'syllabiDateReceived[]')"></td>
        <td class="col-step1"><input type="time" name="syllabiTimeReceived[]" oninput="cascadeSyllabiReceived(this, 'syllabiTimeReceived[]')"></td>

        <td class="col-step2 syllabi-check-cell">
            <input type="hidden" name="syllabiDrfAvailability[]" value="not available" class="syllabi-hidden-toggle">
            <input type="checkbox" class="syllabi-drf-availability" onchange="syncSyllabiDrfRow(this.closest('tr'))">
        </td>
        <td class="col-step2"><input type="text" name="syllabiDrfNo[]" placeholder="Enter DRF No." disabled></td>
        <td class="col-step2"><input type="date" name="syllabiDrfDate[]" oninput="cascadeSyllabiField(this, 'syllabiDrfDate[]')" disabled></td>
        <td class="col-step2"><input type="date" name="syllabiDrfReceived[]" oninput="cascadeSyllabiField(this, 'syllabiDrfReceived[]')" disabled></td>
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

function propagateReceivedToEmptyRows(source) {
    const filled = source && (source.date || source.time)
        ? source
        : findFirstFilledReceived(source?.row || null);
    if (!filled || (!filled.date && !filled.time)) return;

    const tbody = document.getElementById('syllabiTableBody');
    if (!tbody) return;

    tbody.querySelectorAll('tr[data-uid]').forEach(tr => {
        if (filled.row && tr === filled.row) return;
        if (filled.date) stampEmpty(tr.querySelector('input[name="syllabiDateReceived[]"]'), filled.date);
        if (filled.time) stampEmpty(tr.querySelector('input[name="syllabiTimeReceived[]"]'), filled.time);
    });
}

function propagateDrfToEmptyRows(source) {
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

function stampGroupReceivedFromPeer(groupId) {
    const peer = findFirstFilledReceived();
    if (!peer || (!(peer.date || '').trim() && !(peer.time || '').trim())) return;

    const date = (peer.date || '').trim();
    const time = (peer.time || '').trim();

    document.querySelectorAll(`#syllabiTableBody tr[data-group="${groupId}"]`).forEach(tr => {
        if (date) stampEmpty(tr.querySelector('input[name="syllabiDateReceived[]"]'), date);
        if (time) stampEmpty(tr.querySelector('input[name="syllabiTimeReceived[]"]'), time);
    });

    propagateReceivedToEmptyRows({ date, time, row: peer.row });
}

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

function cascadeDrfToNewRow(newRow) {
    if (!newRow) return;
    const peer = findFirstFilledDrf(newRow);
    if (!peer) return;
    stampEmpty(newRow.querySelector('input[name="syllabiDrfDate[]"]'), peer.drfDate);
    stampEmpty(newRow.querySelector('input[name="syllabiDrfReceived[]"]'), peer.drfReceived);
}

function syllabiGroupIsAvailable(groupId) {
    const firstRow = document.querySelector(`#syllabiTableBody tr[data-group="${groupId}"][data-is-first="true"]`);
    return !!firstRow?.querySelector('.syllabi-merged-availability')?.checked;
}

function syllabiRowHasFaculty(tr) {
    const uid = tr?.dataset?.uid;
    const state = uid && window.__syllabiFaculty[uid];
    return !!(state && state.selected && state.selected.length > 0);
}

function clearSyllabiFacultyRow(tr) {
    const uid = tr.dataset.uid;
    if (!window.__syllabiFaculty[uid]) return;
    window.__syllabiFaculty[uid].selected = [];
    syncSyllabiFacultyInputDisplay(uid, { force: true });
}

function setSyllabiFacultyRowEnabled(tr, enabled) {
    const input = tr.querySelector('.syllabi-faculty-input');
    if (input) {
        input.readOnly = !enabled;
        if (tr.dataset.uid) syncSyllabiFacultyInputDisplay(tr.dataset.uid, { force: true });
    }
    tr.querySelectorAll(
        'input[name="syllabiDateReceived[]"], input[name="syllabiTimeReceived[]"], .syllabi-merged-copies, .syllabi-merged-pages'
    ).forEach(el => {
        el.readOnly = !enabled;
        el.classList.toggle('is-locked', !enabled);
        if (!enabled && (el.name === 'syllabiDateReceived[]' || el.name === 'syllabiTimeReceived[]')) {
            el.value = '';
        }
    });
}

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

window.syncSyllabiAvailability = function (groupId) {
    const firstRow = document.querySelector(`#syllabiTableBody tr[data-group="${groupId}"][data-is-first="true"]`);
    if (!firstRow) return;
    const enabled = syllabiGroupIsAvailable(groupId);
    restoreCatalogFaculties(firstRow);
    document.querySelectorAll(`#syllabiTableBody tr[data-group="${groupId}"]`).forEach(tr => {
        setSyllabiFacultyRowEnabled(tr, enabled);
    });
    if (enabled) {
        stampGroupReceivedFromPeer(groupId);
    }
};

/** When any row's date/time changes, copy into other empty rows only (never overwrite). */
window.cascadeSyllabiReceived = function (input, fieldName) {
    const sourceRow = input?.closest('tr');
    if (!sourceRow) return;
    const date = (sourceRow.querySelector('input[name="syllabiDateReceived[]"]')?.value || '').trim();
    const time = (sourceRow.querySelector('input[name="syllabiTimeReceived[]"]')?.value || '').trim();
    if (!date && !time) return;
    propagateReceivedToEmptyRows({ date, time, row: sourceRow });
};

function fillReceivedFromGroup(tr) {
    if (!tr || !syllabiGroupIsAvailable(tr.dataset.group) || !syllabiRowHasFaculty(tr)) return;
    const peer = findFirstFilledReceived(tr);
    if (!peer) return;
    if ((peer.date || '').trim()) stampEmpty(tr.querySelector('input[name="syllabiDateReceived[]"]'), peer.date);
    if ((peer.time || '').trim()) stampEmpty(tr.querySelector('input[name="syllabiTimeReceived[]"]'), peer.time);
}

function autosizeSyllabiCourse(el) {
    el.style.height = 'auto';
    el.style.height = el.scrollHeight + 'px';
}

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
            <textarea rows="1" name="syllabiCourseName[]" placeholder="Enter course name"
                class="syllabi-merged-course"
                oninput="syncSyllabiMergedFields('${groupId}'); autosizeSyllabiCourse(this);"></textarea>
        </td>
        <td class="col-pinned" rowspan="${rowspan}">
            <input type="text" name="syllabiCourseCode[]" placeholder="e.g. CS 101"
                class="syllabi-merged-code"
                oninput="syncSyllabiMergedFields('${groupId}')">
        </td>
        <td class="col-step1" rowspan="${rowspan}">
            <input type="hidden" name="syllabiAvailability[]" value="not available" class="syllabi-merged-availability-hidden">
            <label class="reg-checkbox-wrap">
                <input type="checkbox" class="syllabi-merged-availability" onchange="syncSyllabiMergedFields('${groupId}'); syncSyllabiAvailability('${groupId}')">
            </label>
        </td>
        <td class="col-step1" rowspan="${rowspan}">
            <input type="number" name="syllabiCopies[]" min="1" value="${rowspan}"
                class="syllabi-merged-copies" oninput="handleCopiesChange(this)">
        </td>
        ${buildSyllabiFacultyTd(uid)}
        <td class="col-step1" rowspan="${rowspan}">
            <input type="number" name="syllabiNoPages[]" min="0" placeholder="0"
                class="syllabi-merged-pages" oninput="syncSyllabiMergedFields('${groupId}')">
        </td>
        ${buildSyllabiPerRowCells(uid)}
        <td class="col-pinned" rowspan="${rowspan}">
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

function buildSyllabiContinuationRow(groupId, copyNo) {
    const tr = document.createElement("tr");
    tr.style.animation = "fadeSlideUp 0.25s ease";
    tr.dataset.group = groupId;
    tr.dataset.copyNo = copyNo;
    const uid = ++syllabiRowUidCounter;
    tr.dataset.uid = uid;

    const mirrors = `
        <input type="hidden" name="syllabiCourseName[]" class="syllabi-mirror-course">
        <input type="hidden" name="syllabiCourseCode[]" class="syllabi-mirror-code">
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

window.syncSyllabiMergedFields = function (groupId) {
    const firstRow = document.querySelector(`#syllabiTableBody tr[data-group="${groupId}"][data-is-first="true"]`);
    if (!firstRow) return;

    const courseVal = firstRow.querySelector('.syllabi-merged-course').value;
    const codeVal = firstRow.querySelector('.syllabi-merged-code')?.value || '';
    const availCheckbox = firstRow.querySelector('.syllabi-merged-availability');
    const availHidden = firstRow.querySelector('.syllabi-merged-availability-hidden');
    availHidden.value = availCheckbox.checked ? 'available' : 'not available';
    const copiesVal = firstRow.querySelector('.syllabi-merged-copies').value;
    const pagesVal = firstRow.querySelector('.syllabi-merged-pages').value;

    document.querySelectorAll(`#syllabiTableBody tr[data-group="${groupId}"]:not([data-is-first="true"])`)
        .forEach(row => {
            const mc = row.querySelector('.syllabi-mirror-course');
            const mcode = row.querySelector('.syllabi-mirror-code');
            const ma = row.querySelector('.syllabi-mirror-availability');
            const mp = row.querySelector('.syllabi-mirror-copies');
            const mpg = row.querySelector('.syllabi-mirror-pages');
            if (mc) mc.value = courseVal;
            if (mcode) mcode.value = codeVal;
            if (ma) ma.value = availHidden.value;
            if (mp) mp.value = copiesVal;
            if (mpg) mpg.value = pagesVal;
        });

    updateSyllabiTotals();
};

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
};

window.removeSyllabiGroup = function (groupId) {
    document.querySelectorAll(`#syllabiTableBody tr[data-group="${groupId}"]`).forEach(r => {
        removeSyllabiFacultyPicker(r.dataset.uid);
        r.remove();
    });
    updateSyllabiTotals();
};

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
    document.querySelectorAll('#syllabiTableBody .syllabi-merged-copies').forEach(inp => {
        totalCopies += parseInt(inp.value) || 0;
    });
    const copiesEl = document.getElementById('totalSyllabiCopies');
    if (copiesEl) copiesEl.textContent = totalCopies;

    let totalPages = 0;
    document.querySelectorAll('#syllabiTableBody .syllabi-merged-pages').forEach(inp => {
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
function calcTimeDiff(sDateId, sTimeId, eDateId, eTimeId, dispId, hidId) {
    const sd = document.getElementById(sDateId).value;
    const st = document.getElementById(sTimeId).value;
    const ed = document.getElementById(eDateId).value;
    const et = document.getElementById(eTimeId).value;
    const disp = document.getElementById(dispId);
    const hid = document.getElementById(hidId);
    const result = computeDuration(sd, st, ed, et);
    if (!result) { disp.value = "--"; disp.style.color = ""; hid.value = ""; return; }
    if (result.invalid) { disp.value = "Invalid"; disp.style.color = "var(--reg-error)"; hid.value = ""; return; }
    disp.style.color = "";
    disp.value = formatDuration(result.totalMinutes);
    hid.value = String(result.totalMinutes);
}

window.calcMasterlistTimeSpent = () => calcTimeDiff("masterlistReceiptDate", "masterlistReceiptTime", "masterlistRegisteredDate", "masterlistRegisteredTime", "masterlistTimeSpentDisplay", "masterlistTimeSpent");
window.calcRetrievalTimeSpent = () => calcTimeDiff("retrievalFormDate", "retrievalFormTime", "retrievalDate", "retrievalTime", "retrievalTimeSpentDisplay", "retrievalTimeSpent");
window.calcDistributionTimeSpent = () => calcTimeDiff("distributionFormDate", "distributionFormTime", "distributionDate", "distributionTime", "distributionTimeSpentDisplay", "distributionTimeSpent");

window.generateDistributionTemplate = function () {
    const btn = document.getElementById('btnGenerateDistribution');
    if (btn?.disabled) {
        alert('Save the document first before generating the distribution template.');
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
        alert('Add at least one receiving office before generating the distribution template.');
        return;
    }
    // Always use Masterlist Registration fields for the printed form header/footer.
    const docTitle = (document.getElementById('masterlistDocTitle')?.value || '').trim()
        || (document.getElementById('syllabiDocTitle')?.value || '').trim();
    const effectivityDate = (document.getElementById('masterlistEffectivityDate')?.value || '').trim()
        || (document.getElementById('syllabiEffectivityDate')?.value || '').trim();
    const revisionNo = (document.getElementById('masterlistRevisionNo')?.value || '').trim()
        || (document.querySelector('input[name="masterlistRevisionNo"]')?.value || '').trim();

    const params = new URLSearchParams();
    params.set('date', document.getElementById('distributionDate')?.value || '');
    params.set('template_id', '0');
    params.set('document_title', docTitle);
    params.set('effectivity_date', effectivityDate);
    params.set('revision_no', revisionNo);
    offices.forEach((name) => params.append('offices[]', name));
    copies.forEach((n) => params.append('copies[]', n));
    window.open('{{ route('dcs.reports.distributionTemplate') }}?' + params.toString(), '_blank', 'noopener');
};

function renderDistClusterChips() {
    const wrap = document.getElementById('distClusterChips');
    if (!wrap) return;
    const clusters = (window.__registerCatalog || {}).clusters || [];
    wrap.innerHTML = clusters.map((c) => (
        '<button type="button" class="reg-cluster-chip" data-cluster="' + escapeHtml(c.cluster_code) + '">' +
        'Select all ' + escapeHtml(c.cluster_name) + '</button>'
    )).join('');
    wrap.querySelectorAll('.reg-cluster-chip').forEach((btn) => {
        btn.addEventListener('click', () => toggleOfficesByCluster(btn.getAttribute('data-cluster')));
    });
    syncDistClusterChipState();
}

function getSelectedOfficeIds(bodyId) {
    const tbody = document.getElementById(bodyId);
    if (!tbody) return [];
    return [...tbody.querySelectorAll('input[type="hidden"][name="distOffice[]"], input[type="hidden"][name="retrievalOffice[]"]')]
        .map((inp) => String(inp.value))
        .filter(Boolean);
}

function clusterOffices(clusterCode) {
    return allOffices.filter((o) => String(o.cluster) === String(clusterCode));
}

function isClusterFullySelected(clusterCode) {
    const offices = clusterOffices(clusterCode);
    if (!offices.length) return false;
    const selected = new Set(getSelectedOfficeIds('distBody'));
    return offices.every((o) => selected.has(String(o.office_id)));
}

function addOfficesByCluster(clusterCode) {
    clusterOffices(clusterCode).forEach((o) => {
        addOffice(o.office_id, o.office_name, 'distBody', 'distTotal', 'distResults');
    });
    syncDistClusterChipState();
}

function removeOfficesByCluster(clusterCode) {
    const tbody = document.getElementById('distBody');
    if (!tbody) return;

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
}

function toggleOfficesByCluster(clusterCode) {
    if (isClusterFullySelected(clusterCode)) {
        removeOfficesByCluster(clusterCode);
    } else {
        addOfficesByCluster(clusterCode);
    }
    syncDistClusterChipState();
}

function syncDistClusterChipState() {
    document.querySelectorAll('#distClusterChips .reg-cluster-chip').forEach((btn) => {
        const code = btn.getAttribute('data-cluster');
        btn.classList.toggle('is-active', isClusterFullySelected(code));
    });
}

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

function sectionVisible(id) {
    const el = document.getElementById(id);
    return el && el.style.display !== "none";
}

function validateForm() {
    clearValidation();
    const errors = [];

    if (docNoDuplicate) {
        errors.push({ field: "masterlistDocNo", message: "This document number is already registered. Please use a unique number." });
        return errors;
    }

    const checkedAny = document.querySelectorAll("#dynamicCheckboxes input[type='checkbox']:checked");
    if (checkedAny.length === 0) {
        errors.push({ field: "dynamicCheckboxes", message: "Please check at least one checklist.", type: "checklist" });
        return errors;
    }

    validateTimeSpentFields(errors);
    validateRevisionRowsLinked(errors);

    return errors;
}

function validateTimeSpentFields(errors) {
    if (sectionVisible("section-3")) {
        const ml = document.getElementById("masterlistTimeSpentDisplay");
        if (ml && ml.value === "Invalid") {
            errors.push({ field: "masterlistRegisteredDate", message: "Masterlist: Document Registered must be after Document Receipt." });
            ["masterlistReceiptDate", "masterlistReceiptTime", "masterlistRegisteredDate", "masterlistRegisteredTime"]
                .forEach(id => document.getElementById(id)?.classList.add("reg-input-error"));
        }
    }
    if (sectionVisible("section-4")) {
        const ret = document.getElementById("retrievalTimeSpentDisplay");
        if (ret && ret.value === "Invalid") {
            errors.push({ field: "retrievalDate", message: "Retrieval: Retrieval Date must be after Form Date." });
        }
    }
    if (sectionVisible("section-5")) {
        const dist = document.getElementById("distributionTimeSpentDisplay");
        if (dist && dist.value === "Invalid") {
            errors.push({ field: "distributionDate", message: "Distribution: Distribution Date must be after Form Date." });
        }
    }
}

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

function showValidationErrors(errors) {
    errors.forEach(err => {
        if (err.type === "table") markTableError(err.field, err.message);
        else if (err.type === "search") markSearchError(err.field, err.message);
        else if (err.type === "checklist") markChecklistError(err.message);
        else markFieldError(err.field, err.message);
    });
    scrollToField(errors[0].field);
}

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
        checkText("Syllabi", "syllabiCollege", "College");
        checkText("Syllabi", "syllabiProgram", "Program");
        checkText("Syllabi", "syllabiSemester", "Semester");
        checkText("Syllabi", "syllabiSchoolYear", "School Year");
        checkText("Syllabi", "syllabiDocNo", "Document No.");
        checkText("Syllabi", "syllabiDocTitle", "Document Title");
        checkText("Syllabi", "syllabiEffectivityDate", "Effectivity Date");
        checkText("Syllabi", "syllabiDeadline", "Deadline");

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
            checkText("Masterlist", "masterlistDocNo", "Document No.");
            checkText("Masterlist", "masterlistDocTitle", "Document Title");
            checkText("Masterlist", "masterlistEffectivityDate", "Effectivity Date");
        }
        checkText("Masterlist", "masterlistNoOfPages", "No. of Pages");
        checkText("Masterlist", "keywords", "Keywords");
        if (!window.__sourceWidgets.masterlistOriginator || window.__sourceWidgets.masterlistOriginator.selected.length === 0) missing.push("Masterlist: Originator");
        if (!window.__sourceWidgets.masterlist || window.__sourceWidgets.masterlist.selected.length === 0) missing.push("Masterlist: Source Unit");
    }

    if (sectionVisible("section-4")) {
        checkText("Retrieval", "retrievalFormDate", "Form Date");
        checkText("Retrieval", "retrievalDate", "Retrieval Date");
        if (document.querySelectorAll("#retrievalBody input[type='hidden']").length === 0) missing.push("Retrieval: At least one office");
    }

    if (sectionVisible("section-5")) {
        checkText("Distribution", "distributionFormDate", "Form Date");
        checkText("Distribution", "distributionDate", "Distribution Date");
        if (document.querySelectorAll("#distBody input[type='hidden']").length === 0) missing.push("Distribution: At least one office");
    }

    return missing;
}

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
        confirmBtn.style.opacity = '0.5';
        confirmBtn.style.cursor = 'not-allowed';
    }
}

window.handleConfirmSaveAnywayToggle = function (checkbox) {
    const confirmBtn = document.getElementById('btnConfirmSaveModal');
    if (!confirmBtn) return;
    confirmBtn.disabled = !checkbox.checked;
    if (checkbox.checked) { confirmBtn.style.opacity = ''; confirmBtn.style.cursor = ''; }
    else { confirmBtn.style.opacity = '0.5'; confirmBtn.style.cursor = 'not-allowed'; }
};

// ══════════════════════════════════════════════
// CONFIRM SAVE / REVIEW
// ══════════════════════════════════════════════
function getInputVal(id) { const el = document.getElementById(id); return el ? el.value.trim() : ""; }
function getSelectText(id) {
    const el = document.getElementById(id);
    if (!el || el.selectedIndex < 0) return "";
    return el.options[el.selectedIndex].text;
}
function formatInputDate(id) {
    const val = getInputVal(id);
    if (!val) return "";
    const date = new Date(val + "T00:00:00");
    if (isNaN(date.getTime())) return val;
    return date.toLocaleDateString("en-US", { year: "numeric", month: "short", day: "numeric" });
}
function fmtDateValue(val) {
    if (!val) return "";
    const d = new Date(val + "T00:00:00");
    return isNaN(d.getTime()) ? val : d.toLocaleDateString("en-US", { year: "numeric", month: "short", day: "numeric" });
}
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

function addReviewSection(container, title, fields) {
    if (fields.length === 0) return;
    const section = document.createElement("div");
    section.className = "review-section";
    let html = '<div class="review-section-title">' + escapeHtml(title) + '</div>';
    fields.forEach(f => {
        const hasValue = f.value && String(f.value).trim() !== "" && f.value !== "N/A";
        html += '<div class="review-row' + (hasValue ? '' : ' review-row-empty') + '">';
        html += '<span class="review-label">' + escapeHtml(f.label) + '</span>';
        if (hasValue) {
            html += f.isFile
                ? '<span class="review-value review-file"><i class="fa-solid fa-paperclip"></i> ' + escapeHtml(f.value) + '</span>'
                : '<span class="review-value">' + escapeHtml(String(f.value)) + '</span>';
        } else {
            html += '<span class="review-value review-value-missing"><i class="fa-solid fa-circle-minus"></i> Not provided</span>';
        }
        html += '</div>';
    });
    section.innerHTML = html;
    container.appendChild(section);
}

function addReviewList(container, title, items) {
    const section = document.createElement("div");
    section.className = "review-section";
    let html = '<div class="review-section-title">' + escapeHtml(title) + '</div><ul class="review-list">';
    items.forEach(item => { html += '<li>' + escapeHtml(item) + '</li>'; });
    html += '</ul>';
    section.innerHTML = html;
    container.appendChild(section);
}

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

window.confirmSave = function () {
    if (docNoDuplicate) {
        const fieldId = window.__isSyllabiMode ? 'syllabiDocNo' : 'masterlistDocNo';
        scrollToField(fieldId);
        document.getElementById(fieldId)?.focus();
        return;
    }
    const errors = validateForm();
    if (errors.length > 0) { showValidationErrors(errors); return; }
    clearValidation();

    const reviewContent = document.getElementById("reviewContent");
    reviewContent.innerHTML = "";

    buildSyllabiInfoReview(reviewContent);
    buildSyllabiRowsReview(reviewContent);
    buildDrfReview(reviewContent);
    buildDcnReview(reviewContent);
    buildMasterlistReview(reviewContent);
    buildApprovalReview(reviewContent);
    buildRetrievalReview(reviewContent);
    buildDistributionReview(reviewContent);

    if (!reviewContent.children.length) {
        reviewContent.innerHTML = '<div class="review-empty">No data to review.</div>';
    }

    const missing = collectMissingFields();
    renderMissingFieldsWarning(reviewContent, missing);

    const root = document.getElementById("dcsEditRoot");
    if (root) root.style.overflow = "hidden";
    if (root && window.Alpine) Alpine.$data(root).reviewOpen = true;
};

function buildSyllabiInfoReview(reviewContent) {
    const ss = document.getElementById("section-syllabi");
    if (!ss || ss.style.display === "none") return;
    addReviewSection(reviewContent, "Syllabi — Document Info", [
        { label: "College", value: getSelectText("syllabiCollege") },
        { label: "Program", value: getSelectText("syllabiProgram") },
        { label: "Semester", value: getSelectText("syllabiSemester") },
        { label: "School Year", value: getSelectText("syllabiSchoolYear") },
        { label: "Document No.", value: getInputVal("syllabiDocNo") },
        { label: "Document Title", value: getInputVal("syllabiDocTitle") },
        { label: "Effectivity Date", value: formatInputDate("syllabiEffectivityDate") },
        { label: "Deadline", value: formatInputDate("syllabiDeadline") },
    ]);
}

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
        const label = (n && n.value.trim() ? n.value.trim() + ' — ' : '') + (t && t.value.trim() ? t.value.trim() : '');
        if (label) rev.push("Row " + (i + 1) + ": " + label);
    });
    if (rev.length) addReviewList(reviewContent, "Revisions", rev);
}

function buildMasterlistReview(reviewContent) {
    const s3 = document.getElementById("section-3");
    if (!s3 || s3.style.display === "none") return;
    const f = document.getElementById("uploadScannedCopy")?.files;
    addReviewSection(reviewContent, "Masterlist Registration", [
        { label: "Doc No.", value: getInputVal("masterlistDocNo") },
        { label: "Title", value: getInputVal("masterlistDocTitle") },
        { label: "Deadline", value: formatInputDate("deadlineOfSubmission") },
        { label: "Receipt", value: formatInputDate("masterlistReceiptDate") + " " + getInputVal("masterlistReceiptTime") },
        { label: "Registered", value: formatInputDate("masterlistRegisteredDate") + " " + getInputVal("masterlistRegisteredTime") },
        { label: "Time Spent", value: document.getElementById("masterlistTimeSpentDisplay")?.value || null },
        { label: "Effectivity", value: formatInputDate("masterlistEffectivityDate") },
        { label: "Revision No.", value: getInputVal("masterlistRevisionNo") },
        { label: "Pages", value: getInputVal("masterlistNoOfPages") },
        { label: "Originator", value: window.__sourceWidgets.masterlistOriginator?.selected.length > 0 ? window.__sourceWidgets.masterlistOriginator.selected.map(o => o.label).join(', ') : null },
        { label: "Source Unit", value: window.__sourceWidgets.masterlist?.selected.length > 0 ? window.__sourceWidgets.masterlist.selected.map(i => i.label).join(', ') : null },
        { label: "Keywords", value: getInputVal("keywords") },
        { label: "Related Docs", value: relatedDocsSelected.length > 0 ? relatedDocsSelected.map(d => d.doc_title).join(', ') : null },
        { label: "File", value: f && f.length > 0 ? f[0].name : null, isFile: true },
    ]);
}

function buildApprovalReview(reviewContent) {
    const sa = document.getElementById("section-approval");
    if (!sa || sa.style.display === "none") return;
    addReviewSection(reviewContent, "Approval Details", [
        { label: "Body", value: getSelectText("approvalBody") },
        { label: "Date", value: formatInputDate("approvalDate") },
        { label: "No.", value: getInputVal("approvalNo") },
    ]);
}

function buildRetrievalReview(reviewContent) {
    const s4 = document.getElementById("section-4");
    if (!s4 || s4.style.display === "none") return;
    const f = document.getElementById("scannedRet")?.files;
    addReviewSection(reviewContent, "Document Retrieval", [
        { label: "Form Date", value: formatInputDate("retrievalFormDate") + " " + getInputVal("retrievalFormTime") },
        { label: "Retrieval Date", value: formatInputDate("retrievalDate") + " " + getInputVal("retrievalTime") },
        { label: "Time Spent", value: document.getElementById("retrievalTimeSpentDisplay")?.value || null },
        { label: "Remarks", value: getInputVal("retrievalRemarks") },
        { label: "File", value: f && f.length > 0 ? f[0].name : null, isFile: true },
    ]);
    const off = getOfficeList("retrievalBody");
    if (off.length) addReviewOfficeList(reviewContent, "Receiving Offices (Retrieval)", off);
}

function buildDistributionReview(reviewContent) {
    const s5 = document.getElementById("section-5");
    if (!s5 || s5.style.display === "none") return;
    const f = document.getElementById("scanneddist")?.files;
    addReviewSection(reviewContent, "Document Distribution", [
        { label: "Form Date", value: formatInputDate("distributionFormDate") + " " + getInputVal("distributionFormTime") },
        { label: "Distribution Date", value: formatInputDate("distributionDate") + " " + getInputVal("distributionTime") },
        { label: "Time Spent", value: document.getElementById("distributionTimeSpentDisplay")?.value || null },
        { label: "Remarks", value: getInputVal("distributionRemarks") },
        { label: "File", value: f && f.length > 0 ? f[0].name : null, isFile: true },
    ]);
    const off = getOfficeList("distBody");
    if (off.length) addReviewOfficeList(reviewContent, "Receiving Offices (Distribution)", off);
}

window.closeConfirmModal = function () {
    const root = document.getElementById("dcsEditRoot");
    if (root && window.Alpine) Alpine.$data(root).reviewOpen = false;
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
window.submitForm = function () {
    syncChecklistHiddenInputs();
    document.getElementById("masterForm").submit();
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
        <td class="reg-rev-scan-cell" style="text-align:center;color:#94a3b8;">—</td>
        <td class="reg-rev-purpose-cell">
            <input type="hidden" name="revisionPurpose[]" value="">
            <span class="reg-rev-purpose-text">—</span>
        </td>
        <td><button type="button" class="reg-row-del" onclick="removeRevisionRow(this)"><i class="fa-solid fa-trash-can"></i></button></td>
    `;
}

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

window.addOffice = function (officeId, officeName, bodyId, totalId, resultsId) {
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

    if (isRetrieval) {
        seedRetrievalOfficeRow(bodyId, totalId, officeId, officeName, 1, 'pending');
        updateTotal(totalId, bodyId);
        if (dropdown) {
            dropdown.style.display = "none";
            const searchInput = dropdown.parentElement?.querySelector("input[type='text']");
            if (searchInput) searchInput.value = "";
        }
        return;
    }

    const officeNameAttr = "distOffice[]";
    const copiesNameAttr = "distCopies[]";
    const safeDisplay = escapeHtml(officeName);
    const tr = document.createElement("tr");
    tr.className = "reg-office-added";
    tr.draggable = true;
    tr.innerHTML = `<td><input type="hidden" name="${officeNameAttr}" value="${officeId}"><div class="reg-office-name"><div class="reg-office-icon"><i class="fa-solid fa-building"></i></div><span class="reg-office-text">${safeDisplay}</span></div></td><td style="text-align:center;"><input type="number" name="${copiesNameAttr}" value="1" min="1" oninput="updateTotal('${totalId}', '${bodyId}')"></td><td><button type="button" class="btn-remove" onclick="removeOffice(this, '${totalId}', '${bodyId}')"><i class="fa-solid fa-xmark"></i></button></td>`;
    tbody.appendChild(tr);
    updateTotal(totalId, bodyId);
    if (dropdown) {
        dropdown.style.display = "none";
        const searchInput = dropdown.parentElement?.querySelector("input[type='text']");
        if (searchInput) searchInput.value = "";
    }
    if (bodyId === 'distBody') syncDistClusterChipState();
};

window.removeOffice = function (btn, totalId, bodyId) {
    const tr = btn.closest("tr");
    if (bodyId === 'retrievalBody') {
        const officeId = tr.querySelector('input[type="hidden"][name="retrievalOffice[]"]')?.value;
        const status = tr.querySelector('.reg-retrieval-status')?.value;
        if (officeId && status === 'retrieved') {
            removeRetrievedOfficeFromDistribution(officeId);
        }
    }
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
    }, 200);
};

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

document.addEventListener("click", function (e) {
    document.querySelectorAll(".reg-search-dropdown").forEach(dd => {
        if (!dd.parentElement.contains(e.target)) dd.style.display = "none";
    });
});
</script>
@endif

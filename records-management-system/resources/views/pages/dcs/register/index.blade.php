<?php

use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('CSPC - Document Control System')] class extends Component {
    public function with(): array
    {
        return [
            'catalog' => RegisterQueryHelper::jsCatalog(),
        ];
    }
}; ?>

<main class="reg-container" id="dcsRegisterRoot" wire:ignore x-data="dcsRegisterPage()">

    <div class="reg-header">
        <div class="reg-header-text">
            <p class="reg-breadcrumb">Document Control System / Document Registration / <span>Register</span></p>
            <h1 class="reg-title">Register Document</h1>
        </div>
    </div>

    <form id="masterForm" enctype="multipart/form-data" method="POST" action="{{ route('dcs.register.store') }}" autocomplete="off">
        @csrf
        <input type="hidden" id="registrationMode" name="registration_mode" value="new">
        <input type="hidden" id="revisedFromDocNo" name="revised_from_doc_no" value="">

        <!-- ═══ TOP SELECTION PANEL ═══ -->
        <section class="reg-panel">
            <div class="reg-panel-grid">
                <div class="reg-field">
                    <label>Version Type</label>
                    <select id="versionType" name="version_id" autocomplete="off">
                        <option value="" selected disabled>Select version</option>
                    </select>
                </div>
                <div class="reg-field">
                    <label>Document Type</label>
                    <select id="docType" name="doc_type_id" disabled autocomplete="off">
                        <option value="" selected disabled>Select type</option>
                    </select>
                </div>
                <div class="reg-field">
                    <label>Sub-Type Document</label>
                    <select id="subType" name="sub_type_id" disabled autocomplete="off">
                        <option value="" selected disabled>Select sub-type</option>
                    </select>
                </div>
            </div>
            <div class="reg-panel-bottom">
                <div class="reg-checklist" id="dynamicCheckboxes"></div>
                <div class="reg-approval-toggle">
                    <span class="reg-toggle-label">Approval</span>
                    <div class="reg-toggle-options">
                        <label class="reg-radio">
                            <input type="radio" name="approval_status" value="applicable"
                                onchange="handleApprovalToggle(true)" disabled>
                            <span>Applicable</span>
                        </label>
                        <label class="reg-radio">
                            <input type="radio" name="approval_status" value="not_applicable"
                                onchange="handleApprovalToggle(false)" checked disabled>
                            <span>Not Applicable</span>
                        </label>
                    </div>
                </div>
            </div>
        </section>

        <section class="reg-card" id="section-2" style="display: none;">
            <div class="reg-card-header">
                <span>Document Change Notice</span>
            </div>
            <div class="reg-card-body">
                <div class="reg-field reg-revision-table">
                    <label>Documents for Revision</label>
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
                                    <td>
                                        <button type="button" class="reg-row-del" onclick="removeRevisionRow(this)">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" id="btnAddRevisionRow" onclick="addRevisionRow()">
                        <i class="fa-solid fa-plus"></i> Add Row
                    </button>
                </div>

                <div class="reg-field">
                    <label>Justification</label>
                    <input type="text" id="dcnJustification" name="dcnJustification" placeholder="Enter justification for this change notice...">
                </div>

                <div class="reg-grid-3">
                    <div class="reg-field">
                        <label>DCN No.</label>
                        <input type="text" id="dcnNumber" name="dcnNumber" placeholder="Enter DCN No.">
                    </div>
                    <div class="reg-field">
                        <label>DCN Date</label>
                        <input type="date" id="noticeDate" name="noticeDate">
                    </div>
                    <div class="reg-field">
                        <label>DCN Receipt</label>
                        <div class="reg-dual">
                            <input type="date" id="receiptDate" name="receiptDate">
                            <input type="time" id="receiptTime" name="receiptTime">
                        </div>
                    </div>
                </div>
                <div class="reg-grid-2-1">
                    <div class="reg-field">
                        <label>Upload Scanned DCN</label>
                        <label class="reg-upload">
                            <input type="file" id="dcnFile" name="dcnFile" accept=".pdf">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <span>Choose scanned PDF</span>
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

        <!-- ═══ SECTION SYLLABI ═══ -->
        <section class="reg-card" id="section-syllabi" style="display: none;">
            <div class="reg-card-header">
                <span>Syllabi</span>
            </div>
            <div class="reg-card-body">

                <!-- ═══ CONTEXT: College / Program / Semester / School Year ═══ -->
                <div class="reg-grid-4">
                    <div class="reg-field">
                        <label>College</label>
                        <select id="syllabiCollege" name="college_id">
                            <option value="" selected disabled>Select college</option>
                        </select>
                    </div>
                    <div class="reg-field">
                        <label>Program</label>
                        <select id="syllabiProgram" name="program_id" disabled>
                            <option value="" selected disabled>Select program</option>
                        </select>
                    </div>
                    <div class="reg-field">
                        <label>Semester</label>
                        <select id="syllabiSemester" name="semester_id" disabled>
                            <option value="" selected disabled>Select semester</option>
                        </select>
                    </div>
                    <div class="reg-field">
                        <label>School Year</label>
                        <select id="syllabiSchoolYear" name="school_year_id" disabled>
                            <option value="" selected disabled>Select school year</option>
                        </select>
                    </div>
                </div>

                <!-- ═══ DOCUMENT INFO ═══ -->
                <div class="reg-grid-3">
                    <div class="reg-field">
                        <label>Document No.</label>
                        <input type="text" id="syllabiDocNo" name="syllabiDocNo" placeholder="Enter Document No.">
                    </div>
                    <div class="reg-field">
                        <label>Effectivity Date</label>
                        <input type="date" id="syllabiEffectivityDate" name="syllabiEffectivityDate">
                    </div>
                    <div class="reg-field">
                        <label>Deadline of Submission</label>
                        <input type="date" id="syllabiDeadline" name="syllabiDeadline">
                    </div>
                </div>
                <div class="reg-field" style="margin-bottom: 16px;">
                    <label>Document Title</label>
                    <input type="text" id="syllabiDocTitle" name="syllabiDocTitle" placeholder="Enter Document Title">
                </div>

                <!-- ═══ WIZARD STEP INDICATOR ═══ -->
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
                        Scroll down and click <strong>Save Document</strong> to submit.
                    </span>
                </div>
            </div>
        </section>

        <!-- ═══ SECTION 1 — DRF ═══ -->
        <section class="reg-card" id="section-1" style="display: none;">
            <div class="reg-card-header">
                <span>Document Request Form</span>
            </div>
            <div class="reg-card-body">
                <div class="reg-grid-3">
                    <div class="reg-field">
                        <label>DRF No.</label>
                        <input type="text" id="drfNo" name="drfNo" placeholder="Enter DRF No.">
                    </div>
                    <div class="reg-field">
                        <label>DRF Date</label>
                        <input type="date" id="drfDate" name="drfDate">
                    </div>
                    <div class="reg-field">
                        <label>Date Receipt</label>
                        <div class="reg-dual">
                            <input type="date" id="drfReceiptDate" name="drfReceiptDate">
                            <input type="time" id="drfTime" name="drfTime">
                        </div>
                    </div>
                </div>
                <div class="reg-grid-2-1">
                    <div class="reg-field">
                        <label>Document Title</label>
                        <input type="text" id="drfTitle" name="drfTitle" placeholder="Enter document title">
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
                    <label class="reg-upload">
                        <input type="file" id="drfFile" name="drfFile" accept=".pdf">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <span>Choose scanned PDF</span>
                    </label>
                </div>
            </div>
        </section>

        <!-- ═══ APPROVAL DETAILS ═══ -->
        <section class="reg-card" id="section-approval" style="display: none;">
            <div class="reg-card-header">
                <span>Approval Details</span>
            </div>
            <div class="reg-card-body">
                <div class="reg-grid-3">
                    <div class="reg-field">
                        <label>Approving Body</label>
                        <select id="approvalBody" name="approvalBody">
                            <option value="" disabled selected>Select approving body</option>
                        </select>
                    </div>
                    <div class="reg-field">
                        <label>Approval Date</label>
                        <input type="date" id="approvalDate" name="approvalDate">
                    </div>
                    <div class="reg-field">
                        <label>Approval No.</label>
                        <input type="text" id="approvalNo" name="approvalNo" placeholder="Enter Approval No.">
                    </div>
                </div>
            </div>
        </section>

        <!-- ═══ SECTION 3 — MASTERLIST ═══ -->
        <section class="reg-card" id="section-3" style="display: none;">
            <div class="reg-card-header">
                <span>Masterlist Registration</span>
            </div>
            <div class="reg-card-body">
                <!-- Rows 1-2: 4-column grid, Time Spent spans 2 rows -->
                <div class="reg-ml-grid">
                    <!-- Row 1 -->
                    <div class="reg-field" id="mlFieldDocNo">
                        <label>Document No.</label>
                        <input type="text" id="masterlistDocNo" name="masterlistDocNo" placeholder="Enter Document no.">
                        <span id="docNoHint" style="display:block;margin-top:4px;font-size:12px;"></span>
                    </div>
                    <div class="reg-field" id="mlFieldDeadline">
                        <label>Deadline of Submission</label>
                        <input type="date" id="deadlineOfSubmission" name="deadlineOfSubmission">
                    </div>
                    <div class="reg-field">
                        <label>Document Receipt</label>
                        <div class="reg-dual">
                            <input type="date" id="masterlistReceiptDate" name="masterlistReceiptDate" oninput="calcMasterlistTimeSpent()">
                            <input type="time" id="masterlistReceiptTime" name="masterlistReceiptTime" oninput="calcMasterlistTimeSpent()">
                        </div>
                    </div>
                    <div class="reg-field reg-ml-timespent">
                        <label>Time Spent</label>
                        <input type="text" id="masterlistTimeSpentDisplay" readonly placeholder="--"
                            style="background: #f8fafc; cursor: default; font-weight: 700; text-align: center; font-size: 18px; height: 100%; min-height: 80px;">
                        <input type="hidden" id="masterlistTimeSpent" name="masterlistTimeSpent">
                    </div>

                    <!-- Row 2 -->
                    <div class="reg-field reg-ml-title-span" id="mlFieldTitle">
                        <label>Document Title</label>
                        <input type="text" id="masterlistDocTitle" name="masterlistDocTitle" placeholder="Enter Document title">
                    </div>
                    <div class="reg-field">
                        <label>Document Registered</label>
                        <div class="reg-dual">
                            <input type="date" id="masterlistRegisteredDate" name="masterlistRegisteredDate" oninput="calcMasterlistTimeSpent()">
                            <input type="time" id="masterlistRegisteredTime" name="masterlistRegisteredTime" oninput="calcMasterlistTimeSpent()">
                        </div>
                    </div>
                </div>

                <!-- Row 3: 5-column grid -->
                <div class="reg-ml-mid">
                    <div class="reg-field" id="mlFieldEffectivity">
                        <label>Effectivity Date</label>
                        <input type="date" id="masterlistEffectivityDate" name="masterlistEffectivityDate">
                    </div>
                    <div class="reg-field">
                        <label>Revision No.</label>
                        <input type="number" id="masterlistRevisionNo" name="masterlistRevisionNo" min="0" placeholder="0">
                        <span id="revNoHint" style="display:block;margin-top:4px;font-size:12px;"></span>
                    </div>
                    <div class="reg-field">
                        <label>No. of Pages</label>
                        <input type="number" id="masterlistNoOfPages" name="masterlistNoOfPages" min="0" placeholder="0">
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
                            <div class="reg-reldocs-summary" id="masterlistOriginatorSummary" style="display:none;"></div>
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
                            <div class="reg-reldocs-summary" id="masterlistSourceSummary" style="display:none;"></div>
                            <div id="masterlistSourceSuggestions" class="reg-reldocs-dropdown" style="display:none;"></div>
                            <div id="masterlistSourceInlineChips" class="reg-reldocs-dropdown reg-reldocs-selected-panel" style="display:none;"></div>
                        </div>
                    </div>
                </div>

                <!-- Row 4: 2-column grid -->
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
                            <input type="hidden" id="keywords" name="keywords" value="">
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

                            <!-- Search results (shown while typing) -->
                            <div id="relatedDocsResults" class="reg-reldocs-dropdown" style="display:none;"></div>

                            <!-- Selected documents (shown when arrow is clicked) -->
                            <div id="relatedDocsSelectedPanel" class="reg-reldocs-dropdown reg-reldocs-selected-panel" style="display:none;">
                                <div id="relatedDocsChips" class="reg-reldocs-chips"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Row 5: Full width -->
                <div class="reg-field">
                    <label>Upload Scanned Copy</label>
                    <label class="reg-upload">
                        <input type="file" id="uploadScannedCopy" name="uploadScannedCopy" accept=".pdf">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <span>Choose scanned PDF</span>
                    </label>
                    <div class="reg-upload-actions" id="compareRevisionWrap" style="display:none;margin-top:10px;">
                        <button type="button" id="btnCompareRevision" class="reg-btn reg-btn-save">
                            <i class="fa-solid fa-code-compare"></i> Compare Revisions (DRR)
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- ═══ SECTION 4 — DOCUMENT RETRIEVAL ═══ -->
        <section class="reg-card" id="section-4" style="display: none;">
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
                                    <input type="date" id="retrievalFormDate" name="retrievalFormDate" oninput="calcRetrievalTimeSpent()">
                                    <input type="time" id="retrievalFormTime" name="retrievalFormTime" oninput="calcRetrievalTimeSpent()">
                                </div>
                            </div>
                            <div class="reg-field">
                                <label>Retrieval Date & Time</label>
                                <div class="reg-dual">
                                    <input type="date" id="retrievalDate" name="retrievalDate" oninput="calcRetrievalTimeSpent()">
                                    <input type="time" id="retrievalTime" name="retrievalTime" oninput="calcRetrievalTimeSpent()">
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
                        <input type="text" id="retrievalRemarks" name="retrievalRemarks" placeholder="Type here...">
                    </div>
                    <div class="reg-field">
                        <label>Upload Scanned D&R</label>
                        <label class="reg-upload">
                            <input type="file" id="scannedRet" name="scannedRet" accept=".pdf">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <span>Choose scanned PDF</span>
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
                            <input type="text" id="retrievalSearch" placeholder="Search and add office..." autocomplete="off"
                                oninput="handleSearch(this, 'retrievalResults', 'retrievalBody', 'totalRetrievalCopies')">
                            <div id="retrievalResults" class="reg-search-dropdown" style="display:none;"></div>
                        </div>
                    </div>
                    <div class="reg-office-table-wrap">
                        <table class="reg-dist-table">
                            <thead>
                                <tr>
                                    <th>Receiving Office(s)</th>
                                    <th style="width: 130px;">Status</th>
                                    <th style="width: 110px; text-align: center;">No. of Copies</th>
                                    <th style="width: 40px;"></th>
                                </tr>
                            </thead>
                            <tbody id="retrievalBody">
                                <tr class="reg-empty-row">
                                    <td colspan="4">
                                        <div class="reg-empty-state">
                                            <i class="fa-solid fa-building-circle-xmark"></i>
                                            <span>No offices added yet</span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                            <tbody id="retrievalRetrievedHidden" style="display:none;" aria-hidden="true"></tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="2">Total No. of Copies</td>
                                    <td id="totalRetrievalCopies" style="text-align: center; font-weight: 700;">0</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <!-- ═══ SECTION 5 — DOCUMENT DISTRIBUTION ═══ -->
        <section class="reg-card" id="section-5" style="display: none;">
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
                                    <input type="date" id="distributionFormDate" name="distributionFormDate" oninput="calcDistributionTimeSpent()">
                                    <input type="time" id="distributionFormTime" name="distributionFormTime" oninput="calcDistributionTimeSpent()">
                                </div>
                            </div>
                            <div class="reg-field">
                                <label>Distribution Date & Time</label>
                                <div class="reg-dual">
                                    <input type="date" id="distributionDate" name="distributionDate" oninput="calcDistributionTimeSpent()">
                                    <input type="time" id="distributionTime" name="distributionTime" oninput="calcDistributionTimeSpent()">
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
                        <input type="text" id="distributionRemarks" name="distributionRemarks" placeholder="Type here...">
                    </div>
                    <div class="reg-field">
                        <label>Upload Scanned D&R</label>
                        <label class="reg-upload">
                            <input type="file" id="scanneddist" name="scanneddist" accept=".pdf">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <span>Choose scanned PDF</span>
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
                            <input type="text" id="distSearch" placeholder="Search and add office..." autocomplete="off"
                                oninput="handleSearch(this, 'distResults', 'distBody', 'totalDistCopies')">
                            <div id="distResults" class="reg-search-dropdown" style="display:none;"></div>
                        </div>
                    </div>
                    <div class="reg-office-table-wrap">
                        <table class="reg-dist-table">
                            <thead>
                                <tr>
                                    <th>Receiving Office(s)</th>
                                    <th style="width: 110px; text-align: center;">No. of Copies</th>
                                    <th style="width: 40px;"></th>
                                </tr>
                            </thead>
                            <tbody id="distBody">
                                <tr class="reg-empty-row">
                                    <td colspan="3">
                                        <div class="reg-empty-state">
                                            <i class="fa-solid fa-building-circle-xmark"></i>
                                            <span>No offices added yet</span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td>Total No. of Copies</td>
                                    <td id="totalDistCopies" style="text-align: center; font-weight: 700;">0</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <!-- ═══ FORM ACTIONS ═══ -->
        <section class="reg-actions" id="formActions" style="display: none;">
            <div class="reg-actions-left">
                <a href="{{ route('dcs.dashboard') }}" class="reg-btn reg-btn-cancel">
                    <i class="fa-solid fa-xmark"></i> Cancel
                </a>
            </div>
            <div class="reg-actions-right">
                <button type="button" id="btnGenerateDistribution" class="reg-btn reg-btn-generate" onclick="generateDistributionTemplate()" disabled title="Save the document first">
                    <i class="fa-solid fa-file-lines"></i> Generate
                </button>
                <button type="button" id="btnSaveDocument" class="reg-btn reg-btn-save" onclick="confirmSave()">
                    <i class="fa-solid fa-floppy-disk"></i> Save Document
                </button>
            </div>
        </section>

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
            <button type="button" id="btnConfirmSaveModal" class="reg-btn reg-btn-save" onclick="submitForm()">
                <i class="fa-solid fa-check"></i> Confirm Save
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

    <template x-teleport="body">
    <div class="reg-modal-overlay reg-modal-overlay--wide" id="compareRevisionModal" aria-hidden="true" onclick="if(event.target===this)closeCompareRevisionModal()">
        <div class="reg-modal reg-modal--wide">
            <div class="reg-modal-header">
                <i class="fa-solid fa-code-compare"></i>
                <h3>Compare Revisions</h3>
                <button type="button" class="reg-modal-close" onclick="closeCompareRevisionModal()">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="reg-modal-body reg-modal-body--compare">
                <div class="reg-compare-legend">
                    <span class="reg-compare-leg reg-compare-leg-del">Removed</span>
                    <span class="reg-compare-leg reg-compare-leg-ins">Added</span>
                    <span class="reg-compare-leg reg-compare-leg-chg">Changed</span>
                </div>
                <div class="reg-compare-panels" id="registerPdfCompare">
                    <div class="reg-compare-panel">
                        <div class="reg-compare-label" id="compareRevisionPrevLabel">Previous revision</div>
                        <div class="reg-compare-pdf-stage" data-review-side="left"></div>
                        <p class="reg-compare-pdf-note" data-review-note="left"></p>
                    </div>
                    <div class="reg-compare-panel">
                        <div class="reg-compare-label" id="compareRevisionNewLabel">New upload</div>
                        <div class="reg-compare-pdf-stage" data-review-side="right"></div>
                        <p class="reg-compare-pdf-note" data-review-note="right"></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </template>
</main>

<script>
window.__registerCatalog = @json($catalog);

document.addEventListener('alpine:init', () => {
    Alpine.data('dcsRegisterPage', () => ({
        syllabiStep: 1,
        reviewOpen: false,
        setSyllabiStep(step) {
            this.syllabiStep = step;
            window.syllabiCurrentStep = step;
        },
        closeReview() {
            this.reviewOpen = false;
            const el = document.getElementById('dcsRegisterRoot');
            if (el) el.style.overflow = '';
        },
        addSyllabiRow() {
            if (typeof window.addSyllabiRow === 'function') window.addSyllabiRow();
        },
    }));
});
let allOffices = [];
let allDocTypes = [];
let allOriginators = [];
let syllabiGroupCounter = 0;
let syllabiCurrentStep = 1;
let syllabiRowUidCounter = 0;
let allFaculties = [];
let relatedDocsCache = [];
let relatedDocsSelected = window.__existingRelatedDocs || [];
let relatedDocsSelectedPanelOpen = false;
let docNoDuplicate = false;
let revNoDuplicate = false;
let revisionRowUidCounter = 0;
let revSearchCache = {};
let revSearchTimers = {};
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

// ══════════════════════════════════════════════
// SHARED HELPERS
// (previously duplicated across upload / time / office-search code)
// ══════════════════════════════════════════════

/** Validate a File against the allowed extension / size rules. */
function checkFile(file) {
    const ext = file.name.split('.').pop().toLowerCase();
    if (!ALLOWED_EXTENSIONS.includes(ext)) {
        return { valid: false, ext, reason: 'type' };
    }
    if (file.size > MAX_FILE_SIZE) {
        return { valid: false, ext, reason: 'size', sizeMB: (file.size / (1024 * 1024)).toFixed(1) };
    }
    return { valid: true, ext };
}

function fileTypeErrorMessage(check, file) {
    return check.reason === 'type'
        ? '"' + check.ext + '" is not allowed. Only scanned PDF files are accepted.'
        : '"' + file.name + '" is ' + check.sizeMB + 'MB. Maximum file size is 200MB.';
}

/** Set a widget's icon to the pdf glyph for the given extension. */
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

/** Compute a start→end duration in minutes; null = incomplete inputs, {invalid:true} = end before start. */
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
    const name = (o.office_name || o[o.labelKey] || '').toLowerCase();
    const code = (o.office_code || '').toLowerCase();
    const label = (o.originator_name || '').toLowerCase();
    return name.includes(needle) || code.includes(needle) || label.includes(needle);
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

function emptyOfficeRowHTML(bodyId) {
    const cols = bodyId === 'retrievalBody' ? 4 : 3;
    return '<tr class="reg-empty-row">' +
                '<td colspan="' + cols + '">' +
                    '<div class="reg-empty-state">' +
                        '<i class="fa-solid fa-building-circle-xmark"></i>' +
                        '<span>No offices added yet</span>' +
                    '</div>' +
                '</td>' +
            '</tr>';
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
    seedOfficeRow('distBody', 'totalDistCopies', officeId, officeName, copies);
    const tbody = document.getElementById('distBody');
    const inp = [...tbody.querySelectorAll('input[type="hidden"][name="distOffice[]"]')]
        .find(i => String(i.value) === String(officeId));
    if (inp?.closest('tr')) {
        inp.closest('tr').dataset.fromRetrieval = '1';
    }
    updateTotal('totalDistCopies', 'distBody');
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
    updateTotal('totalDistCopies', 'distBody');
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
            '" min="1" oninput="updateTotal(\'totalRetrievalCopies\', \'retrievalBody\')"></td>' +
            '<td><button type="button" class="btn-remove" onclick="removeOffice(this, \'totalRetrievalCopies\', \'retrievalBody\')">' +
            '<i class="fa-solid fa-xmark"></i></button></td>';
        tbody.appendChild(tr);
    }
    updateTotal('totalRetrievalCopies', 'retrievalBody');
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
    updateTotal('totalRetrievalCopies', 'retrievalBody');
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
        <td>
            <input type="hidden" name="retrievalOffice[]" value="${officeId}">
            <div class="reg-office-name">
                <div class="reg-office-icon"><i class="fa-solid fa-building"></i></div>
                <span class="reg-office-text">${escapeHtml(officeName)}</span>
            </div>
        </td>
        <td>${retrievalStatusSelectHTML(status || 'pending')}</td>
        <td style="text-align: center;">
            <input type="number" name="retrievalCopies[]" value="${copies}" min="1" oninput="updateTotal('${totalId}', '${tbodyId}')">
        </td>
        <td>
            <button type="button" class="btn-remove" onclick="removeOffice(this, '${totalId}', '${tbodyId}')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </td>
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

    const existing = [...tbody.querySelectorAll('input[type="hidden"]')]
        .find(inp => inp.value == officeId);
    if (existing) return;

    const tr = document.createElement("tr");
    tr.className = "reg-office-added";
    tr.draggable = true;
    tr.innerHTML = `
        <td>
            <input type="hidden" name="${officeNameAttr}" value="${officeId}">
            <div class="reg-office-name">
                <div class="reg-office-icon"><i class="fa-solid fa-building"></i></div>
                <span class="reg-office-text">${escapeHtml(officeName)}</span>
            </div>
        </td>
        <td style="text-align: center;">
            <input type="number" name="${copiesNameAttr}" value="${copies}" min="1" oninput="updateTotal('${totalId}', '${tbodyId}')">
        </td>
        <td>
            <button type="button" class="btn-remove" onclick="removeOffice(this, '${totalId}', '${tbodyId}')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </td>
    `;
    tbody.appendChild(tr);
}

/** Returns true if the given office table has no real rows yet (only the empty placeholder). */
function tableIsEmpty(tbodyId) {
    const tbody = document.getElementById(tbodyId);
    if (!tbody) return true;
    return tbody.querySelectorAll('input[type="hidden"]').length === 0;
}

// ══════════════════════════════════════════════
// DOM READY — single consolidated handler
// ══════════════════════════════════════════════
document.addEventListener("DOMContentLoaded", async function () {
    // ── Fetch dropdown data ──
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
        renderDistClusterChips();
        bindDistBodyDrag();

        const versionSelect = document.getElementById("versionType");
        versionTypes.forEach(v => versionSelect.add(new Option(v.version_name, v.version_id)));
        const urlType = new URLSearchParams(location.search).get('type');
        if (versionSelect && urlType === 'revised') {
            const match = [...versionSelect.options].find(o => /revis/i.test(o.text));
            if (match && match.value) {
                versionSelect.value = match.value;
                versionSelect.dataset.lastValid = match.value;
            }
            const modeEl = document.getElementById('registrationMode');
            if (modeEl) modeEl.value = 'revised';
        } else if (versionSelect && (urlType === 'new' || !urlType)) {
            const match = [...versionSelect.options].find(o => /new/i.test(o.text) && !/revis/i.test(o.text));
            if (match && match.value) {
                versionSelect.value = match.value;
                versionSelect.dataset.lastValid = match.value;
            }
            const modeEl = document.getElementById('registrationMode');
            if (modeEl) modeEl.value = 'new';
        }

        const docTypeSelect = document.getElementById("docType");
        docTypes.filter(d => !d.parent_id).forEach(d => {
            docTypeSelect.add(new Option(d.doc_type_name, d.doc_type_id));
        });

        const approvalSelect = document.getElementById("approvalBody");
        if (approvalSelect) approvalBodies.forEach(a => approvalSelect.add(new Option(a.approval_name, a.approval_body_id)));

    } catch (err) {
        console.error("Failed to load data:", err);
    }

    initSelectProtection();
    initFileInputs();
    initKeywordsWidget();

    // ── Wire search/autofill on the initial DCN revision row ──
    const initialRevisionRow = document.querySelector('#revisionTableBody tr');
    if (initialRevisionRow) bindRevisionRowSearch(initialRevisionRow);

    // ── Lock revision field for new registrations ──
    const revField = document.getElementById('masterlistRevisionNo');
    if (revField) {
        revField.addEventListener('input', function () {
            this.dataset.userEdited = 'true';
            scheduleRevNoCheck();
        });
        revField.addEventListener('change', scheduleRevNoCheck);
        revField.addEventListener('blur', scheduleRevNoCheck);
    }

    // ── Document No. live lookup (both modes) ──
    initDocNoLookup(revField);
    initRevNoLookup();
    wireSyllabiMasterlistSync();
    wireApprovalDeadlineSync();
    wireCompareRevisionButton();

    // ── Apply initial version mode (including /register?type=revised or ?type=new) ──
    const versionSelectEl = document.getElementById('versionType');
    if (versionSelectEl?.value) {
        handleVersionChange();
    } else {
        applyRevisionMode();
    }

    // ── Auto-copy DRF title to Masterlist title ──
    const drfTitle = document.getElementById('drfTitle');
    const mlTitle = document.getElementById('masterlistDocTitle');
    if (drfTitle && mlTitle) {
        drfTitle.addEventListener('input', () => { mlTitle.value = drfTitle.value; });
        mlTitle.addEventListener('input', () => { drfTitle.value = mlTitle.value; });
    }

    createSourceUnitWidget({
        key: 'drf',
        widgetId: 'drfSourceUnitWidget',
        inputId: 'drfSourceUnitSearch',
        arrowId: 'drfSourceArrowBtn',
        resultsId: 'drfSourceResults',
        chipsId: 'drfSourceInlineChips',
        summaryId: 'drfSourceSummary',
        allowFreeText: false,
        officeFieldName: 'drfSourceUnit[]',
        nameFieldName: null,
        initial: []
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
        initial: []
    });

    createSourceUnitWidget({
        key: 'masterlist',
        widgetId: 'masterlistSourceWidget',
        inputId: 'masterlistSourceSearch',
        arrowId: 'masterlistSourceArrowBtn',
        resultsId: 'masterlistSourceSuggestions',
        chipsId: 'masterlistSourceInlineChips',
        summaryId: 'masterlistSourceSummary',
            allowFreeText: false,
            officeFieldName: 'masterlistOfficeIds[]',
            nameFieldName: 'masterlistOriginatorNames[]',
        initial: []
    });

    createSourceUnitWidget({
        key: 'masterlistOriginator',
        widgetId: 'masterlistOriginatorWidget',
        inputId: 'masterlistOriginatorSearch',
        arrowId: 'masterlistOriginatorArrowBtn',
        resultsId: 'masterlistOriginatorResults',
        chipsId: 'masterlistOriginatorInlineChips',
        summaryId: 'masterlistOriginatorSummary',
        allowFreeText: true,
        singleSelect: true,
        fieldName: 'masterlistOriginator',
        dataListGetter: () => allOriginators,
        idKey: 'originator_id',
        labelKey: 'originator_name',
        itemLabelPlural: 'originators',
        overlayTitle: 'Originator',
        initial: []
    });
});

// ══════════════════════════════════════════════
// DOCUMENT NO. LOOKUP
// ══════════════════════════════════════════════
function isRevisedDocNoReady(hintEl) {
    const valid = hintEl?.dataset?.valid || '';
    return valid === 'true' || valid === 'renumbered';
}

function getRevisedFromDocNo() {
    const fromInput = document.getElementById('revisedFromDocNo');
    return (fromInput?.value || window.__revisedFromDocNo || '').trim();
}

function initDocNoLookup(revField) {
    const docNoInput = document.getElementById('masterlistDocNo');
    const hintEl = document.getElementById('docNoHint');
    if (!docNoInput) return;

    let docNoTimer = null;

    docNoInput.addEventListener('input', () => {
        clearTimeout(docNoTimer);
        const docNo = docNoInput.value.trim();

        if (!docNo) {
            handleEmptyDocNo(hintEl, revField);
            return;
        }

        // Revised mode: after linking (via DCN or Masterlist), allow keeping or changing
        // the document number (e.g. CSPC-12 → CSPC-F-13) without losing the link.
        const fromNo = getRevisedFromDocNo();
        if (isRevisedMode() && fromNo) {
            window.__revisedFromDocNo = fromNo;
            if (docNo.toLowerCase() !== fromNo.toLowerCase()) {
                if (hintEl) {
                    hintEl.innerHTML = '<span style="color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> Checking new document number...</span>';
                    hintEl.style.color = '';
                    hintEl.dataset.valid = '';
                }
                setSaveEnabled(false);
                docNoTimer = setTimeout(() => runRenumberedDocNoCheck(docNo, fromNo, hintEl), 500);
                return;
            }
        }

        if (hintEl) {
            hintEl.innerHTML = '<span style="color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> Checking document...</span>';
            hintEl.style.color = '';
            hintEl.dataset.valid = '';
        }

        setSaveEnabled(false);

        docNoTimer = setTimeout(() => runDocNoLookup(docNo, hintEl, revField), 500);
    });
}

function handleEmptyDocNo(hintEl, revField) {
    window.__revisedComparePreviousUrl = null;
    window.__revisedComparePreviousRev = null;
    window.__revisedFamilyScans = [];
    refreshCompareRevisionButton();

    const preservedFrom = getRevisedFromDocNo();
    if (preservedFrom) {
        window.__revisedFromDocNo = preservedFrom;
    } else {
        window.__revisedFromDocNo = null;
        clearRevisedApprovalContext();
        const fromInput = document.getElementById('revisedFromDocNo');
        if (fromInput) fromInput.value = '';
        if (typeof window.handleApprovalToggle === 'function') {
            window.handleApprovalToggle(false);
        }
    }

    if (isRevisedMode()) {
        if (hintEl) {
            if (preservedFrom) {
                hintEl.innerHTML = '<span style="color:#94a3b8"><i class="fa-solid fa-circle-info"></i> Enter the document number for this revision (linked from <strong>' +
                    escapeHtml(preservedFrom) + '</strong> — keep the same number or assign a new one)</span>';
            } else {
                hintEl.innerHTML = '<span style="color:#94a3b8"><i class="fa-solid fa-circle-info"></i> Pick a document in Documents for Revision (DCN) or enter its number here. You may keep the same number or assign a new one.</span>';
            }
            hintEl.style.color = '';
            hintEl.dataset.valid = '';
        }
        if (revField) {
            revField.value = '';
            revField.readOnly = false;
            revField.style.background = '';
            revField.style.cursor = '';
        }
        setSaveEnabled(false);
    } else {
        docNoDuplicate = false;
        if (hintEl) {
            hintEl.innerHTML = '';
            hintEl.dataset.valid = '';
        }
        setSaveEnabled(true);
    }
}

// NOTE: kept the exact original markup/coloring rules per-branch below,
// since the hint styling differs subtly (span-wrapped vs colored <i> line) between states.
async function runRenumberedDocNoCheck(docNo, fromNo, hintEl) {
    try {
        const docTypeId = document.getElementById('docType').value;
        const subTypeId = document.getElementById('subType').value;
        const url = '/dcs/register/check-docno?doc_no=' + encodeURIComponent(docNo) +
                    (docTypeId ? '&doc_type_id=' + docTypeId : '') +
                    (subTypeId ? '&sub_type_id=' + subTypeId : '') +
                    '&related_from=' + encodeURIComponent(fromNo);
        const res = await fetch(url);
        const data = await res.json();
        const revField = document.getElementById('masterlistRevisionNo');

        if (data.exists) {
            // Same lineage (e.g. return to original 2024-323 while linked from 2024-323-2):
            // allowed — uniqueness is per (doc_no, revise_no), not doc_no alone.
            if (data.same_family !== false) {
                applyRevisedDocumentContext(data, { hintEl, revField, forceNextRev: true });
                const docNoInput = document.getElementById('masterlistDocNo');
                if (docNoInput) docNoInput.value = docNo;
                window.__revisedFromDocNo = fromNo;
                const fromInput = document.getElementById('revisedFromDocNo');
                if (fromInput) fromInput.value = fromNo;

                if (hintEl) {
                    hintEl.innerHTML = '<i class="fa-solid fa-circle-check"></i> Using existing number <strong>' +
                        escapeHtml(docNo) + '</strong> (linked from <strong>' +
                        escapeHtml(fromNo) + '</strong>). Suggested Rev ' +
                        escapeHtml(String(data.next_rev ?? '')) + '.';
                    hintEl.style.color = '#16a34a';
                    hintEl.dataset.valid = 'renumbered';
                }
                setSaveEnabled(true);
                scheduleRevNoCheck();
                return;
            }

            if (hintEl) {
                hintEl.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> Document number <strong>' +
                    escapeHtml(docNo) + '</strong> is already registered under a different document family. Choose a different number for this revision.';
                hintEl.style.color = '#dc2626';
                hintEl.dataset.valid = 'duplicate';
            }
            setSaveEnabled(false);
            return;
        }

        if (hintEl) {
            hintEl.innerHTML = '<i class="fa-solid fa-circle-check"></i> Changed from <strong>' +
                escapeHtml(fromNo) + '</strong> to <strong>' + escapeHtml(docNo) + '</strong>.' +
                '<br><span style="font-weight:400;font-size:11px;">Previous details kept; original number stays linked.</span>';
            hintEl.style.color = '#16a34a';
            hintEl.dataset.valid = 'renumbered';
        }
        setSaveEnabled(true);
        scheduleRevNoCheck();
    } catch (e) {
        console.error('Renumbered doc-no check failed:', e);
        if (hintEl) {
            hintEl.innerHTML = '<i class="fa-solid fa-circle-info"></i> Document number changed from <strong>' +
                escapeHtml(fromNo) + '</strong>. Save will use the new number for this revision.';
            hintEl.style.color = '#0369a1';
            hintEl.dataset.valid = 'renumbered';
        }
        setSaveEnabled(true);
        scheduleRevNoCheck();
    }
}

async function runDocNoLookup(docNo, hintEl, revField) {
    try {
        const docTypeId = document.getElementById('docType').value;
        const subTypeId = document.getElementById('subType').value;
        const url = '/dcs/register/check-docno?doc_no=' + encodeURIComponent(docNo) +
                    (docTypeId ? '&doc_type_id=' + docTypeId : '') +
                    (subTypeId ? '&sub_type_id=' + subTypeId : '');
        const res = await fetch(url);
        const data = await res.json();

        if (isRevisedMode()) {
            applyRevisedModeLookupResult(data, hintEl, revField);
        } else {
            applyNewModeLookupResult(data, hintEl, revField);
        }
    } catch (e) {
        console.error('DocNo lookup failed:', e);
        if (!isRevisedMode()) setSaveEnabled(true);
    }
}

function applyRevisedDocumentContext(data, options = {}) {
    const { docNo, hintEl, revField } = options;
    const revFieldEl = revField || document.getElementById('masterlistRevisionNo');
    const hint = hintEl !== undefined ? hintEl : document.getElementById('docNoHint');

    if (docNo !== undefined && docNo !== null) {
        const docNoInput = document.getElementById('masterlistDocNo');
        if (docNoInput) docNoInput.value = docNo;
        window.__revisedFromDocNo = docNo;
        const fromInput = document.getElementById('revisedFromDocNo');
        if (fromInput) fromInput.value = docNo;
    }

    const effectiveDocNo = (docNo ?? document.getElementById('masterlistDocNo')?.value ?? '').trim();

    if (revFieldEl) {
        // Fresh doc-no context from DCN/lookup always suggests family next rev.
        if (options.forceNextRev || !revFieldEl.value || revFieldEl.dataset.userEdited !== 'true') {
            revFieldEl.value = data.next_rev;
            revFieldEl.dataset.userEdited = '';
        }
        revFieldEl.readOnly = false;
        revFieldEl.style.background = '';
        revFieldEl.style.cursor = '';
        revFieldEl.removeAttribute('min');
        revFieldEl.setAttribute('title', 'Suggested: Rev ' + data.next_rev + ' (family latest is ' + data.latest_rev + '). Gap fills are allowed only if that Rev is unused in the family.');
        scheduleRevNoCheck();
    }

    const titleField = document.getElementById('masterlistDocTitle');
    if (titleField && data.latest_title) {
        titleField.value = data.latest_title;
        titleField.readOnly = false;
    }

    // Non-date masterlist fields from previous revision
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

    window.__revisedFamilyScans = Array.isArray(data.revision_scans) ? data.revision_scans : [];
    resolveRevisedComparePrevious();

    if (data.latest_distribution_offices && data.latest_distribution_offices.length > 0
        && tableIsEmpty('retrievalBody')) {
        // Previous distribution copies must be retrieved again on this revision → always Pending.
        data.latest_distribution_offices.forEach(o => {
            seedRetrievalOfficeRow('retrievalBody', 'totalRetrievalCopies', o.office_id, o.office_name, o.copies, 'pending');
        });
        updateTotal('totalRetrievalCopies', 'retrievalBody');
    }

    // Offices retrieved on a prior cycle but not on latest distribution still need a Pending row.
    if (data.already_retrieved_offices && data.already_retrieved_offices.length > 0) {
        data.already_retrieved_offices.forEach(o => {
            seedRetrievalOfficeRow(
                'retrievalBody', 'totalRetrievalCopies',
                o.office_id, o.office_name, o.copies || 1, 'pending'
            );
        });
        updateTotal('totalRetrievalCopies', 'retrievalBody');
    }

    // Prefill only applies in revised mode — open Retrieval when offices were seeded.
    if (isRevisedMode() && !tableIsEmpty('retrievalBody')) {
        const retCb = document.querySelector('#dynamicCheckboxes input[name="checklists[]"][value="4"]');
        if (retCb) {
            retCb.checked = true;
            retCb.dataset.lastChecked = 'true';
            if (!retCb.disabled) toggleSection(4, true);
            else {
                const retSection = document.getElementById('section-4');
                if (retSection) retSection.style.display = 'block';
            }
        } else {
            const retSection = document.getElementById('section-4');
            if (retSection) retSection.style.display = 'block';
        }
    }

    applyRevisedApprovalFromPrevious(data);

    if (hint) {
        hint.innerHTML = '<i class="fa-solid fa-circle-check"></i> Document found. Keep <strong>' +
            escapeHtml(effectiveDocNo) + '</strong> or renumber — details prefilled (dates blank).';
        hint.style.color = '#16a34a';
        hint.dataset.valid = 'true';
    }

    document.getElementById('uploadScannedCopy')?.dispatchEvent(new Event('change'));
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
            // Ensure option exists before selecting
            const hasOption = Array.from(bodySelect.options).some(o => String(o.value) === want);
            if (hasOption) {
                bodySelect.value = want;
                bodySelect.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
        if (approvalNo && data.latest_approval_no) {
            approvalNo.value = data.latest_approval_no;
        }
        // Never carry over previous approval date
        if (approvalDate) approvalDate.value = '';
    } else {
        if (bodySelect) bodySelect.selectedIndex = 0;
        if (approvalNo) approvalNo.value = '';
        if (approvalDate) approvalDate.value = '';
    }
}

function clearRevisedApprovalContext() {
    window.__revisedApprovalContext = null;
}

function applyRevisedModeLookupResult(data, hintEl, revField) {
    if (data.exists) {
        setSaveEnabled(true);
        const lookedUpNo = (document.getElementById('masterlistDocNo')?.value || '').trim();
        applyRevisedDocumentContext(data, { docNo: lookedUpNo, hintEl, revField, forceNextRev: true });
    } else {
        setSaveEnabled(false);
        window.__revisedComparePreviousUrl = null;
        window.__revisedComparePreviousRev = null;
        window.__revisedFamilyScans = [];
        refreshCompareRevisionButton();
        window.__revisedFromDocNo = null;
        clearRevisedApprovalContext();
        const fromInput = document.getElementById('revisedFromDocNo');
        if (fromInput) fromInput.value = '';

        // Hide Approval Details until a valid previous document is linked
        const applicableRadio = document.querySelector('input[name="approval_status"][value="applicable"]');
        const notApplicableRadio = document.querySelector('input[name="approval_status"][value="not_applicable"]');
        if (notApplicableRadio) notApplicableRadio.checked = true;
        if (applicableRadio) applicableRadio.checked = false;
        if (typeof window.handleApprovalToggle === 'function') {
            window.handleApprovalToggle(false);
        } else {
            const approval = document.getElementById('section-approval');
            if (approval) approval.style.display = 'none';
        }

        if (revField) {
            revField.value = '';
            revField.readOnly = false;
            revField.style.background = '';
            revField.style.cursor = '';
        }

        if (hintEl) {
            const icon = data.wrong_type ? 'fa-triangle-exclamation' : 'fa-circle-exclamation';
            const color = data.wrong_type ? '#d97706' : '#dc2626';
            hintEl.innerHTML = '<i class="fa-solid ' + icon + '"></i> ' + escapeHtml(data.message || '') +
                '<br><span style="font-weight:400;font-size:11px;">You cannot save until you enter a valid document number.</span>';
            hintEl.style.color = color;
            hintEl.dataset.valid = data.wrong_type ? 'wrong_type' : 'not_found';
        }
    }
}

function applyNewModeLookupResult(data, hintEl, revField) {
    if (data.exists) {
        docNoDuplicate = true;
        setSaveEnabled(false);

        if (hintEl) {
            hintEl.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' +
                'This document number is already registered under <strong>' +
                (escapeHtml(data.existing_type_name || 'this document type')) +
                '</strong>. Use <strong>Revised Registration</strong> to create a new revision.' +
                '<br><span style="font-weight:400;font-size:11px;">You cannot save until you enter a unique document number.</span>';
            hintEl.style.color = '#dc2626';
            hintEl.dataset.valid = 'duplicate';
        }
    } else if (data.wrong_type) {
        docNoDuplicate = false;
        setSaveEnabled(true);
        if (hintEl) {
            hintEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ' + escapeHtml(data.message || '') +
                '<br><span style="font-weight:400;font-size:11px;">This number is registered under a different document type. You may continue.</span>';
            hintEl.style.color = '#d97706';
            hintEl.dataset.valid = 'different_type';
        }
    } else {
        docNoDuplicate = false;
        setSaveEnabled(true);
        if (hintEl) {
            hintEl.innerHTML = '<i class="fa-solid fa-circle-check"></i> Document number is available.';
            hintEl.style.color = '#16a34a';
            hintEl.dataset.valid = 'available';
        }
    }
}

// ══════════════════════════════════════════════
// REVISION MODE HELPERS
// ══════════════════════════════════════════════
function isRevisedMode() {
    const hidden = document.getElementById('registrationMode');
    return hidden && hidden.value === 'revised';
}

/** Checklist 4 = Document Retrieval — only for revised documents. */
function filterChecklistsForMode(checklists) {
    const list = Array.isArray(checklists) ? checklists : [];
    if (isRevisedMode()) return list;
    return list.filter(c => parseInt(c.checklist_id, 10) !== 4);
}

function clearRetrievalSection() {
    const section = document.getElementById('section-4');
    if (section) section.style.display = 'none';

    ['retrievalFormDate', 'retrievalFormTime', 'retrievalDate', 'retrievalTime',
        'retrievalTimeSpentDisplay', 'retrievalTimeSpent', 'retrievalRemarks', 'scannedRet'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        if (el.type === 'file') {
            el.value = '';
            return;
        }
        el.value = '';
    });

    const body = document.getElementById('retrievalBody');
    if (body) body.innerHTML = emptyOfficeRowHTML('retrievalBody');
    const hidden = document.getElementById('retrievalRetrievedHidden');
    if (hidden) hidden.innerHTML = '';
    const total = document.getElementById('totalRetrievalCopies');
    if (total) total.textContent = '0';
}

function syncRetrievalForMode() {
    if (!isRevisedMode()) {
        clearRetrievalSection();
        // Drop retrieval checkbox if it was rendered for a prior version selection.
        document.querySelectorAll('#dynamicCheckboxes input[name="checklists[]"][value="4"]').forEach(cb => {
            cb.checked = false;
            cb.dataset.lastChecked = 'false';
            const label = cb.closest('label');
            if (label) label.remove();
        });
        return;
    }

    // Re-include retrieval checklist when switching into revised mode.
    if (window.__lastVersionChecklists && window.__lastVersionChecklists.length) {
        const container = document.getElementById('dynamicCheckboxes');
        const hasRetrieval = container?.querySelector('input[name="checklists[]"][value="4"]');
        if (!hasRetrieval) {
            const wasEnabled = !!container?.querySelector('input[type="checkbox"]:not([disabled])');
            const checkedIds = [...(container?.querySelectorAll('input[name="checklists[]"]:checked') || [])]
                .map(cb => parseInt(cb.value, 10));
            renderChecklists(window.__lastVersionChecklists, !wasEnabled);
            container?.querySelectorAll('input[name="checklists[]"]').forEach(cb => {
                const id = parseInt(cb.value, 10);
                const on = checkedIds.includes(id);
                cb.checked = on;
                cb.dataset.lastChecked = on ? 'true' : 'false';
                if (wasEnabled) cb.disabled = false;
                toggleSection(id, on);
            });
        }
    }
}

function applyRevisionMode() {
    const revField = document.getElementById('masterlistRevisionNo');
    const hintEl = document.getElementById('docNoHint');

    if (isRevisedMode()) {
        if (revField) {
            revField.value = '';
            revField.dataset.userEdited = '';
            revField.readOnly = false;
            revField.style.background = '';
            revField.style.cursor = '';
            revField.removeAttribute('min');
            revField.removeAttribute('title');
        }
        if (hintEl) {
            hintEl.innerHTML = '<span style="color:#94a3b8"><i class="fa-solid fa-circle-info"></i> Required: pick the document being revised under Documents for Revision (DCN). Then enter/confirm the document number for this new revision.</span>';
            hintEl.style.color = '';
            hintEl.dataset.valid = '';
        }
        setSaveEnabled(false);
        const docNoInput = document.getElementById(window.__isSyllabiMode ? 'syllabiDocNo' : 'masterlistDocNo')
            || document.getElementById('masterlistDocNo');
        const docNo = docNoInput?.value.trim();
        if (docNo) {
            runDocNoLookup(docNo, hintEl, revField);
        }
    } else {
        docNoDuplicate = false;
        window.__revisedFromDocNo = null;
        clearRevisedApprovalContext();
        const fromInput = document.getElementById('revisedFromDocNo');
        if (fromInput) fromInput.value = '';
        if (revField) {
            revField.value = 0;
            revField.readOnly = false;                   // ← editable
            revField.style.background = '';               // ← no locked look
            revField.style.cursor = '';                   // ← normal cursor
            revField.removeAttribute('min');
            revField.setAttribute('title', 'Suggested: 0 for new documents');
        }
        if (hintEl) {
            hintEl.innerHTML = '';
            hintEl.dataset.valid = '';
        }
        setSaveEnabled(true);
    }

    syncRetrievalForMode();
}

function updateRegistrationMode() {
    const sel = document.getElementById('versionType');
    const hidden = document.getElementById('registrationMode');
    if (!sel || !hidden) return;

    const text = sel.options[sel.selectedIndex]?.text?.toLowerCase() || '';
    hidden.value = (text.includes('revised') || text.includes('revision') || text.includes('revise')) ? 'revised' : 'new';
    applyRevisionMode();
}

function setSaveEnabled(enabled) {
    // Don't re-enable if duplicate is detected
    if (enabled && (docNoDuplicate || revNoDuplicate)) return;

    const saveBtn = document.getElementById('btnSaveDocument');
    if (!saveBtn) return;

    if (enabled) {
        saveBtn.disabled = false;
        saveBtn.removeAttribute('disabled');
        saveBtn.style.opacity = '';
        saveBtn.style.cursor = '';
        saveBtn.style.pointerEvents = '';
        saveBtn.style.filter = '';
    } else {
        saveBtn.disabled = true;
        saveBtn.setAttribute('disabled', 'disabled');
        saveBtn.style.opacity = '0.4';
        saveBtn.style.cursor = 'not-allowed';
        saveBtn.style.pointerEvents = 'none';
        saveBtn.style.filter = 'grayscale(1)';
    }
}

// ══════════════════════════════════════════════
// REVISION NO. LIVE DUPLICATE CHECK
// ══════════════════════════════════════════════
let revNoTimer = null;

function getActiveDocNoForRevCheck() {
    if (window.__isSyllabiMode) {
        return (document.getElementById('syllabiDocNo')?.value || document.getElementById('masterlistDocNo')?.value || '').trim();
    }
    return (document.getElementById('masterlistDocNo')?.value || '').trim();
}

function clearRevNoHint() {
    revNoDuplicate = false;
    const hint = document.getElementById('revNoHint');
    const revField = document.getElementById('masterlistRevisionNo');
    if (hint) {
        hint.innerHTML = '';
        hint.style.color = '';
        hint.dataset.valid = '';
    }
    if (revField) {
        revField.style.borderColor = '';
        revField.classList.remove('reg-input-invalid');
    }
}

function initRevNoLookup() {
    // Re-check when type/subtype or doc no changes.
    document.getElementById('docType')?.addEventListener('change', scheduleRevNoCheck);
    document.getElementById('subType')?.addEventListener('change', scheduleRevNoCheck);
    document.getElementById('syllabiDocNo')?.addEventListener('input', scheduleRevNoCheck);
}

function scheduleRevNoCheck() {
    clearTimeout(revNoTimer);
    const hint = document.getElementById('revNoHint');
    const docNo = getActiveDocNoForRevCheck();
    const revField = document.getElementById('masterlistRevisionNo');
    if (!revField) return;

    if (!docNo) {
        clearRevNoHint();
        return;
    }

    if (hint) {
        hint.innerHTML = '<span style="color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> Checking revision…</span>';
        hint.style.color = '';
        hint.dataset.valid = '';
    }

    revNoTimer = setTimeout(() => runRevNoCheck(), 400);
}

async function runRevNoCheck() {
    const revField = document.getElementById('masterlistRevisionNo');
    const hint = document.getElementById('revNoHint');
    const docNo = getActiveDocNoForRevCheck();
    if (!revField || !docNo) {
        clearRevNoHint();
        return;
    }

    const reviseNo = revField.value === '' ? '0' : revField.value;
    const docTypeId = document.getElementById('docType')?.value || '';
    const subTypeId = document.getElementById('subType')?.value || '';

    try {
        const url = '/dcs/register/check-revno?doc_no=' + encodeURIComponent(docNo) +
            '&revise_no=' + encodeURIComponent(reviseNo) +
            (docTypeId ? '&doc_type_id=' + encodeURIComponent(docTypeId) : '') +
            (subTypeId ? '&sub_type_id=' + encodeURIComponent(subTypeId) : '');
        const res = await fetch(url);
        const data = await res.json();

        if (data.needs_doc_no || data.needs_doc_type) {
            clearRevNoHint();
            return;
        }

        if (data.taken) {
            revNoDuplicate = true;
            if (hint) {
                hint.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' +
                    escapeHtml(data.message || 'This revision already exists.');
                hint.style.color = '#dc2626';
                hint.dataset.valid = 'duplicate';
            }
            revField.style.borderColor = '#dc2626';
            revField.classList.add('reg-input-invalid');
            setSaveEnabled(false);
            resolveRevisedComparePrevious();
            return;
        }

        revNoDuplicate = false;
        if (hint) {
            if (Array.isArray(data.taken_revs) && data.taken_revs.length > 0) {
                hint.innerHTML = '<i class="fa-solid fa-circle-check"></i> ' + escapeHtml(data.message || 'Revision available.');
                hint.style.color = '#16a34a';
                hint.dataset.valid = 'true';
            } else {
                hint.innerHTML = '';
                hint.style.color = '';
                hint.dataset.valid = 'true';
            }
        }
        revField.style.borderColor = '';
        revField.classList.remove('reg-input-invalid');
        resolveRevisedComparePrevious();
        // Re-enable save only if doc-no side is also OK
        if (!docNoDuplicate) {
            const docHint = document.getElementById('docNoHint');
            const docOk = !isRevisedMode()
                || isRevisedDocNoReady(docHint)
                || (docHint?.dataset?.valid === 'true');
            if (!isRevisedMode() || docOk) {
                setSaveEnabled(true);
            }
        }
    } catch (e) {
        console.error('RevNo check failed:', e);
        clearRevNoHint();
        resolveRevisedComparePrevious();
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

    const docTypeId = document.getElementById('docType')?.value;
    const subTypeId = document.getElementById('subType')?.value;
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
        } catch (e) {
            console.error('Revision doc search failed:', e);
        }
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
    // If title was already converted to wrap display, refresh the visible text.
    const titleText = row.querySelector('.reg-rev-title-text');
    if (titleText) {
        const title = String(doc.doc_title || '').trim();
        titleText.textContent = title || '—';
        titleText.classList.toggle('is-wrap', title.length > 28);
    }

    lockRevisionRowFields(row);
    lockRevisionScannedCopyCell(row, doc.scanned_copy_url);
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

    // API returns merged chain: current doc no revisions first, then prior renumbered families.
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

    if (isRevisedMode() && doc.doc_no) {
        await bridgeDcnPickToMasterlist(doc.doc_no);
    }
};

async function bridgeDcnPickToMasterlist(docNo, options = {}) {
    const hintEl = document.getElementById('docNoHint');
    const revField = document.getElementById('masterlistRevisionNo');
    try {
        const docTypeId = document.getElementById('docType').value;
        const subTypeId = document.getElementById('subType').value;
        const url = '/dcs/register/check-docno?doc_no=' + encodeURIComponent(docNo) +
                    (docTypeId ? '&doc_type_id=' + docTypeId : '') +
                    (subTypeId ? '&sub_type_id=' + subTypeId : '');
        const res = await fetch(url);
        const data = await res.json();
        if (data.exists) {
            setSaveEnabled(true);
            applyRevisedDocumentContext(data, { docNo, hintEl, revField, forceNextRev: true });
        }
    } catch (e) {
        console.error('DCN pick check-docno failed:', e);
    }
}

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

/** Locks auto-filled revision fields after a document is picked. */
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
        cell.innerHTML = `
            <button type="button" class="${linkClass}" onclick="window.open('${scannedCopyUrl}', '_blank')" title="${label}">
                <i class="${iconClass}"></i>
            </button>
        `;
    } else {
        cell.style.textAlign = 'center';
        cell.innerHTML = `
            <div class="reg-file-error" style="margin:0;">
                <i class="fa-solid fa-circle-exclamation"></i> No scanned copy on file
            </div>
        `;
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

/** Assigns a uid to a revision row (if it doesn't have one yet) and wires both its search fields. */
function bindRevisionRowSearch(tr) {
    if (!tr.dataset.uid) tr.dataset.uid = ++revisionRowUidCounter;
    const uid = tr.dataset.uid;
    bindRevisionSearchInput(tr.querySelector('input[name="documentNo[]"]'), uid, 'no');
    bindRevisionSearchInput(tr.querySelector('input[name="documentTitle[]"]'), uid, 'title');
    if (tr.dataset.linked === 'true') lockRevisionRowFields(tr);
}

/** Clean up both body-attached dropdowns for a row before it's removed. */
function removeRevisionRowDropdowns(tr) {
    if (!tr.dataset.uid) return;
    removeRevSearchDropdown(tr.dataset.uid + '_title');
    removeRevSearchDropdown(tr.dataset.uid + '_no');
}

window.removeRevisionRow = function (btn) {
    const tr = btn.closest('tr');
    if (tr) {
        removeRevisionRowDropdowns(tr);
        tr.remove();
    }
};

// Close any open revision-search dropdown on outside click
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
// Used identically by DRF and Masterlist — one implementation, no duplication.
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

    // Writes the comma-joined selected labels back into the input, with a
    // trailing ", " (multi-select) so typing can continue for the next entry.
    // Input height/box size is untouched — text just scrolls inside it.
    function syncInputText() {
        const inputEl = document.getElementById(opts.inputId);
        if (!inputEl) return;
        if (selected.length === 0) {
            inputEl.value = '';
            return;
        }
        const joined = selected.map(i => i.label).join(', ');
        inputEl.value = opts.singleSelect ? joined : joined + ', ';

        const len = inputEl.value.length;
        inputEl.setSelectionRange(len, len);
    }

    function render() {
        const widget = document.getElementById(opts.widgetId);
        widget.querySelectorAll('input[data-source-hidden]').forEach(el => el.remove());
        selected.forEach(item => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.dataset.sourceHidden = 'true';
            input.name = opts.fieldName || (item.type === 'office' ? opts.officeFieldName : opts.nameFieldName);
            input.value = opts.fieldName ? item.label : (item.type === 'office' ? item.id : item.label);
            widget.appendChild(input);
        });

        // This chips panel (toggled by the arrow button) IS the "view all" —
        // no separate summary element needed.
        const chipsEl = document.getElementById(opts.chipsId);
        if (chipsEl) {
            chipsEl.innerHTML = selected.length === 0
            ? '<div class="reg-reldocs-empty">Nothing selected yet</div>'
            : selected.map(item => `
                <div class="reg-inline-chip">
                    <span>${escapeHtml(item.label)}</span>
                    <button type="button" onclick="event.stopPropagation(); window.__sourceWidgets['${opts.key}'].removeItem('${escapeHtml(item.type)}','${escapeHtml(item.id)}')"><i class="fa-solid fa-xmark"></i></button>
                </div>
            `).join('');
        }

        if (window.__sourceOverlayConfigs[opts.key] && document.getElementById('universalSourceOverlay')) {
            refreshSourceOverlay(window.__sourceOverlayConfigs[opts.key]);
        }
    }

    // Everything after the last comma is the "in-progress" search segment;
    // everything before it is already-committed selections shown as text.
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
                ? `<div class="reg-reldocs-noresult">No matching ${itemLabelPlural} found — press Enter to add "${q.replace(/"/g, '&quot;')}"</div>`
                : `<div class="reg-reldocs-noresult">No matching ${itemLabelPlural} found</div>`;
            dropdown.style.display = 'block';
            return;
        }
        dropdown.innerHTML = filtered.map(o => {
            const extra = o.office_code ? ' (' + o.office_code + ')' : '';
            return `<div onmousedown="window.__sourceWidgets['${opts.key}'].pick(${o[idKey]})">${o[labelKey]}${extra}</div>`;
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
            if (office) selected.push({ type: 'office', id: office[idKey], label: office[labelKey] });
            else { idCounter++; selected.push({ type: 'name', id: 'n' + idCounter, label: part }); }
        });
        render();
        syncInputText();
        maybeSyncSourceUnits();
    }

    const inputEl = document.getElementById(opts.inputId);
    const arrowEl = document.getElementById(opts.arrowId);
    inputEl.addEventListener('input', function () { closePanel(); handleSearch(this); });
    inputEl.addEventListener('keydown', function (e) { handleKeydown(e, this); });

    function jumpCaretToEnd() {
        setTimeout(() => {
            const len = inputEl.value.length;
            inputEl.setSelectionRange(len, len);
        }, 0);
    }
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
// UNIVERSAL SOURCE UNIT OVERLAY (fixed, never clipped)
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

function handleSourceOverlayEsc(e) {
    if (e.key === 'Escape') closeSourceOverlay();
}

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
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
                </svg>
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
                <button type="button" onclick="removeSourceOverlayItem('${escapeHtml(config.key)}', '${escapeHtml(item.id)}')"><i class="fa-solid fa-xmark"></i></button>
            </div>
        `).join('');

    if (config.onChange) config.onChange();
}

function handleSourceOverlaySearch(config, input) {
    const dropdown = document.getElementById('universalSourceOverlaySuggestions');
    const q = input.value.trim();
    if (q.length < 1) { dropdown.style.display = 'none'; return; }

    const selectedIds = config.getSelected()
        .map(i => i.id.split(':').pop());
    const filtered = filterItems(config.getList(), config.labelKey, q)
        .filter(o => !selectedIds.includes(String(o[config.idKey])));

    if (filtered.length === 0) {
        dropdown.innerHTML = `<div class="drf-overlay-noresult">No matching ${config.itemLabelPlural} found</div>`;
        dropdown.style.display = 'block';
        return;
    }

    dropdown.innerHTML = filtered.map(o =>
        `<div onmousedown="pickSourceOverlayOffice('${config.key}', ${o[config.idKey]})">${o[config.labelKey]}</div>`
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

document.addEventListener('DOMContentLoaded', renderRelatedDocsChips);

window.handleRelatedDocFocus = function () {
    closeRelatedDocsSelectedPanel();
};

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
                .map(d => `<div onmousedown="pickRelatedDoc(${Number(d.masterlist_id)})">${escapeHtml(d.label || d.doc_title || '')}</div>`)
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
            <span class="reg-reldocs-chip-title">${escapeHtml(d.doc_title || '')}</span>
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
// (guards against selects being changed by anything other than a real user gesture)
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

        el.addEventListener("pointerdown", () => { userTouched[key] = true; });
        el.addEventListener("keydown", () => { userTouched[key] = true; });

        el.addEventListener("change", function (event) {
            if (!userTouched[key] && !event.isTrusted) {
                this.value = this.dataset.lastValid || "";
                return;
            }
            userTouched[key] = false;
            this.dataset.lastValid = this.value;
            handler();
        });

        el.addEventListener("input", function () {
            if (!userTouched[key]) {
                this.value = this.dataset.lastValid || "";
            }
        });
    });
}

// ══════════════════════════════════════════════
// FILE INPUTS
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

        input.addEventListener('change', function () {
            processUploadAreaFile(this, container, icon, label, originalText);
        });

        container.addEventListener('dragover', function (e) {
            e.preventDefault();
            container.classList.add('reg-upload-drag');
        });

        container.addEventListener('dragleave', function () {
            container.classList.remove('reg-upload-drag');
        });

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

        input.addEventListener('change', function () {
            processUploadCellFile(this, cell);
        });
    });

    document.querySelectorAll('#revisionTableBody input[type="file"]').forEach(input => {
        if (input.dataset.bound) return;
        input.setAttribute('accept', '.pdf');
        input.dataset.bound = "true";
        input.addEventListener('change', function () {
            validateTableFile(this);
        });
    });
}

function processUploadAreaFile(input, container, icon, label, originalText) {
    container.classList.remove('reg-upload-success', 'reg-upload-error', 'reg-upload-drag');
    removeExistingError(container);
    removeUploadFieldActions(container);

    if (!input.files || !input.files[0]) {
        resetUploadArea(container, icon, label, originalText);
        return;
    }

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
    const map = {
        drfNo: 'drfNo',
        drfDate: 'drfDate',
        drfTitle: 'drfTitle',
    };
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
    const host = container?.closest('.reg-field') || container?.closest('td') || container?.parentElement;
    if (!host) return;
    host.querySelectorAll('.reg-upload-preview-actions').forEach(el => {
        if (el.dataset.blobUrl) {
            URL.revokeObjectURL(el.dataset.blobUrl);
        }
        el.remove();
    });
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
        if (input.id === 'uploadScannedCopy') {
            input.dispatchEvent(new Event('change'));
        }
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

function openCompareRevisionModal() {
    resolveRevisedComparePrevious();
    const upload = document.getElementById('uploadScannedCopy');
    const prevUrl = window.__revisedComparePreviousUrl;
    const prevRev = window.__revisedComparePreviousRev;
    const overlay = document.getElementById('compareRevisionModal');
    const prevLabel = document.getElementById('compareRevisionPrevLabel');
    const newLabel = document.getElementById('compareRevisionNewLabel');

    if (!upload?.files?.[0]) {
        alert('Upload a new scanned copy before comparing revisions.');
        return;
    }
    if (!prevUrl) {
        const nextRev = document.getElementById('masterlistRevisionNo')?.value?.trim() || '?';
        alert('No scanned copy found for the previous revision before Rev ' + nextRev + '.');
        return;
    }
    if (!overlay) return;

    if (window.__compareRevisionNewBlobUrl) {
        URL.revokeObjectURL(window.__compareRevisionNewBlobUrl);
    }
    window.__compareRevisionNewBlobUrl = URL.createObjectURL(upload.files[0]);

    if (prevLabel) prevLabel.textContent = 'Previous revision' + (prevRev ? ' (Rev ' + prevRev + ')' : '');
    if (newLabel) {
        const nextRev = document.getElementById('masterlistRevisionNo')?.value?.trim();
        newLabel.textContent = 'New upload' + (nextRev ? ' (Rev ' + nextRev + ')' : '');
    }

    overlay.classList.add('is-open');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';

    if (typeof window.runRegisterPdfCompare === 'function') {
        window.runRegisterPdfCompare(prevUrl, window.__compareRevisionNewBlobUrl, {
            rightFile: upload.files[0],
        }).catch(() => {});
    }
}

function closeCompareRevisionModal() {
    const overlay = document.getElementById('compareRevisionModal');
    const root = document.getElementById('registerPdfCompare');
    if (!overlay) return;

    overlay.classList.remove('is-open');
    overlay.setAttribute('aria-hidden', 'true');
    // Keep rendered compare DOM when possible; only clear volatile blob URL.
    // Re-open restores from local/session cache using file hash (not blob URL).
    if (window.__compareRevisionNewBlobUrl) {
        URL.revokeObjectURL(window.__compareRevisionNewBlobUrl);
        window.__compareRevisionNewBlobUrl = null;
    }
    document.body.style.overflow = '';
}

function showUploadPreview(container, file) {
    const input = container?.querySelector('input[type="file"]');
    if (!input) return;
    const icon = container.querySelector('i');
    const label = container.querySelector('span');
    const originalText = label?.dataset.originalText || 'Choose scanned PDF';
    attachUploadFieldActions(container, input, file, icon, label, originalText);
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
    container.style.borderColor = '';
    container.style.background = '';
    container.style.borderStyle = '';
    container.style.animation = '';

    clearFileIcon(icon, label, originalText);

    removeUploadFieldActions(container);
    removeExistingError(container);
}

function removeExistingError(container) {
    const old = container.querySelector('.reg-file-error');
    if (old) old.remove();
}

function resetUploadCell(cell, icon, label, originalText) {
    cell.classList.remove('reg-upload-cell-success', 'reg-upload-cell-error');
    cell.style.borderColor = '';
    cell.style.background = '';
    clearFileIcon(icon, label, originalText);
    removeExistingError(cell);
    removeExistingPreview(cell);
}

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
        input.value = '';
        return;
    }

    cell.classList.add('reg-upload-cell-success');
    cell.style.borderColor = 'var(--reg-success-border)';
    cell.style.background = 'var(--reg-success-bg)';
    setFileIcon(icon, check.ext);
    label.textContent = file.name;
    label.style.color = 'var(--reg-success)';
    label.style.fontWeight = '600';
    showUploadPreview(cell, file);
}

function validateTableFile(input) {
    const td = input.closest('td');
    removeExistingError(td);
    removeExistingPreview(td);

    if (!input.files || !input.files[0]) return;

    const file = input.files[0];
    const check = checkFile(file);

    if (!check.valid) {
        const errDiv = document.createElement('div');
        errDiv.className = 'reg-file-error';
        errDiv.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' +
            (check.reason === 'type' ? 'Only scanned PDF files are accepted.' : 'Max 10MB');
        td.appendChild(errDiv);
        input.value = '';
        return;
    }

    showUploadPreview(td, file);
}

// ══════════════════════════════════════════════
// VERSION CHANGE
// ══════════════════════════════════════════════
async function handleVersionChange() {
    const versionSelect = document.getElementById("versionType");
    const versionId = versionSelect.value;
    const docTypeSelect = document.getElementById("docType");
    const subTypeSelect = document.getElementById("subType");

    ["section-1", "section-2", "section-3", "section-4", "section-5", "section-approval", "section-syllabi", "formActions"].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = "none";
    });

    docTypeSelect.value = "";
    const hasVersion = String(versionId ?? "").trim() !== "";
    docTypeSelect.disabled = !hasVersion;
    if (hasVersion) {
        docTypeSelect.removeAttribute("disabled");
    } else {
        docTypeSelect.setAttribute("disabled", "disabled");
    }
    subTypeSelect.innerHTML = '<option value="" selected disabled>Select sub-type</option>';
    subTypeSelect.disabled = true;
    subTypeSelect.setAttribute("disabled", "disabled");
    disableApproval();

    // Set new/revised mode before rendering so Document Retrieval (checklist 4)
    // is only included when registering a revision.
    updateRegistrationMode();

    if (!versionId) {
        renderChecklists([
            { checklist_id: 1, checklist_name: "Document Request Form" },
            { checklist_id: 2, checklist_name: "Document Change Notice" },
            { checklist_id: 3, checklist_name: "Masterlist Registration" },
            { checklist_id: 4, checklist_name: "Document Retrieval" },
            { checklist_id: 5, checklist_name: "Document Distribution" },
        ], true);
        return;
    }

    try {
        const byVersion = (window.__registerCatalog && window.__registerCatalog.checklistsByVersion) || {};
        const checklists = byVersion[String(versionId)] || [];
        renderChecklists(checklists, true);
    } catch (err) {
        console.error("Failed to load checklists:", err);
    }
}

// ══════════════════════════════════════════════
// DOC TYPE CHANGE
// ══════════════════════════════════════════════
function resetTextLikeInputs(el) {
    el.querySelectorAll('input[type="text"], input[type="number"], input[type="date"], input[type="time"], textarea').forEach(input => {
        input.value = '';
    });
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

function handleDocTypeChange() {
    window.__isSyllabiMode = false;
    window.__syllabiModeLabel = 'Syllabi';
    window.__lastSubTypeId = null;
    syllabiTitleManuallyEdited = false;
    docNoDuplicate = false;
    clearRevNoHint();
    setSaveEnabled(true);
    const docTypeSelect = document.getElementById("docType");
    const docTypeId = parseInt(docTypeSelect.value);
    const subTypeSelect = document.getElementById("subType");
    const syllabiSection = document.getElementById("section-syllabi");

    subTypeSelect.innerHTML = '<option value="" selected disabled>Select sub-type</option>';
    subTypeSelect.disabled = true;

    if (syllabiSection) syllabiSection.style.display = "none";

    const hintEl = document.getElementById('docNoHint');
    if (hintEl) {
        hintEl.innerHTML = '';
        hintEl.style.color = '';
        hintEl.dataset.valid = '';
    }

    ["section-1", "section-2", "section-3", "section-4", "section-5", "section-approval", "formActions"].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.style.display = "none";
            resetTextLikeInputs(el);
            resetFileWidgetsIn(el);
        }
    });

    ['masterlistTimeSpentDisplay', 'retrievalTimeSpentDisplay', 'distributionTimeSpentDisplay'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.value = '--';
            el.style.color = '';
        }
    });
    ['masterlistTimeSpent', 'retrievalTimeSpent', 'distributionTimeSpent'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });

    ['drf', 'dcn', 'masterlist', 'masterlistOriginator'].forEach(key => {
        if (window.__sourceWidgets[key]) window.__sourceWidgets[key].reset();
    });
    ['drfSourceUnitSearch', 'dcnSourceUnitSearch', 'masterlistSourceSearch', 'masterlistOriginatorSearch'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });

    relatedDocsSelected = [];
    renderRelatedDocsChips();

    ['retrievalBody', 'distBody'].forEach(tbodyId => {
        const tbody = document.getElementById(tbodyId);
        if (tbody) tbody.innerHTML = emptyOfficeRowHTML(tbodyId);
    });
    ['totalRetrievalCopies', 'totalDistCopies'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.textContent = '0';
    });

    const revisionBody = document.getElementById('revisionTableBody');
    if (revisionBody) {
        revisionBody.querySelectorAll('tr[data-uid]').forEach(tr => removeRevisionRowDropdowns(tr));
        revisionBody.innerHTML =
            '<tr>' + revisionRowCellsHTML() + '</tr>';
        const newRow = revisionBody.querySelector('tr');
        bindTableFileInput(newRow.querySelector('input[type="file"]'));
        bindRevisionRowSearch(newRow);
    }

    resetSyllabiSection();
    resetMasterlistNoOfPagesField();
    setSyllabiStep(1);

    disableApproval();
    clearValidation();

    if (!docTypeId) {
        lockChecklist();
        return;
    }

    const children = allDocTypes.filter(d => String(d.parent_id) === String(docTypeId));

    if (children.length > 0) {
        children.forEach(c => subTypeSelect.add(new Option(c.doc_type_name, c.doc_type_id)));
        subTypeSelect.disabled = false;
        subTypeSelect.removeAttribute("disabled");
        lockChecklist();
    } else {
        unlockChecklist();
    }
}

function bindTableFileInput(fileInput) {
    if (!fileInput) return;
    fileInput.dataset.bound = "true";
    fileInput.addEventListener('change', function () { validateTableFile(this); });
}

/** Clears every Syllabi/TOS-Rubrics field and table row. Shared by handleDocTypeChange()
 *  (doc type switch) and validateChecklistState() (sub-type switch, e.g. Syllabi → TOS/Rubrics),
 *  since either change can leave stale input behind otherwise. */
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

// ══════════════════════════════════════════════
// SUB-TYPE CHANGE
// ══════════════════════════════════════════════
function validateChecklistState() {
    const subTypeSelect = document.getElementById("subType");
    const subTypeId = subTypeSelect.value;
    const subTypeData = allDocTypes.find(d => d.doc_type_id == subTypeId);

    const syllabiSection = document.getElementById("section-syllabi");
    if (syllabiSection) syllabiSection.style.display = "none";

    if (!subTypeId) {
        window.__isSyllabiMode = false;
        window.__syllabiModeLabel = 'Syllabi';
        lockChecklist();
        return;
    }

    const isSyllabiLike = isSyllabiLikeSubType(subTypeId);

    // Whenever the sub-type actually changes (e.g. Syllabi -> TOS/Rubrics, or either -> a
    // non-syllabi sub-type), clear stale syllabi inputs so they don't carry over.
    if (subTypeId !== window.__lastSubTypeId) {
        resetSyllabiSection();
    }
    window.__lastSubTypeId = subTypeId;

    window.__isSyllabiMode = isSyllabiLike;
    window.__syllabiModeLabel = subTypeData ? subTypeData.doc_type_name : 'Syllabi';

    unlockChecklist();

    if (!isSyllabiLike) {
        resetMasterlistNoOfPagesField();
        return;
    }

    if (!syllabiSection) return;

    applySyllabiSectionLabel();
    syllabiSection.style.display = "block";
    setSyllabiStep(1);
    syncSyllabiToMasterlistFields();
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
                input.addEventListener('change', function () {
                    processUploadCellFile(this, cell);
                });
            }
        });
    }, 50);
}

/** Updates the visible "Syllabi" card header/placeholder to reflect whichever
 *  syllabi-like sub-type (Syllabi or TOS/Rubrics) is currently selected. */
function applySyllabiSectionLabel() {
    const label = window.__syllabiModeLabel || 'Syllabi';
    const header = document.querySelector('#section-syllabi .reg-card-header span');
    if (header) header.textContent = label;

    const availHeader = document.getElementById('syllabiAvailabilityHeader');
    if (availHeader) {
        availHeader.textContent = /tos|rubric/i.test(label) ? (label + ' Availability') : 'Syllabi Availability';
    }

    document.querySelectorAll('#syllabiTableBody textarea[name="syllabiCourseName[]"], #syllabiTableBody input[name="syllabiCourseName[]"]').forEach(inp => {
        inp.placeholder = /tos/i.test(label) ? 'Enter course/exam name' : 'Enter course name';
    });
}

// ══════════════════════════════════════════════
// CHECKLIST
// ══════════════════════════════════════════════
function renderChecklists(checklists, disabled) {
    const container = document.getElementById("dynamicCheckboxes");
    container.innerHTML = "";

    window.__lastVersionChecklists = Array.isArray(checklists) ? checklists.slice() : [];
    const visible = filterChecklistsForMode(window.__lastVersionChecklists);

    visible.forEach(c => {
        const label = document.createElement("label");
        label.className = "reg-check-item";

        const cb = document.createElement("input");
        cb.type = "checkbox";
        cb.name = "checklists[]";
        cb.value = c.checklist_id;
        cb.autocomplete = "off";
        if (disabled) cb.disabled = true;

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
            if (!userTouched) {
                this.checked = (this.dataset.lastChecked === "true");
                return;
            }
            userTouched = false;
            this.dataset.lastChecked = this.checked ? "true" : "false";
            toggleSection(parseInt(this.value), this.checked);
        });

        container.appendChild(label);
    });

    if (!isRevisedMode()) {
        clearRetrievalSection();
    }
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

    const syllabiSection = document.getElementById("section-syllabi");
    if (syllabiSection) syllabiSection.style.display = "none";
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

    const saveBtn = document.getElementById("btnSaveDocument");
    if (saveBtn) saveBtn.style.display = ""; 
}

// ══════════════════════════════════════════════
// TOGGLE SECTIONS
// ══════════════════════════════════════════════
window.toggleSection = function (checklistId, show) {
    const sectionMap = { 1: "section-1", 2: "section-2", 3: "section-3", 4: "section-4", 5: "section-5" };
    const sectionId = sectionMap[checklistId];
    if (!sectionId) return;

    // In syllabi mode, only DRF stays hidden — Masterlist now shows in full
    if (checklistId === 1 && window.__isSyllabiMode) return;

    // Retrieval (checklist 4) is only for revised documents
    if (checklistId === 4 && !isRevisedMode()) {
        clearRetrievalSection();
        return;
    }

    const el = document.getElementById(sectionId);
    if (!el) return;

    el.style.display = show ? "block" : "none";
    if (show) setTimeout(initFileInputs, 50);

    if (checklistId === 3 && window.__isSyllabiMode) {
        const syllabiSection = document.getElementById("section-syllabi");
        if (syllabiSection) syllabiSection.style.display = show ? "block" : "none";
    }
};

// ══════════════════════════════════════════════
// FORM ACTIONS
// ══════════════════════════════════════════════
function showFormActions() {
    const el = document.getElementById("formActions");
    if (el) el.style.display = "flex";
}

function hideFormActions() {
    const el = document.getElementById("formActions");
    if (el) el.style.display = "none";
}

// ══════════════════════════════════════════════
// AUTO TIME SPENT CALCULATION
// ══════════════════════════════════════════════
function calcTimeDiff(startDateId, startTimeId, endDateId, endTimeId, displayId, hiddenId) {
    const startDate = document.getElementById(startDateId).value;
    const startTime = document.getElementById(startTimeId).value;
    const endDate = document.getElementById(endDateId).value;
    const endTime = document.getElementById(endTimeId).value;

    const display = document.getElementById(displayId);
    const hidden = document.getElementById(hiddenId);

    const result = computeDuration(startDate, startTime, endDate, endTime);

    if (!result) {
        display.value = "--";
        display.style.color = "";
        hidden.value = "";
        return;
    }
    if (result.invalid) {
        display.value = "Invalid";
        display.style.color = "var(--reg-error)";
        hidden.value = "";
        return;
    }

    display.style.color = "";
    display.value = formatDuration(result.totalMinutes);
    hidden.value = String(result.totalMinutes);
}

window.calcRetrievalTimeSpent = function () {
    calcTimeDiff("retrievalFormDate", "retrievalFormTime", "retrievalDate", "retrievalTime", "retrievalTimeSpentDisplay", "retrievalTimeSpent");
};

window.calcDistributionTimeSpent = function () {
    calcTimeDiff("distributionFormDate", "distributionFormTime", "distributionDate", "distributionTime", "distributionTimeSpentDisplay", "distributionTimeSpent");
};

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
        addOffice(o.office_id, o.office_name, 'distBody', 'totalDistCopies', 'distResults');
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

    updateTotal('totalDistCopies', 'distBody');
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

window.calcMasterlistTimeSpent = function () {
    calcTimeDiff("masterlistReceiptDate", "masterlistReceiptTime", "masterlistRegisteredDate", "masterlistRegisteredTime", "masterlistTimeSpentDisplay", "masterlistTimeSpent");
};

// ══════════════════════════════════════════════
// FORM VALIDATION
// ══════════════════════════════════════════════
function clearValidation() {
    document.querySelectorAll(".reg-input-error").forEach(el => el.classList.remove("reg-input-error"));
    document.querySelectorAll(".reg-field-error").forEach(el => el.remove());
}

function markFieldError(fieldId, message) {
    // Fix 1: also search by [name] so dynamic hidden inputs are found
    const field = fieldId
        ? (document.getElementById(fieldId) || document.querySelector('[name="' + fieldId + '"]'))
        : null;

    if (field) {
        field.classList.add("reg-input-error");
        const parent = field.closest(".reg-field") || field.closest("td") || field.parentElement;
        if (parent && !parent.querySelector(".reg-field-error")) {
            const err = document.createElement("div");
            err.className = "reg-field-error";
            err.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + escapeHtml(message);
            parent.appendChild(err);
        }
    } else {
        // Fix 2: use appendChild — .reg-table-wrap is nested, not a direct child of #section-syllabi
        const syllabiSection = document.getElementById("section-syllabi");
        if (syllabiSection && !syllabiSection.querySelector('.reg-field-error')) {
            const err = document.createElement("div");
            err.className = "reg-field-error";
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
        const err = document.createElement("div");
        err.className = "reg-field-error";
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
        const err = document.createElement("div");
        err.className = "reg-field-error";
        err.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + escapeHtml(message);
        parent.appendChild(err);
    }
}

function markChecklistError(message) {
    const container = document.getElementById("dynamicCheckboxes");
    if (!container) return;

    const parent = container.closest(".reg-panel-bottom") || container.parentElement;
    if (parent && !parent.querySelector(".reg-field-error")) {
        const err = document.createElement("div");
        err.className = "reg-field-error";
        err.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + escapeHtml(message);
        parent.appendChild(err);
    }
}

function sectionVisible(id) {
    const el = document.getElementById(id);
    return el && el.style.display !== "none";
}

// ══════════════════════════════════════════════
// FORM VALIDATION — structural only; content fields are optional
// ══════════════════════════════════════════════
function validateForm() {
    clearValidation();
    const errors = [];

    if (!document.getElementById("versionType").value) {
        errors.push({ field: "versionType", message: "Version Type is required." });
    }
    if (!document.getElementById("docType").value) {
        errors.push({ field: "docType", message: "Document Type is required." });
    }

    if (errors.length > 0) return errors;

    const hasChildren = allDocTypes.some(d => d.parent_id == document.getElementById("docType").value);
    if (hasChildren && !document.getElementById("subType").value) {
        errors.push({ field: "subType", message: "Sub-Type is required." });
        return errors;
    }

    if (isRevisedMode()) {
        const fieldId = window.__isSyllabiMode ? 'syllabiDocNo' : 'masterlistDocNo';
        const docNo = document.getElementById(fieldId)?.value.trim()
            || document.getElementById('masterlistDocNo')?.value.trim();
        const hintEl = document.getElementById('docNoHint');
        const linkedFrom = getRevisedFromDocNo();
        if (!docNo || !isRevisedDocNoReady(hintEl)) {
            errors.push({
                field: fieldId,
                message: linkedFrom
                    ? "Enter the document number for this revision (keep the same number or assign a new one)."
                    : "Pick a document in Documents for Revision (DCN) or enter a registered document number before revising.",
            });
        }
        if (!window.__isSyllabiMode) {
            validateRevisedRequiresDocumentsForRevision(errors);
        }
    }

    const checkedBoxes = document.querySelectorAll("#dynamicCheckboxes input[type='checkbox']:checked");
    if (checkedBoxes.length === 0) {
        errors.push({ field: "dynamicCheckboxes", message: "Please check at least one checklist to proceed.", type: "checklist" });
        return errors;
    }

    // ── Time Spent must never be logically invalid (end before start) ──
    // this blocks saving outright — it's a data-integrity error, not a missing value.
    validateTimeSpentFields(errors);

    // ── DCN revision rows must reference an actual registered document ──
    validateRevisionRowsLinked(errors);

    // Content fields are otherwise optional — missing values are
    // surfaced in the review modal instead, with a confirm-anyway step.
    return errors;
}

/** Blocks save if any visible Time Spent calculation shows "Invalid". */
function validateTimeSpentFields(errors) {
    if (sectionVisible("section-3")) {
        const ml = document.getElementById("masterlistTimeSpentDisplay");
        if (ml && ml.value === "Invalid") {
            errors.push({
                field: "masterlistRegisteredDate",
                message: "Masterlist: Document Registered must be after Document Receipt."
            });
            ["masterlistReceiptDate", "masterlistReceiptTime", "masterlistRegisteredDate", "masterlistRegisteredTime"]
                .forEach(id => document.getElementById(id)?.classList.add("reg-input-error"));
        }
    }

    if (sectionVisible("section-4")) {
        const ret = document.getElementById("retrievalTimeSpentDisplay");
        if (ret && ret.value === "Invalid") {
            errors.push({
                field: "retrievalDate",
                message: "Retrieval: Retrieval Date must be after Form Date."
            });
            ["retrievalFormDate", "retrievalFormTime", "retrievalDate", "retrievalTime"]
                .forEach(id => document.getElementById(id)?.classList.add("reg-input-error"));
        }
    }

    if (sectionVisible("section-5")) {
        const dist = document.getElementById("distributionTimeSpentDisplay");
        if (dist && dist.value === "Invalid") {
            errors.push({
                field: "distributionDate",
                message: "Distribution: Distribution Date must be after Form Date."
            });
            ["distributionFormDate", "distributionFormTime", "distributionDate", "distributionTime"]
                .forEach(id => document.getElementById(id)?.classList.add("reg-input-error"));
        }
    }
}

/** Blocks save if a "Documents for Revision" row has typed text but was never
 *  linked to a real registered document via the search suggestions. */
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
                message: "DCN Row " + (idx + 1) + ": Please select an existing registered document from the suggestions — this document is not registered.",
                type: "table"
            });
            row.querySelectorAll('input[name="documentTitle[]"], input[name="documentNo[]"]')
                .forEach(el => el.classList.add("reg-input-error"));
        }
    });
}

/** Revised registrations must pick the document being revised in DCN. */
function validateRevisedRequiresDocumentsForRevision(errors) {
    if (!sectionVisible("section-2")) {
        errors.push({
            field: "dynamicCheckboxes",
            message: "DCN is required for revised documents. Check Document Change Notice and select the document being revised under Documents for Revision.",
            type: "checklist",
        });
        return;
    }

    const hasLinked = [...document.querySelectorAll("#revisionTableBody tr")]
        .some(row => row.dataset.linked === "true");
    if (hasLinked) return;

    errors.push({
        field: "revisionTableBody",
        message: "Select the document being revised under Documents for Revision (DCN). This is required before saving a revised document.",
        type: "table",
    });
    document.querySelectorAll("#revisionTableBody tr").forEach(row => {
        row.querySelectorAll('input[name="documentTitle[]"], input[name="documentNo[]"]')
            .forEach(el => el.classList.add("reg-input-error"));
    });
}

/** Collects a human-readable list of fields left blank, per visible section.
 *  Used only for the review modal's "missing information" summary — never blocks saving. */
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

        // ── Group-level fields — only when Syllabi Availability is checked ──
        syllabiRows.forEach((row) => {
            const groupId = row.dataset.group;
            const syllabiOn = !!row.querySelector('.syllabi-merged-availability')?.checked;
            const anyDrfOn = [...document.querySelectorAll(
                `#syllabiTableBody tr[data-group="${groupId}"] .syllabi-hidden-toggle[name="syllabiDrfAvailability[]"]`
            )].some((h) => h.value === 'available');

            // Unchecked syllabi + unchecked DRF → skip this course entirely
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

        // ── Per-row fields — only for checked Syllabi / DRF availability ──
        document.querySelectorAll("#syllabiTableBody tr[data-uid]").forEach((row) => {
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
                if (!scannedDrf || !scannedDrf.files || scannedDrf.files.length === 0) missing.push(rowLabel + ": Scanned DRF");
            }
        });
    }

    if (sectionVisible("section-1")) {
        checkText("DRF", "drfNo", "DRF No.");
        checkText("DRF", "drfDate", "DRF Date");
        checkText("DRF", "drfReceiptDate", "Date Receipt");
        checkText("DRF", "drfTime", "Time Receipt");
        checkText("DRF", "drfTitle", "Document Title");
        if (!window.__sourceWidgets.drf || window.__sourceWidgets.drf.selected.length === 0) {
            missing.push("DRF: Source Unit");
        }
    }

    if (sectionVisible("section-2")) {
        checkText("DCN", "dcnNumber", "DCN No.");
        checkText("DCN", "noticeDate", "DCN Date");
        checkText("DCN", "receiptDate", "DCN Receipt Date");
        checkText("DCN", "receiptTime", "DCN Receipt Time");
        if (!window.__sourceWidgets.dcn || window.__sourceWidgets.dcn.selected.length === 0) {
            missing.push("DCN: Source Unit");
        }
        let hasRevision = false;
        document.querySelectorAll("#revisionTableBody tr").forEach(row => {
            const title = row.querySelector('input[name="documentTitle[]"]');
            const no = row.querySelector('input[name="documentNo[]"]');
            if ((title && title.value.trim()) || (no && no.value.trim())) hasRevision = true;
        });
        if (!hasRevision) missing.push("DCN: At least one revision document");
    }

    if (sectionVisible("section-3")) {
        if (!window.__isSyllabiMode) {
            checkText("Masterlist", "masterlistDocNo", "Document No.");
            checkText("Masterlist", "masterlistDocTitle", "Document Title");
            checkText("Masterlist", "deadlineOfSubmission", "Deadline of Submission");
            checkText("Masterlist", "masterlistEffectivityDate", "Effectivity Date");
        }
        checkText("Masterlist", "masterlistReceiptDate", "Document Receipt Date");
        checkText("Masterlist", "masterlistReceiptTime", "Document Receipt Time");
        checkText("Masterlist", "masterlistRegisteredDate", "Document Registered Date");
        checkText("Masterlist", "masterlistRegisteredTime", "Document Registered Time");
        checkText("Masterlist", "masterlistNoOfPages", "No. of Pages");
        checkText("Masterlist", "keywords", "Keywords");
        if (!window.__sourceWidgets.masterlistOriginator || window.__sourceWidgets.masterlistOriginator.selected.length === 0) {
            missing.push("Masterlist: Originator");
        }
        if (!window.__sourceWidgets.masterlist || window.__sourceWidgets.masterlist.selected.length === 0) {
            missing.push("Masterlist: Source Unit");
        }
    }

    if (sectionVisible("section-4")) {
        checkText("Retrieval", "retrievalFormDate", "Retrieval Form Date");
        checkText("Retrieval", "retrievalFormTime", "Retrieval Form Time");
        checkText("Retrieval", "retrievalDate", "Retrieval Date");
        checkText("Retrieval", "retrievalTime", "Retrieval Time");
        if (document.querySelectorAll("#retrievalBody input[type='hidden']").length === 0) {
            missing.push("Retrieval: At least one office");
        }
    }

    if (sectionVisible("section-5")) {
        checkText("Distribution", "distributionFormDate", "Distribution Form Date");
        checkText("Distribution", "distributionFormTime", "Distribution Form Time");
        checkText("Distribution", "distributionDate", "Distribution Date");
        checkText("Distribution", "distributionTime", "Distribution Time");
        checkText("Distribution", "distributionRemarks", "Remarks");
        if (document.querySelectorAll("#distBody input[type='hidden']").length === 0) {
            missing.push("Distribution: At least one office");
        }
    }

    return missing;
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

    const section = field.closest(".reg-card");
    if (section && section.style.display === "none") return;

    field.scrollIntoView({ behavior: "smooth", block: "center" });

    setTimeout(() => {
        if (field.tagName === "SELECT" || field.tagName === "INPUT") field.focus();
    }, 400);
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
        if (parent) {
            const err = parent.querySelector(".reg-file-error");
            if (err) err.remove();
        }
        const container = e.target.closest(".reg-upload");
        if (container) {
            const field = container.closest(".reg-field");
            if (field) {
                const err = field.querySelector(".reg-file-error");
                if (err) err.remove();
            }
        }
    }
});

// ══════════════════════════════════════════════
// CONFIRM SAVE / REVIEW
// ══════════════════════════════════════════════
function getInputVal(id) {
    const el = document.getElementById(id);
    return el ? el.value.trim() : "";
}

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
    if (revNoDuplicate) {
        scrollToField('masterlistRevisionNo');
        document.getElementById('masterlistRevisionNo')?.focus();
        return;
    }
    const errors = validateForm();

    if (errors.length > 0) {
        showValidationErrors(errors);
        return;
    }

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

    const root = document.getElementById("dcsRegisterRoot");
    if (root) root.style.overflow = "hidden";
    if (root && window.Alpine) {
        Alpine.$data(root).reviewOpen = true;
    }
};

/** Shows a redesigned warning card + a required "save anyway" checkbox when fields are blank.
 *  Confirm Save button stays disabled until the checkbox is ticked (only when needed). */
function renderMissingFieldsWarning(container, missing) {
    const confirmBtn = document.getElementById("btnConfirmSaveModal");

    if (missing.length === 0) {
        if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.style.opacity = ''; confirmBtn.style.cursor = ''; }
        return;
    }

    // Group "Section: Field" strings by their section for a cleaner layout
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
            <div class="missing-group-title">${section}</div>
            <div class="missing-group-chips">
                ${fields.map(f => `<span class="missing-chip"><i class="fa-solid fa-circle-minus"></i>${f}</span>`).join('')}
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
        <div class="review-missing-body">
            ${groupsHtml}
        </div>
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

/** Fully syncs the Confirm Save button's enabled state AND its visual style
 *  to the "save anyway" checkbox — fixes the bug where the button became
 *  clickable but still looked greyed-out/disabled after checking the box. */
window.handleConfirmSaveAnywayToggle = function (checkbox) {
    const confirmBtn = document.getElementById('btnConfirmSaveModal');
    if (!confirmBtn) return;

    confirmBtn.disabled = !checkbox.checked;

    if (checkbox.checked) {
        confirmBtn.style.opacity = '';
        confirmBtn.style.cursor = '';
    } else {
        confirmBtn.style.opacity = '0.5';
        confirmBtn.style.cursor = 'not-allowed';
    }
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

    const f = document.getElementById("drfFile").files;
    addReviewSection(reviewContent, "Document Request Form", [
        { label: "DRF No.", value: getInputVal("drfNo") },
        { label: "DRF Date", value: formatInputDate("drfDate") },
        { label: "Receipt", value: formatInputDate("drfReceiptDate") + " " + getInputVal("drfTime") },
        { label: "Title", value: getInputVal("drfTitle") },
        { label: "Source Unit", value: window.__sourceWidgets.drf?.selected.length > 0 ? window.__sourceWidgets.drf.selected.map(o => o.label).join(', ') : null },
        { label: "File", value: f.length > 0 ? f[0].name : null, isFile: true },
    ]);
}

function buildDcnReview(reviewContent) {
    const s2 = document.getElementById("section-2");
    if (!s2 || s2.style.display === "none") return;

    const f = document.getElementById("dcnFile").files;
    addReviewSection(reviewContent, "Document Change Notice", [
        { label: "DCN No.", value: getInputVal("dcnNumber") },
        { label: "DCN Date", value: formatInputDate("noticeDate") },
        { label: "Receipt", value: formatInputDate("receiptDate") + " " + getInputVal("receiptTime") },
        { label: "Source Unit", value: window.__sourceWidgets.dcn?.selected.length > 0 ? window.__sourceWidgets.dcn.selected.map(i => i.label).join(', ') : null },
        { label: "File", value: f.length > 0 ? f[0].name : null, isFile: true },
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

    const f = document.getElementById("uploadScannedCopy").files;
    addReviewSection(reviewContent, "Masterlist Registration", [
        { label: "Doc No.", value: getInputVal("masterlistDocNo") },
        { label: "Title", value: getInputVal("masterlistDocTitle") },
        { label: "Deadline", value: formatInputDate("deadlineOfSubmission") },
        { label: "Receipt", value: formatInputDate("masterlistReceiptDate") + " " + getInputVal("masterlistReceiptTime") },
        { label: "Registered", value: formatInputDate("masterlistRegisteredDate") + " " + getInputVal("masterlistRegisteredTime") },
        { label: "Time Spent", value: document.getElementById("masterlistTimeSpentDisplay").value || null },
        { label: "Effectivity", value: formatInputDate("masterlistEffectivityDate") },
        { label: "Revision No.", value: getInputVal("masterlistRevisionNo") },
        { label: "Pages", value: getInputVal("masterlistNoOfPages") },
        { label: "Originator", value: window.__sourceWidgets.masterlistOriginator?.selected.length > 0 ? window.__sourceWidgets.masterlistOriginator.selected.map(o => o.label).join(', ') : null },
        { label: "Source Unit", value: window.__sourceWidgets.masterlist?.selected.length > 0 ? window.__sourceWidgets.masterlist.selected.map(i => i.label).join(', ') : null },
        { label: "Keywords", value: getInputVal("keywords") },
        { label: "Related Docs", value: relatedDocsSelected.length > 0 ? relatedDocsSelected.map(d => d.doc_title).join(', ') : null },
        { label: "File", value: f.length > 0 ? f[0].name : null, isFile: true },
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

    const f = document.getElementById("scannedRet").files;
    addReviewSection(reviewContent, "Document Retrieval", [
        { label: "Form Date", value: formatInputDate("retrievalFormDate") + " " + getInputVal("retrievalFormTime") },
        { label: "Retrieval Date", value: formatInputDate("retrievalDate") + " " + getInputVal("retrievalTime") },
        { label: "Time Spent", value: document.getElementById("retrievalTimeSpentDisplay").value || null },
        { label: "Remarks", value: getInputVal("retrievalRemarks") },
        { label: "File", value: f.length > 0 ? f[0].name : null, isFile: true },
    ]);
    const off = getOfficeList("retrievalBody");
    if (off.length) addReviewOfficeList(reviewContent, "Receiving Offices (Retrieval)", off);
}

function buildDistributionReview(reviewContent) {
    const s5 = document.getElementById("section-5");
    if (!s5 || s5.style.display === "none") return;

    const f = document.getElementById("scanneddist").files;
    addReviewSection(reviewContent, "Document Distribution", [
        { label: "Form Date", value: formatInputDate("distributionFormDate") + " " + getInputVal("distributionFormTime") },
        { label: "Distribution Date", value: formatInputDate("distributionDate") + " " + getInputVal("distributionTime") },
        { label: "Time Spent", value: document.getElementById("distributionTimeSpentDisplay").value || null },
        { label: "Remarks", value: getInputVal("distributionRemarks") },
        { label: "File", value: f.length > 0 ? f[0].name : null, isFile: true },
    ]);
    const off = getOfficeList("distBody");
    if (off.length) addReviewOfficeList(reviewContent, "Receiving Offices (Distribution)", off);
}

window.closeConfirmModal = function () {
    const root = document.getElementById("dcsRegisterRoot");
    if (root && window.Alpine) {
        Alpine.$data(root).reviewOpen = false;
    }
    if (root) root.style.overflow = "";
};

document.addEventListener("keydown", function (e) {
    if (e.key !== "Escape") return;
    const root = document.getElementById("dcsRegisterRoot");
    if (root && window.Alpine && Alpine.$data(root).reviewOpen) closeConfirmModal();
});

window.submitForm = function () {
    document.getElementById("masterForm").submit();
};

// ══════════════════════════════════════════════
// APPROVAL
// ══════════════════════════════════════════════
window.handleApprovalToggle = function (applicable) {
    const approval = document.getElementById("section-approval");
    if (approval) approval.style.display = applicable ? "block" : "none";
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

/**
 * DRR left side = scanned copy of the highest family rev strictly below the new Rev No.
 * e.g. entering Rev 5 with family 1–4,7 → compare against Rev 4 (not Latest 7).
 */
function resolveRevisedComparePrevious() {
    const scans = Array.isArray(window.__revisedFamilyScans) ? window.__revisedFamilyScans : [];
    const revRaw = document.getElementById('masterlistRevisionNo')?.value;
    const newRev = revRaw === '' || revRaw === null || revRaw === undefined
        ? null
        : Number(revRaw);

    window.__revisedComparePreviousUrl = null;
    window.__revisedComparePreviousRev = null;

    if (newRev === null || Number.isNaN(newRev) || scans.length === 0) {
        refreshCompareRevisionButton();
        return;
    }

    let best = null;
    for (const row of scans) {
        const r = Number(row?.revise_no);
        if (Number.isNaN(r) || r >= newRev) continue;
        if (!row?.scanned_copy_url) continue;
        if (!best || r > Number(best.revise_no)) {
            best = row;
        }
    }

    if (best) {
        window.__revisedComparePreviousUrl = best.scanned_copy_url;
        window.__revisedComparePreviousRev = Number(best.revise_no);
    }

    refreshCompareRevisionButton();
}

function refreshCompareRevisionButton() {
    const upload = document.getElementById('uploadScannedCopy');
    const wrap = document.getElementById('compareRevisionWrap');
    if (!wrap) return;
    const show = isRevisedMode()
        && (upload?.files?.length > 0)
        && !!window.__revisedComparePreviousUrl;
    wrap.style.display = show ? 'block' : 'none';
}

function wireCompareRevisionButton() {
    const upload = document.getElementById('uploadScannedCopy');
    const wrap = document.getElementById('compareRevisionWrap');
    const btn = document.getElementById('btnCompareRevision');
    if (!upload || !wrap || !btn) return;

    btn.addEventListener('click', (e) => {
        e.preventDefault();
        resolveRevisedComparePrevious();
        openCompareRevisionModal();
    });
    upload.addEventListener('change', () => {
        resolveRevisedComparePrevious();
    });
    document.getElementById('masterlistDocNo')?.addEventListener('input', refreshCompareRevisionButton);
    document.getElementById('masterlistRevisionNo')?.addEventListener('input', resolveRevisedComparePrevious);
    document.getElementById('masterlistRevisionNo')?.addEventListener('change', resolveRevisedComparePrevious);
    refreshCompareRevisionButton();
}

function enableApproval() {
    document.querySelectorAll('input[name="approval_status"]').forEach(r => r.disabled = false);
    // After checklist unlock, re-sync Approval Details from the linked previous document
    if (isRevisedMode() && window.__revisedApprovalContext) {
        applyRevisedApprovalFromPrevious(window.__revisedApprovalContext);
    }
}

function disableApproval() {
    document.querySelectorAll('input[name="approval_status"]').forEach(r => {
        r.disabled = true;
        r.checked = r.value === "not_applicable";
    });
    const approval = document.getElementById("section-approval");
    if (approval) approval.style.display = "none";
}

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
    const tbody = document.getElementById("revisionTableBody");
    if (!tbody) return;
    const tr = document.createElement("tr");
    tr.innerHTML = revisionRowCellsHTML();
    tbody.appendChild(tr);
    bindRevisionRowSearch(tr);
};

// ══════════════════════════════════════════════
// SYLLABI — AUTO-GENERATED DOCUMENT TITLE
// ══════════════════════════════════════════════
let syllabiTitleManuallyEdited = false;

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

/** Keep masterlist header fields in sync when in syllabi / TOS-Rubrics mode. */
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

    const programId  = document.getElementById('syllabiProgram').value;
    const semesterId = document.getElementById('syllabiSemester').value;

    try {
        const courses = ((window.__registerCatalog || {}).coursesByProgramSemester || {})[programId + ':' + semesterId] || [];
        const tbody = document.getElementById('syllabiTableBody');
        if (!tbody) return;

        const hasManualData = [...tbody.querySelectorAll('.syllabi-merged-course')]
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
                courseInput.title = 'Loaded from Settings → Course Names';
                autosizeSyllabiCourse(courseInput);
                courseInput.addEventListener('input', () => { courseInput.dataset.autoFilled = 'false'; });
            }
            const codeInput = newRow.querySelector('.syllabi-merged-code');
            if (codeInput) {
                codeInput.value = c.course_code || '';
            }

            const faculties = c.faculties || [];
            // Alternate real-world layouts: shared 1-copy/2-faculty, then 2-copies/1-faculty-each.
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

document.addEventListener("DOMContentLoaded", () => {
    const collegeSel = document.getElementById("syllabiCollege");
    const programSel = document.getElementById("syllabiProgram");
    const semSel = document.getElementById("syllabiSemester");
    const sySel = document.getElementById("syllabiSchoolYear");
    const titleInput = document.getElementById("syllabiDocTitle");

    // Stop auto-updating the title the moment the user types into it themselves
    if (titleInput) {
        titleInput.addEventListener("input", () => { syllabiTitleManuallyEdited = true; });
    }

    if (collegeSel) {
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
            } catch (err) {
                console.error("Failed to load programs:", err);
            }
        });
    }

    if (programSel) {
        programSel.addEventListener("change", function () {
            semSel.value = "";
            semSel.disabled = !this.value;
            sySel.value = "";
            sySel.disabled = true;
            updateSyllabiTitle();
            clearSyllabiCourseRows(); 
        });
    }

    if (semSel) {
        semSel.addEventListener("change", function () {
            sySel.value = "";
            sySel.disabled = !this.value;
            updateSyllabiTitle();
            clearSyllabiCourseRows(); 
        });
    }

    if (sySel) {
        sySel.addEventListener("change", function () {
            updateSyllabiTitle();
            autoPopulateSyllabiCourses();
        });
    }
});

function getSelectTextWithCode(id) {
    const el = document.getElementById(id);
    if (!el || el.selectedIndex < 0) return "";
    const opt = el.options[el.selectedIndex];
    const text = opt.text || "";
    const code = opt.dataset ? opt.dataset.code : "";
    return code ? `${text} (${code})` : text;
}

// ══════════════════════════════════════════════
// SYLLABI WIZARD — STEP NAVIGATION
// ══════════════════════════════════════════════
function setSyllabiStep(step) {
    syllabiCurrentStep = step;
    const root = document.getElementById("dcsRegisterRoot");
    if (root && window.Alpine) {
        Alpine.$data(root).syllabiStep = step;
    }
}

window.syllabiStepNext = function () {
    if (syllabiCurrentStep < 2) {
        setSyllabiStep(syllabiCurrentStep + 1);
    }
};

window.syllabiStepBack = function () {
    if (syllabiCurrentStep > 1) setSyllabiStep(syllabiCurrentStep - 1);
};

// ══════════════════════════════════════════════
// SYLLABI — FACULTY PICKER (per row, single or multi depending on copies)
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

/** mode: 'single' (copies > 1) or 'multi' (copies === 1, shared syllabi) */
function initSyllabiFacultyPicker(uid, mode, rootEl) {
    window.__syllabiFaculty[uid] = { mode, selected: [] };
    bindSyllabiFacultyInput(uid, rootEl);
    renderSyllabiFacultyChips(uid);
}

function setSyllabiFacultyMode(uid, mode) {
    const rootEl = document.querySelector(`#syllabiTableBody tr[data-uid="${uid}"]`);
    if (!window.__syllabiFaculty[uid]) {
        initSyllabiFacultyPicker(uid, mode, rootEl);
        return;
    }
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

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
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

// Close faculty dropdowns on outside click
document.addEventListener('mousedown', function (e) {
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

// Faculty is now its own cell builder, separate from the rest of the per-row cells
function buildSyllabiFacultyTd(uid, mirrorHiddenHTML = '') {
    return `
        <td class="col-shared">
            ${mirrorHiddenHTML}
            ${buildSyllabiFacultyCellHTML(uid)}
        </td>
    `;
}

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
            if (uploadCell) {
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

// buildSyllabiPerRowCells — per-copy date/time/pages fields
function buildSyllabiPerRowCells(uid) {
    return `
        <td class="col-step1"><input type="number" name="syllabiNoPages[]" min="0" placeholder="0" class="syllabi-row-pages" oninput="updateSyllabiTotals()"></td>
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
            <label class="reg-upload-cell">
                <input type="file" name="syllabiScannedDrf[]" accept=".pdf">
                <i class="fa-solid fa-cloud-arrow-up"></i>
                <span>No file chosen</span>
            </label>
        </td>
    `;
}

/** Positions a fixed-position dropdown directly under its input. Since it lives
 *  in document.body, no transformed/filtered ancestor can hijack the fixed positioning. */
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

// ══════════════════════════════════════════════
// SYLLABI — DATE/TIME + DRF CASCADE (empty-only)
// ══════════════════════════════════════════════

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

/** When any row's DRF Date / Received changes, copy into other empty enabled rows only. */
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

/** When adding a new row, fill empty DRF Date/Received from the first filled peer. */
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

    // Prefer split layout when catalog asks for it, or when copies already > 1.
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

    // 1 copy + up to 2 faculty (shared syllabi)
    if (!syllabiRowHasFaculty(firstRow)) {
        ensureFacultyState(firstRow.dataset.uid);
        setSyllabiFacultyMode(firstRow.dataset.uid, 'multi');
        names.slice(0, 2).forEach(name => addSyllabiFaculty(firstRow.dataset.uid, name, false));
    }
}

/** Collect faculty labels currently selected across a course group (preserves order). */
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

/** Apply faculty to a group: 1 copy = multi (shared), 2+ copies = 1 faculty per row. */
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

/** When first row date/time changes, sync to all rows in the course group. */
window.cascadeSyllabiReceived = function (input, fieldName) {
    const sourceRow = input?.closest('tr');
    if (!sourceRow) return;
    const date = (sourceRow.querySelector('input[name="syllabiDateReceived[]"]')?.value || '').trim();
    const time = (sourceRow.querySelector('input[name="syllabiTimeReceived[]"]')?.value || '').trim();
    if (!date && !time) return;

    if (sourceRow.dataset.isFirst === 'true') {
        document.querySelectorAll(`#syllabiTableBody tr[data-group="${sourceRow.dataset.group}"]`).forEach(tr => {
            const dateEl = tr.querySelector('input[name="syllabiDateReceived[]"]');
            const timeEl = tr.querySelector('input[name="syllabiTimeReceived[]"]');
            if (dateEl && !dateEl.readOnly && date) dateEl.value = date;
            if (timeEl && !timeEl.readOnly && time) timeEl.value = time;
        });
        return;
    }

    propagateReceivedToEmptyRows({ date, time, row: sourceRow });
};

function fillReceivedFromGroup(tr) {
    if (!tr || !syllabiGroupIsAvailable(tr.dataset.group) || !syllabiRowHasFaculty(tr)) return;
    const peer = findFirstFilledReceived(tr);
    if (!peer) return;
    if ((peer.date || '').trim()) stampEmpty(tr.querySelector('input[name="syllabiDateReceived[]"]'), peer.date);
    if ((peer.time || '').trim()) stampEmpty(tr.querySelector('input[name="syllabiTimeReceived[]"]'), peer.time);
}

/** Update the total copies AND total pages display below the syllabi table. */
function updateSyllabiTotals() {
    let totalCopies = 0;
    document.querySelectorAll('#syllabiTableBody .syllabi-merged-copies').forEach(inp => {
        totalCopies += parseInt(inp.value) || 0;
    });
    const copiesEl = document.getElementById('totalSyllabiCopies');
    if (copiesEl) copiesEl.textContent = totalCopies;

    let totalPages = 0;
    document.querySelectorAll('#syllabiTableBody .syllabi-row-pages, #syllabiTableBody .syllabi-merged-pages').forEach(inp => {
        totalPages += parseInt(inp.value) || 0;
    });
    const pagesEl = document.getElementById('totalSyllabiPages');
    if (pagesEl) pagesEl.textContent = totalPages;

    // NEW: feed the total into the Masterlist "No. of Pages" field
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

function bindSyllabiRowFileInputs(tr) {
    tr.querySelectorAll('.reg-upload-cell input[type="file"]').forEach(fileInput => {
        const cell = fileInput.closest('.reg-upload-cell');
        fileInput.dataset.bound = "true";
        fileInput.addEventListener('change', function () {
            processUploadCellFile(this, cell);
            if (this.files && this.files[0]) {
                const cb = tr.querySelector('.syllabi-drf-availability');
                if (cb && !cb.checked) {
                    cb.checked = true;
                    syncSyllabiDrfRow(tr);
                }
            }
        });
    });
    syncSyllabiDrfRow(tr);
}

/** First row of a course group — holds the merged (rowspan) Course/Availability/Copies cells. */
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
            <input type="number" name="syllabiCopies[]" min="1" value="1"
                class="syllabi-merged-copies" oninput="handleCopiesChange(this)">
        </td>
        ${buildSyllabiFacultyTd(uid)}
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
    `;

    tr.innerHTML = buildSyllabiFacultyTd(uid, mirrors) + buildSyllabiPerRowCells(uid);
    bindSyllabiRowFileInputs(tr);
    initSyllabiFacultyPicker(uid, 'single', tr);
    setSyllabiFacultyRowEnabled(tr, false);
    return tr;
}

function autosizeSyllabiCourse(el) {
    el.style.height = 'auto';
    el.style.height = el.scrollHeight + 'px';
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

    document.querySelectorAll(`#syllabiTableBody tr[data-group="${groupId}"]:not([data-is-first="true"])`)
        .forEach(row => {
            const mc = row.querySelector('.syllabi-mirror-course');
            const mcode = row.querySelector('.syllabi-mirror-code');
            const ma = row.querySelector('.syllabi-mirror-availability');
            const mp = row.querySelector('.syllabi-mirror-copies');
            if (mc) mc.value = courseVal;
            if (mcode) mcode.value = codeVal;
            if (ma) ma.value = availHidden.value;
            if (mp) mp.value = copiesVal;
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

// ══════════════════════════════════════════════
// COPY SPLITTING — driven only by the No. Copies field
// ══════════════════════════════════════════════
window.handleCopiesChange = function (input) {
    const firstRow = input.closest("tr");
    const group = firstRow.dataset.group;
    const desired = Math.max(1, parseInt(input.value) || 1);
    input.value = desired;

    // Keep current faculty list before rows/modes are rebuilt.
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

    // 1 copy → shared multi-faculty; 2+ copies → one faculty per row
    firstRow.dataset.catalogSplit = desired > 1 ? '1' : '0';
    assignSyllabiFacultiesToGroup(group, preservedFaculty, desired);

    syncSyllabiMergedFields(group);
    syncSyllabiAvailability(group);
    updateSyllabiTotals();
};

// ══════════════════════════════════════════════
// OFFICE SEARCH (Retrieval / Distribution)
// ══════════════════════════════════════════════
window.handleSearch = function (input, resultsId, bodyId, totalId) {
    const dropdown = document.getElementById(resultsId);
    if (!dropdown) return;

    const selected = new Set(getSelectedOfficeIds(bodyId));
    const filtered = filterOffices(input.value)
        .filter((o) => !selected.has(String(o.office_id)));

    if (input.value.trim().length < 1 || filtered.length === 0) {
        dropdown.style.display = "none";
        return;
    }

    dropdown.innerHTML = filtered
        .map(o => '<div onclick="addOffice(' + Number(o.office_id) + ", '" + escapeHtml(o.office_name).replace(/'/g, '&#39;') + "', '" + bodyId + "', '" + totalId + "', '" + resultsId + "')\">" + escapeHtml(o.office_name) + '</div>')
        .join("");
    dropdown.style.display = "block";
};

window.addOffice = function (officeId, officeName, bodyId, totalId, resultsId) {
    const tbody = document.getElementById(bodyId);
    const dropdown = document.getElementById(resultsId);
    if (!tbody) return;

    const isRetrieval = bodyId === "retrievalBody";
    const officeNameAttr = isRetrieval ? "retrievalOffice[]" : "distOffice[]";
    const copiesNameAttr = isRetrieval ? "retrievalCopies[]" : "distCopies[]";

    const emptyRow = tbody.querySelector(".reg-empty-row");
    if (emptyRow) emptyRow.remove();

    const existingInputs = tbody.querySelectorAll('input[type="hidden"]');
    for (const inp of existingInputs) {
        if (inp.value == officeId) {
            if (dropdown) {
                dropdown.style.display = "none";
                dropdown.parentElement.querySelector("input[type='text']").value = "";
            }
            const existingRow = inp.closest("tr");
            existingRow.style.animation = "none";
            existingRow.offsetHeight;
            existingRow.style.animation = "flashRow 0.6s ease";
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

    const tr = document.createElement("tr");
    tr.className = "reg-office-added";
    if (!isRetrieval) tr.draggable = true;
    tr.innerHTML = `
        <td>
            <input type="hidden" name="${officeNameAttr}" value="${officeId}">
            <div class="reg-office-name">
                <div class="reg-office-icon"><i class="fa-solid fa-building"></i></div>
                <span class="reg-office-text">${escapeHtml(officeName)}</span>
            </div>
        </td>
        <td style="text-align: center;">
            <input type="number" name="${copiesNameAttr}" value="1" min="1" oninput="updateTotal('${totalId}')">
        </td>
        <td>
            <button type="button" class="btn-remove" onclick="removeOffice(this, '${totalId}', '${bodyId}')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </td>
    `;
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
    tr.style.opacity = "0";
    tr.style.transform = "translateX(20px)";
    tr.style.transition = "all 0.2s ease";

    setTimeout(() => {
        tr.remove();
        updateTotal(totalId, bodyId);
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
        if (tbody) {
            tbody.querySelectorAll('input[type="number"]').forEach(input => {
                sum += parseInt(input.value) || 0;
            });
        }
    } else {
        const table = totalEl.closest("table");
        if (table) {
            table.querySelectorAll('tbody input[type="number"]').forEach(input => {
                sum += parseInt(input.value) || 0;
            });
        }
    }
    totalEl.textContent = sum;
};
</script>

<?php

/**
 * DCS file: Create an office DRF. Title and originator have no maxlength. Several originators go in one field, separated by commas.
 */

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('New DRF — CSPC DCS')] class extends Component {
    public function mount(): void
    {
        OfficeIntakeHelper::assertCanAccessIntake();

        $fromDcn = (int) request()->query('from_dcn', 0);
        if ($fromDcn > 0) {
            $existingDrfId = OfficeIntakeHelper::findLinkedDrfIdForOfficeDcn($fromDcn);
            if ($existingDrfId !== null) {
                session()->flash(
                    'error',
                    'This Document Change Notice already has a Document Request Form. Opening the existing DRF.'
                );
                $this->redirect(route('dcs.office.drf.show', $existingDrfId, absolute: false));

                return;
            }
        }
    }

    public function with(): array
    {
        $prefill = [
            'drfTitle' => '',
            'originatorName' => '',
            'descriptionReason' => '',
            'from_dcn' => 0,
        ];
        $fromDcn = (int) request()->query('from_dcn', 0);
        if ($fromDcn > 0) {
            $prefill = OfficeIntakeHelper::drfPrefillFromDcn($fromDcn);
        }

        $postedOffices = array_values(array_filter(array_map(
            'intval',
            (array) old('distributeToOffice', [])
        )));
        $seedOffices = $postedOffices !== []
            ? $postedOffices
            : array_values(array_filter(array_map('intval', (array) ($prefill['distributeOfficeIds'] ?? []))));

        $office = OfficeIntakeHelper::currentUserOfficeForDcn();
        $fromDcnId = (int) ($prefill['from_dcn'] ?? 0);
        $isRecurring = $fromDcnId < 1 && (
            strtolower((string) request()->query('mode', '')) === 'recurring'
            || old('drfMode') === 'recurring'
        );
        $docCatalog = OfficeIntakeHelper::intakeDocTypeCatalog();
        $optInRadios = OfficeIntakeHelper::enabledOfficeDrfTypeRadios();
        $optInIds = collect($optInRadios)->pluck('id')->map(fn ($id) => (int) $id)->all();
        // Internal catalog dropdown never lists Forms/Logbooks — those are Type of document radios.
        $catalogParents = collect($docCatalog['parents'] ?? [])
            ->filter(fn ($type) => ! OfficeIntakeHelper::isDefaultExcludedDrfParent((string) ($type->doc_type_name ?? '')))
            ->values()
            ->all();
        $sigTemplateMap = OfficeIntakeHelper::drfSignatoryTemplateMap();
        $lockDocType = (bool) ($prefill['lockDocType'] ?? false);
        $oldDocTypeKind = (string) old('docTypeKind', (string) ($prefill['docTypeKind'] ?? ''));
        if (! in_array($oldDocTypeKind, ['internal', 'external', 'forms', 'logbooks'], true)) {
            // Prefill may store kind=internal with Forms parent id — promote to forms/logbooks radio.
            $prefillTypeId = (int) ($prefill['docTypeId'] ?? 0);
            if ($prefillTypeId > 0 && in_array($prefillTypeId, $optInIds, true)) {
                foreach ($optInRadios as $opt) {
                    if ((int) $opt['id'] === $prefillTypeId) {
                        $oldDocTypeKind = (string) $opt['slug'];
                        break;
                    }
                }
            } else {
                $oldDocTypeKind = '';
            }
        }
        $oldDocTypeId = (string) old('docTypeId', (string) ($prefill['docTypeId'] ?? ''));
        $oldSubTypeId = (string) old('subTypeId', (string) ($prefill['subTypeId'] ?? ''));
        if (in_array($oldDocTypeKind, ['external', 'forms', 'logbooks'], true)) {
            if ($oldDocTypeKind === 'external') {
                $oldDocTypeId = '';
            } else {
                foreach ($optInRadios as $opt) {
                    if ($opt['slug'] === $oldDocTypeKind) {
                        $oldDocTypeId = (string) $opt['id'];
                        break;
                    }
                }
            }
            $oldSubTypeId = '';
        }
        $sigPrefill = null;
        if ($oldDocTypeKind === 'external') {
            $sigPrefill = OfficeIntakeHelper::drfSignatoryPrefill('external', null, null);
        } elseif (in_array($oldDocTypeKind, ['forms', 'logbooks'], true) && $oldDocTypeId !== '') {
            $sigPrefill = OfficeIntakeHelper::drfSignatoryPrefill('internal', (int) $oldDocTypeId, null);
        } elseif (($oldDocTypeId !== '' || $lockDocType) && $oldSubTypeId !== '') {
            // Internal catalog: only prefill once a sub-type is chosen.
            // Syllabi / TOS also need a college before signatories are filled.
            $oldCollegeId = old('collegeId') !== null && old('collegeId') !== '' ? (int) old('collegeId') : null;
            $needsCollege = OfficeIntakeHelper::isOfficeDrfSyllabiLike((int) $oldSubTypeId);
            if (! $needsCollege || ($oldCollegeId !== null && $oldCollegeId > 0)) {
                $sigPrefill = OfficeIntakeHelper::drfSignatoryPrefill(
                    $oldDocTypeKind !== '' ? $oldDocTypeKind : 'internal',
                    $oldDocTypeId !== '' ? (int) $oldDocTypeId : null,
                    (int) $oldSubTypeId,
                    $oldCollegeId
                );
            }
        }
        $parentKindMap = $sigTemplateMap['parentKind'] ?? [];
        $docTypeParentsForJs = [];
        foreach ($catalogParents as $type) {
            $id = (int) ($type->id ?? 0);
            if ($id < 1) {
                continue;
            }
            $name = (string) ($type->doc_type_name ?? '');
            $kind = $parentKindMap[(string) $id]
                ?? (str_contains(mb_strtolower($name), 'external') ? 'external' : 'internal');
            $docTypeParentsForJs[] = [
                'id' => $id,
                'name' => $name,
                'kind' => $kind,
            ];
        }

        return [
            'offices' => RegisterQueryHelper::jsCatalog()['offices'] ?? [],
            'clusters' => RegisterQueryHelper::jsCatalog()['clusters'] ?? [],
            'oldDistributeOfficeIds' => $seedOffices,
            'prefill' => $prefill,
            'fromDcnId' => $fromDcnId,
            'isRecurring' => $isRecurring,
            'docTypeParents' => $catalogParents,
            'docTypeChildrenByParent' => $docCatalog['childrenByParent'],
            'docTypeParentsForJs' => $docTypeParentsForJs,
            'optInTypeRadios' => $optInRadios,
            'oldDocTypeKind' => $oldDocTypeKind,
            'oldDocTypeId' => $oldDocTypeId,
            'oldSubTypeId' => $oldSubTypeId,
            'sigPrefill' => $sigPrefill,
            'sigTemplateMap' => $sigTemplateMap,
            'syllabiCatalog' => OfficeIntakeHelper::officeDrfSyllabiCatalog(),
            'userOfficeId' => $office['id'],
            'userOfficeCode' => $office['code'],
            'userOfficeName' => $office['name'],
            'userOfficeLabel' => $office['label'] !== '' ? $office['label'] : 'Your office',
        ];
    }
}; ?>

<div class="ofi-page">
    <div class="ofi-inner">
        <div class="ofi-toolbar">
            <a href="{{ route('dcs.office.drf.index', absolute: false) }}" class="reg-btn reg-btn-cancel">
                <i class="fa-solid fa-arrow-left"></i> Back to list
            </a>
            <p class="ofi-toolbar-hint">
                @if(($fromDcnId ?? 0) > 0)
                    Prefilled from Document Change Notice #{{ $fromDcnId }}. Document type follows the document being revised. Distribution starts with that document’s offices — add any extra offices before saving.
                @elseif($isRecurring ?? false)
                    Recurring Document Request Form. Find the registered document first (same match as DCN / admin Recur). RFIO will register this as Recurring.
                @else
                    Fill in the form and save it. Print stays locked until RFIO confirms the form and notifies you to print and sign. Scanned DRF uploads are handled by RFIO during document registration.
                @endif
            </p>
        </div>

        @if(session('success'))
            <div class="ofi-alert ok">{{ session('success') }}</div>
        @endif

        @if($errors->any())
            <div class="ofi-alert err">
                <ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        @if(($fromDcnId ?? 0) > 0)
            <div class="ofi-alert ok" role="status">
                <strong>Continuing from DCN #{{ $fromDcnId }}.</strong>
                Title and originator are prefilled. Confirm document type and distribution offices, then save this DRF so RFIO receives one combined DCN + DRF notice.
                <div style="margin-top:8px;">
                    <a href="{{ route('dcs.office.dcn.show', $fromDcnId, absolute: false) }}" class="ofi-dash-link">Open saved DCN <i class="fa-solid fa-arrow-right"></i></a>
                </div>
            </div>
        @endif

        <form method="POST" action="{{ route('dcs.office.drf.store', absolute: false) }}" id="ofiDrfForm">
            @csrf
            @if(($fromDcnId ?? 0) > 0)
                <input type="hidden" name="from_dcn" value="{{ $fromDcnId }}">
            @endif
            @if($isRecurring ?? false)
                @include('pages.dcs.office.partials.drf-recur-fields')
            @endif
            <section class="reg-card" id="section-1">
                <div class="reg-card-header">
                    <span>Document Request Form{{ ($isRecurring ?? false) ? ' · Recurring' : '' }}</span>
                </div>
                <div class="reg-card-body ofi-drf-form">
                    <div class="reg-field">
                        <label for="drfOriginatorName">Originator <span class="ofi-req">*</span></label>
                        <input type="text" id="drfOriginatorName" name="originatorName" value="{{ old('originatorName', $prefill['originatorName'] ?? '') }}" required placeholder="Separate multiple originators with a comma">
                        @if($isRecurring ?? false)
                            <div id="drfSuggestOriginator" class="ofi-suggest" hidden></div>
                        @endif
                    </div>
                    <div class="reg-grid-2-1">
                        @include('pages.dcs.office.partials.dcn-department-field', [
                            'userOfficeId' => $userOfficeId ?? 0,
                            'userOfficeCode' => $userOfficeCode ?? '',
                            'userOfficeName' => $userOfficeName ?? '',
                            'userOfficeLabel' => $userOfficeLabel ?? '',
                            'departmentFieldLabel' => 'Source unit',
                        ])
                        <div class="reg-field">
                            <label>Date <span class="ofi-req">*</span></label>
                            <input type="date" id="drfDate" name="drfDate" value="{{ old('drfDate') }}" required>
                        </div>
                    </div>
                    <div class="reg-field">
                        <label for="drfTitle">Document Title <span class="ofi-req">*</span></label>
                        <input type="text" id="drfTitle" name="drfTitle" value="{{ old('drfTitle', $prefill['drfTitle'] ?? '') }}" required placeholder="Enter document title">
                        @if($isRecurring ?? false)
                            <div id="drfSuggestTitle" class="ofi-suggest" hidden></div>
                        @endif
                    </div>
                    <div class="reg-field" id="ofiDrfDocTypeBlock">
                        <label>Type of document <span class="ofi-req">*</span></label>
                        @php
                            $lockDocType = (bool) ($prefill['lockDocType'] ?? false);
                            $docTypeKind = (string) ($oldDocTypeKind ?? '');
                            $selectedParent = (string) ($oldDocTypeId ?? '');
                            $selectedSub = (string) ($oldSubTypeId ?? '');
                            $parentKindMap = $sigTemplateMap['parentKind'] ?? [];
                            $optInRadios = $optInTypeRadios ?? [];
                            $parentsForKind = $docTypeKind === 'internal'
                                ? collect($docTypeParents ?? [])->filter(function ($type) use ($parentKindMap) {
                                    $k = $parentKindMap[(string) $type->id] ?? (str_contains(mb_strtolower((string) $type->doc_type_name), 'external') ? 'external' : 'internal');

                                    return $k === 'internal';
                                })->values()
                                : collect();
                            $parentSubs = ($selectedParent !== '' && isset($docTypeChildrenByParent[$selectedParent]))
                                ? $docTypeChildrenByParent[$selectedParent]
                                : [];
                            $showCatalog = $docTypeKind === 'internal';
                            $isOptInKind = in_array($docTypeKind, ['forms', 'logbooks'], true);
                        @endphp
                        @if($lockDocType)
                            <input type="hidden" name="docTypeKind" id="drfDocTypeKind" value="{{ $docTypeKind !== '' ? $docTypeKind : 'internal' }}">
                            <input type="hidden" name="docTypeId" id="drfDocTypeId" value="{{ $selectedParent }}">
                            <input type="hidden" name="subTypeId" id="drfSubTypeId" value="{{ $selectedSub }}">
                            <div class="ofi-radio-row">
                                <label class="ofi-radio"><input type="checkbox" disabled @checked($docTypeKind === 'internal')> Internal</label>
                                <label class="ofi-radio"><input type="checkbox" disabled @checked($docTypeKind === 'external')> External</label>
                                @foreach($optInRadios as $opt)
                                    <label class="ofi-radio"><input type="checkbox" disabled @checked($docTypeKind === $opt['slug'])> {{ $opt['label'] }}</label>
                                @endforeach
                            </div>
                            @if(!empty($prefill['docTypeLabel']))
                                <p class="ofi-hint">Document type: {{ $prefill['docTypeLabel'] }}</p>
                            @endif
                            <p class="ofi-hint">This follows the document being revised and cannot be changed. RFIO will register with this type.</p>
                        @else
                            <div class="ofi-radio-row" style="margin-top:6px;">
                                <label class="ofi-radio"><input type="radio" name="docTypeKind" value="internal" required @checked($docTypeKind === 'internal')> Internal</label>
                                <label class="ofi-radio"><input type="radio" name="docTypeKind" value="external" required @checked($docTypeKind === 'external')> External</label>
                                @foreach($optInRadios as $opt)
                                    <label class="ofi-radio">
                                        <input
                                            type="radio"
                                            name="docTypeKind"
                                            value="{{ $opt['slug'] }}"
                                            data-optin-doc-type-id="{{ $opt['id'] }}"
                                            required
                                            @checked($docTypeKind === $opt['slug'])
                                        > {{ $opt['label'] }}
                                    </label>
                                @endforeach
                            </div>
                            <input type="hidden" id="drfOptInDocTypeId" name="docTypeId" value="{{ $isOptInKind ? $selectedParent : '' }}" @disabled(! $isOptInKind)>
                            {{-- Catalog pickers only for Internal. Forms/Logbooks/External hide them. --}}
                            <div class="reg-grid-2{{ ! $showCatalog ? ' is-ofi-hidden' : '' }}" style="margin-top:10px;" id="ofiDrfCatalogPickers" @if(! $showCatalog) hidden @endif>
                                <div class="reg-field" style="margin:0;" id="ofiDrfDocTypeWrap">
                                    <label for="drfDocTypeId">Document type <span class="ofi-req">*</span></label>
                                    <select id="drfDocTypeId" name="docTypeId" @required($showCatalog) @disabled(! $showCatalog)>
                                        <option value="" disabled {{ $selectedParent === '' ? 'selected' : '' }} hidden>Select document type…</option>
                                        @foreach($parentsForKind as $type)
                                            <option value="{{ $type->id }}" @selected((string) $type->id === $selectedParent)>{{ $type->doc_type_name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="reg-field" style="margin:0;" id="ofiDrfSubTypeWrap">
                                    <label for="drfSubTypeId">Sub-type <span id="drfSubTypeReq" class="ofi-req" @if(count($parentSubs) < 1) hidden @endif>*</span></label>
                                    <select id="drfSubTypeId" name="subTypeId" @disabled(! $showCatalog || $selectedParent === '') @if($showCatalog && count($parentSubs) > 0) required @endif>
                                        <option value="" disabled {{ $selectedSub === '' ? 'selected' : '' }} hidden>{{ count($parentSubs) > 0 ? 'Select sub-type…' : ($selectedParent !== '' ? 'No sub-types' : 'Select a document type first') }}</option>
                                        @foreach($parentSubs as $sub)
                                            <option value="{{ $sub->id }}" @selected((string) $sub->id === $selectedSub)>{{ $sub->doc_type_name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <p id="drfRecurDocTypeHint" class="ofi-hint" hidden>This follows the registered document and cannot be changed after Find document.</p>
                        @endif
                        @php
                            $syllabiContextVisible = false;
                            if ($selectedSub !== '') {
                                $subName = '';
                                foreach (($docTypeChildrenByParent[$selectedParent] ?? []) as $subRow) {
                                    if ((string) ($subRow->id ?? '') === $selectedSub) {
                                        $subName = (string) ($subRow->doc_type_name ?? '');
                                        break;
                                    }
                                }
                                $syllabiContextVisible = \App\Helpers\RegisterQueryHelper::isSyllabiLikeName($subName);
                            }
                        @endphp
                        @include('pages.dcs.office.partials.drf-syllabi-context', [
                            'syllabiContextVisible' => $syllabiContextVisible,
                            'oldCollegeId' => old('collegeId', ''),
                            'oldProgramId' => old('programId', ''),
                            'oldSemesterId' => old('semesterId', ''),
                            'oldCourseId' => old('courseId', ''),
                            'oldSchoolYearId' => old('schoolYearId', ''),
                        ])
                    </div>
                    <div class="reg-field">
                        <label>Description/reason for request (define in detail) <span class="ofi-req">*</span></label>
                        <textarea id="descriptionReason" name="descriptionReason" rows="4" required maxlength="5000" placeholder="For document control and dissemination">{{ old('descriptionReason') }}</textarea>
                    </div>
                    <div class="reg-field ofi-distribute-field">
                        <label class="ofi-distribute-label">
                            <span>Distribute document to (department/position) <span class="ofi-req">*</span></span>
                            <span class="ofi-total-offices" aria-live="polite">
                                total offices: <strong data-ofi-selected-count="distribute">0</strong>
                            </span>
                        </label>
                        <div class="reg-cluster-chips" data-ofi-cluster-widget="distribute" aria-label="Select offices by cluster"></div>
                        @if(($fromDcnId ?? 0) > 0)
                            <p class="ofi-hint">These offices are the same as the previous distribution of the document being revised. You can add more offices.</p>
                        @elseif($isRecurring ?? false)
                            <p class="ofi-hint">After you find the document, distribution starts with that document’s offices. You can add more offices.</p>
                        @endif
                        <p class="ofi-hint">
                            Search by office name or code — selected offices show their <strong>full name</strong> here.
                            If more than 24 offices are selected, the print form shows “Please see attached list of offices” and lists them on following pages.
                            Click the <i class="fa-solid fa-chevron-down ofi-hint-icon"></i> arrow to view or remove selected offices.
                        </p>
                        <div class="reg-reldocs" id="drfDistributeWidget">
                            <div class="reg-reldocs-inputwrap">
                                <input type="text" id="drfDistributeSearch" class="reg-reldocs-input"
                                    placeholder="Type to search offices..." autocomplete="off">
                                <button type="button" class="reg-reldocs-arrow-btn" id="drfDistributeArrowBtn" title="View selected offices">
                                    <i class="fa-solid fa-chevron-down"></i>
                                    <span class="ofi-arrow-count" data-ofi-arrow-count="distribute" hidden>0</span>
                                </button>
                            </div>
                            <div id="drfDistributeResults" class="reg-reldocs-dropdown" style="display:none;"></div>
                            <div id="drfDistributeInlineChips" class="reg-reldocs-dropdown reg-reldocs-selected-panel" style="display:none;"></div>
                        </div>
                    </div>

                    @php
                        $templateSig = $sigPrefill ?? null;
                    @endphp
                    <div class="ofi-sig-block" id="ofiDrfSigBlock">
                        <p class="ofi-sig-heading">Signatories</p>
                        <div class="ofi-sig-group">
                            <p class="ofi-sig-label">Prepared by</p>
                            <div class="reg-grid-2">
                                <div class="reg-field">
                                    <label for="preparedByName">Name <span class="ofi-req">*</span></label>
                                    <input type="text" id="preparedByName" name="preparedByName"
                                        value="{{ old('preparedByName', $templateSig['prepared_by_name'] ?? '') }}"
                                        required maxlength="255"
                                        placeholder="Prepared by name"
                                        data-sig-key="prepared_by_name">
                                </div>
                                <div class="reg-field">
                                    <label for="preparedByDesignation">Designation <span class="ofi-req">*</span></label>
                                    <input type="text" id="preparedByDesignation" name="preparedByDesignation"
                                        value="{{ old('preparedByDesignation', $templateSig['prepared_by_designation'] ?? '') }}"
                                        required maxlength="255"
                                        placeholder="Prepared by designation"
                                        data-sig-key="prepared_by_designation">
                                </div>
                            </div>
                        </div>
                        <div class="ofi-sig-group">
                            <p class="ofi-sig-label">Reviewed by</p>
                            <div class="reg-grid-2">
                                <div class="reg-field">
                                    <label for="reviewedByName">Name <span class="ofi-req">*</span></label>
                                    <input type="text" id="reviewedByName" name="reviewedByName"
                                        value="{{ old('reviewedByName', $templateSig['reviewed_by_name'] ?? '') }}"
                                        required maxlength="255"
                                        placeholder="Reviewed by name"
                                        data-sig-key="reviewed_by_name">
                                </div>
                                <div class="reg-field">
                                    <label for="reviewedByDesignation">Designation <span class="ofi-req">*</span></label>
                                    <input type="text" id="reviewedByDesignation" name="reviewedByDesignation"
                                        value="{{ old('reviewedByDesignation', $templateSig['reviewed_by_designation'] ?? '') }}"
                                        required maxlength="255"
                                        placeholder="Reviewed by designation"
                                        data-sig-key="reviewed_by_designation">
                                </div>
                            </div>
                        </div>
                        <div class="ofi-sig-group">
                            <p class="ofi-sig-label">Approved by</p>
                            <div class="reg-grid-2">
                                <div class="reg-field">
                                    <label for="approvedByName">Name <span class="ofi-req">*</span></label>
                                    <input type="text" id="approvedByName" name="approvedByName"
                                        value="{{ old('approvedByName', $templateSig['approved_by_name'] ?? '') }}"
                                        required maxlength="255"
                                        placeholder="Approved by name"
                                        data-sig-key="approved_by_name">
                                </div>
                                <div class="reg-field">
                                    <label for="approvedByDesignation">Designation <span class="ofi-req">*</span></label>
                                    <input type="text" id="approvedByDesignation" name="approvedByDesignation"
                                        value="{{ old('approvedByDesignation', $templateSig['approved_by_designation'] ?? '') }}"
                                        required maxlength="255"
                                        placeholder="Approved by designation"
                                        data-sig-key="approved_by_designation">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="ofi-confirm-block">
                <label class="ofi-confirm-check">
                    <input type="checkbox" id="ofiConfirmData" name="confirmDataCorrect" value="1" required>
                    <span>I am confirming all the inputted data are correct.</span>
                </label>
            </div>

            <div class="reg-form-actions ofi-reg-actions">
                <button type="submit" id="ofiDrfPreviewBtn" class="reg-btn ofi-btn-preview" formnovalidate formtarget="_blank" formaction="{{ route('dcs.office.drf.preview', absolute: false) }}">
                    <i class="fa-solid fa-eye" aria-hidden="true"></i> Preview form
                </button>
                <button type="submit" id="ofiDrfSaveBtn" class="reg-btn reg-btn-save" disabled>
                    <i class="fa-solid fa-lock"></i> Save (cannot edit later)
                </button>
            </div>
        </form>
    </div>

    <div class="ofi-confirm-dialog-overlay" id="ofiAlertDialog" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="ofiAlertDialogTitle">
        <div class="ofi-confirm-dialog">
            <div class="ofi-confirm-dialog-header">
                <span class="ofi-confirm-dialog-icon" aria-hidden="true"><i class="fa-solid fa-triangle-exclamation"></i></span>
                <h3 id="ofiAlertDialogTitle">Cannot save</h3>
            </div>
            <div class="ofi-confirm-dialog-body" id="ofiAlertDialogMessage"></div>
            <div class="ofi-confirm-dialog-footer">
                <button type="button" class="ofi-confirm-dialog-btn ofi-confirm-dialog-btn-ok" data-ofi-alert-ok>OK</button>
            </div>
        </div>
    </div>
</div>

<script>
window.__ofiOffices = @json($offices);
window.__ofiClusters = @json($clusters);
window.__ofiOldDistribute = @json($oldDistributeOfficeIds);
window.__ofiDocTypeChildren = @json($docTypeChildrenByParent ?? []);
window.__ofiDocTypeParents = @json($docTypeParentsForJs ?? []);
window.__ofiSigTemplateMap = @json($sigTemplateMap);
window.__ofiSyllabiCatalog = @json($syllabiCatalog ?? []);
window.__ofiSourceConfigs = [
    {
        key: 'distribute',
        widgetId: 'drfDistributeWidget',
        inputId: 'drfDistributeSearch',
        arrowId: 'drfDistributeArrowBtn',
        resultsId: 'drfDistributeResults',
        chipsId: 'drfDistributeInlineChips',
        officeFieldName: 'distributeToOffice[]',
        oldIds: window.__ofiOldDistribute,
        seedDefaultOffice: false,
        labelFormat: 'name',
    },
];
</script>
<script src="{{ asset('js/dcs/office-intake.js') }}?v={{ @filemtime(public_path('js/dcs/office-intake.js')) ?: time() }}"></script>
<script>
(function () {
    const form = document.getElementById('ofiDrfForm');
    const confirmBox = document.getElementById('ofiConfirmData');
    const saveBtn = document.getElementById('ofiDrfSaveBtn');
    if (!form || !confirmBox || !saveBtn) return;

    function showOfiAlert(message, title) {
        const overlay = document.getElementById('ofiAlertDialog');
        if (!overlay) {
            window.alert(message);
            return;
        }
        if (overlay.parentElement !== document.body) {
            document.body.appendChild(overlay);
        }
        const titleEl = overlay.querySelector('#ofiAlertDialogTitle');
        const messageEl = overlay.querySelector('#ofiAlertDialogMessage');
        const okBtn = overlay.querySelector('[data-ofi-alert-ok]');
        if (titleEl) titleEl.textContent = title || 'Cannot save';
        if (messageEl) messageEl.textContent = message;
        function close() {
            overlay.classList.remove('is-open');
            overlay.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('ofi-modal-open');
            document.removeEventListener('keydown', onKey);
            overlay.removeEventListener('click', onBackdrop);
            if (okBtn) okBtn.removeEventListener('click', onOk);
        }
        function onOk() { close(); }
        function onBackdrop(e) { if (e.target === overlay) close(); }
        function onKey(e) { if (e.key === 'Escape' || e.key === 'Enter') close(); }
        overlay.addEventListener('click', onBackdrop);
        document.addEventListener('keydown', onKey);
        if (okBtn) okBtn.addEventListener('click', onOk);
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.classList.add('ofi-modal-open');
        if (okBtn) okBtn.focus();
    }

    function syncConfirm() {
        saveBtn.disabled = !confirmBox.checked;
        saveBtn.setAttribute('aria-disabled', confirmBox.checked ? 'false' : 'true');
    }

    confirmBox.addEventListener('change', syncConfirm);
    syncConfirm();

    const reason = document.getElementById('descriptionReason');
    if (reason) {
        reason.addEventListener('keydown', function (e) {
            if (e.key !== 'Tab' || e.shiftKey || reason.value.trim() !== '') return;
            e.preventDefault();
            reason.value = 'For document control and dissemination';
            const end = reason.value.length;
            reason.setSelectionRange(end, end);
            reason.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }

    const parentSelect = document.getElementById('drfDocTypeId');
    const subSelect = document.getElementById('drfSubTypeId');
    const subReq = document.getElementById('drfSubTypeReq');
    const catalogPickers = document.getElementById('ofiDrfCatalogPickers');
    const subTypeWrap = document.getElementById('ofiDrfSubTypeWrap');
    const optInDocTypeHidden = document.getElementById('drfOptInDocTypeId');
    const childrenByParent = window.__ofiDocTypeChildren || {};
    const allParents = window.__ofiDocTypeParents || [];
    const sigMap = window.__ofiSigTemplateMap || { byDocTypeId: {}, parentKind: {}, externalParentId: null };

    function selectedKind() {
        const checked = form.querySelector('input[name="docTypeKind"]:checked');
        if (checked) {
            const v = String(checked.value || '');
            if (['internal', 'external', 'forms', 'logbooks'].indexOf(v) !== -1) return v;
        }
        const hidden = form.querySelector('input[type="hidden"][name="docTypeKind"]');
        if (hidden) {
            const v = String(hidden.value || '');
            if (['internal', 'external', 'forms', 'logbooks'].indexOf(v) !== -1) return v;
        }
        return '';
    }

    function syncOptInDocTypeId() {
        if (!optInDocTypeHidden) return;
        const checked = form.querySelector('input[name="docTypeKind"]:checked');
        const kind = checked ? String(checked.value || '') : '';
        const isOptIn = kind === 'forms' || kind === 'logbooks';
        if (isOptIn) {
            const id = checked.getAttribute('data-optin-doc-type-id') || '';
            optInDocTypeHidden.disabled = false;
            optInDocTypeHidden.value = id;
        } else {
            optInDocTypeHidden.disabled = true;
            optInDocTypeHidden.value = '';
        }
    }

    function resolveTemplate() {
        const kind = selectedKind();
        const byId = sigMap.byDocTypeId || {};
        const byCollege = sigMap.byDocTypeCollege || {};
        const syllabiIds = sigMap.syllabiLikeDocTypeIds || [];
        const collegeSel = document.getElementById('ofiDrfCollegeId');
        const collegeId = collegeSel && !collegeSel.disabled ? String(collegeSel.value || '') : '';
        if (kind === 'external') {
            const externalId = sigMap.externalParentId ? String(sigMap.externalParentId) : '';
            return (externalId && byId[externalId]) ? byId[externalId] : null;
        }
        if (kind === 'forms' || kind === 'logbooks') {
            const checked = form.querySelector('input[name="docTypeKind"]:checked');
            const optInId = checked ? String(checked.getAttribute('data-optin-doc-type-id') || '') : '';
            if (optInId && byId[optInId]) return byId[optInId];
            return null;
        }
        // Internal catalog: signatories only after a sub-type is selected.
        // Changing document type (e.g. Internal Forms) with no sub-type clears them.
        const parentId = parentSelect ? String(parentSelect.value || '') : '';
        const subId = subSelect && !subSelect.disabled ? String(subSelect.value || '') : '';
        const kids = parentId && childrenByParent[parentId] ? childrenByParent[parentId] : [];
        if (kids.length > 0 && !subId) {
            return null;
        }
        function pickForDocType(docTypeId, requireCollege) {
            if (!docTypeId) return null;
            const isSyllabi = syllabiIds.indexOf(docTypeId) !== -1;
            if (isSyllabi || requireCollege) {
                // Syllabi / TOS: no signatories until a college is chosen.
                if (!collegeId) return null;
                if (byCollege[docTypeId] && byCollege[docTypeId][collegeId]) {
                    return byCollege[docTypeId][collegeId];
                }
                // College-specific missing → optional All-campuses default for that sub-type.
                if (byId[docTypeId]) return byId[docTypeId];
                return null;
            }
            if (byId[docTypeId]) return byId[docTypeId];
            return null;
        }
        if (subId) {
            const subIsSyllabi = syllabiIds.indexOf(subId) !== -1;
            // Prefer sub-type assignment; fall back to parent only for non-syllabi
            // (syllabi must resolve against the sub-type + college scope).
            if (subIsSyllabi) {
                return pickForDocType(subId, true);
            }
            return pickForDocType(subId, false) || pickForDocType(parentId, false);
        }
        // Parent with no sub-types in catalog: whole-type assignment is allowed.
        return pickForDocType(parentId, false);
    }

    function clearSignatories() {
        form.querySelectorAll('#ofiDrfSigBlock [data-sig-key]').forEach(function (input) {
            input.value = '';
            input.dataset.autoFilled = '0';
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }

    function applySignatories(payload) {
        clearSignatories();
        if (!payload) return;
        form.querySelectorAll('#ofiDrfSigBlock [data-sig-key]').forEach(function (input) {
            const key = input.getAttribute('data-sig-key');
            const next = payload[key] ? String(payload[key]) : '';
            input.value = next;
            input.dataset.autoFilled = next !== '' ? '1' : '0';
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }

    function syncCatalogVisibility() {
        const kind = selectedKind();
        // Only Internal shows Document type + Sub-type. Forms/Logbooks/External hide them.
        const showInternalCatalog = kind === 'internal';
        syncOptInDocTypeId();
        if (catalogPickers) {
            catalogPickers.hidden = !showInternalCatalog;
            catalogPickers.classList.toggle('is-ofi-hidden', !showInternalCatalog);
        }
        if (parentSelect && parentSelect.tagName === 'SELECT') {
            parentSelect.disabled = !showInternalCatalog;
            parentSelect.required = showInternalCatalog;
            if (!showInternalCatalog) parentSelect.value = '';
        }
        if (subSelect && subSelect.tagName === 'SELECT' && !showInternalCatalog) {
            subSelect.disabled = true;
            subSelect.required = false;
            subSelect.value = '';
            if (subReq) subReq.hidden = true;
        }
    }

    function rebuildParentOptions(keepValue) {
        if (!parentSelect || parentSelect.tagName !== 'SELECT') return;
        const kind = selectedKind();
        const showInternalCatalog = kind === 'internal';
        const previous = keepValue && showInternalCatalog ? String(parentSelect.value || '') : '';
        const list = showInternalCatalog
            ? allParents.filter(function (p) { return (p.kind || 'internal') === 'internal'; })
            : [];
        parentSelect.innerHTML = '';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Select document type…';
        placeholder.disabled = true;
        placeholder.hidden = true;
        parentSelect.appendChild(placeholder);
        list.forEach(function (p) {
            const opt = document.createElement('option');
            opt.value = String(p.id);
            opt.textContent = p.name || ('Type #' + p.id);
            if (previous && previous === String(p.id)) opt.selected = true;
            parentSelect.appendChild(opt);
        });
        if (previous && list.some(function (p) { return String(p.id) === previous; })) {
            parentSelect.value = previous;
        } else {
            placeholder.selected = true;
        }
    }

    function rebuildSubOptions(keepValue) {
        if (!parentSelect || !subSelect || parentSelect.tagName !== 'SELECT') return;
        const kind = selectedKind();
        const showInternalCatalog = kind === 'internal';
        const parentId = String(parentSelect.value || '');
        const kids = showInternalCatalog && parentId && childrenByParent[parentId] ? childrenByParent[parentId] : [];
        const previous = keepValue && showInternalCatalog ? String(subSelect.value || '') : '';
        subSelect.innerHTML = '';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = kids.length ? 'Select sub-type…' : (parentId ? 'No sub-types' : 'Select a document type first');
        placeholder.disabled = true;
        placeholder.hidden = true;
        subSelect.appendChild(placeholder);
        if (showInternalCatalog) {
            kids.forEach(function (sub) {
                const opt = document.createElement('option');
                opt.value = String(sub.id);
                opt.textContent = sub.doc_type_name || ('Type #' + sub.id);
                if (previous && previous === String(sub.id)) opt.selected = true;
                subSelect.appendChild(opt);
            });
        }
        subSelect.disabled = !showInternalCatalog || !parentId;
        if (showInternalCatalog && kids.length) {
            subSelect.required = true;
            if (subReq) subReq.hidden = false;
            if (previous && kids.some(function (sub) { return String(sub.id) === previous; })) {
                subSelect.value = previous;
            } else {
                placeholder.selected = true;
            }
        } else {
            subSelect.required = false;
            placeholder.selected = true;
            if (subReq) subReq.hidden = true;
        }
    }

    function refreshSignatoriesForSelection() {
        applySignatories(resolveTemplate());
    }
    window.__ofiRefreshDrfSignatories = refreshSignatoriesForSelection;

    form.querySelectorAll('input[name="docTypeKind"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            syncCatalogVisibility();
            rebuildParentOptions(false);
            rebuildSubOptions(false);
            refreshSignatoriesForSelection();
            if (typeof window.__ofiSyncSyllabiContext === 'function') window.__ofiSyncSyllabiContext();
        });
    });
    syncOptInDocTypeId();
    if (parentSelect && parentSelect.tagName === 'SELECT') {
        parentSelect.addEventListener('change', function () {
            rebuildSubOptions(false);
            // Document type change clears sub-type → clear signatories until a new sub-type is picked.
            refreshSignatoriesForSelection();
            if (typeof window.__ofiSyncSyllabiContext === 'function') window.__ofiSyncSyllabiContext();
        });
    }
    if (subSelect && subSelect.tagName === 'SELECT') {
        subSelect.addEventListener('change', function () {
            // Always reset then apply the template for the newly selected sub-type.
            refreshSignatoriesForSelection();
            if (typeof window.__ofiSyncSyllabiContext === 'function') window.__ofiSyncSyllabiContext();
        });
    }

    syncCatalogVisibility();
    if (typeof window.__ofiSyncSyllabiContext === 'function') window.__ofiSyncSyllabiContext();
    refreshSignatoriesForSelection();

    form.addEventListener('submit', function (e) {
        if (e.submitter && e.submitter.id === 'ofiDrfPreviewBtn') return;
        if (!confirmBox.checked) {
            e.preventDefault();
            showOfiAlert('Please confirm that all the inputted data are correct before saving.');
            return;
        }

        const distWidget = window.__sourceWidgets && window.__sourceWidgets.distribute;
        // Re-seed from last finder match if the widget somehow lost its selection.
        const seedCsv = String(form.dataset.ofiSeedDistributeIds || '').trim();
        if (distWidget && (!distWidget.selected || distWidget.selected.length === 0) && seedCsv !== '') {
            const seedIds = seedCsv.split(',').map(Number).filter(function (id) { return id > 0; });
            if (seedIds.length && typeof distWidget.seedQuiet === 'function') {
                distWidget.seedQuiet(seedIds);
            }
        }
        if (distWidget && typeof distWidget.syncHiddenInputs === 'function') {
            distWidget.syncHiddenInputs();
        }
        let selected = form.querySelectorAll('input[name="distributeToOffice[]"]');
        let selectedCount = selected.length
            || (distWidget && distWidget.selected ? distWidget.selected.length : 0);
        if (!selectedCount) {
            // Last resort: rebuild hiddens from backup CSV / seed dataset.
            const backup = form.querySelector('input[name="distributeToOfficeBackup"]');
            const csv = String((backup && backup.value) || seedCsv || '').trim();
            const ids = csv.split(',').map(Number).filter(function (id) { return id > 0; });
            ids.forEach(function (id) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'distributeToOffice[]';
                input.dataset.sourceHidden = 'true';
                input.dataset.ofiKey = 'distribute';
                input.value = String(id);
                form.appendChild(input);
            });
            selected = form.querySelectorAll('input[name="distributeToOffice[]"]');
            selectedCount = selected.length;
        }
        if (!selectedCount) {
            e.preventDefault();
            showOfiAlert('Select at least one office to distribute the document to.');
            return;
        }

        if (!form.checkValidity()) {
            return;
        }

        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    });

    if (@json(!empty($templateSig))) {
        form.querySelectorAll('#ofiDrfSigBlock [data-sig-key]').forEach(function (input) {
            if (input.value.trim() !== '') input.dataset.autoFilled = '1';
        });
    }
})();
</script>
@include('pages.dcs.office.partials.drf-syllabi-context-script')
@if($isRecurring ?? false)
    @include('pages.dcs.office.partials.drf-recur-script')
@endif

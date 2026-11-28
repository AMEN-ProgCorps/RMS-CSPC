{{--
  DCS PAGE: Office view DRF
  FILE: resources/views/pages/dcs/office/drf-show.blade.php
  DEBUG: OfficeIntakeHelper::findOfficeDrf
--}}
<?php

/**
 * DCS file: One office DRF. Print is locked until RFIU sends the print-and-sign notice, then print-unlock-watch.blade.php enables it without a reload.
 */

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('CSPC - Document Control System')] class extends Component {
    #[Locked]
    public int $id;

    /**
     * Initializes component state, authorization gates, and default configurations.
     *
     * @param mixed $id
     *
     * @return void
     */
    public function mount($id): void
    {
        OfficeIntakeHelper::assertCanAccessIntake();
        $this->id = (int) $id;

        if (RegisterQueryHelper::canBrowseAllOfficeIntake()) {
            $this->redirect(route('dcs.requests.show', ['type' => 'drf', 'id' => $this->id], absolute: false));

            return;
        }

        $drf = OfficeIntakeHelper::findOfficeDrf($this->id);
        abort_unless($drf, 404);
        OfficeIntakeHelper::assertOwnsDrf($drf);
    }

    /**
     * Supplies view data, query collections, and metrics to the Blade template.
     * @return array
     */
    public function with(): array
    {
        $drf = OfficeIntakeHelper::findOfficeDrf($this->id);
        abort_unless($drf, 404);
        if (! RegisterQueryHelper::canBrowseAllOfficeIntake()) {
            OfficeIntakeHelper::assertOwnsDrf($drf);
        }

        $distributeOffices = collect(OfficeIntakeHelper::drfDistributeOffices($drf))
            ->filter(fn ($o) => ($o['code'] ?? '') !== '' || ($o['name'] ?? '') !== '')
            ->values()
            ->all();

        $sourceOffice = OfficeIntakeHelper::drfSourceOffices((int) $drf->id)
            ->map(fn ($row) => trim((string) ($row->office_name ?? '')) ?: trim((string) ($row->office_code ?? '')))
            ->filter()
            ->unique()
            ->implode(', ');
        if ($sourceOffice === '') {
            $meta = OfficeIntakeHelper::intakeSubmissionMeta($drf, 'drf', (int) $drf->id);
            $sourceOffice = (string) ($meta['office'] ?? '');
        }

        $batchItems = OfficeIntakeHelper::drfBatchItems((int) $drf->id);
        if (count($batchItems) > 1 && \Illuminate\Support\Facades\Schema::hasTable('dcs_program_courses')) {
            $cIds = array_values(array_unique(array_filter(array_map(fn ($i) => (int) ($i['course_id'] ?? 0), $batchItems))));
            $courseMap = $cIds !== []
                ? \Illuminate\Support\Facades\DB::table('dcs_program_courses')->whereIn('id', $cIds)->get(['id', 'course_code', 'course_name', 'year_level'])->keyBy('id')
                : collect();
            $batchItems = array_map(function (array $item) use ($courseMap) {
                $c = $courseMap->get((int) ($item['course_id'] ?? 0));
                $item['course_code'] = trim((string) ($c->course_code ?? ''));
                $item['course_name'] = trim((string) ($c->course_name ?? ''));
                $item['year_level'] = trim((string) ($c->year_level ?? ''));

                return $item;
            }, $batchItems);
        }

        return [
            'drf' => $drf,
            'batchItems' => $batchItems,
            'distributeOffices' => $distributeOffices,
            'departmentLabel' => $sourceOffice !== '' ? $sourceOffice : '—',
            'immutableMessage' => OfficeIntakeHelper::IMMUTABLE_MESSAGE,
            'isIntakeReviewer' => RegisterQueryHelper::canBrowseAllOfficeIntake(),
            'canEdit' => OfficeIntakeHelper::canOfficeEditIntake('drf', $this->id),
            'editReason' => trim((string) ($drf->edit_unlock_reason ?? '')),
            'isRegistered' => OfficeIntakeHelper::isIntakeRegistered('drf', $this->id),
            'canPrint' => OfficeIntakeHelper::officeMayPrintIntake($drf),
            'printHint' => 'Print stays locked until RFIU confirms this form and notifies you to print and sign.',
        ];
    }
}; ?>

@php
    $originator = trim((string) ($drf->originator_name ?? ''));
    $kind = strtolower(trim((string) ($drf->doc_type_kind ?? '')));
    $docTypeBadges = [
        ['slug' => 'internal', 'label' => 'Internal', 'active' => $kind === 'internal'],
        ['slug' => 'external', 'label' => 'External', 'active' => $kind === 'external'],
    ];
    foreach ([['forms', 'Forms'], ['logbooks', 'Logbooks']] as [$slug, $label]) {
        $enabled = \App\Helpers\OfficeIntakeHelper::isOfficeDrfParentEnabled($label);
        if (! $enabled && $kind !== $slug) {
            continue;
        }
        $docTypeBadges[] = [
            'slug' => $slug,
            'label' => $label,
            'active' => $kind === $slug,
        ];
    }
    $drfDate = $drf->drf_date
        ? \App\Helpers\RegisterQueryHelper::formatSmartDate($drf->drf_date)
        : '—';
    $docTypeId = (int) ($drf->doc_type_id ?? 0);
    $subTypeId = (int) ($drf->sub_type_id ?? 0);
    $docTypeName = $docTypeId > 0
        ? trim((string) (\Illuminate\Support\Facades\DB::table('dcs_doc_types')->where('id', $docTypeId)->value('doc_type_name') ?? ''))
        : '';
    $subTypeName = $subTypeId > 0
        ? trim((string) (\Illuminate\Support\Facades\DB::table('dcs_doc_types')->where('id', $subTypeId)->value('doc_type_name') ?? ''))
        : '';
    $showCatalogTypes = $kind === 'internal' && ($docTypeName !== '' || $subTypeName !== '');
@endphp

<div class="ofi-page">
    <div class="ofi-inner">
        <div class="ofi-show-toolbar">
            <a href="{{ ($isIntakeReviewer ?? false) ? route('dcs', absolute: false) : route('dcs.office.drf.index', absolute: false) }}" class="reg-btn reg-btn-cancel">
                <i class="fa-solid fa-arrow-left"></i> {{ ($isIntakeReviewer ?? false) ? 'Back to DCS' : 'Back to list' }}
            </a>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                @if($canEdit ?? false)
                    <a href="{{ route('dcs.office.drf.edit', $drf->id, absolute: false) }}" class="reg-btn reg-btn-save">
                        <i class="fa-solid fa-pen"></i> Edit form
                    </a>
                @endif
                @if($canPrint ?? false)
                    <a href="{{ route('dcs.office.drf.print', $drf->id, absolute: false) }}" target="_blank" class="reg-btn reg-btn-save">
                        <i class="fa-solid fa-print"></i> Print form
                    </a>
                @else
                    <span id="ofiPrintControl" class="reg-btn reg-btn-save ofi-print-locked" title="{{ $printHint }}"
                        data-locked="1"
                        data-intake-id="{{ $drf->id }}"
                        data-status-url="{{ route('dcs.api.office-intake.print-ready', ['type' => 'drf', 'ids' => $drf->id], false) }}"
                        data-print-url="{{ route('dcs.office.drf.print', $drf->id, false) }}">
                        <i class="fa-solid fa-print"></i> Print form
                    </span>
                @endif
            </div>
        </div>


        @if(($canEdit ?? false) && ($editReason ?? '') !== '')
            <div class="ofi-alert err ofi-correction-alert" role="status">
                <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                <div class="ofi-alert-body">
                    <strong>RFIU asked for corrections</strong>
                    <span>{!! \App\Helpers\OfficeIntakeHelper::linkifyHtml($editReason) !!}</span>
                </div>
            </div>
        @endif

        @unless($canPrint ?? false)
            <div class="ofi-alert ofi-print-hint-alert" id="ofiPrintHint" role="status">
                <i class="fa-solid fa-print" aria-hidden="true"></i>
                <div class="ofi-alert-body">
                    <strong>Print is locked</strong>
                    <span>{{ $printHint }}</span>
                </div>
            </div>
        @endunless

        <div class="ofi-lock-banner {{ ($isRegistered ?? false) ? 'is-registered' : (($canEdit ?? false) ? 'is-edit' : '') }}" role="status">
            @if($isRegistered ?? false)
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <div class="ofi-lock-banner-body">
                    <strong>Registered by RFIU</strong>
                    <span>This document has been registered / controlled by RFIU.</span>
                </div>
            @elseif($canEdit ?? false)
                <i class="fa-solid fa-unlock" aria-hidden="true"></i>
                <div class="ofi-lock-banner-body">
                    <strong>Editing enabled</strong>
                    <span>RFIU enabled editing so you can correct and resubmit this form.</span>
                </div>
            @else
                <i class="fa-solid fa-lock" aria-hidden="true"></i>
                <div class="ofi-lock-banner-body">
                    <strong>View only</strong>
                    <span>{{ $immutableMessage }}</span>
                </div>
            @endif
        </div>

        @php
            $isBatchShow = is_array($batchItems ?? null) && count($batchItems) > 1;
        @endphp
        <section class="reg-card ofi-show-card">
            <div class="reg-card-header">
                <span>Document Request Form @if($isBatchShow)<span style="margin-left:8px;padding:2px 9px;border-radius:999px;background:#e8f0fe;color:#0d2a7a;font-size:11px;font-weight:700;">Batch · {{ count($batchItems) }} DRFs</span>@endif</span>
                <span class="ofi-form-code-badge">{{ \App\Helpers\DcsPrintTemplateHelper::get('office_drf')->form_code ?? 'CSPC-F-DCC-06' }}</span>
            </div>
            <div class="reg-card-body ofi-drf-form ofi-show-form">
                <div class="reg-field">
                    <label>Type of document</label>
                    <div class="ofi-doc-type-badges" aria-label="Type of document">
                        @foreach($docTypeBadges as $badge)
                            <span class="ofi-doc-type-badge @if(!empty($badge['active'])) is-active @endif">{{ $badge['label'] }}</span>
                        @endforeach
                    </div>
                    @if($showCatalogTypes)
                        <div class="ofi-readonly-type-meta" aria-label="Document type and sub-type">
                            @if($docTypeName !== '')
                                <div class="ofi-readonly-type-card">
                                    <span class="ofi-readonly-type-label">Document type</span>
                                    <div class="ofi-readonly-type-value">{{ $docTypeName }}</div>
                                </div>
                            @endif
                            @if($subTypeName !== '')
                                <div class="ofi-readonly-type-card">
                                    <span class="ofi-readonly-type-label">Sub-type</span>
                                    <div class="ofi-readonly-type-value">{{ $subTypeName }}</div>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

                @php
                    $syllabiCtx = \App\Helpers\OfficeIntakeHelper::officeDrfSyllabiContextFromRow($drf);
                @endphp
                @if(!empty($syllabiCtx['is_syllabi']))
                    @php
                        $sylCourseType = trim((string) ($syllabiCtx['course_type'] ?? ''));
                        $sylIsShared = \App\Helpers\CourseTypeRules::isSharedType($sylCourseType);
                    @endphp
                    <div class="reg-grid-3" style="margin-top:8px;">
                        <div class="reg-field">
                            <label>Course Type</label>
                            <div class="ofi-show-value">{{ $sylCourseType !== '' ? $sylCourseType : '—' }}</div>
                        </div>
                        <div class="reg-field">
                            <label>College</label>
                            <div class="ofi-show-value">{{ $syllabiCtx['college'] ?: '—' }}</div>
                        </div>
                        @unless($sylIsShared)
                            <div class="reg-field">
                                <label>Program</label>
                                <div class="ofi-show-value">{{ $syllabiCtx['program'] ?: '—' }}</div>
                            </div>
                        @endunless
                        <div class="reg-field">
                            <label>Semester</label>
                            <div class="ofi-show-value">{{ $syllabiCtx['semester'] ?: '—' }}</div>
                        </div>
                        <div class="reg-field">
                            <label>School year</label>
                            <div class="ofi-show-value">{{ $syllabiCtx['school_year'] ?: '—' }}</div>
                        </div>
                        @unless($isBatchShow)
                        <div class="reg-field">
                            <label>Course name</label>
                            <div class="ofi-show-value">{{ $syllabiCtx['course'] ?: '—' }}</div>
                        </div>
                        @endunless
                    </div>
                @endif

                @php
                    $previousDocNo = trim((string) ($drf->previous_document_no ?? ''));
                    $previousTitle = trim((string) ($drf->previous_document_title ?? ''));
                    $previousOriginator = trim((string) ($drf->previous_originator ?? ''));
                    $isRecurringShow = $previousDocNo !== '' || $previousTitle !== '' || $previousOriginator !== '';
                @endphp
                @if($isRecurringShow)
                    <div class="reg-field ofi-recur-source">
                        <label>Recurring from registered document</label>
                        <div class="ofi-dcn-selected ofi-show-recur-card" style="margin-top:6px;">
                            <div class="ofi-dcn-selected-head">
                                <span>Selected document (recur source)</span>
                            </div>
                            <dl class="ofi-dcn-selected-meta">
                                <div>
                                    <dt>Document no.</dt>
                                    <dd>{{ $previousDocNo !== '' ? $previousDocNo : '—' }}</dd>
                                </div>
                                <div>
                                    <dt>Title</dt>
                                    <dd>{{ $previousTitle !== '' ? $previousTitle : '—' }}</dd>
                                </div>
                                <div>
                                    <dt>Originator</dt>
                                    <dd>{{ $previousOriginator !== '' ? $previousOriginator : '—' }}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                @endif

                @if($isBatchShow)
                    <div class="reg-field" style="margin-top:12px;">
                        <label>Individual DRFs in this batch ({{ count($batchItems) }} {{ !empty($syllabiCtx['is_syllabi']) ? 'courses' : 'DRFs' }})</label>
                        <div style="display:flex;flex-direction:column;gap:12px;margin-top:6px;">
                            @foreach($batchItems as $bIdx => $bItem)
                                <div style="border:1.5px solid #dbe6f7;border-radius:10px;background:#f8fbff;padding:12px 14px;">
                                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid #e2ecf8;">
                                        <span style="padding:2px 9px;border-radius:999px;background:#0d2a7a;color:#fff;font-size:11px;font-weight:700;">DRF #{{ $bIdx + 1 }}</span>
                                        @if(!empty($bItem['course_code']) || !empty($bItem['course_name']))
                                            <strong style="font-size:0.9rem;color:#0f172a;">
                                                @if(!empty($bItem['course_code'])){{ $bItem['course_code'] }} — @endif{{ $bItem['course_name'] }}
                                            </strong>
                                        @endif
                                        @if(!empty($bItem['year_level']))
                                            <span style="margin-left:auto;padding:2px 8px;border-radius:999px;background:#e2e8f0;color:#334155;font-size:11px;font-weight:600;">{{ $bItem['year_level'] }}</span>
                                        @endif
                                    </div>
                                    <div style="display:flex;flex-direction:column;gap:8px;">
                                        <div class="reg-field" style="margin:0;">
                                            <label>Document Title</label>
                                            <div class="ofi-show-value" style="background:#fff;">{{ ($bItem['drfTitle'] ?? '') !== '' ? $bItem['drfTitle'] : '—' }}</div>
                                        </div>
                                        <div class="reg-field" style="margin:0;">
                                            <label>Originator</label>
                                            <div class="ofi-show-value" style="background:#fff;"><x-dcs.originator-lines :name="$bItem['originatorName'] ?? ''" /></div>
                                        </div>
                                        <div class="reg-field" style="margin:0;">
                                            <label>Description/reason for request</label>
                                            <div class="ofi-show-value is-multiline" style="background:#fff;">{{ ($bItem['descriptionReason'] ?? '') !== '' ? $bItem['descriptionReason'] : '—' }}</div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="reg-field">
                        <label>Document Title</label>
                        <div class="ofi-show-value">{{ $drf->doc_title ?: '—' }}</div>
                    </div>
                    <div class="reg-field">
                        <label>Originator</label>
                        <div class="ofi-show-value"><x-dcs.originator-lines :name="$originator" /></div>
                    </div>
                @endif

                <div class="reg-grid-2-1">
                    <div class="reg-field">
                        <label>Source unit</label>
                        <div class="ofi-show-value">{{ $departmentLabel ?: '—' }}</div>
                    </div>
                    <div class="reg-field">
                        <label>Date</label>
                        <div class="ofi-show-value">{{ $drfDate }}</div>
                    </div>
                </div>

                @unless($isBatchShow)
                <div class="reg-field">
                    <label>Description/reason for request (define in detail)</label>
                    <div class="ofi-show-value is-multiline">{{ $drf->description_reason ?: '—' }}</div>
                </div>
                @endunless

                <div class="reg-field">
                    <label class="ofi-distribute-label">
                        <span>Distribute document to (department/position)</span>
                        <span class="ofi-total-offices">
                            total offices: <strong>{{ count($distributeOffices) }}</strong>
                        </span>
                    </label>
                    <p class="ofi-field-hint" style="margin:0 0 8px;font-size:0.82rem;color:#64748b;line-height:1.4;">
                        All offices below are for <strong>distribution</strong> of this document.
                    </p>
                    @if(!empty($distributeOffices))
                        <div class="ofi-show-chips">
                            @foreach($distributeOffices as $office)
                                <span class="ofi-show-chip" title="{{ $office['name'] ?: $office['code'] }}">
                                    @if($office['code'] !== '')
                                        <span class="ofi-show-chip-code">{{ $office['code'] }}</span>
                                    @endif
                                    @if($office['name'] !== '')
                                        <span class="ofi-show-chip-name">{{ $office['name'] }}</span>
                                    @endif
                                </span>
                            @endforeach
                        </div>
                    @else
                        <div class="ofi-show-value is-empty">—</div>
                    @endif
                </div>

                @php $drfReviewLink = \App\Helpers\OfficeIntakeHelper::intakeReviewLink('drf', (int) $drf->id); @endphp
                @if($drfReviewLink !== '')
                    <div class="reg-field">
                        <label>Document link for review</label>
                        <div class="ofi-show-value">
                            <a href="{{ $drfReviewLink }}" target="_blank" rel="noopener noreferrer" style="color:#0d2a7a;font-weight:600;text-decoration:underline;word-break:break-all;">
                                <i class="fa-solid fa-arrow-up-right-from-square" style="margin-right:5px;font-size:0.8em;"></i>{{ $drfReviewLink }}
                            </a>
                        </div>
                    </div>
                @endif

                <div class="ofi-sig-block ofi-sig-block--show">
                    <p class="ofi-sig-heading">Signatories</p>
                    <div class="ofi-sig-group">
                        <p class="ofi-sig-label">Prepared by</p>
                        <div class="reg-grid-2">
                            <div class="reg-field">
                                <label>Name</label>
                                <div class="ofi-show-value">{{ trim((string) data_get($drf, 'prepared_by_name', '')) ?: '—' }}</div>
                            </div>
                            <div class="reg-field">
                                <label>Designation</label>
                                <div class="ofi-show-value">{{ trim((string) data_get($drf, 'prepared_by_designation', '')) ?: '—' }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="ofi-sig-group">
                        <p class="ofi-sig-label">Reviewed by</p>
                        <div class="reg-grid-2">
                            <div class="reg-field">
                                <label>Name</label>
                                <div class="ofi-show-value">{{ trim((string) data_get($drf, 'reviewed_by_name', '')) ?: '—' }}</div>
                            </div>
                            <div class="reg-field">
                                <label>Designation</label>
                                <div class="ofi-show-value">{{ trim((string) data_get($drf, 'reviewed_by_designation', '')) ?: '—' }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="ofi-sig-group">
                        <p class="ofi-sig-label">Approved by</p>
                        <div class="reg-grid-2">
                            <div class="reg-field">
                                <label>Name</label>
                                <div class="ofi-show-value">{{ trim((string) data_get($drf, 'approved_by_name', '')) ?: '—' }}</div>
                            </div>
                            <div class="reg-field">
                                <label>Designation</label>
                                <div class="ofi-show-value">{{ trim((string) data_get($drf, 'approved_by_designation', '')) ?: '—' }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>
@include('pages.dcs.office.partials.print-unlock-watch')

<?php

use App\Helpers\SyllabiMonitoringHelper;
use App\Helpers\RegisterQueryHelper;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('CSPC - Document Control System')] class extends Component {
    public string $collegeId = '';
    public string $schoolYearId = '';
    public string $semesterId = '';
    public string $deadline = '';

    public function saveRemark(int $programId, string $section, string $status): void
    {
        RegisterQueryHelper::assertFullDcsUser();
        if ($this->collegeId === '' || $this->schoolYearId === '' || $this->semesterId === '') {
            return;
        }

        SyllabiMonitoringHelper::saveStatus(
            (int) $this->collegeId,
            (int) $this->schoolYearId,
            (int) $this->semesterId,
            $programId,
            $section,
            $this->deadline !== '' ? $this->deadline : SyllabiMonitoringHelper::OVERALL_DEADLINE,
            $status
        );
    }

    public function with(): array
    {
        $collegeId = $this->collegeId !== '' ? (int) $this->collegeId : null;
        $schoolYearId = $this->schoolYearId !== '' ? (int) $this->schoolYearId : null;
        $semesterId = $this->semesterId !== '' ? (int) $this->semesterId : null;
        $deadline = $this->deadline !== '' ? $this->deadline : null;

        $deadlines = ($collegeId && $schoolYearId && $semesterId)
            ? SyllabiMonitoringHelper::availableDeadlines($collegeId, $schoolYearId, $semesterId)
            : [];

        if ($deadline && ! in_array($deadline, $deadlines, true)) {
            $this->deadline = '';
            $deadline = null;
        }

        return [
            'colleges' => DB::table('dcs_colleges')->orderBy('college_name')->get(['id', 'college_code', 'college_name']),
            'schoolYears' => DB::table('dcs_school_years')->orderBy('school_year', 'desc')->get(['id', 'school_year']),
            'semesters' => DB::table('dcs_semesters')->orderBy('id')->get(['id', 'semester_name']),
            'deadlines' => $deadlines,
            'report' => SyllabiMonitoringHelper::build($collegeId, $schoolYearId, $semesterId, $deadline),
        ];
    }
}; ?>

<main class="rpt-page" id="rptPage" x-data="{
    openSyllabi: false,
    openSyllabiDrf: false,
    openTos: false,
    openTosDrf: false,
    cols(open, full) { return open ? full : 1; },
    hasLeaf() { return this.openSyllabi || this.openSyllabiDrf || this.openTos || this.openTosDrf; },
    groupRowspan(open) { return open ? 1 : (this.hasLeaf() ? 2 : 1); },
    remarkRowspan() { return this.hasLeaf() ? 2 : 1; },
    syllabiSpan() { return this.cols(this.openSyllabi, 8) + this.cols(this.openSyllabiDrf, 6) + 1; },
    tosSpan() { return this.cols(this.openTos, 8) + this.cols(this.openTosDrf, 6) + 1; },
}">
    <header class="rpt-hdr">
        <div>
            <div class="rpt-crumb">Document Control System / Generate Report /<span> Syllabi &amp; TOS/Rubrics</span></div>
            <h1>Monitoring of Syllabi &amp; TOS/Rubrics Submission</h1>
        </div>
    </header>

    <section class="rpt-inline-filters">
        <div class="rpt-inline-filters-grid">
            <div class="rpt-filter-group">
                <label>College</label>
                <select wire:model.live="collegeId">
                    <option value="">Select college</option>
                    @foreach($colleges as $college)
                        <option value="{{ $college->id }}">{{ $college->college_code }} — {{ $college->college_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="rpt-filter-group">
                <label>Academic Year</label>
                <select wire:model.live="schoolYearId">
                    <option value="">Select academic year</option>
                    @foreach($schoolYears as $year)
                        <option value="{{ $year->id }}">{{ $year->school_year }}</option>
                    @endforeach
                </select>
            </div>
            <div class="rpt-filter-group">
                <label>Semester</label>
                <select wire:model.live="semesterId">
                    <option value="">Select semester</option>
                    @foreach($semesters as $semester)
                        <option value="{{ $semester->id }}">{{ $semester->semester_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="rpt-filter-group">
                <label>Deadline (from syllabi)</label>
                <select wire:model.live="deadline" @disabled(count($deadlines) === 0)>
                    <option value="">All deadlines</option>
                    @foreach($deadlines as $d)
                        <option value="{{ $d }}">{{ \Carbon\Carbon::parse($d)->format('M d, Y') }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </section>

    @if(! $report['ready'])
        <section class="rpt-state-pick">
            <p class="rpt-template-status">Select a college, academic year, and semester to load the monitoring table. Pick a syllabus deadline to filter that cohort and save remarks.</p>
        </section>
    @else
        <section class="rpt-results">
            <div class="rpt-results-head">
                <div class="rpt-results-meta">
                    <h3>{{ $report['meta']['college'] }} · {{ $report['meta']['school_year'] }} · {{ $report['meta']['semester'] }}</h3>
                    <span class="rpt-results-count">
                        {{ count($report['rows']) }} programs
                        @if($report['meta']['deadline'])
                            · Deadline {{ $report['meta']['deadline'] }}
                        @elseif(count($deadlines) === 0)
                            · No syllabus deadlines registered yet
                        @else
                            · All deadlines
                        @endif
                    </span>
                </div>
                <div class="rpt-results-actions">
                    <button class="rpt-btn rpt-btn-outline" type="button" onclick="window.print()">
                        <i class="fa-solid fa-print"></i> Print
                    </button>
                </div>
            </div>

            <div class="rpt-table-scroll mon-table-wrap mon-table-wrap--simple" :class="{ 'is-all-collapsed': !hasLeaf() }">
                <table class="rpt-table mon-table mon-table--simple">
                    <thead>
                        <tr class="mon-head-primary">
                            <th class="mon-sticky" :rowspan="hasLeaf() ? 3 : 2">Programs</th>
                            <th :colspan="syllabiSpan()">SYLLABI</th>
                            <th class="mon-section-gap" :rowspan="hasLeaf() ? 3 : 2" aria-hidden="true"></th>
                            <th :colspan="tosSpan()">TOS/RUBRICS</th>
                        </tr>
                        <tr class="mon-head-secondary">
                            <th
                                class="mon-group-cell"
                                :class="{ 'is-collapsed': !openSyllabi }"
                                :colspan="cols(openSyllabi, 8)"
                                :rowspan="groupRowspan(openSyllabi)"
                                @click="openSyllabi = !openSyllabi"
                            >
                                <span class="mon-group-toggle">
                                    <span x-text="openSyllabi ? '▼' : '▶'"></span>
                                    <span>Syllabi</span>
                                </span>
                            </th>
                            <th
                                class="mon-group-cell"
                                :class="{ 'is-collapsed': !openSyllabiDrf }"
                                :colspan="cols(openSyllabiDrf, 6)"
                                :rowspan="groupRowspan(openSyllabiDrf)"
                                @click="openSyllabiDrf = !openSyllabiDrf"
                            >
                                <span class="mon-group-toggle">
                                    <span x-text="openSyllabiDrf ? '▼' : '▶'"></span>
                                    <span>DRF</span>
                                </span>
                            </th>
                            <th class="mon-remark-head" :rowspan="remarkRowspan()">Remarks</th>
                            <th
                                class="mon-group-cell"
                                :class="{ 'is-collapsed': !openTos }"
                                :colspan="cols(openTos, 8)"
                                :rowspan="groupRowspan(openTos)"
                                @click="openTos = !openTos"
                            >
                                <span class="mon-group-toggle">
                                    <span x-text="openTos ? '▼' : '▶'"></span>
                                    <span>TOS</span>
                                </span>
                            </th>
                            <th
                                class="mon-group-cell"
                                :class="{ 'is-collapsed': !openTosDrf }"
                                :colspan="cols(openTosDrf, 6)"
                                :rowspan="groupRowspan(openTosDrf)"
                                @click="openTosDrf = !openTosDrf"
                            >
                                <span class="mon-group-toggle">
                                    <span x-text="openTosDrf ? '▼' : '▶'"></span>
                                    <span>DRF</span>
                                </span>
                            </th>
                            <th class="mon-remark-head" :rowspan="remarkRowspan()">Remarks</th>
                        </tr>
                        <tr class="mon-leaf" x-show="hasLeaf()" x-cloak>
                            <th x-show="openSyllabi">Syllabi</th>
                            <th x-show="openSyllabi">Target</th>
                            <th x-show="openSyllabi">Actual</th>
                            <th x-show="openSyllabi">Percentage</th>
                            <th x-show="openSyllabi">Lacking</th>
                            <th x-show="openSyllabi">Submission Dates</th>
                            <th x-show="openSyllabi">Effectivity Date</th>
                            <th x-show="openSyllabi">Released Date</th>
                            <th x-show="openSyllabiDrf">DRF</th>
                            <th x-show="openSyllabiDrf">Target</th>
                            <th x-show="openSyllabiDrf">Actual</th>
                            <th x-show="openSyllabiDrf">Percentage</th>
                            <th x-show="openSyllabiDrf">Lacking</th>
                            <th x-show="openSyllabiDrf">Received Date</th>
                            <th x-show="openTos">TOS</th>
                            <th x-show="openTos">Target</th>
                            <th x-show="openTos">Actual</th>
                            <th x-show="openTos">Percentage</th>
                            <th x-show="openTos">Lacking</th>
                            <th x-show="openTos">Submission Dates</th>
                            <th x-show="openTos">Effectivity Date</th>
                            <th x-show="openTos">Released Date</th>
                            <th x-show="openTosDrf">DRF</th>
                            <th x-show="openTosDrf">Target</th>
                            <th x-show="openTosDrf">Actual</th>
                            <th x-show="openTosDrf">Percentage</th>
                            <th x-show="openTosDrf">Lacking</th>
                            <th x-show="openTosDrf">Received Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($report['rows'] as $row)
                            @php
                                $syllabiDone = $row['syllabi_target'] > 0 && $row['syllabi_actual'] * 2 >= $row['syllabi_target'];
                                $drfDone = $row['drf_target'] > 0 && $row['drf_actual'] * 2 >= $row['drf_target'];
                                $tosDone = $row['tos_target'] > 0 && $row['tos_actual'] * 2 >= $row['tos_target'];
                                $tosDrfDone = $row['tos_drf_target'] > 0 && $row['tos_drf_actual'] * 2 >= $row['tos_drf_target'];
                            @endphp
                            <tr>
                                <td class="mon-sticky mon-program" title="{{ $row['program_name'] }}">{{ $row['program_code'] }}</td>

                                {{-- Syllabi --}}
                                <td class="mon-summary-col" x-show="!openSyllabi" x-cloak>
                                    <div class="mon-summary-cell">
                                        <span class="mon-check {{ $syllabiDone ? 'is-checked' : '' }}" aria-hidden="true"></span>
                                        <span class="mon-ratio">{{ $row['syllabi_label'] }}</span>
                                    </div>
                                </td>
                                <td class="mon-ratio" x-show="openSyllabi" x-cloak>{{ $row['syllabi_label'] }}</td>
                                <td x-show="openSyllabi" x-cloak>{{ $row['syllabi_target'] }}</td>
                                <td x-show="openSyllabi" x-cloak>{{ $row['syllabi_actual'] }}</td>
                                <td x-show="openSyllabi" x-cloak>@include('pages.dcs.reports._pct', ['pct' => $row['syllabi_pct']])</td>
                                <td x-show="openSyllabi" x-cloak class="mon-list">
                                    @forelse($row['syllabi_lacking_names'] as $course)
                                        <div>{{ $course }}</div>
                                    @empty
                                        <span class="rpt-na">—</span>
                                    @endforelse
                                </td>
                                <td x-show="openSyllabi" x-cloak>{{ $row['submission_dates'] !== '' ? $row['submission_dates'] : '—' }}</td>
                                <td x-show="openSyllabi" x-cloak>{{ $row['effectivity_date'] !== '' ? $row['effectivity_date'] : '—' }}</td>
                                <td x-show="openSyllabi" x-cloak>{{ $row['released_date'] !== '' ? $row['released_date'] : '—' }}</td>

                                {{-- Syllabi DRF --}}
                                <td class="mon-summary-col" x-show="!openSyllabiDrf" x-cloak>
                                    <div class="mon-summary-cell">
                                        <span class="mon-check {{ $drfDone ? 'is-checked' : '' }}" aria-hidden="true"></span>
                                        <span class="mon-ratio">{{ $row['drf_label'] }}</span>
                                    </div>
                                </td>
                                <td class="mon-ratio" x-show="openSyllabiDrf" x-cloak>{{ $row['drf_label'] }}</td>
                                <td x-show="openSyllabiDrf" x-cloak>{{ $row['drf_target'] }}</td>
                                <td x-show="openSyllabiDrf" x-cloak>{{ $row['drf_actual'] }}</td>
                                <td x-show="openSyllabiDrf" x-cloak>@include('pages.dcs.reports._pct', ['pct' => $row['drf_pct']])</td>
                                <td x-show="openSyllabiDrf" x-cloak class="mon-list">
                                    @forelse($row['drf_lacking_names'] as $course)
                                        <div>{{ $course }}</div>
                                    @empty
                                        <span class="rpt-na">—</span>
                                    @endforelse
                                </td>
                                <td x-show="openSyllabiDrf" x-cloak>{{ $row['drf_received'] !== '' ? $row['drf_received'] : '—' }}</td>

                                {{-- Syllabi Remarks (always visible) --}}
                                <td class="mon-remark-cell" wire:key="remark-syllabi-{{ $row['program_id'] }}-{{ $deadline ?: 'overall' }}">
                                    <select
                                        class="mon-remark mon-remark-{{ $row['syllabi_status'] ?: 'empty' }}"
                                        wire:change="saveRemark({{ $row['program_id'] }}, 'syllabi', $event.target.value)"
                                    >
                                        <option value="">Select</option>
                                        @foreach(\App\Helpers\SyllabiMonitoringHelper::REMARKS as $value => $label)
                                            <option value="{{ $value }}" @selected($row['syllabi_status'] === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </td>

                                <td class="mon-section-gap" aria-hidden="true"></td>

                                {{-- TOS --}}
                                <td class="mon-summary-col" x-show="!openTos" x-cloak>
                                    <div class="mon-summary-cell">
                                        <span class="mon-check {{ $tosDone ? 'is-checked' : '' }}" aria-hidden="true"></span>
                                        <span class="mon-ratio">{{ $row['tos_label'] }}</span>
                                    </div>
                                </td>
                                <td class="mon-ratio" x-show="openTos" x-cloak>{{ $row['tos_label'] }}</td>
                                <td x-show="openTos" x-cloak>{{ $row['tos_target'] }}</td>
                                <td x-show="openTos" x-cloak>{{ $row['tos_actual'] }}</td>
                                <td x-show="openTos" x-cloak>@include('pages.dcs.reports._pct', ['pct' => $row['tos_pct']])</td>
                                <td x-show="openTos" x-cloak class="mon-list">
                                    @forelse($row['tos_lacking_names'] as $course)
                                        <div>{{ $course }}</div>
                                    @empty
                                        <span class="rpt-na">—</span>
                                    @endforelse
                                </td>
                                <td x-show="openTos" x-cloak>{{ $row['tos_submission_dates'] !== '' ? $row['tos_submission_dates'] : '—' }}</td>
                                <td x-show="openTos" x-cloak>{{ $row['tos_effectivity_date'] !== '' ? $row['tos_effectivity_date'] : '—' }}</td>
                                <td x-show="openTos" x-cloak>{{ $row['tos_released_date'] !== '' ? $row['tos_released_date'] : '—' }}</td>

                                {{-- TOS DRF --}}
                                <td class="mon-summary-col" x-show="!openTosDrf" x-cloak>
                                    <div class="mon-summary-cell">
                                        <span class="mon-check {{ $tosDrfDone ? 'is-checked' : '' }}" aria-hidden="true"></span>
                                        <span class="mon-ratio">{{ $row['tos_drf_label'] }}</span>
                                    </div>
                                </td>
                                <td class="mon-ratio" x-show="openTosDrf" x-cloak>{{ $row['tos_drf_label'] }}</td>
                                <td x-show="openTosDrf" x-cloak>{{ $row['tos_drf_target'] }}</td>
                                <td x-show="openTosDrf" x-cloak>{{ $row['tos_drf_actual'] }}</td>
                                <td x-show="openTosDrf" x-cloak>@include('pages.dcs.reports._pct', ['pct' => $row['tos_drf_pct']])</td>
                                <td x-show="openTosDrf" x-cloak class="mon-list">
                                    @forelse($row['tos_drf_lacking_names'] as $course)
                                        <div>{{ $course }}</div>
                                    @empty
                                        <span class="rpt-na">—</span>
                                    @endforelse
                                </td>
                                <td x-show="openTosDrf" x-cloak>{{ $row['tos_drf_received'] !== '' ? $row['tos_drf_received'] : '—' }}</td>

                                {{-- TOS Remarks (always visible) --}}
                                <td class="mon-remark-cell" wire:key="remark-tos-{{ $row['program_id'] }}-{{ $deadline ?: 'overall' }}">
                                    <select
                                        class="mon-remark mon-remark-{{ $row['tos_status'] ?: 'empty' }}"
                                        wire:change="saveRemark({{ $row['program_id'] }}, 'tos', $event.target.value)"
                                    >
                                        <option value="">Select</option>
                                        @foreach(\App\Helpers\SyllabiMonitoringHelper::REMARKS as $value => $label)
                                            <option value="{{ $value }}" @selected($row['tos_status'] === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="rpt-na">No programs found for this college.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if(count($report['rows']) > 0)
                        <tfoot>
                            <tr>
                                <td class="mon-sticky">Total</td>
                                <td class="mon-summary-col" x-show="!openSyllabi" x-cloak>
                                    <span class="mon-ratio">{{ $report['totals']['syllabi_actual'] }} / {{ $report['totals']['syllabi_target'] }}</span>
                                </td>
                                <td class="mon-ratio" x-show="openSyllabi" x-cloak>{{ $report['totals']['syllabi_actual'] }} / {{ $report['totals']['syllabi_target'] }}</td>
                                <td x-show="openSyllabi" x-cloak>{{ $report['totals']['syllabi_target'] }}</td>
                                <td x-show="openSyllabi" x-cloak>{{ $report['totals']['syllabi_actual'] }}</td>
                                <td x-show="openSyllabi" x-cloak>@include('pages.dcs.reports._pct', ['pct' => $report['totals']['syllabi_pct']])</td>
                                <td x-show="openSyllabi" x-cloak>{{ $report['totals']['syllabi_lacking'] }}</td>
                                <td x-show="openSyllabi" x-cloak colspan="3"></td>
                                <td class="mon-summary-col" x-show="!openSyllabiDrf" x-cloak>
                                    <span class="mon-ratio">{{ $report['totals']['drf_actual'] }} / {{ $report['totals']['drf_target'] }}</span>
                                </td>
                                <td class="mon-ratio" x-show="openSyllabiDrf" x-cloak>{{ $report['totals']['drf_actual'] }} / {{ $report['totals']['drf_target'] }}</td>
                                <td x-show="openSyllabiDrf" x-cloak>{{ $report['totals']['drf_target'] }}</td>
                                <td x-show="openSyllabiDrf" x-cloak>{{ $report['totals']['drf_actual'] }}</td>
                                <td x-show="openSyllabiDrf" x-cloak>@include('pages.dcs.reports._pct', ['pct' => $report['totals']['drf_pct']])</td>
                                <td x-show="openSyllabiDrf" x-cloak>{{ $report['totals']['drf_lacking'] }}</td>
                                <td x-show="openSyllabiDrf" x-cloak></td>
                                <td class="mon-remark-cell"></td>
                                <td class="mon-section-gap" aria-hidden="true"></td>
                                <td class="mon-summary-col" x-show="!openTos" x-cloak>
                                    <span class="mon-ratio">{{ $report['totals']['tos_actual'] }} / {{ $report['totals']['tos_target'] }}</span>
                                </td>
                                <td class="mon-ratio" x-show="openTos" x-cloak>{{ $report['totals']['tos_actual'] }} / {{ $report['totals']['tos_target'] }}</td>
                                <td x-show="openTos" x-cloak>{{ $report['totals']['tos_target'] }}</td>
                                <td x-show="openTos" x-cloak>{{ $report['totals']['tos_actual'] }}</td>
                                <td x-show="openTos" x-cloak>@include('pages.dcs.reports._pct', ['pct' => $report['totals']['tos_pct']])</td>
                                <td x-show="openTos" x-cloak>{{ $report['totals']['tos_lacking'] }}</td>
                                <td x-show="openTos" x-cloak colspan="3"></td>
                                <td class="mon-summary-col" x-show="!openTosDrf" x-cloak>
                                    <span class="mon-ratio">{{ $report['totals']['tos_drf_actual'] }} / {{ $report['totals']['tos_drf_target'] }}</span>
                                </td>
                                <td class="mon-ratio" x-show="openTosDrf" x-cloak>{{ $report['totals']['tos_drf_actual'] }} / {{ $report['totals']['tos_drf_target'] }}</td>
                                <td x-show="openTosDrf" x-cloak>{{ $report['totals']['tos_drf_target'] }}</td>
                                <td x-show="openTosDrf" x-cloak>{{ $report['totals']['tos_drf_actual'] }}</td>
                                <td x-show="openTosDrf" x-cloak>@include('pages.dcs.reports._pct', ['pct' => $report['totals']['tos_drf_pct']])</td>
                                <td x-show="openTosDrf" x-cloak>{{ $report['totals']['tos_drf_lacking'] }}</td>
                                <td x-show="openTosDrf" x-cloak></td>
                                <td class="mon-remark-cell"></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </section>
    @endif
</main>

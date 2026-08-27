<?php

use App\Helpers\SyllabiMonitoringHelper;
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
        if ($this->collegeId === '' || $this->schoolYearId === '' || $this->semesterId === '') {
            return;
        }

        SyllabiMonitoringHelper::saveStatus(
            (int) $this->collegeId,
            (int) $this->schoolYearId,
            (int) $this->semesterId,
            $programId,
            $section,
            $this->deadline !== '' ? $this->deadline : null,
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

<main class="rpt-page" id="rptPage" x-data="{ openDetail: true }">
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
                            · Select a deadline to save remarks
                        @endif
                    </span>
                </div>
                <div class="rpt-results-actions">
                    <button class="rpt-btn rpt-btn-outline" type="button" onclick="window.print()">
                        <i class="fa-solid fa-print"></i> Print
                    </button>
                </div>
            </div>

            <div class="rpt-table-scroll mon-table-wrap mon-table-wrap--simple">
                <table class="rpt-table mon-table mon-table--simple">
                    <thead>
                        <tr class="mon-head-primary">
                            <th class="mon-sticky" rowspan="3">Programs</th>
                            <th colspan="15">
                                <button type="button" class="mon-group-toggle mon-group-toggle--inverse" @click="openDetail = !openDetail">
                                    <span x-text="openDetail ? '▼' : '▶'"></span> SYLLABI
                                </button>
                            </th>
                            <th colspan="3">TOS/RUBRICS</th>
                        </tr>
                        <tr class="mon-head-secondary" x-show="openDetail" x-cloak>
                            <th colspan="8">SYLLABI</th>
                            <th colspan="7">DOCUMENT REQUEST FORM</th>
                            <th colspan="3"></th>
                        </tr>
                        <tr class="mon-leaf" x-show="openDetail" x-cloak>
                            <th>Syllabi</th>
                            <th>Target</th>
                            <th>Actual</th>
                            <th>Percentage</th>
                            <th>Lacking</th>
                            <th>Submission Dates</th>
                            <th>Effectivity Date</th>
                            <th>Released Date</th>
                            <th>DRF</th>
                            <th>Target</th>
                            <th>Actual</th>
                            <th>Percentage</th>
                            <th>Lacking</th>
                            <th>Received Date</th>
                            <th>Remarks</th>
                            <th>TOS</th>
                            <th>DRF</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($report['rows'] as $row)
                            <tr>
                                <td class="mon-sticky mon-program" title="{{ $row['program_name'] }}">{{ $row['program_code'] }}</td>
                                <td class="mon-ratio">{{ $row['syllabi_label'] }}</td>
                                <td class="mon-ratio" x-show="!openDetail" x-cloak>{{ $row['drf_label'] }}</td>
                                <td x-show="openDetail">{{ $row['syllabi_target'] }}</td>
                                <td x-show="openDetail">{{ $row['syllabi_actual'] }}</td>
                                <td x-show="openDetail">@include('pages.dcs.reports._pct', ['pct' => $row['syllabi_pct']])</td>
                                <td x-show="openDetail" class="mon-list">
                                    @forelse($row['syllabi_lacking_names'] as $course)
                                        <div>{{ $course }}</div>
                                    @empty
                                        <span class="rpt-na">—</span>
                                    @endforelse
                                </td>
                                <td x-show="openDetail">{{ $row['submission_dates'] !== '' ? $row['submission_dates'] : '—' }}</td>
                                <td x-show="openDetail">{{ $row['effectivity_date'] !== '' ? $row['effectivity_date'] : '—' }}</td>
                                <td x-show="openDetail">{{ $row['released_date'] !== '' ? $row['released_date'] : '—' }}</td>
                                <td x-show="openDetail" class="mon-ratio">{{ $row['drf_label'] }}</td>
                                <td x-show="openDetail">{{ $row['drf_target'] }}</td>
                                <td x-show="openDetail">{{ $row['drf_actual'] }}</td>
                                <td x-show="openDetail">@include('pages.dcs.reports._pct', ['pct' => $row['drf_pct']])</td>
                                <td x-show="openDetail" class="mon-list">
                                    @forelse($row['drf_lacking_names'] as $course)
                                        <div>{{ $course }}</div>
                                    @empty
                                        <span class="rpt-na">—</span>
                                    @endforelse
                                </td>
                                <td x-show="openDetail">{{ $row['drf_received'] !== '' ? $row['drf_received'] : '—' }}</td>
                                <td x-show="openDetail">
                                    <select class="mon-remark mon-remark-{{ $row['syllabi_status'] ?: 'empty' }}"
                                        @disabled(! $report['remarks_enabled'])
                                        wire:change="saveRemark({{ $row['program_id'] }}, 'syllabi', $event.target.value)">
                                        <option value="">Select</option>
                                        @foreach(\App\Helpers\SyllabiMonitoringHelper::REMARKS as $value => $label)
                                            <option value="{{ $value }}" @selected($row['syllabi_status'] === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="mon-ratio">{{ $row['tos_label'] }}</td>
                                <td class="mon-ratio">{{ $row['tos_drf_label'] }}</td>
                                <td>
                                    <select class="mon-remark mon-remark-{{ $row['tos_status'] ?: 'empty' }}"
                                        @disabled(! $report['remarks_enabled'])
                                        wire:change="saveRemark({{ $row['program_id'] }}, 'tos', $event.target.value)">
                                        <option value="">Select</option>
                                        @foreach(\App\Helpers\SyllabiMonitoringHelper::REMARKS as $value => $label)
                                            <option value="{{ $value }}" @selected($row['tos_status'] === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="19" class="rpt-na">No programs found for this college.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if(count($report['rows']) > 0)
                        <tfoot>
                            <tr>
                                <td class="mon-sticky">Total</td>
                                <td class="mon-ratio">{{ $report['totals']['syllabi_actual'] }} / {{ $report['totals']['syllabi_target'] }}</td>
                                <td class="mon-ratio" x-show="!openDetail" x-cloak>{{ $report['totals']['drf_actual'] }} / {{ $report['totals']['drf_target'] }}</td>
                                <td x-show="openDetail">{{ $report['totals']['syllabi_target'] }}</td>
                                <td x-show="openDetail">{{ $report['totals']['syllabi_actual'] }}</td>
                                <td x-show="openDetail">@include('pages.dcs.reports._pct', ['pct' => $report['totals']['syllabi_pct']])</td>
                                <td x-show="openDetail">{{ $report['totals']['syllabi_lacking'] }}</td>
                                <td x-show="openDetail" colspan="3"></td>
                                <td x-show="openDetail" class="mon-ratio">{{ $report['totals']['drf_actual'] }} / {{ $report['totals']['drf_target'] }}</td>
                                <td x-show="openDetail">{{ $report['totals']['drf_target'] }}</td>
                                <td x-show="openDetail">{{ $report['totals']['drf_actual'] }}</td>
                                <td x-show="openDetail">@include('pages.dcs.reports._pct', ['pct' => $report['totals']['drf_pct']])</td>
                                <td x-show="openDetail">{{ $report['totals']['drf_lacking'] }}</td>
                                <td x-show="openDetail" colspan="2"></td>
                                <td class="mon-ratio">{{ $report['totals']['tos_actual'] }} / {{ $report['totals']['tos_target'] }}</td>
                                <td></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </section>
    @endif
</main>

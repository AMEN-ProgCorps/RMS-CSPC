<?php

use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('Revision History — CSPC DCS')] class extends Component {
    public string $docNo = '';

    public function mount(string $docNo): void
    {
        $this->docNo = $docNo;
    }

    public function with(): array
    {
        return RegisterQueryHelper::history($this->docNo);
    }
}; ?>

<div class="hst-container" x-data @keydown.escape.window="window.location.href = '{{ route('dcs.register.update') }}'">
    <div class="hst-header">
        <div>
            <div class="reg-breadcrumb">Document Control System / Update / <span>History</span></div>
            <h1 class="hst-page-title">History</h1>
        </div>
        <div class="hst-header-actions">
            <span class="hst-badge"><i class="fa-solid fa-clock-rotate-left"></i> {{ count($revisions) }} {{ \Illuminate\Support\Str::plural('revision', count($revisions)) }}</span>
            <a href="{{ route('dcs.register.update') }}" class="reg-btn reg-btn-cancel">
                <i class="fa-solid fa-arrow-left"></i> Back to Documents
            </a>
        </div>
    </div>

    <div class="hst-panel">
        <div class="hst-doc">
            <span class="hst-info-label">Document</span>
            <p class="hst-doc-title" title="{{ $docTitle }}">{{ $docTitle }}</p>
            <span class="hst-docno">{{ $docNo }}</span>
            @if(!empty($lineageDocNos) && count($lineageDocNos) > 1)
                <span class="hst-lineage-note" title="Includes prior document numbers from renumbering">
                    <i class="fa-solid fa-link"></i>
                    {{ count($lineageDocNos) }} document numbers in lineage
                </span>
            @endif
        </div>

        <div class="hst-timeline">
        @forelse($revisions as $rev)
            @php $firstTab = $rev['checklists'][0]['key'] ?? 'masterlist'; @endphp
            <div
                class="hst-node {{ $rev['is_latest'] ? 'hst-node-current' : '' }}"
                x-data="{
                    open: {{ $rev['is_latest'] ? 'true' : 'false' }},
                    tab: {{ $rev['is_latest'] ? json_encode($firstTab) : 'null' }},
                    first: {{ json_encode($firstTab) }},
                    select(key) {
                        if (this.open && this.tab === key) {
                            this.open = false;
                            this.tab = null;
                            return;
                        }
                        this.tab = key;
                        this.open = true;
                    },
                    toggle() {
                        this.open = !this.open;
                        if (this.open && !this.tab) this.tab = this.first;
                        if (!this.open) this.tab = null;
                    }
                }"
            >
                <div class="hst-rail">
                    <span class="hst-dot" title="Revision {{ $rev['revise_no'] }}">{{ $rev['revise_no'] }}</span>
                </div>
                <div class="hst-card" :class="{ 'is-open': open }">
                    <div class="hst-card-header" @click="toggle()" role="button" tabindex="0" @keydown.enter="toggle()" @keydown.space.prevent="toggle()">
                        <div class="hst-card-heading">
                            <i class="fa-solid fa-chevron-right hst-chevron" :class="{ 'is-open': open }"></i>
                            <span class="hst-rev-badge">Rev {{ $rev['revise_no'] }}</span>
                            @if(!empty($rev['doc_no']) && $rev['doc_no'] !== $docNo)
                                <span class="hst-docno-tag" title="Prior document number">{{ $rev['doc_no'] }}</span>
                            @endif
                            @if($rev['is_latest'])
                                <span class="hst-current-tag">Current</span>
                            @else
                                <span class="hst-prior-tag">Previous</span>
                            @endif
                            @if($rev['is_initial'])
                                <span class="hst-initial-tag">Initial</span>
                            @elseif(($rev['changed_count'] ?? 0) > 0)
                                <span class="hst-changed-tag">{{ $rev['changed_count'] }} {{ \Illuminate\Support\Str::plural('change', $rev['changed_count']) }}</span>
                            @endif
                            <span class="hst-tags">
                                @foreach($rev['checklists'] as $cl)
                                    <button
                                        type="button"
                                        class="hst-tag"
                                        :class="{ 'is-active': tab === '{{ $cl['key'] }}' }"
                                        @click.stop="select('{{ $cl['key'] }}')"
                                    >{{ $cl['label'] }}</button>
                                @endforeach
                            </span>
                        </div>
                        <div class="hst-card-meta">
                            <time class="hst-date">{{ $rev['created_label'] }}</time>
                            @if($rev['is_latest'])
                                <a href="{{ route('dcs.register.edit', $rev['id']) }}" class="hst-btn-edit" @click.stop>
                                    <i class="fa-solid fa-pen-to-square"></i> Edit
                                </a>
                            @endif
                        </div>
                    </div>

                    <div class="hst-card-body" x-show="open" x-cloak>
                        @foreach($rev['sections'] as $section)
                            <div x-show="tab === '{{ $section['key'] }}'">
                                <h2 class="hst-section-title">{{ $section['title'] }}</h2>
                                @if(($section['key'] ?? '') === 'syllabi' && !empty($section['table']['courses']))
                                    @if(!empty($section['table']['meta']))
                                        <div class="hst-syl-meta">
                                            @foreach($section['table']['meta'] as $row)
                                                <div class="{{ !empty($row['changed']) ? 'is-changed' : '' }}">
                                                    <span class="hst-info-label">{{ $row['label'] }}</span>
                                                    <span class="hst-val">{{ $row['value'] }}</span>
                                                    @if(!empty($row['changed']))
                                                        <span class="hst-was">Was: {{ $row['previous'] ?? '—' }}</span>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                    <div class="hst-table-wrap">
                                        <table class="hst-table">
                                            <thead>
                                                <tr>
                                                    <th>Course</th>
                                                    <th>Available</th>
                                                    <th>Copies</th>
                                                    <th>Pages</th>
                                                    <th>Received</th>
                                                    <th>Faculty</th>
                                                    <th>DRF</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($section['table']['courses'] as $course)
                                                    <tr>
                                                        @foreach(['course' => 'is-course', 'avail' => 'is-tight', 'copies' => 'is-tight', 'pages' => 'is-tight', 'received' => 'is-tight', 'faculty' => '', 'drf' => ''] as $col => $cls)
                                                            @php $cell = $course[$col] ?? ['value' => '—']; @endphp
                                                            <td class="{{ $cls }} {{ !empty($cell['changed']) ? 'is-changed' : '' }}">
                                                                <span class="hst-val">{{ $cell['value'] ?? '—' }}</span>
                                                                @if(!empty($cell['changed']))
                                                                    <span class="hst-was">Was: {{ $cell['previous'] ?? '—' }}</span>
                                                                @endif
                                                            </td>
                                                        @endforeach
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @elseif(!empty($section['revisions']))
                                    <div class="hst-table-wrap">
                                        <h3 class="hst-subsection-title">Documents for Revision</h3>
                                        <table class="hst-table">
                                            <thead>
                                                <tr>
                                                    <th>Document No.</th>
                                                    <th>Title</th>
                                                    <th>Effectivity</th>
                                                    <th>Rev</th>
                                                    <th>Brief Purpose</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($section['revisions'] as $dcnRev)
                                                    <tr>
                                                        <td>{{ $dcnRev['document_no'] ?? '—' }}</td>
                                                        <td>{{ $dcnRev['title'] ?? '—' }}</td>
                                                        <td>{{ $dcnRev['effectivity_date'] ?? '—' }}</td>
                                                        <td>{{ $dcnRev['revision_no'] ?? '—' }}</td>
                                                        <td>{{ $dcnRev['brief_purpose'] ?? '—' }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="hst-grid hst-grid-after-table">
                                        @foreach($section['rows'] as $row)
                                            <div class="hst-field {{ !empty($row['changed']) ? 'is-changed' : '' }}">
                                                <span class="hst-info-label">{{ $row['label'] }}</span>
                                                <span class="hst-val">{!! nl2br(e($row['value'])) !!}</span>
                                                @if(!empty($row['changed']))
                                                    <span class="hst-was">Was: {!! nl2br(e($row['previous'] ?? '—')) !!}</span>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <div class="hst-grid">
                                        @foreach($section['rows'] as $row)
                                            <div class="hst-field {{ !empty($row['changed']) ? 'is-changed' : '' }}">
                                                <span class="hst-info-label">{{ $row['label'] }}</span>
                                                <span class="hst-val">{!! nl2br(e($row['value'])) !!}</span>
                                                @if(!empty($row['changed']))
                                                    <span class="hst-was">Was: {!! nl2br(e($row['previous'] ?? '—')) !!}</span>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @empty
            <div class="hst-empty">No revision history found for this document.</div>
        @endforelse
        </div>
    </div>
</div>

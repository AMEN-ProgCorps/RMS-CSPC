<div class="hst-container" x-data @keydown.escape.window="window.location.href = '{{ route('dcs.register.update') }}'">
    <div class="hst-header">
        <div>
            <div class="reg-breadcrumb">Document Control System / Update / <span>Edit History</span></div>
            <h1 class="hst-page-title">Edit History</h1>
        </div>
        <div class="hst-header-actions">
            <span class="hst-badge">
                <i class="fa-solid fa-clock-rotate-left"></i>
                {{ count($entries) }} {{ \Illuminate\Support\Str::plural('edit', count($entries)) }}
            </span>
            @if(!empty($canEdit) && !empty($latestRequestId))
                <a href="{{ route('dcs.register.edit', $latestRequestId) }}" class="hst-btn-edit">
                    <i class="fa-solid fa-pen-to-square"></i> Edit
                </a>
            @endif
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

        <div class="hst-edit-history-page">
            @forelse($entries as $entry)
                <article class="hst-edit-history-entry">
                    <div class="hst-edit-history-meta">
                        <span class="hst-edit-history-by">
                            Edited by: <strong>{{ $entry['edited_by'] ?? $entry['user_name'] ?? 'Unknown user' }}</strong>
                        </span>
                        <time>{{ $entry['created_at'] ?? '—' }}</time>
                        @if($entry['revise_no'] !== null)
                            <span class="hst-rev-badge">Rev {{ $entry['revise_no'] }}</span>
                        @endif
                        @if(!empty($entry['doc_no']) && $entry['doc_no'] !== $docNo)
                            <span class="hst-docno-tag" title="Prior document number">{{ $entry['doc_no'] }}</span>
                        @endif
                        @if(!empty($entry['is_latest']))
                            <span class="hst-current-tag">Current</span>
                        @endif
                        @if(!empty($entry['action']))
                            <span class="hst-edit-history-action">{{ $entry['action'] }}</span>
                        @endif
                    </div>
                    @if(!empty($entry['changes']))
                        <ul class="hst-edit-history-changes">
                            @foreach($entry['changes'] as $change)
                                <li>
                                    <span>{{ $change['label'] ?? '' }}</span>:
                                    <span class="hst-edit-history-old">{{ $change['old'] ?? '—' }}</span>
                                    <span aria-hidden="true">→</span>
                                    <span class="hst-edit-history-new">{{ $change['new'] ?? '—' }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </article>
            @empty
                <div class="hst-empty">No edits recorded yet.</div>
            @endforelse
        </div>
    </div>
</div>

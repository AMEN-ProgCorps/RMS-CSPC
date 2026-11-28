{{--
  DCS PARTIAL: Register partial (edit-history-page.blade.php)
  FILE: resources/views/pages/dcs/register/partials/edit-history-page.blade.php
  DEBUG: RegisterPersistHelper / RegisterQueryHelper; register.css
--}}
<div class="hst-container" x-data @keydown.escape.window="window.location.href = '{{ route('dcs.register.update') }}'">
    <div class="hst-header">
        <div>
            <div class="reg-breadcrumb">Document Control System / Update / <span>Edit History</span></div>
            <h1 class="hst-page-title">Edit History</h1>
        </div>
        <div class="hst-header-actions">
            <span class="hst-badge">
                <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
                {{ count($entries) }} {{ \Illuminate\Support\Str::plural('edit', count($entries)) }}
            </span>
            @if(!empty($canEdit) && !empty($latestRequestId))
                <a href="{{ route('dcs.register.edit', $latestRequestId) }}" class="hst-btn-edit">
                    <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> Edit Document
                </a>
            @endif
            <a href="{{ route('dcs.register.update') }}" class="reg-btn reg-btn-cancel">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to Documents
            </a>
        </div>
    </div>

    <div class="hst-panel">
        <div class="hst-doc">
            <span class="hst-doc-icon" aria-hidden="true"><i class="fa-regular fa-file-lines"></i></span>
            <div class="hst-doc-copy">
                <p class="hst-doc-title" title="{{ $docTitle }}">{{ $docTitle }}</p>
            </div>
            <div class="hst-doc-badges">
                @if(!empty($docNo))
                    <span class="hst-docno">{{ $docNo }}</span>
                @endif
                @if(!empty($lineageDocNos) && count($lineageDocNos) > 1)
                    <span class="hst-lineage-note" title="Includes prior document numbers from renumbering">
                        <i class="fa-solid fa-link" aria-hidden="true"></i>
                        {{ count($lineageDocNos) }} in lineage
                    </span>
                @endif
            </div>
        </div>

        <div class="hst-edit-history-page">
            @forelse($entries as $idx => $entry)
                @php
                    $editorName = trim((string) ($entry['edited_by'] ?? $entry['user_name'] ?? 'Unknown user'));
                    $editorInitial = mb_strtoupper(mb_substr($editorName !== '' ? $editorName : 'U', 0, 1));
                    $isCurrent = !empty($entry['is_latest']);
                @endphp
                <div class="hst-eh-row {{ $isCurrent ? 'is-current' : '' }}">
                    <div class="hst-eh-rail" aria-hidden="true">
                        <span class="hst-eh-dot">
                            <i class="fa-solid {{ $isCurrent ? 'fa-check' : 'fa-pen' }}"></i>
                        </span>
                    </div>
                    <article class="hst-edit-history-entry">
                        <div class="hst-edit-history-meta">
                            <div class="hst-eh-author">
                                <span class="hst-eh-avatar" aria-hidden="true">{{ $editorInitial }}</span>
                                <div class="hst-eh-author-copy">
                                    <span class="hst-edit-history-by"><strong>{{ $editorName }}</strong></span>
                                    <time class="hst-eh-time">
                                        <i class="fa-regular fa-clock" aria-hidden="true"></i>
                                        {{ $entry['created_at'] ?? '—' }}
                                    </time>
                                </div>
                            </div>
                            <div class="hst-eh-pills">
                                @if($entry['revise_no'] !== null)
                                    <span class="hst-rev-badge">Rev {{ $entry['revise_no'] }}</span>
                                @endif
                                @if(!empty($entry['doc_no']) && $entry['doc_no'] !== $docNo)
                                    <span class="hst-docno-tag" title="Prior document number">{{ $entry['doc_no'] }}</span>
                                @endif
                            </div>
                        </div>

                        @if(!empty($entry['changes']))
                            <div class="hst-eh-diff-list">
                                @foreach($entry['changes'] as $change)
                                    @php
                                        $isOfficeField = in_array(($change['field'] ?? ''), ['distribution_offices', 'source_offices'], true);
                                        $isSummary = ! empty($change['summary'])
                                            || $isOfficeField
                                            || str_starts_with((string) ($change['new'] ?? ''), 'Office added')
                                            || str_starts_with((string) ($change['new'] ?? ''), 'Office removed')
                                            || str_contains((string) ($change['new'] ?? ''), 'offices added')
                                            || str_contains((string) ($change['new'] ?? ''), 'offices removed');
                                        $officeSummaries = [];
                                        if ($isOfficeField && empty($change['summary'])) {
                                            $oldCsv = trim((string) ($change['old'] ?? ''));
                                            $newCsv = trim((string) ($change['new'] ?? ''));
                                            if ($oldCsv !== '' && $newCsv !== '' && ! str_starts_with($newCsv, 'Office ')) {
                                                $oldNames = array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/u', $oldCsv === '—' ? '' : $oldCsv) ?: [])));
                                                $newNames = array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/u', $newCsv === '—' ? '' : $newCsv) ?: [])));
                                                $oldSet = array_fill_keys(array_map('mb_strtolower', $oldNames), true);
                                                $newSet = array_fill_keys(array_map('mb_strtolower', $newNames), true);
                                                $added = array_values(array_filter($newNames, fn ($n) => ! isset($oldSet[mb_strtolower($n)])));
                                                $removed = array_values(array_filter($oldNames, fn ($n) => ! isset($newSet[mb_strtolower($n)])));
                                                if ($added !== []) {
                                                    $officeSummaries[] = count($added) === 1
                                                        ? 'Office added: '.$added[0]
                                                        : count($added).' offices added: '.implode(', ', $added);
                                                }
                                                if ($removed !== []) {
                                                    $officeSummaries[] = count($removed) === 1
                                                        ? 'Office removed: '.$removed[0]
                                                        : count($removed).' offices removed: '.implode(', ', $removed);
                                                }
                                            }
                                        }
                                    @endphp
                                    @if($officeSummaries !== [])
                                        @foreach($officeSummaries as $summaryText)
                                            <div class="hst-eh-diff-row">
                                                <span class="hst-eh-diff-field">{{ $change['label'] ?? 'Offices' }}</span>
                                                <div class="hst-eh-diff-vals">
                                                    <span class="hst-edit-history-new">{{ $summaryText }}</span>
                                                </div>
                                            </div>
                                        @endforeach
                                    @else
                                        <div class="hst-eh-diff-row">
                                            <span class="hst-eh-diff-field">{{ $change['label'] ?? '' }}</span>
                                            <div class="hst-eh-diff-vals">
                                                @if($isSummary)
                                                    <span class="hst-edit-history-new">{{ $change['new'] ?? '—' }}</span>
                                                @else
                                                    <span class="hst-edit-history-old">{{ $change['old'] ?? '—' }}</span>
                                                    <i class="fa-solid fa-arrow-right hst-eh-arrow" aria-hidden="true"></i>
                                                    <span class="hst-edit-history-new">{{ $change['new'] ?? '—' }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </article>
                </div>
            @empty
                <div class="hst-empty">
                    <div class="hst-empty-icon" aria-hidden="true"><i class="fa-solid fa-clock-rotate-left"></i></div>
                    <strong>No field edits recorded yet</strong>
                    <p>Registration baselines and other revisions are not listed here.</p>
                </div>
            @endforelse
        </div>
    </div>
</div>

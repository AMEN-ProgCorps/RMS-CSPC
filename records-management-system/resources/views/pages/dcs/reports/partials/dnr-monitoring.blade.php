{{-- Distribution & Retrieval Monitoring (interactive) --}}
@php
    $dnrAlerts = $dnrAlerts ?? [];
    $dnrDocTypeOptions = $dnrDocTypeOptions ?? [];
    $dnrDisplayCards = $dnrDisplayCards ?? [];
    $dnrAwaitingFilter = $dnrAwaitingFilter ?? true;
@endphp

<div class="dnr-shell" id="dnr-shell-root">
    <div class="dnr-toolbar">
        <nav class="rpt-subs rpt-subs-secondary visible dnr-type-subs" aria-label="Document type filter">
            @foreach($dnrDocTypeOptions as $key => $label)
                <button
                    type="button"
                    class="rpt-sub {{ ($dnrDocTypeFilter ?? '') === $key ? 'active' : '' }}"
                    wire:click="selectDnrDocTypeFilter('{{ $key }}')"
                    wire:loading.attr="disabled"
                    wire:target="selectDnrDocTypeFilter,loadDnrMonitoring,applyDnrSearch,dnrSearchQuery"
                >
                    {{ $label }}
                </button>
            @endforeach
        </nav>

        <div class="dnr-search-row">
            <div
                class="dnr-search-wrap"
                wire:ignore.self
                x-data="{
                    open: @entangle('dnrShowSuggestions'),
                    q: @entangle('dnrSearchQuery')
                }"
                @click.outside="open = false; $wire.hideDnrSuggestions()"
            >
                <div class="dnr-search-field" wire:loading.class="is-searching" wire:target="dnrSearchQuery,applyDnrSearch,selectDnrSuggestion">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true" wire:loading.remove wire:target="dnrSearchQuery,applyDnrSearch,selectDnrSuggestion"></i>
                    <i class="fa-solid fa-spinner fa-spin" aria-hidden="true" wire:loading wire:target="dnrSearchQuery,applyDnrSearch,selectDnrSuggestion"></i>
                    <input
                        type="search"
                        placeholder="Search document no. or title…"
                        autocomplete="off"
                        x-model="q"
                        @input.debounce.300ms="$wire.set('dnrSearchQuery', q)"
                        @focus="if ((q || '').trim() !== '') open = true"
                        @keydown.escape.prevent="open = false; $wire.hideDnrSuggestions()"
                        @keydown.enter.prevent="
                            open = false;
                            $wire.set('dnrSearchQuery', q);
                            $wire.hideDnrSuggestions();
                        "
                    >
                </div>
                @if(($dnrShowSuggestions ?? false) && trim($dnrSearchQuery ?? '') !== '')
                    <ul class="dnr-suggest" role="listbox" aria-label="Document suggestions" x-show="open" x-cloak>
                        @forelse(($dnrSearchSuggestions ?? []) as $suggestion)
                            <li role="option">
                                <button
                                    type="button"
                                    class="dnr-suggest-item"
                                    wire:click="selectDnrSuggestion('{{ $suggestion['card_key'] }}')"
                                    wire:loading.attr="disabled"
                                >
                                    @if(($suggestion['doc_no'] ?? '') !== '')
                                        <span class="dnr-suggest-no">{{ $suggestion['doc_no'] }}</span>
                                    @endif
                                    <span class="dnr-suggest-title">
                                        {{ ($suggestion['doc_title'] ?? '') !== '' ? $suggestion['doc_title'] : 'Untitled document' }}
                                    </span>
                                </button>
                            </li>
                        @empty
                            <li class="dnr-suggest-empty">No matching document no. or title</li>
                        @endforelse
                    </ul>
                @endif
            </div>
            @if(($dnrSearchActive ?? '') !== '')
                <button type="button" class="rpt-btn rpt-btn-outline" wire:click="clearDnrSearch">Clear</button>
            @endif
            @if(($dnrAlertOnlyCardKey ?? '') !== '')
                <button type="button" class="rpt-btn rpt-btn-outline" wire:click="clearDnrAlertFocus">
                    Show all cards
                </button>
            @endif
        </div>
    </div>

    @if(count($dnrAlerts) > 0)
        <div class="dnr-alerts" role="region" aria-label="Verification required">
            @foreach($dnrAlerts as $alert)
                <button
                    type="button"
                    class="dnr-alert dnr-alert-btn"
                    wire:key="dnr-alert-{{ $alert['distribution_office_id'] }}"
                    wire:click="focusDnrAlert({{ (int) $alert['distribution_office_id'] }})"
                    wire:loading.attr="disabled"
                    wire:target="focusDnrAlert,loadDnrMonitoring"
                >
                    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                    <p>{{ $alert['message'] }}</p>
                    <span class="dnr-alert-cta">Open tracking card</span>
                </button>
            @endforeach
        </div>
    @endif

    @if($dnrAwaitingFilter)
        <div class="rpt-state dnr-state-pick">
            <div class="rpt-state-icon"><i class="fa-solid fa-filter"></i></div>
            <h4>Select a document type</h4>
            <p>Choose <strong>All</strong> or a document category above to load distribution tracking cards.</p>
        </div>
    @elseif(count($dnrDisplayCards) < 1)
        <div class="rpt-state">
            <div class="rpt-state-icon"><i class="fa-solid fa-truck-ramp-box"></i></div>
            <h4>No tracking cards found</h4>
            <p>
                @if(($dnrSearchActive ?? '') !== '')
                    No document matched your search. Try another keyword or clear the search.
                @elseif(($dnrAlertOnlyCardKey ?? '') !== '')
                    The verification card could not be found for the current filter. Try <strong>All</strong> or clear the alert focus.
                @else
                    When Records Personnel saves a document with recipient offices, tracking cards appear here.
                @endif
            </p>
        </div>
    @else
        <div class="dnr-cards">
            @foreach($dnrDisplayCards as $card)
                @php
                    $cardKey = $card['card_key'] ?? '';
                    $selectedReq = (int) ($card['request_id'] ?? 0);
                @endphp
                <article
                    data-dnr-scroll-id="{{ (int) ($card['distribution_id'] ?? 0) }}"
                    class="dnr-card {{ !empty($card['is_focused']) ? 'is-focused' : '' }}"
                    wire:key="dnr-card-{{ $cardKey }}-{{ $selectedReq }}"
                >
                    <header class="dnr-card-head">
                        <div class="dnr-card-head-main">
                            <p class="dnr-card-kicker">
                                {{ $card['doc_no'] !== '' ? $card['doc_no'] : 'No document number' }}
                            </p>
                            <h4>{{ $card['doc_title'] !== '' ? $card['doc_title'] : 'Untitled document' }}</h4>
                            @if(!empty($card['effectivity_date']))
                                <p class="dnr-card-meta">Effectivity: {{ $card['effectivity_date'] }}</p>
                            @endif
                        </div>
                        <div class="dnr-card-head-actions">
                            @if(count($card['revisions'] ?? []) > 1)
                                <label class="dnr-rev-label">
                                    <span>Revision</span>
                                    <select wire:model.live="dnrCardRevisionRequest.{{ $cardKey }}">
                                        @foreach($card['revisions'] as $rev)
                                            <option value="{{ (int) ($rev['request_id'] ?? 0) }}">
                                                Rev {{ (int) ($rev['revise_no'] ?? 0) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </label>
                            @else
                                <span class="dnr-rev-static">Rev {{ (int) ($card['revise_no'] ?? 0) }}</span>
                            @endif
                            <span
                                class="dnr-copy-progress"
                                title="Distributed copies / total copies"
                            >
                                <span class="dnr-copy-progress-label">Distributed</span>
                                <strong>{{ (int) ($card['distributed_copies'] ?? 0) }}/{{ (int) ($card['total_copies'] ?? 0) }}</strong>
                            </span>
                        </div>
                    </header>
                    <div class="dnr-table-wrap">
                        <table class="dnr-table">
                            <thead>
                                <tr>
                                    <th>Recipient Office</th>
                                    <th>Copies</th>
                                    <th>Distribution Status</th>
                                    <th>Copy Retrieval (Old Version)</th>
                                    <th style="width:148px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($card['offices_visible'] ?? [] as $office)
                                    <tr
                                        wire:key="dnr-off-{{ $office['distribution_office_id'] }}"
                                        class="{{ !empty($office['needs_verify']) ? 'is-verify' : '' }}"
                                    >
                                        <td>
                                            <strong>{{ $office['office_name'] }}</strong>
                                            @if(!empty($office['needs_verify']))
                                                <span class="dnr-pill dnr-pill-info dnr-pill-inline">Verify receipt</span>
                                            @endif
                                        </td>
                                        <td>{{ $office['copies'] }}</td>
                                        <td>
                                            @php
                                                $distStatus = (string) ($office['distribution_status'] ?? 'pending_pickup');
                                                $retStatus = (string) ($office['copy_retrieval_status'] ?? 'n_a');
                                                $distPill = match ($distStatus) {
                                                    'distributed' => 'dnr-pill-ok',
                                                    default => 'dnr-pill-pending',
                                                };
                                                $retPill = match ($retStatus) {
                                                    'retrieved' => 'dnr-pill-retrieved',
                                                    'pending_retrieval' => 'dnr-pill-warn',
                                                    default => 'dnr-pill-na',
                                                };
                                            @endphp
                                            <span class="dnr-pill {{ $distPill }}">
                                                {{ $office['distribution_status_label'] }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="dnr-pill {{ $retPill }}">
                                                {{ $office['copy_retrieval_status_label'] }}
                                            </span>
                                        </td>
                                        <td>
                                            <button
                                                type="button"
                                                class="rpt-btn rpt-btn-outline dnr-update-btn {{ !empty($office['needs_verify']) ? 'dnr-verify-btn' : '' }}"
                                                wire:click="openDnrUpdateModal({{ (int) $office['distribution_office_id'] }}, {{ $selectedReq }})"
                                                wire:loading.attr="disabled"
                                                wire:target="openDnrUpdateModal({{ (int) $office['distribution_office_id'] }}, {{ $selectedReq }})"
                                            >
                                                <span wire:loading.remove wire:target="openDnrUpdateModal({{ (int) $office['distribution_office_id'] }}, {{ $selectedReq }})">
                                                    {{ !empty($office['needs_verify']) ? 'Verify' : 'Update status' }}
                                                </span>
                                                <span wire:loading wire:target="openDnrUpdateModal({{ (int) $office['distribution_office_id'] }}, {{ $selectedReq }})">
                                                    Opening…
                                                </span>
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if(($card['offices_hidden_count'] ?? 0) > 0)
                        <div class="dnr-show-more-wrap">
                            <button
                                type="button"
                                class="dnr-show-more"
                                wire:click="toggleDnrCardExpanded('{{ $cardKey }}')"
                            >
                                Show all {{ count($card['offices'] ?? []) }} offices
                                <span class="dnr-show-more-hint">(+{{ $card['offices_hidden_count'] }} more)</span>
                            </button>
                        </div>
                    @elseif(!empty($card['is_expanded']) && count($card['offices'] ?? []) > \App\Helpers\DistributionRetrievalHelper::DNR_ROW_PREVIEW)
                        <div class="dnr-show-more-wrap">
                            <button type="button" class="dnr-show-more" wire:click="toggleDnrCardExpanded('{{ $cardKey }}')">
                                Show fewer rows
                            </button>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    @endif
</div>

@if($dnrModalOpen ?? false)
    @teleport('body')
        <div class="dnr-modal-overlay" wire:click.self="closeDnrUpdateModal" role="presentation">
            <div
                class="dnr-modal {{ ($dnrModalVerifyMode ?? false) ? 'is-verify-mode' : '' }}"
                role="dialog"
                aria-modal="true"
                aria-labelledby="dnrModalTitle"
                wire:key="dnr-modal-{{ (int) ($dnrModalOfficeId ?? 0) }}"
            >
                <div class="dnr-modal-accent" aria-hidden="true"></div>
                <div class="dnr-modal-head">
                    <div class="dnr-modal-head-main">
                        <div class="dnr-modal-icon {{ ($dnrModalVerifyMode ?? false) ? 'is-verify' : 'is-update' }}">
                            <i class="fa-solid {{ ($dnrModalVerifyMode ?? false) ? 'fa-clipboard-check' : 'fa-pen-to-square' }}"></i>
                        </div>
                        <div>
                            <p class="dnr-card-kicker">{{ ($dnrModalVerifyMode ?? false) ? 'Verify client acknowledgement' : 'Update status' }}</p>
                            <h3 id="dnrModalTitle">Distribution &amp; Retrieval</h3>
                        </div>
                    </div>
                    <button type="button" class="dnr-modal-close" wire:click="closeDnrUpdateModal" aria-label="Close">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                <div class="dnr-modal-body">
                    <div class="dnr-meta-panel">
                        <div class="dnr-meta-item">
                            <span>Doc. number</span>
                            <strong>{{ $dnrModalDocNo !== '' ? $dnrModalDocNo : '—' }}</strong>
                        </div>
                        <div class="dnr-meta-item">
                            <span>Doc. Title</span>
                            <strong>{{ $dnrModalDocTitle !== '' ? $dnrModalDocTitle : '—' }}</strong>
                        </div>
                        <div class="dnr-meta-item">
                            <span>Recipient Office</span>
                            <strong>{{ $dnrModalOfficeName !== '' ? $dnrModalOfficeName : '—' }}</strong>
                        </div>
                    </div>

                    <label class="dnr-check {{ $dnrWetSignatureVerified ? 'is-checked' : '' }}">
                        <input type="checkbox" wire:model.live="dnrWetSignatureVerified">
                        <span>
                            <strong>Physical Wet Signature Verified</strong>
                            <em>I confirm the recipient physically signed the printed D&amp;R Form (QMS-FM-082).</em>
                        </span>
                    </label>

                    <div class="dnr-field-grid">
                        <div class="dnr-field">
                            <label for="dnrDistStatus">Distribution Status</label>
                            <select
                                id="dnrDistStatus"
                                wire:model.live="dnrDistributionStatus"
                                @disabled($dnrCopyRetrievalStatus === 'pending_retrieval')
                            >
                                <option value="pending_pickup">Pending Pickup (Awaiting Recipient)</option>
                                <option value="distributed" @disabled($dnrCopyRetrievalStatus === 'pending_retrieval')>
                                    Distributed (Handover Complete)
                                </option>
                            </select>
                            @if($dnrCopyRetrievalStatus === 'pending_retrieval')
                                <p class="dnr-hint">Old copy must be retrieved before this office can be marked Distributed.</p>
                            @endif
                        </div>

                        <div class="dnr-field">
                            <label for="dnrRetStatus">Copy Retrieval Status (Old Version)</label>
                            <select id="dnrRetStatus" wire:model.live="dnrCopyRetrievalStatus">
                                <option value="pending_retrieval">Pending Retrieval (Unreturned Copy)</option>
                                <option value="retrieved">Retrieved (Return Complete)</option>
                                <option value="n_a">N/A (Initial New Document Issue)</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="dnr-modal-foot">
                    <button type="button" class="rpt-btn rpt-btn-outline" wire:click="closeDnrUpdateModal">Cancel</button>
                    <button
                        type="button"
                        class="rpt-btn rpt-btn-primary"
                        wire:click="saveDnrStatus"
                        wire:loading.attr="disabled"
                        wire:target="saveDnrStatus"
                    >
                        <span wire:loading.remove wire:target="saveDnrStatus">{{ ($dnrModalVerifyMode ?? false) ? 'Confirm verification' : 'Save status' }}</span>
                        <span wire:loading wire:target="saveDnrStatus">Saving…</span>
                    </button>
                </div>
            </div>
        </div>
    @endteleport
@endif

<script>
(function () {
    function scrollDnrCard(scrollId) {
        if (!scrollId) return;
        window.setTimeout(function () {
            var el = document.querySelector('[data-dnr-scroll-id="' + scrollId + '"]');
            if (!el) return;
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            el.classList.add('is-flash');
            window.setTimeout(function () {
                el.classList.remove('is-flash');
            }, 1600);
        }, 180);
    }

    document.addEventListener('livewire:init', function () {
        Livewire.on('dnr-scroll-to-card', function (payload) {
            var detail = Array.isArray(payload) ? (payload[0] || {}) : (payload || {});
            scrollDnrCard(detail.scrollId);
        });
    });
})();
</script>

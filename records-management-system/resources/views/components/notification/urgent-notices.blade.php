<?php
/**
 * Shared Header Urgent/Notice Action Tracker Volt Component
 */

use Livewire\Volt\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use App\Services\DtsWorkingHoursService;

new class extends Component {
    /** @var array<int, array<string, mixed>> */
    public array $urgentItems = [];

    public int $redCount = 0;
    public int $yellowCount = 0;
    public int $greenCount = 0;
    public int $totalCount = 0;

    public int $warningThresholdMinutes = 5;
    public int $urgentThresholdMinutes = 10;
    public bool $monitoringEnabled = true;

    public bool $urgentSoundAlert = true;
    public string $urgentSoundTone = 'urgent-alarm';

    public bool $showDropdown = false;

    public bool $hasMounted = false;
    public int $previousRedCount = 0;

    public function mount(): void
    {
        $this->loadSettings();
        $this->loadUrgentItems();
        $this->hasMounted = true;
        $this->previousRedCount = $this->redCount;
    }

    public function loadSettings(): void
    {
        $sysTable = Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
        
        try {
            $monEnabled = DB::table($sysTable)->where('key', 'dts_urgent_monitoring_enabled')->value('value');
            $this->monitoringEnabled = ($monEnabled !== 'false');

            $warnMin = DB::table($sysTable)->where('key', 'dts_urgent_warning_minutes')->value('value');
            $this->warningThresholdMinutes = $warnMin !== null ? max(1, (int) $warnMin) : 5;

            $urgentMin = DB::table($sysTable)->where('key', 'dts_urgent_action_minutes')->value('value');
            $this->urgentThresholdMinutes = $urgentMin !== null ? max($this->warningThresholdMinutes + 1, (int) $urgentMin) : 10;
        } catch (\Throwable $e) {
            $this->monitoringEnabled = true;
            $this->warningThresholdMinutes = 5;
            $this->urgentThresholdMinutes = 10;
        }

        $user = Auth::user();
        if ($user) {
            $this->urgentSoundAlert = $user->urgentSoundAlert();
            $this->urgentSoundTone = $user->urgentSoundTone();
        }
    }

    public function loadUrgentItems(): void
    {
        $this->loadSettings();

        if (!$this->monitoringEnabled) {
            $this->urgentItems = [];
            $this->redCount = 0;
            $this->yellowCount = 0;
            $this->greenCount = 0;
            $this->totalCount = 0;
            return;
        }

        $userId = Auth::id();
        if (!$userId) {
            $this->urgentItems = [];
            $this->redCount = 0;
            $this->yellowCount = 0;
            $this->greenCount = 0;
            $this->totalCount = 0;
            return;
        }

        $user = Auth::user();
        $officeCode = $user?->details?->office?->office_code;

        if (!$officeCode) {
            $accDetailsTbl = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
            $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
            $officeCode = DB::table($accDetailsTbl)
                ->join($officeTbl, "{$accDetailsTbl}.office_id", '=', "{$officeTbl}.id")
                ->where("{$accDetailsTbl}.account_id", $userId)
                ->value("{$officeTbl}.office_code");
        }

        if (!$officeCode && !$user?->permissions?->is_sadm) {
            $this->urgentItems = [];
            $this->redCount = 0;
            $this->yellowCount = 0;
            $this->greenCount = 0;
            $this->totalCount = 0;
            return;
        }

        $logsTbl = Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs';
        $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';

        $items = [];

        // 1. Fetch Incoming transactions (awaiting receiving at this office)
        try {
            $incomingQuery = DB::table('dts_transactions as dt')
                ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
                ->leftJoin('dts_transaction_flow as flow', 'flow.flow_code', '=', 'dtd.transaction_flow')
                ->leftJoin("{$officeTbl} as orig_off", 'orig_off.office_code', '=', 'dtd.originated_from')
                ->where('dtd.is_active', 1)
                ->whereNotIn('dt.status', ['completed', 'cancelled', 'drafted']);

            if ($officeCode) {
                $incomingQuery->where(function ($q) use ($officeCode, $logsTbl) {
                    $q->where('dt.current_office', $officeCode)
                      ->orWhereExists(function ($sub) use ($officeCode, $logsTbl) {
                          $sub->select(DB::raw(1))
                              ->from("{$logsTbl} as sub_logs")
                              ->whereColumn('sub_logs.transaction_id', 'dt.transaction_id')
                              ->where('sub_logs.office_code', $officeCode)
                              ->whereNull('sub_logs.date_in');
                      });
                });
            }

            $incomingRecords = $incomingQuery->select(
                'dt.transaction_id',
                'dt.status',
                'dt.qr_code',
                'dt.current_office',
                'dtd.control_number',
                'dtd.subject',
                'dtd.date_created',
                'dtd.originated_from',
                DB::raw("COALESCE(NULLIF(flow.flow_name, ''), flow.referenced_flow, 'DTS Flow') as doc_type_name"),
                'orig_off.office_name as originated_office_name'
            )->limit(50)->get();

            foreach ($incomingRecords as $rec) {
                // Check if already received at current visit
                $isReceived = false;
                if ($officeCode) {
                    $latestOfficeLog = DB::table($logsTbl)
                        ->where('transaction_id', $rec->transaction_id)
                        ->where('office_code', $officeCode)
                        ->orderBy('id', 'desc')
                        ->first();

                    if ($latestOfficeLog && $latestOfficeLog->date_in !== null && $latestOfficeLog->date_out === null) {
                        $isReceived = true;
                    }
                }
                if ($isReceived) {
                    continue;
                }

                // Resolve dispatch/start timestamp
                $forwardLog = DB::table($logsTbl)
                    ->where('transaction_id', $rec->transaction_id)
                    ->where('type', 'forwarded')
                    ->orderBy('id', 'desc')
                    ->first();

                $startedAt = $forwardLog?->date_out ?? $forwardLog?->date_in ?? $rec->date_created;

                $elapsedSeconds = DtsWorkingHoursService::calculateWorkingSeconds($startedAt, now());
                $elapsedMinutes = max(0, (int) round($elapsedSeconds / 60));

                $urgency = $this->classifyUrgency($elapsedMinutes);

                $items[] = [
                    'id' => $rec->transaction_id,
                    'control_number' => $rec->control_number ?: $rec->transaction_id,
                    'subject' => $rec->subject ?: 'No subject specified',
                    'doc_type' => $rec->doc_type_name,
                    'originated_from' => $rec->originated_office_name ?: $rec->originated_from,
                    'stage' => 'incoming',
                    'stage_label' => 'Incoming (Needs Receiving)',
                    'action_url' => route('dts.incoming'),
                    'started_at' => $startedAt,
                    'elapsed_minutes' => $elapsedMinutes,
                    'elapsed_formatted' => $this->formatDuration($elapsedMinutes),
                    'urgency' => $urgency['tier'],
                    'urgency_label' => $urgency['label'],
                    'urgency_desc' => $urgency['desc'],
                ];
            }
        } catch (\Throwable $e) {
            // Graceful fallback on query error
        }

        // 2. Fetch In-Custody / Received transactions (received at this office, waiting for action/forwarding)
        try {
            if ($officeCode) {
                $receivedQuery = DB::table('dts_transactions as dt')
                    ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
                    ->leftJoin('dts_transaction_flow as flow', 'flow.flow_code', '=', 'dtd.transaction_flow')
                    ->leftJoin("{$officeTbl} as orig_off", 'orig_off.office_code', '=', 'dtd.originated_from')
                    ->where('dtd.is_active', 1)
                    ->where('dt.current_office', $officeCode)
                    ->whereNotIn('dt.status', ['completed', 'cancelled', 'drafted']);

                $receivedRecords = $receivedQuery->select(
                    'dt.transaction_id',
                    'dt.status',
                    'dt.qr_code',
                    'dt.current_office',
                    'dtd.control_number',
                    'dtd.subject',
                    'dtd.date_created',
                    'dtd.originated_from',
                    DB::raw("COALESCE(NULLIF(flow.flow_name, ''), flow.referenced_flow, 'DTS Flow') as doc_type_name"),
                    'orig_off.office_name as originated_office_name'
                )->limit(50)->get();

                foreach ($receivedRecords as $rec) {
                    $receiveLog = DB::table($logsTbl)
                        ->where('transaction_id', $rec->transaction_id)
                        ->where('office_code', $officeCode)
                        ->whereNotNull('date_in')
                        ->orderBy('id', 'desc')
                        ->first();

                    if (!$receiveLog) {
                        continue;
                    }

                    // If it was already forwarded out, skip
                    if ($receiveLog->date_out !== null) {
                        continue;
                    }

                    $startedAt = $receiveLog->date_in;
                    $elapsedSeconds = DtsWorkingHoursService::calculateWorkingSeconds($startedAt, now());
                    $elapsedMinutes = max(0, (int) round($elapsedSeconds / 60));

                    $urgency = $this->classifyUrgency($elapsedMinutes);

                    $items[] = [
                        'id' => $rec->transaction_id,
                        'control_number' => $rec->control_number ?: $rec->transaction_id,
                        'subject' => $rec->subject ?: 'No subject specified',
                        'doc_type' => $rec->doc_type_name,
                        'originated_from' => $rec->originated_office_name ?: $rec->originated_from,
                        'stage' => 'in_custody',
                        'stage_label' => 'In-Custody (Needs Forwarding/Action)',
                        'action_url' => route('dts.received'),
                        'started_at' => $startedAt,
                        'elapsed_minutes' => $elapsedMinutes,
                        'elapsed_formatted' => $this->formatDuration($elapsedMinutes),
                        'urgency' => $urgency['tier'],
                        'urgency_label' => $urgency['label'],
                        'urgency_desc' => $urgency['desc'],
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Graceful fallback
        }

        // Sort items by priority: RED first, then Yellow, then Green (each by highest elapsed time)
        usort($items, function ($a, $b) {
            $priority = ['red' => 3, 'yellow' => 2, 'green' => 1];
            $pDiff = ($priority[$b['urgency']] ?? 0) <=> ($priority[$a['urgency']] ?? 0);
            if ($pDiff !== 0) return $pDiff;
            return $b['elapsed_minutes'] <=> $a['elapsed_minutes'];
        });

        $this->urgentItems = $items;
        $this->redCount = count(array_filter($items, fn($i) => $i['urgency'] === 'red'));
        $this->yellowCount = count(array_filter($items, fn($i) => $i['urgency'] === 'yellow'));
        $this->greenCount = count(array_filter($items, fn($i) => $i['urgency'] === 'green'));
        $this->totalCount = count($items);

        // Sound notification alert if new RED item appeared while viewing
        if ($this->hasMounted && $this->redCount > $this->previousRedCount && $this->urgentSoundAlert) {
            $this->dispatchBrowserUrgentPing();
        }
        $this->previousRedCount = $this->redCount;
    }

    /**
     * @param int $minutes
     * @return array{tier: string, label: string, desc: string}
     */
    private function classifyUrgency(int $minutes): array
    {
        if ($minutes > $this->urgentThresholdMinutes) {
            $overdue = $minutes - $this->urgentThresholdMinutes;
            return [
                'tier' => 'red',
                'label' => 'Urgent Action Required',
                'desc' => "Overdue by {$overdue}m (Admin limit: {$this->urgentThresholdMinutes}m)",
            ];
        }

        if ($minutes >= $this->warningThresholdMinutes) {
            $left = $this->urgentThresholdMinutes - $minutes;
            return [
                'tier' => 'yellow',
                'label' => 'Warning Notice',
                'desc' => "Near elapsed limit ({$left}m remaining)",
            ];
        }

        return [
            'tier' => 'green',
            'label' => 'Safe / Newly Created',
            'desc' => "Within initial safe threshold (< {$this->warningThresholdMinutes}m)",
        ];
    }

    private function formatDuration(int $minutes): string
    {
        if ($minutes < 1) {
            return 'Just now';
        }
        if ($minutes < 60) {
            return "{$minutes}m elapsed";
        }
        $hours = intdiv($minutes, 60);
        $remMin = $minutes % 60;
        if ($hours < 24) {
            return $remMin > 0 ? "{$hours}h {$remMin}m elapsed" : "{$hours}h elapsed";
        }
        $days = intdiv($hours, 24);
        $remHours = $hours % 24;
        return $remHours > 0 ? "{$days}d {$remHours}h elapsed" : "{$days}d elapsed";
    }

    private function dispatchBrowserUrgentPing(): void
    {
        $this->js('window.dispatchEvent(new CustomEvent("rms-urgent-alert-ping"));');
    }

    #[On('rms-urgent-tone-changed')]
    public function onToneChanged($tone = 'urgent-alarm'): void
    {
        $this->urgentSoundTone = (string) $tone;
    }

    #[On('rms-urgent-sound-setting-changed')]
    public function onSoundSettingChanged($enabled = true): void
    {
        $this->urgentSoundAlert = (bool) $enabled;
    }
};
?>

<div
    class="urgent-wrapper notif-wrapper"
    wire:poll.15s.keep-alive="loadUrgentItems"
    data-urgent-tone="{{ $urgentSoundTone }}"
    data-urgent-sound-enabled="{{ $urgentSoundAlert ? '1' : '0' }}"
    x-data="{
        open: @entangle('showDropdown'),
        lastAlertAt: 0,
        playUrgentPing() {
            const enabled = $el.getAttribute('data-urgent-sound-enabled') === '1';
            if (!enabled) return;
            const now = Date.now();
            if (now - this.lastAlertAt < 1200) return;
            this.lastAlertAt = now;

            const tone = $el.getAttribute('data-urgent-tone') || 'urgent-alarm';
            if (typeof window.playRMSTonePreview === 'function') {
                window.playRMSTonePreview('urgent', tone);
            } else {
                try {
                    const audio = new Audio('{{ asset('sfx/urgent-alarm.wav') }}');
                    audio.volume = 0.85;
                    audio.play().catch(() => {});
                } catch(e) {}
            }
        }
    }"
    @click.outside="open = false"
    @close-urgent-notices.window="open = false"
    @rms-urgent-alert-ping.window="playUrgentPing()"
>
    <!-- Notice/Urgent Button -->
    <button
        class="notif-bell-btn"
        @click="
            open = !open;
            if (open) {
                $wire.loadUrgentItems();
                if (window.dispatchEvent) {
                    window.dispatchEvent(new CustomEvent('close-notifications'));
                }
                window.closeActionsDropdown ? window.closeActionsDropdown() : (typeof closeActionsDropdown !== 'undefined' ? closeActionsDropdown() : null);
            }
        "
        type="button"
        aria-label="Toggle urgent actions menu"
        title="Urgent Actions & Overdue Transactions"
    >
        <svg class="notif-bell-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
            <line x1="12" y1="9" x2="12" y2="13"></line>
            <line x1="12" y1="17" x2="12.01" y2="17"></line>
        </svg>

        @if($totalCount > 0)
            <span
                class="notif-badge {{ $redCount > 0 ? 'urgent-pulse' : '' }}"
                style="background: {{ $redCount > 0 ? 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)' : ($yellowCount > 0 ? 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)' : 'linear-gradient(135deg, #10b981 0%, #059669 100%)') }} !important;"
                title="{{ $redCount }} urgent, {{ $yellowCount }} warning, {{ $greenCount }} safe"
            >
                {{ $redCount > 0 ? $redCount : ($yellowCount > 0 ? $yellowCount : $totalCount) }}
            </span>
        @endif
    </button>

    <!-- Dropdown Menu (Matches Notification Dropbox Container Design) -->
    <div class="notif-dropdown" x-show="open" x-transition.opacity.scale x-cloak style="right: -25px;">
        <div class="notif-dropdown-header">
            <h3>Urgent Actions</h3>
            <div class="notif-header-actions">
                <button class="notif-mark-all-btn" wire:click="loadUrgentItems" type="button" title="Refresh">
                    Refresh
                </button>
            </div>
        </div>

        <div class="notif-dropdown-body">
            @forelse ($urgentItems as $item)
                @php
                    $dotColor = match ($item['urgency']) {
                        'red' => '#ef4444',
                        'yellow' => '#f59e0b',
                        default => '#10b981',
                    };
                @endphp
                <div class="notif-item" onclick="window.location.href='{{ $item['action_url'] }}'" style="cursor: pointer;" wire:key="urgent-item-{{ $item['id'] }}-{{ $item['stage'] }}">
                    <!-- Status dot indicator on left side based on current configured rule -->
                    <div class="notif-unread-dot" style="background-color: {{ $dotColor }}; box-shadow: 0 0 0 2px {{ $item['urgency'] === 'red' ? 'rgba(239, 68, 68, 0.25)' : ($item['urgency'] === 'yellow' ? 'rgba(245, 158, 11, 0.25)' : 'rgba(16, 185, 129, 0.25)') }};" title="{{ $item['urgency_label'] }}"></div>

                    <!-- Content -->
                    <div class="notif-content-wrapper" style="flex-grow: 1;">
                        <p class="notif-message">
                            <strong>{{ $item['control_number'] }}</strong> — {{ $item['subject'] }}
                        </p>
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 4px;">
                            <span class="notif-time" style="margin-top: 0; font-weight: 700; color: {{ $dotColor }};">
                                {{ $item['urgency_label'] }} ({{ $item['elapsed_formatted'] }})
                            </span>
                            <span class="notif-time" style="margin-top: 0; font-size: 11px;">
                                {{ $item['stage_label'] }}
                            </span>
                        </div>
                    </div>
                </div>
            @empty
                <div class="notif-empty-state notif-empty-all">
                    <svg class="notif-empty-icon" style="margin: 0 auto 12px auto; display: block;" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                        <line x1="12" y1="9" x2="12" y2="13"></line>
                        <line x1="12" y1="17" x2="12.01" y2="17"></line>
                    </svg>
                    <p>No urgent transactions requiring action at this time.</p>
                </div>
            @endforelse
        </div>
    </div>

    @once
    <script>
        (function() {
            if (typeof window.playRMSTonePreview === 'function') return;

            window.playRMSTonePreview = function(category, tone) {
                if (category === 'notif' && tone === 'ping-default') {
                    try {
                        const audio = new Audio("{{ asset('sfx/ping-message.mp3') }}");
                        audio.volume = 0.8;
                        const p = audio.play();
                        if (p && typeof p.catch === 'function') {
                            p.catch(function() {
                                synthesizeAudio(tone);
                            });
                        }
                        return;
                    } catch (e) {}
                }

                if (category === 'urgent' && (tone === 'urgent-alarm' || tone === 'warning-beep')) {
                    try {
                        const filename = tone === 'warning-beep' ? 'warning-beep.wav' : 'urgent-alarm.wav';
                        const audio = new Audio("{{ asset('sfx') }}/" + filename);
                        audio.volume = 0.85;
                        const p = audio.play();
                        if (p && typeof p.catch === 'function') {
                            p.catch(function() {
                                synthesizeAudio(tone);
                            });
                        }
                        return;
                    } catch (e) {}
                }

                synthesizeAudio(tone);
            };

            function getAudioContext() {
                if (!window.__rmsAudioCtx) {
                    const AudioCtx = window.AudioContext || window.webkitAudioContext;
                    if (AudioCtx) {
                        window.__rmsAudioCtx = new AudioCtx();
                    }
                }
                if (window.__rmsAudioCtx && window.__rmsAudioCtx.state === 'suspended') {
                    window.__rmsAudioCtx.resume();
                }
                return window.__rmsAudioCtx;
            }

            function synthesizeAudio(tone) {
                try {
                    const ctx = getAudioContext();
                    if (!ctx) return;
                    const now = ctx.currentTime;

                    if (tone === 'urgent-alarm' || tone === 'siren-alert') {
                        const osc = ctx.createOscillator();
                        const gain = ctx.createGain();
                        osc.type = 'sawtooth';
                        osc.frequency.setValueAtTime(880, now);
                        osc.frequency.exponentialRampToValueAtTime(440, now + 0.15);
                        osc.frequency.exponentialRampToValueAtTime(880, now + 0.3);
                        gain.gain.setValueAtTime(0.28, now);
                        gain.gain.linearRampToValueAtTime(0.01, now + 0.45);
                        osc.connect(gain);
                        gain.connect(ctx.destination);
                        osc.start(now);
                        osc.stop(now + 0.45);
                    } else if (tone === 'warning-beep') {
                        [0, 0.16].forEach(function(delay) {
                            const osc = ctx.createOscillator();
                            const gain = ctx.createGain();
                            osc.type = 'triangle';
                            osc.frequency.setValueAtTime(659.25, now + delay);
                            gain.gain.setValueAtTime(0.28, now + delay);
                            gain.gain.exponentialRampToValueAtTime(0.01, now + delay + 0.12);
                            osc.connect(gain);
                            gain.connect(ctx.destination);
                            osc.start(now + delay);
                            osc.stop(now + delay + 0.12);
                        });
                    } else if (tone === 'radar-pulse') {
                        const osc = ctx.createOscillator();
                        const gain = ctx.createGain();
                        osc.type = 'sine';
                        osc.frequency.setValueAtTime(1046.5, now);
                        gain.gain.setValueAtTime(0.35, now);
                        gain.gain.exponentialRampToValueAtTime(0.001, now + 0.35);
                        osc.connect(gain);
                        gain.connect(ctx.destination);
                        osc.start(now);
                        osc.stop(now + 0.35);
                    } else if (tone === 'chime-soft') {
                        [523.25, 659.25, 783.99].forEach(function(freq, i) {
                            const osc = ctx.createOscillator();
                            const gain = ctx.createGain();
                            osc.type = 'sine';
                            osc.frequency.setValueAtTime(freq, now + (i * 0.08));
                            gain.gain.setValueAtTime(0.22, now + (i * 0.08));
                            gain.gain.exponentialRampToValueAtTime(0.001, now + (i * 0.08) + 0.35);
                            osc.connect(gain);
                            gain.connect(ctx.destination);
                            osc.start(now + (i * 0.08));
                            osc.stop(now + (i * 0.08) + 0.35);
                        });
                    } else if (tone === 'bell-ding') {
                        const osc = ctx.createOscillator();
                        const gain = ctx.createGain();
                        osc.type = 'sine';
                        osc.frequency.setValueAtTime(987.77, now);
                        gain.gain.setValueAtTime(0.3, now);
                        gain.gain.exponentialRampToValueAtTime(0.001, now + 0.5);
                        osc.connect(gain);
                        gain.connect(ctx.destination);
                        osc.start(now);
                        osc.stop(now + 0.5);
                    } else if (tone === 'bubble-pop') {
                        const osc = ctx.createOscillator();
                        const gain = ctx.createGain();
                        osc.type = 'sine';
                        osc.frequency.setValueAtTime(400, now);
                        osc.frequency.exponentialRampToValueAtTime(1200, now + 0.08);
                        gain.gain.setValueAtTime(0.3, now);
                        gain.gain.exponentialRampToValueAtTime(0.01, now + 0.1);
                        osc.connect(gain);
                        gain.connect(ctx.destination);
                        osc.start(now);
                        osc.stop(now + 0.1);
                    } else {
                        const osc = ctx.createOscillator();
                        const gain = ctx.createGain();
                        osc.type = 'sine';
                        osc.frequency.setValueAtTime(800, now);
                        gain.gain.setValueAtTime(0.28, now);
                        gain.gain.exponentialRampToValueAtTime(0.01, now + 0.2);
                        osc.connect(gain);
                        gain.connect(ctx.destination);
                        osc.start(now);
                        osc.stop(now + 0.2);
                    }
                } catch(e) {}
            }
        })();
    </script>
    @endonce
</div>

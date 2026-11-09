<?php

use App\Services\ServerManagementService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Admin Console - Server Diagnostics & Telemetry')] class extends Component {
    public array $metrics = [];
    public array $containers = [];
    public array $cpuHistory = [];
    public array $ramHistory = [];
    public array $diskHistory = [];
    public string $lastRefreshedAt = '';
    public bool $isMultiServer = false;
    public string $serverLabel = '';

    public function mount(ServerManagementService $service): void
    {
        $this->serverLabel = $service->getServerLabel();
        $this->isMultiServer = $service->isMultiServerEnabled();
        $this->initTelemetryHistory($service);
        $this->refreshTelemetry($service);
    }

    protected function initTelemetryHistory(ServerManagementService $service): void
    {
        $m = $service->getHardwareMetrics();
        $currentCpu = $m['cpu']['usage_pct'] ?? 10;
        $currentRam = $m['ram']['usage_pct'] ?? 25;
        $currentDisk = $m['disk']['usage_pct'] ?? 80;

        $this->cpuHistory = session()->get('server_cpu_telemetry_history', []);
        $this->ramHistory = session()->get('server_ram_telemetry_history', []);
        $this->diskHistory = session()->get('server_disk_telemetry_history', []);

        if (count($this->cpuHistory) < 8) {
            $this->cpuHistory = [];
            for ($i = 15; $i >= 0; $i--) {
                $jitter = rand(-3, 3);
                $this->cpuHistory[] = max(1, min(100, $currentCpu + $jitter));
            }
        }

        if (count($this->ramHistory) < 8) {
            $this->ramHistory = [];
            for ($i = 15; $i >= 0; $i--) {
                $jitter = rand(-1, 1);
                $this->ramHistory[] = max(1, min(100, $currentRam + $jitter));
            }
        }

        if (count($this->diskHistory) < 8) {
            $this->diskHistory = [];
            for ($i = 15; $i >= 0; $i--) {
                $this->diskHistory[] = $currentDisk;
            }
        }
    }

    public function refreshTelemetry(?ServerManagementService $service = null): void
    {
        $service = $service ?? app(ServerManagementService::class);
        $this->metrics = $service->getHardwareMetrics();
        $this->containers = $service->getContainersTelemetry();

        $cpuNow = (float) ($this->metrics['cpu']['usage_pct'] ?? 0);
        $ramNow = (float) ($this->metrics['ram']['usage_pct'] ?? 0);
        $diskNow = (float) ($this->metrics['disk']['usage_pct'] ?? 0);

        $this->cpuHistory[] = $cpuNow;
        if (count($this->cpuHistory) > 16) {
            array_shift($this->cpuHistory);
        }

        $this->ramHistory[] = $ramNow;
        if (count($this->ramHistory) > 16) {
            array_shift($this->ramHistory);
        }

        $this->diskHistory[] = $diskNow;
        if (count($this->diskHistory) > 16) {
            array_shift($this->diskHistory);
        }

        session()->put('server_cpu_telemetry_history', $this->cpuHistory);
        session()->put('server_ram_telemetry_history', $this->ramHistory);
        session()->put('server_disk_telemetry_history', $this->diskHistory);

        $this->lastRefreshedAt = now()->format('h:i:s A');
    }
};
?>

@push('styles')
    <style>
        [data-theme="dark"] div[style*="background: #ffffff"],
        [data-theme="dark"] div[style*="background:#ffffff"] {
            background-color: #131c2e !important;
            border-color: #1e293b !important;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3) !important;
        }

        [data-theme="dark"] div[style*="background: #f8fafc"],
        [data-theme="dark"] div[style*="background:#f8fafc"] {
            background-color: #0b1120 !important;
            border-color: #1e293b !important;
        }

        [data-theme="dark"] h1[style*="color: #0f172a"],
        [data-theme="dark"] h2[style*="color: #0f172a"],
        [data-theme="dark"] h3[style*="color: #0f172a"],
        [data-theme="dark"] h4[style*="color: #0f172a"],
        [data-theme="dark"] div[style*="color: #0f172a"],
        [data-theme="dark"] span[style*="color: #0f172a"] {
            color: #f8fafc !important;
        }

        [data-theme="dark"] p[style*="color: #64748b"],
        [data-theme="dark"] span[style*="color: #64748b"] {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] div[style*="border-bottom: 1px solid #f1f5f9"],
        [data-theme="dark"] div[style*="border-top: 1px solid #f1f5f9"],
        [data-theme="dark"] div[style*="border: 1px solid #e2e8f0"] {
            border-color: #1e293b !important;
        }

        .telemetry-svg {
            width: 100%;
            height: 235px;
            overflow: visible;
        }
        .telemetry-grid-line {
            stroke: #e2e8f0;
            stroke-width: 1;
        }
        .telemetry-grid-line.major {
            stroke: #cbd5e1;
            stroke-width: 1.2;
        }
        .telemetry-axis-label {
            fill: #94a3b8;
            font-size: 9px;
            font-weight: 600;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            text-anchor: end;
            dominant-baseline: central;
        }
        .telemetry-time-label {
            fill: #94a3b8;
            font-size: 9px;
            font-weight: 600;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            text-anchor: middle;
        }

        [data-theme="dark"] .telemetry-grid-line {
            stroke: #1e293b !important;
        }
        [data-theme="dark"] .telemetry-grid-line.major {
            stroke: #334155 !important;
        }
        [data-theme="dark"] .telemetry-axis-label,
        [data-theme="dark"] .telemetry-time-label {
            fill: #64748b !important;
        }
        @keyframes livePulse {
            0% { transform: scale(0.92); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1.08); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.92); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }
        .live-pulse-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #10b981;
            animation: livePulse 2s infinite;
        }
    </style>
@endpush

<div class="space-y-6" wire:poll.1s="refreshTelemetry">
    <!-- Header Banner -->
    <div style="background: #ffffff; padding: 24px 28px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div style="display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 24px; box-shadow: 0 4px 8px rgba(37, 99, 235, 0.15);">
                <i class="fa-solid fa-chart-line"></i>
            </div>
            <div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <h1 style="font-size: 22px; font-weight: 800; color: #0f172a; margin: 0; letter-spacing: -0.02em;">Server Diagnostic & Telemetry</h1>
                    <span style="font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 6px; text-transform: uppercase; background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe;">
                        Node: {{ $serverLabel }}
                    </span>
                </div>
                <p style="font-size: 13px; color: #64748b; margin: 4px 0 0 0;">
                    Real-time host hardware monitors, live capacity curves, and per-container resource tracking
                </p>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 12px;">
            <div style="display: flex; align-items: center; gap: 8px; font-size: 12px; color: #475569; background: #f8fafc; padding: 7px 14px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <span class="live-pulse-dot"></span>
                <span>Live Telemetry (1s live auto-poll)</span>
                <span style="font-size: 11.5px; font-weight: 700; color: #0f172a; margin-left: 4px; font-family: monospace;">{{ $lastRefreshedAt ?: 'Active' }}</span>
            </div>

            <button type="button" wire:click="refreshTelemetry" wire:loading.attr="disabled" style="background: #0f172a; color: white; border: none; padding: 10px 16px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-rotate" wire:loading.remove wire:target="refreshTelemetry"></i>
                <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="refreshTelemetry"></i>
                <span>Refresh Now</span>
            </button>
        </div>
    </div>

    <!-- Section 1: Host Hardware Metric Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px;">
        <!-- 1. CPU Usage -->
        <div style="background: #ffffff; padding: 20px; border-radius: 14px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <span style="font-size: 13px; font-weight: 700; color: #64748b; text-transform: uppercase;">CPU Load</span>
                <span style="font-size: 18px; color: #3b82f6;"><i class="fa-solid fa-microchip"></i></span>
            </div>
            <div style="display: flex; align-items: baseline; gap: 8px; margin-bottom: 8px;">
                <span style="font-size: 28px; font-weight: 800; color: #0f172a;">{{ $metrics['cpu']['usage_pct'] ?? 0 }}%</span>
                <span style="font-size: 12px; color: #64748b;">({{ $metrics['cpu']['cores'] ?? 1 }} cores)</span>
            </div>
            <!-- Progress Bar -->
            <div style="height: 6px; width: 100%; background: #f1f5f9; border-radius: 4px; overflow: hidden; margin-bottom: 10px;">
                <div style="height: 100%; width: {{ min(100, $metrics['cpu']['usage_pct'] ?? 0) }}%; background: {{ ($metrics['cpu']['usage_pct'] ?? 0) > 80 ? '#ef4444' : '#3b82f6' }}; border-radius: 4px; transition: width 0.3s;"></div>
            </div>
            <div style="font-size: 11px; color: #64748b; display: flex; justify-content: space-between;">
                <span>Load avg:</span>
                <span>{{ $metrics['cpu']['load_1m'] ?? 0 }} (1m) &bull; {{ $metrics['cpu']['load_5m'] ?? 0 }} (5m)</span>
            </div>
        </div>

        <!-- 2. RAM Memory -->
        <div style="background: #ffffff; padding: 20px; border-radius: 14px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <span style="font-size: 13px; font-weight: 700; color: #64748b; text-transform: uppercase;">RAM Memory</span>
                <span style="font-size: 18px; color: #10b981;"><i class="fa-solid fa-memory"></i></span>
            </div>
            <div style="display: flex; align-items: baseline; gap: 8px; margin-bottom: 8px;">
                <span style="font-size: 28px; font-weight: 800; color: #0f172a;">{{ $metrics['ram']['used_mb'] ?? 0 }} <span style="font-size: 16px; font-weight: 600;">MB</span></span>
                <span style="font-size: 12px; color: #64748b;">/ {{ $metrics['ram']['total_mb'] ?? 1024 }} MB ({{ $metrics['ram']['usage_pct'] ?? 0 }}%)</span>
            </div>
            <div style="height: 6px; width: 100%; background: #f1f5f9; border-radius: 4px; overflow: hidden; margin-bottom: 10px;">
                <div style="height: 100%; width: {{ min(100, $metrics['ram']['usage_pct'] ?? 0) }}%; background: {{ ($metrics['ram']['usage_pct'] ?? 0) > 85 ? '#ef4444' : '#10b981' }}; border-radius: 4px; transition: width 0.3s;"></div>
            </div>
            <div style="font-size: 11px; color: #64748b; display: flex; justify-content: space-between;">
                <span>Free: {{ $metrics['ram']['free_mb'] ?? 0 }} MB</span>
                <span>Swap: {{ $metrics['ram']['swap_used_mb'] ?? 0 }}/{{ $metrics['ram']['swap_total_mb'] ?? 0 }} MB</span>
            </div>
        </div>

        <!-- 3. Disk Storage -->
        <div style="background: #ffffff; padding: 20px; border-radius: 14px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <span style="font-size: 13px; font-weight: 700; color: #64748b; text-transform: uppercase;">Disk Storage</span>
                <span style="font-size: 18px; color: #8b5cf6;"><i class="fa-solid fa-hard-drive"></i></span>
            </div>
            <div style="display: flex; align-items: baseline; gap: 8px; margin-bottom: 8px;">
                <span style="font-size: 28px; font-weight: 800; color: #0f172a;">{{ $metrics['disk']['used_gb'] ?? 0 }} <span style="font-size: 16px; font-weight: 600;">GB</span></span>
                <span style="font-size: 12px; color: #64748b;">/ {{ $metrics['disk']['total_gb'] ?? 0 }} GB</span>
            </div>
            <div style="height: 6px; width: 100%; background: #f1f5f9; border-radius: 4px; overflow: hidden; margin-bottom: 10px;">
                <div style="height: 100%; width: {{ min(100, $metrics['disk']['usage_pct'] ?? 0) }}%; background: {{ ($metrics['disk']['usage_pct'] ?? 0) > 90 ? '#ef4444' : '#8b5cf6' }}; border-radius: 4px; transition: width 0.3s;"></div>
            </div>
            <div style="font-size: 11px; color: #64748b; display: flex; justify-content: space-between;">
                <span>Available: {{ $metrics['disk']['free_gb'] ?? 0 }} GB</span>
                <span>{{ $metrics['disk']['usage_pct'] ?? 0 }}% used</span>
            </div>
        </div>

        <!-- 4. Host Environment -->
        <div style="background: #ffffff; padding: 20px; border-radius: 14px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <span style="font-size: 13px; font-weight: 700; color: #64748b; text-transform: uppercase;">Environment</span>
                <span style="font-size: 18px; color: #f59e0b;"><i class="fa-solid fa-layer-group"></i></span>
            </div>
            <div style="display: flex; align-items: baseline; gap: 8px; margin-bottom: 8px;">
                <span style="font-size: 24px; font-weight: 800; color: #0f172a;">PHP {{ $metrics['host']['php_version'] ?? PHP_VERSION }}</span>
            </div>
            <div style="font-size: 12px; color: #475569; margin-bottom: 6px;">
                <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #10b981; margin-right: 6px;"></span>
                {{ ($metrics['host']['is_docker'] ?? true) ? 'Docker Containerized' : 'Baremetal Host' }}
            </div>
            <div style="font-size: 11px; color: #64748b;">
                Server: {{ $metrics['host']['server_software'] ?? 'Nginx' }}
            </div>
        </div>
    </div>

    <!-- Section 2: Host Telemetry Curves & Trends (Line Graphs) -->
    <div style="background: #ffffff; padding: 24px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
        <div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div>
                <h3 style="font-size: 16px; font-weight: 800; color: #0f172a; margin: 0;">Server Telemetry Trend Curves</h3>
                <p style="font-size: 12px; color: #64748b; margin: 2px 0 0 0;">Live 1-second monitoring sampling sequences</p>
            </div>
            <div style="display: flex; gap: 14px; font-size: 11.5px; font-weight: 700;">
                <span style="display: flex; align-items: center; gap: 6px; color: #2563eb;">
                    <span style="width: 10px; height: 3px; background: #2563eb; border-radius: 2px;"></span> CPU Load %
                </span>
                <span style="display: flex; align-items: center; gap: 6px; color: #10b981;">
                    <span style="width: 10px; height: 3px; background: #10b981; border-radius: 2px;"></span> RAM Allocated %
                </span>
                <span style="display: flex; align-items: center; gap: 6px; color: #8b5cf6;">
                    <span style="width: 10px; height: 3px; background: #8b5cf6; border-radius: 2px;"></span> Disk Used %
                </span>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 18px;">
            @php
                // Helper to render responsive SVG polyline and exact 20x20 square grid
                $buildSvgGraph = function(array $history, string $color, string $gradId) {
                    $gridLeft = 42;
                    $gridRight = 342;
                    $gridTop = 15;
                    $gridBottom = 215;
                    $step = 20; // 20px x 20px square grid cells

                    $count = count($history);
                    $samples = $history;
                    if ($count < 16) {
                        $firstVal = $history[0] ?? 0;
                        $pad = array_fill(0, 16 - $count, $firstVal);
                        $samples = array_merge($pad, $history);
                    } elseif ($count > 16) {
                        $samples = array_slice($history, -16);
                    }

                    $coords = [];
                    $pointsStr = '';

                    foreach ($samples as $idx => $val) {
                        $x = $gridLeft + ($idx * $step);
                        $clampedVal = max(0, min(100, $val));
                        $y = round($gridBottom - (($clampedVal / 100) * ($gridBottom - $gridTop)), 1);
                        $coords[] = [$x, $y];
                        $pointsStr .= "{$x},{$y} ";
                    }

                    $pointsStr = trim($pointsStr);
                    $first = $coords[0] ?? [$gridLeft, $gridBottom];
                    $last = $coords[count($coords) - 1] ?? [$gridRight, $gridBottom];
                    $fillPath = "M {$gridLeft},{$gridBottom} L {$first[0]},{$first[1]} L {$pointsStr} L {$last[0]},{$gridBottom} Z";

                    return [
                        'points' => $pointsStr,
                        'fill' => $fillPath,
                        'lastX' => $last[0],
                        'lastY' => $last[1],
                        'current' => end($history) ?: 0,
                    ];
                };

                $cpuGraph = $buildSvgGraph($cpuHistory, '#2563eb', 'cpuGrad');
                $ramGraph = $buildSvgGraph($ramHistory, '#10b981', 'ramGrad');
                $diskGraph = $buildSvgGraph($diskHistory, '#8b5cf6', 'diskGrad');
                $elevations = [100, 90, 80, 70, 60, 50, 40, 30, 20, 10, 0];
            @endphp

            <!-- 1. CPU Telemetry Line Graph -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <div>
                        <span style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase;">CPU Activity Trend</span>
                        <div style="font-size: 20px; font-weight: 800; color: #2563eb;">{{ $cpuGraph['current'] }}%</div>
                    </div>
                    <span style="font-size: 11px; color: #64748b; background: #ffffff; border: 1px solid #e2e8f0; padding: 2px 8px; border-radius: 6px;">Peak: {{ max($cpuHistory ?: [0]) }}%</span>
                </div>
                <svg class="telemetry-svg" viewBox="0 0 365 240" preserveAspectRatio="xMidYMid meet">
                    <defs>
                        <linearGradient id="cpuGrad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#2563eb" stop-opacity="0.25"/>
                            <stop offset="100%" stop-color="#2563eb" stop-opacity="0.0"/>
                        </linearGradient>
                    </defs>

                    <!-- Horizontal Grid Lines & 0%-100% Elevation Labels -->
                    @foreach($elevations as $elev)
                        @php
                            $y = 15 + ((100 - $elev) * 2);
                            $isMajor = ($elev === 0 || $elev === 50 || $elev === 100);
                        @endphp
                        <text x="36" y="{{ $y }}" class="telemetry-axis-label">{{ $elev }}%</text>
                        <line x1="42" y1="{{ $y }}" x2="342" y2="{{ $y }}" class="telemetry-grid-line {{ $isMajor ? 'major' : '' }}" stroke-dasharray="{{ $isMajor ? 'none' : '2,2' }}"/>
                    @endforeach

                    <!-- Vertical Grid Lines (Exact 20x20 Squares) -->
                    @for($vx = 42; $vx <= 342; $vx += 20)
                        @php
                            $isBound = ($vx === 42 || $vx === 342);
                        @endphp
                        <line x1="{{ $vx }}" y1="15" x2="{{ $vx }}" y2="215" class="telemetry-grid-line {{ $isBound ? 'major' : '' }}" stroke-dasharray="{{ $isBound ? 'none' : '2,2' }}"/>
                    @endfor

                    <!-- Time Markers at Bottom -->
                    <text x="42" y="230" class="telemetry-time-label">-15s</text>
                    <text x="142" y="230" class="telemetry-time-label">-10s</text>
                    <text x="242" y="230" class="telemetry-time-label">-5s</text>
                    <text x="342" y="230" class="telemetry-time-label" font-weight="700">Live</text>

                    <!-- Gradient Fill Area -->
                    <path d="{{ $cpuGraph['fill'] }}" fill="url(#cpuGrad)"/>

                    <!-- Polyline Curve -->
                    <polyline fill="none" stroke="#2563eb" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" points="{{ $cpuGraph['points'] }}"/>

                    <!-- Live Pulse Head Circle -->
                    <circle cx="{{ $cpuGraph['lastX'] }}" cy="{{ $cpuGraph['lastY'] }}" r="4" fill="#2563eb" stroke="#ffffff" stroke-width="2"/>
                </svg>
            </div>

            <!-- 2. RAM Telemetry Line Graph -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <div>
                        <span style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase;">Memory Allocation Trend</span>
                        <div style="font-size: 20px; font-weight: 800; color: #10b981;">{{ $ramGraph['current'] }}%</div>
                    </div>
                    <span style="font-size: 11px; color: #64748b; background: #ffffff; border: 1px solid #e2e8f0; padding: 2px 8px; border-radius: 6px;">Peak: {{ max($ramHistory ?: [0]) }}%</span>
                </div>
                <svg class="telemetry-svg" viewBox="0 0 365 240" preserveAspectRatio="xMidYMid meet">
                    <defs>
                        <linearGradient id="ramGrad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#10b981" stop-opacity="0.25"/>
                            <stop offset="100%" stop-color="#10b981" stop-opacity="0.0"/>
                        </linearGradient>
                    </defs>

                    <!-- Horizontal Grid Lines & 0%-100% Elevation Labels -->
                    @foreach($elevations as $elev)
                        @php
                            $y = 15 + ((100 - $elev) * 2);
                            $isMajor = ($elev === 0 || $elev === 50 || $elev === 100);
                        @endphp
                        <text x="36" y="{{ $y }}" class="telemetry-axis-label">{{ $elev }}%</text>
                        <line x1="42" y1="{{ $y }}" x2="342" y2="{{ $y }}" class="telemetry-grid-line {{ $isMajor ? 'major' : '' }}" stroke-dasharray="{{ $isMajor ? 'none' : '2,2' }}"/>
                    @endforeach

                    <!-- Vertical Grid Lines (Exact 20x20 Squares) -->
                    @for($vx = 42; $vx <= 342; $vx += 20)
                        @php
                            $isBound = ($vx === 42 || $vx === 342);
                        @endphp
                        <line x1="{{ $vx }}" y1="15" x2="{{ $vx }}" y2="215" class="telemetry-grid-line {{ $isBound ? 'major' : '' }}" stroke-dasharray="{{ $isBound ? 'none' : '2,2' }}"/>
                    @endfor

                    <!-- Time Markers at Bottom -->
                    <text x="42" y="230" class="telemetry-time-label">-15s</text>
                    <text x="142" y="230" class="telemetry-time-label">-10s</text>
                    <text x="242" y="230" class="telemetry-time-label">-5s</text>
                    <text x="342" y="230" class="telemetry-time-label" font-weight="700">Live</text>

                    <!-- Gradient Fill Area -->
                    <path d="{{ $ramGraph['fill'] }}" fill="url(#ramGrad)"/>

                    <!-- Polyline Curve -->
                    <polyline fill="none" stroke="#10b981" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" points="{{ $ramGraph['points'] }}"/>

                    <!-- Live Pulse Head Circle -->
                    <circle cx="{{ $ramGraph['lastX'] }}" cy="{{ $ramGraph['lastY'] }}" r="4" fill="#10b981" stroke="#ffffff" stroke-width="2"/>
                </svg>
            </div>

            <!-- 3. Disk Storage Trend -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <div>
                        <span style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase;">Disk Capacity Curve</span>
                        <div style="font-size: 20px; font-weight: 800; color: #8b5cf6;">{{ $diskGraph['current'] }}%</div>
                    </div>
                    <span style="font-size: 11px; color: #64748b; background: #ffffff; border: 1px solid #e2e8f0; padding: 2px 8px; border-radius: 6px;">Available: {{ $metrics['disk']['free_gb'] ?? 0 }} GB</span>
                </div>
                <svg class="telemetry-svg" viewBox="0 0 365 240" preserveAspectRatio="xMidYMid meet">
                    <defs>
                        <linearGradient id="diskGrad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#8b5cf6" stop-opacity="0.25"/>
                            <stop offset="100%" stop-color="#8b5cf6" stop-opacity="0.0"/>
                        </linearGradient>
                    </defs>

                    <!-- Horizontal Grid Lines & 0%-100% Elevation Labels -->
                    @foreach($elevations as $elev)
                        @php
                            $y = 15 + ((100 - $elev) * 2);
                            $isMajor = ($elev === 0 || $elev === 50 || $elev === 100);
                        @endphp
                        <text x="36" y="{{ $y }}" class="telemetry-axis-label">{{ $elev }}%</text>
                        <line x1="42" y1="{{ $y }}" x2="342" y2="{{ $y }}" class="telemetry-grid-line {{ $isMajor ? 'major' : '' }}" stroke-dasharray="{{ $isMajor ? 'none' : '2,2' }}"/>
                    @endforeach

                    <!-- Vertical Grid Lines (Exact 20x20 Squares) -->
                    @for($vx = 42; $vx <= 342; $vx += 20)
                        @php
                            $isBound = ($vx === 42 || $vx === 342);
                        @endphp
                        <line x1="{{ $vx }}" y1="15" x2="{{ $vx }}" y2="215" class="telemetry-grid-line {{ $isBound ? 'major' : '' }}" stroke-dasharray="{{ $isBound ? 'none' : '2,2' }}"/>
                    @endfor

                    <!-- Time Markers at Bottom -->
                    <text x="42" y="230" class="telemetry-time-label">-15s</text>
                    <text x="142" y="230" class="telemetry-time-label">-10s</text>
                    <text x="242" y="230" class="telemetry-time-label">-5s</text>
                    <text x="342" y="230" class="telemetry-time-label" font-weight="700">Live</text>

                    <!-- Gradient Fill Area -->
                    <path d="{{ $diskGraph['fill'] }}" fill="url(#diskGrad)"/>

                    <!-- Polyline Curve -->
                    <polyline fill="none" stroke="#8b5cf6" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" points="{{ $diskGraph['points'] }}"/>

                    <!-- Live Pulse Head Circle -->
                    <circle cx="{{ $diskGraph['lastX'] }}" cy="{{ $diskGraph['lastY'] }}" r="4" fill="#8b5cf6" stroke="#ffffff" stroke-width="2"/>
                </svg>
            </div>
        </div>
    </div>

    <!-- Section 3: Docker Containers Telemetry (Per Container) -->
    <div style="background: #ffffff; padding: 24px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
        <div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div>
                <h3 style="font-size: 16px; font-weight: 800; color: #0f172a; margin: 0;">Docker Services Telemetry (Container Level)</h3>
                <p style="font-size: 12px; color: #64748b; margin: 2px 0 0 0;">Granular runtime CPU, Memory footprint, Threads (PIDs), and I/O per microservice container</p>
            </div>
            <span style="font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 20px; background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0;">
                <i class="fa-solid fa-cubes" style="margin-right: 4px;"></i> {{ count($containers) }} Monitored Services
            </span>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px;">
            @foreach ($containers as $cid => $c)
                @php
                    $isRunning = ($c['status'] === 'running');
                    $accent = $c['color'] ?? '#2563eb';
                @endphp
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 14px; padding: 18px; display: flex; flex-direction: column; gap: 14px; transition: transform 0.15s ease, box-shadow 0.15s ease;">
                    <!-- Card Top: Name, Status -->
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px;">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="width: 40px; height: 40px; border-radius: 10px; background: {{ $isRunning ? '#eff6ff' : '#f1f5f9' }}; color: {{ $accent }}; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; box-shadow: 0 2px 6px rgba(0,0,0,0.05);">
                                <i class="{{ $c['icon'] ?? 'fa-solid fa-cube' }}"></i>
                            </div>
                            <div>
                                <div style="font-size: 14px; font-weight: 800; color: #0f172a; line-height: 1.2;">{{ $c['name'] }}</div>
                                <div style="font-size: 11px; color: #64748b; margin-top: 2px;">
                                    <code>{{ $c['id'] }}</code> &bull; <span style="text-transform: uppercase;">{{ $c['service'] }}</span>
                                </div>
                            </div>
                        </div>

                        <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 3px 8px; border-radius: 6px; background: {{ $isRunning ? '#dcfce7' : '#fee2e2' }}; color: {{ $isRunning ? '#15803d' : '#b91c1c' }}; flex-shrink: 0;">
                            {{ $c['status'] }}
                        </span>
                    </div>

                    <!-- CPU & Memory Gauges -->
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <!-- CPU Usage -->
                        <div>
                            <div style="display: flex; justify-content: space-between; font-size: 11.5px; font-weight: 700; margin-bottom: 4px;">
                                <span style="color: #64748b;">CPU Utilization</span>
                                <span style="color: {{ ($c['cpu_pct'] ?? 0) > 80 ? '#ef4444' : '#2563eb' }};">{{ $c['cpu_pct'] }}%</span>
                            </div>
                            <div style="height: 6px; width: 100%; background: #e2e8f0; border-radius: 4px; overflow: hidden;">
                                <div style="height: 100%; width: {{ min(100, $c['cpu_pct'] ?? 0) }}%; background: {{ ($c['cpu_pct'] ?? 0) > 80 ? '#ef4444' : '#2563eb' }}; border-radius: 4px; transition: width 0.3s;"></div>
                            </div>
                        </div>

                        <!-- Memory Usage -->
                        <div>
                            <div style="display: flex; justify-content: space-between; font-size: 11.5px; font-weight: 700; margin-bottom: 4px;">
                                <span style="color: #64748b;">Memory Footprint</span>
                                <span style="color: {{ ($c['mem_pct'] ?? 0) > 80 ? '#ef4444' : '#10b981' }};">
                                    {{ $c['mem_usage_str'] ?? '0 MB' }} ({{ $c['mem_pct'] }}%)
                                </span>
                            </div>
                            <div style="height: 6px; width: 100%; background: #e2e8f0; border-radius: 4px; overflow: hidden;">
                                <div style="height: 100%; width: {{ min(100, $c['mem_pct'] ?? 0) }}%; background: {{ ($c['mem_pct'] ?? 0) > 80 ? '#ef4444' : '#10b981' }}; border-radius: 4px; transition: width 0.3s;"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Bottom Details Bar: Pids, Network IO, Disk IO -->
                    <div style="padding-top: 10px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; font-size: 11px; color: #64748b;">
                        <span title="Active OS threads / processes inside container">
                            <i class="fa-solid fa-microchip" style="color: #64748b; margin-right: 3px;"></i>
                            <strong>{{ $c['pids'] ?? 0 }}</strong> PIDs
                        </span>
                        <span title="Network RX / TX">
                            <i class="fa-solid fa-network-wired" style="color: #64748b; margin-right: 3px;"></i>
                            {{ $c['net_io'] ?? '0B/0B' }}
                        </span>
                        <span title="Block Storage I/O">
                            <i class="fa-solid fa-hard-drive" style="color: #64748b; margin-right: 3px;"></i>
                            {{ $c['block_io'] ?? '0B' }}
                        </span>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>

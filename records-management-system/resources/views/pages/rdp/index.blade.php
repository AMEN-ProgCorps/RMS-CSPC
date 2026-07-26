<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

new #[Layout('layouts.rdp')] #[Title('Records Disposition Program - Landing Page')] class extends Component {
    public string $search = '';

    // Add New Series Quick Modal
    public bool $showAddSeriesModal = false;
    public string $newSeriesTitle = '';
    public ?string $newItemNumber = '';
    public string $newRemarks = '';
    public ?int $newParentId = null;

    public function openAddSeriesModal(): void
    {
        $this->showAddSeriesModal = true;
    }

    public function closeAddSeriesModal(): void
    {
        $this->showAddSeriesModal = false;
        $this->newSeriesTitle = '';
        $this->newItemNumber = '';
        $this->newRemarks = '';
        $this->newParentId = null;
    }

    public function saveNewSeries(): void
    {
        if (empty(trim($this->newSeriesTitle))) return;

        $itemNum = trim((string)$this->newItemNumber) !== '' ? (int)$this->newItemNumber : null;
        $isVerified = !is_null($itemNum) && empty($this->newParentId);

        $id = DB::table('rdp_record_series')->insertGetId([
            'series_title' => trim($this->newSeriesTitle),
            'parent_id'    => $this->newParentId ?: null,
            'item_number'  => $isVerified ? $itemNum : null,
            'is_verified'  => $isVerified,
            'remarks'      => trim($this->newRemarks) ?: null,
        ]);

        // Create associated rdp_record default row
        DB::table('rdp_record')->insert([
            'record_series_id' => $id,
            'volume'           => '0.5 cu. m.',
            'records_location' => 'Records Office',
            'frequence_use'    => 'Monthly',
            'time_value'       => 'T',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        // Audit Log
        DB::table('admin_logs')->insert([
            'admin_id'    => auth()->id() ?? 1,
            'changes'     => 'Added new Record Series via RDP Landing Page: "' . $this->newSeriesTitle . '"',
            'what_system' => 2,
            'when_changes'=> now(),
        ]);

        $this->closeAddSeriesModal();
    }

    public function with(): array
    {
        $user = Auth::user();

        // 1. Core Statistics
        $totalSeries = DB::table('rdp_record_series')->count();
        $verifiedSeries = DB::table('rdp_record_series')->where('is_verified', true)->count();
        $unverifiedSeries = DB::table('rdp_record_series')->where('is_verified', false)->count();

        // Permanent vs Temporary Breakdown
        $permanentSeries = DB::table('rdp_record_series')
            ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
            ->where(function($q) {
                $q->where('rdp_record_series.is_retention_period_permanent', true)
                  ->orWhere('rdp_retention_period.total_period', 'ilike', '%permanent%');
            })
            ->count();

        $temporarySeries = max(0, $totalSeries - $permanentSeries);
        $permanentPercent = $totalSeries > 0 ? round(($permanentSeries / $totalSeries) * 100) : 0;
        $temporaryPercent = $totalSeries > 0 ? round(($temporarySeries / $totalSeries) * 100) : 0;

        // Total managed document files
        $totalFiles = DB::table('document_data')->count();

        // Parent options for create modal
        $parentOptions = DB::table('rdp_record_series')
            ->whereNull('parent_id')
            ->select('id', 'series_title', 'item_number')
            ->orderBy('series_title')
            ->get();

        // Series List with Live Search
        $seriesQuery = DB::table('rdp_record_series')
            ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
            ->leftJoin('rdp_record_series as parent', 'rdp_record_series.parent_id', '=', 'parent.id')
            ->leftJoin('rdp_record', 'rdp_record_series.id', '=', 'rdp_record.record_series_id')
            ->select([
                'rdp_record_series.*',
                'rdp_retention_period.active_period',
                'rdp_retention_period.storage_period',
                'rdp_retention_period.total_period',
                'parent.series_title as parent_title',
                'rdp_record.volume as rec_volume',
                'rdp_record.records_location as rec_location',
                'rdp_record.frequence_use as rec_freq',
            ]);

        if (!empty($this->search)) {
            $seriesQuery->where(function ($q) {
                $q->where('rdp_record_series.series_title', 'ilike', '%' . $this->search . '%')
                  ->orWhere('rdp_record_series.remarks', 'ilike', '%' . $this->search . '%')
                  ->orWhere('parent.series_title', 'ilike', '%' . $this->search . '%')
                  ->orWhereCast('rdp_record_series.item_number', 'text', 'like', '%' . $this->search . '%');
            });
        }

        $seriesList = $seriesQuery->orderByRaw('rdp_record_series.item_number ASC NULLS LAST, rdp_record_series.series_title ASC')
            ->limit(10)
            ->get();

        return [
            'totalSeries'      => $totalSeries,
            'verifiedSeries'   => $verifiedSeries,
            'unverifiedSeries' => $unverifiedSeries,
            'permanentSeries'  => $permanentSeries,
            'temporarySeries'  => $temporarySeries,
            'permanentPercent' => $permanentPercent,
            'temporaryPercent' => $temporaryPercent,
            'totalFiles'       => $totalFiles,
            'parentOptions'    => $parentOptions,
            'seriesList'       => $seriesList,
            'userName'         => $user?->details?->first_name ?: ($user->username ?? 'Officer'),
            'userOffice'       => $user?->details?->office?->office_name ?: 'Records & Freedom of Info Office',
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/admin/console.css'])
@endpush

<div style="padding: 28px; background: #f8fafc; min-height: 100vh; font-family: 'Inter', system-ui, -apple-system, sans-serif;">
    <style>
        /* Monochromatic Blue Design Tokens */
        :root {
            --blue-900: #0f172a;
            --blue-800: #1e3a8a;
            --blue-700: #1d4ed8;
            --blue-600: #2563eb;
            --blue-500: #3b82f6;
            --blue-400: #60a5fa;
            --blue-300: #93c5fd;
            --blue-200: #bfdbfe;
            --blue-100: #dbeafe;
            --blue-50:  #eff6ff;
            --blue-ice: #f0f9ff;
        }

        .rdp-hero-banner {
            background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 55%, #2563eb 100%);
            border-radius: 18px;
            padding: 32px 36px;
            color: #ffffff;
            box-shadow: 0 16px 36px -10px rgba(30, 58, 138, 0.35);
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            position: relative;
            overflow: hidden;
        }

        .rdp-hero-banner::before {
            content: '';
            position: absolute;
            top: -40%;
            right: -10%;
            width: 360px;
            height: 360px;
            background: rgba(255, 255, 255, 0.07);
            border-radius: 50%;
            pointer-events: none;
        }

        .hero-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 20px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 13.5px;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        
        .hero-btn-light {
            background: #ffffff;
            color: #1e3a8a;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .hero-btn-light:hover {
            background: #eff6ff;
            transform: translateY(-2px);
        }

        .hero-btn-trans {
            background: rgba(255, 255, 255, 0.15);
            color: #ffffff;
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.25);
        }
        .hero-btn-trans:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-2px);
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 20px 22px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.02);
            transition: all 0.25s ease;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px -5px rgba(37, 99, 235, 0.1);
            border-color: #bfdbfe;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .modules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }

        /* Clean uniform Module Card with no top border line */
        .module-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 22px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.02);
            transition: all 0.25s ease;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .module-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 24px -5px rgba(37, 99, 235, 0.12);
            border-color: #93c5fd;
        }

        .dashboard-section {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 22px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.02);
            margin-bottom: 24px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
            text-align: left;
        }

        .data-table th {
            background: #f8fafc;
            padding: 12px 16px;
            font-weight: 700;
            color: #334155;
            border-bottom: 2px solid #e2e8f0;
        }

        .data-table td {
            padding: 13px 16px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            color: #0f172a;
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        /* Modal Overlay & Card Styling */
        .modal-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.65); backdrop-filter: blur(4px); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .modal-dialog { background: #ffffff; width: 100%; max-width: 580px; border-radius: 16px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); padding: 24px; }
        
        .nap-btn { padding: 8px 16px; border-radius: 8px; font-weight: 700; font-size: 12.5px; cursor: pointer; border: none; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
        .nap-btn-primary { background: #2563eb; color: #ffffff; }
        .nap-btn-primary:hover { background: #1d4ed8; }
        .nap-btn-secondary { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
        .nap-btn-secondary:hover { background: #dbeafe; }

        /* FAB Bar */
        .rdp-fab-nav {
            position: fixed;
            right: 32px;
            bottom: 32px;
            display: flex;
            gap: 12px;
            z-index: 1100;
            align-items: center;
        }

        .rdp-fab-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            background: #1e3a8a;
            color: #ffffff;
            border-radius: 12px;
            box-shadow: 0 8px 20px rgba(30, 58, 138, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.15);
            cursor: pointer;
            font-weight: 700;
            font-size: 13px;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .rdp-fab-btn:hover {
            background: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 12px 24px rgba(37, 99, 235, 0.35);
        }
    </style>

    <!-- RDP Welcome Command Center Hero Banner -->
    <div class="rdp-hero-banner">
        <div style="position: relative; z-index: 2; max-width: 650px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                <span style="background: rgba(255, 255, 255, 0.18); backdrop-filter: blur(8px); padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">
                    Records Disposition Command Center
                </span>
            </div>
            <h1 style="font-size: 28px; font-weight: 800; margin: 0 0 8px 0; letter-spacing: -0.5px; line-height: 1.2;">
                Records Disposition Program (RDP)
            </h1>
            <p style="font-size: 14px; opacity: 0.92; margin: 0; line-height: 1.5;">
                Welcome, <strong>{{ $userName }}</strong>. Primary portal for records appraisal schedules, inventory classification, and official National Archives disposition reports.
            </p>
        </div>

        <div style="display: flex; gap: 12px; position: relative; z-index: 2; flex-wrap: wrap;">
            <button type="button" wire:click="openAddSeriesModal" class="hero-btn hero-btn-light">
                ➕ New Record Series
            </button>
            <a href="{{ route('rdp.manage-files') }}" class="hero-btn hero-btn-trans">
                📂 Manage Office Files
            </a>
        </div>
    </div>

    <!-- Live Statistics Cards -->
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: #eff6ff; color: #1d4ed8;">
                📁
            </div>
            <div>
                <div style="font-size: 24px; font-weight: 800; color: #0f172a; line-height: 1.1;">
                    {{ number_format($totalSeries) }}
                </div>
                <div style="font-size: 12px; font-weight: 600; color: #64748b; margin-top: 4px;">
                    Total Record Series
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background: #dbeafe; color: #1e40af;">
                ✅
            </div>
            <div>
                <div style="font-size: 24px; font-weight: 800; color: #0f172a; line-height: 1.1;">
                    {{ number_format($verifiedSeries) }}
                </div>
                <div style="font-size: 12px; font-weight: 600; color: #64748b; margin-top: 4px;">
                    Verified Series (GRDS)
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background: #e0f2fe; color: #0369a1;">
                ℹ️
            </div>
            <div>
                <div style="font-size: 24px; font-weight: 800; color: #0f172a; line-height: 1.1;">
                    {{ number_format($unverifiedSeries) }}
                </div>
                <div style="font-size: 12px; font-weight: 600; color: #64748b; margin-top: 4px;">
                    Unverified Series
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background: #f1f5f9; color: #1e293b;">
                💻
            </div>
            <div>
                <div style="font-size: 24px; font-weight: 800; color: #0f172a; line-height: 1.1;">
                    {{ number_format($totalFiles) }}
                </div>
                <div style="font-size: 12px; font-weight: 600; color: #64748b; margin-top: 4px;">
                    Repository Files
                </div>
            </div>
        </div>
    </div>

    <!-- Retention Ratios Visual Progress Breakdown -->
    <div class="dashboard-section">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
            <div>
                <h3 style="font-size: 16px; font-weight: 800; color: #0f172a; margin: 0;">
                    📊 Retention Period Distribution Ratio
                </h3>
                <span style="font-size: 12.5px; color: #64748b;">Permanent vs. Temporary record series classification ratio.</span>
            </div>
            <div style="font-size: 13px; font-weight: 700; color: #1e3a8a;">
                {{ $permanentSeries }} Permanent &nbsp;|&nbsp; {{ $temporarySeries }} Temporary
            </div>
        </div>

        <!-- Progress Bar (Blue Tones) -->
        <div style="width: 100%; height: 14px; background: #e0f2fe; border-radius: 10px; overflow: hidden; display: flex; box-shadow: inset 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 12px;">
            <div style="width: {{ $permanentPercent }}%; background: linear-gradient(90deg, #1d4ed8, #3b82f6); height: 100%; transition: width 0.5s ease;" title="Permanent Series ({{ $permanentPercent }}%)"></div>
            <div style="width: {{ $temporaryPercent }}%; background: linear-gradient(90deg, #60a5fa, #93c5fd); height: 100%; transition: width 0.5s ease;" title="Temporary Series ({{ $temporaryPercent }}%)"></div>
        </div>

        <div style="display: flex; gap: 24px; font-size: 12.5px; font-weight: 600;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="width: 12px; height: 12px; border-radius: 3px; background: #1d4ed8; display: inline-block;"></span>
                <span>Permanent Retention: <strong>{{ $permanentPercent }}%</strong> ({{ $permanentSeries }} Series)</span>
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="width: 12px; height: 12px; border-radius: 3px; background: #60a5fa; display: inline-block;"></span>
                <span>Temporary Retention: <strong>{{ $temporaryPercent }}%</strong> ({{ $temporarySeries }} Series)</span>
            </div>
        </div>
    </div>

    <!-- Core Functional Modules & Reports Grid (Clean Cards Without Top Border Line) -->
    <div style="margin-bottom: 14px;">
        <h3 style="font-size: 17px; font-weight: 800; color: #0f172a; margin: 0 0 16px 0; display: flex; align-items: center; gap: 8px;">
            🚀 Primary RDP Modules & Official Reports
        </h3>
    </div>

    <div class="modules-grid">
        <!-- NAP Form 1 -->
        <div class="module-card">
            <div>
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                    <span style="font-size: 11px; font-weight: 800; color: #1e40af; background: #eff6ff; padding: 4px 10px; border-radius: 6px; text-transform: uppercase;">
                        NAP FORM 1
                    </span>
                    <span style="font-size: 11px; color: #64748b; font-weight: 600;">Landscape Layout</span>
                </div>
                <h4 style="font-size: 16px; font-weight: 800; color: #0f172a; margin: 0 0 6px 0;">
                    Records Inventory and Appraisal
                </h4>
                <p style="font-size: 13px; color: #64748b; margin: 0; line-height: 1.5;">
                    Official 12-column inventory schedule with volume, period covered, frequency, and signature controls.
                </p>
            </div>
            <div style="display: flex; gap: 8px; margin-top: 20px;">
                <a href="{{ route('rdp.add-records.inventory-and-appraisal') }}" class="nap-btn nap-btn-secondary" style="flex: 1; justify-content: center;">
                    📋 Appraisal
                </a>
                <a href="{{ route('rdp.reports.nap-form-1') }}" class="nap-btn nap-btn-primary" style="flex: 1; justify-content: center;">
                    🖨️ Print Form 1
                </a>
            </div>
        </div>

        <!-- NAP Form 2 -->
        <div class="module-card">
            <div>
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                    <span style="font-size: 11px; font-weight: 800; color: #1d4ed8; background: #dbeafe; padding: 4px 10px; border-radius: 6px; text-transform: uppercase;">
                        NAP FORM 2
                    </span>
                    <span style="font-size: 11px; color: #64748b; font-weight: 600;">Verified Series Only</span>
                </div>
                <h4 style="font-size: 16px; font-weight: 800; color: #0f172a; margin: 0 0 6px 0;">
                    Records Disposition Schedule
                </h4>
                <p style="font-size: 13px; color: #64748b; margin: 0; line-height: 1.5;">
                    Official 2-page report for verified record series ready for National Archives approval.
                </p>
            </div>
            <div style="display: flex; gap: 8px; margin-top: 20px;">
                <a href="{{ route('rdp.add-records.records-and-disposition-schedule') }}" class="nap-btn nap-btn-secondary" style="flex: 1; justify-content: center;">
                    ⏳ Schedule
                </a>
                <a href="{{ route('rdp.reports.nap-form-2') }}" class="nap-btn nap-btn-primary" style="flex: 1; justify-content: center;">
                    🖨️ Print Form 2
                </a>
            </div>
        </div>

        <!-- NAP Form 3 -->
        <div class="module-card">
            <div>
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                    <span style="font-size: 11px; font-weight: 800; color: #0369a1; background: #e0f2fe; padding: 4px 10px; border-radius: 6px; text-transform: uppercase;">
                        NAP FORM 3
                    </span>
                    <span style="font-size: 11px; color: #64748b; font-weight: 600;">Unverified Request</span>
                </div>
                <h4 style="font-size: 16px; font-weight: 800; color: #0f172a; margin: 0 0 6px 0;">
                    Request for Disposition Authority
                </h4>
                <p style="font-size: 13px; color: #64748b; margin: 0; line-height: 1.5;">
                    Official disposition request form specifically for custom or unverified record series.
                </p>
            </div>
            <div style="display: flex; gap: 8px; margin-top: 20px;">
                <a href="{{ route('rdp.reports.nap-form-3') }}" class="nap-btn nap-btn-primary" style="width: 100%; justify-content: center;">
                    🖨️ Open NAP Form 3 Hub
                </a>
            </div>
        </div>

        <!-- Manage Files -->
        <div class="module-card">
            <div>
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                    <span style="font-size: 11px; font-weight: 800; color: #0f172a; background: #f1f5f9; padding: 4px 10px; border-radius: 6px; text-transform: uppercase;">
                        FILE REPOSITORY
                    </span>
                    <span style="font-size: 11px; color: #64748b; font-weight: 600;">Cloud & Office Files</span>
                </div>
                <h4 style="font-size: 16px; font-weight: 800; color: #0f172a; margin: 0 0 6px 0;">
                    Manage Files & Documents
                </h4>
                <p style="font-size: 13px; color: #64748b; margin: 0; line-height: 1.5;">
                    Upload, view, and organize digital document files and Google Drive cloud backups.
                </p>
            </div>
            <div style="display: flex; gap: 8px; margin-top: 20px;">
                <a href="{{ route('rdp.manage-files') }}" class="nap-btn nap-btn-primary" style="width: 100%; justify-content: center;">
                    📂 Manage Repository
                </a>
            </div>
        </div>
    </div>

    <!-- Live Search & Interactive Record Series Command Table -->
    <div class="dashboard-section">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
            <div>
                <h3 style="font-size: 17px; font-weight: 800; color: #0f172a; margin: 0;">
                    🔍 Record Series Quick Management Table
                </h3>
                <span style="font-size: 12.5px; color: #64748b;">Search and inspect all record series retention and verification status.</span>
            </div>

            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Quick search title, item no, remarks..." style="padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; min-width: 280px; outline: none;">
                
                <button type="button" wire:click="openAddSeriesModal" class="nap-btn nap-btn-primary">
                    ➕ Add Series
                </button>
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 80px; text-align: center;">ITEM NO.</th>
                        <th>RECORD SERIES TITLE</th>
                        <th>PARENT SERIES</th>
                        <th style="text-align: center;">LOCATION</th>
                        <th style="text-align: center;">RETENTION</th>
                        <th style="text-align: center;">STATUS</th>
                        <th style="text-align: right;">ACTION</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($seriesList as $series)
                        @php
                            $isPerm = (bool)($series->is_retention_period_permanent) || strtolower(trim($series->total_period ?? '')) === 'permanent';
                        @endphp
                        <tr>
                            <td style="text-align: center; font-weight: 700; color: #475569;">
                                {{ $series->item_number ? (string)$series->item_number : '—' }}
                            </td>
                            <td style="font-weight: 700; color: #0f172a;">
                                {{ $series->series_title }}
                            </td>
                            <td style="color: #64748b; font-size: 12.5px;">
                                {{ $series->parent_title ?: '— (Root Series)' }}
                            </td>
                            <td style="text-align: center; font-size: 12.5px; color: #475569;">
                                {{ $series->rec_location ?: 'Records Office' }}
                            </td>
                            <td style="text-align: center;">
                                @if($isPerm)
                                    <span style="padding: 3px 10px; background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; border-radius: 12px; font-weight: 800; font-size: 11.5px;">
                                        PERMANENT
                                    </span>
                                @else
                                    <span style="padding: 3px 8px; background: #f8fafc; color: #0f172a; border: 1px solid #cbd5e1; border-radius: 6px; font-weight: 700; font-size: 12px;">
                                        {{ $series->total_period ?: '—' }}
                                    </span>
                                @endif
                            </td>
                            <td style="text-align: center;">
                                @if($series->is_verified)
                                    <span style="padding: 3px 10px; background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; border-radius: 12px; font-weight: 800; font-size: 11.5px;">
                                        VERIFIED
                                    </span>
                                @else
                                    <span style="padding: 3px 10px; background: #f0f9ff; color: #0369a1; border: 1px solid #bae6fd; border-radius: 12px; font-weight: 800; font-size: 11.5px;">
                                        UNVERIFIED
                                    </span>
                                @endif
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <a href="{{ route('rdp.add-records.inventory-and-appraisal') }}" class="nap-btn nap-btn-secondary" style="padding: 5px 10px; font-size: 12px;">
                                    Edit in Appraisal ➔
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="padding: 32px; text-align: center; color: #64748b;">
                                No record series found matching filter criteria.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- QUICK ADD NEW RECORD SERIES MODAL -->
    @if($showAddSeriesModal)
        <div class="modal-overlay" wire:click.self="closeAddSeriesModal">
            <div class="modal-dialog">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;">
                    <h3 style="margin: 0; font-size: 18px; font-weight: 800; color: #0f172a;">Add New Record Series</h3>
                    <button type="button" wire:click="closeAddSeriesModal" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b;">✕</button>
                </div>

                <form wire:submit.prevent="saveNewSeries" style="display: flex; flex-direction: column; gap: 16px;">
                    <div>
                        <label style="font-size: 13px; font-weight: 700; color: #334155; display: block; margin-bottom: 6px;">Series Title</label>
                        <input type="text" wire:model="newSeriesTitle" placeholder="e.g. Budget Estimates, Travel Orders" style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px; outline: none; box-sizing: border-box;" required>
                    </div>

                    <div>
                        <label style="font-size: 13px; font-weight: 700; color: #334155; display: block; margin-bottom: 6px;">Parent Series (Optional for Subsections)</label>
                        <select wire:model="newParentId" style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px; background: #fff; outline: none; box-sizing: border-box;">
                            <option value="">None (Top-Level Root Parent Series)</option>
                            @foreach($parentOptions as $parent)
                                <option value="{{ $parent->id }}">Parent: {{ $parent->series_title }} {{ $parent->item_number ? '(Item #'.$parent->item_number.')' : '' }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label style="font-size: 13px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">
                            Item Number
                            <span style="font-size: 11.5px; color: #64748b; margin-left: 6px;">(Assigning an Item Number marks a root series as Verified)</span>
                        </label>
                        <input type="number" wire:model="newItemNumber" placeholder="e.g. 1, 2, 15 (Leave blank if unverified)" style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px; outline: none; box-sizing: border-box;">
                    </div>

                    <div>
                        <label style="font-size: 13px; font-weight: 700; color: #334155; display: block; margin-bottom: 6px;">Remarks / Notes</label>
                        <textarea wire:model="newRemarks" rows="2" placeholder="Optional disposition provisions, remarks..." style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px; outline: none; box-sizing: border-box;"></textarea>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px;">
                        <button type="button" wire:click="closeAddSeriesModal" class="nap-btn nap-btn-secondary">Cancel</button>
                        <button type="submit" class="nap-btn nap-btn-primary">Create Series</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Floating Action Navigation FAB Bar -->
    <div class="rdp-fab-nav" aria-hidden="false">
        <button type="button" wire:click="openAddSeriesModal" class="rdp-fab-btn" style="background: #2563eb;">
            ➕ <span>New Series</span>
        </button>
        <a href="{{ route('rdp.manage-files') }}" class="rdp-fab-btn">
            📂 <span>Manage Files</span>
        </a>
        <a href="{{ route('rdp.reports.nap-form-1') }}" class="rdp-fab-btn">
            🖨️ <span>NAP Form 1</span>
        </a>
    </div>
</div>

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
    public array $newSubsections = [];
    public string $newActivePeriod = '';
    public string $newStoragePeriod = '';
    public bool $newIsPermanent = false;
    public string $newRemarks = '';

    public function openAddSeriesModal(): void
    {
        $this->showAddSeriesModal = true;
    }

    public function closeAddSeriesModal(): void
    {
        $this->showAddSeriesModal = false;
        $this->newSeriesTitle = '';
        $this->newSubsections = [];
        $this->newActivePeriod = '';
        $this->newStoragePeriod = '';
        $this->newIsPermanent = false;
        $this->newRemarks = '';
    }

    public function addSubsection(): void
    {
        $this->newSubsections[] = '';
    }

    public function removeSubsection(int $index): void
    {
        if (isset($this->newSubsections[$index])) {
            unset($this->newSubsections[$index]);
            $this->newSubsections = array_values($this->newSubsections);
        }
    }

    public function computeTotalPeriod(?string $active, ?string $storage, bool $isPermanent): string
    {
        if ($isPermanent) {
            return 'Permanent';
        }

        $active = trim($active ?? '');
        $storage = trim($storage ?? '');

        if (empty($active) && empty($storage)) {
            return '';
        }

        if (empty($active)) {
            return $storage;
        }

        if (empty($storage)) {
            return $active;
        }

        $parseTime = function(string $str) {
            $years = 0;
            $months = 0;
            if (preg_match('/(\d+)\s*(?:year|yr|y)s?/i', $str, $m)) {
                $years = (int)$m[1];
            }
            if (preg_match('/(\d+)\s*(?:month|mo|m)s?/i', $str, $m)) {
                $months = (int)$m[1];
            }
            return [$years, $months];
        };

        [$aYears, $aMonths] = $parseTime($active);
        [$sYears, $sMonths] = $parseTime($storage);

        if (($aYears > 0 || $aMonths > 0) && ($sYears > 0 || $sMonths > 0)) {
            $totalMonths = ($aYears * 12 + $aMonths) + ($sYears * 12 + $sMonths);
            $tYears = intdiv($totalMonths, 12);
            $remMonths = $totalMonths % 12;

            $parts = [];
            if ($tYears > 0) {
                $parts[] = $tYears . ' ' . ($tYears === 1 ? 'Year' : 'Years');
            }
            if ($remMonths > 0) {
                $parts[] = $remMonths . ' ' . ($remMonths === 1 ? 'Month' : 'Months');
            }
            return implode(' ', $parts);
        }

        return $active . ' + ' . $storage;
    }

    public function saveNewSeries(): void
    {
        $parentTitle = trim($this->newSeriesTitle);
        if (empty($parentTitle)) return;

        $allTitles = [$parentTitle];
        foreach ($this->newSubsections as $sub) {
            if (!empty(trim($sub))) {
                $allTitles[] = trim($sub);
            }
        }

        $retentionId = null;
        if ($this->newIsPermanent || !empty(trim($this->newActivePeriod)) || !empty(trim($this->newStoragePeriod))) {
            $active = $this->newIsPermanent ? 'Permanent' : (trim($this->newActivePeriod) ?: null);
            $storage = $this->newIsPermanent ? 'Permanent' : (trim($this->newStoragePeriod) ?: null);
            $computedTotal = $this->computeTotalPeriod($this->newActivePeriod, $this->newStoragePeriod, $this->newIsPermanent);

            $retentionId = DB::table('rdp_retention_period')->insertGetId([
                'active_period'  => $active,
                'storage_period' => $storage,
                'total_period'   => $computedTotal ?: null,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        $userOfficeCode = auth()->user()?->details?->office?->office_code;
        $currentParentId = null;

        foreach ($allTitles as $idx => $t) {
            $isLeaf = ($idx === count($allTitles) - 1);

            $existing = DB::table('rdp_record_series')
                ->where('series_title', $t)
                ->where(function($q) use ($currentParentId) {
                    if (is_null($currentParentId)) {
                        $q->whereNull('parent_id');
                    } else {
                        $q->where('parent_id', $currentParentId);
                    }
                })
                ->first();

            if ($existing) {
                $currentParentId = $existing->id;
                if ($isLeaf) {
                    $updateData = [
                        'updated_at' => now(),
                    ];
                    if ($retentionId) {
                        $updateData['retention_period'] = $retentionId;
                        $updateData['is_retention_period_permanent'] = $this->newIsPermanent;
                    }
                    if (!empty(trim($this->newRemarks))) {
                        $updateData['remarks'] = trim($this->newRemarks);
                    }
                    DB::table('rdp_record_series')->where('id', $existing->id)->update($updateData);
                }
            } else {
                $currentParentId = DB::table('rdp_record_series')->insertGetId([
                    'series_title'                  => $t,
                    'parent_id'                     => $currentParentId,
                    'retention_period'              => $isLeaf ? $retentionId : null,
                    'is_retention_period_permanent' => $isLeaf ? $this->newIsPermanent : false,
                    'recorded_at_office'            => $userOfficeCode,
                    'is_verified'                   => false,
                    'remarks'                       => $isLeaf ? (trim($this->newRemarks) ?: null) : null,
                    'created_at'                    => now(),
                    'updated_at'                    => now(),
                ]);

                if ($isLeaf) {
                    DB::table('rdp_record')->insert([
                        'record_series_id' => $currentParentId,
                        'volume'           => '0.5 cu. m.',
                        'records_location' => 'Records Office',
                        'frequence_use'    => 'Monthly',
                        'time_value'       => 'T',
                        'created_at'       => now(),
                        'updated_at'       => now(),
                    ]);
                }
            }
        }

        // Audit Log
        DB::table('admin_logs')->insert([
            'admin_id'    => auth()->id() ?? 1,
            'changes'     => 'Added new Record Series via RDP Landing Page: "' . $parentTitle . '"',
            'what_system' => 2,
            'when_changes'=> now(),
        ]);

        $this->closeAddSeriesModal();
    }

    private function buildTreeHierarchy(array $records): array
    {
        $byParent = [];
        foreach ($records as $r) {
            $pId = $r->parent_id ?? 0;
            $byParent[$pId][] = $r;
        }

        $ordered = [];
        $flatten = function ($parentId, $depth) use (&$flatten, &$ordered, $byParent) {
            if (!isset($byParent[$parentId])) {
                return;
            }
            foreach ($byParent[$parentId] as $item) {
                $item->depth = $depth;
                $ordered[] = $item;
                $flatten($item->id, $depth + 1);
            }
        };

        $flatten(0, 0);

        $addedIds = array_column($ordered, 'id');
        foreach ($records as $r) {
            if (!in_array($r->id, $addedIds, true)) {
                $r->depth = 0;
                $ordered[] = $r;
            }
        }

        return $ordered;
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

        $rawFetched = $seriesQuery->orderByRaw('rdp_record_series.item_number ASC NULLS LAST, rdp_record_series.series_title ASC')
            ->get();

        $treeOrdered = $this->buildTreeHierarchy($rawFetched->all());
        $seriesList = array_slice($treeOrdered, 0, 20);

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
            justify-content: flex-start !important;
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
            right: 96px;
            bottom: 24px;
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
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32" fill="none">
                    <path d="M14.6665 17.3334H6.6665V14.6667H14.6665V6.66669H17.3332V14.6667H25.3332V17.3334H17.3332V25.3334H14.6665V17.3334Z" fill="#043899"/>
                </svg>
                New Record Series
            </button>
            <a href="{{ route('rdp.manage-files') }}" class="hero-btn hero-btn-trans">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
<path fill-rule="evenodd" clip-rule="evenodd" d="M4.8001 4.20001C4.48184 4.20001 4.17661 4.32644 3.95157 4.55148C3.72653 4.77653 3.6001 5.08175 3.6001 5.40001V10.2C3.6001 9.88175 3.72653 9.57653 3.95157 9.35148C4.17661 9.12644 4.48184 9.00001 4.8001 9.00001V8.70001C4.8001 8.38175 4.92653 8.07653 5.15157 7.85148C5.37661 7.62644 5.68184 7.50001 6.0001 7.50001H18.0001C18.3184 7.50001 18.6236 7.62644 18.8486 7.85148C19.0737 8.07653 19.2001 8.38175 19.2001 8.70001V9.00001C19.5184 9.00001 19.8236 9.12644 20.0486 9.35148C20.2737 9.57653 20.4001 9.88175 20.4001 10.2V7.27801C20.4001 6.95975 20.2737 6.65453 20.0486 6.42948C19.8236 6.20444 19.5184 6.07801 19.2001 6.07801H10.5958C10.4998 6.07801 10.4077 6.03781 10.3429 5.96701L10.2409 5.85541L10.1281 5.73211L10.015 5.60881L9.0832 4.59001C8.97078 4.46713 8.83401 4.36899 8.68159 4.30185C8.52917 4.2347 8.36445 4.20002 8.1979 4.20001H4.8001Z" fill="#F2994A"/>
<path d="M3.6001 10.2C3.6001 9.88174 3.72653 9.57652 3.95157 9.35147C4.17661 9.12643 4.48184 9 4.8001 9H19.2001C19.5184 9 19.8236 9.12643 20.0486 9.35147C20.2737 9.57652 20.4001 9.88174 20.4001 10.2V18.6C20.4001 18.9183 20.2737 19.2235 20.0486 19.4485C19.8236 19.6736 19.5184 19.8 19.2001 19.8H4.8001C4.48184 19.8 4.17661 19.6736 3.95157 19.4485C3.72653 19.2235 3.6001 18.9183 3.6001 18.6V10.2Z" fill="#F2C94C"/>
<path fill-rule="evenodd" clip-rule="evenodd" d="M6.00029 7.5C5.68203 7.5 5.37681 7.62643 5.15177 7.85147C4.92672 8.07652 4.80029 8.38174 4.80029 8.7V9H19.2003V8.7C19.2003 8.38174 19.0739 8.07652 18.8488 7.85147C18.6238 7.62643 18.3186 7.5 18.0003 7.5H6.00029Z" fill="#F2F2F2"/>
</svg>
                Manage Office Files
            </a>
        </div>
    </div>

    <!-- Live Statistics Cards -->
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: #eff6ff; color: #1d4ed8;">
                <svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 30 30" fill="none">
<path d="M25.3125 11.25C25.5611 11.25 25.7996 11.1512 25.9754 10.9754C26.1512 10.7996 26.25 10.5611 26.25 10.3125C26.25 9.56658 25.9537 8.85121 25.4262 8.32376C24.8988 7.79632 24.1834 7.5 23.4375 7.5H14.0625C13.7714 7.5 13.4843 7.43223 13.224 7.30205C12.9636 7.17187 12.7372 6.98287 12.5625 6.75L10.875 4.5C10.7004 4.26713 10.4739 4.07813 10.2135 3.94795C9.95317 3.81777 9.66609 3.75 9.375 3.75H2.8125C2.06658 3.75 1.35121 4.04632 0.823762 4.57376C0.296316 5.10121 0 5.81658 0 6.5625L0 23.4375C0 24.1834 0.296316 24.8988 0.823762 25.4262C1.35121 25.9537 2.06658 26.25 2.8125 26.25H23.625C24.216 26.2511 24.7922 26.0647 25.2706 25.7177C25.749 25.3707 26.105 24.8809 26.2875 24.3188L29.6063 14.3438C29.6532 14.2029 29.666 14.0529 29.6436 13.9061C29.6212 13.7594 29.5643 13.62 29.4775 13.4995C29.3907 13.3791 29.2765 13.2809 29.1444 13.2132C29.0123 13.1455 28.866 13.1101 28.7175 13.11H8.4675C8.17216 13.1098 7.88426 13.2027 7.64464 13.3753C7.40501 13.548 7.22583 13.7917 7.1325 14.0719L3.70125 24.3656H2.76375C2.51511 24.3656 2.27665 24.2669 2.10084 24.091C1.92502 23.9152 1.82625 23.6768 1.82625 23.4281V6.55312C1.82625 6.30448 1.92502 6.06603 2.10084 5.89021C2.27665 5.7144 2.51511 5.61562 2.76375 5.61562H9.32625L11.0138 7.86563C11.7225 8.81063 12.8325 9.36562 14.0138 9.36562H23.3888C23.9063 9.36562 24.3263 9.78562 24.3263 10.3031C24.3263 10.8206 24.7463 11.2406 25.2638 11.2406L25.3125 11.25Z" fill="#FFB300"/>
</svg>
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
                <svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 30 30" fill="none">
<path d="M16.25 3.75V10.625C16.25 10.7908 16.3158 10.9497 16.4331 11.0669C16.5503 11.1842 16.7092 11.25 16.875 11.25H23.75M12.1275 18.915L13.895 20.6825L17.8725 16.705M16.9825 3.75H7.5C7.16848 3.75 6.85054 3.8817 6.61612 4.11612C6.3817 4.35054 6.25 4.66848 6.25 5V25C6.25 25.3315 6.3817 25.6495 6.61612 25.8839C6.85054 26.1183 7.16848 26.25 7.5 26.25H22.5C22.8315 26.25 23.1495 26.1183 23.3839 25.8839C23.6183 25.6495 23.75 25.3315 23.75 25V10.5175C23.7499 10.186 23.6182 9.86812 23.3838 9.63375L17.8663 4.11625C17.6319 3.88181 17.314 3.75007 16.9825 3.75Z" stroke="#00992B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
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
                <svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 30 30" fill="none">
<path d="M11.25 1.87877C10.2554 1.87877 9.30161 2.27386 8.59835 2.97712C7.89509 3.68038 7.5 4.63421 7.5 5.62877V9.76314C8.11295 9.58986 8.74063 9.47374 9.375 9.41627V5.62877C9.375 5.13149 9.57254 4.65457 9.92418 4.30294C10.2758 3.95131 10.7527 3.75377 11.25 3.75377H16.875V8.44127C16.875 9.18719 17.1713 9.90256 17.6988 10.43C18.2262 10.9575 18.9416 11.2538 19.6875 11.2538H24.375V24.3713C24.375 24.8685 24.1775 25.3455 23.8258 25.6971C23.4742 26.0487 22.9973 26.2463 22.5 26.2463H18.27C17.68 26.9613 17.0063 27.5863 16.2488 28.1213H22.5C23.4946 28.1213 24.4484 27.7262 25.1516 27.0229C25.8549 26.3197 26.25 25.3658 26.25 24.3713V10.1588C26.2493 9.4131 25.9526 8.69821 25.425 8.17127L19.9538 2.70189C19.4268 2.1743 18.7119 1.87755 17.9662 1.87689L11.25 1.87877ZM18.75 8.44127V4.14752L23.9831 9.37877H19.6875C19.4389 9.37877 19.2004 9.28 19.0246 9.10418C18.8488 8.92837 18.75 8.68991 18.75 8.44127ZM18.75 19.6875C18.75 17.4498 17.8611 15.3036 16.2787 13.7213C14.6964 12.139 12.5503 11.25 10.3125 11.25C8.07474 11.25 5.92862 12.139 4.34629 13.7213C2.76395 15.3036 1.875 17.4498 1.875 19.6875C1.875 21.9253 2.76395 24.0714 4.34629 25.6537C5.92862 27.2361 8.07474 28.125 10.3125 28.125C12.5503 28.125 14.6964 27.2361 16.2787 25.6537C17.8611 24.0714 18.75 21.9253 18.75 19.6875ZM10.3125 23.2125C10.6233 23.2125 10.9214 23.336 11.1411 23.5558C11.3609 23.7755 11.4844 24.0736 11.4844 24.3844C11.4844 24.6952 11.3609 24.9933 11.1411 25.213C10.9214 25.4328 10.6233 25.5563 10.3125 25.5563C10.0017 25.5563 9.70363 25.4328 9.48386 25.213C9.26409 24.9933 9.14062 24.6952 9.14062 24.3844C9.14062 24.0736 9.26409 23.7755 9.48386 23.5558C9.70363 23.336 10.0017 23.2125 10.3125 23.2125ZM10.3125 14.0681C12.2456 14.0681 13.7869 15.6544 13.7869 17.7244C13.7869 18.8231 13.3856 19.4269 12.4237 20.1975L11.9044 20.5988C11.4431 20.9625 11.2875 21.1613 11.2556 21.4388L11.235 21.7313C11.1933 21.9618 11.0667 22.1683 10.8803 22.3102C10.6939 22.4521 10.4611 22.5191 10.2278 22.4979C9.99447 22.4767 9.77754 22.369 9.6197 22.1959C9.46187 22.0227 9.37457 21.7968 9.375 21.5625C9.375 20.4938 9.76875 19.905 10.7175 19.1475L11.2387 18.7444C11.7787 18.3131 11.9137 18.1031 11.9137 17.7244C11.9137 16.6781 11.1975 15.9431 10.3125 15.9431C9.38625 15.9431 8.70187 16.6294 8.71312 17.715C8.71561 17.9637 8.61922 18.2031 8.44517 18.3807C8.27111 18.5582 8.03364 18.6594 7.785 18.6619C7.53636 18.6644 7.29692 18.568 7.11934 18.3939C6.94177 18.2199 6.84061 17.9824 6.83813 17.7338C6.8175 15.6 8.3475 14.0681 10.3125 14.0681Z" fill="#9D1414"/>
</svg>
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
                <svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 30 30" fill="none">
<path d="M27.3047 0.9375H2.69531C1.81641 0.9375 0.9375 1.81641 0.9375 2.69531V22.4709C0.9375 23.3498 1.81641 24.2288 2.69531 24.2288H11.4844V25.9866C11.4844 27.2072 9.90094 27.7444 7.96875 27.7444V29.0625H22.0312V27.7439C20.0986 27.7439 18.5156 27.2067 18.5156 25.9861V24.2283H27.3047C28.1836 24.2283 29.0625 23.3494 29.0625 22.4705V2.69531C29.0625 1.81641 28.1836 0.9375 27.3047 0.9375ZM28.125 22.4709C28.125 22.8314 27.6647 23.2913 27.3047 23.2913H2.69531C2.33484 23.2913 1.875 22.8314 1.875 22.4709V20.7131H28.125V22.4709Z" fill="#646464"/>
<path d="M15.0002 22.9106C15.2431 22.9106 15.4399 22.7138 15.4399 22.4709C15.4399 22.2281 15.2431 22.0312 15.0002 22.0312C14.7574 22.0312 14.5605 22.2281 14.5605 22.4709C14.5605 22.7138 14.7574 22.9106 15.0002 22.9106Z" fill="#646464"/>
</svg>
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
                    <svg xmlns="http://www.w3.org/2000/svg" width="25" height="23" viewBox="0 0 25 23" fill="none">
<path d="M25 23H0V0H2.5V20.4444H5V8.94444H10V20.4444H12.5V3.83333H17.5V20.4444H20V14.0556H25V23Z" fill="#043899"/>
</svg>
                    Retention Period Distribution Ratio
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
            Primary RDP Modules & Official Reports
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
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none">
<path d="M13.2685 5.72399C13.2369 5.65166 13.1926 5.58552 13.1378 5.52866L9.13784 1.52866C9.08098 1.47388 9.01484 1.42964 8.9425 1.39799C8.9225 1.38866 8.90117 1.38333 8.87984 1.37599C8.82404 1.35708 8.76598 1.34565 8.70717 1.34199C8.69317 1.34066 8.6805 1.33333 8.6665 1.33333H3.99984C3.2645 1.33333 2.6665 1.93133 2.6665 2.66666V13.3333C2.6665 14.0687 3.2645 14.6667 3.99984 14.6667H11.9998C12.7352 14.6667 13.3332 14.0687 13.3332 13.3333V5.99999C13.3332 5.98599 13.3258 5.97333 13.3245 5.95866C13.3209 5.89986 13.3094 5.84179 13.2905 5.78599C13.2843 5.76466 13.2769 5.74399 13.2685 5.72399ZM11.0572 5.33333H9.33317V3.60933L11.0572 5.33333ZM3.99984 13.3333V2.66666H7.99984V5.99999C7.99984 6.17681 8.07008 6.34637 8.1951 6.4714C8.32012 6.59642 8.48969 6.66666 8.6665 6.66666H11.9998L12.0012 13.3333H3.99984Z" fill="#043899"/>
<path d="M5.3335 7.99999H10.6668V9.33333H5.3335V7.99999ZM5.3335 10.6667H10.6668V12H5.3335V10.6667ZM5.3335 5.33333H6.66683V6.66666H5.3335V5.33333Z" fill="#043899"/>
</svg>
                    Appraisal
                </a>
                <a href="{{ route('rdp.reports.nap-form-1') }}" class="nap-btn nap-btn-primary" style="flex: 1; justify-content: center;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 17 17" fill="none">
<path d="M12.0416 5.55759H4.95832V3.26967H12.0416V5.55759ZM12.478 8.58217C12.6787 8.58217 12.8468 8.51417 12.9823 8.37817C13.1178 8.24217 13.1858 8.07406 13.1863 7.87384C13.1868 7.67361 13.1188 7.50527 12.9823 7.36879C12.8458 7.23232 12.6777 7.16432 12.478 7.16479C12.2782 7.16527 12.1099 7.23327 11.9729 7.36879C11.836 7.50432 11.7682 7.67267 11.7696 7.87384C11.7711 8.075 11.8388 8.24311 11.9729 8.37817C12.1071 8.51322 12.2754 8.58122 12.478 8.58217ZM11.3333 13.4583V10.2439H5.66665V13.4583H11.3333ZM12.0416 14.1667H4.95832V11.3333H2.53369V7.51967C2.53369 7.11828 2.66993 6.78182 2.9424 6.51029C3.21487 6.23877 3.55086 6.10277 3.95036 6.10229H13.0496C13.451 6.10229 13.7875 6.23829 14.059 6.51029C14.3305 6.78229 14.4663 7.11852 14.4663 7.51896V11.3333H12.0416V14.1667Z" fill="white"/>
</svg>
                    Print Form 1
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
                    <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 17 17" fill="none">
<path opacity="0.3" d="M8.50016 2.83333C5.36933 2.83333 2.8335 5.36916 2.8335 8.49999C2.8335 11.6308 5.36933 14.1667 8.50016 14.1667C11.631 14.1667 14.1668 11.6308 14.1668 8.49999C14.1668 5.36916 11.631 2.83333 8.50016 2.83333ZM11.5106 11.4396L7.79183 9.20833V4.95833H8.85433V8.67708L12.0418 10.5683L11.5106 11.4396Z" fill="#043899"/>
<path d="M8.49275 1.41667C4.58275 1.41667 1.4165 4.59001 1.4165 8.50001C1.4165 12.41 4.58275 15.5833 8.49275 15.5833C12.4098 15.5833 15.5832 12.41 15.5832 8.50001C15.5832 4.59001 12.4098 1.41667 8.49275 1.41667ZM8.49984 14.1667C5.369 14.1667 2.83317 11.6308 2.83317 8.50001C2.83317 5.36917 5.369 2.83334 8.49984 2.83334C11.6307 2.83334 14.1665 5.36917 14.1665 8.50001C14.1665 11.6308 11.6307 14.1667 8.49984 14.1667ZM8.854 4.95834H7.7915V9.20834L11.5103 11.4396L12.0415 10.5683L8.854 8.67709V4.95834Z" fill="#043899"/>
</svg>
                    Schedule
                </a>
                <a href="{{ route('rdp.reports.nap-form-2') }}" class="nap-btn nap-btn-primary" style="flex: 1; justify-content: center;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 17 17" fill="none">
<path d="M12.0416 5.55759H4.95832V3.26967H12.0416V5.55759ZM12.478 8.58217C12.6787 8.58217 12.8468 8.51417 12.9823 8.37817C13.1178 8.24217 13.1858 8.07406 13.1863 7.87384C13.1868 7.67361 13.1188 7.50527 12.9823 7.36879C12.8458 7.23232 12.6777 7.16432 12.478 7.16479C12.2782 7.16527 12.1099 7.23327 11.9729 7.36879C11.836 7.50432 11.7682 7.67267 11.7696 7.87384C11.7711 8.075 11.8388 8.24311 11.9729 8.37817C12.1071 8.51322 12.2754 8.58122 12.478 8.58217ZM11.3333 13.4583V10.2439H5.66665V13.4583H11.3333ZM12.0416 14.1667H4.95832V11.3333H2.53369V7.51967C2.53369 7.11828 2.66993 6.78182 2.9424 6.51029C3.21487 6.23877 3.55086 6.10277 3.95036 6.10229H13.0496C13.451 6.10229 13.7875 6.23829 14.059 6.51029C14.3305 6.78229 14.4663 7.11852 14.4663 7.51896V11.3333H12.0416V14.1667Z" fill="white"/>
</svg>
                    Print Form 2
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
                    <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 17 17" fill="none">
<path d="M12.0416 5.55759H4.95832V3.26967H12.0416V5.55759ZM12.478 8.58217C12.6787 8.58217 12.8468 8.51417 12.9823 8.37817C13.1178 8.24217 13.1858 8.07406 13.1863 7.87384C13.1868 7.67361 13.1188 7.50527 12.9823 7.36879C12.8458 7.23232 12.6777 7.16432 12.478 7.16479C12.2782 7.16527 12.1099 7.23327 11.9729 7.36879C11.836 7.50432 11.7682 7.67267 11.7696 7.87384C11.7711 8.075 11.8388 8.24311 11.9729 8.37817C12.1071 8.51322 12.2754 8.58122 12.478 8.58217ZM11.3333 13.4583V10.2439H5.66665V13.4583H11.3333ZM12.0416 14.1667H4.95832V11.3333H2.53369V7.51967C2.53369 7.11828 2.66993 6.78182 2.9424 6.51029C3.21487 6.23877 3.55086 6.10277 3.95036 6.10229H13.0496C13.451 6.10229 13.7875 6.23829 14.059 6.51029C14.3305 6.78229 14.4663 7.11852 14.4663 7.51896V11.3333H12.0416V14.1667Z" fill="white"/>
</svg>
                    Open NAP Form 3 Hub
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
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none">
<path d="M13.5 6C13.6326 6 13.7598 5.94732 13.8536 5.85355C13.9473 5.75979 14 5.63261 14 5.5C14 5.10218 13.842 4.72064 13.5607 4.43934C13.2794 4.15804 12.8978 4 12.5 4H7.5C7.34476 4 7.19164 3.96386 7.05279 3.89443C6.91393 3.825 6.79315 3.7242 6.7 3.6L5.8 2.4C5.70685 2.2758 5.58607 2.175 5.44721 2.10557C5.30836 2.03614 5.15525 2 5 2H1.5C1.10218 2 0.720644 2.15804 0.43934 2.43934C0.158035 2.72064 0 3.10218 0 3.5L0 12.5C0 12.8978 0.158035 13.2794 0.43934 13.5607C0.720644 13.842 1.10218 14 1.5 14H12.6C12.9152 14.0006 13.2225 13.9012 13.4776 13.7161C13.7328 13.531 13.9227 13.2698 14.02 12.97L15.79 7.65C15.815 7.57487 15.8218 7.49488 15.8099 7.4166C15.798 7.33832 15.7676 7.264 15.7213 7.19975C15.675 7.1355 15.6142 7.08316 15.5437 7.04704C15.4732 7.01093 15.3952 6.99206 15.316 6.992H4.516C4.35848 6.99191 4.20494 7.04141 4.07714 7.13349C3.94934 7.22557 3.85378 7.35556 3.804 7.505L1.974 12.995H1.474C1.34139 12.995 1.21421 12.9423 1.12045 12.8486C1.02668 12.7548 0.974 12.6276 0.974 12.495V3.495C0.974 3.36239 1.02668 3.23521 1.12045 3.14145C1.21421 3.04768 1.34139 2.995 1.474 2.995H4.974L5.874 4.195C6.252 4.699 6.844 4.995 7.474 4.995H12.474C12.75 4.995 12.974 5.219 12.974 5.495C12.974 5.771 13.198 5.995 13.474 5.995L13.5 6Z" fill="white"/>
</svg>
                    Manage Repository
                </a>
            </div>
        </div>
    </div>

    <!-- Live Search & Interactive Record Series Command Table -->
    <div class="dashboard-section">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
            <div>
                <h3 style="font-size: 17px; font-weight: 800; color: #0f172a; margin: 0;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none">
<path d="M19.7266 17.293L15.832 13.3984C15.6562 13.2227 15.418 13.125 15.168 13.125H14.5312C15.6094 11.7461 16.25 10.0117 16.25 8.125C16.25 3.63672 12.6133 0 8.125 0C3.63672 0 0 3.63672 0 8.125C0 12.6133 3.63672 16.25 8.125 16.25C10.0117 16.25 11.7461 15.6094 13.125 14.5312V15.168C13.125 15.418 13.2227 15.6562 13.3984 15.832L17.293 19.7266C17.6602 20.0938 18.2539 20.0938 18.6172 19.7266L19.7227 18.6211C20.0898 18.2539 20.0898 17.6602 19.7266 17.293ZM8.125 13.125C5.36328 13.125 3.125 10.8906 3.125 8.125C3.125 5.36328 5.35938 3.125 8.125 3.125C10.8867 3.125 13.125 5.35938 13.125 8.125C13.125 10.8867 10.8906 13.125 8.125 13.125Z" fill="#043899"/>
</svg>
                    Record Series Quick Management Table
                </h3>
                <span style="font-size: 12.5px; color: #64748b;">Search and inspect all record series retention and verification status.</span>
            </div>

            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Quick search title, item no, remarks..." style="padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; min-width: 280px; outline: none;">
                
                <button type="button" wire:click="openAddSeriesModal" class="nap-btn nap-btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="23" height="23" viewBox="0 0 23 23" fill="none">
<path d="M10.5415 12.4583H4.7915V10.5417H10.5415V4.79166H12.4582V10.5417H18.2082V12.4583H12.4582V18.2083H10.5415V12.4583Z" fill="white"/>
</svg>
                    Add Series
                </button>
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th rowspan="2" style="width: 80px; text-align: center; vertical-align: middle;">ITEM NO.</th>
                        <th rowspan="2" style="vertical-align: middle;">RECORD SERIES TITLE & DESCRIPTION</th>
                        <th colspan="3" style="text-align: center;">RETENTION PERIOD</th>
                        <th rowspan="2" style="vertical-align: middle;">REMARKS / LOCATION</th>
                        <th rowspan="2" style="text-align: right; vertical-align: middle;">ACTION</th>
                    </tr>
                    <tr style="background: #f8fafc; font-size: 11.5px; border-bottom: 2px solid #cbd5e1;">
                        <th style="text-align: center; width: 90px; padding: 6px 8px;">Active</th>
                        <th style="text-align: center; width: 90px; padding: 6px 8px;">Storage</th>
                        <th style="text-align: center; width: 100px; padding: 6px 8px;">Total</th>
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
                            <td style="padding-left: {{ (($series->depth ?? 0) * 20) + 12 }}px; font-weight: 700; color: #0f172a;">
                                @if(($series->depth ?? 0) > 0)
                                    <span style="color: #2563eb; font-weight: 800; margin-right: 4px;">└─</span>
                                @endif
                                {{ $series->series_title }}
                            </td>
                            @if($isPerm)
                                <td colspan="3" style="text-align: center;">
                                    <span style="padding: 3px 12px; background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; border-radius: 12px; font-weight: 800; font-size: 11.5px;">
                                        PERMANENT
                                    </span>
                                </td>
                            @else
                                <td style="text-align: center; font-size: 12.5px; color: #475569;">
                                    {{ $series->active_period ?: '—' }}
                                </td>
                                <td style="text-align: center; font-size: 12.5px; color: #475569;">
                                    {{ $series->storage_period ?: '—' }}
                                </td>
                                <td style="text-align: center; font-weight: 700; font-size: 12.5px; color: #0f172a;">
                                    {{ $series->total_period ?: '—' }}
                                </td>
                            @endif
                            <td style="font-size: 12.5px; color: #475569;">
                                {{ $series->remarks ?: ($series->rec_location ?: 'Records Office') }}
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

                    <!-- Dynamic Subsections (Hierarchy) -->
                    @foreach($newSubsections as $index => $sub)
                        <div style="margin-left: {{ min(($index + 1) * 16, 64) }}px; border-left: 3px solid #2563eb; padding-left: 10px; display: flex; gap: 8px; align-items: center;">
                            <input type="text" 
                                   wire:model.live="newSubsections.{{ $index }}" 
                                   placeholder="Subsection #{{ $index + 1 }} (Child of {{ $index === 0 ? ($newSeriesTitle ?: 'Parent') : 'Subsection #' . $index }})" 
                                   style="flex: 1; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box;">
                            <button type="button" wire:click="removeSubsection({{ $index }})" style="background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; border-radius: 6px; padding: 6px 10px; font-weight: 700; cursor: pointer; font-size: 12px;">✕</button>
                        </div>
                    @endforeach

                    <button type="button" wire:click="addSubsection" style="align-self: flex-start; background: #eff6ff; color: #2563eb; border: 1px dashed #93c5fd; border-radius: 8px; padding: 6px 14px; font-size: 12.5px; font-weight: 700; cursor: pointer;">
                        + Add Subsection (Child Series)
                    </button>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div>
                            <label style="font-size: 13px; font-weight: 700; color: #334155; display: block; margin-bottom: 6px;">Active Period</label>
                            <input type="text" wire:model="newActivePeriod" placeholder="e.g. 2 Years" style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px; outline: none; box-sizing: border-box;" @if($newIsPermanent) disabled @endif>
                        </div>
                        <div>
                            <label style="font-size: 13px; font-weight: 700; color: #334155; display: block; margin-bottom: 6px;">Storage Period</label>
                            <input type="text" wire:model="newStoragePeriod" placeholder="e.g. 3 Years" style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px; outline: none; box-sizing: border-box;" @if($newIsPermanent) disabled @endif>
                        </div>
                    </div>

                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" id="newIsPermanent" wire:model.live="newIsPermanent" style="width: 16px; height: 16px; accent-color: #2563eb; cursor: pointer;">
                        <label for="newIsPermanent" style="font-size: 13px; font-weight: 700; color: #dc2626; cursor: pointer;">
                            Permanent Record Series (No destruction allowed)
                        </label>
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
            <svg xmlns="http://www.w3.org/2000/svg" width="23" height="23" viewBox="0 0 23 23" fill="none">
<path d="M10.5415 12.4583H4.7915V10.5417H10.5415V4.79167H12.4582V10.5417H18.2082V12.4583H12.4582V18.2083H10.5415V12.4583Z" fill="white"/>
</svg>
            <span>New Series</span>
        </button>
        <a href="{{ route('rdp.manage-files') }}" class="rdp-fab-btn">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none">
<path d="M16.875 7.5C17.0408 7.5 17.1997 7.43415 17.3169 7.31694C17.4342 7.19973 17.5 7.04076 17.5 6.875C17.5 6.37772 17.3025 5.90081 16.9508 5.54917C16.5992 5.19754 16.1223 5 15.625 5H9.375C9.18094 5 8.98955 4.95482 8.81598 4.86803C8.64241 4.78125 8.49143 4.65525 8.375 4.5L7.25 3C7.13357 2.84476 6.98259 2.71875 6.80902 2.63197C6.63545 2.54518 6.44406 2.5 6.25 2.5H1.875C1.37772 2.5 0.900805 2.69754 0.549175 3.04917C0.197544 3.40081 0 3.87772 0 4.375L0 15.625C0 16.1223 0.197544 16.5992 0.549175 16.9508C0.900805 17.3025 1.37772 17.5 1.875 17.5H15.75C16.144 17.5007 16.5281 17.3765 16.8471 17.1452C17.166 16.9138 17.4034 16.5873 17.525 16.2125L19.7375 9.5625C19.7688 9.46859 19.7773 9.3686 19.7624 9.27075C19.7475 9.1729 19.7095 9.08 19.6516 8.99969C19.5938 8.91938 19.5177 8.85395 19.4296 8.80881C19.3415 8.76366 19.244 8.74008 19.145 8.74H5.645C5.4481 8.73989 5.25617 8.80177 5.09642 8.91687C4.93667 9.03197 4.81722 9.19444 4.755 9.38125L2.4675 16.2437H1.8425C1.67674 16.2437 1.51777 16.1779 1.40056 16.0607C1.28335 15.9435 1.2175 15.7845 1.2175 15.6187V4.36875C1.2175 4.20299 1.28335 4.04402 1.40056 3.92681C1.51777 3.8096 1.67674 3.74375 1.8425 3.74375H6.2175L7.3425 5.24375C7.815 5.87375 8.555 6.24375 9.3425 6.24375H15.5925C15.9375 6.24375 16.2175 6.52375 16.2175 6.86875C16.2175 7.21375 16.4975 7.49375 16.8425 7.49375L16.875 7.5Z" fill="white"/>
</svg>
            <span>Manage Files</span>
        </a>
        <a href="{{ route('rdp.reports.nap-form-1') }}" class="rdp-fab-btn">
            <svg xmlns="http://www.w3.org/2000/svg" width="23" height="23" viewBox="0 0 23 23" fill="none">
<path d="M16.2914 7.51908H6.70811V4.42367H16.2914V7.51908ZM16.8818 11.6112C17.1533 11.6112 17.3807 11.5192 17.5641 11.3352C17.7475 11.1512 17.8395 10.9237 17.8401 10.6528C17.8407 10.3819 17.7487 10.1542 17.5641 9.96954C17.3795 9.7849 17.152 9.6929 16.8818 9.69354C16.6115 9.69418 16.3838 9.78618 16.1985 9.96954C16.0132 10.1529 15.9215 10.3807 15.9234 10.6528C15.9254 10.925 16.017 11.1524 16.1985 11.3352C16.3799 11.5179 16.6077 11.6099 16.8818 11.6112ZM15.3331 18.2083V13.8594H7.66644V18.2083H15.3331ZM16.2914 19.1667H6.70811V15.3333H3.42773V10.1737C3.42773 9.63061 3.61205 9.1754 3.98069 8.80804C4.34933 8.44068 4.8039 8.25668 5.3444 8.25604H17.6552C18.1982 8.25604 18.6534 8.44004 19.0208 8.80804C19.3881 9.17604 19.5718 9.63093 19.5718 10.1727V15.3333H16.2914V19.1667Z" fill="white"/>
</svg>
            <span>NAP Form 1</span>
        </a>
    </div>
</div>

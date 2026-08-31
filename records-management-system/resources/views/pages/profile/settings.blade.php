<?php
/**
 * Profile Manager - Preferences & Settings Volt Component
 * 
 * This component provides an interface for users to manage their personal
 * preferences, communication settings, and future user customizations.
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Auth;
use App\Models\PersonalSetting;

new #[Layout('layouts.profile')] #[Title('Profile Manager - Preferences & Settings')] class extends Component {
    /** @var bool Personal preference for auto-opening Chatify widget upon login */
    public bool $autoOpenChat = true;

    /** @var bool Personal preference for notification sound alerts (SFX) */
    public bool $notificationSoundAlert = true;

    /** @var bool Personal preference for DTS and RDP top navigation tabs */
    public bool $enableTopTabs = true;

    /** @var string Personal theme preference ('light' or 'dark') */
    public string $theme = 'light';

    /** @var string|null Personal preference for closing modal keyboard shortcut */
    public ?string $modalCloseKey = 'Escape';

    /** @var string|null Personal preference for sidebar navigation toggle shortcut */
    public ?string $sidebarToggleKey = null;

    /** @var string|null Feedback notification message */
    public ?string $feedbackMessage = null;

    public ?int $currentRoleId = null;
    public array $currentPermissions = [];

    /**
     * Component Mount hook - populates user preferences.
     */
    public function mount(): void
    {
        $this->refreshSettings();
        $this->checkRoleUpdate();
    }

    public function refreshSettings(): void
    {
        $user = Auth::user();
        if ($user) {
            $user = $user->fresh();
            $this->autoOpenChat = $user->autoOpenChat();
            $this->notificationSoundAlert = $user->notificationSoundAlert();
            $this->enableTopTabs = $user->enableTopTabs();
            $this->theme = $user->theme();
            $this->modalCloseKey = $user->modalCloseKey();
            $this->sidebarToggleKey = $user->sidebarToggleKey();
        }
    }

    public function toggleAutoOpenChat(): void
    {
        $user = Auth::user();
        if ($user) {
            $setting = PersonalSetting::firstOrCreate(
                ['user' => $user->id],
                ['auto_open_chat' => true, 'notification_sound_alert' => true, 'enable_top_tabs' => true, 'theme' => 'light', 'modal_close_key' => 'Escape']
            );
            $setting->auto_open_chat = !$setting->auto_open_chat;
            $setting->save();
            $this->autoOpenChat = (bool) $setting->auto_open_chat;
            $this->feedbackMessage = 'Preference updated: Chatify auto-open ' . ($this->autoOpenChat ? 'enabled' : 'disabled') . '.';
            $this->dispatch('rms-settings-changed', type: 'profile_preference', message: 'Chatify auto-open preference updated.');
        }
    }

    public function toggleNotificationSoundAlert(): void
    {
        $user = Auth::user();
        if ($user) {
            $setting = PersonalSetting::firstOrCreate(
                ['user' => $user->id],
                ['auto_open_chat' => true, 'notification_sound_alert' => true, 'enable_top_tabs' => true, 'theme' => 'light', 'modal_close_key' => 'Escape']
            );
            $setting->notification_sound_alert = !((bool)($setting->notification_sound_alert ?? true));
            $setting->save();
            $this->notificationSoundAlert = (bool) $setting->notification_sound_alert;
            $this->feedbackMessage = 'Preference updated: Notification sound alerts ' . ($this->notificationSoundAlert ? 'enabled' : 'disabled') . '.';
            $this->dispatch('rms-sound-setting-changed', enabled: $this->notificationSoundAlert);
            $this->dispatch('rms-settings-changed', type: 'profile_preference', message: 'Notification sound alerts preference updated.');
        }
    }

    public function toggleEnableTopTabs(): void
    {
        $user = Auth::user();
        if ($user) {
            $setting = PersonalSetting::firstOrCreate(
                ['user' => $user->id],
                ['auto_open_chat' => true, 'notification_sound_alert' => true, 'enable_top_tabs' => true, 'theme' => 'light', 'modal_close_key' => 'Escape']
            );
            $setting->enable_top_tabs = !((bool)($setting->enable_top_tabs ?? true));
            $setting->save();
            $this->enableTopTabs = (bool) $setting->enable_top_tabs;
            $this->feedbackMessage = 'Preference updated: Top Navigation Tabs ' . ($this->enableTopTabs ? 'enabled' : 'disabled') . '.';
            $this->dispatch('rms-settings-changed', type: 'profile_preference', message: 'Admin, DTS, RDP & DCS Top Navigation Tabs preference updated.');
        }
    }

    public function setTheme(string $theme): void
    {
        $theme = in_array($theme, ['light', 'dark']) ? $theme : 'light';
        $user = Auth::user();
        if ($user) {
            $setting = PersonalSetting::firstOrCreate(
                ['user' => $user->id],
                ['auto_open_chat' => true, 'notification_sound_alert' => true, 'enable_top_tabs' => true, 'theme' => 'light', 'modal_close_key' => 'Escape']
            );
            $setting->theme = $theme;
            $setting->save();
            $this->theme = $theme;
            $this->feedbackMessage = 'Theme preference updated to ' . ucfirst($theme) . ' Mode.';
            $this->js("localStorage.setItem('rms-theme', '{$theme}'); document.documentElement.setAttribute('data-theme', '{$theme}'); document.cookie = 'rms_theme={$theme}; path=/; max-age=31536000; SameSite=Lax'; document.cookie = 'dark_mode=" . ($theme === 'dark' ? 'enabled' : 'disabled') . "; path=/; max-age=31536000; SameSite=Lax';");
            $this->dispatch('rms-settings-changed', type: 'theme_change', message: 'Theme updated to ' . ucfirst($theme) . ' Mode.');
        }
    }

    public function setModalCloseKey(?string $key): void
    {
        $key = trim((string)$key);
        if (empty($key) || strtolower($key) === 'none') {
            $key = null;
        } elseif (strlen($key) > 50) {
            $key = substr($key, 0, 50);
        }

        $user = Auth::user();
        if ($user) {
            $setting = PersonalSetting::firstOrCreate(
                ['user' => $user->id],
                ['auto_open_chat' => true, 'notification_sound_alert' => true, 'enable_top_tabs' => true, 'theme' => 'light', 'modal_close_key' => 'Escape', 'sidebar_toggle_key' => null]
            );

            $conflictOccurred = false;
            // Conflict check: if key matches sidebarToggleKey, unbind sidebarToggleKey
            if ($key !== null && !empty($this->sidebarToggleKey)) {
                if (strcasecmp($key, $this->sidebarToggleKey) === 0) {
                    $setting->sidebar_toggle_key = null;
                    $this->sidebarToggleKey = null;
                    $conflictOccurred = true;
                    $this->js("localStorage.setItem('rms-sidebar-toggle-key', 'none'); window.RMS_SIDEBAR_TOGGLE_KEY = ''; window.dispatchEvent(new CustomEvent('rms-sidebar-key-changed', { detail: 'none' }));");
                }
            }

            $setting->modal_close_key = $key;
            $setting->save();
            $this->modalCloseKey = $key;

            $displayKey = $key ? ($key === 'Escape' ? 'Esc (Default)' : $key) : 'None (Disabled)';
            if ($conflictOccurred) {
                $this->feedbackMessage = "Assigned '{$displayKey}' to Modal Close (unassigned from Sidebar Toggle to avoid duplicate key).";
            } else {
                $this->feedbackMessage = "Preference updated: Modal close shortcut set to {$displayKey}.";
            }

            $jsKey = $key ? $key : 'none';
            $this->js("localStorage.setItem('rms-modal-close-key', '{$jsKey}'); window.RMS_MODAL_CLOSE_KEY = '{$key}'; window.dispatchEvent(new CustomEvent('rms-modal-key-changed', { detail: '{$jsKey}' }));");
            $this->dispatch('rms-settings-changed', type: 'profile_preference', message: 'Modal close shortcut updated.');
        }
    }

    public function resetModalCloseKey(): void
    {
        $this->setModalCloseKey('Escape');
    }

    public function clearModalCloseKey(): void
    {
        $this->setModalCloseKey(null);
    }

    public function setSidebarToggleKey(?string $key): void
    {
        $key = trim((string)$key);
        if (empty($key) || strtolower($key) === 'none') {
            $key = null;
        } elseif (strlen($key) > 50) {
            $key = substr($key, 0, 50);
        }

        $user = Auth::user();
        if ($user) {
            $setting = PersonalSetting::firstOrCreate(
                ['user' => $user->id],
                ['auto_open_chat' => true, 'notification_sound_alert' => true, 'enable_top_tabs' => true, 'theme' => 'light', 'modal_close_key' => 'Escape', 'sidebar_toggle_key' => null]
            );

            $conflictOccurred = false;
            // Conflict check: if key matches modalCloseKey, unbind modalCloseKey
            if ($key !== null && !empty($this->modalCloseKey)) {
                if (strcasecmp($key, $this->modalCloseKey) === 0) {
                    $setting->modal_close_key = null;
                    $this->modalCloseKey = null;
                    $conflictOccurred = true;
                    $this->js("localStorage.setItem('rms-modal-close-key', 'none'); window.RMS_MODAL_CLOSE_KEY = ''; window.dispatchEvent(new CustomEvent('rms-modal-key-changed', { detail: 'none' }));");
                }
            }

            $setting->sidebar_toggle_key = $key;
            $setting->save();
            $this->sidebarToggleKey = $key;

            $displayKey = $key ? $key : 'None (Unassigned)';
            if ($conflictOccurred) {
                $this->feedbackMessage = "Assigned '{$displayKey}' to Sidebar Toggle (unassigned from Modal Close to avoid duplicate key).";
            } else {
                $this->feedbackMessage = "Preference updated: Sidebar toggle shortcut set to {$displayKey}.";
            }

            $jsKey = $key ? $key : 'none';
            $this->js("localStorage.setItem('rms-sidebar-toggle-key', '{$jsKey}'); window.RMS_SIDEBAR_TOGGLE_KEY = '{$key}'; window.dispatchEvent(new CustomEvent('rms-sidebar-key-changed', { detail: '{$jsKey}' }));");
            $this->dispatch('rms-settings-changed', type: 'profile_preference', message: 'Sidebar toggle shortcut updated.');
        }
    }

    public function resetSidebarToggleKey(): void
    {
        $this->setSidebarToggleKey(null);
    }

    public function checkRoleUpdate(): void
    {
        $user = Auth::user();
        if (!$user) return;
        $user = $user->fresh();
        
        $perms = $user->permissions;
        $permValues = $perms ? [
            $perms->is_sadm, $perms->can_access_dts, $perms->can_access_rdp, $perms->can_access_dcs,
            $perms->can_dts_modify_docflow, $perms->can_sadm_modify_accountlist, $perms->can_sadm_modify_pass,
            $perms->can_sadm_modify_account, $perms->can_dts_view_all_list, $perms->can_dts_view_all_archive,
            $perms->can_dts_view_all_current_trans, $perms->can_dts_create_own_flow
        ] : [];

        if ($this->currentRoleId === null) {
            $this->currentRoleId = $user->account_role;
            $this->currentPermissions = $permValues;
            return;
        }

        if ($user->account_role !== $this->currentRoleId || $permValues !== $this->currentPermissions) {
            $this->js('window.location.reload();');
        }
    }
};
?>

@push('styles')
    @vite('resources/css/profile/personal_details.css')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .personal-details-container .grid-layout {
            display: flex;
            flex-direction: column;
            gap: 24px;
            width: 100%;
        }

        .settings-block-row {
            display: flex;
            flex-direction: column;
            padding: 18px 0;
            border-bottom: 1px solid #f1f5f9;
            gap: 8px;
        }
        .settings-block-row:last-child {
            border-bottom: none;
            padding-bottom: 4px;
        }
        .settings-block-row:first-of-type {
            padding-top: 4px;
        }
        [data-theme="dark"] .settings-block-row {
            border-bottom-color: #1e293b !important;
        }

        .settings-row-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
        }

        .settings-info-title {
            font-size: 15px;
            font-weight: 600;
            color: #1e293b;
            display: flex;
            align-items: center;
            font-family: 'Inter', sans-serif;
        }
        [data-theme="dark"] .settings-info-title {
            color: #f1f5f9 !important;
        }

        .settings-info-desc {
            font-size: 13px;
            color: #64748b;
            display: block;
            margin-top: 2px;
            font-family: 'Inter', sans-serif;
            line-height: 1.5;
            width: 100%;
        }
        [data-theme="dark"] .settings-info-desc {
            color: #94a3b8 !important;
        }

        .settings-toast {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 18px;
            border-radius: 10px;
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 18px;
            animation: fadeIn 0.25s ease-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .settings-clarification-badge {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            margin-top: 6px;
            font-size: 12px;
            line-height: 1.5;
            color: #475569;
            background: rgba(37, 99, 235, 0.07);
            padding: 8px 14px;
            border-radius: 8px;
            border: 1px solid rgba(37, 99, 235, 0.18);
            width: 100%;
            box-sizing: border-box;
        }
        [data-theme="dark"] .settings-clarification-badge {
            background: rgba(37, 99, 235, 0.12) !important;
            border-color: rgba(37, 99, 235, 0.3) !important;
            color: #cbd5e1 !important;
        }
        [data-theme="dark"] .settings-clarification-badge strong {
            color: #60a5fa !important;
        }

        .settings-controls-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 6px;
            width: 100%;
        }

        .theme-selector-container {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.04);
        }
        [data-theme="dark"] .theme-selector-container {
            background: #090d16 !important;
            border: 1px solid #1e293b !important;
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.4) !important;
        }

        .theme-option-btn {
            background: transparent;
            color: #64748b;
        }
        .theme-option-btn:hover {
            color: #1e293b;
            background: rgba(0, 0, 0, 0.03);
        }
        [data-theme="dark"] .theme-option-btn {
            color: #94a3b8;
        }
        [data-theme="dark"] .theme-option-btn:hover {
            color: #f8fafc;
            background: rgba(255, 255, 255, 0.06);
        }

        .theme-option-btn--active-light {
            background: #ffffff !important;
            color: #043899 !important;
            border-color: #cbd5e1 !important;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.08), 0 1px 2px rgba(0, 0, 0, 0.04) !important;
        }
        [data-theme="dark"] .theme-option-btn--active-light {
            background: #ffffff !important;
            color: #0f172a !important;
            border-color: #ffffff !important;
            box-shadow: 0 2px 8px rgba(255, 255, 255, 0.25) !important;
        }

        .theme-option-btn--active-dark {
            background: #1e293b !important;
            color: #60a5fa !important;
            border-color: #334155 !important;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.15) !important;
        }
        [data-theme="dark"] .theme-option-btn--active-dark {
            background: #2563eb !important;
            color: #ffffff !important;
            border-color: #3b82f6 !important;
            box-shadow: 0 2px 10px rgba(37, 99, 235, 0.45) !important;
        }
        .theme-option-btn--active-dark i {
            color: #818cf8;
        }
        [data-theme="dark"] .theme-option-btn--active-dark i {
            color: #ffffff !important;
        }

        /* Modal Close Shortcut Key Configuration UI */
        .shortcut-badge-container {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            padding: 5px 12px;
            border-radius: 9px;
        }
        [data-theme="dark"] .shortcut-badge-container {
            background: #090d16 !important;
            border-color: #1e293b !important;
        }

        .shortcut-key-label {
            font-size: 12px;
            color: #64748b;
            font-weight: 500;
        }
        [data-theme="dark"] .shortcut-key-label {
            color: #94a3b8 !important;
        }

        .shortcut-kbd-badge {
            display: inline-block;
            padding: 3px 8px;
            font-size: 12px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-weight: 700;
            line-height: 1.2;
            color: #1e293b;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-bottom-width: 2px;
            border-radius: 6px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }
        [data-theme="dark"] .shortcut-kbd-badge {
            color: #60a5fa !important;
            background: #1e293b !important;
            border-color: #334155 !important;
            border-bottom-color: #475569 !important;
        }

        .shortcut-select-wrapper {
            position: relative;
            display: inline-block;
        }

        .shortcut-select-dropdown {
            appearance: none;
            -webkit-appearance: none;
            background: #ffffff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2364748b' stroke-width='2'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E") no-repeat right 10px center;
            background-size: 14px;
            border: 1px solid #cbd5e1;
            color: #334155;
            font-size: 12.5px;
            font-weight: 600;
            padding: 7px 32px 7px 12px;
            border-radius: 8px;
            cursor: pointer;
            outline: none;
            transition: all 0.2s ease;
        }
        .shortcut-select-dropdown:hover {
            border-color: #94a3b8;
        }
        .shortcut-select-dropdown:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15);
        }
        .shortcut-select-dropdown option {
            background-color: #ffffff;
            color: #1e293b;
            padding: 6px 10px;
        }
        [data-theme="dark"] .shortcut-select-dropdown {
            background-color: #1e293b !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2394a3b8' stroke-width='2'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E") !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }
        [data-theme="dark"] .shortcut-select-dropdown option {
            background-color: #1e293b !important;
            color: #f8fafc !important;
        }
        [data-theme="dark"] .shortcut-select-dropdown option:checked {
            background-color: #2563eb !important;
            color: #ffffff !important;
        }

        .shortcut-record-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 13px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #2563eb;
            font-size: 12.5px;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            outline: none;
        }
        .shortcut-record-btn:hover {
            background: #dbeafe;
            border-color: #93c5fd;
        }
        [data-theme="dark"] .shortcut-record-btn {
            background: rgba(37, 99, 235, 0.15) !important;
            border-color: rgba(37, 99, 235, 0.35) !important;
            color: #93c5fd !important;
        }
        [data-theme="dark"] .shortcut-record-btn:hover {
            background: rgba(37, 99, 235, 0.25) !important;
        }

        .shortcut-record-btn--active {
            background: #fef2f2 !important;
            border-color: #fecaca !important;
            color: #dc2626 !important;
            animation: recordingPulse 1.2s infinite ease-in-out;
        }
        [data-theme="dark"] .shortcut-record-btn--active {
            background: rgba(220, 38, 38, 0.2) !important;
            border-color: rgba(220, 38, 38, 0.5) !important;
            color: #f87171 !important;
        }

        @keyframes recordingPulse {
            0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
            50% { transform: scale(1.02); box-shadow: 0 0 0 6px rgba(239, 68, 68, 0); }
            100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }

        .shortcut-reset-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 7px 11px;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            color: #64748b;
            font-size: 12px;
            font-weight: 500;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            outline: none;
        }
        .shortcut-reset-btn:hover {
            background: #f1f5f9;
            color: #1e293b;
            border-color: #94a3b8;
        }
        [data-theme="dark"] .shortcut-reset-btn {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #94a3b8 !important;
        }
        [data-theme="dark"] .shortcut-reset-btn:hover {
            background: #1e293b !important;
            color: #f8fafc !important;
        }
    </style>
@endpush

<div class="container personal-details-container" wire:poll.5s="checkRoleUpdate">
    <!-- Hero Banner -->
    <div class="profile-hero-banner">
        <div class="hero-left">
            <div class="avatar-circle">
                <i class="fa-solid fa-sliders" style="font-size: 24px;"></i>
            </div>
            <div class="hero-user-info">
                <span class="hero-greeting">Profile Manager</span>
                <h1 class="hero-name">Preferences & Settings</h1>
            </div>
        </div>
        <div class="hero-right">
            <div class="hero-clock" wire:ignore>
                <span id="currTime" class="clock-time">--:--:--</span>
                <span id="currDate" class="clock-date">--</span>
            </div>
        </div>
    </div>

    <!-- Settings Feedback Alert -->
    @if($feedbackMessage)
        <div class="settings-toast" x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)">
            <i class="fa-solid fa-circle-check" style="font-size: 15px; color: #10b981;"></i>
            <span>{{ $feedbackMessage }}</span>
        </div>
    @endif

    <!-- Settings Layout Grid (Vertical Stacking) -->
    <div class="grid-layout">
        <!-- Communication & Widget Settings -->
        <div class="profile-card">
            <h2 class="card-title">
                <i class="fa-solid fa-comments"></i> Communication & Messaging
            </h2>

            <!-- Auto-open Chatify -->
            <div class="settings-block-row">
                <div class="settings-row-header">
                    <span class="settings-info-title">
                        <i class="fa-solid fa-message" style="color: #2563eb; margin-right: 8px; font-size: 14px;"></i>
                        Auto-open Chatify upon login
                    </span>
                    <button type="button" wire:click="toggleAutoOpenChat" role="switch" aria-checked="{{ $autoOpenChat ? 'true' : 'false' }}"
                        style="position: relative; display: inline-flex; width: 48px; height: 26px; border: none; cursor: pointer; background-color: {{ $autoOpenChat ? '#2563eb' : '#cbd5e1' }}; transition: background-color 0.25s ease; border-radius: 26px; padding: 0; outline: none; flex-shrink: 0;">
                        <span style="position: absolute; top: 3px; left: {{ $autoOpenChat ? '25px' : '3px' }}; width: 20px; height: 20px; background-color: #ffffff; border-radius: 50%; transition: left 0.25s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.2);"></span>
                    </button>
                </div>
                <span class="settings-info-desc">
                    Automatically pop up the floating Chatify messaging widget whenever you sign into your RMS account.
                </span>
            </div>

            <!-- Notification Sound Alerts -->
            <div class="settings-block-row">
                <div class="settings-row-header">
                    <span class="settings-info-title">
                        <i class="fa-solid fa-volume-high" style="color: #2563eb; margin-right: 8px; font-size: 14px;"></i>
                        Notification Sound Alerts
                    </span>
                    <button type="button" wire:click="toggleNotificationSoundAlert" role="switch" aria-checked="{{ $notificationSoundAlert ? 'true' : 'false' }}"
                        style="position: relative; display: inline-flex; width: 48px; height: 26px; border: none; cursor: pointer; background-color: {{ $notificationSoundAlert ? '#2563eb' : '#cbd5e1' }}; transition: background-color 0.25s ease; border-radius: 26px; padding: 0; outline: none; flex-shrink: 0;">
                        <span style="position: absolute; top: 3px; left: {{ $notificationSoundAlert ? '25px' : '3px' }}; width: 20px; height: 20px; background-color: #ffffff; border-radius: 50%; transition: left 0.25s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.2);"></span>
                    </button>
                </div>
                <span class="settings-info-desc">
                    Play audio sound effects (SFX) whenever new unread messages or important system notifications arrive.
                </span>
            </div>
        </div>

        <!-- Personal Customizations Card -->
        <div class="profile-card">
            <h2 class="card-title">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Personal Customizations
            </h2>

            <!-- Enable Top Navigation Tabs -->
            <div class="settings-block-row">
                <div class="settings-row-header">
                    <span class="settings-info-title">
                        <i class="fa-solid fa-table-columns" style="color: #2563eb; margin-right: 8px; font-size: 14px;"></i>
                        Enable Top Navigation Tabs
                    </span>
                    <button type="button" wire:click="toggleEnableTopTabs" role="switch" aria-checked="{{ $enableTopTabs ? 'true' : 'false' }}"
                        style="position: relative; display: inline-flex; width: 48px; height: 26px; border: none; cursor: pointer; background-color: {{ $enableTopTabs ? '#2563eb' : '#cbd5e1' }}; transition: background-color 0.25s ease; border-radius: 26px; padding: 0; outline: none; flex-shrink: 0;">
                        <span style="position: absolute; top: 3px; left: {{ $enableTopTabs ? '25px' : '3px' }}; width: 20px; height: 20px; background-color: #ffffff; border-radius: 50%; transition: left 0.25s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.2);"></span>
                    </button>
                </div>
                <span class="settings-info-desc">
                    Display horizontal quick-toggle tabs above views for fast section switching.
                </span>
                <div class="settings-clarification-badge">
                    <i class="fa-solid fa-circle-info" style="color: #2563eb; margin-top: 2px;"></i>
                    <span><strong>Clarification:</strong> Top Navigation Tabs are currently supported across <strong>Admin Console</strong>, <strong>Document Tracking System (DTS)</strong>, <strong>Records Disposition Program (RDP)</strong>, and <strong>Document Control System (DCS)</strong>.</span>
                </div>
            </div>

            <!-- Theme Appearance (Light / Dark) -->
            <div class="settings-block-row">
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
                    <!-- Left Column: Title and Description -->
                    <div style="flex: 1; min-width: 260px;">
                        <span class="settings-info-title">
                            <i class="fa-solid fa-circle-half-stroke" style="color: #2563eb; margin-right: 8px; font-size: 14px;"></i>
                            Theme Appearance
                        </span>
                        <span class="settings-info-desc" style="margin-top: 3px;">
                            Switch between Light and Dark interface modes.
                        </span>
                    </div>

                    <!-- Right Column: Buttons on the side, vertically centered -->
                    <div style="display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end; flex-shrink: 0;">
                        <div class="theme-selector-container" 
                             x-data="{
                                 activeTheme: @entangle('theme'),
                                 init() {
                                     const domTheme = document.documentElement.getAttribute('data-theme') || localStorage.getItem('rms-theme');
                                     if (domTheme && (domTheme === 'light' || domTheme === 'dark')) {
                                         if (this.activeTheme !== domTheme) {
                                             this.activeTheme = domTheme;
                                             $wire.setTheme(domTheme);
                                         }
                                     }
                                 }
                             }"
                             style="display: inline-flex; align-items: center; padding: 4px; border-radius: 12px; gap: 4px; box-sizing: border-box;">
                            <button type="button" 
                                @click="activeTheme = 'light'; $wire.setTheme('light')" 
                                class="theme-option-btn"
                                :class="{ 'theme-option-btn--active-light': activeTheme === 'light' }"
                                style="display: inline-flex; align-items: center; gap: 7px; padding: 7px 18px; font-size: 13px; font-weight: 600; border-radius: 9px; cursor: pointer; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); outline: none; border: 1px solid transparent; font-family: 'Inter', sans-serif;">
                                <i class="fa-solid fa-sun" style="color: #f59e0b; font-size: 14px;"></i>
                                <span>Light</span>
                            </button>
                            <button type="button" 
                                @click="activeTheme = 'dark'; $wire.setTheme('dark')" 
                                class="theme-option-btn"
                                :class="{ 'theme-option-btn--active-dark': activeTheme === 'dark' }"
                                style="display: inline-flex; align-items: center; gap: 7px; padding: 7px 18px; font-size: 13px; font-weight: 600; border-radius: 9px; cursor: pointer; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); outline: none; border: 1px solid transparent; font-family: 'Inter', sans-serif;">
                                <i class="fa-solid fa-moon" style="font-size: 13px;"></i>
                                <span>Dark</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Full-width Clarification Badge -->
                <div class="settings-clarification-badge" style="margin-top: 6px;">
                    <i class="fa-solid fa-circle-info" style="color: #2563eb; margin-top: 2px;"></i>
                    <span><strong>Clarification:</strong> Dark Mode is currently supported across <strong>Admin Console</strong>, <strong>Profile Manager</strong>, <strong>Document Tracking System (DTS)</strong>, <strong>Records Disposition Program (RDP)</strong>, and <strong>Document Control System (DCS)</strong>.</span>
                </div>
            </div>
        </div>

        <!-- Key Binds Card -->
        <div class="profile-card">
            <h2 class="card-title">
                <i class="fa-solid fa-keyboard"></i> Key Binds
            </h2>

            <!-- Modal Close Shortcut Key Configuration -->
            <div class="settings-block-row"
                 x-data="{
                     isRecording: false,
                     currentKey: @entangle('modalCloseKey'),
                     startRecording() {
                         this.isRecording = true;
                         const handleKey = (e) => {
                             e.preventDefault();
                             e.stopPropagation();
                             let pressedKey = e.key;
                             if (pressedKey === ' ' || e.code === 'Space') pressedKey = 'Space';
                             if (pressedKey === 'Escape' || pressedKey === 'Esc') pressedKey = 'Escape';
                             $wire.setModalCloseKey(pressedKey);
                             this.isRecording = false;
                             window.removeEventListener('keydown', handleKey, true);
                         };
                         window.addEventListener('keydown', handleKey, true);
                         setTimeout(() => {
                             if (this.isRecording) {
                                 this.isRecording = false;
                                 window.removeEventListener('keydown', handleKey, true);
                             }
                         }, 10000);
                     }
                 }">
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
                    <!-- Left Column: Keybind Name and Description -->
                    <div style="flex: 1; min-width: 260px;">
                        <span class="settings-info-title">
                            <i class="fa-solid fa-window-restore" style="color: #2563eb; margin-right: 8px; font-size: 14px;"></i>
                            Modal Close Shortcut Key
                        </span>
                        <span class="settings-info-desc" style="margin-top: 3px;">
                            Choose or record a keyboard shortcut key to instantly close open modals and dialogs.
                        </span>
                    </div>

                    <!-- Right Column: Buttons centered vertically relative to Name + Description -->
                    <div style="display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end; flex-shrink: 0;">
                        <!-- Key Badge Display -->
                        <div class="shortcut-badge-container">
                            <span class="shortcut-key-label">Active Key:</span>
                            <kbd class="shortcut-kbd-badge" 
                                 :style="!currentKey || currentKey === 'none' ? 'color: #94a3b8; font-weight: 500;' : ''" 
                                 x-text="!currentKey || currentKey === 'none' ? 'None' : (currentKey === 'Escape' ? 'Esc' : currentKey)">{{ empty($modalCloseKey) || $modalCloseKey === 'none' ? 'None' : ($modalCloseKey === 'Escape' ? 'Esc' : $modalCloseKey) }}</kbd>
                        </div>

                        <!-- Quick Preset Dropdown -->
                        <div class="shortcut-select-wrapper">
                            <select class="shortcut-select-dropdown"
                                    @change="$wire.setModalCloseKey($event.target.value)"
                                    :value="!currentKey || currentKey === 'none' ? 'none' : (['Escape', 'F2', 'F4', 'F8', 'F9', 'F12', '`', 'q', 'x'].includes(currentKey) ? currentKey : 'custom')">
                                <option value="none">None (Disabled)</option>
                                <option value="Escape">Esc (Default)</option>
                                <option value="F2">F2 Key</option>
                                <option value="F4">F4 Key</option>
                                <option value="F8">F8 Key</option>
                                <option value="F9">F9 Key</option>
                                <option value="F12">F12 Key</option>
                                <option value="`">Backquote ( ` / ~ )</option>
                                <option value="q">Q Key</option>
                                <option value="x">X Key</option>
                                <option value="custom" x-show="currentKey && currentKey !== 'none' && !['Escape', 'F2', 'F4', 'F8', 'F9', 'F12', '`', 'q', 'x'].includes(currentKey)" x-text="'Custom Key (' + (currentKey === ' ' ? 'Space' : currentKey) + ')'">Custom Key ({{ $modalCloseKey }})</option>
                            </select>
                        </div>

                        <!-- Record Key Button -->
                        <button type="button" 
                                @click="startRecording()" 
                                :class="{ 'shortcut-record-btn--active': isRecording }"
                                class="shortcut-record-btn"
                                title="Click to press any key on your keyboard to set as close shortcut">
                            <i class="fa-solid fa-record-vinyl" :style="isRecording ? 'color: #ef4444; animation: recordingPulse 1s infinite;' : ''"></i>
                            <span x-text="isRecording ? 'Press key now...' : 'Record Key'">Record Key</span>
                        </button>

                        <!-- Reset to Esc Button (visible when not Esc) -->
                        <button type="button" 
                                wire:click="resetModalCloseKey"
                                class="shortcut-reset-btn"
                                x-show="currentKey !== 'Escape'"
                                title="Reset shortcut back to default Escape (Esc)">
                            <i class="fa-solid fa-arrow-rotate-left"></i>
                            <span>Reset (Esc)</span>
                        </button>

                        <!-- Clear Button (visible when a key is assigned) -->
                        <button type="button" 
                                wire:click="clearModalCloseKey"
                                class="shortcut-reset-btn"
                                x-show="currentKey && currentKey !== 'none'"
                                title="Clear shortcut and disable modal close keybind">
                            <i class="fa-solid fa-trash-can"></i>
                            <span>Clear</span>
                        </button>
                    </div>
                </div>

                <!-- Full-width Clarification Badge -->
                <div class="settings-clarification-badge" style="margin-top: 6px;">
                    <i class="fa-solid fa-circle-info" style="color: #2563eb; margin-top: 2px;"></i>
                    <span><strong>Clarification:</strong> By default, this is set to <strong>Escape (Esc)</strong>. When pressed, it closes any active modal across all subsystems (except Action menu, notification dropdown, and Chatify widget). Set to <strong>None</strong> to disable the keyboard shortcut.</span>
                </div>
            </div>

            <!-- Sidebar Navigation Toggle Shortcut Key Configuration -->
            <div class="settings-block-row"
                 style="border-top: 1px solid var(--border-color, #e2e8f0); padding-top: 18px; margin-top: 14px;"
                 x-data="{
                     isRecording: false,
                     currentKey: @entangle('sidebarToggleKey'),
                     startRecording() {
                         this.isRecording = true;
                         const handleKey = (e) => {
                             e.preventDefault();
                             e.stopPropagation();
                             let pressedKey = e.key;
                             if (pressedKey === ' ' || e.code === 'Space') pressedKey = 'Space';
                             if (pressedKey === 'Escape' || pressedKey === 'Esc') pressedKey = 'Escape';
                             $wire.setSidebarToggleKey(pressedKey);
                             this.isRecording = false;
                             window.removeEventListener('keydown', handleKey, true);
                         };
                         window.addEventListener('keydown', handleKey, true);
                         setTimeout(() => {
                             if (this.isRecording) {
                                 this.isRecording = false;
                                 window.removeEventListener('keydown', handleKey, true);
                             }
                         }, 10000);
                     }
                 }">
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
                    <!-- Left Column: Keybind Name and Description -->
                    <div style="flex: 1; min-width: 260px;">
                        <span class="settings-info-title">
                            <i class="fa-solid fa-bars-staggered" style="color: #2563eb; margin-right: 8px; font-size: 14px;"></i>
                            Sidebar Navigation Panel Toggle
                        </span>
                        <span class="settings-info-desc" style="margin-top: 3px;">
                            Choose or record a keyboard shortcut key to toggle open and close the sidebar navigation panel.
                        </span>
                    </div>

                    <!-- Right Column: Buttons centered vertically relative to Name + Description -->
                    <div style="display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end; flex-shrink: 0;">
                        <!-- Key Badge Display -->
                        <div class="shortcut-badge-container">
                            <span class="shortcut-key-label">Active Key:</span>
                            <kbd class="shortcut-kbd-badge" 
                                 :style="!currentKey || currentKey === 'none' ? 'color: #94a3b8; font-weight: 500;' : ''" 
                                 x-text="!currentKey || currentKey === 'none' ? 'None' : currentKey">{{ empty($sidebarToggleKey) ? 'None' : $sidebarToggleKey }}</kbd>
                        </div>

                        <!-- Quick Preset Dropdown -->
                        <div class="shortcut-select-wrapper">
                            <select class="shortcut-select-dropdown"
                                    @change="$wire.setSidebarToggleKey($event.target.value)"
                                    :value="!currentKey || currentKey === 'none' ? 'none' : (['\\', '[', ']', 'F1', 'F3', 'b', 'm', 'n'].includes(currentKey) ? currentKey : 'custom')">
                                <option value="none">None (Disabled)</option>
                                <option value="\">Backslash ( \ )</option>
                                <option value="[">Left Bracket ( [ )</option>
                                <option value="]">Right Bracket ( ] )</option>
                                <option value="F1">F1 Key</option>
                                <option value="F3">F3 Key</option>
                                <option value="b">B Key</option>
                                <option value="m">M Key</option>
                                <option value="n">N Key</option>
                                <option value="custom" x-show="currentKey && currentKey !== 'none' && !['\\', '[', ']', 'F1', 'F3', 'b', 'm', 'n'].includes(currentKey)" x-text="'Custom Key (' + (currentKey === ' ' ? 'Space' : currentKey) + ')'">Custom Key ({{ $sidebarToggleKey }})</option>
                            </select>
                        </div>

                        <!-- Record Key Button -->
                        <button type="button" 
                                @click="startRecording()" 
                                :class="{ 'shortcut-record-btn--active': isRecording }"
                                class="shortcut-record-btn"
                                title="Click to press any key on your keyboard to set as sidebar toggle shortcut">
                            <i class="fa-solid fa-record-vinyl" :style="isRecording ? 'color: #ef4444; animation: recordingPulse 1s infinite;' : ''"></i>
                            <span x-text="isRecording ? 'Press key now...' : 'Record Key'">Record Key</span>
                        </button>

                        <!-- Clear / Reset Button (visible when a key is set) -->
                        <button type="button" 
                                wire:click="resetSidebarToggleKey"
                                class="shortcut-reset-btn"
                                x-show="currentKey && currentKey !== 'none'"
                                title="Clear shortcut and disable sidebar toggle keybind">
                            <i class="fa-solid fa-trash-can"></i>
                            <span>Clear</span>
                        </button>
                    </div>
                </div>

                <!-- Full-width Clarification Badge -->
                <div class="settings-clarification-badge" style="margin-top: 6px;">
                    <i class="fa-solid fa-circle-info" style="color: #2563eb; margin-top: 2px;"></i>
                    <span><strong>Clarification:</strong> By default, this is <strong>Unassigned (None)</strong>. When assigned to any key, pressing it will quickly collapse or expand the navigation sidebar across all subsystems.</span>
                </div>
            </div>
        </div>
    </div>
</div>



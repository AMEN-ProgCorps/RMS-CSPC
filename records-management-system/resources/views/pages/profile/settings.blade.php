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
        }
    }

    public function toggleAutoOpenChat(): void
    {
        $user = Auth::user();
        if ($user) {
            $setting = PersonalSetting::firstOrCreate(
                ['user' => $user->id],
                ['auto_open_chat' => true, 'notification_sound_alert' => true, 'enable_top_tabs' => true, 'theme' => 'light']
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
                ['auto_open_chat' => true, 'notification_sound_alert' => true, 'enable_top_tabs' => true, 'theme' => 'light']
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
                ['auto_open_chat' => true, 'notification_sound_alert' => true, 'enable_top_tabs' => true, 'theme' => 'light']
            );
            $setting->enable_top_tabs = !((bool)($setting->enable_top_tabs ?? true));
            $setting->save();
            $this->enableTopTabs = (bool) $setting->enable_top_tabs;
            $this->feedbackMessage = 'Preference updated: Top Navigation Tabs ' . ($this->enableTopTabs ? 'enabled' : 'disabled') . '.';
            $this->dispatch('rms-settings-changed', type: 'profile_preference', message: 'DTS & RDP Top Navigation Tabs preference updated.');
        }
    }

    public function setTheme(string $theme): void
    {
        $theme = in_array($theme, ['light', 'dark']) ? $theme : 'light';
        $user = Auth::user();
        if ($user) {
            $setting = PersonalSetting::firstOrCreate(
                ['user' => $user->id],
                ['auto_open_chat' => true, 'notification_sound_alert' => true, 'enable_top_tabs' => true, 'theme' => 'light']
            );
            $setting->theme = $theme;
            $setting->save();
            $this->theme = $theme;
            $this->feedbackMessage = 'Theme preference updated to ' . ucfirst($theme) . ' Mode.';
            $this->js("localStorage.setItem('rms-theme', '{$theme}'); document.documentElement.setAttribute('data-theme', '{$theme}');");
            $this->dispatch('rms-settings-changed', type: 'theme_change', message: 'Theme updated to ' . ucfirst($theme) . ' Mode.');
        }
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
        .settings-toggle-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .settings-toggle-row:last-child {
            border-bottom: none;
            padding-bottom: 4px;
        }
        .settings-toggle-row:first-of-type {
            padding-top: 4px;
        }
        .settings-info-title {
            font-size: 14.5px;
            font-weight: 600;
            color: #1e293b;
            display: block;
            font-family: 'Inter', sans-serif;
        }
        .settings-info-desc {
            font-size: 12.5px;
            color: #64748b;
            display: block;
            margin-top: 3px;
            font-family: 'Inter', sans-serif;
            max-width: 580px;
            line-height: 1.45;
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

    <!-- Settings Layout Grid -->
    <div class="grid-layout">
        <!-- Communication & Widget Settings -->
        <div class="profile-card">
            <h2 class="card-title">
                <i class="fa-solid fa-comments"></i> Communication & Messaging
            </h2>

            <div class="settings-toggle-row">
                <div>
                    <span class="settings-info-title">Auto-open Chatify upon login</span>
                    <span class="settings-info-desc">Automatically pop up the floating Chatify messaging widget whenever you sign into your RMS account.</span>
                </div>
                <div>
                    <button type="button" wire:click="toggleAutoOpenChat" role="switch" aria-checked="{{ $autoOpenChat ? 'true' : 'false' }}"
                        style="position: relative; display: inline-flex; width: 48px; height: 26px; border: none; cursor: pointer; background-color: {{ $autoOpenChat ? '#2563eb' : '#cbd5e1' }}; transition: background-color 0.25s ease; border-radius: 26px; padding: 0; outline: none; flex-shrink: 0;">
                        <span style="position: absolute; top: 3px; left: {{ $autoOpenChat ? '25px' : '3px' }}; width: 20px; height: 20px; background-color: #ffffff; border-radius: 50%; transition: left 0.25s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.2);"></span>
                    </button>
                </div>
            </div>

            <div class="settings-toggle-row">
                <div>
                    <span class="settings-info-title">Notification Sound Alerts</span>
                    <span class="settings-info-desc">Play audio sound effects (SFX) whenever new unread messages or important system notifications arrive.</span>
                </div>
                <div>
                    <button type="button" wire:click="toggleNotificationSoundAlert" role="switch" aria-checked="{{ $notificationSoundAlert ? 'true' : 'false' }}"
                        style="position: relative; display: inline-flex; width: 48px; height: 26px; border: none; cursor: pointer; background-color: {{ $notificationSoundAlert ? '#2563eb' : '#cbd5e1' }}; transition: background-color 0.25s ease; border-radius: 26px; padding: 0; outline: none; flex-shrink: 0;">
                        <span style="position: absolute; top: 3px; left: {{ $notificationSoundAlert ? '25px' : '3px' }}; width: 20px; height: 20px; background-color: #ffffff; border-radius: 50%; transition: left 0.25s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.2);"></span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Personal Customizations Card -->
        <div class="profile-card">
            <h2 class="card-title">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Personal Customizations
            </h2>

            <div class="settings-toggle-row">
                <div>
                    <span class="settings-info-title">Enable DTS & RDP Top Navigation Tabs</span>
                    <span class="settings-info-desc">Display horizontal quick-toggle tabs above the records table in Document Tracking System (DTS) and Records Disposition Program (RDP).</span>
                </div>
                <div>
                    <button type="button" wire:click="toggleEnableTopTabs" role="switch" aria-checked="{{ $enableTopTabs ? 'true' : 'false' }}"
                        style="position: relative; display: inline-flex; width: 48px; height: 26px; border: none; cursor: pointer; background-color: {{ $enableTopTabs ? '#2563eb' : '#cbd5e1' }}; transition: background-color 0.25s ease; border-radius: 26px; padding: 0; outline: none; flex-shrink: 0;">
                        <span style="position: absolute; top: 3px; left: {{ $enableTopTabs ? '25px' : '3px' }}; width: 20px; height: 20px; background-color: #ffffff; border-radius: 50%; transition: left 0.25s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.2);"></span>
                    </button>
                </div>
            </div>

            <!-- Theme Appearance (Light / Dark) -->
            <div class="settings-toggle-row" style="border-top: 1px solid var(--border-color, #e2e8f0); padding-top: 18px; margin-top: 14px; align-items: flex-start;">
                <div style="flex: 1; min-width: 0; padding-right: 16px;">
                    <span class="settings-info-title" style="display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-circle-half-stroke" style="color: #2563eb;"></i>
                        Theme Appearance
                    </span>
                    <span class="settings-info-desc">
                        Switch between Light and Dark interface modes.
                        <br>
                        <span style="display: inline-block; margin-top: 5px; font-size: 11.5px; color: #64748b; background: rgba(37, 99, 235, 0.08); padding: 4px 8px; border-radius: 6px; border: 1px solid rgba(37, 99, 235, 0.15);">
                            <i class="fa-solid fa-circle-info" style="color: #2563eb; margin-right: 3px;"></i>
                            <strong>Clarification:</strong> Dark Mode is currently supported across <strong>Admin Console</strong>, <strong>Profile Manager</strong>, <strong>Document Tracking System (DTS)</strong>, and <strong>Records Disposition Program (RDP)</strong>.
                        </span>
                    </span>
                </div>
                <div>
                    <div style="display: inline-flex; align-items: center; background: var(--theme-toggle-bg, #f1f5f9); padding: 4px; border-radius: 10px; gap: 4px; border: 1px solid var(--border-color, #e2e8f0);" class="theme-selector-container">
                        <button type="button" wire:click="setTheme('light')" 
                            style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; font-size: 12px; font-weight: 600; border-radius: 7px; border: none; cursor: pointer; transition: all 0.2s ease; background: {{ $theme === 'light' ? '#ffffff' : 'transparent' }}; color: {{ $theme === 'light' ? '#0f172a' : '#64748b' }}; box-shadow: {{ $theme === 'light' ? '0 1px 3px rgba(0,0,0,0.1)' : 'none' }};">
                            <i class="fa-solid fa-sun" style="color: #f59e0b;"></i>
                            Light
                        </button>
                        <button type="button" wire:click="setTheme('dark')" 
                            style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; font-size: 12px; font-weight: 600; border-radius: 7px; border: none; cursor: pointer; transition: all 0.2s ease; background: {{ $theme === 'dark' ? '#0f172a' : 'transparent' }}; color: {{ $theme === 'dark' ? '#f8fafc' : '#64748b' }}; box-shadow: {{ $theme === 'dark' ? '0 1px 3px rgba(0,0,0,0.3)' : 'none' }};">
                            <i class="fa-solid fa-moon" style="color: #818cf8;"></i>
                            Dark
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

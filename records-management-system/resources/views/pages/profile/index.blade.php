<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.profile')] #[Title('Profile Manager - Details')] class extends Component {
    public string $firstName     = '';
    public string $lastName      = '';
    public string $middleName    = '';
    public string $email         = '';
    public string $contactNumber = '';
    public int    $accountId     = 0;
    public string $roleName      = '';
    public array  $enabledPermissions = [];

    public function mount(): void
    {
        $user    = auth()->user();
        $details = $user->details;

        if ($details) {
            $this->firstName     = $details->first_name     ?? '';
            $this->lastName      = $details->last_name      ?? '';
            $this->middleName    = $details->middle_name    ?? '';
            $this->email         = $details->email          ?? '';
            $this->contactNumber = $details->contact_number ?? '';
            $this->accountId     = $details->account_id;
        }

        if ($user->account_role) {
            $role = \DB::table('condition_key')->where('id', $user->account_role)->first();
            $this->roleName = $role?->key_name ?? 'Unknown';
        }

        $perms = $user->permissions;
        if ($perms) {
            $labels = [
                'is_sadm'                => 'Super Administrator',
                'can_access_dts'         => 'Access Document Tracking System',
                'can_access_archv'       => 'Access Archive',
                'can_access_dcs'         => 'Access DCS',
                'can_modify_docflow'     => 'Modify Document Flow',
                'can_modify_accountlist' => 'Modify Account List',
                'can_modify_pass'        => 'Modify Passwords',
                'can_modify_user'        => 'Modify Users',
                'can_view_all_list'      => 'View All Lists',
                'can_view_all_archive'   => 'View All Archives',
            ];

            foreach ($labels as $key => $label) {
                if ($perms->$key) {
                    $this->enabledPermissions[] = $label;
                }
            }
        }
    }
};
?>

@push('styles')
    @vite('resources/css/profile/personal_details.css')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
@endpush

<div class="container">
    <div boxid_container="header" class="box-container">
        <div boxid="name" class="box">
            Hello!
            <span>{{ $firstName }}</span>
        </div>
        <div boxid="datentime" class="box">
            <span id="currTime">--:--:--</span>
            <span id="currDate">--</span>
        </div>
    </div>
    <div class="visible-line">
        <hr>
    </div>
    <div boxid_container="details" class="box-container">
        <div boxid="details" class="box">
            <span>Accounts Details</span>
            <hr>
            <div setid="account" class="sets">
                ACCOUNT ID
                <span>{{ $accountId }}</span>
            </div>
            <div setid="firstname" class="sets">
                FIRSTNAME
                <span>{{ $firstName }}</span>
            </div>
            <div setid="middlename" class="sets">
                MIDDLENAME
                <span>{{ $middleName ?: '—' }}</span>
            </div>
            <div setid="Lastname" class="sets">
                LASTNAME
                <span>{{ $lastName }}</span>
            </div>
            <div setid="email" class="sets">
                EMAIL
                <div class="masked-field">
                    <span class="masked-value" data-masked="true">{{ $email }}</span>
                    <button type="button" class="mask-toggle" data-target="email" aria-label="Show hidden value">
                        <span class="eye-icon">
                            <i class="fa-solid fa-eye"></i>
                        </span>
                    </button>
                </div>
            </div>
            <div setid="contacts" class="sets">
                USER CONTACT NUMBER
                <div class="masked-field">
                    <span class="masked-value" data-masked="true">{{ $contactNumber ?: '—' }}</span>
                    <button type="button" class="mask-toggle" data-target="contacts" aria-label="Show hidden value">
                        <span class="eye-icon">
                            <i class="fa-solid fa-eye"></i>
                        </span>
                    </button>
                </div>
            </div>
        </div>
        <div boxid="role" class="box">
            <div setid="role_status" class="sets">
                Current Role
                <span>{{ $roleName ?: 'No Role Assigned' }}</span>
            </div>
            <hr>
            <div setid="column_header" class="sets">
                Enabled
                <span>AFunction </span>
            </div>
            @forelse($enabledPermissions as $index => $permission)
            <div setid="function_{{ $index + 1 }}" class="sets">
                <i class="fa-solid fa-check"></i>
                <span>{{ $permission }}</span>
            </div>
            @empty
            <div setid="function_none" class="sets">
                <span>No permissions assigned</span>
            </div>
            @endforelse
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const timeEl = document.getElementById('currTime');
    const dateEl = document.getElementById('currDate');

    if (!timeEl || !dateEl) {
        return;
    }

    const updateDateTime = () => {
        const now = new Date();
        timeEl.textContent = now.toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
        dateEl.textContent = now.toLocaleDateString([], {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    };

    document.querySelectorAll('.masked-value').forEach(function (valueEl) {
        const plainValue = valueEl.textContent.trim();
        valueEl.setAttribute('data-plain', plainValue);
        valueEl.textContent = '•'.repeat(Math.max(plainValue.length, 8));
    });

    document.querySelectorAll('.mask-toggle').forEach(function (buttonEl) {
        buttonEl.addEventListener('click', function () {
            const target = this.getAttribute('data-target');
            const valueEl = document.querySelector(`[setid="${target}"] .masked-value`);

            if (!valueEl) {
                return;
            }

            const isMasked = valueEl.getAttribute('data-masked') === 'true';
            const plainValue = valueEl.getAttribute('data-plain') || '';

            if (isMasked) {
                valueEl.textContent = plainValue;
                valueEl.setAttribute('data-masked', 'false');
                this.querySelector('.eye-icon').innerHTML = '<i class="fa-solid fa-eye-slash"></i>';
            } else {
                valueEl.textContent = '•'.repeat(Math.max(plainValue.length, 8));
                valueEl.setAttribute('data-masked', 'true');
                this.querySelector('.eye-icon').innerHTML = '<i class="fa-solid fa-eye"></i>';
            }
        });
    });

    updateDateTime();
    setInterval(updateDateTime, 1000);
});
</script>

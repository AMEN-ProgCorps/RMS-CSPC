<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.profile')] #[Title('Profile Manager - Details')] class extends Component {
    //
};
?>

@push('styles')
    @vite('resources/css/profile/personal_details.css')
@endpush
<div class="container">
    <div boxid_container="header" class="box-container">
        <div boxid="name" class="box">
            Hello!
            <span>{acccount_details.firstname}</span>
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
            <div setid="firstname" class="sets">
                <span>Firstname</span>
                <span>{acccount_details.firstname}</span>
            </div>
            <div setid="middlename" class="sets">
                <span>Middlename</span>
                <span>{acccount_details.middlename}</span>
            </div>
            <div setid="Lastname" class="sets">
                <span>Lastname</span>
                <span>{acccount_details.lastname}</span>
            </div>
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

    updateDateTime();
    setInterval(updateDateTime, 1000);
});
</script>

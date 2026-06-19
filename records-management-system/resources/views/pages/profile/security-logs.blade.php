<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.profile')] #[Title('Profile Manager - Security Logs')] class extends Component {
    //
};
?>
@push('style')
    @vite('resources/css/profile/personal_details.css')
@endpush
<div class="container">
    <div boxid_container="header" class="box-container">
        <div boxid="name" class="box">
            Login History
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
            <span>Recent Login Activities</span>
            <hr>
            <!-- in this space here data from security_logs will show ups that bind to 
             the user account id -->
        </div>
    </div>
</div>

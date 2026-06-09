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
            <span>{fullname}</span>
        </div>
        <div boxid="datentime" class="box">
            <span>{Currtime}</span>
            <span>{Currdate}</span>
        </div>
    </div>
    <div class="visible-line">
        <hr>
    </div>
    <div boxid_container="details" class="box-container">
        <div boxid="details" class="box">
            
        </div>
    </div>
</div>

<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.portal')] #[Title('Profile')] class extends Component
{
    //
};
?>

@push('styles')
@endpush

<section class="profile-page">
    <a href="{{ route('portal') }}" wire:navigate class="log">← Back to portal</a>
    <h1>Profile</h1>
    <p>Profile content will go here.</p>
</section>

{{--
  Legacy DCS Recycle Bin page — soft-deletes now live in Admin Console Recycle Bin.
  Route redirects to /admin/recycle-bin; this Volt stub is kept only for stale caches.
--}}
<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('CSPC - Document Control System')] class extends Component {
    public function mount(): void
    {
        $this->redirect(route('admin.recycle-bin', absolute: false), navigate: true);
    }
}; ?>

<div class="main-content">
    <p>Redirecting to Admin Console Recycle Bin…</p>
</div>

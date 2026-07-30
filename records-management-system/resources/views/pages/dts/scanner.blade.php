<?php
/**
 * Legacy DTS Scanner Component - Redirects to Receive Transactions Console
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dts')] #[Title('Redirecting to Receive Transactions...')] class extends Component {
    public function mount()
    {
        return redirect()->route('dts.receive');
    }
}; ?>

<div style="padding: 40px; text-align: center; color: #64748b; font-family: sans-serif;">
    <p>Redirecting to Receive Transactions Console...</p>
</div>

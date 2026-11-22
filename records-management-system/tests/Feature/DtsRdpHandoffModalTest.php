<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DtsRdpHandoffModalTest extends TestCase
{
    protected ?User $user = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::first();
        if ($this->user) {
            $this->actingAs($this->user);
        }
    }

    public function test_handoff_modal_renders_and_opens_on_event()
    {
        $comp = Volt::test('components.dts-rdp-handoff-modal');
        $comp->assertSet('isOpen', false);

        // Dispatch open-dts-rdp-handoff event with a dummy or sample ID
        $comp->dispatch('open-dts-rdp-handoff', transactionId: 1, controlNumber: 'DOC-2026-TEST');

        $comp->assertSet('isOpen', true);
        $comp->assertSee('Proceed this Transaction to RDP?');
        $comp->assertSee('Records Disposition Program');
    }

    public function test_handoff_modal_closes_and_saves_incomplete()
    {
        $comp = Volt::test('components.dts-rdp-handoff-modal')
            ->dispatch('open-dts-rdp-handoff', transactionId: 1, controlNumber: 'DOC-2026-TEST')
            ->assertSet('isOpen', true)
            ->call('closeModal')
            ->assertSet('isOpen', false);
    }

    public function test_dts_received_documents_page_renders_without_import_button()
    {
        $comp = Volt::test('pages.rdp.received-documents.dts');
        $comp->assertSee('Received Documents');
        $comp->assertDontSee('Import from DTS');
        $comp->assertDontSee('Pull from Document Tracking System');
    }
}

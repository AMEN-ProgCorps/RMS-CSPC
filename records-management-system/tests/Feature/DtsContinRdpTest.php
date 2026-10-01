<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DtsRdpIntakeService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * DTS -> RDP continuity (contin_rdp_id):
 *  - the Transaction Flows details panels pick a Record Series entry
 *  - System Settings holds the "Server default" record series
 *  - DTS -> RDP intake resolves flow series, then the server default
 */
class DtsContinRdpTest extends TestCase
{
    private const FLOW_CODE = 'TEST-CONT-RDP-FLOW';
    private const CUSTOM_FLOW_NAME = 'Test Continuity Custom Flow';
    private const TYPE_NAME = 'TEST CONTINUITY TYPE';
    private const SERIES_TITLE = 'TEST CONTINUITY SERIES';
    private const DEFAULT_SERIES_TITLE = 'TEST CONTINUITY DEFAULT SERIES';
    private const SERIES_ITEM_NO = 77001;
    private const DEFAULT_SERIES_ITEM_NO = 77002;
    private const SETTING_KEY = 'rdp_server_default_record_series_id';

    private int $typeId = 0;
    private int $seriesId = 0;
    private int $defaultSeriesId = 0;
    private int $flowId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::find(1);
        if ($admin) {
            Auth::login($admin);
        }

        $this->cleanup();

        $this->typeId = DB::table('rdp_record_series_type')->insertGetId([
            'type_name' => self::TYPE_NAME,
            'shorted_type' => 'TESTCT',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seriesId = DB::table('rdp_record_series')->insertGetId([
            'item_number' => self::SERIES_ITEM_NO,
            'series_title' => self::SERIES_TITLE,
            'series_type' => $this->typeId,
            'is_active' => true,
        ]);

        $this->defaultSeriesId = DB::table('rdp_record_series')->insertGetId([
            'item_number' => self::DEFAULT_SERIES_ITEM_NO,
            'series_title' => self::DEFAULT_SERIES_TITLE,
            'series_type' => $this->typeId,
            'is_active' => true,
        ]);

        $maxFlowId = DB::table('dts_transaction_flow')->max('id') ?? 0;
        $this->flowId = $maxFlowId + 1;
        DB::table('dts_transaction_flow')->insert([
            'id' => $this->flowId,
            'flow_name' => 'Test Continuity Flow',
            'flow_code' => self::FLOW_CODE,
            'is_active' => true,
            'flow_use' => 'internal',
            'flow_for' => 'system',
            'added_by' => 1,
            'date_added' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $flowIds = DB::table('dts_transaction_flow')
            ->whereIn('flow_code', [self::FLOW_CODE])
            ->orWhere('flow_name', self::CUSTOM_FLOW_NAME)
            ->pluck('id');

        if ($flowIds->isNotEmpty()) {
            DB::table('dts_sequence_list')->whereIn('control_id', $flowIds)->delete();
            DB::table('dts_transaction_flow')->whereIn('id', $flowIds)->delete();
        }

        DB::table('dts_transaction_details')->where('id', 'like', 'TRANS-CONT-%')->delete();
        DB::table('dts_transactions')->where('transaction_id', 'like', 'TRANS-CONT-%')->delete();
        DB::table('dts_qr_code')->where('code_id', 'like', 'QR-CONT-%')->delete();
        DB::table('rdp_received_documents')->where('document_code', 'like', 'CT-CONT-%')->delete();

        DB::table('sys_system_settings')->where('key', self::SETTING_KEY)->delete();

        DB::table('rdp_record_series')->whereIn('series_title', [
            self::SERIES_TITLE,
            self::DEFAULT_SERIES_TITLE,
        ])->delete();
        DB::table('rdp_record_series_type')->where('type_name', self::TYPE_NAME)->delete();
    }

    private function makeCompletedTransaction(string $suffix): string
    {
        $detailsId = 'TRANS-CONT-' . $suffix;
        $controlNumber = 'CT-CONT-' . $suffix;
        $qrCode = 'QR-CONT-' . $suffix;

        // FK direction: dts_transaction_details.id -> dts_transactions.transaction_id,
        // so delete details first, and insert the transactions row first.
        DB::table('dts_transaction_details')->where('id', $detailsId)->delete();
        DB::table('dts_transactions')->where('transaction_id', $detailsId)->delete();
        DB::table('rdp_received_documents')->where('document_code', $controlNumber)->delete();
        DB::table('dts_qr_code')->where('code_id', $qrCode)->delete();

        DB::table('dts_qr_code')->insert([
            'code_id' => $qrCode,
            'qr_status' => 'used',
            'created_at' => now(),
        ]);

        DB::table('dts_transactions')->insert([
            'transaction_id' => $detailsId,
            'trans_type' => 'internal',
            'qr_code' => $qrCode,
            'current_office' => 'ORIGIN',
            'status' => 'completed',
            'sequence' => 1,
        ]);

        DB::table('dts_transaction_details')->insert([
            'id' => $detailsId,
            'type' => 'internal',
            'created_by' => 1,
            'originated_from' => 'ORIGIN',
            'current_office_hold' => 'ORIGIN',
            'status' => 'completed',
            'transaction_flow' => self::FLOW_CODE,
            'is_active' => true,
            'date_created' => now(),
            'control_number' => $controlNumber,
            'subject' => 'Continuity test document ' . $suffix,
        ]);

        return $detailsId;
    }

    private function intakeMetadata(string $controlNumber): array
    {
        $raw = DB::table('rdp_received_documents')
            ->where('document_code', $controlNumber)
            ->value('metadata');

        return json_decode((string) $raw, true) ?: [];
    }

    public function test_predefined_details_panel_saves_selected_record_series(): void
    {
        Volt::test('pages.admin.dts.transaction-flows')
            ->set('selectedPredefined', (string) $this->flowId)
            ->assertSee('RDP Record Series (Continuity)')
            ->assertSee('Server default')
            ->assertSee(self::TYPE_NAME) // type filter dropdown is populated from mount()
            ->call('selectContinRdpSeries', $this->seriesId)
            ->assertSet('continRdpId', $this->seriesId)
            ->call('savePredefinedFlow')
            ->assertHasNoErrors();

        $stored = DB::table('dts_transaction_flow')->where('id', $this->flowId)->value('contin_rdp_id');
        $this->assertEquals($this->seriesId, (int) $stored);
    }

    public function test_clearing_selection_reverts_flow_to_server_default(): void
    {
        Volt::test('pages.admin.dts.transaction-flows')
            ->set('selectedPredefined', (string) $this->flowId)
            ->call('selectContinRdpSeries', $this->seriesId)
            ->assertSet('continRdpId', $this->seriesId)
            ->call('clearContinRdpSeries')
            ->assertSet('continRdpId', null)
            ->call('savePredefinedFlow')
            ->assertHasNoErrors();

        $this->assertNull(DB::table('dts_transaction_flow')->where('id', $this->flowId)->value('contin_rdp_id'));
    }

    public function test_typing_over_selection_reverts_to_server_default(): void
    {
        Volt::test('pages.admin.dts.transaction-flows')
            ->set('selectedPredefined', (string) $this->flowId)
            ->call('selectContinRdpSeries', $this->seriesId)
            ->assertSet('continRdpId', $this->seriesId)
            ->set('continRdpSearch', 'Some other series')
            ->assertSet('continRdpId', null);
    }

    public function test_reloading_flow_shows_saved_record_series(): void
    {
        DB::table('dts_transaction_flow')->where('id', $this->flowId)->update([
            'contin_rdp_id' => $this->seriesId,
        ]);

        Volt::test('pages.admin.dts.transaction-flows')
            ->set('selectedPredefined', (string) $this->flowId)
            ->assertSet('continRdpId', $this->seriesId)
            ->assertSet('continRdpSearch', self::SERIES_TITLE);
    }

    public function test_custom_details_panel_saves_selected_record_series(): void
    {
        Volt::test('pages.admin.dts.transaction-flows')
            ->set('selectedCustom', 'new')
            ->set('customFlowName', self::CUSTOM_FLOW_NAME)
            ->set('customFlowUse', 'internal')
            ->call('selectCustomContinRdpSeries', $this->seriesId)
            ->assertSet('customContinRdpId', $this->seriesId)
            ->call('saveCustomFlow')
            ->assertHasNoErrors();

        $saved = DB::table('dts_transaction_flow')
            ->where('flow_name', self::CUSTOM_FLOW_NAME)
            ->first();

        $this->assertNotNull($saved);
        $this->assertEquals($this->seriesId, (int) $saved->contin_rdp_id);
    }

    public function test_intake_uses_flow_linked_record_series(): void
    {
        DB::table('dts_transaction_flow')->where('id', $this->flowId)->update([
            'contin_rdp_id' => $this->seriesId,
        ]);

        // Server default points elsewhere — the flow's own series must win.
        DB::table('sys_system_settings')->updateOrInsert(
            ['key' => self::SETTING_KEY],
            ['value' => (string) $this->defaultSeriesId, 'updated_at' => now()]
        );

        $detailsId = $this->makeCompletedTransaction('A');
        $response = DtsRdpIntakeService::recordCompleted($detailsId);

        $this->assertNotNull($response);
        $this->assertTrue($response['success'] ?? false, 'Intake should have succeeded: ' . json_encode($response));

        $metadata = $this->intakeMetadata('CT-CONT-A');
        $this->assertEquals($this->seriesId, (int) ($metadata['record_series_id'] ?? 0));
        $this->assertEquals($this->typeId, (int) ($metadata['rdp_record_series_type_id'] ?? 0));
    }

    public function test_intake_falls_back_to_server_default_record_series(): void
    {
        DB::table('dts_transaction_flow')->where('id', $this->flowId)->update([
            'contin_rdp_id' => null,
        ]);

        DB::table('sys_system_settings')->updateOrInsert(
            ['key' => self::SETTING_KEY],
            ['value' => (string) $this->defaultSeriesId, 'updated_at' => now()]
        );

        $detailsId = $this->makeCompletedTransaction('B');
        $response = DtsRdpIntakeService::recordCompleted($detailsId);

        $this->assertNotNull($response);
        $this->assertTrue($response['success'] ?? false, 'Intake should have succeeded: ' . json_encode($response));

        $metadata = $this->intakeMetadata('CT-CONT-B');
        $this->assertEquals($this->defaultSeriesId, (int) ($metadata['record_series_id'] ?? 0));
    }

    public function test_intake_sends_no_series_when_server_default_is_unset(): void
    {
        DB::table('dts_transaction_flow')->where('id', $this->flowId)->update([
            'contin_rdp_id' => null,
        ]);
        DB::table('sys_system_settings')->where('key', self::SETTING_KEY)->delete();

        $detailsId = $this->makeCompletedTransaction('C');
        $response = DtsRdpIntakeService::recordCompleted($detailsId);

        $this->assertNotNull($response);
        $this->assertTrue($response['success'] ?? false, 'Intake should have succeeded: ' . json_encode($response));

        $metadata = $this->intakeMetadata('CT-CONT-C');
        $this->assertArrayHasKey('record_series_id', $metadata);
        $this->assertNull($metadata['record_series_id']);
    }

    public function test_system_settings_persists_server_default_record_series(): void
    {
        Volt::test('pages.admin.settings.index')
            ->set('settingsTab', 'rdp')
            ->assertSee('Server Default Record Series')
            ->call('selectRdpServerDefaultSeries', $this->seriesId)
            ->assertSet('rdpServerDefaultRecordSeries', (string) $this->seriesId)
            ->assertSet('rdpSeriesSearch', self::SERIES_TITLE)
            ->call('saveSettings');

        $stored = DB::table('sys_system_settings')
            ->where('key', self::SETTING_KEY)
            ->value('value');

        $this->assertEquals($this->seriesId, (int) $stored);
    }

    public function test_system_settings_series_picker_select_clear_and_type_over(): void
    {
        $component = Volt::test('pages.admin.settings.index')
            ->set('settingsTab', 'rdp')
            // Narrow the suggestions first: the picker lists the first 15 matches,
            // which could otherwise exclude the test series among real data.
            ->set('rdpSeriesSearch', 'TEST CONTINUITY')
            ->assertSee(self::SERIES_TITLE) // picker suggestions list the test series
            ->assertSee(self::TYPE_NAME);   // type filter dropdown is populated from mount()

        // Pick a series from the search picker.
        $component->call('selectRdpServerDefaultSeries', $this->seriesId)
            ->assertSet('rdpServerDefaultRecordSeries', (string) $this->seriesId)
            ->assertSet('rdpSeriesSearch', self::SERIES_TITLE);

        // Typing over the selection reverts to "None".
        $component->set('rdpSeriesSearch', 'Something else')
            ->assertSet('rdpServerDefaultRecordSeries', '');

        // Pick again, then clear with the × button.
        $component->call('selectRdpServerDefaultSeries', $this->seriesId)
            ->assertSet('rdpServerDefaultRecordSeries', (string) $this->seriesId)
            ->call('clearRdpServerDefaultSeries')
            ->assertSet('rdpServerDefaultRecordSeries', '')
            ->assertSet('rdpSeriesSearch', '');
    }

    public function test_system_settings_series_type_filter_narrows_choices(): void
    {
        $otherTypeId = DB::table('rdp_record_series_type')->insertGetId([
            'type_name' => self::TYPE_NAME . ' SETTINGS',
            'shorted_type' => 'TESTCS',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $otherSeriesId = DB::table('rdp_record_series')->insertGetId([
            'series_title' => 'TEST CONTINUITY SETTINGS SERIES',
            'series_type' => $otherTypeId,
            'is_active' => true,
        ]);

        try {
            $component = Volt::test('pages.admin.settings.index')
                ->set('settingsTab', 'rdp')
                ->set('rdpSeriesTypeFilter', (string) $otherTypeId);

            $choices = $component->instance()->rdpSeriesChoices('TEST CONTINUITY', (string) $otherTypeId);

            $this->assertTrue($choices->pluck('id')->contains($otherSeriesId));
            $this->assertFalse($choices->pluck('id')->contains($this->seriesId));

            $allChoices = $component->instance()->rdpSeriesChoices('TEST CONTINUITY', '');
            $this->assertTrue($allChoices->pluck('id')->contains($this->seriesId));
        } finally {
            DB::table('rdp_record_series')->where('id', $otherSeriesId)->delete();
            DB::table('rdp_record_series_type')->where('id', $otherTypeId)->delete();
        }
    }

    public function test_record_series_pickers_show_item_number_at_the_front(): void
    {
        // Transaction Flows details panel: item number chip at the front of the suggestions.
        Volt::test('pages.admin.dts.transaction-flows')
            ->set('selectedPredefined', (string) $this->flowId)
            ->set('continRdpSearch', 'TEST CONTINUITY')
            ->assertSee((string) self::SERIES_ITEM_NO)
            ->assertSee(self::SERIES_TITLE);

        // ...and the "#item · title" status line once a series is selected.
        Volt::test('pages.admin.dts.transaction-flows')
            ->set('selectedPredefined', (string) $this->flowId)
            ->call('selectContinRdpSeries', $this->seriesId)
            ->assertSee('#' . self::SERIES_ITEM_NO);

        // System Settings picker: chip in the suggestions + "#item · title" status line.
        Volt::test('pages.admin.settings.index')
            ->set('settingsTab', 'rdp')
            ->set('rdpSeriesSearch', 'TEST CONTINUITY')
            ->assertSee((string) self::SERIES_ITEM_NO)
            ->call('selectRdpServerDefaultSeries', $this->seriesId)
            ->assertSee('#' . self::SERIES_ITEM_NO);

        // Typing the item number filters to that series (both pickers).
        $settingsChoices = Volt::test('pages.admin.settings.index')
            ->instance()
            ->rdpSeriesChoices((string) self::SERIES_ITEM_NO, '');

        $this->assertTrue($settingsChoices->pluck('id')->contains($this->seriesId));
        $this->assertFalse($settingsChoices->pluck('id')->contains($this->defaultSeriesId));

        $flowChoices = Volt::test('pages.admin.dts.transaction-flows')
            ->instance()
            ->continRdpChoices((string) self::DEFAULT_SERIES_ITEM_NO, '');

        $this->assertTrue($flowChoices->pluck('id')->contains($this->defaultSeriesId));
        $this->assertFalse($flowChoices->pluck('id')->contains($this->seriesId));
    }

    public function test_type_filter_narrows_record_series_choices(): void
    {
        $otherTypeId = DB::table('rdp_record_series_type')->insertGetId([
            'type_name' => self::TYPE_NAME . ' OTHER',
            'shorted_type' => 'TESTCO',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $otherSeriesId = DB::table('rdp_record_series')->insertGetId([
            'series_title' => 'TEST CONTINUITY OTHER SERIES',
            'series_type' => $otherTypeId,
            'is_active' => true,
        ]);

        try {
            $component = Volt::test('pages.admin.dts.transaction-flows')
                ->set('selectedPredefined', (string) $this->flowId)
                ->set('continRdpTypeFilter', (string) $otherTypeId);

            $choices = $component->instance()->continRdpChoices('TEST CONTINUITY', (string) $otherTypeId);

            $this->assertTrue($choices->pluck('id')->contains($otherSeriesId));
            $this->assertFalse($choices->pluck('id')->contains($this->seriesId));

            $allChoices = $component->instance()->continRdpChoices('TEST CONTINUITY', '');
            $this->assertTrue($allChoices->pluck('id')->contains($this->seriesId));
            $this->assertTrue($allChoices->pluck('id')->contains($otherSeriesId));
        } finally {
            DB::table('rdp_record_series')->where('id', $otherSeriesId)->delete();
            DB::table('rdp_record_series_type')->where('id', $otherTypeId)->delete();
        }
    }
}

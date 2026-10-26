<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remarks on rdp_record_series are no longer free text: rdp_record_series.remarks
 * stores the id of an rdp_remarks_logic row (schema: 2026_10_21_130402_rdp_adding_modification).
 * Every record series owns its own logic row, so these helpers keep the pairing
 * (series <-> remark text) in sync. Read-side queries join rdp_remarks_logic and
 * alias rdp_remarks_logic.remarks_value back to "remarks".
 */
class RdpRemarksService
{
    /**
     * The table/col checks let the UI keep working before the migration has run.
     */
    public static function enabled(): bool
    {
        return Schema::hasTable('rdp_remarks_logic')
            && Schema::hasTable('rdp_record_series')
            && Schema::hasColumn('rdp_record_series', 'remarks');
    }

    /**
     * Save free-text remarks for a record series and return its rdp_remarks_logic id.
     * A blank/empty string clears the remark (the logic row is deleted, and the
     * series FK is detached by the FK's nullOnDelete).
     */
    public static function saveForSeries(int $seriesId, ?string $text): ?int
    {
        if (! self::enabled()) {
            return null;
        }

        $text      = trim((string) $text);
        $currentId = DB::table('rdp_record_series')->where('id', $seriesId)->value('remarks');

        if ($text === '') {
            if ($currentId) {
                DB::table('rdp_remarks_logic')->where('id', $currentId)->delete();
            }

            DB::table('rdp_record_series')->where('id', $seriesId)->update(['remarks' => null]);

            return null;
        }

        if ($currentId) {
            DB::table('rdp_remarks_logic')->where('id', $currentId)->update([
                'remarks_value' => $text,
            ]);

            return (int) $currentId;
        }

        $id = DB::table('rdp_remarks_logic')->insertGetId([
            'remarks_value' => $text,
            'created_at'    => now(),
        ]);

        DB::table('rdp_record_series')->where('id', $seriesId)->update(['remarks' => $id]);

        return $id;
    }

    /**
     * Save remarks together with their trigger and alternate retention periods.
     */
    public static function saveConfigurationForSeries(
        int $seriesId,
        ?string $text,
        ?int $keyWordId,
        ?string $activePeriod,
        ?string $storagePeriod,
        ?string $totalPeriod
    ): ?int {
        if (! self::enabled()) {
            return null;
        }

        $text = trim((string) $text);
        if ($text === '') {
            return self::saveForSeries($seriesId, null);
        }

        if ($keyWordId !== null && ! DB::table('rdp_remark_key_triggers')->where('id', $keyWordId)->exists()) {
            throw new \InvalidArgumentException('The selected remark key trigger is not available.');
        }

        $currentId = DB::table('rdp_record_series')->where('id', $seriesId)->value('remarks');
        $data = [
            'key_word_id'          => $keyWordId,
            'remarks_value'        => $text,
            'active_period_backup' => trim((string) $activePeriod) ?: null,
            'storage_period_backup'=> trim((string) $storagePeriod) ?: null,
            'total_period_backup'  => trim((string) $totalPeriod) ?: null,
        ];

        if ($currentId) {
            DB::table('rdp_remarks_logic')->where('id', $currentId)->update($data);

            return (int) $currentId;
        }

        $data['created_at'] = now();
        $id = DB::table('rdp_remarks_logic')->insertGetId($data);
        DB::table('rdp_record_series')->where('id', $seriesId)->update(['remarks' => $id]);

        return $id;
    }

    /**
     * Available remark key triggers for the record-series editor.
     */
    public static function keyTriggers(): array
    {
        if (! Schema::hasTable('rdp_remark_key_triggers')) {
            return [];
        }

        return DB::table('rdp_remark_key_triggers')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Read the remarks text for a single record series id.
     */
    public static function textForSeries(?int $seriesId): ?string
    {
        if ($seriesId === null || ! self::enabled()) {
            return null;
        }

        return DB::table('rdp_remarks_logic')
            ->join('rdp_record_series', 'rdp_record_series.remarks', '=', 'rdp_remarks_logic.id')
            ->where('rdp_record_series.id', $seriesId)
            ->value('rdp_remarks_logic.remarks_value');
    }

    /**
     * Read the remarks text for one series' remarks id (e.g. a row already loaded
     * from rdp_record_series where "remarks" now holds the id).
     */
    public static function textFor(mixed $remarksId): ?string
    {
        if (empty($remarksId) || ! self::enabled()) {
            return null;
        }

        return DB::table('rdp_remarks_logic')
            ->where('id', $remarksId)
            ->value('remarks_value');
    }
}

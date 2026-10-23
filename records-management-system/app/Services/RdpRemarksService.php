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

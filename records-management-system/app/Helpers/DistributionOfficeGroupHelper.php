<?php

namespace App\Helpers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class DistributionOfficeGroupHelper
{
    public static function tablesReady(): bool
    {
        return Schema::hasTable('dcs_distribution_office_groups')
            && Schema::hasTable('dcs_distribution_office_group_items');
    }

    /** @return list<array{id:int,name:string,offices:list<array{office_id:int,office_name:string,copies:int}>}> */
    public static function listForCatalog(): array
    {
        if (! self::tablesReady()) {
            return [];
        }

        $groups = DB::table('dcs_distribution_office_groups')
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($groups->isEmpty()) {
            return [];
        }

        $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $items = DB::table('dcs_distribution_office_group_items as i')
            ->leftJoin($officeTbl.' as o', 'o.id', '=', 'i.office_id')
            ->whereIn('i.group_id', $groups->pluck('id'))
            ->orderBy('i.sort_order')
            ->orderBy('i.id')
            ->get([
                'i.group_id',
                'i.office_id',
                'i.copies',
                'o.office_name',
                'o.is_active',
            ]);

        $byGroup = [];
        foreach ($items as $item) {
            // Skip inactive / missing offices so apply stays valid.
            if (empty($item->is_active) || empty($item->office_name)) {
                continue;
            }
            $byGroup[(int) $item->group_id][] = [
                'office_id' => (int) $item->office_id,
                'office_name' => (string) $item->office_name,
                'copies' => max(1, (int) $item->copies),
            ];
        }

        return $groups->map(function ($g) use ($byGroup) {
            return [
                'id' => (int) $g->id,
                'name' => (string) $g->name,
                'offices' => $byGroup[(int) $g->id] ?? [],
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    public static function store(Request $request): array
    {
        if (! self::tablesReady()) {
            return [
                'ok' => false,
                'message' => 'Office groups are not available yet. Run migrations first.',
            ];
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:120',
            'offices' => 'required|array|min:1',
            'offices.*.office_id' => 'required|integer|min:1',
            'offices.*.copies' => 'nullable|integer|min:1|max:9999',
        ]);

        if ($validator->fails()) {
            return [
                'ok' => false,
                'message' => $validator->errors()->first() ?: 'Invalid office group.',
            ];
        }

        $name = trim((string) $request->input('name'));
        $offices = collect($request->input('offices', []))
            ->map(fn ($row) => [
                'office_id' => (int) ($row['office_id'] ?? 0),
                'copies' => max(1, (int) ($row['copies'] ?? 1)),
            ])
            ->filter(fn ($row) => $row['office_id'] > 0)
            ->unique('office_id')
            ->values();

        if ($offices->isEmpty()) {
            return ['ok' => false, 'message' => 'Add at least one office before saving a group.'];
        }

        $existingId = (int) (DB::table('dcs_distribution_office_groups')->where('name', $name)->value('id') ?? 0);
        $overwrite = (bool) $request->boolean('overwrite');

        if ($existingId > 0 && ! $overwrite) {
            return [
                'ok' => false,
                'exists' => true,
                'message' => 'A group with that name already exists. Overwrite it?',
            ];
        }

        DB::transaction(function () use ($name, $offices, $existingId) {
            if ($existingId > 0) {
                DB::table('dcs_distribution_office_group_items')->where('group_id', $existingId)->delete();
                DB::table('dcs_distribution_office_groups')->where('id', $existingId)->update([
                    'updated_at' => now(),
                ]);
                $groupId = $existingId;
            } else {
                $groupId = DB::table('dcs_distribution_office_groups')->insertGetId([
                    'name' => $name,
                    'created_by' => Auth::id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($offices as $i => $row) {
                DB::table('dcs_distribution_office_group_items')->insert([
                    'group_id' => $groupId,
                    'office_id' => $row['office_id'],
                    'copies' => $row['copies'],
                    'sort_order' => $i,
                ]);
            }
        });

        return [
            'ok' => true,
            'message' => $existingId > 0 ? 'Office group updated.' : 'Office group saved.',
            'groups' => self::listForCatalog(),
        ];
    }

    /** @return array<string, mixed> */
    public static function destroy(int $id): array
    {
        if (! self::tablesReady()) {
            return ['ok' => false, 'message' => 'Office groups are not available yet.'];
        }

        $deleted = DB::table('dcs_distribution_office_groups')->where('id', $id)->delete();
        if (! $deleted) {
            return ['ok' => false, 'message' => 'Group not found.'];
        }

        return [
            'ok' => true,
            'message' => 'Office group deleted.',
            'groups' => self::listForCatalog(),
        ];
    }
}

<?php

namespace App\Helpers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CalendarHelper
{
    private const CUSTOM_COLORS = ['#0369a1', '#7c3aed', '#be123c', '#0f766e', '#c2410c', '#4338ca'];

    public static function categories(): JsonResponse
    {
        return response()->json(self::categoryRows());
    }

    public static function events(): JsonResponse
    {
        $rows = DB::table('dcs_calendar_events as e')
            ->leftJoin('dcs_calendar_categories as c', 'c.id', '=', 'e.category_id')
            ->orderBy('e.event_date')
            ->orderBy('e.start_time')
            ->get([
                'e.id',
                'e.category_id',
                'e.title',
                'e.event_date',
                'e.start_time',
                'e.end_time',
                'e.description',
                'c.name as category_name',
                'c.color',
            ])
            ->map(fn ($row) => self::formatEvent($row))
            ->values()
            ->all();

        // Document deadline/effectivity overlays are paused until the client
        // confirms how they should appear on the calendar.

        return response()->json($rows);
    }

    public static function storeCategory(Request $request): JsonResponse
    {
        RegisterQueryHelper::assertFullDcsUser();
        $data = $request->validate([
            'name' => 'required|string|max:80',
        ]);

        $name = trim($data['name']);
        $exists = DB::table('dcs_calendar_categories')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => 'That category already exists.']);
        }

        $used = DB::table('dcs_calendar_categories')->pluck('color')->all();
        $color = collect(self::CUSTOM_COLORS)->first(fn ($c) => !in_array($c, $used, true)) ?: self::CUSTOM_COLORS[0];

        $id = DB::table('dcs_calendar_categories')->insertGetId([
            'name' => $name,
            'color' => $color,
            'is_system' => false,
            'created_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        RegisterPersistHelper::logAdminChange('Added calendar category — ' . $name);

        return response()->json(self::categoryRows()->firstWhere('id', $id));
    }

    public static function storeEvent(Request $request): JsonResponse
    {
        RegisterQueryHelper::assertFullDcsUser();
        $data = self::validatedEvent($request);

        $id = DB::table('dcs_calendar_events')->insertGetId([
            'category_id' => $data['category_id'],
            'title' => $data['title'],
            'event_date' => $data['date'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'description' => $data['description'],
            'created_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        RegisterPersistHelper::logAdminChange(
            'Added calendar event — ' . $data['title'] . ' (' . $data['date'] . ')'
        );

        return response()->json(self::eventById($id), 201);
    }

    public static function updateEvent(Request $request, int $id): JsonResponse
    {
        RegisterQueryHelper::assertFullDcsUser();
        $existing = DB::table('dcs_calendar_events')->where('id', $id)->first();
        if (!$existing) {
            abort(404);
        }

        $data = self::validatedEvent($request);

        DB::table('dcs_calendar_events')->where('id', $id)->update([
            'category_id' => $data['category_id'],
            'title' => $data['title'],
            'event_date' => $data['date'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'description' => $data['description'],
            'updated_at' => now(),
        ]);

        RegisterPersistHelper::logAdminChange(
            'Updated calendar event #' . $id . ' — ' . $data['title']
        );

        return response()->json(self::eventById($id));
    }

    public static function destroyEvent(int $id): JsonResponse
    {
        RegisterQueryHelper::assertFullDcsUser();
        $event = DB::table('dcs_calendar_events')->where('id', $id)->first();
        $deleted = DB::table('dcs_calendar_events')->where('id', $id)->delete();
        if (!$deleted) {
            abort(404);
        }

        if ($event) {
            RegisterPersistHelper::logAdminChange(
                'Deleted calendar event #' . $id . ' — ' . ($event->title ?? 'Untitled')
            );
        }

        return response()->json(['ok' => true]);
    }

    public static function destroyCategory(int $id): JsonResponse
    {
        RegisterQueryHelper::assertFullDcsUser();
        $cat = DB::table('dcs_calendar_categories')->where('id', $id)->first();
        if (!$cat) {
            abort(404);
        }

        if (DB::table('dcs_calendar_events')->where('category_id', $id)->exists()) {
            return response()->json(['message' => 'This category still has events. Move or delete those events first.'], 422);
        }

        DB::table('dcs_calendar_categories')->where('id', $id)->delete();

        RegisterPersistHelper::logAdminChange(
            'Deleted calendar category #' . $id . ' — ' . ($cat->name ?? 'Unknown')
        );

        return response()->json(['ok' => true]);
    }

    private static function validatedEvent(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:160',
            'category_id' => 'required|integer|exists:dcs_calendar_categories,id',
            'date' => 'required|date',
            'start_time' => 'required|string',
            'end_time' => 'required|string',
            'description' => 'nullable|string|max:2000',
        ]);

        $data['title'] = trim($data['title']);
        $data['description'] = isset($data['description']) ? trim($data['description']) : null;
        $data['start_time'] = substr($data['start_time'], 0, 5);
        $data['end_time'] = substr($data['end_time'], 0, 5);

        if ($data['end_time'] < $data['start_time']) {
            throw ValidationException::withMessages(['end_time' => 'End time cannot be earlier than start time.']);
        }

        return $data;
    }

    private static function categoryRows()
    {
        return DB::table('dcs_calendar_categories')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get(['id', 'name', 'color', 'is_system'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'color' => $row->color,
                'is_system' => (bool) $row->is_system,
            ]);
    }

    private static function eventById(int $id): array
    {
        $row = DB::table('dcs_calendar_events as e')
            ->leftJoin('dcs_calendar_categories as c', 'c.id', '=', 'e.category_id')
            ->where('e.id', $id)
            ->first([
                'e.id',
                'e.category_id',
                'e.title',
                'e.event_date',
                'e.start_time',
                'e.end_time',
                'e.description',
                'c.name as category_name',
                'c.color',
            ]);

        return $row ? self::formatEvent($row) : [];
    }

    private static function formatEvent(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'category_id' => (int) $row->category_id,
            'title' => $row->title,
            'date' => $row->event_date instanceof \DateTimeInterface
                ? $row->event_date->format('Y-m-d')
                : substr((string) $row->event_date, 0, 10),
            'startTime' => substr((string) $row->start_time, 0, 5),
            'endTime' => substr((string) $row->end_time, 0, 5),
            'description' => $row->description,
            'category_name' => $row->category_name,
            'color' => $row->color ?: '#0d2a7a',
            'readonly' => false,
        ];
    }
}

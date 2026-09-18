<?php

namespace App\Helpers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CalendarHelper
{
    private const CUSTOM_COLORS = ['#0369a1', '#7c3aed', '#be123c', '#0f766e', '#c2410c', '#4338ca'];

    /** Recognizable colors for the standard attendance/event categories. */
    private const NAMED_CATEGORY_COLORS = [
        'suspension' => '#dc2626',
        'leave' => '#16a34a',
        'wfj' => '#2563eb',
        // Keep the existing WFH category aligned with the requested WFJ color.
        'wfh' => '#2563eb',
    ];

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
        RegisterQueryHelper::assertFullDcsUser('settings');
        $rateCheck = \App\Services\RateLimiterService::check('dcs_action');
        if (!$rateCheck['allowed']) {
            return response()->json(['message' => $rateCheck['message']], 429);
        }
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $name = trim($data['name']);
        $exists = DB::table('dcs_calendar_categories')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => 'That category already exists.']);
        }

        $selectedColor = trim((string) ($data['color'] ?? ''));
        $used = DB::table('dcs_calendar_categories')->pluck('color')->all();
        $color = $selectedColor !== ''
            ? mb_strtolower($selectedColor)
            : self::colorForCategory($name, $used);

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

    public static function updateCategory(Request $request, int $id): JsonResponse
    {
        RegisterQueryHelper::assertFullDcsUser('settings');
        $rateCheck = \App\Services\RateLimiterService::check('dcs_action');
        if (!$rateCheck['allowed']) {
            return response()->json(['message' => $rateCheck['message']], 429);
        }
        $cat = DB::table('dcs_calendar_categories')->where('id', $id)->first();
        if (!$cat) {
            abort(404);
        }

        $data = $request->validate([
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'name' => 'nullable|string|max:80',
        ]);

        $payload = [
            'color' => mb_strtolower(trim($data['color'])),
            'updated_at' => now(),
        ];

        if (array_key_exists('name', $data) && trim((string) $data['name']) !== '') {
            $name = trim($data['name']);
            $exists = DB::table('dcs_calendar_categories')
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->where('id', '!=', $id)
                ->exists();
            if ($exists) {
                throw ValidationException::withMessages(['name' => 'That category already exists.']);
            }
            $payload['name'] = $name;
        }

        DB::table('dcs_calendar_categories')->where('id', $id)->update($payload);

        RegisterPersistHelper::logAdminChange(
            'Updated calendar category #' . $id . ' — ' . ($payload['name'] ?? $cat->name)
        );

        return response()->json(self::categoryRows()->firstWhere('id', $id));
    }

    public static function storeEvent(Request $request): JsonResponse
    {
        RegisterQueryHelper::assertFullDcsUser('settings');
        $rateCheck = \App\Services\RateLimiterService::check('dcs_action');
        if (!$rateCheck['allowed']) {
            return response()->json(['message' => $rateCheck['message']], 429);
        }
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
        RegisterQueryHelper::assertFullDcsUser('settings');
        $rateCheck = \App\Services\RateLimiterService::check('dcs_action');
        if (!$rateCheck['allowed']) {
            return response()->json(['message' => $rateCheck['message']], 429);
        }
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
        RegisterQueryHelper::assertFullDcsUser('settings');
        $rateCheck = \App\Services\RateLimiterService::check('dcs_action');
        if (!$rateCheck['allowed']) {
            return response()->json(['message' => $rateCheck['message']], 429);
        }
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
        RegisterQueryHelper::assertFullDcsUser('settings');
        $rateCheck = \App\Services\RateLimiterService::check('dcs_action');
        if (!$rateCheck['allowed']) {
            return response()->json(['message' => $rateCheck['message']], 429);
        }
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

    /**
     * Standard categories retain their meaning wherever they are created;
     * custom categories receive the next available palette color.
     */
    private static function colorForCategory(string $name, array $used): string
    {
        $key = mb_strtolower(trim($name));
        if (isset(self::NAMED_CATEGORY_COLORS[$key])) {
            return self::NAMED_CATEGORY_COLORS[$key];
        }

        return collect(self::CUSTOM_COLORS)
            ->first(fn ($color) => !in_array($color, $used, true))
            ?: self::CUSTOM_COLORS[0];
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

    /**
     * Philippine regular holidays (fixed + movable) keyed by Y-m-d.
     *
     * @return array<string, string>
     */
    public static function philippineHolidays(int ...$years): array
    {
        if ($years === []) {
            $y = (int) now('Asia/Manila')->year;
            $years = [$y - 1, $y, $y + 1];
        }

        $holidays = [];
        foreach (array_unique($years) as $y) {
            $y = (int) $y;
            $holidays["{$y}-01-01"] = "New Year's Day";
            $holidays["{$y}-04-09"] = 'The Day of Valor';
            $holidays["{$y}-05-01"] = 'Labor Day';
            $holidays["{$y}-06-12"] = 'Independence Day';
            $holidays["{$y}-08-21"] = 'Ninoy Aquino Day';
            $heroes = \Carbon\Carbon::create($y, 8, 31, 0, 0, 0, 'Asia/Manila');
            $heroes->subDays(($heroes->dayOfWeek - \Carbon\Carbon::MONDAY + 7) % 7);
            $holidays[$heroes->toDateString()] = 'National Heroes Day';
            $holidays["{$y}-11-01"] = "All Saints' Day";
            $holidays["{$y}-11-30"] = 'Bonifacio Day';
            $holidays["{$y}-12-25"] = 'Christmas Day';
            $holidays["{$y}-12-30"] = 'Rizal Day';
            if (function_exists('easter_date')) {
                $easter = \Carbon\Carbon::createFromTimestamp(easter_date($y), 'UTC')->timezone('Asia/Manila');
                $holidays[$easter->copy()->subDays(3)->toDateString()] = 'Maundy Thursday';
                $holidays[$easter->copy()->subDays(2)->toDateString()] = 'Good Friday';
            }
        }

        return $holidays;
    }

    /** @return list<string> Y-m-d dates with a Suspension calendar event */
    public static function suspensionEventDates(): array
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('dcs_calendar_events')) {
            return [];
        }

        return DB::table('dcs_calendar_events as e')
            ->join('dcs_calendar_categories as c', 'c.id', '=', 'e.category_id')
            ->whereRaw('LOWER(TRIM(c.name)) = ?', ['suspension'])
            ->pluck('e.event_date')
            ->map(function ($d) {
                if ($d instanceof \DateTimeInterface) {
                    return $d->format('Y-m-d');
                }

                return substr((string) $d, 0, 10);
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Holidays + suspension event dates (weekends are handled separately in the calculator).
     *
     * @return list<string>
     */
    public static function nonWorkingDatesForTimeSpent(?int $fromYear = null, ?int $toYear = null): array
    {
        $now = (int) now('Asia/Manila')->year;
        $fromYear ??= $now - 5;
        $toYear ??= $now + 2;
        if ($fromYear > $toYear) {
            [$fromYear, $toYear] = [$toYear, $fromYear];
        }

        $holidayDates = array_keys(self::philippineHolidays(...range($fromYear, $toYear)));

        return array_values(array_unique(array_merge($holidayDates, self::suspensionEventDates())));
    }

    /**
     * Elapsed minutes between two datetimes excluding Sat/Sun, Philippine holidays,
     * and calendar Suspension events. Returns null when end is before start.
     */
    public static function workingMinutesBetween(\Carbon\CarbonInterface $start, \Carbon\CarbonInterface $end): ?int
    {
        if ($end->lt($start)) {
            return null;
        }

        $excluded = array_fill_keys(
            self::nonWorkingDatesForTimeSpent((int) $start->year, (int) $end->year),
            true
        );

        $total = 0;
        $cursor = $start->copy()->startOfDay();
        $lastDay = $end->copy()->startOfDay();

        while ($cursor->lte($lastDay)) {
            $iso = $cursor->toDateString();
            $isWeekend = $cursor->isSaturday() || $cursor->isSunday();

            if (!$isWeekend && !isset($excluded[$iso])) {
                if ($cursor->isSameDay($start) && $cursor->isSameDay($end)) {
                    $total += (int) $start->diffInMinutes($end);
                } elseif ($cursor->isSameDay($start)) {
                    $total += (int) $start->diffInMinutes($cursor->copy()->addDay()->startOfDay());
                } elseif ($cursor->isSameDay($end)) {
                    $total += (int) $cursor->copy()->startOfDay()->diffInMinutes($end);
                } else {
                    $total += 1440;
                }
            }

            $cursor->addDay();
        }

        return max(0, $total);
    }
}

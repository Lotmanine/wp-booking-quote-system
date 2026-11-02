<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class StaffMatcher
{
    /**
     * Staff who cover the area and skill and are free at the slot, lightest load first.
     * Load counts assignments inside a window around the slot, so a quiet week stays quiet.
     *
     * @return Collection<int, Staff>
     */
    public function candidates(string $area, string $skill, CarbonImmutable $slot): Collection
    {
        $days = (int) config('dispatch.load_window_days');

        return Staff::query()
            ->where('active', true)
            ->withCount(['assignments as load' => function ($query) use ($slot, $days): void {
                $query->whereBetween('slot_start', [$slot->subDays($days), $slot->addDays($days)]);
            }])
            ->get()
            ->filter(fn (Staff $staff) => in_array($area, $staff->areas, true) && in_array($skill, $staff->skills, true))
            ->reject(fn (Staff $staff) => Assignment::where('staff_id', $staff->id)->where('slot_start', $slot)->exists())
            ->sortBy([['load', 'asc'], ['id', 'asc']])
            ->values();
    }
}

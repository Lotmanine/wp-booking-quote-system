<?php

namespace App\Jobs;

use App\Models\Assignment;
use App\Models\DispatchRequest;
use App\Services\StaffMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;

class AssignStaff implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $requestId) {}

    public function handle(StaffMatcher $matcher): void
    {
        $request = DispatchRequest::findOrFail($this->requestId);
        if ($request->status !== 'queued') {
            return;
        }

        // A newer version replaces whatever an older version of this booking reserved.
        Assignment::where('booking_id', $request->booking_id)->where('version', '<', $request->version)->delete();

        $slot = CarbonImmutable::parse($request->slot_start);
        foreach ($matcher->candidates($request->area, $request->skill, $slot) as $staff) {
            try {
                Assignment::create([
                    'booking_id' => $request->booking_id,
                    'version' => $request->version,
                    'staff_id' => $staff->id,
                    'slot_start' => $slot,
                ]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent worker took this person for this slot. Try the next one.
                continue;
            }

            $this->finish($request, 'assigned', $staff->ref, "Matched {$request->area} and {$request->skill}, load {$staff->load}.");

            return;
        }

        $this->finish($request, 'unassigned', null, "No staff free for {$request->area} and {$request->skill}.");
    }

    private function finish(DispatchRequest $request, string $outcome, ?string $staffRef, string $reason): void
    {
        $request->update([
            'status' => 'decided',
            'outcome' => $outcome,
            'staff_ref' => $staffRef,
            'reason' => $reason,
        ]);

        SendDecision::dispatch($request->id);
    }
}

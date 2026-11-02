<?php

namespace App\Http\Controllers;

use App\Jobs\AssignStaff;
use App\Models\Assignment;
use App\Models\DispatchRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DispatchController extends Controller
{
    /**
     * Accept a request and decide on the queue. A repeat of the same booking version is a no-op,
     * so n8n retries cannot assign twice.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'booking_id' => ['required', 'integer', 'min:1'],
            'version' => ['required', 'integer', 'min:1'],
            'area' => ['required', 'string', 'max:40'],
            'skill' => ['required', 'string', 'max:40'],
            'slot_start' => ['required', 'date_format:Y-m-d H:i:s'],
        ]);

        $dispatch = DispatchRequest::firstOrCreate(
            ['booking_id' => $data['booking_id'], 'version' => $data['version']],
            $data + ['status' => 'queued'],
        );

        if ($dispatch->wasRecentlyCreated) {
            AssignStaff::dispatch($dispatch->id);
        }

        return response()->json(['id' => $dispatch->id, 'status' => $dispatch->status], 202);
    }

    /**
     * Clear all dispatch data for the nightly demo reset. Refused unless the deployment opts in.
     */
    public function reset(): JsonResponse
    {
        if (! config('dispatch.allow_reset')) {
            return response()->json(['message' => 'Reset is off.'], 403);
        }

        Assignment::query()->delete();
        DispatchRequest::query()->delete();

        return response()->json(['reset' => true]);
    }

    /**
     * Free the staff member when a booking is cancelled.
     */
    public function release(int $bookingId): JsonResponse
    {
        $released = Assignment::where('booking_id', $bookingId)->delete();
        DispatchRequest::where('booking_id', $bookingId)->whereIn('status', ['queued'])->update(['status' => 'cancelled']);

        return response()->json(['released' => $released]);
    }
}

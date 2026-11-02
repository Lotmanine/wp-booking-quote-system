<?php

namespace App\Jobs;

use App\Models\DispatchRequest;
use App\Services\WordPressSigner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SendDecision implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(public int $requestId) {}

    public function handle(WordPressSigner $signer): void
    {
        $request = DispatchRequest::findOrFail($this->requestId);

        $signed = $signer->sign((string) json_encode([
            'booking_id' => $request->booking_id,
            'version' => $request->version,
            'outcome' => $request->outcome,
            'staff_ref' => $request->staff_ref,
            'reason' => $request->reason,
        ]));

        $response = Http::withHeaders($signed['headers'])
            ->timeout(10)
            ->post(config('dispatch.wp_url').'/wp-json/qhs/v1/dispatch/decision', $signed['body']);

        if (! $response->successful()) {
            throw new RuntimeException('WordPress answered HTTP '.$response->status());
        }

        $request->update(['status' => 'delivered']);
    }

    public function failed(?Throwable $exception): void
    {
        DispatchRequest::whereKey($this->requestId)->update(['status' => 'failed']);
        Log::error('Dispatch decision was not delivered', ['request' => $this->requestId]);
    }
}

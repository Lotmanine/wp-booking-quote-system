<?php

namespace Tests\Feature;

use App\Jobs\AssignStaff;
use App\Jobs\SendDecision;
use App\Models\Assignment;
use App\Models\DispatchRequest;
use App\Models\Staff;
use App\Services\WordPressSigner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DispatchApiTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'unit-test-key';

    private const SECRET = 'unit-test-signing-value';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'dispatch.api_key' => self::KEY,
            'dispatch.hmac_secret' => self::SECRET,
            'dispatch.wp_url' => 'http://wp.test',
        ]);
        $this->seed(DatabaseSeeder::class);
        Http::fake(['wp.test/*' => Http::response(['result' => 'assigned'])]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return $overrides + [
            'booking_id' => 1,
            'version' => 4,
            'area' => 'north',
            'skill' => 'cleaning',
            'slot_start' => '2026-11-10 09:00:00',
        ];
    }

    private function send(array $body = [], string $key = self::KEY)
    {
        return $this->withHeader('X-Api-Key', $key)->postJson('/api/v1/dispatch-requests', $this->body($body));
    }

    #[DataProvider('badKeys')]
    public function test_rejects_a_missing_or_wrong_key(array $headers): void
    {
        $this->withHeaders($headers)->postJson('/api/v1/dispatch-requests', $this->body())->assertStatus(401);
        $this->assertSame(0, DispatchRequest::count());
    }

    public static function badKeys(): array
    {
        return [
            'missing' => [[]],
            'wrong' => [['X-Api-Key' => 'nope']],
            'empty' => [['X-Api-Key' => '']],
        ];
    }

    public function test_fails_closed_when_no_key_is_configured(): void
    {
        config(['dispatch.api_key' => '']);

        $this->withHeader('X-Api-Key', '')->postJson('/api/v1/dispatch-requests', $this->body())->assertStatus(401);
    }

    public function test_validates_the_request(): void
    {
        $this->send(['slot_start' => 'tomorrow'])->assertStatus(422);
    }

    public function test_queues_the_decision(): void
    {
        Queue::fake();

        $this->send()->assertStatus(202);

        Queue::assertPushed(AssignStaff::class);
    }

    public function test_assigns_by_area_and_skill(): void
    {
        $this->send()->assertStatus(202);

        $request = DispatchRequest::first();
        $this->assertSame('assigned', $request->outcome);
        $this->assertSame('rowan', $request->staff_ref);
        $this->assertSame('delivered', $request->status);
    }

    public function test_skill_filters_out_staff_in_the_area(): void
    {
        $this->send(['skill' => 'garden'])->assertStatus(202);

        $this->assertSame('jordan', DispatchRequest::first()->staff_ref);
    }

    public function test_prefers_the_lightest_load(): void
    {
        $sam = Staff::where('ref', 'sam')->first();
        Assignment::create(['booking_id' => 90, 'version' => 1, 'staff_id' => Staff::where('ref', 'rowan')->first()->id, 'slot_start' => '2026-11-11 09:00:00']);
        Assignment::create(['booking_id' => 91, 'version' => 1, 'staff_id' => Staff::where('ref', 'rowan')->first()->id, 'slot_start' => '2026-11-12 09:00:00']);

        $this->send(['area' => 'central'])->assertStatus(202);

        $this->assertSame($sam->ref, DispatchRequest::first()->staff_ref);
    }

    public function test_never_double_books_a_person_in_a_slot(): void
    {
        $this->send(['booking_id' => 1])->assertStatus(202);
        $this->send(['booking_id' => 2])->assertStatus(202);

        $second = DispatchRequest::where('booking_id', 2)->first();
        $this->assertSame('unassigned', $second->outcome);
        $this->assertSame(1, Assignment::count());
    }

    public function test_reports_when_nobody_fits(): void
    {
        Staff::query()->update(['active' => false]);

        $this->send()->assertStatus(202);

        $this->assertSame('unassigned', DispatchRequest::first()->outcome);
        Http::assertSent(fn (Request $request) => str_contains((string) json_decode($request->body(), true)['payload'], '"outcome":"unassigned"'));
    }

    public function test_repeat_of_a_version_is_idempotent(): void
    {
        $this->send()->assertStatus(202);
        $this->send()->assertStatus(202);

        $this->assertSame(1, DispatchRequest::count());
        $this->assertSame(1, Assignment::count());
        Http::assertSentCount(1);
    }

    public function test_new_version_replaces_the_old_assignment(): void
    {
        $this->send()->assertStatus(202);
        $this->send(['version' => 6, 'slot_start' => '2026-11-11 13:00:00'])->assertStatus(202);

        $this->assertSame(1, Assignment::count());
        $this->assertSame(6, Assignment::first()->version);
    }

    public function test_release_frees_the_person(): void
    {
        $this->send()->assertStatus(202);

        $this->withHeader('X-Api-Key', self::KEY)->deleteJson('/api/v1/assignments/1')->assertOk()->assertJson(['released' => 1]);

        $this->assertSame(0, Assignment::count());
    }

    public function test_release_needs_the_key(): void
    {
        $this->deleteJson('/api/v1/assignments/1')->assertStatus(401);
    }

    public function test_reset_clears_everything_on_a_demo_deployment(): void
    {
        config(['dispatch.allow_reset' => true]);
        $this->send()->assertStatus(202);

        $this->withHeader('X-Api-Key', self::KEY)->deleteJson('/api/v1/assignments')->assertOk();

        $this->assertSame(0, Assignment::count());
        $this->assertSame(0, DispatchRequest::count());
    }

    public function test_reset_is_refused_unless_the_deployment_opts_in(): void
    {
        config(['dispatch.allow_reset' => false]);
        $this->send()->assertStatus(202);

        $this->withHeader('X-Api-Key', self::KEY)->deleteJson('/api/v1/assignments')->assertStatus(403);

        $this->assertSame(1, Assignment::count());
    }

    public function test_reset_needs_the_key(): void
    {
        config(['dispatch.allow_reset' => true]);

        $this->deleteJson('/api/v1/assignments')->assertStatus(401);
    }

    public function test_decision_is_signed_over_timestamp_nonce_and_payload(): void
    {
        $this->send()->assertStatus(202);

        Http::assertSent(function (Request $request) {
            $payload = (string) json_decode($request->body(), true)['payload'];
            $expected = 'v1='.hash_hmac(
                'sha256',
                $request->header('X-QHS-Timestamp')[0].'.'.$request->header('X-QHS-Nonce')[0].'.'.$payload,
                self::SECRET,
            );

            return $request->url() === 'http://wp.test/wp-json/qhs/v1/dispatch/decision'
                && $request->header('X-QHS-Key-Id')[0] === 'dispatch'
                && hash_equals($expected, $request->header('X-QHS-Signature')[0]);
        });
    }

    public function test_each_decision_uses_a_fresh_nonce(): void
    {
        $nonces = [];
        Http::fake(function (Request $request) use (&$nonces) {
            $nonces[] = $request->header('X-QHS-Nonce')[0];

            return Http::response(['result' => 'assigned']);
        });

        $this->send(['booking_id' => 1])->assertStatus(202);
        $this->send(['booking_id' => 2, 'area' => 'riverside'])->assertStatus(202);

        $this->assertCount(2, array_unique($nonces));
    }

    public function test_failed_delivery_throws_for_a_retry_and_is_then_marked(): void
    {
        Queue::fake();
        $this->send()->assertStatus(202);
        $request = DispatchRequest::first();
        $request->update(['status' => 'decided', 'outcome' => 'assigned', 'staff_ref' => 'rowan']);
        Http::fake(['wp.test/*' => Http::response('down', 500)]);
        $job = new SendDecision($request->id);

        try {
            $job->handle(new WordPressSigner);
            $this->fail('Expected the job to throw so the queue retries it.');
        } catch (RuntimeException) {
            $job->failed(null);
        }

        $this->assertSame('failed', $request->fresh()->status);
    }
}

<?php

namespace Tests\Unit\Services;

use App\Contracts\Repositories\NotificationDeliveryRepositoryInterface;
use App\Enums\NotificationDeliveryClaim;
use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\GuestNotifiable;
use App\Services\NotificationDeliveryGuard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * 알림 수신자별 중복 발송 방지 테스트 (#139)
 *
 * 선점(리스) → 확정/해제 → 오래된 선점 넘겨받기 → 저장소 실패 시 fail-open → 정리 를 검증한다.
 */
class NotificationDeliveryGuardTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 처음 선점하면 Acquired 이고 sending 행이 생긴다.
     *
     * @scenario rerun=none
     *
     * @effects delivery_claim_states
     *
     * @return void 반환값 없음
     */
    public function test_first_claim_is_acquired(): void
    {
        $guard = $this->guard();

        $this->assertSame(NotificationDeliveryClaim::Acquired, $guard->claim('ev-1', 'type_a', 'mail', 'user:1'));
        $this->assertSame(NotificationDeliveryStatus::Sending, NotificationDelivery::query()->where('delivery_key', $guard->deliveryKey('ev-1', 'type_a', 'mail', 'user:1'))->first()?->status);
    }

    /**
     * 보냄으로 확정된 수신자는 Done 이다.
     *
     * @scenario rerun=duplicate_child
     *
     * @effects delivery_claim_states
     *
     * @return void 반환값 없음
     */
    public function test_claim_after_sent_returns_done(): void
    {
        $guard = $this->guard();
        $guard->claim('ev-2', 'type_a', 'mail', 'user:2');
        $guard->markSent('ev-2', 'type_a', 'mail', 'user:2');

        $this->assertSame(NotificationDeliveryClaim::Done, $guard->claim('ev-2', 'type_a', 'mail', 'user:2'));

        $guard->claim('ev-2s', 'type_a', 'mail', 'user:2');
        $guard->markSkipped('ev-2s', 'type_a', 'mail', 'user:2');
        $this->assertSame(NotificationDeliveryClaim::Done, $guard->claim('ev-2s', 'type_a', 'mail', 'user:2'));
    }

    /**
     * 리스 안의 sending 은 InFlight 다(다른 사본이 보내는 중).
     *
     * @scenario rerun=rereserved_copy
     *
     * @effects delivery_claim_states
     *
     * @return void 반환값 없음
     */
    public function test_fresh_sending_claim_returns_in_flight(): void
    {
        $guard = $this->guard();
        $guard->claim('ev-3', 'type_a', 'mail', 'user:3');

        $this->assertSame(NotificationDeliveryClaim::InFlight, $guard->claim('ev-3', 'type_a', 'mail', 'user:3'));
        $this->assertSame([$guard->deliveryKey('ev-3', 'type_a', 'mail', 'user:3')], $guard->stillInFlight([$guard->deliveryKey('ev-3', 'type_a', 'mail', 'user:3')]));
    }

    /**
     * 리스가 지난 sending 은 넘겨받는다(선점 시각 갱신·시도 +1).
     *
     * @scenario rerun=rereserved_copy
     *
     * @effects delivery_claim_states
     *
     * @return void 반환값 없음
     */
    public function test_stale_sending_claim_is_taken_over(): void
    {
        config(['notification.delivery.claim_lease_seconds' => 60]);
        $guard = $this->guard();
        $this->travelTo('2026-10-13 10:00:00');
        $guard->claim('ev-4', 'type_a', 'mail', 'user:4');

        $this->travelTo('2026-10-13 10:01:01');
        $this->assertSame([], $guard->stillInFlight([$guard->deliveryKey('ev-4', 'type_a', 'mail', 'user:4')]));
        $this->assertSame(NotificationDeliveryClaim::Acquired, $guard->claim('ev-4', 'type_a', 'mail', 'user:4'));

        $row = NotificationDelivery::query()->where('delivery_key', $guard->deliveryKey('ev-4', 'type_a', 'mail', 'user:4'))->first();
        $this->assertSame(2, $row->attempts);
        $this->assertSame('2026-10-13 10:01:01', $row->claimed_at->format('Y-m-d H:i:s'));
        // 넘겨받은 직후 다른 사본은 다시 InFlight
        $this->assertSame(NotificationDeliveryClaim::InFlight, $guard->claim('ev-4', 'type_a', 'mail', 'user:4'));
    }

    /**
     * 발송 실패로 해제하면 재시도가 다시 선점해 보낸다.
     *
     * @scenario rerun=channel_failure_retry
     *
     * @effects failed_channel_releases_claim
     *
     * @return void 반환값 없음
     */
    public function test_release_allows_reclaim_after_failure(): void
    {
        $guard = $this->guard();
        $guard->claim('ev-5', 'type_a', 'mail', 'user:5');
        $guard->release('ev-5', 'type_a', 'mail', 'user:5');

        $this->assertSame(NotificationDeliveryClaim::Acquired, $guard->claim('ev-5', 'type_a', 'mail', 'user:5'));

        // 확정된 행은 해제로 지우지 않는다
        $guard->markSent('ev-5', 'type_a', 'mail', 'user:5');
        $guard->release('ev-5', 'type_a', 'mail', 'user:5');
        $this->assertSame(NotificationDeliveryClaim::Done, $guard->claim('ev-5', 'type_a', 'mail', 'user:5'));
    }

    /**
     * 중복 방지 키는 같은 입력이면 같고, 이벤트·유형·채널·수신자 중 하나라도 다르면 다르다.
     *
     * @scenario rerun=none
     *
     * @effects delivery_claim_states
     *
     * @return void 반환값 없음
     */
    public function test_delivery_key_is_stable_and_distinguishes_event_type_channel_recipient(): void
    {
        $guard = $this->guard();
        $base = $guard->deliveryKey('ev', 'type', 'mail', 'user:1');

        $this->assertSame(64, strlen($base));
        $this->assertSame($base, $guard->deliveryKey('ev', 'type', 'mail', 'user:1'));
        foreach ([['ev2', 'type', 'mail', 'user:1'], ['ev', 'type2', 'mail', 'user:1'], ['ev', 'type', 'sms', 'user:1'], ['ev', 'type', 'mail', 'user:2']] as $args) {
            $this->assertNotSame($base, $guard->deliveryKey(...$args));
        }
    }

    /**
     * 수신자 키 — 회원 user:{id}, 비회원 guest:{hash}, 그 밖의 모델 {morph}:{id}, 식별 불가 객체 null.
     *
     * @scenario rerun=none
     *
     * @effects delivery_claim_states
     *
     * @return void 반환값 없음
     */
    public function test_recipient_key_for_user_guest_model_and_unknown_object(): void
    {
        $guard = $this->guard();
        $user = new User;
        $user->id = 77;
        $guest = new GuestNotifiable('Guest@Example.test', 'Guest');
        $other = new NotificationDelivery;
        $other->id = 9;

        $this->assertSame('user:77', $guard->recipientKey($user));
        $this->assertSame('guest:'.sha1('guest@example.test'), $guard->recipientKey($guest));
        $this->assertSame($other->getMorphClass().':9', $guard->recipientKey($other));
        $this->assertNull($guard->recipientKey(new \stdClass));
        $this->assertNull($guard->recipientKey(new User));
    }

    /**
     * 저장소가 실패하면 선점한 것으로 보고 보낸다(누락 방지 우선) — 확정·해제도 예외를 올리지 않는다.
     *
     * @scenario rerun=none
     *
     * @effects delivery_guard_fails_open
     *
     * @return void 반환값 없음
     */
    public function test_guard_fails_open_when_repository_throws(): void
    {
        $repo = Mockery::mock(NotificationDeliveryRepositoryInterface::class);
        $repo->shouldReceive('insertClaim', 'complete', 'deleteClaim', 'findInFlightKeys')->andThrow(new RuntimeException('no table'));
        $guard = new NotificationDeliveryGuard($repo);

        $this->assertSame(NotificationDeliveryClaim::Acquired, $guard->claim('ev', 'type', 'mail', 'user:1'));
        $guard->markSent('ev', 'type', 'mail', 'user:1');
        $guard->release('ev', 'type', 'mail', 'user:1');
        $this->assertSame([], $guard->stillInFlight(['k']));
    }

    /**
     * 보관 일수보다 오래된 행만 지운다.
     *
     * @scenario rerun=none
     *
     * @effects stale_deliveries_pruned
     *
     * @return void 반환값 없음
     */
    public function test_prune_deletes_rows_older_than_days(): void
    {
        $guard = $this->guard();
        $this->travelTo('2026-10-01 00:00:00');
        $guard->claim('old', 'type', 'mail', 'user:1');
        $this->travelTo('2026-10-12 00:00:00');
        $guard->claim('new', 'type', 'mail', 'user:1');

        $this->assertSame(1, $guard->prune(7));
        $this->assertSame(0, NotificationDelivery::query()->where('event_id', 'old')->count());
        $this->assertSame(1, NotificationDelivery::query()->where('event_id', 'new')->count());
    }

    /**
     * 가드를 만듭니다.
     *
     * @return NotificationDeliveryGuard 가드
     */
    private function guard(): NotificationDeliveryGuard
    {
        return app(NotificationDeliveryGuard::class);
    }
}

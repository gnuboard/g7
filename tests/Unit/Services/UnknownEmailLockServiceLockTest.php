<?php

namespace Tests\Unit\Services;

use App\Services\UnknownEmailLockService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 가입되지 않은 주소의 실패 기록이 잠금 안에서 읽고 쓰이는지 검증한다 (KVE 동형 L5).
 *
 * 검출력 한계: 단일 프로세스 PHPUnit 은 「읽고 쓰는 사이」 에 다른 요청을 끼워 넣을 수 없다. 이 테스트는 기록 갱신이
 * Cache::lock 으로 감싸였는지(직렬화 장치의 존재)와 잠금 기준 동작이 유지되는지만 단언한다. 실제 동시 요청은 브라우저
 * 실측이 덮는다.
 */
class UnknownEmailLockServiceLockTest extends TestCase
{
    /**
     * 테스트 공통 준비를 합니다 — 잠금 기준을 켭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'g7_settings.core.security.login_attempt_enabled' => true,
            'g7_settings.core.security.max_login_attempts' => 3,
            'g7_settings.core.security.login_lockout_time' => 5,
        ]);
    }

    /**
     * 실패 기록은 주소별 캐시 잠금 안에서 갱신한다 — 다른 요청이 같은 주소의 잠금을 쥐고 있으면 기다린다.
     *
     * 잠금을 먼저 잡아 둔 채 기록하면 잠금 대기(최대 3초) 뒤 잠금 없이 센다. 대기가 일어났다는 것이 기록이 잠금을
     * 거친다는 측정값이다(수정 전에는 대기 없이 즉시 끝난다).
     *
     * @effects unknown_email_failure_serialized
     *
     * @return void 반환값 없음
     */
    public function test_record_failure_waits_for_address_lock(): void
    {
        $email = 'nobody@example.test';
        $held = Cache::lock('auth.unknown_login.lock.'.hash('sha256', $email), 10);
        $this->assertTrue($held->get());

        try {
            $service = app(UnknownEmailLockService::class);
            $started = microtime(true);
            $service->recordFailure($email);
            $elapsed = microtime(true) - $started;
        } finally {
            $held->release();
        }

        $this->assertGreaterThanOrEqual(2.5, $elapsed, '실패 기록이 주소별 잠금을 거치지 않았습니다.');
        // 잠금 시간 초과여도 실패는 센다 — 로그인 응답을 막지 않는다
        $this->assertSame(1, $service->failureCount($email));
    }

    /**
     * 기준 횟수에 이르면 잠금 흉내가 걸리고 유지된다 (기존 규칙).
     *
     * @effects unknown_email_lock_threshold_kept
     *
     * @return void 반환값 없음
     */
    public function test_threshold_lock_is_kept(): void
    {
        $service = app(UnknownEmailLockService::class);

        for ($i = 0; $i < 3; $i++) {
            $service->recordFailure('nobody2@example.test');
        }

        $this->assertNotNull($service->lockState('nobody2@example.test'));
        $service->recordFailure('nobody2@example.test');
        $this->assertSame(3, $service->failureCount('nobody2@example.test'));
    }
}

<?php

namespace Tests\Unit\Services;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\HookManager;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Extension\IdentityVerification\Providers\MailIdentityProvider;
use App\Models\IdentityVerificationLog;
use App\Services\IdentityVerificationService;
use Mockery;
use Tests\TestCase;

/**
 * IdentityVerificationService 의 라이프사이클 hook pair 회귀 차단.
 *
 * cancel()/consumeToken() 메서드가 start/verify 와 달리 before/after hook 을
 * 발행하지 않아 외부 plugin 이 cancel 시점/token 소비 시점에 자기 record 정리 listener 를
 * 등록할 수 없었던 결함을 차단한다.
 *
 * 모든 Service 라이프사이클 메서드는 일관된 before_X / after_X hook pair 를 발행해야 한다.
 */
class IdentityVerificationServiceHookPairsTest extends TestCase
{
    /**
     * Mockery 기대를 검증하고 닫은 뒤 부모 정리를 실행한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * 취소가 성공하면 before_cancel, after_cancel 훅이 차례로 한 번씩 나가고 after 의 결과 인자가 true 다.
     *
     * @scenario surface=service_lifecycle_hooks
     *
     * @effects cancel_fires_before_and_after_hooks_on_success, cancel_hook_pair_dispatched
     *
     * @return void 반환값 없음
     */
    public function test_cancel_dispatches_before_and_after_hooks_on_success(): void
    {
        $fired = [];

        HookManager::addAction('core.identity.before_cancel', function (...$args) use (&$fired) {
            $fired[] = ['name' => 'before_cancel', 'args' => $args];
        });
        HookManager::addAction('core.identity.after_cancel', function (...$args) use (&$fired) {
            $fired[] = ['name' => 'after_cancel', 'args' => $args];
        });

        $log = new IdentityVerificationLog([
            'id' => 'challenge-uuid',
            'provider_id' => 'g7:core.mail',
        ]);
        $log->setRawAttributes(array_merge($log->getAttributes(), ['id' => 'challenge-uuid']), true);

        $logRepo = Mockery::mock(IdentityVerificationLogRepositoryInterface::class);
        $logRepo->shouldReceive('findById')->with('challenge-uuid')->andReturn($log);

        $userRepo = Mockery::mock(UserRepositoryInterface::class);

        $provider = Mockery::mock(MailIdentityProvider::class);
        $provider->shouldReceive('cancel')->with('challenge-uuid')->andReturn(true);

        $manager = Mockery::mock(IdentityVerificationManager::class);
        $manager->shouldReceive('get')->with('g7:core.mail')->andReturn($provider);

        $service = new IdentityVerificationService($manager, $logRepo, $userRepo);

        $result = $service->cancel('challenge-uuid');

        $this->assertTrue($result);
        $this->assertCount(2, $fired);
        $this->assertSame('before_cancel', $fired[0]['name']);
        $this->assertSame('challenge-uuid', $fired[0]['args'][0]);
        $this->assertSame('after_cancel', $fired[1]['name']);
        $this->assertSame('challenge-uuid', $fired[1]['args'][0]);
        $this->assertTrue($fired[1]['args'][2]);
    }

    /**
     * 취소할 기록이 없어도 두 훅이 나가며 before 의 기록 인자는 null, after 의 결과 인자는 false 다.
     *
     * @scenario surface=service_lifecycle_hooks
     *
     * @effects cancel_fires_hook_pair_with_false_when_log_missing, cancel_hook_pair_dispatched
     *
     * @return void 반환값 없음
     */
    public function test_cancel_dispatches_after_hook_with_false_when_log_missing(): void
    {
        $fired = [];

        HookManager::addAction('core.identity.before_cancel', function (...$args) use (&$fired) {
            $fired[] = ['name' => 'before_cancel', 'args' => $args];
        });
        HookManager::addAction('core.identity.after_cancel', function (...$args) use (&$fired) {
            $fired[] = ['name' => 'after_cancel', 'args' => $args];
        });

        $logRepo = Mockery::mock(IdentityVerificationLogRepositoryInterface::class);
        $logRepo->shouldReceive('findById')->andReturn(null);

        $userRepo = Mockery::mock(UserRepositoryInterface::class);
        $manager = Mockery::mock(IdentityVerificationManager::class);

        $service = new IdentityVerificationService($manager, $logRepo, $userRepo);

        $result = $service->cancel('missing-uuid');

        $this->assertFalse($result);
        $this->assertCount(2, $fired);
        $this->assertSame('before_cancel', $fired[0]['name']);
        $this->assertNull($fired[0]['args'][1], 'before_cancel 의 두 번째 인자는 log 가 없을 때 null');
        $this->assertSame('after_cancel', $fired[1]['name']);
        $this->assertFalse($fired[1]['args'][2]);
    }

    /**
     * 토큰 소비가 성공하면 before_consume_token, after_consume_token 훅이 차례로 나가고 after 의 결과 인자가 true 다.
     *
     * @scenario surface=service_lifecycle_hooks
     *
     * @effects consume_token_fires_before_and_after_hooks_on_success, consume_hook_pair_dispatched
     *
     * @return void 반환값 없음
     */
    public function test_consume_token_dispatches_before_and_after_hooks_on_success(): void
    {
        $fired = [];

        HookManager::addAction('core.identity.before_consume_token', function (...$args) use (&$fired) {
            $fired[] = ['name' => 'before_consume_token', 'args' => $args];
        });
        HookManager::addAction('core.identity.after_consume_token', function (...$args) use (&$fired) {
            $fired[] = ['name' => 'after_consume_token', 'args' => $args];
        });

        $log = new IdentityVerificationLog(['id' => 'log-uuid']);

        $logRepo = Mockery::mock(IdentityVerificationLogRepositoryInterface::class);
        $logRepo->shouldReceive('findVerifiedForToken')
            ->with('token-abc', 'signup')
            ->andReturn($log);
        // 소비는 「아직 안 쓴 행만」 바꾸는 조건부 갱신이다 — 동시 소비 두 번째는 false (KVE 동형 O4)
        $logRepo->shouldReceive('consumeIfUnconsumed')
            ->with('log-uuid', Mockery::type(\DateTimeInterface::class))
            ->andReturn(true);

        $userRepo = Mockery::mock(UserRepositoryInterface::class);
        $manager = Mockery::mock(IdentityVerificationManager::class);

        $service = new IdentityVerificationService($manager, $logRepo, $userRepo);

        $result = $service->consumeToken('token-abc');

        $this->assertTrue($result);
        $this->assertCount(2, $fired);
        $this->assertSame('before_consume_token', $fired[0]['name']);
        $this->assertSame('token-abc', $fired[0]['args'][0]);
        $this->assertSame('after_consume_token', $fired[1]['name']);
        $this->assertTrue($fired[1]['args'][2]);
    }

    /**
     * 토큰에 맞는 기록이 없어도 두 훅이 나가며 before 의 기록 인자는 null, after 의 결과 인자는 false 다.
     *
     * @scenario surface=service_lifecycle_hooks
     *
     * @effects consume_token_fires_hook_pair_with_false_when_log_missing, consume_hook_pair_dispatched
     *
     * @return void 반환값 없음
     */
    public function test_consume_token_dispatches_after_hook_with_false_when_log_missing(): void
    {
        $fired = [];

        HookManager::addAction('core.identity.before_consume_token', function (...$args) use (&$fired) {
            $fired[] = ['name' => 'before_consume_token', 'args' => $args];
        });
        HookManager::addAction('core.identity.after_consume_token', function (...$args) use (&$fired) {
            $fired[] = ['name' => 'after_consume_token', 'args' => $args];
        });

        $logRepo = Mockery::mock(IdentityVerificationLogRepositoryInterface::class);
        $logRepo->shouldReceive('findVerifiedForToken')->andReturn(null);

        $userRepo = Mockery::mock(UserRepositoryInterface::class);
        $manager = Mockery::mock(IdentityVerificationManager::class);

        $service = new IdentityVerificationService($manager, $logRepo, $userRepo);

        $result = $service->consumeToken('missing-token');

        $this->assertFalse($result);
        $this->assertCount(2, $fired);
        $this->assertNull($fired[0]['args'][1]);
        $this->assertFalse($fired[1]['args'][2]);
    }
}

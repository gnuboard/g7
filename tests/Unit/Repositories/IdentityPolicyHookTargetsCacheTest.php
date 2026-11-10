<?php

namespace Tests\Unit\Repositories;

use App\Contracts\Extension\CacheInterface;
use App\Extension\Cache\CoreCacheDriver;
use App\Models\IdentityPolicy;
use App\Repositories\IdentityPolicyRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 본인인증 hook target 목록 캐시 (#860 W6)
 *
 * 동적 hook 구독 동기화가 매 요청 `identity_policies` 를 DISTINCT 조회하던 것을 캐시로 바꾼다. 정책이
 * 바뀌면(모델 저장·삭제, 이벤트 없는 대량 삭제) 같은 무효화 계열이 함께 지워 다음 요청이 바로 본다.
 */
class IdentityPolicyHookTargetsCacheTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 설치 완료 상태와 격리된 캐시를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.installer_completed' => true]);
        $this->app->instance(CacheInterface::class, new CoreCacheDriver('array'));
        IdentityPolicy::flushRouteScopeCache();
    }

    /**
     * 설치 완료 플래그를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        config(['app.installer_completed' => false]);

        parent::tearDown();
    }

    /**
     * hook scope 정책 1건을 만듭니다.
     *
     * @param  string  $target  hook 이름
     * @return IdentityPolicy 생성된 정책
     */
    private function makePolicy(string $target): IdentityPolicy
    {
        return IdentityPolicy::query()->create([
            'key' => 'w6.test.'.md5($target.microtime()),
            'scope' => 'hook',
            'target' => $target,
            'purpose' => 'sensitive_action',
            'enabled' => false,
            'priority' => 0,
            'source_type' => 'module',
            'source_identifier' => 'w6-test',
            'applies_to' => 'both',
            'fail_mode' => 'block',
        ]);
    }

    /**
     * DISTINCT 조회 건수를 세며 콜백을 실행합니다.
     *
     * @param  \Closure  $callback  실행할 작업
     * @return int DISTINCT target 조회 건수
     */
    private function countDistinctQueries(\Closure $callback): int
    {
        $count = 0;
        DB::listen(static function ($query) use (&$count): void {
            if (str_contains($query->sql, 'distinct') && str_contains($query->sql, 'identity_policies')) {
                $count++;
            }
        });

        $callback();

        return $count;
    }

    /**
     * 같은 목록을 두 번 읽어도 DB 는 한 번만 조회합니다.
     *
     * @effects identity_hook_targets_are_cached_across_requests
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 두_번_읽어도_d_b_는_한_번만_조회한다(): void
    {
        $this->makePolicy('w6.target.alpha');
        IdentityPolicy::flushRouteScopeCache();
        $repository = $this->app->make(IdentityPolicyRepository::class);

        $queries = $this->countDistinctQueries(function () use ($repository): void {
            $this->assertContains('w6.target.alpha', $repository->listHookTargets());
            $this->assertContains('w6.target.alpha', $repository->listHookTargets());
        });

        $this->assertSame(1, $queries);
    }

    /**
     * 모델 저장·삭제와 이벤트 없는 대량 삭제 뒤에는 새 목록을 읽습니다.
     *
     * @effects identity_hook_target_cache_is_flushed_on_policy_write
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 정책을_쓰면_다음_조회가_새_목록을_본다(): void
    {
        $repository = $this->app->make(IdentityPolicyRepository::class);
        $policy = $this->makePolicy('w6.target.alpha');
        $this->assertContains('w6.target.alpha', $repository->listHookTargets());

        // 모델 저장(saved 이벤트)
        $policy->update(['target' => 'w6.target.beta']);
        $targets = $repository->listHookTargets();
        $this->assertContains('w6.target.beta', $targets);
        $this->assertNotContains('w6.target.alpha', $targets);

        // 모델 삭제(deleted 이벤트)
        $policy->delete();
        $this->assertNotContains('w6.target.beta', $repository->listHookTargets());

        // 이벤트 없는 대량 삭제(cleanupStale)
        $this->makePolicy('w6.target.gamma');
        $this->assertContains('w6.target.gamma', $repository->listHookTargets());
        $repository->cleanupStale('module', 'w6-test', []);
        $this->assertNotContains('w6.target.gamma', $repository->listHookTargets());
    }

    /**
     * 조회가 실패하면 빈 목록을 돌려주되 캐시하지 않습니다 — 다음 조회가 다시 시도합니다.
     *
     * @effects identity_hook_target_lookup_failure_is_not_cached
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 조회_실패는_캐시하지_않는다(): void
    {
        $this->makePolicy('w6.target.alpha');
        IdentityPolicy::flushRouteScopeCache();

        $repository = new class($this->app->make(CacheInterface::class)) extends IdentityPolicyRepository
        {
            /**
             * 첫 조회만 실패시킬지 여부
             */
            public bool $failNext = true;

            /**
             * 첫 호출에서 예외를 던지고 이후에는 실제 조회를 합니다.
             *
             * @return array<int, string> hook target 목록
             */
            protected function queryHookTargets(): array
            {
                if ($this->failNext) {
                    $this->failNext = false;

                    throw new \RuntimeException('연결 끊김 흉내');
                }

                return parent::queryHookTargets();
            }
        };

        $this->assertSame([], $repository->listHookTargets());
        $this->assertContains('w6.target.alpha', $repository->listHookTargets(), '실패 결과가 캐시되면 정책이 계속 안 보인다.');
    }
}

<?php

namespace Tests\Unit\Repositories;

use App\Contracts\Repositories\IdentityPolicyRepositoryInterface;
use App\Models\IdentityPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IdentityPolicyRepository 의 priority 정렬 결정성 검증.
 *
 * 회귀 배경: resolveByScopeTarget / getRouteScopeIndex 가 orderByDesc('priority') 만 사용해
 * 동률 priority 정책의 반환 순서가 비결정적이었다. 같은 scope+target 에 동률 정책이 둘 이상이면
 * 어느 정책이 먼저 enforce 되는지(어떤 purpose 로 challenge 되는지)가 캐시/실행계획에 좌우됐다.
 * id 2차 정렬키를 추가해 동률이어도 항상 동일한 순서(먼저 생성된 정책 우선)가 되는지 검증한다.
 *
 * @group identity
 * @group unit
 */
class IdentityPolicyRepositoryPriorityOrderTest extends TestCase
{
    use RefreshDatabase;

    private IdentityPolicyRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(IdentityPolicyRepositoryInterface::class);
    }

    /**
     * 정책을 생성합니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 속성
     */
    private function makePolicy(array $overrides = []): IdentityPolicy
    {
        return IdentityPolicy::create(array_merge([
            'key' => 'test.order.'.uniqid(),
            'scope' => 'route',
            'target' => 'api.test.order',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => true,
            'priority' => 100,
            'source_type' => 'admin',
            'source_identifier' => 'admin',
            'applies_to' => 'both',
            'fail_mode' => 'block',
        ], $overrides));
    }

    /**
     * 우선순위가 다르면 높은 priority 가 먼저 온다 (기존 동작 보존).
     */
    public function test_higher_priority_comes_first(): void
    {
        $low = $this->makePolicy(['key' => 'test.order.low', 'priority' => 50]);
        $high = $this->makePolicy(['key' => 'test.order.high', 'priority' => 150]);

        $ordered = $this->repository->resolveByScopeTarget('route', 'api.test.order');

        $this->assertSame($high->id, $ordered->first()->id);
        $this->assertSame($low->id, $ordered->last()->id);
    }

    /**
     * 동률 priority 면 id 오름차순(먼저 생성된 정책 우선)으로 결정적 정렬된다.
     */
    public function test_tied_priority_is_ordered_deterministically_by_id(): void
    {
        $first = $this->makePolicy(['key' => 'test.order.tie_a', 'priority' => 100, 'purpose' => 'sensitive_action']);
        $second = $this->makePolicy(['key' => 'test.order.tie_b', 'priority' => 100, 'purpose' => 'adult_verification']);

        $ordered = $this->repository->resolveByScopeTarget('route', 'api.test.order');

        $this->assertCount(2, $ordered);
        // 먼저 생성된 정책(작은 id)이 항상 앞 — 여러 번 조회해도 동일
        $this->assertSame($first->id, $ordered->first()->id);
        $this->assertSame($second->id, $ordered->last()->id);

        // 결정성: 재조회해도 동일 순서
        $again = $this->repository->resolveByScopeTarget('route', 'api.test.order');
        $this->assertSame(
            $ordered->pluck('id')->all(),
            $again->pluck('id')->all(),
        );
    }

    /**
     * getRouteScopeIndex 도 동일한 결정적 정렬을 적용한다 (미들웨어 enforce 순서).
     */
    public function test_route_scope_index_is_ordered_deterministically(): void
    {
        $first = $this->makePolicy(['key' => 'test.order.idx_a', 'priority' => 100]);
        $second = $this->makePolicy(['key' => 'test.order.idx_b', 'priority' => 100]);

        $index = $this->repository->getRouteScopeIndex();
        $bucket = $index['api.test.order'] ?? collect();

        $this->assertCount(2, $bucket);
        $this->assertSame($first->id, $bucket->first()->id);
        $this->assertSame($second->id, $bucket->last()->id);
    }
}

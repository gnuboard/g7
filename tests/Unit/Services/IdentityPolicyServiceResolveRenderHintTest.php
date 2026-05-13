<?php

namespace Tests\Unit\Services;

use App\Contracts\Extension\IdentityVerificationInterface;
use App\Contracts\Repositories\IdentityPolicyRepositoryInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Models\IdentityPolicy;
use App\Services\IdentityPolicyService;
use Mockery;
use Tests\TestCase;

/**
 * IdentityPolicyService::resolveRenderHint 의 fallback 분기 정합성 회귀 차단.
 *
 * 호출자 명시 인자(정책의 provider_id) 가 4계층(Service → Manager) 통과 중 silent drop
 * 되던 결함을 cherry-pick 으로 가져온 4c723e154 가 Service::start 경로에서 수정했다.
 * 본 테스트는 동일 패턴이 resolveRenderHint 의 fallback 분기에서도 회귀하지 않도록
 * Manager::resolveForPurpose 호출 시 정책의 provider_id 가 두 번째 인자로 전달되는지를
 * spy 로 직접 검증한다.
 *
 * 실질 동작 결과는 수정 전후 동일 (Manager 의 0번 우선순위는 미등록 provider 를 매치하지
 * 않으므로 fallback chain 결과가 같음). 정합성 차원의 회귀 차단 목적.
 */
class IdentityPolicyServiceResolveRenderHintTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_fallback_branch_passes_policy_provider_id_to_manager(): void
    {
        $policyRepo = Mockery::mock(IdentityPolicyRepositoryInterface::class);
        $logRepo = Mockery::mock(IdentityVerificationLogRepositoryInterface::class);

        $resolvedProvider = Mockery::mock(IdentityVerificationInterface::class);
        $resolvedProvider->shouldReceive('getRenderHint')->andReturn('text_code');

        $manager = Mockery::mock(IdentityVerificationManager::class);
        // 첫 분기: manager->has(provider) === false → fallback 진입 유도
        $manager->shouldReceive('has')->with('plugin:disabled')->andReturn(false);
        // 핵심 검증: fallback 분기에서 두 번째 인자로 정책의 provider_id 가 전달되어야 함
        $manager->shouldReceive('resolveForPurpose')
            ->once()
            ->with('signup', 'plugin:disabled')
            ->andReturn($resolvedProvider);

        $service = new IdentityPolicyService($policyRepo, $logRepo, $manager);

        $policy = new IdentityPolicy([
            'purpose' => 'signup',
            'provider_id' => 'plugin:disabled',
        ]);

        $hint = $this->invokeResolveRenderHint($service, $policy);

        $this->assertSame('text_code', $hint);
    }

    public function test_fallback_branch_passes_null_when_policy_has_no_provider_id(): void
    {
        $policyRepo = Mockery::mock(IdentityPolicyRepositoryInterface::class);
        $logRepo = Mockery::mock(IdentityVerificationLogRepositoryInterface::class);

        $resolvedProvider = Mockery::mock(IdentityVerificationInterface::class);
        $resolvedProvider->shouldReceive('getRenderHint')->andReturn('text_code');

        $manager = Mockery::mock(IdentityVerificationManager::class);
        // provider_id 가 falsy 면 첫 if 조건 자체에서 fallback 진입 (manager->has 미호출)
        $manager->shouldReceive('resolveForPurpose')
            ->once()
            ->with('signup', null)
            ->andReturn($resolvedProvider);

        $service = new IdentityPolicyService($policyRepo, $logRepo, $manager);

        $policy = new IdentityPolicy([
            'purpose' => 'signup',
            'provider_id' => null,
        ]);

        $hint = $this->invokeResolveRenderHint($service, $policy);

        $this->assertSame('text_code', $hint);
    }

    public function test_first_branch_returns_provider_hint_without_fallback(): void
    {
        $policyRepo = Mockery::mock(IdentityPolicyRepositoryInterface::class);
        $logRepo = Mockery::mock(IdentityVerificationLogRepositoryInterface::class);

        $directProvider = Mockery::mock(IdentityVerificationInterface::class);
        $directProvider->shouldReceive('getRenderHint')->andReturn('redirect_oauth');

        $manager = Mockery::mock(IdentityVerificationManager::class);
        $manager->shouldReceive('has')->with('plugin:active')->andReturn(true);
        $manager->shouldReceive('get')->with('plugin:active')->andReturn($directProvider);
        // 첫 분기 통과 시 resolveForPurpose 는 호출되어선 안 됨 (회귀 차단)
        $manager->shouldNotReceive('resolveForPurpose');

        $service = new IdentityPolicyService($policyRepo, $logRepo, $manager);

        $policy = new IdentityPolicy([
            'purpose' => 'signup',
            'provider_id' => 'plugin:active',
        ]);

        $hint = $this->invokeResolveRenderHint($service, $policy);

        $this->assertSame('redirect_oauth', $hint);
    }

    /**
     * protected resolveRenderHint 를 reflection 으로 직접 호출합니다.
     */
    private function invokeResolveRenderHint(IdentityPolicyService $service, IdentityPolicy $policy): ?string
    {
        $reflection = new \ReflectionMethod(IdentityPolicyService::class, 'resolveRenderHint');
        $reflection->setAccessible(true);

        /** @var string|null $hint */
        $hint = $reflection->invoke($service, $policy);

        return $hint;
    }
}

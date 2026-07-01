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
 * IdentityPolicyService::resolveProviderId 의 fallback 분기 회귀 차단.
 *
 * 이슈 #275 — 정책 DB 의 provider_id 가 NULL 인 경우 enforce() 가 던지는 428 응답의
 * verification.provider_id 가 그대로 NULL 로 흘러가 launcher 가 외부 plugin Extension Point
 * 슬롯과 매칭 못해 빈 모달이 표시되던 결함을 차단한다.
 *
 * 본 테스트는 두 가지 분기를 검증한다:
 *   (1) 정책의 provider_id 가 등록된 provider id 면 그대로 반환
 *   (2) 정책의 provider_id 가 NULL 또는 미등록이면 Manager 의 purpose 기반 fallback 체인을 따름
 *
 * resolveProviderId 는 Service::enforce() 가 throw 할 때 IdentityVerificationRequiredException
 * 의 providerId 인자로 전달되는 값을 결정하므로, 본 회귀 차단이 통과해야 admin UI 의
 * default_provider 환경설정이 모달 launcher 까지 정상 전달된다.
 */
class IdentityPolicyServiceResolveProviderIdTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_policy_provider_id_when_registered(): void
    {
        $policyRepo = Mockery::mock(IdentityPolicyRepositoryInterface::class);
        $logRepo = Mockery::mock(IdentityVerificationLogRepositoryInterface::class);

        $manager = Mockery::mock(IdentityVerificationManager::class);
        // 등록된 provider 면 그대로 반환 — Manager fallback 진입 없음
        $manager->shouldReceive('has')->with('inicis')->andReturn(true);
        $manager->shouldNotReceive('resolveForPurpose');

        $service = new IdentityPolicyService($policyRepo, $logRepo, $manager);

        $policy = new IdentityPolicy([
            'purpose' => 'signup',
            'provider_id' => 'inicis',
        ]);

        $this->assertSame('inicis', $this->invokeResolveProviderId($service, $policy));
    }

    public function test_falls_back_to_manager_when_policy_provider_id_is_null(): void
    {
        $policyRepo = Mockery::mock(IdentityPolicyRepositoryInterface::class);
        $logRepo = Mockery::mock(IdentityVerificationLogRepositoryInterface::class);

        $resolvedProvider = Mockery::mock(IdentityVerificationInterface::class);
        $resolvedProvider->shouldReceive('getId')->andReturn('inicis');

        $manager = Mockery::mock(IdentityVerificationManager::class);
        // 정책 provider_id 가 NULL → 즉시 fallback 분기
        $manager->shouldReceive('resolveForPurpose')
            ->once()
            ->with('signup', null)
            ->andReturn($resolvedProvider);

        $service = new IdentityPolicyService($policyRepo, $logRepo, $manager);

        $policy = new IdentityPolicy([
            'purpose' => 'signup',
            'provider_id' => null,
        ]);

        $this->assertSame('inicis', $this->invokeResolveProviderId($service, $policy));
    }

    public function test_falls_back_to_manager_when_policy_provider_unregistered(): void
    {
        $policyRepo = Mockery::mock(IdentityPolicyRepositoryInterface::class);
        $logRepo = Mockery::mock(IdentityVerificationLogRepositoryInterface::class);

        $resolvedProvider = Mockery::mock(IdentityVerificationInterface::class);
        $resolvedProvider->shouldReceive('getId')->andReturn('g7:core.mail');

        $manager = Mockery::mock(IdentityVerificationManager::class);
        // 정책 provider 가 비활성/언인스톨된 경우 — has()=false → fallback 진입
        $manager->shouldReceive('has')->with('plugin:disabled')->andReturn(false);
        // 핵심 회귀 차단: 정책의 provider_id 를 Manager 의 0번 우선순위로 전달
        $manager->shouldReceive('resolveForPurpose')
            ->once()
            ->with('signup', 'plugin:disabled')
            ->andReturn($resolvedProvider);

        $service = new IdentityPolicyService($policyRepo, $logRepo, $manager);

        $policy = new IdentityPolicy([
            'purpose' => 'signup',
            'provider_id' => 'plugin:disabled',
        ]);

        $this->assertSame('g7:core.mail', $this->invokeResolveProviderId($service, $policy));
    }

    public function test_returns_original_provider_id_when_manager_throws(): void
    {
        $policyRepo = Mockery::mock(IdentityPolicyRepositoryInterface::class);
        $logRepo = Mockery::mock(IdentityVerificationLogRepositoryInterface::class);

        $manager = Mockery::mock(IdentityVerificationManager::class);
        $manager->shouldReceive('resolveForPurpose')
            ->andThrow(new \InvalidArgumentException('No provider supports purpose'));

        $service = new IdentityPolicyService($policyRepo, $logRepo, $manager);

        $policy = new IdentityPolicy([
            'purpose' => 'unknown',
            'provider_id' => 'legacy-id',
        ]);

        // Manager 예외 발생 시 정책의 원본 provider_id 를 그대로 반환 (정책 정보 보존)
        $this->assertSame('legacy-id', $this->invokeResolveProviderId($service, $policy));
    }

    private function invokeResolveProviderId(IdentityPolicyService $service, IdentityPolicy $policy): ?string
    {
        $reflection = new \ReflectionMethod($service, 'resolveProviderId');
        $reflection->setAccessible(true);

        return $reflection->invoke($service, $policy);
    }
}

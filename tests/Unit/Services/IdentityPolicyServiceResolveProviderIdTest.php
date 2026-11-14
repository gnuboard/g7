<?php

namespace Tests\Unit\Services;

use App\Contracts\Extension\IdentityVerificationInterface;
use App\Contracts\Repositories\IdentityPolicyRepositoryInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Models\IdentityPolicy;
use App\Services\IdentityPolicyService;
use Mockery;
use Tests\PureTestCase;

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
class IdentityPolicyServiceResolveProviderIdTest extends PureTestCase
{
    /**
     * 테스트마다 만든 Mockery 목 객체의 기대값을 검증하고 정리한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 정책의 provider_id 가 등록돼 있고 목적을 지원하며 쓸 수 있으면 그 값을 그대로 돌려준다 (Manager 폴백 없음).
     *
     * @scenario case=registered
     *
     * @effects registered_policy_provider_returned_as_is
     *
     * @return void 반환값 없음
     */
    public function test_returns_policy_provider_id_when_registered(): void
    {
        $policyRepo = Mockery::mock(IdentityPolicyRepositoryInterface::class);
        $logRepo = Mockery::mock(IdentityVerificationLogRepositoryInterface::class);

        $provider = Mockery::mock(IdentityVerificationInterface::class);
        $provider->shouldReceive('supportsPurpose')->with('signup')->andReturn(true);
        $provider->shouldReceive('isAvailable')->andReturn(true);

        $manager = Mockery::mock(IdentityVerificationManager::class);
        $manager->shouldReceive('has')->with('inicis')->andReturn(true);
        $manager->shouldReceive('get')->with('inicis')->andReturn($provider);
        $manager->shouldNotReceive('resolveForPurpose');

        $service = new IdentityPolicyService($policyRepo, $logRepo, $manager);

        $policy = new IdentityPolicy([
            'purpose' => 'signup',
            'provider_id' => 'inicis',
        ]);

        $this->assertSame('inicis', $this->invokeResolveProviderId($service, $policy));
    }

    /**
     * 정책의 provider_id 가 NULL(기본 인증 수단 사용)이면 Manager 의 목적 기반 해석 결과를 돌려준다.
     *
     * @scenario case=null_provider
     *
     * @effects null_policy_provider_falls_back_to_manager
     *
     * @return void 반환값 없음
     */
    public function test_falls_back_to_manager_when_policy_provider_id_is_null(): void
    {
        $policyRepo = Mockery::mock(IdentityPolicyRepositoryInterface::class);
        $logRepo = Mockery::mock(IdentityVerificationLogRepositoryInterface::class);

        $resolvedProvider = Mockery::mock(IdentityVerificationInterface::class);
        $resolvedProvider->shouldReceive('getId')->andReturn('inicis');

        $manager = Mockery::mock(IdentityVerificationManager::class);
        $manager->shouldReceive('resolveForPurpose')
            ->once()
            ->with('signup')
            ->andReturn($resolvedProvider);

        $service = new IdentityPolicyService($policyRepo, $logRepo, $manager);

        $policy = new IdentityPolicy([
            'purpose' => 'signup',
            'provider_id' => null,
        ]);

        $this->assertSame('inicis', $this->invokeResolveProviderId($service, $policy));
    }

    /**
     * 정책이 지정한 수단을 쓸 수 없으면(미등록·비활성) 메일로 폴백하지 않고 null 을 돌려준다 (7.1.6, KVE 동형 B9).
     *
     * @scenario case=unregistered_provider
     *
     * @effects unavailable_policy_provider_not_replaced
     *
     * @return void 반환값 없음
     */
    public function test_returns_null_when_policy_provider_unregistered(): void
    {
        $policyRepo = Mockery::mock(IdentityPolicyRepositoryInterface::class);
        $logRepo = Mockery::mock(IdentityVerificationLogRepositoryInterface::class);

        $manager = Mockery::mock(IdentityVerificationManager::class);
        $manager->shouldReceive('has')->with('plugin:disabled')->andReturn(false);
        $manager->shouldNotReceive('resolveForPurpose');

        $service = new IdentityPolicyService($policyRepo, $logRepo, $manager);

        $policy = new IdentityPolicy([
            'purpose' => 'signup',
            'provider_id' => 'plugin:disabled',
        ]);

        $this->assertNull($this->invokeResolveProviderId($service, $policy));
    }

    /**
     * 수단을 지정하지 않은 정책에서 Manager 해석이 예외를 던지면 null 을 돌려준다.
     *
     * @scenario case=manager_failure
     *
     * @effects manager_failure_returns_null
     *
     * @return void 반환값 없음
     */
    public function test_returns_null_when_manager_throws(): void
    {
        $policyRepo = Mockery::mock(IdentityPolicyRepositoryInterface::class);
        $logRepo = Mockery::mock(IdentityVerificationLogRepositoryInterface::class);

        $manager = Mockery::mock(IdentityVerificationManager::class);
        $manager->shouldReceive('resolveForPurpose')
            ->andThrow(new \InvalidArgumentException('No provider supports purpose'));

        $service = new IdentityPolicyService($policyRepo, $logRepo, $manager);

        $policy = new IdentityPolicy([
            'purpose' => 'unknown',
            'provider_id' => null,
        ]);

        $this->assertNull($this->invokeResolveProviderId($service, $policy));
    }

    /**
     * 비공개 메서드 resolveProviderId 를 리플렉션으로 호출해 해석된 provider id 를 얻는다.
     *
     * @param  IdentityPolicyService  $service  검사 대상 서비스 인스턴스
     * @param  IdentityPolicy  $policy  해석에 쓸 본인인증 정책 모델
     * @return string|null 해석된 provider id (없으면 null)
     */
    private function invokeResolveProviderId(IdentityPolicyService $service, IdentityPolicy $policy): ?string
    {
        $reflection = new \ReflectionMethod($service, 'resolveProviderId');
        $reflection->setAccessible(true);

        return $reflection->invoke($service, $policy);
    }
}

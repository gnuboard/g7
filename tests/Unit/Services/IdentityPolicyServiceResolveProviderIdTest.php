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
     * 정책의 provider_id 가 등록된 provider 이면 Manager fallback 없이 그 값을 그대로 돌려준다.
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

    /**
     * 정책의 provider_id 가 NULL 이면 Manager 의 목적 기반 해석으로 넘어가 그 결과 provider id 를 돌려준다.
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

    /**
     * 정책 provider 가 미등록이면 그 값을 0순위로 Manager 에 넘겨 fallback 해석한 provider id 를 돌려준다.
     *
     * @scenario case=unregistered_provider
     *
     * @effects unregistered_policy_provider_passed_to_manager_fallback
     *
     * @return void 반환값 없음
     */
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

    /**
     * Manager 해석이 예외를 던지면 정책에 저장된 원본 provider_id 를 그대로 돌려준다.
     *
     * @scenario case=manager_failure
     *
     * @effects manager_failure_keeps_original_provider_id
     *
     * @return void 반환값 없음
     */
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

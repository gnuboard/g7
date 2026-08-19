<?php

namespace Tests\Feature\Api\Identity;

use App\Extension\IdentityVerification\Providers\MailIdentityProvider;
use App\Models\IdentityPolicy;
use App\Services\IdentityPolicyService;
use Tests\TestCase;

/**
 * 정책 프리페치 엔드포인트의 provider_id 해석 (A6a)
 *
 * `GET /api/identity/policies/resolve` 는 저장된 `provider_id` 를 그대로 내보냈다.
 * 바로 옆의 428 강제 경로(`IdentityPolicyService::resolveProviderId()`)는 같은 값을
 * 레지스트리와 대조하고 미등록이면 purpose 기반 폴백으로 대체하는데, 이쪽만 raw 였다 —
 * 같은 데이터에 두 개의 게터가 서로 다른 답을 주는 안티패턴이고, 제거된 플러그인의
 * provider ID 가 공개 응답(optional.sanctum)에 그대로 실린다.
 */
class ResolvePolicyEndpointTest extends TestCase
{
    private string $endpoint = '/api/identity/policies/resolve';

    /**
     * 정책 행을 만듭니다.
     *
     * @param  string|null  $providerId  저장할 provider ID
     * @return IdentityPolicy 생성된 정책
     */
    private function makePolicy(?string $providerId): IdentityPolicy
    {
        return IdentityPolicy::updateOrCreate([
            'key' => 'test.resolve.policy',
        ], [
            'scope' => 'route',
            'target' => 'api.test.resolve',
            'purpose' => 'sensitive_action',
            'provider_id' => $providerId,
            'enabled' => true,
            'grace_minutes' => 10,
            'applies_to' => 'both',
            'fail_mode' => 'block',
            'source_type' => 'core',
        ]);
    }

    /**
     * 미등록 provider 는 해석된 값(폴백)으로 대체되어 나간다. (실패-먼저)
     */
    public function test_unregistered_provider_is_resolved_not_echoed(): void
    {
        $this->makePolicy('ghost_provider');

        $response = $this->getJson($this->endpoint.'?scope=route&target=api.test.resolve')
            ->assertOk();

        $this->assertNotSame(
            'ghost_provider',
            $response->json('data.provider_id'),
            '제거된 플러그인의 provider ID 가 공개 응답에 그대로 실렸습니다.'
        );

        // 428 강제 경로와 같은 게터를 경유해야 두 경로의 답이 갈리지 않는다
        $policy = IdentityPolicy::where('key', 'test.resolve.policy')->firstOrFail();
        $this->assertSame(
            app(IdentityPolicyService::class)->resolveProviderId($policy),
            $response->json('data.provider_id'),
            '프리페치 응답과 428 강제 경로의 provider 해석이 어긋납니다.'
        );
    }

    /**
     * 등록된 provider 는 그대로 유지된다. (비회귀 pin)
     */
    public function test_registered_provider_is_returned_as_is(): void
    {
        // 코어 메일 provider 는 항상 등록되어 있다 (ID 는 provider 자신이 선언한 값)
        $registeredId = app(MailIdentityProvider::class)->getId();
        $this->makePolicy($registeredId);

        $this->getJson($this->endpoint.'?scope=route&target=api.test.resolve')
            ->assertOk()
            ->assertJsonPath('data.provider_id', $registeredId);
    }

    /**
     * 매칭 정책이 없으면 null 을 반환한다. (기존 계약 pin)
     */
    public function test_no_matching_policy_returns_null(): void
    {
        $this->getJson($this->endpoint.'?scope=route&target=api.no.such.target')
            ->assertOk()
            ->assertJsonPath('data', null);
    }
}

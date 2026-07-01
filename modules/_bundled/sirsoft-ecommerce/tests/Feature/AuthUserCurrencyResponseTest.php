<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 인증 사용자 응답 결제 통화 노출 Feature 테스트 (A3, D-LOGIN-CUR / 회귀 D1)
 *
 * 배경: 템플릿 currentUser 는 GET /api/auth/user 응답으로 채워진다. 이 응답에
 * ecommerce_preferred_currency 가 없으면 initPreferredCurrency 가 계정 영속 통화를
 * 1순위로 읽지 못해 "로그인 시 계정 통화로 덮어씀"(D-LOGIN-CUR)이 깨진다.
 * UserCurrencyInfoListener 는 core.user.filter_resource_data 필터를 구독하므로,
 * UserResource::toArray() 가 그 필터를 적용해야 /api/auth/user 에도 통화가 실린다.
 */
class AuthUserCurrencyResponseTest extends ModuleTestCase
{
    public function test_auth_user_response_includes_persisted_currency(): void
    {
        $user = User::factory()->create();
        app(EcommerceUserProfileRepositoryInterface::class)->setPreferredCurrency($user->id, 'JPY');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/auth/user');

        $response->assertOk();
        // currentUser 를 채우는 /api/auth/user 응답에 영속 통화가 실려야 한다 (D1 회귀)
        $response->assertJsonPath('data.ecommerce_preferred_currency', 'JPY');
    }

    public function test_auth_user_response_has_currency_key_when_unset(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/auth/user');

        $response->assertOk();
        // 미설정이어도 키는 존재(null) — 프론트가 default_currency 로 폴백할 수 있도록
        $response->assertJsonPath('data.ecommerce_preferred_currency', null);
    }
}

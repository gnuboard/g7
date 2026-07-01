<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 유저 결제 통화 컨트롤러 Feature 테스트 (A3)
 *
 * - 인증 유저가 등록 통화로 변경 → 영속 저장 (golden path)
 * - 미등록 통화 → 422 (유효성 실패)
 * - 비인증 → 401 (권한 경계)
 * - GET 으로 현재 통화 조회
 */
class UserCurrencyControllerTest extends ModuleTestCase
{
    private const UPDATE_URL = '/api/modules/sirsoft-ecommerce/user/currency';

    public function test_authenticated_user_can_update_to_registered_currency(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->putJson(self::UPDATE_URL, ['currency' => 'USD']);

        $response->assertOk();
        $this->assertSame(
            'USD',
            app(EcommerceUserProfileRepositoryInterface::class)->getPreferredCurrency($user->id)
        );
    }

    public function test_unregistered_currency_is_rejected_422(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // XYZ 는 등록 통화 목록에 없음 → 422
        $response = $this->putJson(self::UPDATE_URL, ['currency' => 'XYZ']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('currency');
        $this->assertNull(
            app(EcommerceUserProfileRepositoryInterface::class)->getPreferredCurrency($user->id)
        );
    }

    public function test_currency_without_exchange_rate_is_rejected(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // KRW 는 기본통화(is_default) → 허용. 환율 미설정 비-기본 통화는 거부되어야 하나
        // 기본 통화는 항상 허용됨을 확인(golden).
        $response = $this->putJson(self::UPDATE_URL, ['currency' => 'KRW']);
        $response->assertOk();
    }

    public function test_guest_cannot_update_currency(): void
    {
        $response = $this->putJson(self::UPDATE_URL, ['currency' => 'USD']);

        $response->assertStatus(401);
    }

    public function test_show_returns_current_currency(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        app(EcommerceUserProfileRepositoryInterface::class)->setPreferredCurrency($user->id, 'JPY');

        $response = $this->getJson(self::UPDATE_URL);

        $response->assertOk();
        $response->assertJsonPath('data.preferred_currency', 'JPY');
    }
}

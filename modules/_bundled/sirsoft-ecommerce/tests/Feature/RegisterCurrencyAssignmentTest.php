<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 가입폼 결제 통화 부여 Feature 테스트 (A4, D-LOGIN-CUR / D-SIGNUP / 회귀 D2)
 *
 * - 제출 통화가 등록 통화면 그 값을 영속(제출값 우선)
 * - 제출 없으면 locale 기반 추정으로 폴백
 * - 무효 통화 강제 전송 → 422
 */
class RegisterCurrencyAssignmentTest extends ModuleTestCase
{
    /**
     * 가입 요청 본문을 만듭니다.
     *
     * @param  array  $overrides  덮어쓸 필드
     * @return array 가입 요청 본문
     */
    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test User',
            'email' => 'reg_'.uniqid().'@example.com',
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
            'agree_terms' => '1',
            'agree_privacy' => '1',
        ], $overrides);
    }

    /**
     * 제출한 통화가 등록 통화면 그 값이 가입 직후 저장돼 있다.
     *
     * @scenario listener=register_assignment, queue=sync
     *
     * @effects derived_data_visible_in_same_request
     *
     * @return void 반환값 없음
     */
    public function test_submitted_currency_is_persisted(): void
    {
        $response = $this->postJson('/api/auth/register', $this->registerPayload([
            'preferred_currency' => 'USD',
        ]));

        $response->assertSuccessful();

        $user = User::where('uuid', $response->json('data.user.uuid'))->firstOrFail();
        $this->assertSame(
            'USD',
            app(EcommerceUserProfileRepositoryInterface::class)->getPreferredCurrency($user->id)
        );
    }

    /**
     * 통화를 제출하지 않으면 로케일 추정(없으면 기본 통화)으로 가입 직후 부여된다.
     *
     * @scenario listener=register_assignment, queue=sync
     *
     * @effects derived_data_visible_in_same_request
     *
     * @return void 반환값 없음
     */
    public function test_falls_back_to_locale_when_currency_omitted(): void
    {
        // 제출 통화 없음 → locale(en, 테스트 env supported_locales) 기반 추정 경로 작동.
        // 매칭 없으면 is_default 통화로 폴백 — 어느 경우든 통화가 부여되어야 한다(null 아님).
        $response = $this->postJson('/api/auth/register', $this->registerPayload([
            'language' => 'en',
        ]));

        $response->assertSuccessful();

        $user = User::where('uuid', $response->json('data.user.uuid'))->firstOrFail();
        $assigned = app(EcommerceUserProfileRepositoryInterface::class)->getPreferredCurrency($user->id);
        $this->assertNotNull($assigned);
    }

    /**
     * 등록되지 않은 통화를 강제로 보내면 422 로 거부한다.
     *
     * @scenario listener=register_assignment, queue=sync
     *
     * @effects derived_data_visible_in_same_request
     *
     * @return void 반환값 없음
     */
    public function test_invalid_currency_is_rejected_422(): void
    {
        $response = $this->postJson('/api/auth/register', $this->registerPayload([
            'preferred_currency' => 'GBP',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('preferred_currency');
    }
}

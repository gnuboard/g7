<?php

namespace Tests\Feature\Api\Identity;

use App\Extension\IdentityVerification\IdentityVerificationManager;
use Tests\TestCase;

/**
 * GET /api/identity/purposes 응답 shape 회귀 테스트.
 *
 * 프론트 S1b "목적별 프로바이더" 카드는 `[{id, label, description, ...}]` 형태의
 * **객체 배열**을 가정한다. Manager 가 반환하는 연관 배열을 컨트롤러가 객체 배열로
 * 정규화하지 않으면 S1b 가 비어 보이는 회귀가 발생한다.
 */
class IdentityPurposesEndpointTest extends TestCase
{
    public function test_returns_object_array_with_resolved_label_and_description(): void
    {
        $response = $this->getJson('/api/identity/purposes');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'label', 'description', 'default_provider', 'allowed_channels'],
                ],
            ]);

        $data = $response->json('data');

        $this->assertIsList($data, 'data 는 객체 배열(list)이어야 한다 — 연관 배열 그대로 노출 금지');

        $ids = array_column($data, 'id');
        $this->assertContains('signup', $ids);
        $this->assertContains('password_reset', $ids);
        $this->assertContains('self_update', $ids);
        $this->assertContains('sensitive_action', $ids);

        $signup = collect($data)->firstWhere('id', 'signup');
        $this->assertIsString($signup['label']);
        $this->assertNotSame('', $signup['label'], 'label 은 i18n 으로 풀어진 비어 있지 않은 문자열이어야 한다');
        $this->assertStringNotContainsString('identity.purposes.', $signup['label'], 'label 은 i18n 키가 아닌 풀이 결과여야 한다');
        $this->assertNotSame(
            $signup['id'],
            $signup['label'],
            'label 이 id(raw 키, 예: "signup") 와 동일하면 i18n resolve 가 실패한 것 — '
            .'config/core.php 의 identity_purposes 가 `label_key` 같은 비표준 필드명을 쓰면 발생'
        );
        $this->assertIsString($signup['description']);
        $this->assertNotSame('', $signup['description'], 'description 도 i18n 풀이 결과여야 한다');
    }

    public function test_extension_declared_purpose_with_locale_array_label_is_resolved(): void
    {
        /** @var IdentityVerificationManager $manager */
        $manager = $this->app->make(IdentityVerificationManager::class);
        $manager->registerPurpose('checkout_verification', [
            'label' => ['ko' => '결제 인증', 'en' => 'Checkout Verification'],
            'description' => ['ko' => '결제 직전 본인 확인.', 'en' => 'Verify before checkout.'],
            'default_provider' => null,
            'allowed_channels' => ['email', 'sms'],
        ]);

        $response = $this->getJson('/api/identity/purposes');
        $response->assertOk();

        $checkout = collect($response->json('data'))->firstWhere('id', 'checkout_verification');
        $this->assertNotNull($checkout, '확장 등록 purpose 가 응답에 포함되어야 한다');
        $this->assertContains($checkout['label'], ['결제 인증', 'Checkout Verification'], '현재 로케일에 맞춰 풀어져야 한다');
        $this->assertContains($checkout['description'], ['결제 직전 본인 확인.', 'Verify before checkout.']);
    }

    public function test_string_label_passthrough_for_non_i18n_keys(): void
    {
        /** @var IdentityVerificationManager $manager */
        $manager = $this->app->make(IdentityVerificationManager::class);
        $manager->registerPurpose('plain_label_purpose', [
            'label' => 'PlainLabel',
            'allowed_channels' => ['email'],
        ]);

        $response = $this->getJson('/api/identity/purposes');
        $row = collect($response->json('data'))->firstWhere('id', 'plain_label_purpose');

        $this->assertSame('PlainLabel', $row['label'], '점(.) 없는 일반 문자열은 그대로 통과해야 한다');
    }
}

<?php

namespace Tests\Feature\Settings;

use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Tests\TestCase;

/**
 * 이커머스 settings 카탈로그 다국어 자동 보강 검증.
 *
 * `EcommerceSettingsService` 가 `getBuiltinPaymentMethods()` 와 `getAllSettings()`
 * 안에서 `localize_catalog_field()` helper 를 직접 호출하여 활성 언어팩 라벨을 자동 채움.
 *
 * lang pack 미설치 환경(테스트 DB) 에서는 skip-aware 처리.
 */
class EcommerceSettingsLocalizationTest extends TestCase
{
    private function langPackJaActive(): bool
    {
        $jaName = __('sirsoft-ecommerce::settings.payment_methods.card.name', [], 'ja');
        $koName = __('sirsoft-ecommerce::settings.payment_methods.card.name', [], 'ko');

        return $jaName !== 'sirsoft-ecommerce::settings.payment_methods.card.name'
            && $jaName !== $koName;
    }

    public function test_payment_methods_carry_japanese_label_after_enrichment(): void
    {
        if (! $this->langPackJaActive()) {
            $this->markTestSkipped('lang pack ja 미로드');
        }
        config(['app.translatable_locales' => ['ko', 'en', 'ja']]);

        $svc = app(EcommerceSettingsService::class);
        $payment = $svc->getPublicPaymentSettings();
        $card = collect($payment['payment_methods'] ?? [])->firstWhere('id', 'card');

        $this->assertNotNull($card);
        $this->assertSame('クレジットカード', $card['_cached_name']['ja'] ?? null);
        $this->assertSame('신용카드', $card['_cached_name']['ko'] ?? null);
        $this->assertSame('Credit Card', $card['_cached_name']['en'] ?? null);
    }

    public function test_payment_methods_dbank_uses_id_based_lang_key(): void
    {
        if (! $this->langPackJaActive()) {
            $this->markTestSkipped('lang pack ja 미로드');
        }
        config(['app.translatable_locales' => ['ko', 'en', 'ja']]);

        $svc = app(EcommerceSettingsService::class);
        $payment = $svc->getPublicPaymentSettings();
        $dbank = collect($payment['payment_methods'] ?? [])->firstWhere('id', 'dbank');

        $this->assertNotNull($dbank, '저장된 dbank 결제수단 누락');
        // id 기반 lang key (sirsoft-ecommerce::settings.payment_methods.dbank.name) 사용 검증
        $this->assertSame('銀行振込', $dbank['_cached_name']['ja'] ?? null);
    }

    public function test_currencies_carry_japanese_label_via_enrich_catalog_locales(): void
    {
        if (! $this->langPackJaActive()) {
            $this->markTestSkipped('lang pack ja 미로드');
        }
        config(['app.translatable_locales' => ['ko', 'en', 'ja']]);

        $svc = app(EcommerceSettingsService::class);
        $settings = $svc->getAllSettings();
        $krw = collect($settings['language_currency']['currencies'] ?? [])->firstWhere('code', 'KRW');

        $this->assertNotNull($krw);
        $this->assertSame('KRW (ウォン)', $krw['name']['ja'] ?? null);
    }

    public function test_localize_catalog_field_helper_preserves_operator_edit(): void
    {
        config(['app.translatable_locales' => ['ko', 'en', 'ja']]);

        $field = ['ko' => '신용카드', 'en' => 'Credit Card', 'ja' => '운영자 수정'];
        $result = localize_catalog_field($field, 'sirsoft-ecommerce::settings.payment_methods.card.name');

        // 운영자 ja 편집값 보존 (lang pack 으로 덮어쓰지 않음)
        $this->assertSame('운영자 수정', $result['ja']);
    }

    public function test_localize_catalog_field_helper_fills_missing_locale(): void
    {
        if (! $this->langPackJaActive()) {
            $this->markTestSkipped('lang pack ja 미로드');
        }
        config(['app.translatable_locales' => ['ko', 'en', 'ja']]);

        $field = ['ko' => '신용카드', 'en' => 'Credit Card'];
        $result = localize_catalog_field($field, 'sirsoft-ecommerce::settings.payment_methods.card.name');

        $this->assertSame('クレジットカード', $result['ja'] ?? null);
        $this->assertSame('신용카드', $result['ko']);
    }
}

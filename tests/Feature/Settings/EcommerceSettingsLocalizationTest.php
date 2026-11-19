<?php

namespace Tests\Feature\Settings;

use App\Services\LanguagePack\LanguagePackTranslator;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Tests\TestCase;

/**
 * 이커머스 settings 카탈로그 다국어 자동 보강 검증.
 *
 * `EcommerceSettingsService` 가 `getBuiltinPaymentMethods()` 와 `getAllSettings()`
 * 안에서 `localize_catalog_field()` helper 를 직접 호출하여 활성 언어팩 라벨을 자동 채움.
 *
 * 시험 DB 에는 언어팩 행이 없으므로, 활성 팩 등록(LanguagePackServiceProvider)이 하는 일과 같게
 * 번들 ja 팩의 backend/ja 경로를 번역기 폴백으로 직접 붙인다. 예전에는 팩이 없으면 건너뛰어 이 시험 넷이 늘 skip 이었다.
 */
class EcommerceSettingsLocalizationTest extends TestCase
{
    /**
     * 이커머스 설정의 시험 격리 경로.
     */
    private string $storagePath;

    /**
     * 설정 격리 경로를 비우고 번들 이커머스 ja 언어팩을 번역기 폴백 경로로 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }
        app(EcommerceSettingsService::class)->clearCache();

        $translator = app('translator');
        $this->assertInstanceOf(LanguagePackTranslator::class, $translator);
        $translator->addNamespaceFallbackPath(
            namespace: 'sirsoft-ecommerce',
            locale: 'ja',
            path: base_path('lang-packs/_bundled/g7-module-sirsoft-ecommerce-ja/backend/ja'),
        );
    }

    /**
     * 시험이 저장한 설정을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }

        parent::tearDown();
    }

    /**
     * ja 팩이 실렸는지 확인합니다. 실리지 않았으면 시험을 건너뛰지 않고 실패시킵니다.
     *
     * @return void 반환값 없음
     */
    private function assertLangPackJaActive(): void
    {
        $jaName = __('sirsoft-ecommerce::settings.payment_methods.card.name', [], 'ja');
        $koName = __('sirsoft-ecommerce::settings.payment_methods.card.name', [], 'ko');

        $this->assertNotSame('sirsoft-ecommerce::settings.payment_methods.card.name', $jaName, 'ja 팩 폴백이 실리지 않았다');
        $this->assertNotSame($koName, $jaName, 'ja 이름이 ko 로 떨어졌다');
    }

    /**
     * 공개 결제 설정의 카드 결제수단은 ko, en 저장값을 지키고 비어 있는 ja 이름을 언어팩에서 채운다.
     *
     * @scenario catalog=payment_method, ja_value=missing
     *
     * @effects payment_method_ja_name_filled_from_language_pack
     *
     * @return void 반환값 없음
     */
    public function test_payment_methods_carry_japanese_label_after_enrichment(): void
    {
        $this->assertLangPackJaActive();
        config(['app.translatable_locales' => ['ko', 'en', 'ja']]);

        $svc = app(EcommerceSettingsService::class);
        $payment = $svc->getPublicPaymentSettings();
        $card = collect($payment['payment_methods'] ?? [])->firstWhere('id', 'card');

        $this->assertNotNull($card);
        $this->assertSame('クレジットカード', $card['_cached_name']['ja'] ?? null);
        $this->assertSame('신용카드', $card['_cached_name']['ko'] ?? null);
        $this->assertSame('Credit Card', $card['_cached_name']['en'] ?? null);
    }

    /**
     * 무통장입금(dbank)은 결제수단 id 로 만든 번역 키에서 ja 이름을 찾는다.
     *
     * 공개 응답은 사용 중인 입금 계좌가 없으면 dbank 를 빼므로(DbankWithoutActiveAccountTest), 계좌 하나를 갖춘 상태에서 잰다.
     *
     * @scenario catalog=payment_method, ja_value=missing
     *
     * @effects payment_method_name_key_follows_method_id
     *
     * @return void 반환값 없음
     */
    public function test_payment_methods_dbank_uses_id_based_lang_key(): void
    {
        $this->assertLangPackJaActive();
        config(['app.translatable_locales' => ['ko', 'en', 'ja']]);

        $svc = app(EcommerceSettingsService::class);
        $this->assertTrue($svc->setSetting('order_settings.bank_accounts', [
            ['bank_code' => '004', 'account_number' => '123-456-789', 'account_holder' => '시험', 'is_active' => true, 'is_default' => true],
        ]));
        $svc->clearCache();
        $payment = $svc->getPublicPaymentSettings();
        $dbank = collect($payment['payment_methods'] ?? [])->firstWhere('id', 'dbank');

        $this->assertNotNull($dbank, '저장된 dbank 결제수단 누락');
        // id 기반 lang key (sirsoft-ecommerce::settings.payment_methods.dbank.name) 사용 검증
        $this->assertSame('銀行振込', $dbank['_cached_name']['ja'] ?? null);
    }

    /**
     * 전체 설정의 통화 목록은 비어 있는 ja 통화 이름을 언어팩에서 채운다.
     *
     * @scenario catalog=currency, ja_value=missing
     *
     * @effects currency_ja_name_filled_from_language_pack
     *
     * @return void 반환값 없음
     */
    public function test_currencies_carry_japanese_label_via_enrich_catalog_locales(): void
    {
        $this->assertLangPackJaActive();
        config(['app.translatable_locales' => ['ko', 'en', 'ja']]);

        $svc = app(EcommerceSettingsService::class);
        $settings = $svc->getAllSettings();
        $krw = collect($settings['language_currency']['currencies'] ?? [])->firstWhere('code', 'KRW');

        $this->assertNotNull($krw);
        $this->assertSame('KRW (ウォン)', $krw['name']['ja'] ?? null);
    }

    /**
     * 운영자가 적은 ja 값은 언어팩 이름으로 덮어쓰지 않는다.
     *
     * @scenario catalog=helper, ja_value=operator_edited
     *
     * @effects operator_edited_locale_value_is_preserved
     *
     * @return void 반환값 없음
     */
    public function test_localize_catalog_field_helper_preserves_operator_edit(): void
    {
        config(['app.translatable_locales' => ['ko', 'en', 'ja']]);

        $field = ['ko' => '신용카드', 'en' => 'Credit Card', 'ja' => '운영자 수정'];
        $result = localize_catalog_field($field, 'sirsoft-ecommerce::settings.payment_methods.card.name');

        // 운영자 ja 편집값 보존 (lang pack 으로 덮어쓰지 않음)
        $this->assertSame('운영자 수정', $result['ja']);
    }

    /**
     * helper 는 비어 있는 로케일만 언어팩에서 채우고 있는 값은 그대로 둔다.
     *
     * @scenario catalog=helper, ja_value=missing
     *
     * @effects helper_fills_missing_locale_from_language_pack
     *
     * @return void 반환값 없음
     */
    public function test_localize_catalog_field_helper_fills_missing_locale(): void
    {
        $this->assertLangPackJaActive();
        config(['app.translatable_locales' => ['ko', 'en', 'ja']]);

        $field = ['ko' => '신용카드', 'en' => 'Credit Card'];
        $result = localize_catalog_field($field, 'sirsoft-ecommerce::settings.payment_methods.card.name');

        $this->assertSame('クレジットカード', $result['ja'] ?? null);
        $this->assertSame('신용카드', $result['ko']);
    }
}

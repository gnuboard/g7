<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreEcommerceSettingsRequest;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 통화별 언어(locales) 검증 테스트 (A4)
 *
 * currencies.*.locales 는 시스템 지원 로케일(config app.supported_locales)만 허용한다.
 */
class CurrencyLocalesValidationTest extends ModuleTestCase
{
    private function validate(array $currencies): Validator
    {
        $request = new StoreEcommerceSettingsRequest;

        return ValidatorFacade::make(
            ['language_currency' => ['currencies' => $currencies]],
            $request->rules()
        );
    }

    /**
     * FormRequest 의 attributes() 를 적용한 Validator 를 생성합니다.
     *
     * 실제 요청 흐름과 동일하게 사람이 읽는 필드명 치환을 검증하기 위함입니다.
     *
     * @param  array  $currencies  통화 배열
     * @return Validator attributes 가 적용된 Validator
     */
    private function validateWithAttributes(array $currencies): Validator
    {
        $request = new StoreEcommerceSettingsRequest;

        return ValidatorFacade::make(
            ['language_currency' => ['currencies' => $currencies]],
            $request->rules(),
            [],
            $request->attributes()
        );
    }

    public function test_supported_locale_tags_pass(): void
    {
        config(['app.supported_locales' => ['ko', 'en', 'ja']]);

        $v = $this->validate([
            ['code' => 'JPY', 'name' => ['ko' => '엔', 'en' => 'Yen'], 'locales' => ['ja']],
        ]);

        $this->assertFalse($v->errors()->has('language_currency.currencies.0.locales.0'));
    }

    public function test_unsupported_locale_tag_rejected(): void
    {
        config(['app.supported_locales' => ['ko', 'en']]);

        $v = $this->validate([
            ['code' => 'JPY', 'name' => ['ko' => '엔', 'en' => 'Yen'], 'locales' => ['xx']],
        ]);

        $v->passes();
        $this->assertTrue(
            $v->errors()->has('language_currency.currencies.0.locales.0'),
            'supported_locales 외 언어 태그가 거부되지 않았습니다.'
        );
    }

    public function test_empty_locales_allowed(): void
    {
        config(['app.supported_locales' => ['ko', 'en']]);

        $v = $this->validate([
            ['code' => 'USD', 'name' => ['ko' => '달러', 'en' => 'Dollar'], 'locales' => []],
        ]);

        $this->assertFalse($v->errors()->has('language_currency.currencies.0.locales'));
    }

    public function test_locale_error_message_uses_human_readable_attribute(): void
    {
        // 결함 B 회귀: 에러 메시지에 raw 경로(...locales.0)가 아닌 친화 필드명이 나와야 한다.
        config(['app.supported_locales' => ['ko', 'en']]);
        app()->setLocale('ko');

        $v = $this->validateWithAttributes([
            ['code' => 'CNY', 'name' => ['ko' => '위안', 'en' => 'Yuan'], 'locales' => ['zh']],
        ]);

        $v->passes();
        $message = $v->errors()->first('language_currency.currencies.0.locales.0');

        $this->assertNotSame('', $message);
        $this->assertStringContainsString('사용 언어', $message, '에러 메시지에 친화 필드명(사용 언어)이 없습니다.');
        $this->assertStringNotContainsString('locales.0', $message, '에러 메시지에 raw 경로가 노출되었습니다.');
        $this->assertStringNotContainsString('language_currency', $message, '에러 메시지에 raw 경로가 노출되었습니다.');
    }
}

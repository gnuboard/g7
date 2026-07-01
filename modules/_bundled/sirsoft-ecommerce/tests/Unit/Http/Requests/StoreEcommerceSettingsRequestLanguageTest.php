<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http\Requests;

use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreEcommerceSettingsRequest;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 기본 언어 필드 제거 테스트 (A1-⑤, D-LANG)
 *
 * "언어·통화" 화면에서 기본 언어 필드를 제거하고 사이트 언어는 코어 일반설정으로 일원화한다.
 * 모듈 default_language 는 읽는 코드 0(orphan) → 검증 규칙 제거 + validated() 미포함.
 */
class StoreEcommerceSettingsRequestLanguageTest extends ModuleTestCase
{
    private function rulesFor(): array
    {
        return (new StoreEcommerceSettingsRequest)->rules();
    }

    public function test_default_language_rule_is_removed(): void
    {
        $rules = $this->rulesFor();

        $this->assertArrayNotHasKey(
            'language_currency.default_language',
            $rules,
            'default_language 검증 규칙이 남아 있습니다 (제거되어야 함).'
        );
    }

    public function test_default_currency_rule_still_present(): void
    {
        $rules = $this->rulesFor();

        // 통화 설정은 유지(비회귀)
        $this->assertArrayHasKey('language_currency.default_currency', $rules);
        $this->assertArrayHasKey('language_currency.currencies', $rules);
    }

    public function test_validated_drops_default_language_when_sent(): void
    {
        // default_language 가 전달돼도 규칙 부재 → validated() 미포함
        $request = StoreEcommerceSettingsRequest::create('/', 'POST', [
            '_tab' => 'language_currency',
            'language_currency' => [
                'default_language' => 'en',
                'default_currency' => 'KRW',
                'currencies' => [
                    ['code' => 'KRW', 'name' => ['ko' => 'KRW', 'en' => 'KRW'], 'exchange_rate' => null, 'is_default' => true],
                ],
            ],
        ]);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);

        $validated = $request->validateResolved();
        $data = $request->validated();

        $this->assertArrayHasKey('language_currency', $data);
        $this->assertArrayNotHasKey(
            'default_language',
            $data['language_currency'],
            'default_language 가 validated() 에 포함되었습니다 (규칙 제거로 떨궈야 함).'
        );
        $this->assertSame('KRW', $data['language_currency']['default_currency']);
    }
}

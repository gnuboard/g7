<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Lang;

use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateAdminUserCurrencyRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UpdateUserCurrencyRequest;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 통화 검증 메시지 다국어 해석 테스트 (D10 회귀)
 *
 * base 통화 잠금 메시지와 유저 통화 검증 메시지가 사용자에게 실제 번역 문자열로
 * 노출되는지 검증한다. 메시지 키는 모듈 lang 파일의 `custom` 네임스페이스에 정의되어 있으며
 * (validation.custom.language_currency.* / validation.custom.user_currency.*),
 * FormRequest 의 messages()/withValidator() 도 같은 경로(custom 포함)로 참조해야 한다.
 *
 * 회귀 배경: 3개 참조 지점이 `custom` 세그먼트를 누락해
 * (validation.language_currency.base_locked_after_data / validation.user_currency.*)
 * __() 가 해석하지 못하고 사용자에게 raw 키(sirsoft-ecommerce::validation....)가 노출되었다.
 */
class CurrencyValidationMessageTest extends ModuleTestCase
{
    /**
     * base 통화 잠금 메시지(custom 경로)가 ko/en 모두 실제 문자열로 해석되어야 한다.
     */
    public function test_base_locked_message_resolves(): void
    {
        foreach (['ko', 'en'] as $locale) {
            $this->app->setLocale($locale);
            $key = 'sirsoft-ecommerce::validation.custom.language_currency.base_locked_after_data';
            $resolved = __($key);

            $this->assertNotSame($key, $resolved, "[{$locale}] base 잠금 메시지가 해석되지 않았습니다.");
            $this->assertNotEmpty($resolved);
        }
    }

    /**
     * 유저 통화 검증 메시지(custom 경로)가 ko/en 모두 실제 문자열로 해석되어야 한다.
     */
    public function test_user_currency_messages_resolve(): void
    {
        foreach (['ko', 'en'] as $locale) {
            $this->app->setLocale($locale);
            foreach (['required', 'invalid'] as $rule) {
                $key = "sirsoft-ecommerce::validation.custom.user_currency.{$rule}";
                $resolved = __($key);

                $this->assertNotSame($key, $resolved, "[{$locale}] user_currency.{$rule} 가 해석되지 않았습니다.");
                $this->assertNotEmpty($resolved);
            }
        }
    }

    /**
     * 유저 통화 FormRequest 의 messages() 가 raw 키가 아닌 번역 문자열을 반환해야 한다.
     * (참조 지점이 custom 세그먼트를 누락하면 이 단언이 raw 키를 잡아낸다.)
     */
    public function test_user_currency_request_messages_are_translated(): void
    {
        $this->app->setLocale('ko');

        foreach ([new UpdateUserCurrencyRequest, new UpdateAdminUserCurrencyRequest] as $request) {
            $messages = $request->messages();
            foreach (['currency.required', 'currency.in'] as $field) {
                $this->assertArrayHasKey($field, $messages);
                $this->assertStringNotContainsString(
                    'sirsoft-ecommerce::validation',
                    $messages[$field],
                    sprintf('%s 의 %s 메시지가 raw 키로 남아 있습니다: %s', $request::class, $field, $messages[$field])
                );
            }
        }
    }
}

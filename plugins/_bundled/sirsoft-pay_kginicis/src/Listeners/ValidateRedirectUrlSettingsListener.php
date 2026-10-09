<?php

namespace Plugins\Sirsoft\PayKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;

/**
 * 결제 후 이동 주소 설정(redirect_success_url·redirect_fail_url)의 형식을 검증합니다.
 *
 * 설정 스키마가 type string 뿐이라 「not a url」 같은 글자도 저장됐고, 결제를 마친 구매자가 그 상대 경로로
 * 보내졌다. 주소는 `{shopBase}`(상점 주소로 치환)·`/`(사이트 기준 경로)·`http(s)://`(전체 주소)로 시작하고
 * 공백이 없어야 한다. `//host` 처럼 다른 도메인으로 새는 상대 프로토콜 주소와 `javascript:` 는 받지 않는다.
 * 비우면 기본 주소를 쓴다(콜백이 `?? DEFAULT` 로 읽는다).
 */
class ValidateRedirectUrlSettingsListener implements HookListenerInterface
{
    /** 이 플러그인 식별자 */
    private const IDENTIFIER = 'sirsoft-pay_kginicis';

    /** 이동 주소 형식 — {shopBase}·/(뒤에 / 아님)·http(s):// 로 시작, 공백 없음 */
    private const PATTERN = '/^(\{shopBase\}(\/(?![\/\\\\])\S*)?|\/(?![\/\\\\])\S*|https?:\/\/\S+)$/D';

    /**
     * 구독 메서드와 동기 실행 계약을 선언합니다.
     *
     * @return array 처리 결과
     */
    public static function getSubscribedHooks(): array
    {
        return ['core.plugin_settings.update_validation_rules' => ['method' => 'rules', 'type' => 'filter', 'priority' => 10]];
    }

    /**
     * 등록된 처리를 실행합니다.
     *
     * @param  mixed  $args  args 입력값
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 이 플러그인 설정 저장에 이동 주소 형식 규칙을 더합니다.
     *
     * @param  array  $rules  기존 검증 규칙
     * @param  string  $identifier  저장 대상 플러그인 식별자
     * @param  array  $input  이번 요청 입력값
     * @return array 규칙
     */
    public function rules(array $rules, string $identifier, array $input = []): array
    {
        if ($identifier !== self::IDENTIFIER) {
            return $rules;
        }

        foreach (['redirect_success_url', 'redirect_fail_url'] as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'string', 'max:500', function ($attribute, $value, $fail) {
                if (is_string($value) && preg_match(self::PATTERN, $value) !== 1) {
                    $fail(__(self::IDENTIFIER.'::messages.settings.redirect_url_format'));
                }
            }];
        }

        return $rules;
    }
}

<?php

namespace App\Exceptions\Auth;

use App\Extension\Auth\PasswordAuthDecision;
use App\Support\LocalizedValue;
use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * 비밀번호 인증 정책이 이 식별자·회원의 비밀번호 사용을 막았을 때의 예외.
 *
 * 로그인 컨트롤러는 이 예외를 403 `errors.code = auth.password_auth_disabled` 와 `data{method_label, redirect_url}` 로 바꾼다
 * (다른 로그인 실패처럼 사유를 버리지 않는다 — 화면이 「회사 계정으로 로그인」 안내와 이동 버튼을 그린다).
 *
 * 사용자에게 안내로 답하는 정상 결과라 오류 로그에 보고하지 않는다(`ShouldntReport` — 같은 성격의 `AccountLockedException` 등은
 * `HttpException` 이라 원래 보고되지 않는다).
 *
 * @since 7.2.0
 */
class PasswordAuthDisallowedException extends RuntimeException implements ShouldntReport
{
    /** 응답 오류 코드 */
    public const ERROR_CODE = 'auth.password_auth_disabled';

    /**
     * 예외를 만듭니다.
     *
     * @param  PasswordAuthDecision  $decision  거절 판정
     */
    public function __construct(public readonly PasswordAuthDecision $decision)
    {
        parent::__construct(self::localizedMessage($decision));
    }

    /**
     * 판정의 안내 문구를 만듭니다 — 수단 이름이 있으면 그 이름을 넣는다.
     *
     * @param  PasswordAuthDecision  $decision  거절 판정
     * @return string 안내 문구
     */
    public static function localizedMessage(PasswordAuthDecision $decision): string
    {
        [$key, $params] = self::messageKeyAndParams($decision);

        return __($key, $params);
    }

    /**
     * 판정의 안내 문구 키와 치환값을 고릅니다 — 확장이 준 사유 키가 번역되면 그것, 아니면 수단 이름 유무로 코어 키.
     *
     * @param  PasswordAuthDecision  $decision  거절 판정
     * @return array{0: string, 1: array<string, string>} 문구 키와 치환값
     */
    public static function messageKeyAndParams(PasswordAuthDecision $decision): array
    {
        if ($decision->reasonKey !== null && $decision->reasonKey !== self::ERROR_CODE) {
            $custom = __($decision->reasonKey);

            if (is_string($custom) && $custom !== $decision->reasonKey) {
                return [$decision->reasonKey, []];
            }
        }

        $label = self::labelFor($decision);

        return $label === null
            ? ['auth.password_auth_disabled', []]
            : ['auth.password_auth_disabled_with_method', ['method' => $label]];
    }

    /**
     * 현재 로케일에 맞는 수단 이름을 고릅니다.
     *
     * @param  PasswordAuthDecision  $decision  거절 판정
     * @return string|null 수단 이름 (없으면 null)
     */
    private static function labelFor(PasswordAuthDecision $decision): ?string
    {
        $labels = $decision->methodLabel ?? [];

        if ($labels === []) {
            return null;
        }

        return LocalizedValue::pick($labels);
    }
}

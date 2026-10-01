<?php

namespace Plugins\Sirsoft\Gdpr\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Plugins\Sirsoft\Gdpr\Support\PrivacyPolicyUrl;

/**
 * 개인정보처리방침 주소 검증 규칙
 *
 * `/` 로 시작하는 사이트 내부 경로 또는 `https://` 절대 주소만 받습니다. 판정은
 * `PrivacyPolicyUrl::isSafe()` 가 단일 출처이며, 이 규칙은 그 결과를 검증 오류로 바꿉니다.
 * 빈 값은 통과시킵니다 — 비우면 쿠키 배너의 처리방침 링크를 숨기겠다는 뜻입니다.
 */
class PrivacyPolicyUrlRule implements ValidationRule
{
    /**
     * 값이 허용 형식의 처리방침 주소인지 검증합니다.
     *
     * @param  string  $attribute  검증 대상 필드명
     * @param  mixed  $value  검증 대상 값
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || ! PrivacyPolicyUrl::isSafe($value)) {
            $fail(__('sirsoft-gdpr::messages.settings.fields.privacy_policy_url.invalid'));
        }
    }
}

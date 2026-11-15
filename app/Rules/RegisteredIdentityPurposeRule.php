<?php

namespace App\Rules;

use App\Enums\IdentityVerificationPurpose;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 본인인증 목적(purpose)이 코어·확장에 등록된 목적인지 검증합니다.
 *
 * 공개 인증 시작 요청이 등록되지 않은 목적이나 다른 확장의 목적으로 인증 결과를 만들어 두지 못하게 한다(KVE 동형 B1).
 * 로그인 2단계(login) 목적은 로그인 전용 경로만 발급하므로 공개 요청에서는 거부한다.
 *
 * @since 7.1.6
 */
class RegisteredIdentityPurposeRule implements ValidationRule
{
    /**
     * @param  bool  $allowLogin  login 목적 허용 여부 (공개 요청은 false)
     * @param  string  $messageKey  실패 메시지 다국어 키
     * @param  string|null  $unchangedValue  이미 저장된 값 — 같으면 검사하지 않는다(꺼진 확장의 목적을 쓰는 정책도 수정·끄기 저장 가능)
     */
    public function __construct(
        protected bool $allowLogin = false,
        protected string $messageKey = 'validation.identity_policy.purpose_unknown',
        protected ?string $unchangedValue = null,
    ) {}

    /**
     * 목적이 등록돼 있는지 검사합니다.
     *
     * @param  string  $attribute  필드명
     * @param  mixed  $value  입력값
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if ($this->unchangedValue !== null && $value === $this->unchangedValue) {
            return;
        }

        if (! $this->allowLogin && $value === IdentityVerificationPurpose::Login->value) {
            $fail(__($this->messageKey));

            return;
        }

        if (! app(IdentityVerificationManager::class)->hasPurpose($value)) {
            $fail(__($this->messageKey));
        }
    }
}

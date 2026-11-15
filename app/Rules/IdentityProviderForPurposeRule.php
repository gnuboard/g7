<?php

namespace App\Rules;

use App\Extension\IdentityVerification\IdentityVerificationManager;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 본인인증 수단(provider_id)이 등록돼 있고 그 목적을 지원하는지 검증합니다.
 *
 * 종전에는 등록되지 않은 수단을 지정해도 조용히 기본 수단(메일)으로 발급했고, 관리자 정책에도 지원하지 않는 수단이
 * 저장됐다(KVE 동형 B8·B9). 빈 값(지정 안 함)은 통과한다.
 *
 * 관리자 정책 수정은 이미 저장돼 있는 값(예: 지금은 제거된 플러그인의 수단)을 그대로 보내도 통과시킨다 — 그 정책을
 * 끄는 저장까지 막으면 운영자가 정책을 정리할 방법이 없다.
 *
 * @since 7.1.6
 */
class IdentityProviderForPurposeRule implements ValidationRule
{
    /**
     * @param  Closure(): ?string  $purposeResolver  검사할 목적을 돌려주는 콜백 (입력값 또는 현재 정책 값)
     * @param  string|null  $unchangedValue  이미 저장된 값 — 같으면 검사하지 않는다
     */
    public function __construct(
        protected Closure $purposeResolver,
        protected ?string $unchangedValue = null,
    ) {}

    /**
     * 수단이 등록·목적 지원 상태인지 검사합니다.
     *
     * @param  string  $attribute  필드명
     * @param  mixed  $value  입력값
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail(__('validation.identity_policy.provider_id_unknown'));

            return;
        }

        if ($this->unchangedValue !== null && $value === $this->unchangedValue) {
            return;
        }

        $manager = app(IdentityVerificationManager::class);

        if (! $manager->has($value)) {
            $fail(__('validation.identity_policy.provider_id_unknown'));

            return;
        }

        $purpose = ($this->purposeResolver)();
        if (is_string($purpose) && $purpose !== '' && ! $manager->get($value)->supportsPurpose($purpose)) {
            $fail(__('validation.identity_policy.provider_purpose_unsupported'));
        }
    }
}

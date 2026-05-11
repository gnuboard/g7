<?php

namespace App\Rules;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Mode B: register 시 verification_token 이 IDV 인프라의 verified 상태를 가리키는지 검증.
 *
 * purpose+target_hash 바인딩된 서명 토큰만 통과시키며,
 * 다른 가입 시도에 재사용되거나 소비된 토큰은 거부한다.
 *
 * @since 7.0.0-beta.4
 */
class IdvTokenRule implements ValidationRule
{
    public function __construct(
        protected string $purpose,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail(__('identity.errors.invalid_verification_token'));

            return;
        }

        $log = app(IdentityVerificationLogRepositoryInterface::class)
            ->findVerifiedForToken($value, $this->purpose);

        if (! $log) {
            $fail(__('identity.errors.invalid_verification_token'));
        }
    }
}

<?php

namespace App\Rules;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Services\IdentityTokenAcceptanceService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Mode B: register 시 verification_token 이 IDV 인프라의 verified 상태를 가리키는지 검증.
 *
 * purpose+target_hash 바인딩된 서명 토큰만 통과시키며,
 * 다른 가입 시도에 재사용되거나 소비된 토큰은 거부한다.
 *
 * 같은 요청에서 정책 미들웨어가 이미 인정(소비)한 결과는 통과시킨다 — 결과는 작업 1건에 1번 소비되므로(7.1.6)
 * 미소비 조회만 하면 정상 가입이 검증 단계에서 거부된다.
 *
 * @since 7.0.0-beta.4
 */
class IdvTokenRule implements ValidationRule
{
    /**
     * @param  string  $purpose  요구 목적
     */
    public function __construct(
        protected string $purpose,
    ) {}

    /**
     * 토큰이 확인 완료 결과를 가리키는지 검사합니다.
     *
     * @param  string  $attribute  필드명
     * @param  mixed  $value  입력값
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail(__('identity.errors.invalid_verification_token'));

            return;
        }

        $log = app(IdentityTokenAcceptanceService::class)->acceptedInRequest($value, $this->purpose)
            ?? app(IdentityVerificationLogRepositoryInterface::class)->findVerifiedForToken($value, $this->purpose);

        if (! $log) {
            $fail(__('identity.errors.invalid_verification_token'));
        }
    }
}

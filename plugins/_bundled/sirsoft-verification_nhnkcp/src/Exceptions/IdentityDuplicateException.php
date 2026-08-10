<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Exceptions;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Validation\ValidationException;

/**
 * 동일인이 이미 가입된 상태에서 재가입을 시도할 때 발생.
 *
 * KCP 본인확인의 DI 또는 CI hash 가 `nhnkcp_identity_records` 의 기존 row 와 매칭되면
 * 본 예외가 throw 된다. AuthService::register() 가 호출하는 `core.auth.before_register` 훅의
 * priority 20 listener (AssertNoDuplicateKcpIdentity) 에서 발화한다.
 *
 * HTTP 422 + identity 필드 에러로 응답 — Laravel ValidationException 상속으로 표준 변환.
 * 메시지는 i18n 키로 PII (이메일/이름) 를 절대 노출하지 않는다 — heads-up 공격 방어.
 *
 * @since 1.0.0
 */
class IdentityDuplicateException extends ValidationException
{
    /**
     * 가입 차단 예외 생성.
     *
     * @param  string  $matchedField  매칭된 hash 컬럼명 (di_hash / ci_hash). 감사 로그/테스트용
     */
    public function __construct(public readonly string $matchedField = 'di_hash')
    {
        parent::__construct($this->buildValidator());
    }

    /**
     * identity 필드 에러를 담은 Validator 인스턴스 생성.
     *
     * @return Validator identity 필드에 중복 가입 안내 메시지가 담긴 Validator
     */
    protected function buildValidator(): Validator
    {
        $validator = ValidatorFactory::make([], []);
        $validator->errors()->add(
            'identity',
            __('sirsoft-verification_nhnkcp::exceptions.duplicate_register'),
        );

        return $validator;
    }
}

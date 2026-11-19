<?php

namespace App\Rules;

use App\Rules\Concerns\ChecksNicknamePolicy;
use App\Services\NicknamePolicyService;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 닉네임 금지어 규칙 — 운영자가 정한 금지어가 들어 있으면 실패한다(전각, 대소문자, 공백 차이는 무시).
 *
 * @since 7.2.0
 */
class NicknameForbiddenWords implements ValidationRule
{
    use ChecksNicknamePolicy;

    /**
     * 이 규칙이 맡는 위반 사유를 반환합니다.
     *
     * @return string 사유
     */
    protected function reason(): string
    {
        return NicknamePolicyService::REASON_FORBIDDEN_WORD;
    }
}

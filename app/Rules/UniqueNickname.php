<?php

namespace App\Rules;

use App\Rules\Concerns\ChecksNicknamePolicy;
use App\Services\NicknamePolicyService;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 닉네임 중복 금지 규칙 — 중복 금지가 켜져 있고 다른 회원이 같은 닉네임(정규화 기준)을 쓰면 실패한다.
 *
 * @since 7.2.0
 */
class UniqueNickname implements ValidationRule
{
    use ChecksNicknamePolicy;

    /**
     * 이 규칙이 맡는 위반 사유를 반환합니다.
     *
     * @return string 사유
     */
    protected function reason(): string
    {
        return NicknamePolicyService::REASON_DUPLICATE;
    }
}

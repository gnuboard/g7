<?php

namespace App\Rules;

use App\Rules\Concerns\ChecksNicknamePolicy;
use App\Services\NicknamePolicyService;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 닉네임 변경 주기 규칙 — 본인이 닉네임을 바꾼 뒤 정해진 일수가 지나지 않았으면 실패한다(관리자 수정은 면제).
 *
 * @since 7.2.0
 */
class NicknameChangeInterval implements ValidationRule
{
    use ChecksNicknamePolicy;

    /**
     * 이 규칙이 맡는 위반 사유를 반환합니다.
     *
     * @return string 사유
     */
    protected function reason(): string
    {
        return NicknamePolicyService::REASON_CHANGE_INTERVAL;
    }
}

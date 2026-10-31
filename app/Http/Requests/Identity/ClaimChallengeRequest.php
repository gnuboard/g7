<?php

namespace App\Http\Requests\Identity;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 링크 방식 challenge 의 확인 결과 받아 가기 요청 검증.
 *
 * 권한은 라우트의 permission:user,core.identity.verify 미들웨어가 담당하고, 받아 갈 자격은 시작 응답으로 받은
 * 일회용 비밀값(`claim_secret`)이 증명한다.
 */
class ClaimChallengeRequest extends FormRequest
{
    /**
     * 요청 권한 — 라우트 permission 미들웨어가 담당하므로 true 고정.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'claim_secret' => ['required', 'string', 'max:128'],
        ];

        return HookManager::applyFilters('core.identity.claim_validation_rules', $rules, $this);
    }
}

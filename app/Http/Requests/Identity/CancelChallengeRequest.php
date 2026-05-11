<?php

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Challenge 취소 요청.
 *
 * 권한은 라우트의 permission:user,core.identity.cancel 미들웨어가 담당합니다.
 * 로그인 사용자는 PermissionMiddleware 의 scope=self 가드가 challenge.user_id 일치를 자동 검증합니다.
 * 비로그인 게스트는 guest 역할 권한만 통과하면 진입합니다 (모달 취소 시 audit trail 정합용).
 *
 * 검증 규칙은 비어 있습니다 — 라우트 모델 바인딩이 challenge UUID 유효성을 보장하므로 추가 입력 검증 불필요.
 */
class CancelChallengeRequest extends FormRequest
{
    /**
     * 요청 권한 — 라우트 permission 미들웨어가 담당하므로 true 고정.
     *
     * @return bool
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
        return [];
    }
}

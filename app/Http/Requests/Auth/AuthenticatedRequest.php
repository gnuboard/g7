<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 본문 입력이 없는 인증 세션 요청
 *
 * 로그아웃 · 현재 사용자 조회 · 토큰 갱신처럼 토큰으로 식별되는 주체만 있으면 되고
 * 본문 입력을 받지 않는 엔드포인트가 공유합니다. 검증 규칙이 비어 있다는 사실 자체가
 * "이 엔드포인트는 어떤 입력도 받지 않는다" 는 선언이며, 나중에 입력이 생기면 그 규칙을
 * 둘 자리가 이미 마련되어 있습니다.
 *
 * 인증은 라우트의 `auth:sanctum` 미들웨어가, 권한은 permission 미들웨어가 담당합니다.
 */
class AuthenticatedRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인합니다.
     *
     * @return bool 항상 true (인증·권한은 미들웨어에서 검증)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 빈 배열 (본문 입력 없음)
     */
    public function rules(): array
    {
        return [];
    }
}

<?php

namespace App\Http\Requests\Identity;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Challenge 검증 요청.
 *
 * 권한은 라우트의 permission:user,core.identity.verify 미들웨어가 담당합니다.
 * 로그인 사용자는 PermissionMiddleware 의 scope=self 가드가 challenge.user_id 일치를 자동 검증합니다.
 * 비로그인 가입 플로우(Mode B) 도 동일 엔드포인트를 사용하므로 optional.sanctum 경로도 허용.
 */
class VerifyChallengeRequest extends FormRequest
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
        $rules = [
            'code' => ['nullable', 'string', 'max:16'],
            'token' => ['nullable', 'string', 'max:256'],
        ];

        // 모듈/플러그인이 IDV challenge 검증 입력 규칙을 동적으로 확장 가능 (예: 외부 provider SDK payload 필드)
        return HookManager::applyFilters('core.identity.verify_validation_rules', $rules, $this);
    }
}

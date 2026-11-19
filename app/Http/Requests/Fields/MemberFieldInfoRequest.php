<?php

namespace App\Http\Requests\Fields;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 환경설정 회원 탭의 안내 조회 요청 (닉네임 겹침 수, 중복 검사 준비 진행 상태).
 *
 * 본문이 없는 읽기 요청이다. 권한은 라우트의 `permission:admin,core.settings.read` 가 확인한다.
 *
 * @since 7.2.0
 */
class MemberFieldInfoRequest extends FormRequest
{
    /**
     * 요청 권한 — 라우트 미들웨어가 확인하므로 true 고정.
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
     * @return array<string, array<int, mixed>> 검증 규칙 (입력 없음)
     */
    public function rules(): array
    {
        return HookManager::applyFilters('core.member_field.info_validation_rules', [], $this);
    }
}

<?php

namespace App\Http\Requests\Settings;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 개인정보 조치 대상 건수 미리보기 요청 FormRequest
 *
 * 입력은 없다. 응답의 `can_view_targets`(「더보기」 목록 권한)를 요청 관리자 기준으로
 * 판정하기 위해 요청 객체가 필요할 뿐이다.
 */
class PrivacyPreviewRequest extends FormRequest
{
    /**
     * 요청 권한을 확인합니다.
     *
     * @return bool 인가 여부 (권한 체크는 라우트 미들웨어 `core.settings.read` 에 위임)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙 (입력 없음)
     */
    public function rules(): array
    {
        return HookManager::applyFilters('core.settings.privacy_preview_validation_rules', [], $this);
    }
}

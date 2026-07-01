<?php

namespace App\Http\Requests\Admin\Template;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 템플릿 레이아웃 첨부 파일 목록 조회 요청 검증
 *
 * 권한은 라우트의 permission 미들웨어(core.templates.layouts.edit)가 담당하므로
 * authorize()는 true 를 고정 반환한다.
 */
class ListTemplateLayoutAttachmentsRequest extends FormRequest
{
    /**
     * 요청 권한 확인 — 권한은 permission 미들웨어가 담당.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙 — layout_name 쿼리 필터(선택).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'layout_name' => ['nullable', 'string', 'max:150'],
        ];

        // 모듈/플러그인이 검증 규칙을 동적으로 확장할 수 있도록 훅 제공
        return HookManager::applyFilters('core.template_layout_attachment.list_validation_rules', $rules, $this);
    }
}

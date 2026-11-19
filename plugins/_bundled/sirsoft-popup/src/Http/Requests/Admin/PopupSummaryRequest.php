<?php

namespace Plugins\Sirsoft\Popup\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 「지금 노출 중」 요약 조회 요청 — 입력을 받지 않는다.
 */
class PopupSummaryRequest extends FormRequest
{
    /**
     * 권한은 라우트 permission 미들웨어가 판정합니다(팝업 조회).
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
     * @return array<string, mixed> 검증 규칙 (사용 파라미터 없음)
     */
    public function rules(): array
    {
        return [];
    }
}

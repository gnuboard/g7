<?php

namespace Plugins\Sirsoft\Popup\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\Popup\Enums\PopupDevice;
use Plugins\Sirsoft\Popup\Enums\PopupStatus;

/**
 * 팝업 목록 요청 — 상태·기기·관리용 제목 검색·정렬·쪽.
 */
class PopupListRequest extends FormRequest
{
    /**
     * 권한은 라우트 permission 미들웨어가 판정합니다.
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
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(PopupStatus::class)],
            // 「PC + 모바일」 팝업은 어느 기기로 걸러도 나온다 — 필터 값은 PC·모바일만 받는다
            'device' => ['nullable', Rule::enum(PopupDevice::class)->only([PopupDevice::Pc, PopupDevice::Mobile])],
            'search' => ['nullable', 'string', 'max:100'],
            'sort_by' => ['nullable', Rule::in(['sort_order', 'created_at', 'status'])],
            'sort_order' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * 검증 오류에 쓸 필드 이름을 반환합니다.
     *
     * @return array<string, string> 필드 => 이름
     */
    public function attributes(): array
    {
        return [
            'status' => __('sirsoft-popup::validation.attributes.status'),
            'device' => __('sirsoft-popup::validation.attributes.device'),
            'search' => __('sirsoft-popup::validation.attributes.search'),
            'sort_by' => __('sirsoft-popup::validation.attributes.sort_by'),
            'sort_order' => __('sirsoft-popup::validation.attributes.sort_order'),
            'page' => __('sirsoft-popup::validation.attributes.page'),
            'per_page' => __('sirsoft-popup::validation.attributes.per_page'),
        ];
    }
}

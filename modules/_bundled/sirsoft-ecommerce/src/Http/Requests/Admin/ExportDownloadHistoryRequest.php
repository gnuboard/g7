<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 내보내기 다운로드 이력 조회 요청
 *
 * 권한(`sirsoft-ecommerce.exports.read`)과 범위(본인/전체)는 라우트 미들웨어가, 그 파일 유형의 조회 권한·개인정보 권한은
 * 컨트롤러가 본다.
 */
class ExportDownloadHistoryRequest extends FormRequest
{
    /**
     * 권한 확인 — 라우트 미들웨어가 담당한다
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 유효성 검사 규칙
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        return HookManager::applyFilters('sirsoft-ecommerce.export.downloads_validation_rules', [
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ], $this);
    }

    /**
     * 검증 필드 라벨
     *
     * @return array<string, string> 필드 → 라벨
     */
    public function attributes(): array
    {
        return [
            'per_page' => __('sirsoft-ecommerce::validation.export_attributes.per_page'),
            'page' => __('sirsoft-ecommerce::validation.export_attributes.page'),
        ];
    }
}

<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Ecommerce\Models\Export;

/**
 * 실패한 내보내기 다시 요청
 *
 * 권한(`sirsoft-ecommerce.exports.create`)과 범위(본인/전체)는 라우트 미들웨어가, 그 파일 유형의 조회 권한·개인정보 권한은
 * 컨트롤러가 본다.
 */
class RetryExportRequest extends FormRequest
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
        // 남의 개인정보 작업을 다시 요청하면 자기 사유가 필요하다 — 원래 요청자의 사유를 자기 사유로 남기지 않는다
        $export = $this->route('export');
        $needsReason = $export instanceof Export && $export->contains_personal_data && (int) $export->requested_by !== (int) $this->user()?->id;

        return HookManager::applyFilters('sirsoft-ecommerce.export.retry_validation_rules', [
            'reason' => [$needsReason ? 'required' : 'nullable', 'string', 'max:500'],
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
            'reason' => __('sirsoft-ecommerce::validation.export_attributes.reason'),
        ];
    }
}

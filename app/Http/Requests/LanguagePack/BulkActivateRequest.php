<?php

namespace App\Http\Requests\LanguagePack;

use App\Extension\HookManager;
use App\Models\LanguagePack;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 언어팩 일괄 활성화 요청 (요구사항 #7 reactivate 모달 → "활성화" 버튼).
 *
 * 호스트 확장 재활성화 시 cascade 비활성화됐던 언어팩 ID 배열을 받아
 * Service 의 bulkActivate() 로 위임합니다.
 */
class BulkActivateRequest extends FormRequest
{
    /**
     * 권한 체크는 라우트 미들웨어가 담당.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 정의합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', Rule::exists(LanguagePack::class, 'id')],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.language_packs.bulk_activate_validation_rules', $rules, $this);
    }
}

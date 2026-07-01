<?php

namespace App\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * OG/Twitter/구조화 미리보기 요청 검증.
 *
 * 편집기가 dirty meta.seo + 샘플 컨텍스트를 POST 로 보낸다. 권한 체크는 라우트
 * `core.templates.layouts.edit` 미들웨어에서 수행한다(authorize() 는 true 고정).
 */
class SeoOgPreviewRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인합니다.
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, string>> 검증 규칙 배열
     */
    public function rules(): array
    {
        $rules = [
            'seo' => ['required', 'array'],
            // 이 레이아웃이 직접 선언한 meta.seo(base 병합 전). 병합본에는 있으나 own 에 없는
            // og/twitter 키 = base 상속(SEO-B). 선택 — 미전달 시 상속/자체 구분 안 함.
            'own_seo' => ['nullable', 'array'],
            'seed_context' => ['nullable', 'array'],
            'route_params' => ['nullable', 'array'],
        ];

        return HookManager::applyFilters('core.seo_og_preview.show_validation_rules', $rules, $this);
    }
}

<?php

namespace App\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 봇 HTML 미리보기 요청 검증.
 *
 * 편집기가 dirty 레이아웃 + 샘플 컨텍스트를 POST 로 보낸다. 권한 체크는 라우트
 * `core.templates.layouts.edit` 미들웨어에서 수행한다(authorize() 는 true 고정).
 */
class SeoBotPreviewRequest extends FormRequest
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
            'layout' => ['required', 'array'],
            'route_params' => ['nullable', 'array'],
            'url' => ['nullable', 'string'],
            'locale' => ['nullable', 'string'],
            'module_id' => ['nullable', 'string'],
            'plugin_id' => ['nullable', 'string'],
            'seed_context' => ['nullable', 'array'],
        ];

        return HookManager::applyFilters('core.seo_bot_preview.show_validation_rules', $rules, $this);
    }
}

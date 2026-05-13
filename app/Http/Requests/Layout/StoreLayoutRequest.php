<?php

namespace App\Http\Requests\Layout;

use App\Extension\HookManager;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Rules\ComponentExists;
use App\Rules\NoExternalUrls;
use App\Rules\ValidLayoutStructure;
use App\Rules\WhitelistedEndpoint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 레이아웃 생성 요청 검증
 *
 * Custom Rules를 조합하여 악의적 레이아웃 JSON을 차단합니다.
 * Controller 진입 전 자동으로 검증이 수행됩니다.
 */
class StoreLayoutRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            // 템플릿 ID (필수)
            'template_id' => [
                'required',
                'integer',
                Rule::exists(Template::class, 'id'),
            ],

            // 레이아웃 이름 (필수)
            'name' => [
                'required',
                'string',
                'max:255',
                // 같은 템플릿 내에서 레이아웃 이름 중복 방지
                Rule::unique(TemplateLayout::class, 'name')
                    ->where('template_id', $this->input('template_id')),
            ],

            // 레이아웃 JSON 내용 (필수)
            'content' => [
                'required',
                'array',
                // 1. JSON 스키마 검증
                new ValidLayoutStructure,
                // 2. 컴포넌트 존재 여부 검증
                new ComponentExists,
                // 3. API endpoint 화이트리스트 검증
                new WhitelistedEndpoint,
                // 4. 외부 URL 차단
                new NoExternalUrls,
            ],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.layout.store_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // 템플릿 ID 검증 메시지
            'template_id.required' => __('validation.request.template_id.required'),
            'template_id.integer' => __('validation.request.template_id.integer'),
            'template_id.exists' => __('validation.request.template_id.exists'),

            // 레이아웃 이름 검증 메시지
            'name.required' => __('validation.request.layout_name.required'),
            'name.string' => __('validation.request.layout_name.string'),
            'name.max' => __('validation.request.layout_name.max', ['max' => 255]),
            'name.unique' => __('validation.request.layout_name.unique'),

            // 레이아웃 JSON 검증 메시지
            'content.required' => __('validation.request.content.required'),
            'content.array' => __('validation.request.content.array'),
        ];
    }
}

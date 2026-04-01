<?php

namespace App\Http\Requests\Layout;

use App\Extension\HookManager;
use App\Models\Template;
use App\Rules\ValidDataSourceMerge;
use App\Rules\ValidParentLayout;
use App\Rules\ValidSlotStructure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLayoutInheritanceRequest extends FormRequest
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
     */
    public function rules(): array
    {
        // 현재 레이아웃의 template_id를 요청 데이터에 추가 (Custom Rule에서 사용)
        $layout = $this->route('layout');
        if ($layout) {
            $this->merge(['template_id' => $layout->template_id]);
        }

        $rules = [
            // template_id는 라우트에서 가져온 레이아웃의 template_id 사용
            'template_id' => ['sometimes', 'integer', Rule::exists(Template::class, 'id')],

            // name 필드는 선택 (업데이트 시 변경 가능)
            'name' => ['sometimes', 'string', 'max:255'],

            // 레이아웃 content
            'content' => ['required', 'array'],
            'content.version' => ['required', 'string'],
            'content.layout_name' => ['required', 'string'],

            // 레이아웃 상속 관련 검증
            'content.extends' => ['nullable', 'string', new ValidParentLayout],
            'content.slots' => ['nullable', 'array', new ValidSlotStructure],
            'content.data_sources' => ['nullable', 'array', new ValidDataSourceMerge],

            // 메타 정보 (선택)
            'content.meta' => ['sometimes', 'array'],
            'content.meta.title' => ['sometimes', 'string'],
            'content.meta.description' => ['sometimes', 'string'],

            // 컴포넌트 (선택, extends 사용 시 없을 수 있음)
            'content.components' => ['sometimes', 'array'],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.layout.update_inheritance_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     */
    public function messages(): array
    {
        return [
            'template_id.integer' => __('validation.request.template_id.integer'),
            'template_id.exists' => __('validation.request.template_id.exists'),

            'name.string' => __('validation.request.layout_name.string'),
            'name.max' => __('validation.request.layout_name.max', ['max' => 255]),

            'content.required' => __('validation.request.content.required'),
            'content.array' => __('validation.request.content.array'),

            'content.version.required' => __('validation.layout.required_field_missing', ['field' => 'version']),
            'content.version.string' => __('validation.layout.version_must_be_string'),

            'content.layout_name.required' => __('validation.layout.required_field_missing', ['field' => 'layout_name']),
            'content.layout_name.string' => __('validation.layout.layout_name_must_be_string'),
        ];
    }
}

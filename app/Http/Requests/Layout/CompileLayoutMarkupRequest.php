<?php

namespace App\Http\Requests\Layout;

use App\Extension\HookManager;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 레이아웃·확장 마크업(`.g7.html`) 변환 전용 요청 검증 — 저장 없이 컴파일 결과(JSON·경고)만 돌려준다.
 *
 * 코드 편집기가 HTML 탭에서 타이핑이 멈춘 뒤(1초 유휴) JSON 읽기 전용 뷰를 갱신하려고 부른다.
 * 저장 경로(`format=markup`)와 같은 컴파일러를 타되 DB 를 건드리지 않으므로 권한은 `core.templates.read` 다.
 */
class CompileLayoutMarkupRequest extends FormRequest
{
    /**
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 항상 true (권한은 미들웨어 체인에서 처리)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'markup' => ['required', 'string'],
        ];

        // 모듈/플러그인이 validation rules 를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.layout.markup_compile_validation_rules', $rules, $this);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'markup.required' => __('validation.layout.markup.required'),
            'markup.string' => __('validation.layout.markup.string'),
        ];
    }
}

<?php

namespace App\Http\Requests\Layout;

use App\Extension\HookManager;
use App\Services\LayoutFileService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 레이아웃 **이름변경(이동)** 요청 검증.
 *
 * 이름 규칙은 생성과 같다({@see CreateLayoutFileRequest}) — 한쪽만 느슨하면 생성으로는 막히는
 * 이름이 이름변경으로 들어온다.
 *
 * `expected_lock_version` 을 의무화하는 이유: 이름변경은 본문의 `layout_name` 까지 함께 옮기므로
 * 내용을 건드리는 연산이다. 다른 창에서 저장이 일어난 뒤 이름을 바꾸면 그 저장분을 못 본 채
 * 덮어쓰게 되므로, 저장과 같은 낙관적 잠금을 지난다.
 */
class RenameLayoutRequest extends FormRequest
{
    /**
     * 권한은 라우트의 permission 미들웨어가 판정합니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 전 전처리 — 앞뒤 공백을 떼고 역슬래시를 슬래시로 맞춥니다.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $newName = $this->input('new_name');

        if (is_string($newName)) {
            $this->merge(['new_name' => trim(str_replace('\\', '/', $newName))]);
        }
    }

    /**
     * 검증 규칙.
     *
     * @return array<string, mixed> 규칙 배열
     */
    public function rules(): array
    {
        $rules = [
            'new_name' => [
                'required',
                'string',
                'max:255',
                'regex:'.LayoutFileService::NAME_PATTERN,
            ],

            'expected_lock_version' => ['required', 'integer', 'min:0'],
        ];

        return HookManager::applyFilters('core.layout.rename_validation_rules', $rules, $this);
    }

    /**
     * 검증 메시지.
     *
     * @return array<string, string> 속성별 메시지
     */
    public function messages(): array
    {
        return [
            'new_name.regex' => __('layouts.errors.invalid_name', ['name' => (string) $this->input('new_name', '')]),
        ];
    }
}

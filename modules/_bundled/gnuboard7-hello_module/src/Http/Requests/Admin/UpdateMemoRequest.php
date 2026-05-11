<?php

namespace Modules\Gnuboard7\HelloModule\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 메모 수정 요청
 *
 * 권한 검증은 라우트의 permission 미들웨어에서 수행됩니다.
 */
class UpdateMemoRequest extends FormRequest
{
    /**
     * 인증 허용 여부
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
        ];
    }

    /**
     * 검증 오류 메시지를 반환합니다.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => __('gnuboard7-hello_module::validation.title.required'),
            'title.max' => __('gnuboard7-hello_module::validation.title.max'),
            'content.required' => __('gnuboard7-hello_module::validation.content.required'),
        ];
    }
}

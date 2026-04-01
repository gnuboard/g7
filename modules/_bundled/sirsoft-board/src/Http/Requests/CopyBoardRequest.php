<?php

namespace Modules\Sirsoft\Board\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Board\Rules\SlugUniqueRule;

class CopyBoardRequest extends FormRequest
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
        return [
            'slug' => ['nullable', 'string', 'max:50', 'regex:/^[a-z][a-z0-9-]*$/', new SlugUniqueRule],
        ];
    }

    /**
     * 검증 오류 메시지 커스터마이징
     */
    public function messages(): array
    {
        return [
            'slug.regex' => __('sirsoft-board::validation.slug.format'),
            'slug.max' => __('sirsoft-board::validation.slug.max'),
        ];
    }
}

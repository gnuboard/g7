<?php

namespace Plugins\Sirsoft\Koreaexim\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** 상태 조회는 라우트 권한으로 보호하며 사용자 입력을 받지 않습니다. */
class StatusRequest extends FormRequest
{
    /** {@inheritDoc} */
    public function authorize(): bool
    {
        return true;
    }

    /** {@inheritDoc} */
    public function rules(): array
    {
        return [];
    }
}

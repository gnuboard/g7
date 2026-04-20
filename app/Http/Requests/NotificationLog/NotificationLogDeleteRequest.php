<?php

namespace App\Http\Requests\NotificationLog;

use Illuminate\Foundation\Http\FormRequest;

class NotificationLogDeleteRequest extends FormRequest
{
    /**
     * 권한 확인 (미들웨어에서 처리).
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
     * @return array
     */
    public function rules(): array
    {
        return [];
    }
}

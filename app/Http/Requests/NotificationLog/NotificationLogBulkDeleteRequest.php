<?php

namespace App\Http\Requests\NotificationLog;

use Illuminate\Foundation\Http\FormRequest;

class NotificationLogBulkDeleteRequest extends FormRequest
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
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ];
    }

    /**
     * 검증 메시지를 반환합니다.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'ids.required' => __('notification_log.no_items_selected'),
            'ids.min' => __('notification_log.no_items_selected'),
        ];
    }
}

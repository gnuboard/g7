<?php

namespace App\Http\Requests\Notification;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

class NotificationBatchReadRequest extends FormRequest
{
    /**
     * 권한 확인 (미들웨어에서 처리).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     */
    public function rules(): array
    {
        $rules = [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'string'],
        ];

        return HookManager::applyFilters(
            'core.notification.filter_batch_read_rules',
            $rules
        );
    }
}

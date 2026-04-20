<?php

namespace App\Http\Requests\Notification;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

class NotificationIndexRequest extends FormRequest
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
            'read' => ['nullable', 'string', 'in:unread,read,all'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'sort_order' => ['nullable', 'string', 'in:asc,desc'],
        ];

        return HookManager::applyFilters(
            'core.notification.filter_index_rules',
            $rules
        );
    }
}

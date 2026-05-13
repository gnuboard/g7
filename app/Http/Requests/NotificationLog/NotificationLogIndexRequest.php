<?php

namespace App\Http\Requests\NotificationLog;

use App\Enums\NotificationLogStatus;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationLogIndexRequest extends FormRequest
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
            'sender_user_id' => ['nullable', 'integer', Rule::exists(User::class, 'id')],
            'recipient_user_id' => ['nullable', 'integer', Rule::exists(User::class, 'id')],
            'search' => ['nullable', 'string', 'max:255'],
            'channel' => ['nullable', 'string', 'max:50'],
            'notification_type' => ['nullable', 'string', 'max:100'],
            'extension_type' => ['nullable', 'string', 'in:core,module,plugin'],
            'status' => ['nullable', 'string', Rule::enum(NotificationLogStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort_by' => ['nullable', 'string', 'in:id,channel,notification_type,status,sent_at,created_at'],
            'sort_order' => ['nullable', 'string', 'in:asc,desc'],
        ];

        return HookManager::applyFilters(
            'core.notification_log.filter_index_rules',
            $rules
        );
    }
}

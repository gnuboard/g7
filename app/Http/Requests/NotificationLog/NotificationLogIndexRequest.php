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
     *
     * @return bool 항상 true (권한은 라우트 permission 미들웨어가 담당)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙 배열
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
            // 허용 집합은 NotificationLogRepository::SORTABLE_COLUMNS 와 동일해야 한다.
            // 게이트가 더 좁으면 화면 정렬 셀렉트가 제공하는 옵션(수신자명순/제목순)이 422 로 막힌다.
            'sort_by' => ['nullable', 'string', 'in:id,channel,notification_type,status,sent_at,created_at,recipient_name,subject'],
            'sort_order' => ['nullable', 'string', 'in:asc,desc'],
            // 커서를 주면 목록이 키셋 방식으로 응답한다. 로그는 계속 쌓이기만 하므로 깊은
            // 페이지를 OFFSET 으로 훑으면 건너뛸 행을 실제로 읽어야 한다.
            // 형식이 깨진 값은 KeysetPaginator 가 첫 페이지로 되돌리므로 여기서는 길이만 본다.
            'cursor' => ['nullable', 'string', 'max:500'],
        ];

        return HookManager::applyFilters(
            'core.notification_log.index_validation_rules',
            $rules
        );
    }
}

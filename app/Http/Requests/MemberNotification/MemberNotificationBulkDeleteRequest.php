<?php

namespace App\Http\Requests\MemberNotification;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\Rule;

/**
 * 관리자 회원 알림함 일괄 삭제 요청
 *
 * 알림의 PK 는 **uuid 문자열**이다(Laravel 표준 notifications 테이블). 발송 이력의
 * integer 규칙을 복사하면 모든 요청이 422 로 막히므로 규칙을 그대로 베끼지 않는다.
 */
class MemberNotificationBulkDeleteRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인합니다.
     *
     * @return bool 항상 true (권한은 라우트 permission 미들웨어에서 검증)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'string', 'uuid', 'distinct', Rule::exists(DatabaseNotification::class, 'id')],
        ];

        return HookManager::applyFilters('core.member_notification.bulk_delete_validation_rules', $rules, $this);
    }
}

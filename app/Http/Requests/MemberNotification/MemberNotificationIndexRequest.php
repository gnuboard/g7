<?php

namespace App\Http\Requests\MemberNotification;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 관리자 회원 알림함 목록 조회 요청
 *
 * 회원이 «받은» 알림(in-app 수신함)을 전 회원 범위로 조회한다. 발송 이력
 * (notification_logs)과는 다른 축이다 — 그쪽은 "무엇을 보냈나", 이쪽은 "무엇이 남아 있나".
 *
 * search_type 은 **실제로 동작하는** 닫힌 집합이다. 발송 이력 화면의 셀렉트는 Request 규칙에
 * 없어 무시되는 장식이었는데(부록 B D7), 같은 실수를 반복하지 않도록 여기서 규칙으로 닫는다.
 */
class MemberNotificationIndexRequest extends FormRequest
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
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search_type' => ['nullable', 'string', 'in:all,recipient,subject,body'],
            'search' => ['nullable', 'string', 'max:255'],
            'read' => ['nullable', 'string', 'in:all,read,unread'],
            // exists 까지 닫는다 — 없는 회원 id 로 조회하면 «빈 알림함» 처럼 보여
            // 오탈자와 «정말 0건» 을 운영자가 구분할 수 없다.
            'user_id' => ['nullable', 'integer', 'min:1', Rule::exists(User::class, 'id')],
            'user_uuid' => ['nullable', 'string', 'uuid'],
            'created_from' => ['nullable', 'date_format:Y-m-d'],
            'created_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:created_from'],
            'sort_by' => ['nullable', 'string', 'in:created_at,read_at'],
            'sort_order' => ['nullable', 'string', 'in:asc,desc'],
        ];

        return HookManager::applyFilters('core.member_notification.index_validation_rules', $rules);
    }

    /**
     * 검증 오류 문구에 쓸 항목 이름
     *
     * 항목 이름이 없으면 422 문구에 `search type` 같은 내부 키가 그대로 나온다. 확장이 규칙 필터로
     * 파라미터를 더했다면 같은 키의 이름도 이 필터로 더한다(회원 목록 요청과 같은 짝).
     *
     * @return array<string, string> 항목 이름
     */
    public function attributes(): array
    {
        return HookManager::applyFilters('core.member_notification.index_validation_attributes', [
            'page' => __('member_notification.list_attributes.page'),
            'per_page' => __('member_notification.list_attributes.per_page'),
            'search_type' => __('member_notification.list_attributes.search_type'),
            'search' => __('member_notification.list_attributes.search'),
            'read' => __('member_notification.list_attributes.read'),
            'user_id' => __('member_notification.list_attributes.user_id'),
            'user_uuid' => __('member_notification.list_attributes.user_uuid'),
            'created_from' => __('member_notification.list_attributes.created_from'),
            'created_to' => __('member_notification.list_attributes.created_to'),
            'sort_by' => __('member_notification.list_attributes.sort_by'),
            'sort_order' => __('member_notification.list_attributes.sort_order'),
        ], $this);
    }
}

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
     * 다중 선택 필터의 스칼라 값을 배열로 승격합니다.
     *
     * 옛 링크·북마크(`?status=failed`)가 계속 동작하도록 한 값도 받는다. 빈 문자열은 "선택 없음"으로 지운다.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        foreach (['extension_type', 'status'] as $key) {
            if (! $this->has($key)) {
                continue;
            }

            $value = $this->input($key);

            if (is_array($value)) {
                $this->merge([$key => array_values(array_filter($value, fn ($v) => $v !== null && $v !== ''))]);
            } elseif ($value === null || $value === '') {
                $this->merge([$key => null]);
            } else {
                $this->merge([$key => [$value]]);
            }
        }
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
            // 검색대상 — 화면의 [전체/수신자/제목] 선택. 규칙이 없으면 validated() 가 버려
            // 어떤 대상을 골라도 전체 검색이 됐다.
            'search_type' => ['nullable', 'string', 'in:all,recipient,subject'],
            'channel' => ['nullable', 'string', 'max:50'],
            'notification_type' => ['nullable', 'string', 'max:100'],
            // 확장 유형·상태는 화면이 다중 선택으로 보낸다. 스칼라는 prepareForValidation 에서 배열로 승격한다.
            'extension_type' => ['nullable', 'array'],
            'extension_type.*' => ['string', 'in:core,module,plugin'],
            'status' => ['nullable', 'array'],
            'status.*' => ['string', Rule::enum(NotificationLogStatus::class)],
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

    /**
     * 검증 오류 문구에 쓸 필드 라벨을 반환합니다.
     *
     * @return array<string, string> 필드 → 라벨
     */
    public function attributes(): array
    {
        // 다중 선택 항목 하나하나(`status.1` 등)의 오류도 같은 라벨로 보인다
        return [
            'sender_user_id' => __('notification_log.attributes.sender_user_id'),
            'recipient_user_id' => __('notification_log.attributes.recipient_user_id'),
            'search' => __('notification_log.attributes.search'),
            'search_type' => __('notification_log.attributes.search_type'),
            'channel' => __('notification_log.attributes.channel'),
            'notification_type' => __('notification_log.attributes.notification_type'),
            'extension_type' => __('notification_log.attributes.extension_type'),
            'extension_type.*' => __('notification_log.attributes.extension_type'),
            'status' => __('notification_log.attributes.status'),
            'status.*' => __('notification_log.attributes.status'),
            'per_page' => __('notification_log.attributes.per_page'),
            'sort_by' => __('notification_log.attributes.sort_by'),
            'sort_order' => __('notification_log.attributes.sort_order'),
            'cursor' => __('notification_log.attributes.cursor'),
        ];
    }
}

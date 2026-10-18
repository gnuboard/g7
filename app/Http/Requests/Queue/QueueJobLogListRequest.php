<?php

namespace App\Http\Requests\Queue;

use App\Contracts\Repositories\QueueJobLogRepositoryInterface;
use App\Enums\QueueJobLogStatus;
use App\Extension\HookManager;
use App\Support\Query\PaginationLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 큐 작업 처리 기록 목록 조회 요청 (#17)
 *
 * 쿼리 키: search_field(all,job,error,uuid)·search_keyword·status(processed,failed)·
 * queue·connection·created_start_date/created_end_date·per_page·sort_by·sort_order.
 */
class QueueJobLogListRequest extends FormRequest
{
    /**
     * 요청 권한을 확인합니다.
     *
     * @return bool 항상 true (권한은 permission 미들웨어가 처리)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 유효성 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'page' => array_values(array_filter([
                'nullable', 'integer', 'min:1',
                ($maxPage = PaginationLimits::maxPage('admin.queue.logs')) !== null ? 'max:'.$maxPage : null,
            ])),
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search_field' => ['nullable', 'string', 'in:all,job,error,uuid'],
            'search_keyword' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:all,'.implode(',', QueueJobLogStatus::values())],
            'queue' => ['nullable', 'string', 'max:100'],
            'connection' => ['nullable', 'string', 'max:50'],
            'created_start_date' => ['nullable', 'date'],
            'created_end_date' => ['nullable', 'date', 'after_or_equal:created_start_date'],
            // 정렬 — 목록마다 정한 열만 받는다(지정이 없으면 최근순, 같은 값은 id 로 순서를 정한다)
            'sort_by' => ['nullable', 'string', Rule::in(QueueJobLogRepositoryInterface::SORT_COLUMNS)],
            'sort_order' => ['nullable', 'string', 'in:asc,desc'],
        ];

        return HookManager::applyFilters('core.queue_job.log_list_validation_rules', $rules, $this);
    }

    /**
     * 사용자 정의 유효성 검증 메시지를 반환합니다.
     *
     * @return array<string, string> 메시지
     */
    public function messages(): array
    {
        return QueueValidationMessages::list() + [
            'status.in' => __('queue.validation.status_invalid'),
            'created_start_date.date' => __('queue.validation.date_invalid'),
            'created_end_date.date' => __('queue.validation.date_invalid'),
            'created_end_date.after_or_equal' => __('queue.validation.date_range_invalid'),
        ];
    }

    /**
     * 검증 오류 문구에 쓰는 필드 라벨을 반환합니다.
     *
     * @return array<string, string> 필드 => 라벨
     */
    public function attributes(): array
    {
        return [
            'page' => __('queue.attributes.page'),
            'per_page' => __('queue.attributes.per_page'),
            'search_field' => __('queue.attributes.search_field'),
            'search_keyword' => __('queue.attributes.search_keyword'),
            'status' => __('queue.attributes.status'),
            'queue' => __('queue.attributes.queue'),
            'connection' => __('queue.attributes.connection'),
            'sort_by' => __('queue.attributes.sort_by'),
            'sort_order' => __('queue.attributes.sort_order'),
            'created_start_date' => __('queue.attributes.created_start_date'),
            'created_end_date' => __('queue.attributes.created_end_date'),
        ];
    }
}

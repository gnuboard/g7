<?php

namespace App\Http\Requests\Queue;

use App\Contracts\Repositories\JobQueueRepositoryInterface;
use App\Extension\HookManager;
use App\Support\Query\PaginationLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 큐 대기 작업 목록 조회 요청 (#17)
 *
 * 쿼리 키: search_field(all,job,uuid)·search_keyword·queue·connection·per_page·sort_by·sort_order.
 */
class PendingJobListRequest extends FormRequest
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
                ($maxPage = PaginationLimits::maxPage('admin.queue.pending')) !== null ? 'max:'.$maxPage : null,
            ])),
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search_field' => ['nullable', 'string', 'in:all,job,uuid'],
            'search_keyword' => ['nullable', 'string', 'max:255'],
            'queue' => ['nullable', 'string', 'max:100'],
            'connection' => ['nullable', 'string', 'max:50'],
            // 정렬 — 목록마다 정한 열만 받는다(지정이 없으면 최근순, 같은 값은 id 로 순서를 정한다)
            'sort_by' => ['nullable', 'string', Rule::in(JobQueueRepositoryInterface::PENDING_SORT_COLUMNS)],
            'sort_order' => ['nullable', 'string', 'in:asc,desc'],
        ];

        return HookManager::applyFilters('core.queue_job.pending_list_validation_rules', $rules, $this);
    }

    /**
     * 사용자 정의 유효성 검증 메시지를 반환합니다.
     *
     * @return array<string, string> 메시지
     */
    public function messages(): array
    {
        return QueueValidationMessages::list();
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
            'queue' => __('queue.attributes.queue'),
            'connection' => __('queue.attributes.connection'),
            'sort_by' => __('queue.attributes.sort_by'),
            'sort_order' => __('queue.attributes.sort_order'),
        ];
    }
}

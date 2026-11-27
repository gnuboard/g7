<?php

namespace App\Http\Requests\Queue;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 실패 작업 일괄 삭제 요청
 *
 * 요청당 최대 {@see BulkRetryFailedJobRequest::MAX_ITEMS} 건 — 초과하면 422.
 */
class BulkDeleteFailedJobRequest extends FormRequest
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
            'uuids' => ['required', 'array', 'min:1', 'max:'.BulkRetryFailedJobRequest::MAX_ITEMS],
            'uuids.*' => ['required', 'string', 'uuid', 'distinct'],
        ];

        return HookManager::applyFilters('core.queue_job.bulk_delete_validation_rules', $rules, $this);
    }

    /**
     * 사용자 정의 유효성 검증 메시지를 반환합니다.
     *
     * @return array<string, string> 메시지
     */
    public function messages(): array
    {
        return QueueValidationMessages::bulk();
    }

    /**
     * 검증 오류 문구에 쓰는 필드 라벨을 반환합니다.
     *
     * @return array<string, string> 필드 => 라벨
     */
    public function attributes(): array
    {
        return [
            'uuids' => __('queue.attributes.uuids'),
            'uuids.*' => __('queue.attributes.uuid'),
        ];
    }
}

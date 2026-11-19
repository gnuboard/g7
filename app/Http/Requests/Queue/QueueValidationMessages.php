<?php

namespace App\Http\Requests\Queue;

/**
 * 큐 관리 요청들이 공유하는 검증 메시지 (#17)
 *
 * 목록 3종·일괄 2종이 같은 키에 같은 문구를 쓰므로 한 곳에 둔다.
 */
final class QueueValidationMessages
{
    /**
     * 목록 요청 공통 메시지를 반환합니다.
     *
     * @return array<string, string> 메시지
     */
    public static function list(): array
    {
        return [
            'page.integer' => __('queue.validation.page_invalid'),
            'page.min' => __('queue.validation.page_invalid'),
            'page.max' => __('queue.validation.page_invalid'),
            'per_page.integer' => __('queue.validation.per_page_invalid'),
            'per_page.min' => __('queue.validation.per_page_invalid'),
            'per_page.max' => __('queue.validation.per_page_invalid'),
            'search_field.in' => __('queue.validation.search_field_invalid'),
            'search_keyword.max' => __('queue.validation.search_keyword_max'),
            'queue.max' => __('queue.validation.queue_invalid'),
            'connection.max' => __('queue.validation.connection_invalid'),
            'sort_by.in' => __('queue.validation.sort_by_invalid'),
            'sort_order.in' => __('queue.validation.sort_order_invalid'),
        ];
    }

    /**
     * 일괄 요청 공통 메시지를 반환합니다.
     *
     * @return array<string, string> 메시지
     */
    public static function bulk(): array
    {
        return [
            'uuids.required' => __('queue.validation.uuids_required'),
            'uuids.array' => __('queue.validation.uuids_required'),
            'uuids.min' => __('queue.validation.uuids_required'),
            'uuids.max' => __('queue.validation.uuids_max', ['max' => BulkRetryFailedJobRequest::MAX_ITEMS]),
            'uuids.*.required' => __('queue.validation.uuid_invalid'),
            'uuids.*.uuid' => __('queue.validation.uuid_invalid'),
            'uuids.*.distinct' => __('queue.validation.uuid_duplicated'),
        ];
    }
}

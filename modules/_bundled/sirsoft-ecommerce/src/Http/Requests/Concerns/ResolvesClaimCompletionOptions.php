<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Concerns;

use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;

/**
 * 완료 처리 옵션(D26) 요청 계약 공용 트레이트
 *
 * 세 진입점(단건 전이·일괄 전이·상품 단위 전이)이 같은 `completion` 페이로드 계약을 쓴다 —
 * 규칙·메시지·DTO 변환을 요청마다 복제하면 계약을 고칠 때 두 벌이 조용히 낡는다.
 */
trait ResolvesClaimCompletionOptions
{
    /**
     * `completion` 페이로드 검증 규칙을 반환합니다. (`status=completed` 일 때 필수)
     *
     * @return array<string, mixed> 검증 규칙 배열
     */
    protected function completionRules(): array
    {
        return [
            'completion' => ['required_if:status,completed', 'array'],
            'completion.restore_stock_by_item' => ['nullable', 'array'],
            'completion.restore_stock_by_item.*' => ['boolean'],
            'completion.create_refund_request' => ['required_with:completion', 'boolean'],
            // 선교환 「미회수로 완료」(D3) — 행 ID => 사유. 사유 없는 항목은 서비스가 거절한다.
            'completion.unreturned' => ['nullable', 'array'],
            'completion.unreturned.*' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * `completion` 페이로드 검증 메시지를 반환합니다.
     *
     * @return array<string, string> 메시지 배열
     */
    protected function completionMessages(): array
    {
        return [
            'completion.required_if' => __('sirsoft-ecommerce::validation.claims.completion.required_if'),
            'completion.array' => __('sirsoft-ecommerce::validation.claims.completion.array'),
            'completion.restore_stock_by_item.array' => __('sirsoft-ecommerce::validation.claims.completion.restore_stock_by_item_array'),
            'completion.restore_stock_by_item.*.boolean' => __('sirsoft-ecommerce::validation.claims.completion.restore_stock_by_item_boolean'),
            'completion.create_refund_request.required_with' => __('sirsoft-ecommerce::validation.claims.completion.create_refund_request_required'),
            'completion.create_refund_request.boolean' => __('sirsoft-ecommerce::validation.claims.completion.create_refund_request_boolean'),
            'completion.unreturned.array' => __('sirsoft-ecommerce::validation.claims.unreturned.array'),
            'completion.unreturned.*.string' => __('sirsoft-ecommerce::validation.claims.unreturned.reason_string'),
            'completion.unreturned.*.max' => __('sirsoft-ecommerce::validation.claims.unreturned.reason_max'),
        ];
    }

    /**
     * `completion` 페이로드의 필드 표시명을 반환합니다.
     *
     * 전용 메시지가 없는 규칙이 실패해도 필드 키 대신 칸 이름이 실리도록, 트레이트를 쓰는 요청은
     * `attributes()` 에서 이 목록을 합친다.
     *
     * @return array<string, string> 필드 키 → 표시명
     */
    protected function completionAttributes(): array
    {
        return [
            'completion' => __('sirsoft-ecommerce::validation.request_attributes.completion'),
            'completion.restore_stock_by_item' => __('sirsoft-ecommerce::validation.request_attributes.restore_stock_by_item'),
            'completion.restore_stock_by_item.*' => __('sirsoft-ecommerce::validation.request_attributes.restore_stock_item'),
            'completion.create_refund_request' => __('sirsoft-ecommerce::validation.request_attributes.create_refund_request'),
            'completion.unreturned' => __('sirsoft-ecommerce::validation.request_attributes.unreturned_reasons'),
            'completion.unreturned.*' => __('sirsoft-ecommerce::validation.request_attributes.unreturned_reasons'),
        ];
    }

    /**
     * 완료 처리 옵션을 반환합니다. (완료 전이가 아니면 null)
     *
     * @return ClaimCompletionOptions|null 완료 처리 옵션
     */
    public function completionOptions(): ?ClaimCompletionOptions
    {
        $completion = $this->validated('completion');

        if (! is_array($completion)) {
            return null;
        }

        $restoreByItem = [];

        foreach (($completion['restore_stock_by_item'] ?? []) as $itemId => $restore) {
            $restoreByItem[(int) $itemId] = (bool) $restore;
        }

        $unreturned = [];

        foreach (($completion['unreturned'] ?? []) as $itemId => $reason) {
            $unreturned[(int) $itemId] = (string) ($reason ?? '');
        }

        return new ClaimCompletionOptions(
            restoreStockByItem: $restoreByItem,
            createRefundRequest: (bool) ($completion['create_refund_request'] ?? false),
            unreturned: $unreturned,
        );
    }
}

<?php

namespace Modules\Sirsoft\Ecommerce\Services\Concerns;

/**
 * 추가옵션 선택을 서버에서 재조회해 동결 스냅샷과 단위당 합계로 바꾸는 공용 규칙.
 *
 * 주문(체크아웃)과 교환 신청이 같은 규칙을 써야 한다 — 한쪽만 고치면 같은 선택지가
 * 주문에서는 청구되고 교환에서는 청구되지 않는(또는 그 반대) 상태가 되는데, 둘 다
 * 정상 응답이라 어긋난 흔적이 남지 않는다.
 *
 * 이 trait 을 쓰는 클래스는 `$additionalOptionValueRepository`
 * (ProductAdditionalOptionValueRepositoryInterface) 를 보유해야 한다.
 */
trait ResolvesAdditionalOptions
{
    /**
     * 추가옵션 선택지를 서버에서 재조회해 합계와 동결 스냅샷을 만듭니다.
     *
     * 클라이언트가 보낸 가격은 신뢰하지 않는다 — `value_id` 로 다시 읽어 그 시점 값을
     * 동결한다. 선택지의 그룹이 그 상품 소속이 아니면 제외한다(D12) — 다른 상품의
     * 선택지 ID 로 금액이나 표기를 만들 수 없어야 한다.
     *
     * @param  int  $productId  소속을 검사할 상품 ID
     * @param  array  $selections  선택 목록 [{value_id, custom_text?}]
     * @return array{0: int, 1: array} [단위당 추가옵션 합계, 동결 스냅샷]
     */
    protected function resolveAdditionalOptions(int $productId, array $selections): array
    {
        if (empty($selections)) {
            return [0, []];
        }

        // value_id => custom_text 매핑 (직접입력 텍스트 동결용)
        $valueIds = [];
        $customTextByValueId = [];
        foreach ($selections as $selection) {
            $valueId = (int) ($selection['value_id'] ?? 0);
            if ($valueId > 0) {
                $valueIds[] = $valueId;
                $customText = trim((string) ($selection['custom_text'] ?? ''));
                if ($customText !== '') {
                    $customTextByValueId[$valueId] = $customText;
                }
            }
        }

        if (empty($valueIds)) {
            return [0, []];
        }

        $values = $this->additionalOptionValueRepository->findActiveByIds($valueIds);

        $total = 0;
        $snapshot = [];
        foreach ($values as $value) {
            // 소속 검증: 선택지의 그룹이 이 상품에 속해야 함 (D12)
            if ($value->additionalOption?->product_id !== $productId) {
                continue;
            }

            $total += $value->getPriceAdjustment();

            $entry = $value->toSnapshotArray();
            // 직접입력 텍스트 동결: allow_custom_text 선택지에 한해 주입 (E3)
            if ($value->allow_custom_text && isset($customTextByValueId[$value->id])) {
                $entry['custom_text'] = $customTextByValueId[$value->id];
            }
            $snapshot[] = $entry;
        }

        return [$total, $snapshot];
    }

    /**
     * 추가옵션 스냅샷에서 단위당 합계(기준 통화)를 산출합니다.
     *
     * @param  array  $snapshot  추가옵션 스냅샷 [{price_adjustment, ...}]
     * @return int 단위당 추가옵션 합계
     */
    protected function sumAdditionalOptionsSnapshot(array $snapshot): int
    {
        $total = 0;
        foreach ($snapshot as $entry) {
            $total += (int) ($entry['price_adjustment'] ?? 0);
        }

        return $total;
    }
}

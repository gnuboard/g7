<?php

namespace Modules\Sirsoft\Ecommerce\Support;

/**
 * 상품 단위 전이 지시의 정규화기 (교환·반품·환불 공용)
 *
 * 운영자가 "3개 중 2개만 승인" 을 지시하면 대상은 더 이상 행 ID 만으로 표현되지 않는다 —
 * `{id, quantity}` 짝이다. 그런데 수량을 싣지 않는 호출부(신청서 단위 완료 우회, 내부 훅,
 * 기존 테스트)가 남아 있어 서비스 경계는 두 모양을 모두 받는다.
 *
 * **HTTP 계약은 두 모양을 받지 않는다.** `BulkClaimOptionStatusRequest` 는 `items: [{id,
 * quantity}]` 만 받고 스칼라 배열은 422 로 거절한다 — 거기서 두 모양을 허용하면 `Rule::exists`
 * 스코프 분기가 둘이 되어 "상위 스코프 없는 존재 확인" 결함이 되살아난다. 여기서의 관용은
 * 그 계약 **뒤쪽**, 이미 검증을 통과한 값의 내부 표현에만 적용된다.
 */
class ClaimItemSpecs
{
    /**
     * 전이 지시를 `{id, quantity}` 목록으로 정규화합니다.
     *
     * 같은 id 가 두 번 오면 뒤엣것이 이깁니다 — 한 행을 두 줄로 보내면 각 줄이 따로 잔여를
     * 보고 통과한 뒤 합계가 넘으므로, HTTP 계약은 중복 자체를 422 로 거절한다. 여기서의
     * 병합은 내부 호출부가 같은 id 를 두 번 실었을 때 **두 번 전이하지 않게** 하는 방어다.
     *
     * @param  array<int, int|string|array{id?: int|string, quantity?: int|string|null}>  $items  전이 지시
     * @return array<int, array{id: int, quantity: int|null}> 정규화된 지시 (등장 순서 유지)
     */
    public static function normalize(array $items): array
    {
        $specs = [];

        foreach ($items as $entry) {
            $id = is_array($entry) ? ($entry['id'] ?? null) : $entry;

            if (! is_numeric($id)) {
                continue;
            }

            $quantity = is_array($entry) ? ($entry['quantity'] ?? null) : null;

            $specs[(int) $id] = [
                'id' => (int) $id,
                'quantity' => is_numeric($quantity) ? (int) $quantity : null,
            ];
        }

        return array_values($specs);
    }

    /**
     * 정규화된 지시에서 행 ID 만 뽑습니다.
     *
     * @param  array<int, array{id: int, quantity: int|null}>  $specs  정규화된 지시
     * @return array<int, int> 행 ID 목록
     */
    public static function ids(array $specs): array
    {
        return array_map(static fn (array $spec): int => $spec['id'], $specs);
    }

    /**
     * 정규화된 지시를 행 ID → 수량 맵으로 만듭니다.
     *
     * @param  array<int, array{id: int, quantity: int|null}>  $specs  정규화된 지시
     * @return array<int, int|null> 행 ID → 수량 (수량 미지정이면 null)
     */
    public static function quantities(array $specs): array
    {
        $map = [];

        foreach ($specs as $spec) {
            $map[$spec['id']] = $spec['quantity'];
        }

        return $map;
    }
}

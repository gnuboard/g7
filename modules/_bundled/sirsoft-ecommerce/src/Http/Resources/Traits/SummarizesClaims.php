<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources\Traits;

use Illuminate\Http\Resources\MissingValue;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;

/**
 * 주문의 클레임 요약(D22) 직렬화
 *
 * 관리자 목록('클레임' 컬럼)과 마이페이지 주문내역이 같은 형태를 쓴다 — 한쪽만 고치면 같은
 * 주문이 화면마다 다른 상태로 보이므로 배지 형태를 이 한 곳에서 만든다.
 */
trait SummarizesClaims
{
    /**
     * 유형별 최신 상태 배지 배열을 만듭니다. (D22 — '클레임' 컬럼)
     *
     * 로드 여부는 Repository 가 결정한다(`claimSummaryRelations()`) — 여기서 되읽지 않는다.
     * 관계가 로드되지 않았으면 필드를 통째로 뺀다. 항목은 접수 건이 있는 유형만 싣고,
     * 최신 건은 관계 정렬(id 역순)의 첫 행이다.
     *
     * @return array<int, array<string, mixed>>|MissingValue 유형별 배지 배열 또는 미충족 표식
     */
    protected function resolveClaimSummary(): array|MissingValue
    {
        $relationByType = [
            ClaimTypeEnum::EXCHANGE->value => ['exchanges', 'exchange_number', 'exchange_status', 'exchanges_count'],
            ClaimTypeEnum::RETURN->value => ['returns', 'return_number', 'return_status', 'returns_count'],
            ClaimTypeEnum::REFUND->value => ['refunds', 'refund_number', 'refund_status', 'refunds_count'],
            ClaimTypeEnum::CANCEL->value => ['cancels', 'cancel_number', 'cancel_status', 'cancels_count'],
        ];

        $loaded = array_filter(
            $relationByType,
            fn (array $spec) => $this->resource->relationLoaded($spec[0]),
        );

        if ($loaded === []) {
            return new MissingValue;
        }

        $entries = [];

        foreach ($loaded as $typeValue => [$relation, $numberField, $statusField, $countAlias]) {
            $list = $this->resource->{$relation};

            if ($list->isEmpty()) {
                continue;
            }

            $latest = $list->first();
            $status = $latest->{$statusField};
            $type = ClaimTypeEnum::from($typeValue);

            $entries[] = [
                'type' => $type->value,
                'type_label' => $type->label(),
                // 건수는 DB 집계를 쓰고, 별칭이 없는 경로만 컬렉션 길이로 떨어진다.
                // 별칭 유무는 값이 아니라 속성 키로 판정한다 — 0건과 "집계 안 함" 은
                // 값으로 구분되지 않는다 (`resolveOptionsCount()` 동형).
                'count' => $this->hasAggregate($countAlias)
                    ? (int) $this->aggregate($countAlias, 0)
                    : $list->count(),
                'latest_claim_number' => $latest->{$numberField},
                'status' => $status instanceof \BackedEnum ? $status->value : $status,
                'status_label' => $status instanceof \BackedEnum ? $status->label() : null,
                'status_variant' => $status instanceof \BackedEnum && method_exists($status, 'variant') ? $status->variant() : null,
            ];
        }

        return $entries;
    }
}

<?php

namespace Modules\Sirsoft\Ecommerce\Support;

/**
 * 배송정책 적용 스냅샷의 구조 SSoT.
 *
 * `ecommerce_orders.shipping_policy_applied_snapshot` 은 두 가지를 담는다 —
 * 옵션별 적용 정책 **목록**과 주문 시점 배송지 **메타**(국가/우편번호). 종전에는 이 둘을
 * 한 배열에 섞어(`$policies[] = ...` 뒤에 `$policies['address'] = ...`) 저장했다.
 * PHP 배열이 non-sequential 이 되면 `json_encode` 가 리스트가 아니라 객체
 * `{"0": {...}, "address": {...}}` 로 직렬화한다. 서버측 소비자는 `is_int($key)` 로
 * 관용 처리해 무사했지만, **프론트는 배열을 전제**하므로
 * `(snapshot ?? []).find(...)` 가 `TypeError: .find is not a function` 으로 죽었고
 * 마이페이지·비회원 주문 상세의 배송정책 표시 블록이 예외도 없이 통째로 사라졌다.
 *
 * 한 컬럼에 두 의미를 섞지 않도록 `{items: [...], address: {...}}` 로 분리하고,
 * 판별·변환 로직을 이 클래스 하나로 모은다. 구형 혼합 배열은 `normalize()` 가 흡수하므로
 * 백필 전 기존 주문도 같은 경로로 동작한다.
 *
 * @since 1.0.5
 */
class ShippingPolicySnapshot
{
    /** 항목 목록 키 */
    public const ITEMS = 'items';

    /** 배송지 메타 키 */
    public const ADDRESS = 'address';

    /**
     * 신형 스냅샷 구조를 생성합니다.
     *
     * @param  array  $items  옵션별 적용 정책 목록 (각 항목: product_option_id, policy)
     * @param  array  $address  배송지 메타 (country_code, zipcode)
     * @return array{items: array<int, array>, address: array} 신형 스냅샷
     */
    public static function make(array $items, array $address): array
    {
        return [
            self::ITEMS => array_values($items),
            self::ADDRESS => $address,
        ];
    }

    /**
     * 신형/구형 어느 형태든 신형 구조로 정규화합니다 (멱등).
     *
     * 구형은 정수 키에 항목이, `'address'` 문자열 키에 배송지가 섞여 있다.
     *
     * @param  array|null  $snapshot  원본 스냅샷 (신형 · 구형 · null 모두 허용)
     * @return array{items: array<int, array>, address: array} 신형 스냅샷
     */
    public static function normalize(?array $snapshot): array
    {
        if (empty($snapshot)) {
            return self::make([], []);
        }

        // 이미 신형이면 items 만 재인덱싱해 반환 (멱등)
        if (array_key_exists(self::ITEMS, $snapshot)) {
            return self::make(
                is_array($snapshot[self::ITEMS]) ? $snapshot[self::ITEMS] : [],
                is_array($snapshot[self::ADDRESS] ?? null) ? $snapshot[self::ADDRESS] : []
            );
        }

        $items = [];
        $address = [];

        foreach ($snapshot as $key => $entry) {
            if ($key === self::ADDRESS) {
                $address = is_array($entry) ? $entry : [];

                continue;
            }

            if (is_array($entry)) {
                $items[] = $entry;
            }
        }

        return self::make($items, $address);
    }

    /**
     * 스냅샷에서 배송지 메타만 추출합니다.
     *
     * @param  array|null  $snapshot  원본 스냅샷 (신형 · 구형 모두 허용)
     * @return array 배송지 메타 (country_code, zipcode)
     */
    public static function address(?array $snapshot): array
    {
        return self::normalize($snapshot)[self::ADDRESS];
    }

    /**
     * 주문이 실제로 배송된 배송지(국가/우편번호)를 해석합니다.
     *
     * SSoT 는 주문 생성 시점에 보존된 `address` 메타다. 주문 주소 행은 사후 변경·삭제될 수
     * 있으므로 스냅샷이 우선하며, 스냅샷에 배송지가 없는 과거 주문만 폴백을 쓴다.
     *
     * 이 판정은 취소 시점 스냅샷(`OrderCancellationService::buildShippingSnapshot`)과
     * 클레임 배송비의 도서산간 재판정(`ClaimShippingFeeService`)이 함께 쓴다 — 두 벌로 두면
     * 한쪽만 고쳐질 때 같은 주문의 도서산간 판정이 경로에 따라 갈린다.
     *
     * @param  array|null  $snapshot  원본 스냅샷 (신형 · 구형 모두 허용)
     * @param  callable|null  $fallback  스냅샷에 배송지가 없을 때만 호출되는 폴백 (=> array{country_code, zipcode})
     * @return array{country_code: string, zipcode: string|null} 배송지 메타
     */
    public static function deliveryAddress(?array $snapshot, ?callable $fallback = null): array
    {
        $address = self::address($snapshot);

        if (empty($address['country_code']) && $fallback !== null) {
            $address = $fallback() ?: [];
        }

        $zipcode = $address['zipcode'] ?? null;
        $countryCode = $address['country_code'] ?? null;

        return [
            'country_code' => strtoupper((string) ($countryCode ?: 'KR')),
            'zipcode' => ($zipcode !== null && $zipcode !== '') ? (string) $zipcode : null,
        ];
    }

    /**
     * 스냅샷에서 항목 목록만 추출합니다.
     *
     * @param  array|null  $snapshot  원본 스냅샷 (신형 · 구형 모두 허용)
     * @return array<int, array> 옵션별 적용 정책 목록
     */
    public static function items(?array $snapshot): array
    {
        return self::normalize($snapshot)[self::ITEMS];
    }

    /**
     * 옵션별 「그 상품만 샀을 때 배송비」 표시 칸만 새 값으로 바꾼 스냅샷을 돌려줍니다. (부분취소·재활성 재계산)
     *
     * 회원·비회원 주문 상세의 「적용 배송정책」이 이 칸을 읽는다 — 재계산이 배송 행만 다시 쓰고 이 칸을 주문 시점 값으로
     * 두면, 일부 취소로 조건부 무료배송 기준 아래가 된 상품도 「무료배송」으로 보인다. 그 밖의 필드(정책 규칙
     * `policy_snapshot`·훅 배송비 `hook_overridden`·`shipping_amount`·`policy_id`)는 다음 재계산의 입력이라 건드리지 않는다.
     * 맵에 없는 옵션(전량 취소 등)은 그대로 두고, 스냅샷의 형태(신형·구형)도 바꾸지 않는다.
     *
     * @param  array|null  $snapshot  원본 스냅샷 (신형 · 구형 모두 허용)
     * @param  array<int|string, int|float>  $standaloneByOptionId  product_option_id => 단독 배송비
     * @return array 표시 칸을 갱신한 스냅샷 (원본이 비면 빈 배열)
     */
    public static function withStandaloneAmounts(?array $snapshot, array $standaloneByOptionId): array
    {
        if (empty($snapshot)) {
            return [];
        }

        $apply = function (array $policy, int|float $amount): array {
            $policy['standalone_shipping_amount'] = (int) $amount;
            $policy['standalone_shipping_amount_formatted'] = ecommerce_format_price((int) $amount);

            return $policy;
        };

        if (array_key_exists(self::ITEMS, $snapshot)) {
            foreach ($snapshot[self::ITEMS] as $index => $entry) {
                $optionId = $entry['product_option_id'] ?? null;
                if ($optionId !== null && is_array($entry['policy'] ?? null) && array_key_exists($optionId, $standaloneByOptionId)) {
                    $snapshot[self::ITEMS][$index]['policy'] = $apply($entry['policy'], $standaloneByOptionId[$optionId]);
                }
            }

            return $snapshot;
        }

        // 구형 — `optionId => policy` 맵(배송지는 `address` 키)
        foreach ($snapshot as $key => $entry) {
            if ($key !== self::ADDRESS && is_array($entry) && array_key_exists($key, $standaloneByOptionId)) {
                $snapshot[$key] = $apply($entry, $standaloneByOptionId[$key]);
            }
        }

        return $snapshot;
    }

    /**
     * 스냅샷을 `product_option_id => policy` 맵으로 변환합니다.
     *
     * `OrderCalculationService::groupByShippingPolicy()` 가 `$snapshots[$optionId]` 로
     * 접근하므로 재계산 경로에서 필요하다. 테스트 헬퍼 등이 직접 구성한
     * `optionId => policy` 형태도 그대로 통과시킨다.
     *
     * @param  array|null  $snapshot  원본 스냅샷 (신형 · 구형 · 옵션 키 맵 모두 허용)
     * @return array<int|string, array> product_option_id => 정책 데이터
     */
    public static function toOptionPolicyMap(?array $snapshot): array
    {
        if (empty($snapshot)) {
            return [];
        }

        $map = [];

        // 신형이 아니면서 정수 키에 policy_id 를 직접 갖는 형태 = optionId => policy 맵
        if (! array_key_exists(self::ITEMS, $snapshot)) {
            foreach ($snapshot as $key => $entry) {
                if ($key === self::ADDRESS || ! is_array($entry)) {
                    continue;
                }

                if (! isset($entry['product_option_id'], $entry['policy'])) {
                    $map[$key] = $entry;
                }
            }
        }

        foreach (self::items($snapshot) as $entry) {
            if (isset($entry['product_option_id'], $entry['policy'])) {
                $map[$entry['product_option_id']] = $entry['policy'];
            }
        }

        return $map;
    }

    /**
     * 화면 표시에 필요한 필드만 남긴 스냅샷을 만듭니다.
     *
     * 주문 상세의 배송정책 블록(`partials/mypage/orders/_items.json`)이 읽는 것은
     * `product_option_id` 와 `policy.policy_name` · `policy.standalone_shipping_amount`
     * (+ 포맷) 뿐이다. 비회원 응답은 정책 id·계산 근거 같은 내부 값을 내보내지 않으면서도
     * 이 블록이 그려지도록 표시용 필드만 추린다.
     *
     * 배송지 메타(`address`)는 표시 대상이 아니므로 제외한다 — 화면은 별도 배송지 블록에서
     * 주문 주소를 쓴다.
     *
     * `items` 는 프론트가 `.find(...)` 로 순회하므로 **항상 리스트**여야 한다.
     *
     * @param  array|null  $snapshot  원본 스냅샷 (신형 · 구형 모두 허용)
     * @return array{items: array<int, array>} 표시용 스냅샷
     *
     * @since 1.0.5
     */
    public static function forDisplay(?array $snapshot): array
    {
        $items = [];

        foreach (self::items($snapshot) as $entry) {
            if (! isset($entry['product_option_id'], $entry['policy']) || ! is_array($entry['policy'])) {
                continue;
            }

            $policy = $entry['policy'];

            $items[] = [
                'product_option_id' => $entry['product_option_id'],
                'policy' => [
                    'policy_name' => $policy['policy_name'] ?? '',
                    'standalone_shipping_amount' => $policy['standalone_shipping_amount'] ?? 0,
                    'standalone_shipping_amount_formatted' => self::viewerFormattedAmount($policy),
                ],
            ];
        }

        return [self::ITEMS => $items];
    }

    /**
     * 응답용 신형 스냅샷 — 상품별 배송비 표기를 보는 사람의 언어로 다시 만듭니다.
     *
     * `standalone_shipping_amount_formatted` 는 주문을 만든 요청의 언어로 포맷되어 저장된다. 그대로 내보내면
     * 영어 화면에서 주문한 주문을 한국어로 열 때 이 줄만 「₩2,500」로 남는다(S3-D12). 저장된 스냅샷은 재계산의
     * 입력이므로 `normalize()` 는 그대로 두고, 응답을 만드는 이 경로에서만 금액 숫자로 다시 포맷한다.
     *
     * @param  array|null  $snapshot  원본 스냅샷 (신형 · 구형 · null 모두 허용)
     * @return array{items: array<int, array>, address: array} 표기를 갱신한 신형 스냅샷
     */
    public static function forViewer(?array $snapshot): array
    {
        $normalized = self::normalize($snapshot);

        foreach ($normalized[self::ITEMS] as $index => $entry) {
            if (is_array($entry['policy'] ?? null)) {
                $normalized[self::ITEMS][$index]['policy']['standalone_shipping_amount_formatted'] = self::viewerFormattedAmount($entry['policy']);
            }
        }

        return $normalized;
    }

    /**
     * 정책 항목의 단독 배송비를 현재 언어로 포맷합니다. 금액 숫자가 없으면 저장된 표기를 그대로 씁니다.
     *
     * @param  array  $policy  정책 항목
     * @return string 표기 문자열
     */
    private static function viewerFormattedAmount(array $policy): string
    {
        $amount = $policy['standalone_shipping_amount'] ?? null;

        if (! is_numeric($amount)) {
            return (string) ($policy['standalone_shipping_amount_formatted'] ?? '');
        }

        return ecommerce_format_price((int) $amount);
    }

    /**
     * 지정한 옵션 ID 집합에 해당하는 항목만 추립니다.
     *
     * @param  array|null  $snapshot  원본 스냅샷 (신형 · 구형 모두 허용)
     * @param  array<int, bool>  $optionIds  product_option_id => true 형태의 대상 집합
     * @return array<int, array> 추려진 항목 목록
     */
    public static function itemsForOptions(?array $snapshot, array $optionIds): array
    {
        $picked = [];

        foreach (self::items($snapshot) as $entry) {
            if (isset($entry['product_option_id'], $entry['policy'])
                && isset($optionIds[$entry['product_option_id']])) {
                $picked[] = $entry;
            }
        }

        return $picked;
    }
}

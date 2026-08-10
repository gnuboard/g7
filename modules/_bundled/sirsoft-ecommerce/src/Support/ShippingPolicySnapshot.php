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
                    'standalone_shipping_amount_formatted' => $policy['standalone_shipping_amount_formatted'] ?? '',
                ],
            ];
        }

        return [self::ITEMS => $items];
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

<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Support\ShippingPolicySnapshot;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 배송정책 스냅샷 구조 테스트
 *
 * `shipping_policy_applied_snapshot` 은 종전에 항목 리스트(정수 키)와 배송지 메타(`'address'`
 * 문자열 키)를 **한 배열에 섞어** 저장했다. PHP 배열이 non-sequential 이 되어 `json_encode` 가
 * 객체 `{"0": {...}, "address": {...}}` 로 직렬화했고, 그 결과 프론트의
 * `(snapshot ?? []).find(...)` 가 `TypeError: .find is not a function` 으로 죽어
 * 마이페이지/비회원 주문 상세의 **배송정책 표시 블록이 통째로 사라졌다**
 * (실측: 저장된 주문 18건 중 18건 전부 객체형).
 *
 * 이 테스트는 구조가 `{items: [...], address: {...}}` 로 분리되어 있고, JSON 왕복 후에도
 * `items` 가 **배열로 유지**되는지 잠근다. 구형 혼합 배열도 정규화로 흡수되어야 한다.
 */
class ShippingPolicySnapshotShapeTest extends ModuleTestCase
{
    /**
     * 신형 스냅샷은 JSON 왕복 후에도 items 가 배열(리스트)로 유지된다.
     */
    public function test_신형_스냅샷은_json_왕복_후에도_items_가_배열이다(): void
    {
        $snapshot = ShippingPolicySnapshot::make(
            [
                ['product_option_id' => 490, 'policy' => ['policy_id' => 1, 'policy_name' => '국내 무료배송']],
                ['product_option_id' => 491, 'policy' => ['policy_id' => 2, 'policy_name' => '조건부 무료']],
            ],
            ['country_code' => 'KR', 'zipcode' => '06234']
        );

        $decoded = json_decode(json_encode($snapshot), true);

        $this->assertArrayHasKey('items', $decoded);
        $this->assertArrayHasKey('address', $decoded);
        $this->assertSame([0, 1], array_keys($decoded['items']), 'items 는 0부터 연속된 리스트여야 한다');
        $this->assertSame('KR', $decoded['address']['country_code']);

        // JSON 문자열에서 items 가 배열 리터럴(`[`)로 시작해야 한다 — 객체(`{`)면 프론트 .find 가 죽는다
        $this->assertStringContainsString('"items":[', json_encode($snapshot));
    }

    /**
     * 항목이 없어도 items 는 객체가 아니라 빈 배열이어야 한다.
     */
    public function test_항목이_없어도_items_는_빈_배열이다(): void
    {
        $snapshot = ShippingPolicySnapshot::make([], ['country_code' => 'KR', 'zipcode' => null]);

        $this->assertStringContainsString('"items":[]', json_encode($snapshot));
    }

    /**
     * 구형(항목 + address 혼합) 스냅샷도 신형으로 정규화된다.
     */
    public function test_구형_혼합_배열은_신형으로_정규화된다(): void
    {
        $legacy = [
            0 => ['product_option_id' => 490, 'policy' => ['policy_id' => 1]],
            1 => ['product_option_id' => 491, 'policy' => ['policy_id' => 2]],
            'address' => ['country_code' => 'KR', 'zipcode' => '06234'],
        ];

        $normalized = ShippingPolicySnapshot::normalize($legacy);

        $this->assertCount(2, $normalized['items']);
        $this->assertSame(490, $normalized['items'][0]['product_option_id']);
        $this->assertSame('KR', $normalized['address']['country_code']);
        $this->assertStringContainsString('"items":[', json_encode($normalized));
    }

    /**
     * 신형 스냅샷을 다시 정규화해도 형태가 유지된다(멱등).
     */
    public function test_정규화는_멱등이다(): void
    {
        $modern = ShippingPolicySnapshot::make(
            [['product_option_id' => 490, 'policy' => ['policy_id' => 1]]],
            ['country_code' => 'JP', 'zipcode' => null]
        );

        $this->assertSame($modern, ShippingPolicySnapshot::normalize($modern));
    }

    /**
     * 빈 스냅샷/널도 안전하게 빈 구조를 반환한다.
     */
    public function test_빈_스냅샷은_빈_구조를_반환한다(): void
    {
        $normalized = ShippingPolicySnapshot::normalize([]);

        $this->assertSame([], $normalized['items']);
        $this->assertSame([], $normalized['address']);
    }

    /**
     * product_option_id 키 맵 변환은 신형/구형 양쪽에서 같은 결과를 낸다.
     */
    public function test_옵션_키_맵_변환은_신형_구형_동일_결과(): void
    {
        $legacy = [
            0 => ['product_option_id' => 490, 'policy' => ['policy_id' => 1, 'policy_name' => 'A']],
            'address' => ['country_code' => 'KR'],
        ];
        $modern = ShippingPolicySnapshot::normalize($legacy);

        $this->assertSame(
            ShippingPolicySnapshot::toOptionPolicyMap($legacy),
            ShippingPolicySnapshot::toOptionPolicyMap($modern)
        );
        $this->assertSame(
            ['policy_id' => 1, 'policy_name' => 'A'],
            ShippingPolicySnapshot::toOptionPolicyMap($modern)[490]
        );
    }
}

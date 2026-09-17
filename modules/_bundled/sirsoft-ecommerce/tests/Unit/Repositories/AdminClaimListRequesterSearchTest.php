<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Repositories;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderAddressFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnOptionFactory;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 클레임 목록 — 「신청자」 검색 (S3-D19)
 *
 * 주문자 이름은 주문 헤더가 아니라 **배송지 행**(`OrderAddress.orderer_name`)에 있다.
 * 필터가 `order` 관계에 직접 걸면 `ecommerce_orders.orderer_name` 을 찾다가 SQL 이 죽어
 * **목록 전체가 500** 이 된다. 화면에는 그것이 「검색 결과 없음」으로 보이므로 운영자는
 * 장애를 빈 결과로 오인한다 — 그래서 「0건이 아니라 맞는 행이 나온다」를 잠근다.
 */
class AdminClaimListRequesterSearchTest extends ModuleTestCase
{
    /**
     * 신청자 이름으로 검색하면 그 주문의 클레임만 나온다.
     *
     * 되돌리면(`whereHas('order', …)`) 이 테스트는 통과가 아니라 **SQL 예외로 죽는다** —
     * 「0건」이 아니라 「쿼리 불가」가 결함의 실제 모습이기 때문이다.
     *
     * @scenario claim_type=return
     *
     * @effects requester_search_reads_orderer_name_from_shipping_address
     */
    public function test_requester_search_matches_orderer_name_on_shipping_address(): void
    {
        $repository = app(OrderReturnRepositoryInterface::class);

        $mine = OrderFactory::new()->create();
        OrderAddressFactory::new()->create([
            'order_id' => $mine->id,
            'address_type' => 'shipping',
            'orderer_name' => 'MEAS599-TARGET',
        ]);
        OrderReturnFactory::new()->create(['order_id' => $mine->id]);

        $other = OrderFactory::new()->create();
        OrderAddressFactory::new()->create([
            'order_id' => $other->id,
            'address_type' => 'shipping',
            'orderer_name' => 'SOMEONE-ELSE',
        ]);
        OrderReturnFactory::new()->create(['order_id' => $other->id]);

        $found = $repository->paginateForAdmin([
            'search_field' => 'requester',
            'search_keyword' => 'MEAS599-TARGET',
        ]);

        $this->assertSame(1, $found->total(), '신청자 이름과 맞는 클레임 1건만 나와야 한다');
        $this->assertSame($mine->id, $found->items()[0]->order_id);
    }

    /**
     * 없는 이름은 **빈 결과**다 — 예외가 아니다.
     *
     * 이 축이 없으면 「항상 0건을 내는」 구현도 위 테스트를 통과시킬 수 있다.
     *
     * @scenario claim_type=return
     *
     * @effects requester_search_reads_orderer_name_from_shipping_address
     */
    public function test_requester_search_with_no_match_returns_empty_not_error(): void
    {
        $repository = app(OrderReturnRepositoryInterface::class);

        $order = OrderFactory::new()->create();
        OrderAddressFactory::new()->create([
            'order_id' => $order->id,
            'address_type' => 'shipping',
            'orderer_name' => 'MEAS599-TARGET',
        ]);
        OrderReturnFactory::new()->create(['order_id' => $order->id]);

        $found = $repository->paginateForAdmin([
            'search_field' => 'requester',
            'search_keyword' => '없는이름ZZZ',
        ]);

        $this->assertSame(0, $found->total());
    }

    /**
     * 상품명은 **한글로도 찾아진다** (S3-D20).
     *
     * `product_name` 은 다국어 JSON 컬럼이고 MySQL 이 비ASCII 를 유니코드 이스케이프로
     * 저장한다. 평문 `like` 로 훑으면 한글이 저장값과 영영 만나지 않아 **영문만 찾아진다** —
     * 한국어 쇼핑몰에서 상품명 검색이 사실상 죽는데 화면은 200 에 「결과 없음」만 낸다.
     * 그래서 **한글과 영문이 같은 건을 집는다**를 잠근다.
     *
     * @scenario claim_type=return
     *
     * @effects product_name_search_matches_korean_not_only_ascii
     */
    public function test_product_name_search_matches_korean_and_english_alike(): void
    {
        $repository = app(OrderReturnRepositoryInterface::class);

        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->create([
            'order_id' => $order->id,
            'product_name' => ['ko' => '스테인리스 텀블러 #34', 'en' => 'Stainless Tumbler #34'],
        ]);
        $return = OrderReturnFactory::new()->create(['order_id' => $order->id]);
        OrderReturnOptionFactory::new()->create([
            'order_return_id' => $return->id,
            'order_option_id' => $option->id,
        ]);

        $korean = $repository->paginateForAdmin([
            'search_field' => 'product_name',
            'search_keyword' => '텀블러',
        ]);
        $english = $repository->paginateForAdmin([
            'search_field' => 'product_name',
            'search_keyword' => 'Tumbler',
        ]);

        $this->assertSame(1, $korean->total(), '한글 상품명으로도 찾아져야 한다');
        $this->assertSame($english->total(), $korean->total(), '한글과 영문이 같은 건을 집어야 한다');
    }
}

<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Product;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 일괄 수정 부분 전송 가격 정합성 테스트 (E4)
 *
 * FormRequest 는 정가/판매가가 **둘 다** 전송된 경우에만 비교한다 — 일괄 수정은 한쪽만 보내는
 * 부분 전송이 흔해 양쪽 전송을 강제하면 정상 사용이 막히기 때문이다. 그 결과 한쪽만 보내면
 * 나머지는 DB 에 남아 있던 값이 그대로 적용되므로, 판매가만 정가 위로 올려 보내는 우회로가
 * 열려 있었다. 저장 직전 DB 기존값과 합친 조합으로 다시 판정하는지 검증한다.
 */
class BulkPricePartialUpdateTest extends ModuleTestCase
{
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.products.update',
        ]);
    }

    /**
     * 일괄 수정 엔드포인트 URL
     *
     * @return string 엔드포인트 URL
     */
    private function url(): string
    {
        return '/api/modules/sirsoft-ecommerce/admin/products/bulk-update';
    }

    /**
     * 판매가만 전송해 DB 정가를 넘기면 차단되는지 테스트합니다.
     *
     * @scenario entity=ec_product, payload=selling_only, relation=exceeds_stored_list
     *
     * @effects partial_price_rejected_422, price_unchanged_on_rejection
     */
    public function test_selling_price_only_cannot_exceed_stored_list_price(): void
    {
        $product = Product::factory()->create([
            'list_price' => 10000,
            'selling_price' => 9000,
        ]);

        $this->actingAs($this->adminUser)
            ->patchJson($this->url(), [
                'ids' => [$product->id],
                'items' => [
                    ['id' => $product->id, 'selling_price' => 20000],
                ],
            ])
            ->assertStatus(422);

        $this->assertSame(9000, (int) $product->fresh()->selling_price);
    }

    /**
     * 정가만 전송해 DB 판매가 아래로 내리면 차단되는지 테스트합니다.
     *
     * @scenario entity=ec_product, payload=list_only, relation=below_stored_selling
     *
     * @effects partial_price_rejected_422, price_unchanged_on_rejection
     */
    public function test_list_price_only_cannot_drop_below_stored_selling_price(): void
    {
        $product = Product::factory()->create([
            'list_price' => 10000,
            'selling_price' => 9000,
        ]);

        $this->actingAs($this->adminUser)
            ->patchJson($this->url(), [
                'ids' => [$product->id],
                'items' => [
                    ['id' => $product->id, 'list_price' => 5000],
                ],
            ])
            ->assertStatus(422);

        $this->assertSame(10000, (int) $product->fresh()->list_price);
    }

    /**
     * 관계를 지키는 부분 전송은 정상 반영되는지 테스트합니다.
     *
     * @scenario entity=ec_product, payload=selling_only, relation=within_stored_list
     *
     * @effects partial_price_applied_200
     */
    public function test_valid_partial_price_update_is_applied(): void
    {
        $product = Product::factory()->create([
            'list_price' => 10000,
            'selling_price' => 9000,
        ]);

        $this->actingAs($this->adminUser)
            ->patchJson($this->url(), [
                'ids' => [$product->id],
                'items' => [
                    ['id' => $product->id, 'selling_price' => 8000],
                ],
            ])
            ->assertStatus(200);

        $this->assertSame(8000, (int) $product->fresh()->selling_price);
    }
}

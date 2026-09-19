<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Listeners;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 회원 상세 쇼핑 데이터 요약 주입 리스너 테스트 (이슈 #524 §4.1)
 *
 * 검증 목적:
 * - 관리자 회원 상세(GET /api/admin/users/{uuid}) 응답에만 ecommerce_data 가 실린다
 * - 프로필(/api/me)에는 실리지 않는다 (부팅 경로마다 COUNT 4개를 돌리지 않는다)
 *
 * @scenario surface=admin_user_detail,actor=admin
 *
 * @effects admin_user_detail_includes_ecommerce_summary, profile_endpoint_omits_ecommerce_summary
 *
 * @group ecommerce
 * @group admin
 */
class UserEcommerceDataInfoListenerTest extends ModuleTestCase
{
    private User $admin;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        // 회원 상세 라우트는 코어 권한을 본다
        $this->admin = $this->createAdminUser(['core.users.read']);
        $this->member = $this->createUser();
    }

    /**
     * 관리자 회원 상세에 찜·장바구니·배송지·문의 건수가 실린다.
     *
     * @effects admin_user_detail_includes_ecommerce_summary
     */
    public function test_admin_user_detail_includes_ecommerce_data(): void
    {
        $product = Product::factory()->create();

        DB::table('ecommerce_product_wishlists')->insert([
            'user_id' => $this->member->id,
            'product_id' => $product->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/admin/users/'.$this->member->uuid);

        $response->assertStatus(200);
        $response->assertJsonPath('data.ecommerce_data.wishlist_count', 1);
        // 나머지 축도 키가 존재해야 화면이 0 을 그릴 수 있다
        foreach (['cart_count', 'address_count', 'inquiry_count'] as $key) {
            $this->assertArrayHasKey($key, $response->json('data.ecommerce_data'));
        }
    }

    /**
     * 문의는 **미답변 건수**를 따로 싣는다.
     *
     * 전체 건수만으로는 「지금 답해야 할 것이 있는가」 를 알 수 없다 — 탭에 그 수를 띄우기
     * 위해 집계가 갈라져 있어야 한다.
     *
     * @effects admin_user_detail_includes_pending_inquiry_count
     */
    public function test_admin_user_detail_includes_pending_inquiry_count(): void
    {
        $product = Product::factory()->create();

        ProductInquiry::factory()->create([
            'user_id' => $this->member->id,
            'product_id' => $product->id,
            'is_answered' => true,
        ]);
        ProductInquiry::factory()->create([
            'user_id' => $this->member->id,
            'product_id' => $product->id,
            'is_answered' => false,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/admin/users/'.$this->member->uuid);

        $response->assertStatus(200);
        $response->assertJsonPath('data.ecommerce_data.inquiry_count', 2);
        $response->assertJsonPath('data.ecommerce_data.inquiry_pending_count', 1);
    }

    /**
     * 프로필 응답에는 싣지 않는다.
     *
     * @effects profile_endpoint_omits_ecommerce_summary
     */
    public function test_profile_endpoint_omits_ecommerce_data(): void
    {
        $response = $this->actingAs($this->member)->getJson('/api/me');

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('ecommerce_data', $response->json('data') ?? []);
    }
}

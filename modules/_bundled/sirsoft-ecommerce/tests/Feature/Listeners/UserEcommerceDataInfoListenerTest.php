<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Listeners;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 회원 상세 쇼핑 데이터 요약 주입 리스너 테스트 (이슈 #524 §4.1)
 *
 * 검증 목적:
 * - 관리자 회원 상세(GET /api/admin/users/{uuid}) 응답에만 ecommerce_data 가 실린다
 * - 프로필(/api/me)에는 «건수» 가 실리지 않는다 (부팅 경로마다 COUNT 4개를 돌리지 않는다)
 * - 관리자 상세는 배송지 «주소 줄» 도 함께 싣고, 프로필은 기본 배송지 한 줄만 싣는다
 *   (2026-09-21 검수 지적 — 건수만으로는 「어디로 보내는가」 를 알 수 없다)
 *
 * @scenario surface=admin_user_detail,actor=admin
 *
 * @effects admin_user_detail_includes_ecommerce_summary, profile_endpoint_omits_ecommerce_summary
 */
#[Group('ecommerce')]
#[Group('admin')]
class UserEcommerceDataInfoListenerTest extends ModuleTestCase
{
    private User $admin;

    private User $member;

    /**
     * 테스트 픽스처를 준비합니다.
     *
     * @return void 반환값 없음
     */
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     * 관리자 상세는 저장된 배송지의 «주소 줄» 도 함께 싣는다.
     *
     * 건수만으로는 「어디로 보내는 회원인가」 를 알 수 없어, 상담 중에 배송지 관리 화면으로
     * 나갔다 돌아오면 그 화면의 조회 조건을 잃는다. 기본 배송지가 맨 앞이다.
     *
     * @scenario list=user_address,member_scope=true
     *
     * @effects admin_user_detail_includes_saved_address_lines
     *
     * @return void 반환값 없음
     */
    public function test_admin_user_detail_includes_saved_address_lines(): void
    {
        UserAddress::factory()->create([
            'user_id' => $this->member->id,
            'recipient_name' => '보조수령인',
            'is_default' => false,
        ]);
        UserAddress::factory()->create([
            'user_id' => $this->member->id,
            'recipient_name' => '기본수령인',
            'country_code' => 'KR',
            'zipcode' => '06134',
            'address' => '서울시 강남구 테헤란로 1',
            'address_detail' => '2층',
            'is_default' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/admin/users/'.$this->member->uuid);

        $response->assertStatus(200);
        $response->assertJsonPath('data.ecommerce_data.address_count', 2);
        $response->assertJsonPath('data.ecommerce_data.addresses.0.recipient_name', '기본수령인');
        $response->assertJsonPath('data.ecommerce_data.addresses.0.is_default', true);
        $this->assertStringContainsString(
            '06134',
            $response->json('data.ecommerce_data.addresses.0.address_summary')
        );
    }

    /**
     * 프로필 응답에는 건수를 싣지 않고, 기본 배송지 한 줄만 싣는다.
     *
     * @effects profile_endpoint_omits_ecommerce_summary, profile_endpoint_includes_default_address
     *
     * @return void 반환값 없음
     */
    public function test_profile_endpoint_omits_ecommerce_data(): void
    {
        $response = $this->actingAs($this->member)->getJson('/api/me');

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('ecommerce_data', $response->json('data') ?? []);
        // 등록한 배송지가 없으면 null 이다 — 화면이 「아직 없음」과 「기능 없음」을 구별한다.
        $this->assertArrayHasKey('ecommerce_default_address', $response->json('data') ?? []);
        $this->assertNull($response->json('data.ecommerce_default_address'));
    }

    /**
     * 내 정보 화면이 「어디로 받는가」 를 말할 수 있도록 기본 배송지 한 줄을 싣는다.
     *
     * @scenario list=user_address,member_scope=false
     *
     * @effects profile_endpoint_includes_default_address
     *
     * @return void 반환값 없음
     */
    public function test_profile_endpoint_includes_default_address(): void
    {
        UserAddress::factory()->create([
            'user_id' => $this->member->id,
            'recipient_name' => '기본수령인',
            'country_code' => 'KR',
            'zipcode' => '06134',
            'address' => '서울시 강남구 테헤란로 1',
            'is_default' => true,
        ]);

        $response = $this->actingAs($this->member)->getJson('/api/me');

        $response->assertStatus(200);
        $response->assertJsonPath('data.ecommerce_default_address.recipient_name', '기본수령인');
        $response->assertJsonPath('data.ecommerce_default_address.is_default', true);
    }
}

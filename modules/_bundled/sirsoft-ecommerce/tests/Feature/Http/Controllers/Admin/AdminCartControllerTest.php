<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Database\Factories\CartFactory;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 관리자 장바구니 관리 API Feature 테스트 (이슈 #524 §4.1)
 *
 * 검증 목적 — 회원이 만든 데이터인데 관리자 표면이 없던 축을 채운다:
 * - 조회와 삭제의 권한이 갈린다
 * - 회원 상세에서 넘어온 user_uuid 필터, 없는 uuid 는 빈 결과(전체 노출 금지)
 * - 검색 대상 구분(member / member_id / email …)
 * - 일괄 삭제 시 관리자 활동 로그 기록
 *
 * URL:
 * - GET    /api/modules/sirsoft-ecommerce/admin/carts
 * - DELETE /api/modules/sirsoft-ecommerce/admin/carts/{id}
 * - DELETE /api/modules/sirsoft-ecommerce/admin/carts/bulk
 *
 * @scenario surface=admin_cart_list,actor=admin
 *
 * @effects admin_cart_requires_read_permission, admin_cart_bulk_delete_writes_activity_log
 */
#[Group('ecommerce')]
#[Group('admin')]
#[Group('cart')]
class AdminCartControllerTest extends ModuleTestCase
{
    private string $apiBase = '/api/modules/sirsoft-ecommerce/admin/carts';

    private User $admin;

    private User $readOnlyAdmin;

    private User $memberA;

    private User $memberB;

    private Product $product;

    /**
     * 각 테스트 전에 공통 준비를 합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.carts.read',
            'sirsoft-ecommerce.carts.manage',
        ]);
        // ModuleTestCase 는 사용자마다 고유 역할을 만들므로 "조회만" 이 정확히 재현된다
        $this->readOnlyAdmin = $this->createAdminUser(['sirsoft-ecommerce.carts.read']);

        $this->memberA = $this->createUser();
        $this->memberB = $this->createUser();
        $this->product = Product::factory()->create();
    }

    /**
     * 조회 권한 없는 관리자 → 403.
     *
     * @effects admin_cart_requires_read_permission
     *
     * @return void 반환값 없음
     */
    public function test_index_requires_read_permission(): void
    {
        $noPermission = $this->createAdminUser([]);

        $this->actingAs($noPermission)->getJson($this->apiBase)->assertStatus(403);
    }

    /**
     * 목록은 페이지네이션과 abilities 를 함께 낸다 (화면이 삭제 버튼 상태를 정할 근거).
     *
     * @effects admin_cart_returns_pagination_and_abilities
     *
     * @return void 반환값 없음
     */
    public function test_index_returns_pagination_and_abilities(): void
    {
        $this->seedRow($this->memberA);

        $response = $this->actingAs($this->admin)->getJson($this->apiBase);

        $response->assertStatus(200);
        $response->assertJsonPath('data.abilities.can_manage', true);
        $this->assertIsArray($response->json('data.pagination'));
        $this->assertSame(1, $response->json('data.pagination.total'));
    }

    /**
     * 삭제 권한 없는 관리자는 목록은 보되 can_manage=false 로 받는다.
     *
     * @effects admin_cart_read_only_cannot_manage
     *
     * @return void 반환값 없음
     */
    public function test_index_read_only_admin_gets_can_manage_false(): void
    {
        $this->seedRow($this->memberA);

        $this->actingAs($this->readOnlyAdmin)
            ->getJson($this->apiBase)
            ->assertStatus(200)
            ->assertJsonPath('data.abilities.can_manage', false);
    }

    /**
     * user_uuid 필터 — 없는 uuid 는 빈 결과여야 한다 (필터 무시 후 전체 노출 금지).
     *
     * @effects admin_cart_filters_by_user_uuid, admin_cart_unknown_uuid_returns_empty
     *
     * @return void 반환값 없음
     */
    public function test_index_filters_by_user_uuid(): void
    {
        $mine = $this->seedRow($this->memberA);
        $this->seedRow($this->memberB);

        $this->actingAs($this->admin)
            ->getJson($this->apiBase.'?user_uuid='.$this->memberA->uuid)
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $mine);

        $this->actingAs($this->admin)
            ->getJson($this->apiBase.'?user_uuid=00000000-0000-4000-8000-000000000000')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.data');
    }

    /**
     * 검색 대상 분기 — member(이름) / email / member_id(uuid).
     *
     * @effects admin_cart_search_field_branches
     *
     * @return void 반환값 없음
     */
    public function test_index_search_field_branches(): void
    {
        $mine = $this->seedRow($this->memberA);
        $this->seedRow($this->memberB);

        foreach ([
            'member' => $this->memberA->name,
            'email' => $this->memberA->email,
            'member_id' => $this->memberA->uuid,
        ] as $field => $keyword) {
            $this->actingAs($this->admin)
                ->getJson($this->apiBase.'?search_field='.$field.'&search_keyword='.urlencode($keyword))
                ->assertStatus(200)
                ->assertJsonCount(1, 'data.data')
                ->assertJsonPath('data.data.0.id', $mine, "search_field={$field} 분기가 어긋났다");
        }
    }

    /**
     * 삭제 권한 없는 관리자의 삭제 → 403.
     *
     * @effects admin_cart_destroy_requires_manage_permission
     *
     * @return void 반환값 없음
     */
    public function test_destroy_requires_manage_permission(): void
    {
        $id = $this->seedRow($this->memberA);

        $this->actingAs($this->readOnlyAdmin)
            ->deleteJson($this->apiBase.'/'.$id)
            ->assertStatus(403);

        $this->assertDatabaseHas('ecommerce_carts', ['id' => $id]);
    }

    /**
     * 일괄 삭제 → 건수 반환 + 행마다 관리자 활동 로그.
     *
     * @effects admin_cart_bulk_delete_writes_activity_log
     *
     * @return void 반환값 없음
     */
    public function test_bulk_destroy_deletes_and_writes_activity_log(): void
    {
        $first = $this->seedRow($this->memberA);
        $second = $this->seedRow($this->memberB);

        $before = (int) DB::table('activity_logs')->where('action', 'cart.bulk_delete')->count();

        $response = $this->actingAs($this->admin)
            ->deleteJson($this->apiBase.'/bulk', ['ids' => [$first, $second]]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.deleted_count', 2);

        $after = (int) DB::table('activity_logs')->where('action', 'cart.bulk_delete')->count();
        $this->assertSame($before + 2, $after, '일괄 삭제는 행마다 활동 로그를 남겨야 한다');

        // 장바구니는 소프트 삭제가 아니라 지우고 나면 행이 사라진다 — 로그에 id 만 남으면
        // 「누구의 무엇을 지웠는가」를 되짚을 수 없다. 삭제 전 스냅샷이 실렸는지 잰다.
        $properties = (array) json_decode(
            (string) DB::table('activity_logs')
                ->where('action', 'cart.bulk_delete')
                ->orderByDesc('id')
                ->value('properties'),
            true
        );

        $this->assertArrayHasKey('user_uuid', $properties, '삭제된 장바구니의 회원 식별자가 로그에 남아야 한다');
        $this->assertArrayHasKey('product_name', $properties, '삭제된 장바구니의 상품명이 로그에 남아야 한다');
    }

    /**
     * owner=guest → 비회원 장바구니만 나온다.
     *
     * 회원 검색과 같은 칸에 섞으면 "회원 검색으로 비회원 행이 사라지는" 혼란이 나므로
     * 축을 따로 둔 것이 이 분기의 근거다.
     *
     * @effects admin_cart_owner_filter_guest_only
     *
     * @return void 반환값 없음
     */
    public function test_index_owner_filter_guest_only(): void
    {
        $this->seedRow($this->memberA);
        $guest = $this->seedRow(null);

        $response = $this->actingAs($this->admin)->getJson($this->apiBase.'?owner=guest');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.data');
        $response->assertJsonPath('data.data.0.id', $guest);
        $response->assertJsonPath('data.data.0.owner', 'guest');
    }

    /**
     * owner=member → 회원 장바구니만 나온다.
     *
     * @effects admin_cart_owner_filter_member_only
     *
     * @return void 반환값 없음
     */
    public function test_index_owner_filter_member_only(): void
    {
        $mine = $this->seedRow($this->memberA);
        $this->seedRow(null);

        $response = $this->actingAs($this->admin)->getJson($this->apiBase.'?owner=member');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.data');
        $response->assertJsonPath('data.data.0.id', $mine);
        $response->assertJsonPath('data.data.0.owner', 'member');
    }

    /**
     * 장바구니 행을 시드하고 ID 를 반환합니다.
     *
     * @param  User|null  $user  담은 회원 (null 이면 비회원 장바구니)
     * @return int 장바구니 행 ID
     */
    private function seedRow(?User $user): int
    {
        $cart = $user !== null
            ? CartFactory::new()->create(['user_id' => $user->id, 'product_id' => $this->product->id])
            : CartFactory::new()->create(['user_id' => null, 'cart_key' => 'guest-'.uniqid(), 'product_id' => $this->product->id]);

        return (int) $cart->id;
    }
}

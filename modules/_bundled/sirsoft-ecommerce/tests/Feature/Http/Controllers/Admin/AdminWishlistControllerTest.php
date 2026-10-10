<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 관리자 찜 관리 API Feature 테스트 (이슈 #524 §4.1)
 *
 * 검증 목적 — 회원이 만든 데이터인데 관리자 표면이 없던 축을 채운다:
 * - 조회는 wishlists.read, 삭제는 wishlists.manage (권한 분리)
 * - 회원 상세에서 넘어온 user_uuid 필터, 없는 uuid 는 빈 결과(전체 노출 금지)
 * - 검색 대상 구분(member / member_id / email / product)
 * - 일괄 삭제 시 관리자 활동 로그 기록
 *
 * URL:
 * - GET    /api/modules/sirsoft-ecommerce/admin/wishlists
 * - DELETE /api/modules/sirsoft-ecommerce/admin/wishlists/{id}
 * - DELETE /api/modules/sirsoft-ecommerce/admin/wishlists/bulk
 *
 * @scenario surface=admin_wishlist_list,actor=admin
 *
 * @effects admin_wishlist_requires_read_permission, admin_wishlist_bulk_delete_writes_activity_log
 */
#[Group('ecommerce')]
#[Group('admin')]
#[Group('wishlist')]
class AdminWishlistControllerTest extends ModuleTestCase
{
    private string $apiBase = '/api/modules/sirsoft-ecommerce/admin/wishlists';

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
            'sirsoft-ecommerce.wishlists.read',
            'sirsoft-ecommerce.wishlists.manage',
        ]);
        // ModuleTestCase 는 사용자마다 고유 역할을 만들므로 "조회만" 이 정확히 재현된다
        $this->readOnlyAdmin = $this->createAdminUser(['sirsoft-ecommerce.wishlists.read']);

        $this->memberA = $this->createUser();
        $this->memberB = $this->createUser();
        $this->product = Product::factory()->create();
    }

    /**
     * read 권한 없는 관리자 → 403.
     *
     * @effects admin_wishlist_requires_read_permission
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
     * @effects admin_wishlist_returns_pagination_and_abilities
     *
     * @return void 반환값 없음
     */
    public function test_index_returns_pagination_and_abilities(): void
    {
        $this->seedWishlist($this->memberA);

        $response = $this->actingAs($this->admin)->getJson($this->apiBase);

        $response->assertStatus(200);
        $response->assertJsonPath('data.abilities.can_manage', true);
        $this->assertIsArray($response->json('data.pagination'));
        $this->assertSame(1, $response->json('data.pagination.total'));
    }

    /**
     * read 만 있는 관리자는 목록은 보되 can_manage=false 로 받는다.
     *
     * @effects admin_wishlist_read_only_cannot_manage
     *
     * @return void 반환값 없음
     */
    public function test_index_read_only_admin_gets_can_manage_false(): void
    {
        $this->seedWishlist($this->memberA);

        $this->actingAs($this->readOnlyAdmin)
            ->getJson($this->apiBase)
            ->assertStatus(200)
            ->assertJsonPath('data.abilities.can_manage', false);
    }

    /**
     * user_uuid 필터 — 없는 uuid 는 빈 결과여야 한다 (필터 무시 후 전체 노출 금지).
     *
     * @effects admin_wishlist_filters_by_user_uuid, admin_wishlist_unknown_uuid_returns_empty
     *
     * @return void 반환값 없음
     */
    public function test_index_filters_by_user_uuid(): void
    {
        $mine = $this->seedWishlist($this->memberA);
        $this->seedWishlist($this->memberB);

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
     * 검색 대상 분기 — member(이름) / email / member_id(uuid) / product(상품명).
     *
     * @effects admin_wishlist_search_field_branches
     *
     * @return void 반환값 없음
     */
    public function test_index_search_field_branches(): void
    {
        $mine = $this->seedWishlist($this->memberA);
        $this->seedWishlist($this->memberB);

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
     * manage 권한 없는 관리자의 삭제 → 403.
     *
     * @effects admin_wishlist_destroy_requires_manage_permission
     *
     * @return void 반환값 없음
     */
    public function test_destroy_requires_manage_permission(): void
    {
        $id = $this->seedWishlist($this->memberA);

        $this->actingAs($this->readOnlyAdmin)
            ->deleteJson($this->apiBase.'/'.$id)
            ->assertStatus(403);

        $this->assertDatabaseHas('ecommerce_product_wishlists', ['id' => $id]);
    }

    /**
     * 일괄 삭제 → 건수 반환 + 행마다 관리자 활동 로그.
     *
     * @effects admin_wishlist_bulk_delete_writes_activity_log
     *
     * @return void 반환값 없음
     */
    public function test_bulk_destroy_deletes_and_writes_activity_log(): void
    {
        $first = $this->seedWishlist($this->memberA);
        $second = $this->seedWishlist($this->memberB);

        $before = (int) DB::table('activity_logs')->where('action', 'wishlist.bulk_delete')->count();

        $response = $this->actingAs($this->admin)
            ->deleteJson($this->apiBase.'/bulk', ['ids' => [$first, $second]]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.deleted_count', 2);

        $this->assertDatabaseMissing('ecommerce_product_wishlists', ['id' => $first]);
        $this->assertDatabaseMissing('ecommerce_product_wishlists', ['id' => $second]);

        $after = (int) DB::table('activity_logs')->where('action', 'wishlist.bulk_delete')->count();
        $this->assertSame($before + 2, $after, '일괄 삭제는 행마다 활동 로그를 남겨야 한다');

        // 찜은 소프트 삭제가 아니라 지우고 나면 행이 사라진다 — 로그에 id 만 남으면
        // 「누구의 무엇을 지웠는가」를 되짚을 수 없다. 삭제 전 스냅샷이 실렸는지 잰다.
        $properties = (array) json_decode(
            (string) DB::table('activity_logs')
                ->where('action', 'wishlist.bulk_delete')
                ->orderByDesc('id')
                ->value('properties'),
            true
        );

        $this->assertArrayHasKey('user_uuid', $properties, '삭제된 찜의 회원 식별자가 로그에 남아야 한다');
        $this->assertNotNull($properties['user_uuid'], '회원 식별자가 비어 있으면 추적이 불가능하다');
        $this->assertArrayHasKey('product_name', $properties, '삭제된 찜의 상품명이 로그에 남아야 한다');
    }

    /**
     * 존재하지 않는 ID 가 섞이면 422 (목록이 낡았음을 드러낸다).
     *
     * @effects admin_wishlist_bulk_destroy_rejects_unknown_ids
     *
     * @return void 반환값 없음
     */
    public function test_bulk_destroy_rejects_unknown_ids(): void
    {
        $id = $this->seedWishlist($this->memberA);

        $this->actingAs($this->admin)
            ->deleteJson($this->apiBase.'/bulk', ['ids' => [$id, 999999]])
            ->assertStatus(422);

        $this->assertDatabaseHas('ecommerce_product_wishlists', ['id' => $id]);
    }

    /**
     * 찜 행을 시드하고 ID 를 반환합니다.
     *
     * ProductWishlist 는 팩토리가 없어 직접 만든다 (user_id + product_id 만 있는 피벗).
     *
     * @param  User  $user  찜한 회원
     * @param  Product|null  $product  대상 상품 (기본: 공용 상품)
     * @return int 찜 행 ID
     */
    private function seedWishlist(User $user, ?Product $product = null): int
    {
        return (int) DB::table('ecommerce_product_wishlists')->insertGetId([
            'user_id' => $user->id,
            'product_id' => ($product ?? $this->product)->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 숨김 상품을 만듭니다 (전시 상태만 다른 상품).
     *
     * @return Product 숨김 상품
     */
    private function createHiddenProduct(): Product
    {
        return Product::factory()->create([
            'display_status' => ProductDisplayStatus::HIDDEN->value,
        ]);
    }
}

<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 회원 배송지 관리 API Feature 테스트 (이슈 #524 §4.1)
 *
 * 검증 목적 — 회원이 만든 데이터인데 관리자 표면이 없던 축을 채운다:
 * - 조회와 삭제의 권한이 갈린다
 * - 회원 상세에서 넘어온 user_uuid 필터, 없는 uuid 는 빈 결과(전체 노출 금지)
 * - 검색 대상 구분(member / member_id / email …)
 * - 일괄 삭제 시 관리자 활동 로그 기록
 *
 * URL:
 * - GET    /api/modules/sirsoft-ecommerce/admin/user-addresses
 * - DELETE /api/modules/sirsoft-ecommerce/admin/user-addresses/{id}
 * - DELETE /api/modules/sirsoft-ecommerce/admin/user-addresses/bulk
 *
 * @scenario surface=admin_user_address_list,actor=admin
 *
 * @effects admin_user_address_requires_read_permission, admin_user_address_bulk_delete_writes_activity_log
 *
 * @group ecommerce
 * @group admin
 * @group address
 */
class AdminUserAddressControllerTest extends ModuleTestCase
{
    private string $apiBase = '/api/modules/sirsoft-ecommerce/admin/user-addresses';

    private User $admin;

    private User $readOnlyAdmin;

    private User $memberA;

    private User $memberB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.user-addresses.read',
            'sirsoft-ecommerce.user-addresses.manage',
        ]);
        // ModuleTestCase 는 사용자마다 고유 역할을 만들므로 "조회만" 이 정확히 재현된다
        $this->readOnlyAdmin = $this->createAdminUser(['sirsoft-ecommerce.user-addresses.read']);

        $this->memberA = $this->createUser();
        $this->memberB = $this->createUser();

    }

    /**
     * 조회 권한 없는 관리자 → 403.
     *
     * @effects admin_user_address_requires_read_permission
     */
    public function test_index_requires_read_permission(): void
    {
        $noPermission = $this->createAdminUser([]);

        $this->actingAs($noPermission)->getJson($this->apiBase)->assertStatus(403);
    }

    /**
     * 목록은 페이지네이션과 abilities 를 함께 낸다 (화면이 삭제 버튼 상태를 정할 근거).
     *
     * @effects admin_user_address_returns_pagination_and_abilities
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
     * @effects admin_user_address_read_only_cannot_manage
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
     * @effects admin_user_address_filters_by_user_uuid, admin_user_address_unknown_uuid_returns_empty
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
     * @effects admin_user_address_search_field_branches
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
     * @effects admin_user_address_destroy_requires_manage_permission
     */
    public function test_destroy_requires_manage_permission(): void
    {
        $id = $this->seedRow($this->memberA);

        $this->actingAs($this->readOnlyAdmin)
            ->deleteJson($this->apiBase.'/'.$id)
            ->assertStatus(403);

        $this->assertDatabaseHas('ecommerce_user_addresses', ['id' => $id]);
    }

    /**
     * 일괄 삭제 → 건수 반환 + 행마다 관리자 활동 로그.
     *
     * @effects admin_user_address_bulk_delete_writes_activity_log
     */
    public function test_bulk_destroy_deletes_and_writes_activity_log(): void
    {
        $first = $this->seedRow($this->memberA);
        $second = $this->seedRow($this->memberB);

        $before = (int) DB::table('activity_logs')->where('action', 'user_address.bulk_delete')->count();

        $response = $this->actingAs($this->admin)
            ->deleteJson($this->apiBase.'/bulk', ['ids' => [$first, $second]]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.deleted_count', 2);

        $after = (int) DB::table('activity_logs')->where('action', 'user_address.bulk_delete')->count();
        $this->assertSame($before + 2, $after, '일괄 삭제는 행마다 활동 로그를 남겨야 한다');

        // 배송지는 소프트 삭제가 아니라 지우고 나면 행이 사라진다 — 로그에 id 만 남으면
        // 「누구의 무엇을 지웠는가」를 되짚을 수 없다. 삭제 전 스냅샷이 실렸는지 잰다.
        $properties = (array) json_decode(
            (string) DB::table('activity_logs')
                ->where('action', 'user_address.bulk_delete')
                ->orderByDesc('id')
                ->value('properties'),
            true
        );

        $this->assertArrayHasKey('user_uuid', $properties, '삭제된 배송지의 회원 식별자가 로그에 남아야 한다');
        $this->assertArrayHasKey('recipient_name', $properties, '삭제된 배송지의 수령인이 로그에 남아야 한다');
    }

    /**
     * is_default 필터 — false 를 명시적으로 구분한다(empty() 로 보면 "기본 아님" 필터가 삼켜진다).
     *
     * @effects admin_user_address_is_default_filter
     */
    public function test_index_is_default_filter(): void
    {
        $default = $this->seedRow($this->memberA, ['is_default' => true]);
        $other = $this->seedRow($this->memberA, ['is_default' => false]);

        $this->actingAs($this->admin)
            ->getJson($this->apiBase.'?is_default=1')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $default);

        $this->actingAs($this->admin)
            ->getJson($this->apiBase.'?is_default=0')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $other);
    }

    /**
     * 배송지 행을 시드하고 ID 를 반환합니다.
     *
     * @param  User|null  $user  소유 회원
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return int 배송지 행 ID
     */
    private function seedRow(?User $user, array $attributes = []): int
    {
        $address = UserAddress::factory()->create(array_merge([
            'user_id' => $user?->id,
        ], $attributes));

        return (int) $address->id;
    }
}

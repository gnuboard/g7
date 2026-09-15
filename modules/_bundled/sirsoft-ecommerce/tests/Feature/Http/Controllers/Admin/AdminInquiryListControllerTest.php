<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 상품문의 관리 API Feature 테스트 (이슈 #524 §4.1)
 *
 * 검증 목적 — 회원이 만든 데이터인데 관리자 표면이 없던 축을 채운다:
 * - 조회와 삭제의 권한이 갈린다
 * - 회원 상세에서 넘어온 user_uuid 필터, 없는 uuid 는 빈 결과(전체 노출 금지)
 * - 검색 대상 구분(member / member_id / email …)
 * - 일괄 삭제 시 관리자 활동 로그 기록
 *
 * URL:
 * - GET    /api/modules/sirsoft-ecommerce/admin/inquiries
 * - DELETE /api/modules/sirsoft-ecommerce/admin/inquiries/{id}
 * - DELETE /api/modules/sirsoft-ecommerce/admin/inquiries/bulk
 *
 * @scenario surface=admin_product_inquiry_list,actor=admin
 *
 * @effects admin_product_inquiry_requires_read_permission, admin_product_inquiry_bulk_delete_writes_activity_log
 *
 * @group ecommerce
 * @group admin
 * @group inquiry
 */
class AdminInquiryListControllerTest extends ModuleTestCase
{
    private string $apiBase = '/api/modules/sirsoft-ecommerce/admin/inquiries';

    private User $admin;

    private User $readOnlyAdmin;

    private User $memberA;

    private User $memberB;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.inquiries.read',
            'sirsoft-ecommerce.inquiries.delete',
        ]);
        // ModuleTestCase 는 사용자마다 고유 역할을 만들므로 "조회만" 이 정확히 재현된다
        $this->readOnlyAdmin = $this->createAdminUser(['sirsoft-ecommerce.inquiries.read']);

        $this->memberA = $this->createUser();
        $this->memberB = $this->createUser();
        $this->product = Product::factory()->create();
    }

    /**
     * 조회 권한 없는 관리자 → 403.
     *
     * @effects admin_product_inquiry_requires_read_permission
     */
    public function test_index_requires_read_permission(): void
    {
        $noPermission = $this->createAdminUser([]);

        $this->actingAs($noPermission)->getJson($this->apiBase)->assertStatus(403);
    }

    /**
     * 목록은 페이지네이션과 abilities 를 함께 낸다 (화면이 삭제 버튼 상태를 정할 근거).
     *
     * @effects admin_product_inquiry_returns_pagination_and_abilities
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
     * @effects admin_product_inquiry_read_only_cannot_manage
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
     * @effects admin_product_inquiry_filters_by_user_uuid, admin_product_inquiry_unknown_uuid_returns_empty
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
     * @effects admin_product_inquiry_search_field_branches
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
     * @effects admin_product_inquiry_destroy_requires_manage_permission
     */
    public function test_destroy_requires_manage_permission(): void
    {
        $id = $this->seedRow($this->memberA);

        $this->actingAs($this->readOnlyAdmin)
            ->deleteJson($this->apiBase.'/'.$id)
            ->assertStatus(403);

        $this->assertDatabaseHas('ecommerce_product_inquiries', ['id' => $id]);
    }

    /**
     * 일괄 삭제 → 건수 반환 + 행마다 관리자 활동 로그.
     *
     * @effects admin_product_inquiry_bulk_delete_writes_activity_log
     */
    public function test_bulk_destroy_deletes_and_writes_activity_log(): void
    {
        $first = $this->seedRow($this->memberA);
        $second = $this->seedRow($this->memberB);

        $before = (int) DB::table('activity_logs')->where('action', 'product_inquiry.bulk_delete')->count();

        $response = $this->actingAs($this->admin)
            ->deleteJson($this->apiBase.'/bulk', ['ids' => [$first, $second]]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.deleted_count', 2);

        $after = (int) DB::table('activity_logs')->where('action', 'product_inquiry.bulk_delete')->count();
        $this->assertSame($before + 2, $after, '일괄 삭제는 행마다 활동 로그를 남겨야 한다');
    }

    /**
     * 이미 지워진 문의를 일괄 삭제에 섞어 보내면 422 로 되돌린다.
     *
     * `ProductInquiry` 는 소프트 삭제 모델이고 `Rule::exists` 는 쿼리 빌더로 테이블을 직접 치므로
     * 모델의 `SoftDeletingScope` 를 타지 않는다. 좁히지 않으면 목록에서 이미 사라진 행을 다시 골라
     * 보내도 검증을 통과해 「0건 삭제」가 나가고, 운영자는 목록이 낡았다는 사실을 보지 못한 채
     * 같은 요청을 되풀이한다. Request 의 docblock 이 약속한 「없는 ID 는 422」를 살아 있는 행
     * 기준으로 지킨다.
     *
     * @scenario list=inquiry, action=bulk_delete
     *
     * @effects admin_inquiry_bulk_delete_rejects_soft_deleted_ids
     */
    public function test_bulk_destroy_rejects_soft_deleted_ids(): void
    {
        $alive = $this->seedRow($this->memberA);
        $removed = $this->seedRow($this->memberB);

        ProductInquiry::where('id', $removed)->delete();

        $this->actingAs($this->admin)
            ->deleteJson($this->apiBase.'/bulk', ['ids' => [$alive, $removed]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids.1');

        $this->assertDatabaseHas('ecommerce_product_inquiries', ['id' => $alive, 'deleted_at' => null]);
    }

    /**
     * 일괄 삭제는 피벗만이 아니라 **게시판의 질문 글까지** 지운다.
     *
     * 피벗만 지우면 관리 목록에서만 사라지고 회원에게는 질문 글이 계속 보인다 — 운영자는
     * 목록과 활동 로그를 보고 「지워졌다」 고 믿는다. 같은 화면의 단건 삭제는 글까지 지우므로
     * 두 경로의 결과가 갈리는 자리이기도 하다.
     *
     * 글 삭제는 게시판 모듈이 소유한 훅으로 넘어가므로, 여기서는 **훅이 항목마다 발화하는지**
     * 를 잰다(게시판 모듈 없이도 계약이 성립해야 한다).
     *
     * @effects admin_product_inquiry_bulk_delete_removes_board_post
     */
    public function test_bulk_destroy_deletes_board_question_post_not_just_pivot(): void
    {
        app(EcommerceSettingsService::class)->setSetting('inquiry.board_slug', 'test-inquiry-board');

        $first = $this->seedRow($this->memberA, ['inquirable_id' => 8801]);
        $second = $this->seedRow($this->memberB, ['inquirable_id' => 8802]);

        $deletedPostIds = [];
        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.delete',
            function ($carry, $slug, $inquirableId) use (&$deletedPostIds) {
                $deletedPostIds[] = $inquirableId;

                return true;
            },
            priority: 1
        );

        $this->actingAs($this->admin)
            ->deleteJson($this->apiBase.'/bulk', ['ids' => [$first, $second]])
            ->assertStatus(200)
            ->assertJsonPath('data.deleted_count', 2);

        sort($deletedPostIds);
        $this->assertSame(
            [8801, 8802],
            $deletedPostIds,
            '일괄 삭제는 항목마다 질문 글 삭제 훅을 태워야 한다 — 피벗만 지우면 글이 게시판에 남는다'
        );

        $this->assertSoftDeleted('ecommerce_product_inquiries', ['id' => $first]);
        $this->assertSoftDeleted('ecommerce_product_inquiries', ['id' => $second]);
    }

    /**
     * 게시판 훅이 피벗까지 캐스케이드로 지워도 건수는 «지워진 것» 을 센다.
     *
     * 실제 게시판 모듈은 질문 글을 지우면서 문의 피벗을 함께 소프트 삭제한다. 그러면 뒤이은
     * 피벗 삭제 쿼리가 0행을 건드리는데, 그것을 실패로 읽으면 실제로는 지워졌는데도 응답이
     * 「0건 삭제」 로 나간다(2026-09-15 브라우저 실측에서 드러난 자리).
     *
     * @effects admin_product_inquiry_bulk_delete_counts_cascaded_rows
     */
    public function test_bulk_destroy_counts_rows_even_when_hook_cascades_the_pivot(): void
    {
        app(EcommerceSettingsService::class)->setSetting('inquiry.board_slug', 'test-inquiry-board');

        $id = $this->seedRow($this->memberA);

        // 게시판 훅이 피벗까지 지우는 상황을 그대로 흉내 낸다.
        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.delete',
            function ($carry) use ($id) {
                ProductInquiry::where('id', $id)->delete();

                return true;
            },
            priority: 1
        );

        $response = $this->actingAs($this->admin)
            ->deleteJson($this->apiBase.'/bulk', ['ids' => [$id]]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.deleted_count', 1);
        $this->assertSoftDeleted('ecommerce_product_inquiries', ['id' => $id]);
    }

    /**
     * 일괄 삭제 문구의 `:count` 가 실제 숫자로 채워진다.
     *
     * `moduleSuccess` 는 messageParams 를 따로 받는다 — 빼먹으면 토스트에
     * 「:count개의 상품문의를 삭제했습니다」 가 그대로 뜬다.
     *
     * @effects admin_product_inquiry_bulk_delete_message_interpolates_count
     */
    public function test_bulk_destroy_message_interpolates_count(): void
    {
        $first = $this->seedRow($this->memberA);
        $second = $this->seedRow($this->memberB);

        $response = $this->actingAs($this->admin)
            ->deleteJson($this->apiBase.'/bulk', ['ids' => [$first, $second]]);

        $response->assertStatus(200);

        $message = (string) $response->json('message');
        $this->assertStringNotContainsString(':count', $message, '자리표시자가 그대로 노출되면 안 된다');
        $this->assertStringContainsString('2', $message, '실제 삭제 건수가 문구에 들어가야 한다');
    }

    /**
     * 문의 게시판 **미지정** 이면 글 정리를 생략하고 피벗만 지운다.
     *
     * 미지정 상태에서는 작성·답변이 전부 422 로 막히지만 과거에 쌓인 행은 관리 목록에 남는다.
     * 그때 삭제까지 막으면 운영자가 정리할 방법이 사라진다 — 상품 삭제 경로
     * (`deleteInquiriesForProduct()`)와 같은 판단이다.
     *
     * @effects admin_product_inquiry_bulk_delete_without_board_configured
     */
    public function test_bulk_destroy_without_inquiry_board_still_removes_rows(): void
    {
        app(EcommerceSettingsService::class)->setSetting('inquiry.board_slug', null);

        $id = $this->seedRow($this->memberA);

        $hookFired = 0;
        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.delete',
            function ($carry) use (&$hookFired) {
                $hookFired++;

                return true;
            },
            priority: 1
        );

        $this->actingAs($this->admin)
            ->deleteJson($this->apiBase.'/bulk', ['ids' => [$id]])
            ->assertStatus(200)
            ->assertJsonPath('data.deleted_count', 1);

        $this->assertSame(0, $hookFired, '지울 글이 없으므로 글 삭제 훅을 태우지 않는다');
        $this->assertSoftDeleted('ecommerce_product_inquiries', ['id' => $id]);
    }

    /**
     * is_answered 필터 — false 가 「미답변만」 이라는 뜻이라 empty() 로 보면 안 된다.
     *
     * @effects admin_inquiry_is_answered_filter
     */
    public function test_index_is_answered_filter(): void
    {
        $answered = $this->seedRow($this->memberA, ['is_answered' => true]);
        $pending = $this->seedRow($this->memberA, ['is_answered' => false]);

        $this->actingAs($this->admin)
            ->getJson($this->apiBase.'?is_answered=1')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $answered);

        $this->actingAs($this->admin)
            ->getJson($this->apiBase.'?is_answered=0')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $pending);
    }

    /**
     * 소프트 삭제된 문의는 목록에서 빠진다.
     *
     * @effects admin_inquiry_excludes_soft_deleted
     */
    public function test_index_excludes_soft_deleted(): void
    {
        $alive = $this->seedRow($this->memberA);
        $deleted = $this->seedRow($this->memberA);

        ProductInquiry::where('id', $deleted)->delete();

        $this->actingAs($this->admin)
            ->getJson($this->apiBase)
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $alive);
    }

    /**
     * 상품문의 행을 시드하고 ID 를 반환합니다.
     *
     * @param  User|null  $user  문의한 회원
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return int 문의 행 ID
     */
    private function seedRow(?User $user, array $attributes = []): int
    {
        $inquiry = ProductInquiry::factory()->create(array_merge([
            'user_id' => $user?->id,
            'product_id' => $this->product->id,
        ], $attributes));

        return (int) $inquiry->id;
    }
}

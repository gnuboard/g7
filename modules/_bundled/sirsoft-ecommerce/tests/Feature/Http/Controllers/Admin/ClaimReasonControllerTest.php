<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\Permission;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 클레임 사유 관리자 API 테스트
 */
class ClaimReasonControllerTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected User $adminUser;

    protected User $readOnlyUser;

    protected string $apiBase = '/api/modules/sirsoft-ecommerce/admin/claim-reasons';

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.settings.read',
            'sirsoft-ecommerce.settings.update',
        ]);

        $this->readOnlyUser = $this->createAdminUser([
            'sirsoft-ecommerce.settings.read',
        ]);
    }

    /**
     * 테스트용 클레임 사유를 생성합니다.
     *
     * @param  array  $overrides  오버라이드할 속성
     */
    protected function createReason(array $overrides = []): ClaimReason
    {
        return ClaimReason::create(array_merge([
            'type' => 'refund',
            'code' => 'test_reason_'.uniqid(),
            'name' => ['ko' => '테스트 사유', 'en' => 'Test Reason'],
            'fault_type' => 'customer',
            'is_user_selectable' => true,
            'is_active' => true,
            'sort_order' => 0,
        ], $overrides));
    }

    /**
     * 요청 데이터를 생성합니다.
     *
     * @param  array  $overrides  오버라이드할 속성
     */
    protected function makeReasonData(array $overrides = []): array
    {
        return array_merge([
            'type' => 'refund',
            'code' => 'new_reason_'.uniqid(),
            'name' => ['ko' => '새 사유', 'en' => 'New Reason'],
            'fault_type' => 'customer',
            'is_user_selectable' => true,
            'is_active' => true,
            'sort_order' => 0,
        ], $overrides);
    }

    // ==================== Index ====================

    #[Test]
    public function index_returns_claim_reasons_list(): void
    {
        $this->createReason(['code' => 'reason_a', 'sort_order' => 0]);
        $this->createReason(['code' => 'reason_b', 'sort_order' => 1]);

        $response = $this->actingAs($this->adminUser)
            ->getJson("{$this->apiBase}?type=refund");

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'type',
                            'code',
                            'name',
                            'localized_name',
                            'fault_type',
                            'is_user_selectable',
                            'is_active',
                            'sort_order',
                        ],
                    ],
                ],
            ]);
    }

    #[Test]
    public function index_requires_read_permission(): void
    {
        $noPermUser = $this->createAdminUser([]);

        $response = $this->actingAs($noPermUser)
            ->getJson($this->apiBase);

        $response->assertForbidden();
    }

    // ==================== Store ====================

    #[Test]
    public function store_creates_new_claim_reason(): void
    {
        $data = $this->makeReasonData([
            'code' => 'custom_reason',
            'name' => ['ko' => '커스텀 사유', 'en' => 'Custom Reason'],
            'fault_type' => 'seller',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson($this->apiBase, $data);

        $response->assertCreated()
            ->assertJsonPath('data.code', 'custom_reason')
            ->assertJsonPath('data.fault_type', 'seller');

        $this->assertDatabaseHas('ecommerce_claim_reasons', [
            'code' => 'custom_reason',
            'type' => 'refund',
            'fault_type' => 'seller',
        ]);
    }

    #[Test]
    public function store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson($this->apiBase, []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'code', 'name', 'fault_type']);
    }

    #[Test]
    public function store_validates_unique_code_per_type(): void
    {
        $this->createReason(['code' => 'duplicate_code', 'type' => 'refund']);

        $data = $this->makeReasonData(['code' => 'duplicate_code']);

        $response = $this->actingAs($this->adminUser)
            ->postJson($this->apiBase, $data);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    #[Test]
    public function store_requires_update_permission(): void
    {
        $data = $this->makeReasonData();

        $response = $this->actingAs($this->readOnlyUser)
            ->postJson($this->apiBase, $data);

        $response->assertForbidden();
    }

    #[Test]
    public function destroy_is_blocked_while_an_exchange_or_return_still_uses_the_reason(): void
    {
        // 사용 중 판정이 취소 한 표만 세면 교환·반품·환불 사유는 사용 중에도 지워지고, 그 순간
        // 해당 신청서 전건의 사유 라벨이 카탈로그를 잃어 원시 코드로 노출된다(오류·경고 없음).
        $order = $this->deliveredClaimOrder();

        $exchangeReason = $this->createReason(['type' => 'exchange', 'code' => 'in_use_exchange']);
        $returnReason = $this->createReason(['type' => 'return', 'code' => 'in_use_return']);
        $refundReason = $this->createReason(['type' => 'refund', 'code' => 'in_use_refund']);

        OrderExchange::factory()->create(['order_id' => $order->id, 'reason_code' => $exchangeReason->code]);
        OrderReturn::factory()->create(['order_id' => $order->id, 'reason_code' => $returnReason->code]);
        OrderRefund::create([
            'order_id' => $order->id,
            'refund_number' => 'RF'.uniqid(),
            'refund_status' => 'requested',
            'refund_method' => 'pg',
            'reason_code' => $refundReason->code,
        ]);

        foreach ([$exchangeReason, $returnReason, $refundReason] as $reason) {
            $this->actingAs($this->adminUser)
                ->deleteJson("{$this->apiBase}/{$reason->id}")
                ->assertStatus(400) // 기존 `inUse` 계약 (취소 사유가 쓰이던 상태코드를 그대로 승계)
                // 전용 키로 안내하는지 + 건수가 치환되는지 (원문 노출·키 교체 차단)
                ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_reason_in_use', ['count' => 1]))
                // 안내가 취소 전용 서술로 되돌아가는 것을 막는다. 판정 모집단은 교환·반품·환불
                // 전수인데 안내만 취소를 지목하면, 운영자는 안내받은 건수를 취소 이력에서 찾다가
                // 0건을 보게 된다. 위 키 단언은 기대값도 같은 파일에서 읽으므로 이 되돌림을
                // 잡지 못한다 — 어느 로케일로 렌더되든 걸리도록 3언어 어휘를 함께 본다.
                ->assertJsonPath('message', fn ($m) => is_string($m)
                    && ! str_contains($m, '취소')
                    && ! str_contains($m, 'cancellation')
                    && ! str_contains($m, 'キャンセル'));

            $this->assertDatabaseHas('ecommerce_claim_reasons', ['id' => $reason->id]);
        }

        // 위 응답 단언은 요청이 렌더된 한 로케일만 본다. 원본 두 개를 직접 확인해 두면
        // 렌더되지 않은 쪽이 취소 전용 서술로 되돌아가도 잡힌다.
        $this->assertStringNotContainsString(
            '취소',
            trans('sirsoft-ecommerce::exceptions.claim_reason_in_use', ['count' => 1], 'ko')
        );
        $this->assertStringNotContainsString(
            'cancellation',
            trans('sirsoft-ecommerce::exceptions.claim_reason_in_use', ['count' => 1], 'en')
        );
    }

    #[Test]
    public function destroy_removes_a_reason_that_nothing_uses(): void
    {
        $reason = $this->createReason(['type' => 'exchange', 'code' => 'unused_exchange']);

        $this->actingAs($this->adminUser)
            ->deleteJson("{$this->apiBase}/{$reason->id}")
            ->assertOk();

        $this->assertDatabaseMissing('ecommerce_claim_reasons', ['id' => $reason->id]);
    }

    // ==================== Show ====================

    #[Test]
    public function show_returns_single_reason(): void
    {
        $reason = $this->createReason(['code' => 'show_test']);

        $response = $this->actingAs($this->adminUser)
            ->getJson("{$this->apiBase}/{$reason->id}");

        $response->assertOk()
            ->assertJsonPath('data.code', 'show_test')
            ->assertJsonPath('data.id', $reason->id);
    }

    #[Test]
    public function show_returns_404_for_nonexistent(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson("{$this->apiBase}/99999");

        $response->assertNotFound();
    }

    // ==================== Update ====================

    #[Test]
    public function update_modifies_claim_reason(): void
    {
        $reason = $this->createReason(['code' => 'update_test']);

        $data = $this->makeReasonData([
            'code' => 'update_test',
            'name' => ['ko' => '수정된 사유', 'en' => 'Updated Reason'],
            'fault_type' => 'carrier',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->putJson("{$this->apiBase}/{$reason->id}", $data);

        $response->assertOk()
            ->assertJsonPath('data.fault_type', 'carrier');

        $this->assertDatabaseHas('ecommerce_claim_reasons', [
            'id' => $reason->id,
            'fault_type' => 'carrier',
        ]);
    }

    #[Test]
    public function update_requires_update_permission(): void
    {
        $reason = $this->createReason();
        $data = $this->makeReasonData();

        $response = $this->actingAs($this->readOnlyUser)
            ->putJson("{$this->apiBase}/{$reason->id}", $data);

        $response->assertForbidden();
    }

    // ==================== Destroy ====================

    #[Test]
    public function destroy_deletes_unused_reason(): void
    {
        $reason = $this->createReason(['code' => 'delete_test']);

        $response = $this->actingAs($this->adminUser)
            ->deleteJson("{$this->apiBase}/{$reason->id}");

        $response->assertOk();

        $this->assertDatabaseMissing('ecommerce_claim_reasons', [
            'id' => $reason->id,
        ]);
    }

    #[Test]
    public function destroy_requires_update_permission(): void
    {
        $reason = $this->createReason();

        $response = $this->actingAs($this->readOnlyUser)
            ->deleteJson("{$this->apiBase}/{$reason->id}");

        $response->assertForbidden();
    }

    // ==================== Toggle Status ====================

    #[Test]
    public function toggle_status_changes_is_active(): void
    {
        $reason = $this->createReason(['is_active' => true]);

        $response = $this->actingAs($this->adminUser)
            ->patchJson("{$this->apiBase}/{$reason->id}/toggle-status");

        $response->assertOk();

        $reason->refresh();
        $this->assertFalse($reason->is_active);
    }

    #[Test]
    public function toggle_status_requires_update_permission(): void
    {
        $reason = $this->createReason();

        $response = $this->actingAs($this->readOnlyUser)
            ->patchJson("{$this->apiBase}/{$reason->id}/toggle-status");

        $response->assertForbidden();
    }

    // ==================== Active ====================

    #[Test]
    public function active_returns_only_active_reasons(): void
    {
        $this->createReason(['code' => 'active_one', 'is_active' => true]);
        $this->createReason(['code' => 'inactive_one', 'is_active' => false]);

        $response = $this->actingAs($this->adminUser)
            ->getJson("{$this->apiBase}/active?type=refund");

        $response->assertOk();

        $codes = collect($response->json('data.data'))->pluck('code');
        $this->assertTrue($codes->contains('active_one'));
        $this->assertFalse($codes->contains('inactive_one'));
    }

    // ==================== User Selectable ====================

    #[Test]
    public function user_selectable_returns_active_user_selectable_reasons(): void
    {
        $this->createReason(['code' => 'selectable', 'is_active' => true, 'is_user_selectable' => true]);
        $this->createReason(['code' => 'not_selectable', 'is_active' => true, 'is_user_selectable' => false]);

        $user = $this->createUser();

        // user-orders.cancel 권한 부여
        $userRole = $user->roles()->first();
        if ($userRole) {
            $cancelPermission = Permission::firstOrCreate(
                ['identifier' => 'sirsoft-ecommerce.user-orders.cancel'],
                [
                    'name' => ['ko' => '주문 취소', 'en' => 'Cancel Order'],
                    'type' => 'user',
                ]
            );
            $userRole->permissions()->syncWithoutDetaching([$cancelPermission->id]);
        }

        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/user/claim-reasons?type=refund');

        $response->assertOk();

        $codes = collect($response->json('data.data') ?? $response->json('data'))->pluck('code');
        $this->assertTrue($codes->contains('selectable'));
        $this->assertFalse($codes->contains('not_selectable'));
    }
}

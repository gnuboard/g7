<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 클래임 사유 관리자 API 테스트
 */
class ClaimReasonControllerTest extends ModuleTestCase
{
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
     * 테스트용 클래임 사유를 생성합니다.
     *
     * @param array $overrides 오버라이드할 속성
     * @return ClaimReason
     */
    protected function createReason(array $overrides = []): ClaimReason
    {
        return ClaimReason::create(array_merge([
            'type' => 'refund',
            'code' => 'test_reason_' . uniqid(),
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
     * @param array $overrides 오버라이드할 속성
     * @return array
     */
    protected function makeReasonData(array $overrides = []): array
    {
        return array_merge([
            'type' => 'refund',
            'code' => 'new_reason_' . uniqid(),
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
            $cancelPermission = \App\Models\Permission::firstOrCreate(
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

<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * ShippingCarrierController Feature 테스트
 *
 * 배송사 관리 API 엔드포인트 테스트
 */
class ShippingCarrierControllerTest extends ModuleTestCase
{
    protected User $adminUser;

    protected User $readOnlyUser;

    /** @var string API 베이스 URL */
    protected string $apiBase = '/api/modules/sirsoft-ecommerce/admin/shipping-carriers';

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 관리자 사용자 생성 (설정 읽기+쓰기 권한)
        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.settings.read',
            'sirsoft-ecommerce.settings.update',
        ]);

        // 읽기 전용 사용자
        $this->readOnlyUser = $this->createAdminUser([
            'sirsoft-ecommerce.settings.read',
        ]);
    }

    // ──────────────────────────────────────────────
    // 헬퍼 메서드
    // ──────────────────────────────────────────────

    /**
     * 배송사를 생성하는 헬퍼
     *
     * @param array $overrides 오버라이드
     * @return ShippingCarrier
     */
    protected function createCarrier(array $overrides = []): ShippingCarrier
    {
        $data = array_merge([
            'code' => 'test-carrier',
            'name' => ['ko' => '테스트택배', 'en' => 'Test Carrier'],
            'type' => 'domestic',
            'tracking_url' => 'https://example.com/track?no={tracking_number}',
            'is_active' => true,
            'sort_order' => 0,
        ], $overrides);

        return ShippingCarrier::create($data);
    }

    /**
     * 배송사 생성용 기본 요청 데이터
     *
     * @param array $overrides 오버라이드
     * @return array
     */
    protected function makeCarrierData(array $overrides = []): array
    {
        return array_merge([
            'code' => 'new-carrier',
            'name' => ['ko' => '새택배', 'en' => 'New Carrier'],
            'type' => 'domestic',
            'tracking_url' => 'https://example.com/track?no={tracking_number}',
            'is_active' => true,
            'sort_order' => 0,
        ], $overrides);
    }

    // ──────────────────────────────────────────────
    // 목록 조회 (Index)
    // ──────────────────────────────────────────────

    #[Test]
    public function test_index_returns_carrier_list(): void
    {
        $this->createCarrier(['code' => 'carrier-a', 'sort_order' => 1]);
        $this->createCarrier(['code' => 'carrier-b', 'sort_order' => 2]);

        $response = $this->actingAs($this->adminUser)
            ->getJson($this->apiBase);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'code',
                            'name',
                            'localized_name',
                            'type',
                            'type_label',
                            'tracking_url',
                            'is_active',
                            'sort_order',
                        ],
                    ],
                ],
            ]);
    }

    #[Test]
    public function test_index_filters_by_type(): void
    {
        $this->createCarrier(['code' => 'domestic-a', 'type' => 'domestic']);
        $this->createCarrier(['code' => 'intl-a', 'type' => 'international']);

        $response = $this->actingAs($this->adminUser)
            ->getJson($this->apiBase . '?type=domestic');

        $response->assertOk();

        $data = $response->json('data.data');
        foreach ($data as $carrier) {
            $this->assertEquals('domestic', $carrier['type']);
        }
    }

    #[Test]
    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson($this->apiBase);

        $response->assertUnauthorized();
    }

    // ──────────────────────────────────────────────
    // 생성 (Store)
    // ──────────────────────────────────────────────

    #[Test]
    public function test_store_creates_carrier(): void
    {
        $data = $this->makeCarrierData();

        $response = $this->actingAs($this->adminUser)
            ->postJson($this->apiBase, $data);

        $response->assertCreated()
            ->assertJsonPath('data.code', 'new-carrier')
            ->assertJsonPath('data.type', 'domestic');

        $this->assertDatabaseHas('ecommerce_shipping_carriers', [
            'code' => 'new-carrier',
            'type' => 'domestic',
        ]);
    }

    #[Test]
    public function test_store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson($this->apiBase, []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'name', 'type']);
    }

    #[Test]
    public function test_store_validates_unique_code(): void
    {
        $this->createCarrier(['code' => 'existing-code']);

        $response = $this->actingAs($this->adminUser)
            ->postJson($this->apiBase, $this->makeCarrierData(['code' => 'existing-code']));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    #[Test]
    public function test_store_validates_code_format(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson($this->apiBase, $this->makeCarrierData(['code' => 'INVALID CODE!']));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    #[Test]
    public function test_store_validates_type_values(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson($this->apiBase, $this->makeCarrierData(['type' => 'invalid']));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }

    // ──────────────────────────────────────────────
    // 활성 목록 (Active)
    // ──────────────────────────────────────────────

    #[Test]
    public function test_active_returns_select_format(): void
    {
        $this->createCarrier(['code' => 'active-a', 'is_active' => true]);
        $this->createCarrier(['code' => 'inactive-a', 'is_active' => false]);

        $response = $this->actingAs($this->adminUser)
            ->getJson($this->apiBase . '/active');

        $response->assertOk();

        $data = $response->json('data');
        $this->assertIsArray($data);

        // 비활성 배송사는 포함되지 않아야 함
        $codes = array_column($data, 'code');
        $this->assertContains('active-a', $codes);
        $this->assertNotContains('inactive-a', $codes);

        // Select 포맷 확인 (value, label)
        foreach ($data as $item) {
            $this->assertArrayHasKey('value', $item);
            $this->assertArrayHasKey('label', $item);
            $this->assertArrayHasKey('code', $item);
            $this->assertArrayHasKey('type', $item);
        }
    }

    #[Test]
    public function test_active_filters_by_type(): void
    {
        $this->createCarrier(['code' => 'domestic-b', 'type' => 'domestic', 'is_active' => true]);
        $this->createCarrier(['code' => 'intl-b', 'type' => 'international', 'is_active' => true]);

        $response = $this->actingAs($this->adminUser)
            ->getJson($this->apiBase . '/active?type=international');

        $response->assertOk();

        $data = $response->json('data');
        foreach ($data as $item) {
            $this->assertEquals('international', $item['type']);
        }
    }

    // ──────────────────────────────────────────────
    // 상세 조회 (Show)
    // ──────────────────────────────────────────────

    #[Test]
    public function test_show_returns_carrier_detail(): void
    {
        $carrier = $this->createCarrier();

        $response = $this->actingAs($this->adminUser)
            ->getJson($this->apiBase . '/' . $carrier->id);

        $response->assertOk()
            ->assertJsonPath('data.code', 'test-carrier')
            ->assertJsonPath('data.type', 'domestic');
    }

    #[Test]
    public function test_show_returns_404_for_nonexistent(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson($this->apiBase . '/99999');

        $response->assertNotFound();
    }

    // ──────────────────────────────────────────────
    // 수정 (Update)
    // ──────────────────────────────────────────────

    #[Test]
    public function test_update_modifies_carrier(): void
    {
        $carrier = $this->createCarrier();

        $response = $this->actingAs($this->adminUser)
            ->putJson($this->apiBase . '/' . $carrier->id, [
                'name' => ['ko' => '수정된택배', 'en' => 'Updated Carrier'],
                'type' => 'international',
            ]);

        $response->assertOk();

        $carrier->refresh();
        $this->assertEquals('international', $carrier->type);
        $this->assertEquals('수정된택배', $carrier->name['ko']);
    }

    #[Test]
    public function test_update_allows_same_code(): void
    {
        $carrier = $this->createCarrier(['code' => 'keep-code']);

        $response = $this->actingAs($this->adminUser)
            ->putJson($this->apiBase . '/' . $carrier->id, [
                'code' => 'keep-code',
                'name' => ['ko' => '수정됨', 'en' => 'Updated'],
            ]);

        $response->assertOk();
    }

    #[Test]
    public function test_update_rejects_duplicate_code(): void
    {
        $this->createCarrier(['code' => 'existing-code2']);
        $carrier = $this->createCarrier(['code' => 'my-code']);

        $response = $this->actingAs($this->adminUser)
            ->putJson($this->apiBase . '/' . $carrier->id, [
                'code' => 'existing-code2',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    // ──────────────────────────────────────────────
    // 삭제 (Destroy)
    // ──────────────────────────────────────────────

    #[Test]
    public function test_destroy_deletes_carrier(): void
    {
        $carrier = $this->createCarrier();

        $response = $this->actingAs($this->adminUser)
            ->deleteJson($this->apiBase . '/' . $carrier->id);

        $response->assertOk();

        $this->assertDatabaseMissing('ecommerce_shipping_carriers', [
            'id' => $carrier->id,
        ]);
    }

    #[Test]
    public function test_destroy_returns_404_for_nonexistent(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->deleteJson($this->apiBase . '/99999');

        $response->assertNotFound();
    }

    // ──────────────────────────────────────────────
    // 상태 토글 (Toggle Status)
    // ──────────────────────────────────────────────

    #[Test]
    public function test_toggle_status_changes_active_state(): void
    {
        $carrier = $this->createCarrier(['is_active' => true]);

        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->apiBase . '/' . $carrier->id . '/toggle-status');

        $response->assertOk();

        $carrier->refresh();
        $this->assertFalse($carrier->is_active);

        // 다시 토글
        $response = $this->actingAs($this->adminUser)
            ->patchJson($this->apiBase . '/' . $carrier->id . '/toggle-status');

        $response->assertOk();

        $carrier->refresh();
        $this->assertTrue($carrier->is_active);
    }

    // ──────────────────────────────────────────────
    // 권한 검증
    // ──────────────────────────────────────────────

    #[Test]
    public function test_read_only_user_can_list_carriers(): void
    {
        $response = $this->actingAs($this->readOnlyUser)
            ->getJson($this->apiBase);

        $response->assertOk();
    }

    #[Test]
    public function test_read_only_user_cannot_create_carrier(): void
    {
        $response = $this->actingAs($this->readOnlyUser)
            ->postJson($this->apiBase, $this->makeCarrierData());

        $response->assertForbidden();
    }

    #[Test]
    public function test_read_only_user_cannot_delete_carrier(): void
    {
        $carrier = $this->createCarrier();

        $response = $this->actingAs($this->readOnlyUser)
            ->deleteJson($this->apiBase . '/' . $carrier->id);

        $response->assertForbidden();
    }
}

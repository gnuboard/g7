<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\ProductLabel;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * ProductLabelController Feature 테스트
 *
 * 상품 라벨 관리 API 엔드포인트 테스트
 */
class ProductLabelControllerTest extends ModuleTestCase
{
    protected User $adminUser;

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 관리자 사용자 생성 (라벨 권한 포함)
        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.product-labels.read',
            'sirsoft-ecommerce.product-labels.create',
            'sirsoft-ecommerce.product-labels.update',
            'sirsoft-ecommerce.product-labels.delete',
        ]);
    }

    // ========================================
    // index() 테스트
    // ========================================

    /**
     * 라벨 목록 조회 테스트
     */
    #[Test]
    public function test_index_returns_label_list(): void
    {
        // Given: 라벨 생성
        ProductLabel::create([
            'name' => ['ko' => '신상품', 'en' => 'New'],
            'color' => '#FF5733',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        ProductLabel::create([
            'name' => ['ko' => '베스트', 'en' => 'Best'],
            'color' => '#33FF57',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        // When: 목록 조회 API 호출
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/product-labels');

        // Then: 라벨 목록 반환
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'data' => [
                    '*' => ['id', 'name', 'color', 'is_active', 'sort_order'],
                ],
                'abilities',
            ],
        ]);
        $this->assertCount(2, $response->json('data.data'));
    }

    /**
     * 활성 상태로 필터링 테스트
     */
    #[Test]
    public function test_index_filters_by_is_active(): void
    {
        // Given: 활성/비활성 라벨 생성
        ProductLabel::create([
            'name' => ['ko' => '활성라벨', 'en' => 'Active'],
            'color' => '#FF5733',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        ProductLabel::create([
            'name' => ['ko' => '비활성라벨', 'en' => 'Inactive'],
            'color' => '#33FF57',
            'is_active' => false,
            'sort_order' => 2,
        ]);

        // When: 활성 라벨만 필터링
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/product-labels?is_active=1');

        // Then: 활성 라벨만 반환
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
        $this->assertTrue($response->json('data.data.0.is_active'));
    }

    /**
     * 라벨명 검색 테스트
     */
    #[Test]
    public function test_index_searches_by_name(): void
    {
        // Given: 라벨 생성
        ProductLabel::create([
            'name' => ['ko' => '신상품', 'en' => 'New Product'],
            'color' => '#FF5733',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        ProductLabel::create([
            'name' => ['ko' => '베스트셀러', 'en' => 'Best Seller'],
            'color' => '#33FF57',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        // When: 라벨명으로 검색
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/product-labels?search=신상품');

        // Then: 검색된 라벨만 반환
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    /**
     * 정렬 테스트 - 이름 오름차순
     *
     * Note: MySQL JSON 정렬은 바이트 순서로 정렬되므로
     * 한국어 알파벳 순서와 다를 수 있음. 영문 기준 테스트.
     */
    #[Test]
    public function test_index_sorts_by_name_asc(): void
    {
        // Given: 라벨 생성 (영문 정렬 기준 Alpha < Beta)
        ProductLabel::create([
            'name' => ['ko' => '베타', 'en' => 'Beta'],
            'color' => '#FF5733',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        ProductLabel::create([
            'name' => ['ko' => '알파', 'en' => 'Alpha'],
            'color' => '#33FF57',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        // When: 이름 오름차순 정렬 (영문 로케일 기준)
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/product-labels?sort=name_asc&locale=en');

        // Then: 정렬된 결과 반환 (Alpha가 먼저)
        $response->assertStatus(200);
        $this->assertEquals('Alpha', $response->json('data.data.0.name.en'));
        $this->assertEquals('Beta', $response->json('data.data.1.name.en'));
    }

    // ========================================
    // show() 테스트
    // ========================================

    /**
     * 라벨 상세 조회 테스트
     */
    #[Test]
    public function test_show_returns_label_detail(): void
    {
        // Given: 라벨 생성
        $label = ProductLabel::create([
            'name' => ['ko' => '신상품', 'en' => 'New'],
            'color' => '#FF5733',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // When: 상세 조회 API 호출
        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/product-labels/{$label->id}");

        // Then: 라벨 상세 반환
        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $label->id);
        $response->assertJsonPath('data.name.ko', '신상품');
        $response->assertJsonPath('data.color', '#FF5733');
    }

    /**
     * 존재하지 않는 라벨 조회 시 404 반환 테스트
     */
    #[Test]
    public function test_show_returns_404_for_nonexistent_label(): void
    {
        // When: 존재하지 않는 ID로 조회
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/product-labels/99999');

        // Then: 404 반환
        $response->assertStatus(404);
    }

    // ========================================
    // store() 테스트
    // ========================================

    /**
     * 라벨 생성 테스트
     */
    #[Test]
    public function test_store_creates_label(): void
    {
        // Given: 라벨 생성 데이터
        $data = [
            'name' => ['ko' => '신상품', 'en' => 'New'],
            'color' => '#FF5733',
            'is_active' => true,
            'sort_order' => 1,
        ];

        // When: 생성 API 호출
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/product-labels', $data);

        // Then: 생성 성공
        $response->assertStatus(201);
        $response->assertJsonPath('data.name.ko', '신상품');

        // DB 확인
        $this->assertDatabaseHas('ecommerce_product_labels', [
            'color' => '#FF5733',
        ]);
    }

    /**
     * 필수 필드 누락 시 검증 에러 테스트
     */
    #[Test]
    public function test_store_validates_required_fields(): void
    {
        // Given: 필수 필드 누락 데이터
        $data = [
            'color' => '#FF5733',
        ];

        // When: 생성 API 호출
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/product-labels', $data);

        // Then: 검증 에러
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    /**
     * 잘못된 색상 형식 검증 테스트
     */
    #[Test]
    public function test_store_validates_color_format(): void
    {
        // Given: 잘못된 색상 형식 데이터
        $data = [
            'name' => ['ko' => '신상품', 'en' => 'New'],
            'color' => 'invalid-color',
        ];

        // When: 생성 API 호출
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/product-labels', $data);

        // Then: 검증 에러
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['color']);
    }

    // ========================================
    // update() 테스트
    // ========================================

    /**
     * 라벨 수정 테스트
     */
    #[Test]
    public function test_update_modifies_label(): void
    {
        // Given: 라벨 존재
        $label = ProductLabel::create([
            'name' => ['ko' => '신상품', 'en' => 'New'],
            'color' => '#FF5733',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $updateData = [
            'name' => ['ko' => '수정된 라벨', 'en' => 'Updated Label'],
            'color' => '#00FF00',
        ];

        // When: 수정 API 호출
        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/product-labels/{$label->id}", $updateData);

        // Then: 수정 성공
        $response->assertStatus(200);
        $response->assertJsonPath('data.name.ko', '수정된 라벨');
        $response->assertJsonPath('data.color', '#00FF00');

        // DB 확인
        $this->assertDatabaseHas('ecommerce_product_labels', [
            'id' => $label->id,
            'color' => '#00FF00',
        ]);
    }

    /**
     * 존재하지 않는 라벨 수정 시 404 반환 테스트
     */
    #[Test]
    public function test_update_returns_404_for_nonexistent_label(): void
    {
        // Given: 수정 데이터
        $updateData = [
            'name' => ['ko' => '수정된 라벨', 'en' => 'Updated Label'],
        ];

        // When: 존재하지 않는 ID로 수정 시도
        $response = $this->actingAs($this->adminUser)
            ->putJson('/api/modules/sirsoft-ecommerce/admin/product-labels/99999', $updateData);

        // Then: 404 반환
        $response->assertStatus(404);
    }

    // ========================================
    // destroy() 테스트
    // ========================================

    /**
     * 라벨 삭제 테스트
     */
    #[Test]
    public function test_destroy_deletes_label(): void
    {
        // Given: 라벨 존재
        $label = ProductLabel::create([
            'name' => ['ko' => '삭제할 라벨', 'en' => 'To Delete'],
            'color' => '#FF5733',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // When: 삭제 API 호출
        $response = $this->actingAs($this->adminUser)
            ->deleteJson("/api/modules/sirsoft-ecommerce/admin/product-labels/{$label->id}");

        // Then: 삭제 성공
        $response->assertStatus(200);

        // DB에서 삭제 확인
        $this->assertDatabaseMissing('ecommerce_product_labels', [
            'id' => $label->id,
        ]);
    }

    /**
     * 존재하지 않는 라벨 삭제 시 404 반환 테스트
     */
    #[Test]
    public function test_destroy_returns_404_for_nonexistent_label(): void
    {
        // When: 존재하지 않는 ID로 삭제 시도
        $response = $this->actingAs($this->adminUser)
            ->deleteJson('/api/modules/sirsoft-ecommerce/admin/product-labels/99999');

        // Then: 404 반환
        $response->assertStatus(404);
    }

    // ========================================
    // toggleStatus() 테스트
    // ========================================

    /**
     * 사용여부 토글 테스트
     */
    #[Test]
    public function test_toggle_status_changes_is_active(): void
    {
        // Given: 활성 라벨 존재
        $label = ProductLabel::create([
            'name' => ['ko' => '토글 라벨', 'en' => 'Toggle Label'],
            'color' => '#FF5733',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // When: 토글 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson("/api/modules/sirsoft-ecommerce/admin/product-labels/{$label->id}/toggle-status");

        // Then: 상태 변경 성공
        $response->assertStatus(200);
        $response->assertJsonPath('data.is_active', false);

        // DB 확인
        $label->refresh();
        $this->assertFalse($label->is_active);
    }

    /**
     * 존재하지 않는 라벨 토글 시 404 반환 테스트
     */
    #[Test]
    public function test_toggle_status_returns_404_for_nonexistent_label(): void
    {
        // When: 존재하지 않는 ID로 토글 시도
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/product-labels/99999/toggle-status');

        // Then: 404 반환
        $response->assertStatus(404);
    }

    // ========================================
    // 권한 테스트
    // ========================================

    /**
     * 인증 안된 사용자 접근 불가 테스트
     */
    #[Test]
    public function test_unauthenticated_user_cannot_access(): void
    {
        // When: 인증 없이 API 호출
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/admin/product-labels');

        // Then: 인증 필요 에러
        $response->assertStatus(401);
    }

    /**
     * 권한 없는 사용자 접근 불가 테스트 - 생성
     */
    #[Test]
    public function test_user_without_create_permission_cannot_create(): void
    {
        // Given: 읽기 권한만 있는 사용자
        $readOnlyUser = $this->createAdminUser([
            'sirsoft-ecommerce.product-labels.read',
        ]);

        $data = [
            'name' => ['ko' => '신상품', 'en' => 'New'],
            'color' => '#FF5733',
        ];

        // When: 생성 API 호출
        $response = $this->actingAs($readOnlyUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/product-labels', $data);

        // Then: 권한 거부
        $response->assertStatus(403);
    }
}

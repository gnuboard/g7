<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\SearchPreset;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * SearchPresetController Feature 테스트
 *
 * 검색 프리셋 API 엔드포인트 테스트
 */
class SearchPresetControllerTest extends ModuleTestCase
{
    protected User $adminUser;

    protected User $otherUser;

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 관리자 사용자 생성
        $this->adminUser = $this->createAdminUser();
        $this->otherUser = $this->createAdminUser();
    }

    /**     */
    #[Test]
    public function test_index_returns_user_presets(): void
    {
        // Given: 현재 사용자의 프리셋 생성
        SearchPreset::create([
            'user_id' => $this->adminUser->id,
            'target_screen' => 'products',
            'preset_name' => 'My Preset 1',
            'conditions' => ['salesStatus' => ['on_sale']],
        ]);

        SearchPreset::create([
            'user_id' => $this->adminUser->id,
            'target_screen' => 'products',
            'preset_name' => 'My Preset 2',
            'conditions' => ['displayStatus' => 'visible'],
        ]);

        // 다른 사용자의 프리셋
        SearchPreset::create([
            'user_id' => $this->otherUser->id,
            'target_screen' => 'products',
            'preset_name' => 'Other User Preset',
            'conditions' => [],
        ]);

        // When: 프리셋 목록 API 호출
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/presets?target_screen=products');

        // Then: 현재 사용자의 프리셋만 반환
        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
        $this->assertEquals('My Preset 1', $response->json('data.0.name'));
        $this->assertEquals('My Preset 2', $response->json('data.1.name'));
    }

    /**     */
    #[Test]
    public function test_index_filters_by_target_screen(): void
    {
        // Given: 다른 화면의 프리셋 생성
        SearchPreset::create([
            'user_id' => $this->adminUser->id,
            'target_screen' => 'products',
            'preset_name' => 'Products Preset',
            'conditions' => [],
        ]);

        SearchPreset::create([
            'user_id' => $this->adminUser->id,
            'target_screen' => 'orders',
            'preset_name' => 'Orders Preset',
            'conditions' => [],
        ]);

        // When: products 화면 프리셋만 요청
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/presets?target_screen=products');

        // Then: products 프리셋만 반환
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $this->assertEquals('Products Preset', $response->json('data.0.name'));
    }

    /**     */
    #[Test]
    public function test_store_creates_preset(): void
    {
        // Given: 프리셋 생성 데이터
        $data = [
            'target_screen' => 'products',
            'name' => '품절상품 필터',
            'conditions' => [
                'salesStatus' => ['sold_out'],
                'displayStatus' => 'all',
            ],
        ];

        // When: 프리셋 생성 API 호출
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/presets', $data);

        // Then: 생성 성공
        $response->assertStatus(201);
        $this->assertEquals('품절상품 필터', $response->json('data.name'));

        // DB에 저장 확인
        $this->assertDatabaseHas('ecommerce_search_presets', [
            'user_id' => $this->adminUser->id,
            'target_screen' => 'products',
            'preset_name' => '품절상품 필터',
        ]);
    }

    /**     */
    #[Test]
    public function test_store_validates_required_fields(): void
    {
        // Given: 필수 필드 누락 데이터
        $data = [
            'target_screen' => 'products',
            // name 누락
            'conditions' => [],
        ];

        // When: 프리셋 생성 API 호출
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/presets', $data);

        // Then: 검증 실패
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    /**     */
    #[Test]
    public function test_store_validates_unique_name(): void
    {
        // Given: 동일한 이름의 프리셋이 이미 존재
        SearchPreset::create([
            'user_id' => $this->adminUser->id,
            'target_screen' => 'products',
            'preset_name' => '중복이름',
            'conditions' => [],
        ]);

        $data = [
            'target_screen' => 'products',
            'name' => '중복이름',
            'conditions' => ['test' => true],
        ];

        // When: 프리셋 생성 API 호출
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/presets', $data);

        // Then: 검증 실패 (중복 이름)
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    /**     */
    #[Test]
    public function test_update_updates_preset(): void
    {
        // Given: 기존 프리셋
        $preset = SearchPreset::create([
            'user_id' => $this->adminUser->id,
            'target_screen' => 'products',
            'preset_name' => '원래 이름',
            'conditions' => ['old' => true],
        ]);

        $data = ['name' => '변경된 이름'];

        // When: 프리셋 수정 API 호출
        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/presets/{$preset->id}", $data);

        // Then: 수정 성공
        $response->assertStatus(200);
        $this->assertEquals('변경된 이름', $response->json('data.name'));

        // DB에 반영 확인
        $this->assertDatabaseHas('ecommerce_search_presets', [
            'id' => $preset->id,
            'preset_name' => '변경된 이름',
        ]);
    }

    /**     */
    #[Test]
    public function test_update_validates_unique_name_except_self(): void
    {
        // Given: 두 개의 프리셋
        $preset1 = SearchPreset::create([
            'user_id' => $this->adminUser->id,
            'target_screen' => 'products',
            'preset_name' => '프리셋 1',
            'conditions' => [],
        ]);

        $preset2 = SearchPreset::create([
            'user_id' => $this->adminUser->id,
            'target_screen' => 'products',
            'preset_name' => '프리셋 2',
            'conditions' => [],
        ]);

        // When: preset2의 이름을 preset1의 이름으로 변경 시도
        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/presets/{$preset2->id}", [
                'name' => '프리셋 1',
            ]);

        // Then: 검증 실패 (중복 이름)
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    /**     */
    #[Test]
    public function test_update_allows_same_name_for_self(): void
    {
        // Given: 기존 프리셋
        $preset = SearchPreset::create([
            'user_id' => $this->adminUser->id,
            'target_screen' => 'products',
            'preset_name' => '동일 이름',
            'conditions' => ['old' => true],
        ]);

        // When: 자기 자신의 이름 그대로 수정
        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/presets/{$preset->id}", [
                'name' => '동일 이름',
            ]);

        // Then: 수정 성공 (자기 자신은 중복 체크에서 제외)
        $response->assertStatus(200);
    }

    /**     */
    #[Test]
    public function test_destroy_deletes_preset(): void
    {
        // Given: 기존 프리셋
        $preset = SearchPreset::create([
            'user_id' => $this->adminUser->id,
            'target_screen' => 'products',
            'preset_name' => '삭제할 프리셋',
            'conditions' => [],
        ]);

        // When: 프리셋 삭제 API 호출
        $response = $this->actingAs($this->adminUser)
            ->deleteJson("/api/modules/sirsoft-ecommerce/admin/presets/{$preset->id}");

        // Then: 삭제 성공
        $response->assertStatus(200);

        // DB에서 삭제 확인
        $this->assertDatabaseMissing('ecommerce_search_presets', [
            'id' => $preset->id,
        ]);
    }

    /**     */
    #[Test]
    public function test_destroy_returns_error_for_other_user_preset(): void
    {
        // Given: 다른 사용자의 프리셋
        $preset = SearchPreset::create([
            'user_id' => $this->otherUser->id,
            'target_screen' => 'products',
            'preset_name' => '타인의 프리셋',
            'conditions' => [],
        ]);

        // When: 삭제 시도
        $response = $this->actingAs($this->adminUser)
            ->deleteJson("/api/modules/sirsoft-ecommerce/admin/presets/{$preset->id}");

        // Then: 권한 없음 에러
        $response->assertStatus(403);

        // DB에 여전히 존재
        $this->assertDatabaseHas('ecommerce_search_presets', [
            'id' => $preset->id,
        ]);
    }

    /**     */
    #[Test]
    public function test_unauthenticated_user_cannot_access(): void
    {
        // When: 인증 없이 API 호출
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/admin/presets?target_screen=products');

        // Then: 인증 필요 에러
        $response->assertStatus(401);
    }
}

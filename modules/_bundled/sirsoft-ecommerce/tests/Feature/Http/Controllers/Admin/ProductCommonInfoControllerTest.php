<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\ProductCommonInfo;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * ProductCommonInfoController Feature 테스트
 *
 * 공통정보 API 엔드포인트 테스트
 */
class ProductCommonInfoControllerTest extends ModuleTestCase
{
    protected User $adminUser;

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 관리자 사용자 생성 (공통정보 권한 포함)
        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.product-common-infos.read',
            'sirsoft-ecommerce.product-common-infos.create',
            'sirsoft-ecommerce.product-common-infos.update',
            'sirsoft-ecommerce.product-common-infos.delete',
        ]);
    }

    /**
     * 공통정보 목록 조회 테스트
     */
    #[Test]
    public function test_index_returns_common_infos(): void
    {
        // Given: 공통정보 생성
        ProductCommonInfo::create([
            'name' => ['ko' => '일반 상품 안내', 'en' => 'General Product Info'],
            'content' => ['ko' => '안내 내용입니다.', 'en' => 'Product information.'],
            'content_mode' => 'text',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        ProductCommonInfo::create([
            'name' => ['ko' => '식품 안내', 'en' => 'Food Info'],
            'content' => ['ko' => '식품 관련 안내', 'en' => 'Food related info'],
            'content_mode' => 'text',
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        // When: 목록 조회 API 호출
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/product-common-infos');

        // Then: 공통정보 목록 반환
        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data.data');
        $response->assertJsonStructure(['data' => ['data', 'abilities']]);
    }

    /**
     * 공통정보 목록 검색 테스트
     */
    #[Test]
    public function test_index_filters_by_search(): void
    {
        // Given: 공통정보 생성
        ProductCommonInfo::create([
            'name' => ['ko' => '일반 상품 안내', 'en' => 'General Product Info'],
            'content' => ['ko' => '안내 내용', 'en' => 'Info content'],
            'content_mode' => 'text',
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        ProductCommonInfo::create([
            'name' => ['ko' => '식품 안내', 'en' => 'Food Info'],
            'content' => ['ko' => '식품 관련', 'en' => 'Food related'],
            'content_mode' => 'text',
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        // When: 검색으로 API 호출
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/product-common-infos?search=식품');

        // Then: 검색된 공통정보만 반환
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.data');
    }

    /**
     * 공통정보 상세 조회 테스트
     */
    #[Test]
    public function test_show_returns_common_info(): void
    {
        // Given: 공통정보 생성
        $commonInfo = ProductCommonInfo::create([
            'name' => ['ko' => '일반 상품 안내', 'en' => 'General Product Info'],
            'content' => ['ko' => '상세 안내 내용', 'en' => 'Detailed info content'],
            'content_mode' => 'html',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // When: 상세 조회 API 호출
        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/product-common-infos/{$commonInfo->id}");

        // Then: 공통정보 상세 반환
        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $commonInfo->id);
        $response->assertJsonPath('data.name.ko', '일반 상품 안내');
        $response->assertJsonPath('data.content_mode', 'html');
        $response->assertJsonPath('data.is_default', true);
    }

    /**
     * 존재하지 않는 공통정보 조회 테스트
     */
    #[Test]
    public function test_show_returns_404_for_nonexistent_common_info(): void
    {
        // When: 존재하지 않는 ID로 조회
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/product-common-infos/99999');

        // Then: 404 반환
        $response->assertStatus(404);
    }

    /**
     * 공통정보 생성 테스트
     */
    #[Test]
    public function test_store_creates_common_info(): void
    {
        // Given: 생성 데이터
        $data = [
            'name' => ['ko' => '새 공통정보', 'en' => 'New Common Info'],
            'content' => ['ko' => '새 안내 내용입니다.', 'en' => 'New info content.'],
            'content_mode' => 'text',
            'is_default' => false,
            'is_active' => true,
        ];

        // When: 생성 API 호출
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/product-common-infos', $data);

        // Then: 생성 성공
        $response->assertStatus(201);
        $response->assertJsonPath('data.name.ko', '새 공통정보');
        $response->assertJsonPath('data.content_mode', 'text');
        $response->assertJsonPath('data.is_active', true);

        // DB에 저장 확인
        $this->assertDatabaseHas('ecommerce_product_common_infos', [
            'is_active' => true,
            'is_default' => false,
        ]);
    }

    /**
     * 공통정보 생성 시 기본값 설정 테스트
     */
    #[Test]
    public function test_store_sets_default_and_clears_previous_default(): void
    {
        // Given: 기존 기본값 공통정보
        $existingDefault = ProductCommonInfo::create([
            'name' => ['ko' => '기존 기본값', 'en' => 'Existing Default'],
            'content' => ['ko' => '기존 내용', 'en' => 'Existing content'],
            'content_mode' => 'text',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // 새로운 기본값 데이터
        $data = [
            'name' => ['ko' => '새 기본값', 'en' => 'New Default'],
            'content' => ['ko' => '새 내용', 'en' => 'New content'],
            'content_mode' => 'text',
            'is_default' => true,
            'is_active' => true,
        ];

        // When: 생성 API 호출
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/product-common-infos', $data);

        // Then: 생성 성공
        $response->assertStatus(201);
        $response->assertJsonPath('data.is_default', true);

        // 기존 기본값이 해제됨 확인
        $existingDefault->refresh();
        $this->assertFalse($existingDefault->is_default);

        // 새로 생성된 것이 기본값
        $newId = $response->json('data.id');
        $this->assertTrue(ProductCommonInfo::find($newId)->is_default);
    }

    /**
     * 공통정보 생성 시 필수 필드 검증 테스트
     */
    #[Test]
    public function test_store_validates_required_fields(): void
    {
        // Given: 필수 필드 누락 데이터 (현재 로케일 name 필수)
        $data = [
            'name' => ['ko' => ''],  // 빈 값
            'content' => ['ko' => '내용은 선택사항'],  // content는 nullable
        ];

        // When: 생성 API 호출
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/product-common-infos', $data);

        // Then: 검증 실패 (현재 로케일 name 필수 - LocaleRequiredTranslatable Rule)
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    /**
     * 공통정보 생성 시 content_mode 유효성 검증 테스트
     */
    #[Test]
    public function test_store_validates_content_mode(): void
    {
        // Given: 잘못된 content_mode 데이터
        $data = [
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'content' => ['ko' => '내용', 'en' => 'Content'],
            'content_mode' => 'invalid_mode',  // 잘못된 값
            'is_active' => true,
        ];

        // When: 생성 API 호출
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/product-common-infos', $data);

        // Then: 검증 실패
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['content_mode']);
    }

    /**
     * 공통정보 생성 시 이름 최대 길이 검증 테스트
     */
    #[Test]
    public function test_store_validates_name_max_length(): void
    {
        // Given: 이름이 100자를 초과하는 데이터
        $data = [
            'name' => ['ko' => str_repeat('가', 101), 'en' => 'Test'],
            'content' => ['ko' => '내용', 'en' => 'Content'],
            'content_mode' => 'text',
            'is_active' => true,
        ];

        // When: 생성 API 호출
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/product-common-infos', $data);

        // Then: 최대 길이 검증 실패 (LocaleRequiredTranslatable Rule)
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    /**
     * 공통정보 수정 테스트
     */
    #[Test]
    public function test_update_updates_common_info(): void
    {
        // Given: 기존 공통정보
        $commonInfo = ProductCommonInfo::create([
            'name' => ['ko' => '원래 이름', 'en' => 'Original Name'],
            'content' => ['ko' => '원래 내용', 'en' => 'Original Content'],
            'content_mode' => 'text',
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $data = [
            'name' => ['ko' => '변경된 이름', 'en' => 'Changed Name'],
            'content' => ['ko' => '변경된 내용', 'en' => 'Changed Content'],
            'content_mode' => 'html',
            'is_default' => false,
            'is_active' => false,
        ];

        // When: 수정 API 호출
        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/product-common-infos/{$commonInfo->id}", $data);

        // Then: 수정 성공
        $response->assertStatus(200);
        $response->assertJsonPath('data.name.ko', '변경된 이름');
        $response->assertJsonPath('data.content_mode', 'html');
        $response->assertJsonPath('data.is_active', false);

        // DB에 반영 확인
        $commonInfo->refresh();
        $this->assertEquals('변경된 이름', $commonInfo->name['ko']);
        $this->assertEquals('html', $commonInfo->content_mode);
        $this->assertFalse($commonInfo->is_active);
    }

    /**
     * 공통정보 수정 시 기본값 설정 테스트
     */
    #[Test]
    public function test_update_sets_default_and_clears_previous_default(): void
    {
        // Given: 기존 기본값 공통정보
        $existingDefault = ProductCommonInfo::create([
            'name' => ['ko' => '기존 기본값', 'en' => 'Existing Default'],
            'content' => ['ko' => '기존 내용', 'en' => 'Existing content'],
            'content_mode' => 'text',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // 수정할 공통정보
        $commonInfo = ProductCommonInfo::create([
            'name' => ['ko' => '다른 공통정보', 'en' => 'Another Common Info'],
            'content' => ['ko' => '다른 내용', 'en' => 'Another content'],
            'content_mode' => 'text',
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $data = [
            'name' => ['ko' => '다른 공통정보', 'en' => 'Another Common Info'],
            'content' => ['ko' => '다른 내용', 'en' => 'Another content'],
            'content_mode' => 'text',
            'is_default' => true,  // 기본값으로 설정
            'is_active' => true,
        ];

        // When: 수정 API 호출
        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/product-common-infos/{$commonInfo->id}", $data);

        // Then: 수정 성공
        $response->assertStatus(200);
        $response->assertJsonPath('data.is_default', true);

        // 기존 기본값이 해제됨 확인
        $existingDefault->refresh();
        $this->assertFalse($existingDefault->is_default);
    }

    /**
     * 공통정보 삭제 테스트
     */
    #[Test]
    public function test_destroy_deletes_common_info(): void
    {
        // Given: 기존 공통정보
        $commonInfo = ProductCommonInfo::create([
            'name' => ['ko' => '삭제할 공통정보', 'en' => 'To Delete'],
            'content' => ['ko' => '삭제할 내용', 'en' => 'Content to delete'],
            'content_mode' => 'text',
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // When: 삭제 API 호출
        $response = $this->actingAs($this->adminUser)
            ->deleteJson("/api/modules/sirsoft-ecommerce/admin/product-common-infos/{$commonInfo->id}");

        // Then: 삭제 성공
        $response->assertStatus(200);

        // DB에서 삭제 확인
        $this->assertDatabaseMissing('ecommerce_product_common_infos', [
            'id' => $commonInfo->id,
        ]);
    }

    /**
     * HTML 모드 공통정보 생성 테스트
     */
    #[Test]
    public function test_store_creates_html_mode_common_info(): void
    {
        // Given: HTML 내용 데이터
        $data = [
            'name' => ['ko' => 'HTML 공통정보', 'en' => 'HTML Common Info'],
            'content' => [
                'ko' => '<p>HTML <strong>내용</strong>입니다.</p>',
                'en' => '<p>HTML <strong>content</strong> here.</p>',
            ],
            'content_mode' => 'html',
            'is_default' => false,
            'is_active' => true,
        ];

        // When: 생성 API 호출
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/product-common-infos', $data);

        // Then: 생성 성공
        $response->assertStatus(201);
        $response->assertJsonPath('data.content_mode', 'html');
        $response->assertJsonPath('data.content.ko', '<p>HTML <strong>내용</strong>입니다.</p>');
    }

    /**
     * 인증 안된 사용자 접근 불가 테스트
     */
    #[Test]
    public function test_unauthenticated_user_cannot_access(): void
    {
        // When: 인증 없이 API 호출
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/admin/product-common-infos');

        // Then: 인증 필요 에러
        $response->assertStatus(401);
    }

}

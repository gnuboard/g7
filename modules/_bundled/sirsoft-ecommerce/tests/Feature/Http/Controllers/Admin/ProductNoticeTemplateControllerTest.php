<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\ProductNoticeTemplate;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * ProductNoticeTemplateController Feature 테스트
 *
 * 상품정보제공고시 템플릿 API 엔드포인트 테스트
 */
class ProductNoticeTemplateControllerTest extends ModuleTestCase
{
    protected User $adminUser;

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 관리자 사용자 생성 (상품정보제공고시 권한 포함)
        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.product-notice-templates.read',
            'sirsoft-ecommerce.product-notice-templates.create',
            'sirsoft-ecommerce.product-notice-templates.update',
            'sirsoft-ecommerce.product-notice-templates.delete',
        ]);
    }

    /**
     * 템플릿 목록 조회 테스트
     */
    #[Test]
    public function test_index_returns_templates(): void
    {
        // Given: 템플릿 생성
        ProductNoticeTemplate::create([
            'name' => ['ko' => '의류', 'en' => 'Clothing'],
            'fields' => [
                ['name' => ['ko' => '제조사', 'en' => 'Manufacturer'], 'content' => ['ko' => '테스트', 'en' => 'Test']],
            ],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        ProductNoticeTemplate::create([
            'name' => ['ko' => '전자제품', 'en' => 'Electronics'],
            'fields' => [
                ['name' => ['ko' => '제조국', 'en' => 'Country'], 'content' => ['ko' => '한국', 'en' => 'Korea']],
            ],
            'is_active' => true,
            'sort_order' => 2,
        ]);

        // When: 목록 조회 API 호출
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/product-notice-templates');

        // Then: 템플릿 목록 반환
        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data.data');
        $response->assertJsonStructure(['data' => ['data', 'abilities']]);
    }

    /**
     * 템플릿 목록 검색 테스트
     */
    #[Test]
    public function test_index_filters_by_search(): void
    {
        // Given: 템플릿 생성
        ProductNoticeTemplate::create([
            'name' => ['ko' => '의류', 'en' => 'Clothing'],
            'fields' => [],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        ProductNoticeTemplate::create([
            'name' => ['ko' => '전자제품', 'en' => 'Electronics'],
            'fields' => [],
            'is_active' => true,
            'sort_order' => 2,
        ]);

        // When: 검색으로 API 호출
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/product-notice-templates?search=의류');

        // Then: 검색된 템플릿만 반환
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.data');
    }

    /**
     * 템플릿 상세 조회 테스트
     */
    #[Test]
    public function test_show_returns_template(): void
    {
        // Given: 템플릿 생성
        $template = ProductNoticeTemplate::create([
            'name' => ['ko' => '의류', 'en' => 'Clothing'],
            'fields' => [
                ['name' => ['ko' => '제조사', 'en' => 'Manufacturer'], 'content' => ['ko' => '테스트', 'en' => 'Test']],
            ],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // When: 상세 조회 API 호출
        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/product-notice-templates/{$template->id}");

        // Then: 템플릿 상세 반환
        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $template->id);
        $response->assertJsonPath('data.name.ko', '의류');
    }

    /**
     * 존재하지 않는 템플릿 조회 테스트
     */
    #[Test]
    public function test_show_returns_404_for_nonexistent_template(): void
    {
        // When: 존재하지 않는 ID로 조회
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/product-notice-templates/99999');

        // Then: 404 반환
        $response->assertStatus(404);
    }

    /**
     * 템플릿 생성 테스트
     */
    #[Test]
    public function test_store_creates_template(): void
    {
        // Given: 생성 데이터
        $data = [
            'name' => ['ko' => '새 템플릿', 'en' => 'New Template'],
            'fields' => [
                ['name' => ['ko' => '항목1', 'en' => 'Item1'], 'content' => ['ko' => '내용1', 'en' => 'Content1']],
                ['name' => ['ko' => '항목2', 'en' => 'Item2'], 'content' => ['ko' => '내용2', 'en' => 'Content2']],
            ],
            'is_active' => true,
        ];

        // When: 생성 API 호출
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/product-notice-templates', $data);

        // Then: 생성 성공
        $response->assertStatus(201);
        $response->assertJsonPath('data.name.ko', '새 템플릿');
        $response->assertJsonPath('data.fields_count', 2);

        // DB에 저장 확인
        $this->assertDatabaseHas('ecommerce_product_notice_templates', [
            'is_active' => true,
        ]);
    }

    /**
     * 템플릿 생성 시 필수 필드 검증 테스트
     */
    #[Test]
    public function test_store_validates_required_fields(): void
    {
        // Given: 필수 필드 누락 데이터
        $data = [
            'name' => ['ko' => ''],  // 빈 값
            'fields' => [],  // 빈 배열
        ];

        // When: 생성 API 호출
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/product-notice-templates', $data);

        // Then: 검증 실패
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'fields']);
    }

    /**
     * 템플릿 생성 시 항목명(fields.*.name.ko) 필수 검증 테스트
     */
    #[Test]
    public function test_store_validates_field_name_required(): void
    {
        // Given: 항목명이 비어있는 데이터
        $data = [
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
            'fields' => [
                ['name' => ['ko' => '', 'en' => ''], 'content' => ['ko' => '내용', 'en' => 'Content']],
            ],
            'is_active' => true,
        ];

        // When: 생성 API 호출
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/product-notice-templates', $data);

        // Then: 항목명 필수 검증 실패
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['fields.0.name']);
    }

    /**
     * 템플릿 생성 시 항목내용(fields.*.content.ko) 필수 검증 테스트
     */
    #[Test]
    public function test_store_validates_field_content_required(): void
    {
        // Given: 항목내용이 비어있는 데이터
        $data = [
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
            'fields' => [
                ['name' => ['ko' => '항목명', 'en' => 'Field Name'], 'content' => ['ko' => '', 'en' => '']],
            ],
            'is_active' => true,
        ];

        // When: 생성 API 호출
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/product-notice-templates', $data);

        // Then: 항목내용 필수 검증 실패
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['fields.0.content']);
    }

    /**
     * 템플릿 생성 시 상품군명 최대 길이 검증 테스트
     */
    #[Test]
    public function test_store_validates_name_max_length(): void
    {
        // Given: 상품군명이 100자를 초과하는 데이터
        $data = [
            'name' => ['ko' => str_repeat('가', 101), 'en' => 'Test'],
            'fields' => [
                ['name' => ['ko' => '항목명', 'en' => 'Field'], 'content' => ['ko' => '내용', 'en' => 'Content']],
            ],
            'is_active' => true,
        ];

        // When: 생성 API 호출
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/product-notice-templates', $data);

        // Then: 최대 길이 검증 실패
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    /**
     * 템플릿 생성 시 항목명 최대 길이 검증 테스트
     */
    #[Test]
    public function test_store_validates_field_name_max_length(): void
    {
        // Given: 항목명이 200자를 초과하는 데이터
        $data = [
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test'],
            'fields' => [
                ['name' => ['ko' => str_repeat('가', 201), 'en' => 'Field'], 'content' => ['ko' => '내용', 'en' => 'Content']],
            ],
            'is_active' => true,
        ];

        // When: 생성 API 호출
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/product-notice-templates', $data);

        // Then: 최대 길이 검증 실패
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['fields.0.name']);
    }

    /**
     * 템플릿 생성 시 항목내용 최대 길이 검증 테스트
     */
    #[Test]
    public function test_store_validates_field_content_max_length(): void
    {
        // Given: 항목내용이 2000자를 초과하는 데이터
        $data = [
            'name' => ['ko' => '테스트 템플릿', 'en' => 'Test'],
            'fields' => [
                ['name' => ['ko' => '항목명', 'en' => 'Field'], 'content' => ['ko' => str_repeat('가', 2001), 'en' => 'Content']],
            ],
            'is_active' => true,
        ];

        // When: 생성 API 호출
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/product-notice-templates', $data);

        // Then: 최대 길이 검증 실패
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['fields.0.content']);
    }

    /**
     * 템플릿 수정 시 항목명/내용 필수 검증 테스트
     */
    #[Test]
    public function test_update_validates_field_name_and_content_required(): void
    {
        // Given: 기존 템플릿
        $template = ProductNoticeTemplate::create([
            'name' => ['ko' => '원래 이름', 'en' => 'Original Name'],
            'fields' => [
                ['name' => ['ko' => '항목', 'en' => 'Item'], 'content' => ['ko' => '내용', 'en' => 'Content']],
            ],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // 항목명과 내용이 빈 데이터
        $data = [
            'name' => ['ko' => '변경된 이름', 'en' => 'Changed Name'],
            'fields' => [
                ['name' => ['ko' => '', 'en' => ''], 'content' => ['ko' => '', 'en' => '']],
            ],
            'is_active' => true,
        ];

        // When: 수정 API 호출
        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/product-notice-templates/{$template->id}", $data);

        // Then: 검증 실패
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['fields.0.name', 'fields.0.content']);
    }

    /**
     * 템플릿 수정 테스트
     */
    #[Test]
    public function test_update_updates_template(): void
    {
        // Given: 기존 템플릿
        $template = ProductNoticeTemplate::create([
            'name' => ['ko' => '원래 이름', 'en' => 'Original Name'],
            'fields' => [
                ['name' => ['ko' => '항목', 'en' => 'Item'], 'content' => ['ko' => '내용', 'en' => 'Content']],
            ],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $data = [
            'name' => ['ko' => '변경된 이름', 'en' => 'Changed Name'],
            'fields' => [
                ['name' => ['ko' => '새항목', 'en' => 'New Item'], 'content' => ['ko' => '새내용', 'en' => 'New Content']],
            ],
            'is_active' => false,
        ];

        // When: 수정 API 호출
        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/product-notice-templates/{$template->id}", $data);

        // Then: 수정 성공
        $response->assertStatus(200);
        $response->assertJsonPath('data.name.ko', '변경된 이름');
        $response->assertJsonPath('data.is_active', false);

        // DB에 반영 확인
        $template->refresh();
        $this->assertEquals('변경된 이름', $template->name['ko']);
        $this->assertFalse($template->is_active);
    }

    /**
     * 템플릿 삭제 테스트
     */
    #[Test]
    public function test_destroy_deletes_template(): void
    {
        // Given: 기존 템플릿
        $template = ProductNoticeTemplate::create([
            'name' => ['ko' => '삭제할 템플릿', 'en' => 'To Delete'],
            'fields' => [
                ['name' => ['ko' => '항목', 'en' => 'Item'], 'content' => ['ko' => '내용', 'en' => 'Content']],
            ],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // When: 삭제 API 호출
        $response = $this->actingAs($this->adminUser)
            ->deleteJson("/api/modules/sirsoft-ecommerce/admin/product-notice-templates/{$template->id}");

        // Then: 삭제 성공
        $response->assertStatus(200);

        // DB에서 삭제 확인
        $this->assertDatabaseMissing('ecommerce_product_notice_templates', [
            'id' => $template->id,
        ]);
    }

    /**
     * 템플릿 복사 테스트
     */
    #[Test]
    public function test_copy_creates_duplicate_template(): void
    {
        // Given: 기존 템플릿
        $template = ProductNoticeTemplate::create([
            'name' => ['ko' => '원본 템플릿', 'en' => 'Original Template'],
            'fields' => [
                ['name' => ['ko' => '항목1', 'en' => 'Item1'], 'content' => ['ko' => '내용1', 'en' => 'Content1']],
                ['name' => ['ko' => '항목2', 'en' => 'Item2'], 'content' => ['ko' => '내용2', 'en' => 'Content2']],
            ],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // When: 복사 API 호출
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/product-notice-templates/{$template->id}/copy");

        // Then: 복사 성공
        $response->assertStatus(201);
        $response->assertJsonPath('data.fields_count', 2);

        // 복사본 이름 확인 (원본 이름 + " (복사)")
        $copiedName = $response->json('data.name.ko');
        $this->assertStringContainsString('원본 템플릿', $copiedName);
        $this->assertStringContainsString('복사', $copiedName);

        // DB에 2개 확인
        $this->assertEquals(2, ProductNoticeTemplate::count());
    }

    /**
     * 인증 안된 사용자 접근 불가 테스트
     */
    #[Test]
    public function test_unauthenticated_user_cannot_access(): void
    {
        // When: 인증 없이 API 호출
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/admin/product-notice-templates');

        // Then: 인증 필요 에러
        $response->assertStatus(401);
    }
}

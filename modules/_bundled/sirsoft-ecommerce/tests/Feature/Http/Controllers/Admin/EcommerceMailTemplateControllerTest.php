<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\EcommerceMailTemplate;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * EcommerceMailTemplateController Feature 테스트
 *
 * 이커머스 메일 템플릿 관리 API 엔드포인트 테스트
 */
class EcommerceMailTemplateControllerTest extends ModuleTestCase
{
    protected User $adminUser;

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 관리자 사용자 생성 (settings 읽기/수정 권한 포함)
        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.settings.read',
            'sirsoft-ecommerce.settings.update',
        ]);
    }

    // ========================================
    // 인증/권한 테스트
    // ========================================

    /**
     * 인증되지 않은 사용자가 목록 조회 시 401 반환 테스트
     */
    #[Test]
    public function test_index_returns_401_without_authentication(): void
    {
        // When: 인증 없이 API 호출
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/admin/mail-templates');

        // Then: 401 반환
        $response->assertStatus(401);
    }

    /**
     * 권한 없는 사용자가 목록 조회 시 403 반환 테스트
     */
    #[Test]
    public function test_index_returns_403_without_permission(): void
    {
        // Given: 권한 없는 관리자 사용자
        $userWithoutPermission = $this->createAdminUser();

        // When: 권한 없이 API 호출
        $response = $this->actingAs($userWithoutPermission)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/mail-templates');

        // Then: 403 반환
        $response->assertStatus(403);
    }

    // ========================================
    // index() 테스트
    // ========================================

    /**
     * 메일 템플릿 목록 페이지네이션 조회 테스트
     */
    #[Test]
    public function test_index_returns_paginated_list(): void
    {
        // Given: 메일 템플릿 생성
        $this->createTemplate(['type' => 'order_confirmed']);
        $this->createTemplate(['type' => 'order_shipped']);

        // When: 목록 조회 API 호출
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/mail-templates');

        // Then: 페이지네이션된 목록 반환
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'data' => [
                    '*' => ['id', 'type', 'subject', 'body', 'is_active'],
                ],
                'pagination' => ['current_page', 'last_page', 'per_page', 'total'],
            ],
        ]);
        $this->assertGreaterThanOrEqual(2, $response->json('data.pagination.total'));
    }

    /**
     * per_page 파라미터로 페이지 크기 변경 테스트
     */
    #[Test]
    public function test_index_paginates_with_per_page(): void
    {
        // Given: 여러 메일 템플릿 생성
        for ($i = 0; $i < 5; $i++) {
            $this->createTemplate(['type' => 'test_type_' . $i]);
        }

        // When: per_page=2로 목록 조회
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/mail-templates?per_page=2');

        // Then: 페이지 크기 2로 반환
        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('data.pagination.per_page'));
        $this->assertCount(2, $response->json('data.data'));
    }

    /**
     * 제목(subject) 검색 필터 테스트
     */
    #[Test]
    public function test_index_filters_by_subject_search(): void
    {
        // Given: 서로 다른 제목의 메일 템플릿 생성
        $this->createTemplate([
            'type' => 'order_confirmed',
            'subject' => ['ko' => '주문 확인', 'en' => 'Order Confirmed'],
        ]);
        $this->createTemplate([
            'type' => 'shipping_notice',
            'subject' => ['ko' => '배송 안내', 'en' => 'Shipping Notice'],
        ]);

        // When: 제목으로 검색 (search_type=subject)
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/mail-templates?search=' . urlencode('주문 확인') . '&search_type=subject');

        // Then: 해당 제목의 템플릿만 반환
        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.pagination.total'));
    }

    /**
     * 본문(body) 검색 필터 테스트
     */
    #[Test]
    public function test_index_filters_by_body_search(): void
    {
        // Given: 서로 다른 본문의 메일 템플릿 생성
        $this->createTemplate([
            'type' => 'order_confirmed',
            'body' => ['ko' => '<p>주문이 확인되었습니다</p>', 'en' => '<p>Your order is confirmed</p>'],
        ]);
        $this->createTemplate([
            'type' => 'shipping_notice',
            'body' => ['ko' => '<p>배송이 시작되었습니다</p>', 'en' => '<p>Your shipment is on the way</p>'],
        ]);

        // When: 본문으로 검색 (search_type=body)
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/mail-templates?search=' . urlencode('배송이 시작') . '&search_type=body');

        // Then: 해당 본문의 템플릿만 반환
        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.pagination.total'));
    }

    /**
     * 전체(all) 검색 타입 필터 테스트
     */
    #[Test]
    public function test_index_filters_by_all_search_type(): void
    {
        // Given: 제목과 본문이 다른 메일 템플릿 생성
        $this->createTemplate([
            'type' => 'order_confirmed',
            'subject' => ['ko' => '주문 확인', 'en' => 'Order Confirmed'],
            'body' => ['ko' => '<p>주문 본문</p>', 'en' => '<p>Order body</p>'],
        ]);
        $this->createTemplate([
            'type' => 'shipping_notice',
            'subject' => ['ko' => '배송 안내', 'en' => 'Shipping Notice'],
            'body' => ['ko' => '<p>특별한 내용</p>', 'en' => '<p>Special content</p>'],
        ]);

        // When: 전체 검색 (search_type=all, 제목에 '주문'이 있는 것)
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/mail-templates?search=' . urlencode('주문') . '&search_type=all');

        // Then: 제목 또는 본문에 '주문'이 포함된 템플릿 반환
        $response->assertStatus(200);
        $totalFound = $response->json('data.pagination.total');
        $this->assertGreaterThanOrEqual(1, $totalFound);
    }

    // ========================================
    // preview() 테스트
    // ========================================

    /**
     * 메일 템플릿 미리보기 렌더링 결과 반환 테스트
     */
    #[Test]
    public function test_preview_returns_rendered_result(): void
    {
        // Given: 미리보기 요청 데이터
        $previewData = [
            'subject' => '주문 확인 - {order_number}',
            'body' => '<p>안녕하세요 {customer_name}님, 주문번호 {order_number}이 확인되었습니다.</p>',
            'variables' => [
                ['key' => 'order_number'],
                ['key' => 'customer_name'],
            ],
        ];

        // When: 미리보기 API 호출
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/mail-templates/preview', $previewData);

        // Then: 렌더링된 제목과 본문 반환
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'data' => ['subject', 'body'],
        ]);
    }

    // ========================================
    // update() 테스트
    // ========================================

    /**
     * 메일 템플릿 수정 저장 테스트
     */
    #[Test]
    public function test_update_saves_template(): void
    {
        // Given: 기존 메일 템플릿
        $template = $this->createTemplate();

        $updateData = [
            'subject' => ['ko' => '수정된 제목', 'en' => 'Updated Subject'],
            'body' => ['ko' => '<p>수정된 본문</p>', 'en' => '<p>Updated Body</p>'],
            'is_active' => false,
        ];

        // When: 수정 API 호출
        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/mail-templates/{$template->id}", $updateData);

        // Then: 수정 성공
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        // DB 확인
        $template->refresh();
        $this->assertEquals('수정된 제목', $template->subject['ko']);
        $this->assertEquals('Updated Subject', $template->subject['en']);
        $this->assertEquals('<p>수정된 본문</p>', $template->body['ko']);
        $this->assertFalse($template->is_default);
        $this->assertEquals($this->adminUser->id, $template->updated_by);
    }

    // ========================================
    // toggleActive() 테스트
    // ========================================

    /**
     * 메일 템플릿 활성 상태 토글 테스트
     */
    #[Test]
    public function test_toggle_active_flips_state(): void
    {
        // Given: 활성 상태의 메일 템플릿
        $template = $this->createTemplate(['is_active' => true]);

        // When: 토글 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson("/api/modules/sirsoft-ecommerce/admin/mail-templates/{$template->id}/toggle-active");

        // Then: 비활성으로 변경
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $template->refresh();
        $this->assertFalse($template->is_active);

        // When: 다시 토글 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson("/api/modules/sirsoft-ecommerce/admin/mail-templates/{$template->id}/toggle-active");

        // Then: 활성으로 복원
        $response->assertStatus(200);
        $template->refresh();
        $this->assertTrue($template->is_active);
    }

    // ========================================
    // reset() 테스트
    // ========================================

    /**
     * 메일 템플릿 기본값 복원 테스트
     */
    #[Test]
    public function test_reset_restores_default_data(): void
    {
        // Given: 시더에 정의된 타입의 템플릿을 수정된 상태로 생성
        $seeder = new \Modules\Sirsoft\Ecommerce\Database\Seeders\EcommerceMailTemplateSeeder;
        $defaults = $seeder->getDefaultTemplates();

        // 시더에 기본 템플릿이 존재해야 테스트 가능
        if (empty($defaults)) {
            $this->markTestSkipped('시더에 기본 템플릿 데이터가 없습니다.');
        }

        $defaultTemplate = $defaults[0];
        $template = $this->createTemplate([
            'type' => $defaultTemplate['type'],
            'subject' => ['ko' => '사용자가 수정한 제목', 'en' => 'User Modified Subject'],
            'body' => ['ko' => '<p>사용자가 수정한 본문</p>', 'en' => '<p>User Modified Body</p>'],
            'is_default' => false,
        ]);

        // When: 기본값 복원 API 호출
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/mail-templates/{$template->id}/reset");

        // Then: 시더 기본값으로 복원 성공
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $template->refresh();
        $this->assertEquals($defaultTemplate['subject'], $template->subject);
        $this->assertEquals($defaultTemplate['body'], $template->body);
        $this->assertTrue($template->is_active);
        $this->assertTrue($template->is_default);
    }

    // ========================================
    // 헬퍼 메서드
    // ========================================

    /**
     * 테스트용 메일 템플릿을 생성합니다.
     *
     * @param array $attributes 추가/오버라이드 속성
     * @return EcommerceMailTemplate 생성된 메일 템플릿
     */
    private function createTemplate(array $attributes = []): EcommerceMailTemplate
    {
        return EcommerceMailTemplate::create(array_merge([
            'type' => 'test_' . uniqid(),
            'subject' => ['ko' => '테스트 제목', 'en' => 'Test Subject'],
            'body' => ['ko' => '<p>테스트 본문</p>', 'en' => '<p>Test Body</p>'],
            'variables' => [['key' => 'name', 'description' => '이름']],
            'is_active' => true,
            'is_default' => true,
        ], $attributes));
    }
}

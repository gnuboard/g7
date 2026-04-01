<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__ . '/../../ModuleTestCase.php';

use App\Models\User;
use Modules\Sirsoft\Board\Models\BoardMailTemplate;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 메일 템플릿 API 테스트
 *
 * BoardMailTemplateController의 index, update, toggleActive, preview, reset을 검증합니다.
 */
class BoardMailTemplateControllerTest extends ModuleTestCase
{
    /**
     * @var User 관리자 사용자 (settings.read + settings.update 권한)
     */
    protected User $admin;

    /**
     * @var User 일반 사용자 (권한 없음)
     */
    protected User $normalUser;

    /**
     * @var string API 베이스 URL
     */
    private string $baseUrl = '/api/modules/sirsoft-board/admin/mail-templates';

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser([
            'sirsoft-board.settings.read',
            'sirsoft-board.settings.update',
        ]);

        $this->normalUser = $this->createUser();
    }

    /**
     * 테스트 정리
     */
    protected function tearDown(): void
    {
        // 테스트에서 생성한 메일 템플릿 삭제
        BoardMailTemplate::where('type', 'like', 'test_%')->delete();

        parent::tearDown();
    }

    // ========================================
    // 인증/권한 테스트
    // ========================================

    /**
     * 비인증 사용자는 목록을 조회할 수 없음
     */
    public function test_index_returns_401_without_authentication(): void
    {
        $response = $this->getJson($this->baseUrl);

        $response->assertStatus(401);
    }

    /**
     * 권한 없는 사용자는 목록을 조회할 수 없음
     */
    public function test_index_returns_403_without_permission(): void
    {
        $response = $this->actingAs($this->normalUser)
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson($this->baseUrl);

        $response->assertStatus(403);
    }

    // ========================================
    // index (페이지네이션 목록 조회) 테스트
    // ========================================

    /**
     * 관리자가 메일 템플릿 목록을 페이지네이션하여 조회할 수 있는지 확인
     */
    public function test_index_returns_paginated_list(): void
    {
        $this->createTemplate(['type' => 'test_type_a']);
        $this->createTemplate(['type' => 'test_type_b']);

        $response = $this->authRequest()
            ->getJson($this->baseUrl);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'data' => [
                        '*' => ['id', 'type', 'subject', 'body', 'variables', 'is_active', 'is_default'],
                    ],
                    'pagination' => ['current_page', 'last_page', 'per_page', 'total'],
                ],
            ]);

        // 생성한 템플릿이 결과에 포함되어야 함
        $types = collect($response->json('data.data'))->pluck('type')->toArray();
        $this->assertContains('test_type_a', $types);
        $this->assertContains('test_type_b', $types);
    }

    /**
     * per_page 파라미터로 페이지 크기를 조절할 수 있는지 확인
     */
    public function test_index_paginates_with_per_page(): void
    {
        // 기존 데이터 영향 배제를 위해 5개 생성
        for ($i = 1; $i <= 5; $i++) {
            $this->createTemplate(['type' => "test_paginate_{$i}"]);
        }

        $response = $this->authRequest()
            ->getJson($this->baseUrl . '?per_page=2');

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('data.pagination.per_page'));
        $this->assertCount(2, $response->json('data.data'));
    }

    /**
     * search_type=subject으로 제목 검색이 동작하는지 확인
     */
    public function test_index_filters_by_subject_search(): void
    {
        $this->createTemplate([
            'type' => 'test_subject_match',
            'subject' => ['ko' => '고유한검색키워드제목', 'en' => 'Unique Subject'],
        ]);
        $this->createTemplate([
            'type' => 'test_subject_nomatch',
            'subject' => ['ko' => '다른 제목', 'en' => 'Other Subject'],
        ]);

        $response = $this->authRequest()
            ->getJson($this->baseUrl . '?search=고유한검색키워드제목&search_type=subject');

        $response->assertStatus(200);

        $types = collect($response->json('data.data'))->pluck('type')->toArray();
        $this->assertContains('test_subject_match', $types);
        $this->assertNotContains('test_subject_nomatch', $types);
    }

    /**
     * search_type=body로 본문 검색이 동작하는지 확인
     */
    public function test_index_filters_by_body_search(): void
    {
        $this->createTemplate([
            'type' => 'test_body_match',
            'body' => ['ko' => '<p>고유본문검색키워드</p>', 'en' => '<p>Unique Body</p>'],
        ]);
        $this->createTemplate([
            'type' => 'test_body_nomatch',
            'body' => ['ko' => '<p>다른 본문</p>', 'en' => '<p>Other Body</p>'],
        ]);

        $response = $this->authRequest()
            ->getJson($this->baseUrl . '?search=고유본문검색키워드&search_type=body');

        $response->assertStatus(200);

        $types = collect($response->json('data.data'))->pluck('type')->toArray();
        $this->assertContains('test_body_match', $types);
        $this->assertNotContains('test_body_nomatch', $types);
    }

    /**
     * search_type=all로 제목+본문 통합 검색이 동작하는지 확인
     */
    public function test_index_filters_by_all_search_type(): void
    {
        $this->createTemplate([
            'type' => 'test_all_in_subject',
            'subject' => ['ko' => '통합검색고유키', 'en' => 'All Search'],
            'body' => ['ko' => '<p>일반 본문</p>', 'en' => '<p>Normal Body</p>'],
        ]);
        $this->createTemplate([
            'type' => 'test_all_in_body',
            'subject' => ['ko' => '일반 제목', 'en' => 'Normal Subject'],
            'body' => ['ko' => '<p>통합검색고유키</p>', 'en' => '<p>Normal Body</p>'],
        ]);
        $this->createTemplate([
            'type' => 'test_all_nomatch',
            'subject' => ['ko' => '다른 제목', 'en' => 'Other'],
            'body' => ['ko' => '<p>다른 본문</p>', 'en' => '<p>Other</p>'],
        ]);

        $response = $this->authRequest()
            ->getJson($this->baseUrl . '?search=통합검색고유키&search_type=all');

        $response->assertStatus(200);

        $types = collect($response->json('data.data'))->pluck('type')->toArray();
        $this->assertContains('test_all_in_subject', $types);
        $this->assertContains('test_all_in_body', $types);
        $this->assertNotContains('test_all_nomatch', $types);
    }

    // ========================================
    // preview (미리보기) 테스트
    // ========================================

    /**
     * 미리보기가 렌더링 결과를 반환하는지 확인
     */
    public function test_preview_returns_rendered_result(): void
    {
        $response = $this->authRequest()
            ->postJson($this->baseUrl . '/preview', [
                'subject' => '안녕하세요 {name}님',
                'body' => '<p>{name}님에게 보내는 메일입니다.</p>',
                'variables' => [
                    ['key' => 'name'],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['subject', 'body'],
            ]);

        // 미리보기는 변수를 {key} 형태로 치환
        $this->assertStringContainsString('{name}', $response->json('data.subject'));
        $this->assertStringContainsString('{name}', $response->json('data.body'));
    }

    // ========================================
    // update (수정) 테스트
    // ========================================

    /**
     * 관리자가 메일 템플릿을 수정할 수 있는지 확인
     */
    public function test_update_saves_template(): void
    {
        $template = $this->createTemplate(['type' => 'test_update']);

        $response = $this->authRequest()
            ->putJson($this->baseUrl . '/' . $template->id, [
                'subject' => ['ko' => '수정된 제목', 'en' => 'Updated Subject'],
                'body' => ['ko' => '<p>수정된 본문</p>', 'en' => '<p>Updated Body</p>'],
            ]);

        $response->assertStatus(200);

        // DB에 반영되었는지 확인
        $template->refresh();
        $this->assertEquals('수정된 제목', $template->subject['ko']);
        $this->assertEquals('Updated Subject', $template->subject['en']);
        $this->assertEquals('<p>수정된 본문</p>', $template->body['ko']);
        $this->assertEquals('<p>Updated Body</p>', $template->body['en']);
        // 수정 후 is_default가 false로 변경되어야 함
        $this->assertFalse($template->is_default);
    }

    // ========================================
    // toggleActive (활성 상태 토글) 테스트
    // ========================================

    /**
     * 활성 상태를 토글할 수 있는지 확인
     */
    public function test_toggle_active_flips_state(): void
    {
        $template = $this->createTemplate([
            'type' => 'test_toggle',
            'is_active' => true,
        ]);

        // true → false
        $response = $this->authRequest()
            ->patchJson($this->baseUrl . '/' . $template->id . '/toggle-active');

        $response->assertStatus(200);
        $template->refresh();
        $this->assertFalse($template->is_active);

        // false → true
        $response = $this->authRequest()
            ->patchJson($this->baseUrl . '/' . $template->id . '/toggle-active');

        $response->assertStatus(200);
        $template->refresh();
        $this->assertTrue($template->is_active);
    }

    // ========================================
    // reset (기본값 복원) 테스트
    // ========================================

    /**
     * 시더 기본값으로 복원할 수 있는지 확인
     */
    public function test_reset_restores_default_data(): void
    {
        // new_comment 유형 (시더에 기본 데이터 존재)
        $template = $this->createTemplate([
            'type' => 'new_comment',
            'subject' => ['ko' => '사용자가 변경한 제목', 'en' => 'User Changed'],
            'body' => ['ko' => '<p>사용자가 변경한 본문</p>', 'en' => '<p>User Changed</p>'],
            'is_default' => false,
        ]);

        $response = $this->authRequest()
            ->postJson($this->baseUrl . '/' . $template->id . '/reset');

        $response->assertStatus(200);

        // DB에서 시더 기본값으로 복원되었는지 확인
        $template->refresh();
        $this->assertTrue($template->is_default);
        $this->assertTrue($template->is_active);
        // 시더의 new_comment 기본 제목 확인
        $this->assertStringContainsString('새 댓글', $template->subject['ko']);
    }

    // ========================================
    // 헬퍼 메서드
    // ========================================

    /**
     * 테스트용 메일 템플릿을 생성합니다.
     *
     * @param array $attributes 오버라이드 속성
     * @return BoardMailTemplate 생성된 템플릿
     */
    private function createTemplate(array $attributes = []): BoardMailTemplate
    {
        return BoardMailTemplate::create(array_merge([
            'type' => 'test_' . uniqid(),
            'subject' => ['ko' => '테스트 제목', 'en' => 'Test Subject'],
            'body' => ['ko' => '<p>테스트 본문</p>', 'en' => '<p>Test Body</p>'],
            'variables' => [['key' => 'name', 'description' => '이름']],
            'is_active' => true,
            'is_default' => true,
        ], $attributes));
    }

    /**
     * 인증된 관리자 요청 헬퍼
     *
     * @return static
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->admin->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ]);
    }
}

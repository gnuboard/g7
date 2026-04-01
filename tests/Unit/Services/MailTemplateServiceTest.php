<?php

namespace Tests\Unit\Services;

use App\Models\MailTemplate;
use App\Models\User;
use App\Services\MailTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * MailTemplateService 테스트
 *
 * 서비스의 CRUD, resolveTemplate 캐시, toggleActive, resetToDefault를 검증합니다.
 */
class MailTemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    private MailTemplateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->service = app(MailTemplateService::class);
    }

    // ========================================================================
    // getTemplatesForSettings
    // ========================================================================

    /**
     * 전체 템플릿 목록을 반환
     */
    public function test_get_templates_for_settings_returns_all(): void
    {
        MailTemplate::factory()->withType('welcome')->create();
        MailTemplate::factory()->withType('reset_password')->create();
        MailTemplate::factory()->withType('password_changed')->inactive()->create();

        $result = $this->service->getTemplatesForSettings();

        $this->assertCount(3, $result);
    }

    // ========================================================================
    // updateTemplate
    // ========================================================================

    /**
     * 템플릿 수정이 올바르게 동작
     */
    public function test_update_template_saves_data(): void
    {
        $user = User::factory()->create();
        $template = MailTemplate::factory()->withType('welcome')->create();

        $updated = $this->service->updateTemplate($template, [
            'subject' => ['ko' => '수정된 제목', 'en' => 'Updated Subject'],
            'body' => ['ko' => '<p>수정됨</p>', 'en' => '<p>Updated</p>'],
            'is_active' => true,
        ], $user->id);

        $this->assertEquals(['ko' => '수정된 제목', 'en' => 'Updated Subject'], $updated->subject);
        $this->assertFalse($updated->is_default);
        $this->assertEquals($user->id, $updated->updated_by);
    }

    /**
     * 수정 시 캐시가 무효화됨
     */
    public function test_update_template_invalidates_cache(): void
    {
        $template = MailTemplate::factory()->withType('welcome')->create();
        Cache::put('mail_template:core:welcome', $template, 3600);

        $this->service->updateTemplate($template, [
            'subject' => ['ko' => '새 제목'],
            'body' => ['ko' => '<p>새 본문</p>'],
        ]);

        $this->assertFalse(Cache::has('mail_template:core:welcome'));
    }

    // ========================================================================
    // toggleActive
    // ========================================================================

    /**
     * 활성 상태를 토글
     */
    public function test_toggle_active_flips_state(): void
    {
        $template = MailTemplate::factory()->create(['is_active' => true]);

        $toggled = $this->service->toggleActive($template);

        $this->assertFalse($toggled->is_active);
    }

    /**
     * 비활성→활성 토글
     */
    public function test_toggle_active_activates_inactive(): void
    {
        $template = MailTemplate::factory()->inactive()->create();

        $toggled = $this->service->toggleActive($template);

        $this->assertTrue($toggled->is_active);
    }

    /**
     * 토글 시 캐시가 무효화됨
     */
    public function test_toggle_active_invalidates_cache(): void
    {
        $template = MailTemplate::factory()->withType('reset_password')->create();
        Cache::put('mail_template:core:reset_password', $template, 3600);

        $this->service->toggleActive($template);

        $this->assertFalse(Cache::has('mail_template:core:reset_password'));
    }

    // ========================================================================
    // resolveTemplate (캐시)
    // ========================================================================

    /**
     * resolveTemplate이 활성 템플릿을 반환
     */
    public function test_resolve_template_returns_active(): void
    {
        MailTemplate::factory()->withType('welcome')->create(['is_active' => true]);

        $result = $this->service->resolveTemplate('welcome');

        $this->assertNotNull($result);
        $this->assertEquals('welcome', $result->type);
    }

    /**
     * resolveTemplate이 비활성 템플릿에 null 반환
     */
    public function test_resolve_template_returns_null_for_inactive(): void
    {
        MailTemplate::factory()->withType('welcome')->inactive()->create();

        $result = $this->service->resolveTemplate('welcome');

        $this->assertNull($result);
    }

    /**
     * resolveTemplate이 결과를 캐싱
     */
    public function test_resolve_template_caches_result(): void
    {
        MailTemplate::factory()->withType('welcome')->create(['is_active' => true]);

        $this->service->resolveTemplate('welcome');

        $this->assertTrue(Cache::has('mail_template:core:welcome'));
    }

    // ========================================================================
    // getPreview
    // ========================================================================

    /**
     * 미리보기가 변수를 placeholder로 치환
     */
    public function test_get_preview_replaces_variables(): void
    {
        $data = [
            'subject' => 'Hello {name}',
            'body' => '<p>Welcome to {app_name}</p>',
            'variables' => [
                ['key' => 'name'],
                ['key' => 'app_name'],
            ],
        ];

        $result = $this->service->getPreview($data);

        $this->assertEquals('Hello {name}', $result['subject']);
        $this->assertEquals('<p>Welcome to {app_name}</p>', $result['body']);
    }

    // ========================================================================
    // resetToDefault
    // ========================================================================

    // ========================================================================
    // getTemplates (페이지네이션)
    // ========================================================================

    /**
     * 페이지네이션된 목록을 반환
     */
    public function test_get_templates_returns_paginated(): void
    {
        MailTemplate::factory()->count(5)->create();

        $result = $this->service->getTemplates([], 2);

        $this->assertEquals(5, $result->total());
        $this->assertCount(2, $result->items());
        $this->assertEquals(3, $result->lastPage());
    }

    /**
     * 검색 필터가 동작
     */
    public function test_get_templates_filters_by_search(): void
    {
        MailTemplate::factory()->withType('welcome')->create([
            'subject' => ['ko' => '환영 메일', 'en' => 'Welcome'],
        ]);
        MailTemplate::factory()->withType('reset_password')->create([
            'subject' => ['ko' => '비밀번호 재설정', 'en' => 'Reset Password'],
        ]);

        $result = $this->service->getTemplates(['search' => '환영', 'search_type' => 'subject']);

        $this->assertEquals(1, $result->total());
    }

    // ========================================================================
    // resetToDefault
    // ========================================================================

    /**
     * 기본값으로 복원
     */
    public function test_reset_to_default_restores_data(): void
    {
        $template = MailTemplate::factory()->withType('welcome')->create([
            'subject' => ['ko' => '사용자 수정'],
            'body' => ['ko' => '<p>수정됨</p>'],
            'is_default' => false,
        ]);

        $defaultData = [
            'subject' => ['ko' => '원래 제목', 'en' => 'Original Subject'],
            'body' => ['ko' => '<p>원래 본문</p>', 'en' => '<p>Original Body</p>'],
            'variables' => [['key' => 'name', 'description' => '이름']],
        ];

        $result = $this->service->resetToDefault($template, $defaultData);

        $this->assertEquals(['ko' => '원래 제목', 'en' => 'Original Subject'], $result->subject);
        $this->assertTrue($result->is_default);
        $this->assertTrue($result->is_active);
    }
}

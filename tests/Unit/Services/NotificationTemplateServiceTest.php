<?php

namespace Tests\Unit\Services;

use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Services\NotificationTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NotificationTemplateService 테스트
 *
 * 알림 템플릿 조회, 캐싱, 수정, 미리보기 동작을 검증합니다.
 */
class NotificationTemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    private NotificationTemplateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(NotificationTemplateService::class);
    }

    /**
     * resolve()가 활성 템플릿을 반환하는지 확인
     */
    public function test_resolve_returns_active_template(): void
    {
        $definition = NotificationDefinition::create([
            'type' => 'test_resolve',
            'hook_prefix' => 'core.test',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '테스트'],
            'variables' => [],
            'channels' => ['mail'],
            'hooks' => [],
            'is_active' => true,
            'is_default' => true,
        ]);

        NotificationTemplate::create([
            'definition_id' => $definition->id,
            'channel' => 'mail',
            'subject' => ['ko' => '제목', 'en' => 'Subject'],
            'body' => ['ko' => '본문', 'en' => 'Body'],
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->service->invalidateCache('test_resolve', 'mail');

        $result = $this->service->resolve('test_resolve', 'mail');

        $this->assertNotNull($result);
        $this->assertEquals('mail', $result->channel);
    }

    /**
     * resolve()가 비활성 템플릿을 반환하지 않는지 확인
     */
    public function test_resolve_returns_null_for_inactive_template(): void
    {
        $definition = NotificationDefinition::create([
            'type' => 'test_inactive',
            'hook_prefix' => 'core.test',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '테스트'],
            'variables' => [],
            'channels' => ['mail'],
            'hooks' => [],
            'is_active' => true,
            'is_default' => true,
        ]);

        NotificationTemplate::create([
            'definition_id' => $definition->id,
            'channel' => 'mail',
            'subject' => ['ko' => '제목'],
            'body' => ['ko' => '본문'],
            'is_active' => false,
            'is_default' => true,
        ]);

        $this->service->invalidateCache('test_inactive', 'mail');

        $result = $this->service->resolve('test_inactive', 'mail');

        $this->assertNull($result);
    }

    /**
     * updateTemplate()이 템플릿을 수정하는지 확인
     */
    public function test_update_template(): void
    {
        $definition = NotificationDefinition::create([
            'type' => 'test_update',
            'hook_prefix' => 'core.test',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '테스트'],
            'variables' => [],
            'channels' => ['mail'],
            'hooks' => [],
            'is_active' => true,
            'is_default' => true,
        ]);

        $template = NotificationTemplate::create([
            'definition_id' => $definition->id,
            'channel' => 'mail',
            'subject' => ['ko' => '원본 제목'],
            'body' => ['ko' => '원본 본문'],
            'is_active' => true,
            'is_default' => true,
        ]);

        $updated = $this->service->updateTemplate($template, [
            'subject' => ['ko' => '수정된 제목'],
            'body' => ['ko' => '수정된 본문'],
        ]);

        $this->assertEquals(['ko' => '수정된 제목'], $updated->subject);
        $this->assertEquals(['ko' => '수정된 본문'], $updated->body);
    }

    /**
     * toggleActive()가 활성 상태를 반전하는지 확인
     */
    public function test_toggle_active(): void
    {
        $definition = NotificationDefinition::create([
            'type' => 'test_toggle',
            'hook_prefix' => 'core.test',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '테스트'],
            'variables' => [],
            'channels' => ['mail'],
            'hooks' => [],
            'is_active' => true,
            'is_default' => true,
        ]);

        $template = NotificationTemplate::create([
            'definition_id' => $definition->id,
            'channel' => 'mail',
            'subject' => ['ko' => '제목'],
            'body' => ['ko' => '본문'],
            'is_active' => true,
            'is_default' => true,
        ]);

        $result = $this->service->toggleActive($template);

        $this->assertFalse($result->is_active);
    }

    /**
     * getPreview()가 변수를 치환하는지 확인
     */
    public function test_get_preview_replaces_variables(): void
    {
        $result = $this->service->getPreview([
            'subject' => ['ko' => '{name}님 환영합니다'],
            'body' => ['ko' => '<p>{app_name}입니다</p>'],
            'locale' => 'ko',
            'variables' => [
                'name' => '홍길동',
                'app_name' => 'G7',
            ],
        ]);

        $this->assertEquals('홍길동님 환영합니다', $result['subject']);
        $this->assertEquals('<p>G7입니다</p>', $result['body']);
    }
}

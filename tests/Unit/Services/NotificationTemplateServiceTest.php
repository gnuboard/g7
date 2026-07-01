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
     * getDefaultTemplateData()가 click_url과 recipients를 포함하는지 확인 (복원 누락 회귀)
     *
     * config/core.php 의 welcome/database 채널은 recipients 와 click_url='/mypage' 를 가진다.
     * 복원 데이터에 두 필드가 누락되면 관리자 수정값이 잔존하므로 추출 화이트리스트를 검증한다.
     */
    public function test_get_default_template_data_includes_click_url_and_recipients(): void
    {
        $data = $this->service->getDefaultTemplateData('welcome', 'database');

        $this->assertArrayHasKey('click_url', $data);
        $this->assertArrayHasKey('recipients', $data);
        $this->assertEquals('/mypage', $data['click_url']);
        $this->assertEquals([['type' => 'trigger_user']], $data['recipients']);
    }

    /**
     * config에 click_url이 없는 채널은 null로 복원되는지 확인 (정상값 비파괴)
     *
     * welcome/mail 채널은 recipients 만 있고 click_url 이 없으므로 null 이어야 한다.
     */
    public function test_get_default_template_data_null_click_url_when_absent_in_config(): void
    {
        $data = $this->service->getDefaultTemplateData('welcome', 'mail');

        $this->assertArrayHasKey('click_url', $data);
        $this->assertNull($data['click_url']);
        $this->assertEquals([['type' => 'trigger_user']], $data['recipients']);
    }

    /**
     * resetToDefault()가 관리자 수정한 click_url/recipients를 기본값으로 복원하는지 확인 (회귀)
     *
     * 관리자가 click_url/recipients 를 수정한 뒤 복원하면 config 기본값으로 돌아가야 한다.
     * 수정 전에는 subject/body 만 복원되고 두 필드는 수정본이 잔존하는 결함이 있었다.
     */
    public function test_reset_to_default_restores_click_url_and_recipients(): void
    {
        $definition = NotificationDefinition::create([
            'type' => 'welcome',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '회원가입 환영'],
            'variables' => [],
            'channels' => ['database'],
            'hooks' => [],
            'is_active' => true,
            'is_default' => true,
        ]);

        // 관리자가 click_url/recipients 를 임의 값으로 수정한 상태
        $template = NotificationTemplate::create([
            'definition_id' => $definition->id,
            'channel' => 'database',
            'subject' => ['ko' => '수정된 제목'],
            'body' => ['ko' => '수정된 본문'],
            'click_url' => '/custom-edited-url',
            'recipients' => [['type' => 'admin']],
            'is_active' => true,
            'is_default' => false,
            'user_overrides' => ['click_url' => true, 'recipients' => true],
        ]);

        $defaultData = $this->service->getDefaultTemplateData('welcome', 'database');
        $restored = $this->service->resetToDefault($template, $defaultData);

        $this->assertEquals('/mypage', $restored->click_url);
        $this->assertEquals([['type' => 'trigger_user']], $restored->recipients);
        $this->assertNull($restored->user_overrides);
        $this->assertTrue($restored->is_default);
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

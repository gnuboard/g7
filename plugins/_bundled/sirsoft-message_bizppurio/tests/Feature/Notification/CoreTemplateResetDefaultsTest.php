<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Feature\Notification;

use App\Extension\HookListenerRegistrar;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationTemplateService;
use Plugins\Sirsoft\MessageBizppurio\Listeners\SeedChannelTemplatesListener;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * 코어 [기본값 복원]이 플러그인이 시드한 alimtalk 채널 template 에서도 동작한다 (#597 §18.7 C2).
 *
 * 실측 결함: welcome 알림톡 행의 [기본값 복원] → 404 "기본 템플릿 데이터를 찾을 수 없습니다".
 * 플러그인이 시딩 훅(seed.*.notifications.translations)으로 행을 만들어 놓고, 복원 경로가
 * 통과하는 필터(core.notification.filter_default_definitions)에는 참여하지 않아서였다.
 *
 * 리스너 메서드를 직접 부르지 않는다 — 실제 등록 경로(HookListenerRegistrar)로 올린 뒤
 * 코어 서비스·엔드포인트의 관찰 가능한 결과(복원값·행 상태)로 판정한다.
 */
class CoreTemplateResetDefaultsTest extends PluginTestCase
{
    /** 리스너를 실제 훅 파이프라인에 등록합니다 (getSubscribedHooks 기반). */
    private function registerListener(): void
    {
        HookListenerRegistrar::register(SeedChannelTemplatesListener::class, 'plugin:sirsoft-message_bizppurio');
    }

    /** config/core.php welcome 정의의 database 채널 본문(ko) — 시딩·복원이 공유하는 출처. */
    private function welcomeDatabaseBodyKo(): string
    {
        foreach (config('core.notification_definitions.welcome.templates', []) as $template) {
            if (($template['channel'] ?? null) === 'database') {
                return (string) $template['body']['ko'];
            }
        }

        $this->fail('config/core.php welcome 정의에 database 채널 template 이 없다.');
    }

    /** welcome 정의 + 운영자가 손본(is_default=false) alimtalk 채널 행. */
    private function modifiedAlimtalkTemplate(): NotificationTemplate
    {
        $definition = NotificationDefinition::create([
            'type' => 'welcome',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '회원가입 환영', 'en' => 'Welcome'],
            'variables' => [['key' => 'name', 'description' => '이름']],
            'channels' => ['mail', 'database', 'alimtalk'],
            'hooks' => ['core.auth.after_register'],
            'is_active' => true,
        ]);

        return NotificationTemplate::create([
            'definition_id' => $definition->id,
            'channel' => 'alimtalk',
            'subject' => ['ko' => '바뀐 제목'],
            'body' => ['ko' => '바뀐 본문 {name}'],
            'recipients' => [['type' => 'role', 'value' => 'admin']],
            'is_active' => true,
            'is_default' => false,
        ]);
    }

    /** core.settings.update 권한을 가진 관리자의 인증 헤더. */
    private function adminHeaders(): array
    {
        $user = User::factory()->create();
        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            ['name' => json_encode(['ko' => '관리자', 'en' => 'Admin']), 'type' => 'admin']
        );
        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.settings.update'],
            ['name' => json_encode(['ko' => '설정 수정', 'en' => 'Settings update']), 'type' => 'admin']
        );
        $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$user->fresh()->createToken('test')->plainTextToken,
        ];
    }

    /**
     * @effects core_reset_restores_seeded_channel_template_defaults
     */
    public function test_리스너_등록_후_코어_기본값_조회가_시드와_같은_alimtalk_기본값을_돌려준다(): void
    {
        $this->registerListener();

        $data = app(NotificationTemplateService::class)->getDefaultTemplateData('welcome', 'alimtalk');

        $this->assertNotEmpty($data, '복원 경로가 alimtalk 기본값을 찾아야 한다(시드만 하고 복원에 빠지면 빈 배열).');
        $this->assertSame([['type' => 'trigger_user']], $data['recipients']);
        $this->assertSame($this->welcomeDatabaseBodyKo(), $data['body']['ko'], '시딩과 같은 database 평문 body 가 복원값이다.');
    }

    /**
     * @effects core_reset_restores_seeded_channel_template_defaults
     */
    public function test_기본값_복원_엔드포인트가_시드된_alimtalk_행을_복원한다(): void
    {
        $this->registerListener();
        $template = $this->modifiedAlimtalkTemplate();

        $this->postJson('/api/admin/notification-templates/'.$template->id.'/reset', [], $this->adminHeaders())
            ->assertOk();

        $fresh = $template->fresh();
        $this->assertTrue((bool) $fresh->is_default, '복원 후 "사용자 수정" 표시가 사라져야 한다.');
        $this->assertSame([['type' => 'trigger_user']], $fresh->recipients);
        $this->assertSame($this->welcomeDatabaseBodyKo(), $fresh->body['ko']);
    }
}

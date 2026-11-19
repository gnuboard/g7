<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Listeners;

use App\Extension\HookManager;
use App\Models\ActivityLog;
use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 관리자 행위 활동 로그 — 제공자 설정 변경(바뀐 칸 이름만, 비밀값 없음), 정책 저장(위험 정책 값), 다른 플러그인 저장은 남기지 않음.
 *
 * @since 1.0.0
 */
class ActivityLogTest extends PluginTestCase
{
    /**
     * 비밀값을 바꾼 제공자 수정은 `provider_updated` 에 칸 이름(`client_secret`)만 남기고 값은 남기지 않는다.
     *
     * @scenario action=provider_updated
     *
     * @effects provider_update_logs_field_names_only
     *
     * @return void 반환값 없음
     */
    public function test_provider_update_logs_names_not_values(): void
    {
        $this->enableKakao();
        $admin = $this->makeAdminUser(['email' => $this->uniqueEmail('al')], ['sirsoft-social_login.settings.view', 'sirsoft-social_login.settings.update']);
        Sanctum::actingAs($admin);

        $this->putJson('/api/plugins/sirsoft-social_login/admin/providers/kakao', ['client_secret' => 'brand-new-secret'])->assertOk();

        $log = ActivityLog::query()->where('user_id', $admin->id)->where('action', 'provider_updated')->latest('id')->firstOrFail();
        $this->assertStringContainsString('client_secret', (string) json_encode($log->properties));
        $this->assertStringNotContainsString('brand-new-secret', (string) json_encode($log->properties));
    }

    /**
     * 이 플러그인 설정 저장은 위험 정책 값과 함께 `settings_updated` 로 남고, 다른 플러그인 저장은 남기지 않는다.
     *
     * @scenario action=settings_updated, identifier=self|other
     *
     * @effects settings_save_logs_policies, other_plugin_save_not_logged
     *
     * @return void 반환값 없음
     */
    public function test_settings_saved_logs_policies(): void
    {
        $admin = $this->makeAdminUser(['email' => $this->uniqueEmail('as')]);
        Sanctum::actingAs($admin);
        $before = ActivityLog::query()->where('action', 'settings_updated')->count();

        HookManager::doAction('core.plugin_settings.after_save', 'sirsoft-social_login', ['email_conflict_policy' => 'auto_link_verified', 'state_ttl_minutes' => 10], true);
        HookManager::doAction('core.plugin_settings.after_save', 'sirsoft-other', ['email_conflict_policy' => 'x'], true);

        $logs = ActivityLog::query()->where('action', 'settings_updated')->orderByDesc('id')->get();
        $this->assertSame($before + 1, $logs->count());
        $this->assertSame(['email_conflict_policy' => 'auto_link_verified'], $logs->first()->properties['policies'] ?? null);
    }
}

<?php

namespace Plugins\Sirsoft\Totp\Tests\Feature;

use App\DisplayName\DisplayNameAreaRegistry;
use App\DisplayName\NicknameRequirement;
use App\Extension\HookManager;
use App\Models\ActivityLog;
use App\Models\User;
use Plugins\Sirsoft\Totp\Listeners\TotpAdminResetActivityLogListener;
use Plugins\Sirsoft\Totp\Models\TotpCredential;
use Plugins\Sirsoft\Totp\Tests\PluginTestCase;

/**
 * 인증 앱 강제 해제 활동 로그의 회원 이름 (#490)
 *
 * 새로 남기는 활동 로그의 회원 이름(`user_name`)은 그 시점의 관리자 영역(`core:admin`) 표시 이름이다.
 * 설정을 바꾸지 않은 사이트는 수정 전과 같이 회원 이름이 실린다.
 */
class MemberDisplayNameTest extends PluginTestCase
{
    /**
     * 강제 해제 리스너를 훅에 붙입니다 (플러그인 시험 부팅에는 리스너 자동 등록이 없다).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $listener = app(TotpAdminResetActivityLogListener::class);
        foreach (TotpAdminResetActivityLogListener::getSubscribedHooks() as $hook => $meta) {
            HookManager::addAction($hook, fn (...$args) => $listener->{$meta['method']}(...$args), $meta['priority'] ?? 50);
        }
    }

    /**
     * 관리자 영역을 닉네임으로 둔 사이트에서 관리자 화면 강제 해제의 활동 로그 회원 이름은 닉네임이다.
     *
     * @scenario case=activity_log_nickname_admin_area
     *
     * @effects activity_log_user_name_is_admin_area_name
     *
     * @return void 반환값 없음
     */
    public function test_activity_log_records_admin_area_name(): void
    {
        $this->areas(['core:admin' => ['mode' => 'fixed', 'kind' => 'nickname']]);

        $this->assertSame('길동이', $this->resetAndReadUserName());
    }

    /**
     * 설정을 바꾸지 않은 사이트의 활동 로그 회원 이름은 수정 전과 같은 이름이다.
     *
     * @scenario case=activity_log_default_site
     *
     * @effects activity_log_user_name_unchanged_on_default_site
     *
     * @return void 반환값 없음
     */
    public function test_activity_log_keeps_name_on_default_site(): void
    {
        $this->assertSame('홍길동', $this->resetAndReadUserName());
    }

    /**
     * 인증 앱을 등록한 회원을 관리자 화면에서 강제 해제하고 활동 로그의 회원 이름을 돌려줍니다.
     *
     * @return string 활동 로그 문구 인자의 회원 이름
     */
    private function resetAndReadUserName(): string
    {
        $target = User::factory()->create(['name' => '홍길동', 'nickname' => '길동이']);
        TotpCredential::create(['user_id' => $target->id, 'secret' => 'JBSWY3DPEHPK3PXP', 'confirmed_at' => now()]);

        $this->actingAs($this->createCredentialOperatorUser())
            ->deleteJson('/api/plugins/sirsoft-totp/admin/credentials/'.$target->uuid)
            ->assertOk()
            ->assertJsonPath('data.removed', true);

        $log = ActivityLog::query()->where('action', 'credential.admin_reset')->where('loggable_id', $target->id)->latest('id')->firstOrFail();

        return (string) $log->description_params['user_name'];
    }

    /**
     * 영역 설정을 바꿉니다 (사이트 기본과 관리자 화면은 지정하지 않으면 이름 고정).
     *
     * @param  array<string, array<string, mixed>>  $areas  영역 키 => 설정
     * @return void 반환값 없음
     */
    private function areas(array $areas): void
    {
        config(['g7_settings.core.member.display_name_areas' => $areas + [
            'core:site' => ['mode' => 'fixed', 'kind' => 'name'],
            'core:admin' => ['mode' => 'fixed', 'kind' => 'name'],
        ]]);
        app(DisplayNameAreaRegistry::class)->flush();
        app(NicknameRequirement::class)->flush();
    }
}

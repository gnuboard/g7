<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Admin;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 관리자 회원 상세 「소셜 로그인」 탭 API — 연결 열람(제공자 사용자 ID 는 앞 6자만), 강제 해제(마지막 수단 가드 없음, 권한 회수,
 * 알림 훅, 활동 로그), 권한 분리(열람만 / 해제).
 *
 * @since 1.0.0
 */
class UserAccountsControllerTest extends PluginTestCase
{
    /**
     * 카카오(어드민 키 포함)를 켭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->enableKakao(['admin_key' => 'kakao-admin']);
        Http::fake(['kapi.kakao.com/v1/user/unlink' => Http::response(['id' => 1])]);
    }

    /**
     * 회원 목록 가입경로 필터의 선택지는 「연결이 있는 제공자」다 — 꺼진 제공자에 연결된 회원도 고를 수 있어야 한다. 회원 연결 열람
     * 권한이 필요하다.
     *
     * @scenario provider=disabled_with_links, actor=viewer|member
     *
     * @effects filter_options_include_disabled_linked, filter_options_require_accounts_view
     *
     * @return void 반환값 없음
     */
    public function test_linked_provider_options(): void
    {
        $member = User::factory()->create(['email' => $this->uniqueEmail('opt')]);
        SocialAccount::query()->create(['user_id' => $member->id, 'provider_key' => 'naver', 'provider_user_id' => 'opt-n', 'linked_at' => now()]);
        $url = '/api/plugins/sirsoft-social_login/admin/linked-providers';

        Sanctum::actingAs(User::factory()->create(['email' => $this->uniqueEmail('plainopt')]));
        $this->getJson($url)->assertStatus(403);

        Sanctum::actingAs($this->makeAdminUser(['email' => $this->uniqueEmail('optv')], ['sirsoft-social_login.accounts.view']));
        $options = collect($this->getJson($url)->assertOk()->json('data'))->keyBy('key');
        $this->assertTrue($options->has('naver'));
        $this->assertContains($options['naver']['label'], ['네이버', 'Naver']);
        $this->assertGreaterThanOrEqual(1, $options['naver']['count']);
    }

    /**
     * 열람 권한은 목록 200·해제 403, 해제 권한은 비밀번호 없는 회원의 마지막 연결도 끊는다 — 훅·활동 로그·권한 회수 요청이 남는다.
     *
     * @scenario actor=viewer|unlinker, member=passwordless_single_link
     *
     * @effects admin_list_masks_uid, viewer_cannot_unlink, admin_unlink_ignores_last_method_guard, unlinked_by_admin_hook, admin_unlink_activity_logged, admin_unlink_revokes
     *
     * @return void 반환값 없음
     */
    public function test_view_and_force_unlink(): void
    {
        $member = User::factory()->passwordless()->create(['email' => $this->uniqueEmail('target')]);
        SocialAccount::query()->create([
            'user_id' => $member->id, 'provider_key' => 'kakao', 'provider_user_id' => '1234567890',
            'email' => 'k@example.test', 'access_token' => 'at', 'linked_at' => now(),
        ]);
        $url = '/api/plugins/sirsoft-social_login/admin/users/'.$member->uuid.'/accounts';

        Sanctum::actingAs($this->makeAdminUser(['email' => $this->uniqueEmail('viewer')], ['sirsoft-social_login.accounts.view']));
        $list = $this->getJson($url)->assertOk();
        $this->assertSame('123456', $list->json('data.accounts.0.provider_user_id_prefix'));
        $this->assertStringNotContainsString('1234567890', (string) $list->getContent());
        $this->assertFalse($list->json('data.has_password'));
        $this->assertFalse($list->json('data.abilities.can_unlink'));
        $this->deleteJson($url.'/kakao')->assertStatus(403);

        $fired = [];
        HookManager::addAction('sirsoft-social_login.account.unlinked_by_admin', function (User $u, string $key) use (&$fired) {
            $fired[] = [$u->id, $key];
        });

        $admin = $this->makeAdminUser(['email' => $this->uniqueEmail('unlinker')], ['sirsoft-social_login.accounts.view', 'sirsoft-social_login.accounts.unlink']);
        Sanctum::actingAs($admin);
        $this->assertTrue($this->getJson($url)->json('data.abilities.can_unlink'));
        $this->deleteJson($url.'/kakao')->assertOk()->assertJsonPath('data.provider_key', 'kakao');

        $this->assertSame(0, SocialAccount::query()->where('user_id', $member->id)->count());
        $this->assertSame([[$member->id, 'kakao']], $fired);
        $this->assertDatabaseHas('activity_logs', ['loggable_id' => $member->id, 'action' => 'account_unlinked_by_admin', 'user_id' => $admin->id]);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'v1/user/unlink'));

        $this->deleteJson($url.'/kakao')->assertStatus(404)->assertJsonPath('errors.code', 'not_linked');
        $this->getJson('/api/plugins/sirsoft-social_login/admin/users/00000000-0000-0000-0000-000000000000/accounts')->assertStatus(404);
    }
}

<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Listeners;

use App\Models\User;
use App\Services\UserService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Services\ProviderSettings;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 회원 생애주기 리스너 — 삭제·탈퇴 때 연결을 지우고(연쇄 삭제에 기대지 않는다), 회원 응답에 연결된 제공자 목록을 더한다.
 *
 * @since 1.0.0
 */
class UserLifecycleTest extends PluginTestCase
{
    /**
     * 카카오를 켜고 제공자 권한 회수 요청을 가짜로 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->enableKakao();
        Http::fake(['*' => Http::response(['id' => 1])]);
    }

    /**
     * 연결 행을 만듭니다.
     *
     * @param  User  $user  회원
     * @param  string  $uid  카카오 사용자 ID
     * @return SocialAccount 연결
     */
    private function link(User $user, string $uid): SocialAccount
    {
        return SocialAccount::query()->create(['user_id' => $user->id, 'provider_key' => 'kakao', 'provider_user_id' => $uid, 'linked_at' => now()]);
    }

    /**
     * 회원 삭제는 연결을 먼저 지워 외래키에 걸리지 않고, 탈퇴는 회원 행을 남기고 연결만 지운다.
     *
     * @scenario lifecycle=delete|withdraw
     *
     * @effects delete_removes_links_without_fk_error, withdraw_removes_links
     *
     * @return void 반환값 없음
     */
    public function test_delete_and_withdraw_remove_links(): void
    {
        $deleted = User::factory()->create(['email' => $this->uniqueEmail('del')]);
        $this->link($deleted, 'k-del');
        $this->assertTrue(app(UserService::class)->deleteUser($deleted));
        $this->assertSame(0, SocialAccount::query()->where('provider_user_id', 'k-del')->count());

        $withdrawn = User::factory()->create(['email' => $this->uniqueEmail('wd')]);
        $this->link($withdrawn, 'k-wd');
        $this->assertTrue(app(UserService::class)->withdrawUser($withdrawn));
        $this->assertSame(0, SocialAccount::query()->where('provider_user_id', 'k-wd')->count());
        $this->assertTrue(User::query()->whereKey($withdrawn->id)->exists());
    }

    /**
     * 내 정보 응답에 연결된 제공자 목록이 실리고, 그 조회는 연결 표 쿼리 1번이다. 제공자 사용자 ID 는 싣지 않는다.
     *
     * @scenario resource=me
     *
     * @effects social_providers_injected_with_one_query
     *
     * @return void 반환값 없음
     */
    public function test_user_resource_lists_social_providers(): void
    {
        $user = User::factory()->create(['email' => $this->uniqueEmail('me')]);
        $this->link($user, 'k-me-secret-id');
        Sanctum::actingAs($user);

        $queries = 0;
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'social_accounts')) {
                $queries++;
            }
        });

        $response = $this->getJson('/api/me')->assertOk();

        $this->assertSame([['key' => 'kakao', 'label' => app(ProviderSettings::class)->label('kakao'), 'brand' => 'kakao']], $response->json('data.social_providers'));
        $this->assertSame(1, $queries);
        $this->assertStringNotContainsString('k-me-secret-id', (string) $response->getContent());
    }
}

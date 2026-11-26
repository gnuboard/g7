<?php

namespace Tests\Feature\Auth;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 로그아웃 후 이동 `core.auth.logout_redirect_url`
 *
 * 로그아웃 응답이 `data.redirect_url` 을 싣는다 — 확장(예: IdP 로그아웃)이 같은 출처 경로를 주면 그 경로, 아니면 null. 필터는 토큰을 지우기
 * **전**에 불려 어느 토큰(세션)을 닫는지 안다.
 */
class LogoutRedirectTest extends TestCase
{
    use DatabaseTransactions;
    use PasswordPolicyFixtures;

    /**
     * 로그아웃 이동 필터를 둡니다.
     *
     * @param  string  $path  돌려줄 경로
     * @param  array<int, array<string, mixed>>  $seen  필터가 받은 문맥 기록
     * @return void 반환값 없음
     */
    private function redirectTo(string $path, array &$seen): void
    {
        HookManager::addFilter('core.auth.logout_redirect_url', function ($url, User $user, array $context) use ($path, &$seen) {
            $seen[] = $context + ['token_exists' => PersonalAccessToken::query()->whereKey($context['token_id'])->exists()];

            return $path;
        });
    }

    /**
     * 확장이 없으면 null 이다.
     *
     * @scenario extension=none
     *
     * @effects logout_redirect_null_by_default
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 확장이_없으면_null(): void
    {
        $user = $this->makeMember(['email' => 'lo.'.Str::lower(Str::random(8)).'@example.test']);

        $this->withToken($this->issueToken($user))->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('data.redirect_url', null);
    }

    /**
     * 확장 경로를 싣고, 필터는 지우기 전의 토큰 id 를 받는다.
     *
     * @scenario extension=path, guard=user
     *
     * @effects logout_redirect_from_extension, logout_filter_sees_token_before_delete
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 확장_경로를_싣고_삭제_전_토큰을_본다(): void
    {
        $seen = [];
        $this->redirectTo('/plugins/test/logout?t=1', $seen);
        $user = $this->makeMember(['email' => 'lo.'.Str::lower(Str::random(8)).'@example.test']);

        $this->withToken($this->issueToken($user))->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('data.redirect_url', '/plugins/test/logout?t=1');

        $this->assertSame('user', $seen[0]['guard']);
        $this->assertTrue($seen[0]['token_exists']);
        $this->assertSame(0, $user->tokens()->count());
    }

    /**
     * 외부 주소는 null 로 낮춘다.
     *
     * @scenario extension=external_url
     *
     * @effects logout_external_redirect_dropped
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 외부_주소는_null(): void
    {
        $seen = [];
        $this->redirectTo('https://idp.example/logout', $seen);
        $user = $this->makeMember(['email' => 'lo.'.Str::lower(Str::random(8)).'@example.test']);

        $this->withToken($this->issueToken($user))->postJson('/api/auth/logout')->assertJsonPath('data.redirect_url', null);
    }

    /**
     * 관리자 로그아웃은 guard 가 admin 이다.
     *
     * @scenario guard=admin
     *
     * @effects admin_logout_redirect
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 관리자_로그아웃(): void
    {
        $seen = [];
        $this->redirectTo('/plugins/test/logout', $seen);
        $admin = $this->makeAdmin(['email' => 'lo.admin.'.Str::lower(Str::random(8)).'@example.test']);

        $this->withToken($this->issueToken($admin))->postJson('/api/admin/auth/logout')
            ->assertOk()
            ->assertJsonPath('data.redirect_url', '/plugins/test/logout');
        $this->assertSame('admin', $seen[0]['guard']);
    }
}

<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * 무묶음 로그인 인계 발급 명령 `auth:handoff-issue` (#798 K2)
 *
 * 실측·시험용으로 브라우저에 묶지 않은 코드를 만든다 — 묶음 쿠키 없이 교환되고, 운영 환경에서는 거절한다.
 */
class AuthHandoffIssueCommandTest extends TestCase
{
    use CapturesSentMail;
    use DatabaseTransactions;
    use PasswordPolicyFixtures;

    /**
     * 메일 발송을 막고 로그인 시도 제한을 초기화합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Cache::flush();
        $this->prepareIdentityNotifications();
    }

    /**
     * 명령 출력의 주소에서 코드를 꺼냅니다.
     *
     * @param  string  $output  명령 출력
     * @return string 인계 코드
     */
    private function codeFrom(string $output): string
    {
        $this->assertSame(1, preg_match('/login_handoff=([A-Za-z0-9_-]+)/', $output, $m), $output);

        return $m[1];
    }

    /**
     * 명령이 만든 코드는 묶음 쿠키 없이 관리자 경로에서 교환된다.
     *
     * @scenario env=local, guard=admin
     *
     * @effects unbound_code_exchanges_without_cookie
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 명령이_만든_코드는_쿠키_없이_교환된다(): void
    {
        $admin = $this->makeAdmin(['email' => 'cmd.admin.'.Str::lower(Str::random(8)).'@example.test']);

        $this->assertSame(0, Artisan::call('auth:handoff-issue', ['email' => $admin->email, '--guard' => 'admin']));
        $output = Artisan::output();
        $code = $this->codeFrom($output);

        $this->assertStringContainsString('/admin/login?login_handoff=', $output);
        $this->postJson('/api/auth/admin/login/handoff', ['code' => $code])->assertOk();
    }

    /**
     * 운영 환경에서는 거절하고 코드를 만들지 않는다.
     *
     * @scenario env=production
     *
     * @effects handoff_command_refused_in_production
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 운영_환경에서는_거절한다(): void
    {
        $member = $this->makeMember(['email' => 'cmd.member.'.Str::lower(Str::random(8)).'@example.test']);
        $this->app['env'] = 'production';

        try {
            $this->assertSame(1, Artisan::call('auth:handoff-issue', ['email' => $member->email]));
            $this->assertStringNotContainsString('login_handoff=', Artisan::output());
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    /**
     * 없는 회원과 잘못된 경로는 실패 코드로 끝난다.
     *
     * @scenario user=missing, guard=invalid
     *
     * @effects handoff_command_rejects_bad_input
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 없는_회원과_잘못된_경로는_실패한다(): void
    {
        $this->assertSame(1, Artisan::call('auth:handoff-issue', ['email' => 'nobody.'.Str::random(6).'@example.test']));

        $member = $this->makeMember(['email' => 'cmd.guard.'.Str::lower(Str::random(8)).'@example.test']);
        $this->assertSame(1, Artisan::call('auth:handoff-issue', ['email' => $member->email, '--guard' => 'root']));
    }
}

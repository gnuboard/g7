<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\LoginHandoffBinding;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * Playwright 묶음 인계 발급 명령 `playwright:issue-handoff` (#798 K2 E2E 픽스처)
 *
 * 묶음 쿠키와 코드를 함께 돌려준다 — 그 쿠키를 실은 요청만 교환되고, 쿠키 없는 요청은 422 다. 옵트인 가드와 운영 환경 거절을 지킨다.
 */
class PlaywrightIssueHandoffTest extends TestCase
{
    use CapturesSentMail;
    use DatabaseTransactions;
    use LoginHandoffBinding;
    use PasswordPolicyFixtures;

    /**
     * 메일 발송을 막고 로그인 시도 제한을 초기화한 뒤 옵트인 가드를 켭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Cache::flush();
        $this->prepareIdentityNotifications();
        putenv('G7_PLAYWRIGHT_BYPASS=1');
        $_ENV['G7_PLAYWRIGHT_BYPASS'] = '1';
        $_SERVER['G7_PLAYWRIGHT_BYPASS'] = '1';
    }

    /**
     * 옵트인 가드를 원래대로 끕니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        putenv('G7_PLAYWRIGHT_BYPASS');
        unset($_ENV['G7_PLAYWRIGHT_BYPASS'], $_SERVER['G7_PLAYWRIGHT_BYPASS']);

        parent::tearDown();
    }

    /**
     * 명령 출력의 JSON 을 읽습니다.
     *
     * @param  string  $output  명령 출력
     * @return array{code: string, cookie: array{name: string, value: string, path: string}} 코드와 쿠키
     */
    private function ticketFrom(string $output): array
    {
        $line = collect(preg_split('/\r\n|\r|\n/', trim($output)))->last(fn (string $l) => str_starts_with(trim($l), '{'));
        $this->assertNotNull($line, $output);

        return json_decode((string) $line, true);
    }

    /**
     * 돌려준 쿠키를 실은 요청만 교환되고, 쿠키 없이 낸 코드는 422 로 거절된다.
     *
     * @scenario cookie=present|absent
     *
     * @effects bound_ticket_exchanges_with_cookie, bound_ticket_rejected_without_cookie
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 쿠키를_실은_요청만_교환된다(): void
    {
        $member = $this->makeMember(['email' => 'pw.handoff.'.Str::lower(Str::random(8)).'@example.test']);

        $this->assertSame(0, Artisan::call('playwright:issue-handoff', ['email' => $member->email]));
        $ticket = $this->ticketFrom(Artisan::output());
        $this->assertSame('g7_login_bind', $ticket['cookie']['name']);
        $this->assertSame('/api/auth', $ticket['cookie']['path']);

        $this->exchangeHandoff($ticket['code'], null)->assertStatus(422);

        Artisan::call('playwright:issue-handoff', ['email' => $member->email]);
        $second = $this->ticketFrom(Artisan::output());
        $this->exchangeHandoff($second['code'], $second['cookie']['value'])->assertOk();
    }

    /**
     * 옵트인 가드가 없거나 운영 환경이면 코드를 만들지 않는다.
     *
     * @scenario guard=bypass_missing|production
     *
     * @effects handoff_fixture_refused
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 가드가_없거나_운영이면_거절한다(): void
    {
        $member = $this->makeMember(['email' => 'pw.handoff.'.Str::lower(Str::random(8)).'@example.test']);

        putenv('G7_PLAYWRIGHT_BYPASS');
        unset($_ENV['G7_PLAYWRIGHT_BYPASS'], $_SERVER['G7_PLAYWRIGHT_BYPASS']);
        $this->assertSame(1, Artisan::call('playwright:issue-handoff', ['email' => $member->email]));
        $this->assertStringNotContainsString('"code"', Artisan::output());

        putenv('G7_PLAYWRIGHT_BYPASS=1');
        $_ENV['G7_PLAYWRIGHT_BYPASS'] = '1';
        $this->app['env'] = 'production';
        try {
            $this->assertSame(1, Artisan::call('playwright:issue-handoff', ['email' => $member->email]));
            $this->assertStringNotContainsString('"code"', Artisan::output());
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}

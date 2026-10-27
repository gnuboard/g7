<?php

namespace Plugins\Sirsoft\Totp\Tests\Feature;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Totp\Listeners\ConsolePasswordResetNoticeListener;
use Plugins\Sirsoft\Totp\Plugin;
use Plugins\Sirsoft\Totp\Repositories\Contracts\TotpCredentialRepositoryInterface;
use Plugins\Sirsoft\Totp\Repositories\TotpCredentialRepository;
use Plugins\Sirsoft\Totp\Services\TotpEnrollmentService;
use Plugins\Sirsoft\Totp\Support\Totp;
use Tests\TestCase;

// audit:allow test-extension-base-class reason: 코어 회원 행과 이미 마이그레이션된 totp_credentials 행만 쓰는 명령 출력 테스트라 트랜잭션 격리로 충분하다 — PluginTestCase 의 RefreshDatabase(migrate:fresh)는 로컬 MySQL OOM 규율(mem:migrate-fresh-ddl-crashes-local-mysql)에 걸린다. 저장소 바인딩은 setUp 에서 PluginTestCase 와 같게 한다

/**
 * 터미널 비밀번호 재설정(`user:reset-password`) 결과의 인증 앱 초기화 안내.
 *
 * 코어는 어느 확장이 설치돼 있는지 묻지 않고 filter 훅 `core.auth.console_password_reset_notices` 로
 * 안내를 받는다 — 이 플러그인이 그 훅에 답하지 않으면 인증 앱을 잃은 관리자는 비밀번호만 되찾고
 * 여전히 로그인하지 못한다. 실제 명령 → 실제 훅 → 실제 리스너 체인으로 검증한다.
 *
 * 테이블 재생성 없이 트랜잭션으로 격리한다(PluginTestCase 의 RefreshDatabase 를 쓰지 않는다).
 *
 * @since 1.0.2
 */
class TotpConsolePasswordResetNoticeTest extends TestCase
{
    use DatabaseTransactions;

    /** @var callable|null 붙인 필터 (tearDown 에서 뗀다) */
    private $filter = null;

    /**
     * 저장소 바인딩과 리스너를 실제 훅에 붙인다(플러그인 테스트 부팅에는 자동 등록 경로가 없다).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(TotpCredentialRepositoryInterface::class, TotpCredentialRepository::class);

        $listener = app(ConsolePasswordResetNoticeListener::class);
        $meta = ConsolePasswordResetNoticeListener::getSubscribedHooks()['core.auth.console_password_reset_notices'];
        $this->filter = fn (...$args) => $listener->{$meta['method']}(...$args);
        HookManager::addFilter('core.auth.console_password_reset_notices', $this->filter, $meta['priority']);

        putenv('G7_RESET_PASSWORD=New-Passw0rd!Strong');
    }

    /**
     * 정리.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::removeFilter('core.auth.console_password_reset_notices', $this->filter);
        putenv('G7_RESET_PASSWORD');

        parent::tearDown();
    }

    /**
     * 인증 앱 등록을 마친 회원이면 결과에 `totp:reset` 안내가 붙는다.
     *
     * @scenario enrollment=confirmed
     * @effects console_password_reset_shows_totp_reset_notice_for_enrolled_user
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function enrolled_user_gets_the_totp_reset_notice(): void
    {
        $user = User::factory()->create(['email' => 'totp-notice-'.bin2hex(random_bytes(4)).'@example.test']);
        $enrollment = app(TotpEnrollmentService::class);
        $secret = $enrollment->start($user, 'G7 Test')['secret'];
        $enrollment->confirm($user, Totp::codeAt($secret, Totp::timestepAt()));

        $this->artisan('user:reset-password', ['email' => $user->email])
            ->expectsOutputToContain('php artisan totp:reset '.$user->email)
            ->assertExitCode(0);
    }

    /**
     * 인증 앱을 등록하지 않은 회원에게는 안내를 붙이지 않는다.
     *
     * @scenario enrollment=none
     * @effects console_password_reset_omits_totp_notice_without_enrollment
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function unenrolled_user_gets_no_notice(): void
    {
        $user = User::factory()->create(['email' => 'totp-none-'.bin2hex(random_bytes(4)).'@example.test']);

        $this->artisan('user:reset-password', ['email' => $user->email])
            ->doesntExpectOutputToContain('totp:reset')
            ->assertExitCode(0);
    }

    /**
     * 플러그인이 리스너를 등록한다(빠지면 운영에서 안내가 나오지 않는다).
     *
     * @effects console_password_reset_notice_listener_is_registered_by_the_plugin
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_plugin_registers_the_notice_listener(): void
    {
        $this->assertContains(ConsolePasswordResetNoticeListener::class, (new Plugin)->getHookListeners());
    }
}

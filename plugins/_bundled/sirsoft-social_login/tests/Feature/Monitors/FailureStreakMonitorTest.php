<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Monitors;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Http;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptIntent;
use Plugins\Sirsoft\SocialLogin\Services\FailureStreakMonitor;
use Plugins\Sirsoft\SocialLogin\Services\OAuthFlowService;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 제공자 연속 실패 감시 — 기준에 닿으면 하루 한 번 훅, 성공하면 셈 초기화, 회원 동의 취소·관리자 테스트는 세지 않음, 기준 0 은 꺼짐,
 * 실제 콜백 실패가 셈에 들어감.
 *
 * @since 1.0.0
 */
class FailureStreakMonitorTest extends PluginTestCase
{
    /** @var array<int, array{0: string, 1: int, 2: string}> 발행된 훅 */
    private array $fired = [];

    /**
     * 훅 수집기를 겁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        HookManager::addAction(FailureStreakMonitor::ACTION_FAILING, function (string $key, int $count, string $code) {
            $this->fired[] = [$key, $count, $code];
        });
    }

    /**
     * 기준 3: 세 번째 실패에 훅 1회, 네 번째는 같은 날이라 다시 쏘지 않는다. 성공하면 셈이 0 으로 돌아간다. 동의 취소와 test 의도는
     * 세지 않는다. 기준 0 이면 아무것도 하지 않는다.
     *
     * @scenario threshold=3|0, code=provider|access_denied, intent=login|test
     *
     * @effects streak_fires_at_threshold, streak_cooldown_daily, success_resets_streak, user_cancel_not_counted, test_intent_not_counted, zero_threshold_disables
     *
     * @return void 반환값 없음
     */
    public function test_streak_rules(): void
    {
        $this->setPluginSettings(['provider_failure_streak_threshold' => 3]);
        $monitor = app(FailureStreakMonitor::class);
        $key = 'fs'.substr(uniqid(), -6);

        $monitor->record($key, 'login', 'KOE010');
        $monitor->record($key, 'login', 'access_denied');
        $monitor->record($key, 'test', 'KOE010');
        $monitor->record($key, 'login', 'KOE010');
        $this->assertSame([], $this->fired);

        $monitor->record($key, 'login', 'token_exchange_failed');
        $this->assertSame([[$key, 3, 'token_exchange_failed']], $this->fired);

        $monitor->record($key, 'login', 'token_exchange_failed');
        $this->assertCount(1, $this->fired);

        $other = $key.'b';
        $monitor->record($other, 'login', 'x');
        $monitor->record($other, 'login', 'x');
        $monitor->record($other, 'login', null);
        $monitor->record($other, 'login', 'x');
        $monitor->record($other, 'login', 'x');
        $this->assertCount(1, $this->fired);

        $this->setPluginSettings(['provider_failure_streak_threshold' => 0]);
        $zero = $key.'z';
        foreach (range(1, 5) as $i) {
            app(FailureStreakMonitor::class)->record($zero, 'login', 'x');
        }
        $this->assertCount(1, $this->fired);
    }

    /**
     * 실제 콜백에서 제공자 코드 교환이 실패하면 셈에 들어간다(기준 1 → 바로 훅).
     *
     * @scenario path=callback
     *
     * @effects callback_failure_counted
     *
     * @return void 반환값 없음
     */
    public function test_callback_failure_is_counted(): void
    {
        $this->enableKakao();
        $this->setPluginSettings(['provider_failure_streak_threshold' => 1]);
        Http::fake(['kauth.kakao.com/oauth/token' => Http::response(['error' => 'invalid_client', 'error_code' => 'KOE010'], 401)]);

        $url = app(OAuthFlowService::class)->start('kakao', AttemptIntent::Login, '/');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->get('/plugins/sirsoft-social_login/oauth/kakao/callback?code=c&state='.$q['state'])->assertStatus(302);

        $this->assertSame([['kakao', 1, 'KOE010']], $this->fired);
    }
}

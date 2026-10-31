<?php

namespace Tests\Feature\Analytics\Api;

use App\Extension\HookManager;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * 수집 제외 API — 운영자가 자기 브라우저를 통계에서 빼는 길.
 *
 * 쿠키가 잘못 심기면 증상이 없다 — 응답은 정상이고 운영자는 제외된 줄 알지만 자기 방문이
 * 계속 인기 페이지를 밀어 올린다. 그래서 응답에 실린 쿠키의 성질을 값으로 잠근다.
 */
class OptOutControllerTest extends AnalyticsApiTestCase
{
    /**
     * 수집 제외 쿠키 이름
     */
    private const COOKIE = 'g7_analytics_optout';

    /**
     * 응답이 실어 보낸 수집 제외 쿠키를 꺼냅니다.
     *
     * @param  TestResponse  $response  대상 응답
     * @return Cookie|null 응답에 실린 쿠키 (없으면 null)
     */
    private function sentCookie($response): ?Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === self::COOKIE) {
                return $cookie;
            }
        }

        return null;
    }

    /**
     * 제외 요청이 쿠키를 심는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=opt_out, role=none
     *
     * @effects opt_out_sets_and_clears_a_readable_cookie
     */
    public function test_opting_out_sets_the_cookie(): void
    {
        $response = $this->actingAs($this->updateAdmin())->postJson('/api/admin/analytics/opt-out');

        $response->assertOk();
        $this->assertTrue($response->json('data.opted_out'));

        $cookie = $this->sentCookie($response);

        $this->assertNotNull($cookie, '쿠키를 심지 않으면 트래커가 제외를 알 길이 없다');
        $this->assertSame('1', $cookie->getValue());
    }

    /**
     * 쿠키가 스크립트에서 읽히는 성질로 심기는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=opt_out, role=none
     *
     * @effects opt_out_cookie_is_readable_by_the_tracker
     */
    public function test_the_cookie_is_readable_by_the_tracker(): void
    {
        $response = $this->actingAs($this->updateAdmin())->postJson('/api/admin/analytics/opt-out');
        $response->assertOk();

        $cookie = $this->sentCookie($response);
        $this->assertNotNull($cookie);

        $this->assertFalse(
            $cookie->isHttpOnly(),
            '트래커가 이 값을 읽고 스스로 멈춰야 한다 — 스크립트가 못 읽으면 제외가 서버까지 갔다 와야 한다',
        );
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertSame('/', $cookie->getPath());
    }

    /**
     * 해제 요청이 쿠키를 지우라고 알리는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=opt_out, role=none
     *
     * @effects opt_out_sets_and_clears_a_readable_cookie
     */
    public function test_opting_back_in_clears_the_cookie(): void
    {
        $response = $this->actingAs($this->updateAdmin())->deleteJson('/api/admin/analytics/opt-out');

        $response->assertOk();
        $this->assertFalse($response->json('data.opted_out'));

        $cookie = $this->sentCookie($response);

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isCleared(), '해제는 과거 만료로 브라우저에게 지우라고 알린다');
    }

    /**
     * 현재 상태 조회가 보내온 쿠키를 그대로 읽는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=opt_out, role=none
     *
     * @effects opt_out_state_is_read_from_the_cookie
     */
    public function test_the_current_state_is_read_from_the_cookie(): void
    {
        $admin = $this->updateAdmin();

        $this->actingAs($admin)
            ->getJson('/api/admin/analytics/opt-out')
            ->assertOk()
            ->assertJsonPath('data.opted_out', false);

        // 브라우저가 실제로 보내오는 모양(암호화 안 된 평문)으로 넣는다 — 트래커가
        // `document.cookie` 로 심은 값도 이 모양이다. 서버가 이 값을 되읽지 못하면
        // 트래커를 거치지 않는 경로(서버 폴백·내부 검색 훅)에서 제외가 무시된다.
        // 이 쿠키는 암호화 예외로 등재돼 있으므로(`bootstrap/app.php`) 평문 그대로 오간다.
        $this->assertTrue(
            EncryptCookies::serialized(self::COOKIE) === false,
            '이 단언은 예외 등재 자체가 아니라 직렬화 규약만 본다',
        );

        $response = $this->actingAs($admin)
            ->call('GET', '/api/admin/analytics/opt-out', [], [self::COOKIE => '1']);

        $this->assertSame(200, $response->status());
        $this->assertTrue(
            $response->json('data.opted_out'),
            '평문 쿠키를 서버가 못 읽으면 트래커를 거치지 않는 경로에서 제외가 무시된다',
        );
    }

    /**
     * 제외 전환이 훅으로 알려지는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=opt_out, role=none
     *
     * @effects opt_out_change_fires_a_hook
     */
    public function test_the_change_fires_a_hook(): void
    {
        $fired = [];
        HookManager::addAction('core.analytics.opt_out_changed', function ($payload) use (&$fired): void {
            $fired[] = $payload;
        });

        $admin = $this->updateAdmin();
        $this->actingAs($admin)->postJson('/api/admin/analytics/opt-out')->assertOk();
        $this->actingAs($admin)->deleteJson('/api/admin/analytics/opt-out')->assertOk();

        $this->assertCount(2, $fired);
        $this->assertTrue($fired[0]['opted_out']);
        $this->assertFalse($fired[1]['opted_out']);
    }
}

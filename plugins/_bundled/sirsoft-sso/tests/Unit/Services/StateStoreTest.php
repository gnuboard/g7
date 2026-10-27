<?php

namespace Plugins\Sirsoft\Sso\Tests\Unit\Services;

use Illuminate\Http\Request;
use Plugins\Sirsoft\Sso\DTO\LoginIntent;
use Plugins\Sirsoft\Sso\Services\StateStore;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * state, 브라우저 묶음 쿠키, 시작 티켓 — 1회용, 만료, 다른 브라우저 거절.
 *
 * @since 1.0.0
 */
class StateStoreTest extends PluginTestCase
{
    /**
     * 묶음 쿠키를 가진 요청을 만듭니다.
     *
     * @param  string  $bind  묶음값
     * @return Request 요청
     */
    private function requestWithBind(string $bind): Request
    {
        return Request::create('/x', 'GET', [], [StateStore::BIND_COOKIE => $bind]);
    }

    /**
     * 같은 플러그인 캐시를 쓰는 새 저장소 — 「다른 요청」(새로 구운 묶음값 기억 없음)을 흉내 낸다.
     *
     * @param  StateStore  $store  기존 저장소
     * @return StateStore 새 저장소
     */
    private function freshStore(StateStore $store): StateStore
    {
        return new StateStore((fn () => $this->cache)->call($store));
    }

    /**
     * state 를 만듭니다.
     *
     * @param  StateStore  $store  저장소
     * @param  string  $bind  묶음값
     * @return LoginIntent 시작 정보
     */
    private function makeState(StateStore $store, string $bind): LoginIntent
    {
        return $store->create(['intent' => LoginIntent::LOGIN, 'guard' => 'user', 'redirect' => '//evil.test', 'idp_id' => 1, 'idp_key' => 'corp'], $bind);
    }

    /**
     * state 가 1회용이고 복귀 주소를 정리하는지 확인한다.
     *
     * @scenario state=fresh, pull=twice
     *
     * @effects state_single_use, redirect_sanitized
     *
     * @return void 반환값 없음
     */
    public function test_state_is_single_use_and_sanitizes_redirect(): void
    {
        $store = app(StateStore::class);
        $bind = str_repeat('a', 64);
        $intent = $this->makeState($store, $bind);

        $pulled = $store->pull($intent->stateId);
        $this->assertNotNull($pulled);
        $this->assertSame('/', $pulled->redirect);
        $this->assertSame(StateStore::hashBind($bind), $pulled->bindHash);
        $this->assertNull($store->pull($intent->stateId));
    }

    /**
     * state 가 수명이 지나면 사라지는지 확인한다.
     *
     * @scenario state=expired
     *
     * @effects state_expired
     *
     * @return void 반환값 없음
     */
    public function test_state_expires(): void
    {
        $store = app(StateStore::class);
        $intent = $this->makeState($store, str_repeat('b', 64));

        $this->travel(StateStore::DEFAULT_STATE_TTL_MINUTES + 1)->minutes();
        $this->assertNull($store->pull($intent->stateId));
    }

    /**
     * 저장한 묶음 해시를 요청 쿠키와 대조하는지 확인한다.
     *
     * @scenario bind=same|other|missing
     *
     * @effects bind_compared
     *
     * @return void 반환값 없음
     */
    public function test_bind_matching(): void
    {
        $store = app(StateStore::class);
        $bind = str_repeat('c', 64);
        $hash = StateStore::hashBind($bind);

        $this->assertTrue($store->matchesBind($hash, $this->requestWithBind($bind)));
        $this->assertFalse($this->freshStore($store)->matchesBind($hash, $this->requestWithBind(str_repeat('d', 64))));
        $this->assertFalse($this->freshStore($store)->matchesBind($hash, Request::create('/x')));
        $this->assertFalse($store->matchesBind(null, $this->requestWithBind($bind)));
    }

    /**
     * 묶음값을 요청당 한 번만 만들고 쿠키 속성이 맞는지 확인한다.
     *
     * @scenario bind=missing
     *
     * @effects bind_cookie_issued_once_per_request
     *
     * @return void 반환값 없음
     */
    public function test_ensure_bind_issues_once_and_cookie_attributes(): void
    {
        $store = app(StateStore::class);
        $request = Request::create('/x');

        $first = $store->ensureBind($request, queue: false);
        $second = $store->ensureBind($request, queue: false);
        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);

        $cookie = $store->bindCookie($first, $request);
        $this->assertSame(StateStore::BIND_COOKIE, $cookie->getName());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/', $cookie->getPath());
        $this->assertContains($cookie->getSameSite(), [Cookie::SAMESITE_NONE, Cookie::SAMESITE_LAX]);
        if ($cookie->getSameSite() === Cookie::SAMESITE_NONE) {
            $this->assertTrue($cookie->isSecure());
        }
    }

    /**
     * 시작 티켓이 1회용이고 다른 브라우저에서 소멸하는지 확인한다.
     *
     * @scenario ticket=same_browser|other_browser|reuse
     *
     * @effects ticket_single_use, ticket_bound
     *
     * @return void 반환값 없음
     */
    public function test_ticket_single_use_and_bound(): void
    {
        $store = app(StateStore::class);
        $bind = str_repeat('e', 64);

        $ticket = $store->issueTicket(['user_id' => 7, 'intent' => LoginIntent::LINK, 'idp_id' => 3, 'redirect' => '/mypage'], $bind);
        $other = $this->freshStore($store);
        $this->assertNull($other->pullTicket($ticket, $this->requestWithBind(str_repeat('f', 64))));
        // 다른 브라우저가 쓰면 티켓도 소멸한다.
        $this->assertNull($store->pullTicket($ticket, $this->requestWithBind($bind)));

        $ticket2 = $store->issueTicket(['user_id' => 7, 'intent' => LoginIntent::LINK, 'idp_id' => 3], $bind);
        $payload = $store->pullTicket($ticket2, $this->requestWithBind($bind));
        $this->assertSame(7, $payload['user_id']);
        $this->assertNull($store->pullTicket($ticket2, $this->requestWithBind($bind)));
    }
}

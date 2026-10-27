<?php

namespace Plugins\Sirsoft\Sso\Tests\Unit\Services;

use Illuminate\Http\Request;
use Plugins\Sirsoft\Sso\DTO\LoginIntent;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Repositories\Policy\Contracts\SitePolicyRepositoryInterface;
use Plugins\Sirsoft\Sso\Services\PendingStore;
use Plugins\Sirsoft\Sso\Services\Policy\PolicyService;
use Plugins\Sirsoft\Sso\Services\StateStore;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * 정책 「로그인 요청 유효 시간」(`state_ttl_minutes`)·「동의·연결 확인 대기 시간」(`pending_ttl_minutes`)이 실제 수명이 되는지 검증합니다.
 *
 * 두 값은 관리자 정책 화면에서 저장되지만 state·대기 저장소가 늘 기본 10분을 써서 동작에 반영되지 않았다(실측: 정책 1분, 78초 뒤 승인해도
 * 로그인 성공). 저장소가 수명을 받지 않으면 정책 값을 쓰고, 드라이버가 시작 정보를 다시 저장해도(SAML 요청 id) 같은 수명을 지킨다.
 *
 * @scenario-manifest tests/scenarios/policy-ttl.yaml
 */
class PolicyTtlTest extends PluginTestCase
{
    /**
     * 정책 두 수명을 저장합니다.
     *
     * @param  int  $stateTtl  로그인 요청 유효 시간(분)
     * @param  int  $pendingTtl  대기 시간(분)
     * @return void 반환값 없음
     */
    private function policy(int $stateTtl, int $pendingTtl): void
    {
        app(SitePolicyRepositoryInterface::class)->save(array_merge(PolicyService::DEFAULTS, [
            'state_ttl_minutes' => $stateTtl,
            'pending_ttl_minutes' => $pendingTtl,
        ]), null);
        app(PolicyService::class)->flush();
    }

    /**
     * state 를 만듭니다.
     *
     * @return LoginIntent 시작 정보
     */
    private function makeState(): LoginIntent
    {
        return app(StateStore::class)->create(
            ['intent' => LoginIntent::LOGIN, 'guard' => 'user', 'redirect' => '/', 'idp_id' => 1, 'idp_key' => 'corp'],
            str_repeat('c', 64),
        );
    }

    /**
     * 정책 1분이면 61초 뒤 state 가 사라지고, 드라이버가 다시 저장해도 수명이 늘지 않는다.
     *
     * @scenario store=state, policy=one_minute
     *
     * @effects state_follows_policy_ttl
     *
     * @return void 반환값 없음
     */
    public function test_state_follows_policy_ttl(): void
    {
        $this->policy(1, 10);
        $store = app(StateStore::class);

        $expiring = $this->makeState();
        $this->travel(61)->seconds();
        $this->assertNull($store->pull($expiring->stateId));

        // 드라이버의 다시 저장(`update`, SAML 요청 id) — 수명을 따로 주지 않아도 기본 10분이 아니라 정책 1분이다
        // (경과는 저장소가 실제 시계 `time()` 으로 재므로 다시 저장은 만든 직후에 한다)
        $resaved = $this->makeState();
        $store->update($resaved->withExtra(['saml_request_id' => 'r-1']));
        $this->travel(61)->seconds();
        $this->assertNull($store->pull($resaved->stateId));
    }

    /**
     * 기본값(10분)은 그대로 — 61초 뒤에도 state 가 남는다.
     *
     * @scenario store=state, policy=default
     *
     * @effects default_ttl_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_state_keeps_default_ttl(): void
    {
        $this->policy(10, 10);
        $intent = $this->makeState();

        $this->travel(61)->seconds();
        $this->assertNotNull(app(StateStore::class)->pull($intent->stateId));
    }

    /**
     * 정책 1분이면 동의·연결 확인 대기가 61초 뒤 만료 오류다.
     *
     * @scenario store=pending, policy=one_minute
     *
     * @effects pending_follows_policy_ttl
     *
     * @return void 반환값 없음
     */
    public function test_pending_follows_policy_ttl(): void
    {
        $this->policy(10, 1);
        $bind = str_repeat('d', 64);
        $request = Request::create('/x', 'GET', [], [StateStore::BIND_COOKIE => $bind]);
        $pending = app(PendingStore::class);

        $consent = $pending->create(PendingStore::TYPE_CONSENT, ['idp_id' => 1], $bind);
        $link = $pending->create(PendingStore::TYPE_LINK, ['idp_id' => 1], $bind);
        $this->travel(61)->seconds();

        foreach ([[$consent, PendingStore::TYPE_CONSENT, 'pending_expired'], [$link, PendingStore::TYPE_LINK, 'link_expired']] as [$token, $type, $code]) {
            try {
                $pending->get($token, $type, $request);
                $this->fail("{$type} 대기가 정책 1분 뒤에도 남아 있다");
            } catch (SsoException $e) {
                $this->assertSame($code, $e->errorCode);
            }
        }
    }

    /**
     * 기본값(10분)이면 61초 뒤에도 대기가 남는다.
     *
     * @scenario store=pending, policy=default
     *
     * @effects default_ttl_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_pending_keeps_default_ttl(): void
    {
        $this->policy(10, 10);
        $bind = str_repeat('e', 64);
        $pending = app(PendingStore::class);
        $token = $pending->create(PendingStore::TYPE_CONSENT, ['idp_id' => 1], $bind);

        $this->travel(61)->seconds();
        $data = $pending->get($token, PendingStore::TYPE_CONSENT, Request::create('/x', 'GET', [], [StateStore::BIND_COOKIE => $bind]));
        $this->assertSame(1, $data['idp_id']);
    }
}

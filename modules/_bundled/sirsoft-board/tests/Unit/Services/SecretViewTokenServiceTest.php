<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Services;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Contracts\Extension\CacheInterface;
use App\Models\User;
use Illuminate\Http\Request;
use Modules\Sirsoft\Board\Services\SecretViewTokenService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use ReflectionProperty;

/**
 * 비밀글 열람 토큰 서비스 (#789 §4.1 · D3 · D4)
 *
 * 브라우저 토큰 1개 + 서버의 글별 grant. 재사용·누적·만료·상한·신원 결속·키 해시를 고정한다.
 *
 * @scenario link=board-secret-thread-password
 */
class SecretViewTokenServiceTest extends ModuleTestCase
{
    /**
     * 시험 대상 서비스
     */
    private SecretViewTokenService $service;

    /**
     * 서비스를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SecretViewTokenService::class);
    }

    /**
     * 토큰을 헤더로 실은 새 요청을 만듭니다.
     *
     * @param  string|null  $token  토큰
     * @return Request 요청
     */
    private function requestWith(?string $token): Request
    {
        $request = Request::create('/');
        if ($token !== null) {
            $request->headers->set(SecretViewTokenService::HEADER, $token);
        }

        return $request;
    }

    /**
     * grant 유효 시간(초)을 반환합니다 (코어 설정 cache.post_verify_token_ttl).
     *
     * @return int 유효 시간(초)
     */
    private function ttl(): int
    {
        return max(1, (int) g7_core_settings('cache.post_verify_token_ttl', 3600));
    }

    /**
     * 서비스가 쓰는 캐시를 꺼냅니다.
     *
     * @return CacheInterface 모듈 캐시
     */
    private function cache(): CacheInterface
    {
        return (new ReflectionProperty($this->service, 'cache'))->getValue($this->service);
    }

    /**
     * 토큰 없이 grant 하면 40자 토큰을 새로 발급합니다.
     *
     * @scenario case=token_issue
     *
     * @effects thread_password_issues_view_token
     *
     * @return void 반환값 없음
     */
    public function test_issues_new_token(): void
    {
        $issued = $this->service->grant($this->requestWith(null), 'b', 1);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $issued['token']);
        $this->assertTrue($this->service->tokenHasGrant($issued['token'], 'b', 1));
        $this->assertFalse($this->service->tokenHasGrant($issued['token'], 'b', 2));
    }

    /**
     * 살아 있는 토큰을 보내면 같은 토큰에 grant 를 누적합니다.
     *
     * @scenario case=token_reuse
     *
     * @effects same_token_accumulates_grants
     *
     * @return void 반환값 없음
     */
    public function test_reuses_and_accumulates(): void
    {
        $first = $this->service->grant($this->requestWith(null), 'b', 1)['token'];
        $second = $this->service->grant($this->requestWith($first), 'b', 2)['token'];

        $this->assertSame($first, $second);
        $this->assertSame([1, 2], $this->service->grantedPostIds($this->requestWith($first), 'b'));
    }

    /**
     * 서버가 발급하지 않은(등록되지 않은) 토큰은 재사용하지 않습니다.
     *
     * @scenario case=token_forged
     *
     * @effects forged_token_not_registered
     *
     * @return void 반환값 없음
     */
    public function test_forged_token_is_not_reused(): void
    {
        $forged = str_repeat('A', 40);
        $issued = $this->service->grant($this->requestWith($forged), 'b', 1)['token'];

        $this->assertNotSame($forged, $issued);
        $this->assertFalse($this->service->tokenHasGrant($forged, 'b', 1));
    }

    /**
     * 형식이 틀린 토큰은 없는 것으로 봅니다.
     *
     * @scenario case=token_malformed
     *
     * @effects malformed_token_ignored
     *
     * @return void 반환값 없음
     */
    public function test_malformed_token_is_ignored(): void
    {
        $this->assertNull($this->service->presentedToken($this->requestWith('short')));
        $this->assertSame([], $this->service->grantedPostIds($this->requestWith('x/../y'), 'b'));
    }

    /**
     * 만료된 레지스트리는 재사용하지 않고 grant 도 읽히지 않습니다.
     *
     * @scenario case=token_expired
     *
     * @effects expired_registry_not_reused
     *
     * @return void 반환값 없음
     */
    public function test_expired_grants_are_not_reused(): void
    {
        $token = $this->service->grant($this->requestWith(null), 'b', 1)['token'];
        $this->travel($this->ttl() + 60)->seconds();

        $this->assertFalse($this->service->tokenHasGrant($token, 'b', 1));
        $this->assertNotSame($token, $this->service->grant($this->requestWith($token), 'b', 2)['token']);
    }

    /**
     * 다른 신원(회원 ID)이 받은 토큰에는 grant 를 더하지 않습니다.
     *
     * @scenario case=token_other_identity
     *
     * @effects other_identity_token_not_extended
     *
     * @return void 반환값 없음
     */
    public function test_other_identity_token_is_not_extended(): void
    {
        $guestToken = $this->service->grant($this->requestWith(null), 'b', 1)['token'];
        $this->actingAs(User::factory()->create());

        $memberToken = $this->service->grant($this->requestWith($guestToken), 'b', 2)['token'];

        $this->assertNotSame($guestToken, $memberToken);
        $this->assertFalse($this->service->tokenHasGrant($guestToken, 'b', 2));
    }

    /**
     * grant 마다 만료가 따로입니다 — 먼저 받은 grant 가 먼저 끝납니다.
     *
     * @scenario case=token_grant_ttl
     *
     * @effects grant_expires_independently
     *
     * @return void 반환값 없음
     */
    public function test_grants_expire_independently(): void
    {
        $ttl = $this->ttl();
        $token = $this->service->grant($this->requestWith(null), 'b', 1)['token'];
        $this->travel(intdiv($ttl * 2, 3))->seconds();
        $this->service->grant($this->requestWith($token), 'b', 2);
        $this->travel(intdiv($ttl, 2))->seconds();

        $this->assertFalse($this->service->tokenHasGrant($token, 'b', 1));
        $this->assertTrue($this->service->tokenHasGrant($token, 'b', 2));
    }

    /**
     * grant 는 토큰당 50개까지이며, 넘으면 만료가 가장 이른 것부터 빠집니다.
     *
     * @scenario case=token_grant_cap
     *
     * @effects grant_cap_evicts_oldest
     *
     * @return void 반환값 없음
     */
    public function test_grant_cap_evicts_oldest(): void
    {
        $token = $this->service->grant($this->requestWith(null), 'b', 1)['token'];
        for ($id = 2; $id <= SecretViewTokenService::MAX_GRANTS + 1; $id++) {
            $this->travel(1)->seconds();
            $this->service->grant($this->requestWith($token), 'b', $id);
        }

        $ids = $this->service->grantedPostIds($this->requestWith($token), 'b');
        $this->assertCount(SecretViewTokenService::MAX_GRANTS, $ids);
        $this->assertNotContains(1, $ids);
        $this->assertContains(SecretViewTokenService::MAX_GRANTS + 1, $ids);
    }

    /**
     * 캐시 키에 토큰 원문을 넣지 않습니다 (해시만).
     *
     * @scenario case=token_key_hash
     *
     * @effects registry_key_has_no_raw_token
     *
     * @return void 반환값 없음
     */
    public function test_cache_key_does_not_contain_raw_token(): void
    {
        $token = $this->service->grant($this->requestWith(null), 'b', 1)['token'];

        $this->assertFalse($this->cache()->has('board_secret_view_token_'.$token));
        $this->assertTrue($this->cache()->has('board_secret_view_token_'.hash('sha256', $token)));
    }

    /**
     * 다른 게시판 슬러그의 grant 는 이 게시판 목록에 들어오지 않습니다.
     *
     * @scenario case=token_slug_bound
     *
     * @effects grant_bound_to_board_slug
     *
     * @return void 반환값 없음
     */
    public function test_grants_are_bound_to_slug(): void
    {
        $token = $this->service->grant($this->requestWith(null), 'other', 1)['token'];

        $this->assertSame([], $this->service->grantedPostIds($this->requestWith($token), 'b'));
    }

    /**
     * useForRequest 로 건 토큰은 헤더보다 먼저 읽힙니다 (같은 응답에서 새 grant 반영).
     *
     * @scenario case=token_override
     *
     * @effects same_response_uses_new_grant
     *
     * @return void 반환값 없음
     */
    public function test_override_wins_over_header(): void
    {
        $token = $this->service->grant($this->requestWith(null), 'b', 7)['token'];
        $request = $this->requestWith(null);
        $this->assertSame([], $this->service->grantedPostIds($request, 'b'));

        $this->service->useForRequest($token, $request);

        $this->assertSame([7], $this->service->grantedPostIds($request, 'b'));
    }
}

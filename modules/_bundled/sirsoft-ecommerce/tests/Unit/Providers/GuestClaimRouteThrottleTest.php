<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 비회원 클레임 라우트 전수의 요청 빈도 제한 부착 검사 (S14 · P11)
 *
 * 이 블록에는 전역 API 리미터가 없고, 형제 라우트(`guest.orders.verify`)만 `throttle:20,1` 을
 * 갖고 있었다. 조회 토큰을 손에 넣은 쪽이 신청·미리보기·첨부 업로드를 무제한으로 반복할 수
 * 있으면 파일 쓰기(`claim-images.store`)가 디스크를 채우고, 철회가 행을 남기는 신청↔철회
 * 반복은 그 주문의 목록을 계속 부풀린다. 어느 경우에도 응답은 정상 2xx 라 오류가 남지 않는다.
 *
 * 모집단을 라우트 테이블에서 파생시키므로 새 비회원 클레임 라우트가 늘면 자동으로 검사 대상이
 * 된다 — 나열식 검사는 나열한 것만 보므로 누락된 라우트를 영원히 못 본다(형제
 * `GuestOrderTokenMiddlewareRegistrationTest` 와 같은 근거).
 */
class GuestClaimRouteThrottleTest extends ModuleTestCase
{
    /** 비회원 주문 라우트명 공통 접두사 */
    private const GUEST_ORDER_PREFIX = 'api.modules.sirsoft-ecommerce.guest.orders.';

    /**
     * 비회원 클레임 라우트 이름 조각.
     *
     * 신청 3종은 이름에 `claim` 이 들어가지 않아(`exchanges.store` 등) 접두사만으로는 골라지지
     * 않는다. 그래서 조각을 명시하되 아래 건수 단언이 누락을 잡는다.
     *
     * @var list<string>
     */
    private const CLAIM_SUFFIXES = [
        'claimable-options',
        'claims.preview',
        'exchanges.store',
        'returns.store',
        'refund-requests.store',
        'claims.index',
        'claims.show',
        'claims.update',
        'claims.withdraw',
        'claim-images.store',
        'claim-images.destroy',
        'claim-payments.pay',
        'claim-payments.approve',
    ];

    /** 파일을 쓰는 라우트 — 블록 상한 위에 좁은 상한을 겹친다 */
    private const FILE_WRITE = self::GUEST_ORDER_PREFIX.'claim-images.store';

    /**
     * 비회원 클레임 라우트를 수집합니다.
     *
     * @return array<string, array<int, string>> 라우트명 => 미들웨어 목록
     */
    private function claimRoutes(): array
    {
        $expected = array_map(fn (string $suffix): string => self::GUEST_ORDER_PREFIX.$suffix, self::CLAIM_SUFFIXES);
        $collected = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if (is_string($name) && in_array($name, $expected, true)) {
                $collected[$name] = $route->gatherMiddleware();
            }
        }

        return $collected;
    }

    /**
     * 미들웨어 목록에서 `throttle:` 상한을 읽습니다. (숫자 상한 · 이름 있는 리미터 모두)
     *
     * 이름 있는 리미터(`throttle:sirsoft-ecommerce.guest-claims`)는 등록된 리미터를 실제로 풀어
     * 분당 허용 횟수를 읽는다 — 이름만 보고 통과시키면 리미터가 등록되지 않은 경우를 놓친다.
     *
     * @param  array<int, string>  $middleware  미들웨어 목록
     * @return array<int, int> 분당 허용 횟수 목록
     */
    private function throttleLimits(array $middleware): array
    {
        $limits = [];

        foreach ($middleware as $entry) {
            if (! str_starts_with($entry, 'throttle:')) {
                continue;
            }

            $parts = explode(',', substr($entry, strlen('throttle:')));

            if (is_numeric($parts[0])) {
                $limits[] = (int) $parts[0];

                continue;
            }

            $limiter = RateLimiter::limiter($parts[0]);

            if ($limiter === null) {
                continue;
            }

            foreach (Arr::wrap($limiter(Request::create('/'))) as $limit) {
                if ($limit instanceof Limit) {
                    $limits[] = (int) $limit->maxAttempts;
                }
            }
        }

        return $limits;
    }

    /**
     * 비회원 클레임 라우트 전부에 요청 빈도 상한(숫자 또는 이름 있는 리미터)이 걸려 있는지 검증합니다.
     *
     * @scenario claim_type=exchange, entry=with_token, screen=apply
     *
     * @effects guest_claim_routes_carry_a_request_rate_limit
     *
     * @return void 반환값 없음
     */
    public function test_every_guest_claim_route_carries_a_request_rate_limit(): void
    {
        $routes = $this->claimRoutes();

        $this->assertCount(
            13,
            $routes,
            '비회원 클레임 라우트가 13건이 아닙니다(수집: '.count($routes).'). 라우트가 늘었다면 그것이 '
            .'검사 대상에 들어왔는지 CLAIM_SUFFIXES 로 확인하십시오 — 그러지 않으면 이 테스트는 '
            .'새 라우트를 보지 못한 채 green 이 됩니다.',
        );

        $unthrottled = [];

        foreach ($routes as $name => $middleware) {
            if ($this->throttleLimits($middleware) === []) {
                $unthrottled[] = $name;
            }
        }

        $this->assertSame([], $unthrottled, '요청 빈도 제한 없이 열린 비회원 클레임 라우트: '.implode(', ', $unthrottled));
    }

    /**
     * 파일을 쓰는 첨부 업로드 라우트의 상한이 블록의 다른 라우트보다 좁은지 검증합니다.
     *
     * @scenario claim_type=return, entry=with_token, screen=apply
     *
     * @effects guest_claim_image_upload_is_bounded_more_tightly
     *
     * @return void 반환값 없음
     */
    public function test_the_file_writing_route_is_bounded_more_tightly_than_the_block(): void
    {
        // 첨부 업로드는 이 블록에서 유일한 파일 쓰기다. 한 신청서의 첨부 장수 상한(설정)은
        // 신청서 단위이지 시간 단위가 아니라, 신청서를 만들지 않고 업로드만 반복하는 경로를
        // 막지 못한다 — 그 경로는 디스크를 채우고 예외도 남기지 않는다.
        $routes = $this->claimRoutes();

        $this->assertArrayHasKey(self::FILE_WRITE, $routes);

        $uploadLimit = min($this->throttleLimits($routes[self::FILE_WRITE]));

        $others = [];

        foreach ($routes as $name => $middleware) {
            if ($name !== self::FILE_WRITE) {
                $others[] = min($this->throttleLimits($middleware));
            }
        }

        $this->assertNotEmpty($others);
        $this->assertLessThan(
            min($others),
            $uploadLimit,
            '첨부 업로드의 상한이 나머지 비회원 클레임 라우트보다 좁지 않습니다.',
        );
    }
}

<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Feature;

use App\Extension\Consent\ConsentSubject;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Gdpr\Consent\GdprConsentProvider;
use Plugins\Sirsoft\Gdpr\Http\Middleware\CookieConsentMiddleware;
use Plugins\Sirsoft\Gdpr\Http\Resources\GdprUserConsentResource;
use Plugins\Sirsoft\Gdpr\Models\GdprUserConsent;
use Plugins\Sirsoft\Gdpr\Models\GdprUserConsentHistory;
use Plugins\Sirsoft\Gdpr\Providers\GdprServiceProvider;
use Plugins\Sirsoft\Gdpr\Services\GdprConsentService;
use Plugins\Sirsoft\Gdpr\Services\GdprPolicyVersionService;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * 요청당 동의 조회 쿼리 예산 (#860 W10)
 *
 * 쿠키 동의 미들웨어는 web·api 모든 응답을 지나간다. 걸러낼 쿠키가 없는 응답에서도 동의 내역과 정책 버전을
 * 읽었고, 한 요청 안에서 미들웨어·동의 제공자·리소스가 정책 버전과 세션 동의 내역을 몇 번씩 다시 읽었다.
 */
class CookieConsentRequestQueryBudgetTest extends PluginTestCase
{
    /** @var array<int, string> 기록한 SQL */
    private array $queries = [];

    /**
     * 플러그인 서비스 수명(요청 단위)을 provider 와 같게 맞추고 쿼리 기록을 켭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach (GdprServiceProvider::SCOPED_SERVICES as $service) {
            $this->app->scoped($service);
        }

        DB::listen(function ($query): void {
            $this->queries[] = $query->sql;
        });
    }

    /**
     * 기록한 SQL 중 해당 테이블을 읽은 수를 셉니다.
     *
     * @param  string  $table  테이블명
     * @return int 쿼리 수
     */
    private function queriesOn(string $table): int
    {
        return count(array_filter($this->queries, fn (string $sql): bool => str_contains($sql, $table)));
    }

    /**
     * 미들웨어를 실행합니다.
     *
     * @param  callable(HttpResponse): void  $withCookies  응답에 쿠키를 심는 콜백
     * @return HttpResponse 미들웨어를 지난 응답
     */
    private function runMiddleware(callable $withCookies): HttpResponse
    {
        $middleware = app(CookieConsentMiddleware::class);

        return $middleware->handle(Request::create('/'), function () use ($withCookies) {
            $response = new HttpResponse('ok');
            $withCookies($response);

            return $response;
        });
    }

    /**
     * 걸러낼 쿠키가 없는 응답은 동의 테이블을 읽지 않고 그대로 나갑니다.
     *
     * @effects cookie_consent_middleware_skips_db_without_target_cookies
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 대상_쿠키가_없으면_동의_테이블을_읽지_않는다(): void
    {
        $this->queries = [];
        $response = $this->runMiddleware(function (HttpResponse $r): void {
            $r->headers->setCookie(Cookie::create('XSRF-TOKEN', 'token', 0, '/'));
            $r->headers->setCookie(Cookie::create('app_pref')->withValue('')->withExpires(1)->withPath('/'));
        });

        $this->assertSame(0, $this->queriesOn('gdpr_'), implode("\n", $this->queries));
        $names = array_map(fn (Cookie $c): string => $c->getName(), $response->headers->getCookies());
        $this->assertSame(['XSRF-TOKEN', 'app_pref'], $names, '응답 쿠키는 그대로');
    }

    /**
     * 걸러낼 쿠키가 있으면 지금처럼 동의를 읽고, 미동의면 그 쿠키를 지웁니다.
     *
     * @effects cookie_consent_middleware_skips_db_without_target_cookies
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 대상_쿠키가_있으면_지금처럼_동의를_보고_거른다(): void
    {
        $this->queries = [];
        $response = $this->runMiddleware(function (HttpResponse $r): void {
            $r->headers->setCookie(Cookie::create('XSRF-TOKEN', 'token', 0, '/'));
            $r->headers->setCookie(Cookie::create('app_pref', 'v', 0, '/'));
        });

        $this->assertGreaterThan(0, $this->queriesOn('gdpr_'));
        $names = array_map(fn (Cookie $c): string => $c->getName(), $response->headers->getCookies());
        $this->assertSame(['XSRF-TOKEN'], $names);
    }

    /**
     * 한 요청에서 미들웨어와 동의 제공자가 함께 읽어도 정책 버전·세션 동의 내역은 한 번씩만 읽습니다.
     *
     * @effects current_policy_version_is_queried_at_most_once_per_request
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 정책_버전과_세션_동의_내역은_요청당_한_번만_읽는다(): void
    {
        $sessionId = 'budget-guest-session';
        GdprUserConsentHistory::query()->create([
            'user_id' => null,
            'session_id' => $sessionId,
            'consent_key' => 'cookie_functional',
            'action' => 'granted',
            'source' => 'banner',
            'policy_version' => (string) app(GdprPolicyVersionService::class)->getCurrentVersion(),
            'categories' => ['functional' => true],
        ]);
        $this->app->forgetScopedInstances();

        $this->queries = [];
        $provider = app(GdprConsentProvider::class);
        $provider->getSnapshot(ConsentSubject::guest($sessionId));
        $provider->getSnapshot(ConsentSubject::guest($sessionId));
        app(GdprConsentService::class)->getCurrentCookieConsents(null, $sessionId);

        $this->assertLessThanOrEqual(1, $this->queriesOn('gdpr_policy_versions'), implode("\n", $this->queries));
        $this->assertLessThanOrEqual(1, $this->queriesOn('gdpr_user_consent_histories'), implode("\n", $this->queries));
    }

    /**
     * 동의 현황 리소스는 행 수와 무관하게 정책 버전을 한 번만 읽습니다 (N+1 없음).
     *
     * @effects current_policy_version_is_queried_at_most_once_per_request
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 동의_현황_20행도_정책_버전은_한_번만_읽는다(): void
    {
        $rows = collect(range(1, 20))->map(fn (int $i): GdprUserConsent => new GdprUserConsent([
            'user_id' => 1,
            'consent_key' => $i % 2 ? 'cookie_analytics' : 'cookie_marketing',
            'is_consented' => true,
            'policy_version' => '1',
        ]));

        $this->queries = [];
        GdprUserConsentResource::collection($rows)->toArray(Request::create('/'));

        $this->assertLessThanOrEqual(1, $this->queriesOn('gdpr_policy_versions'), implode("\n", $this->queries));
    }
}

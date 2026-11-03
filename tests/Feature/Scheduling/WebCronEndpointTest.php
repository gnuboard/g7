<?php

namespace Tests\Feature\Scheduling;

use App\Scheduling\ScheduleRunner;
use App\Scheduling\ScheduleRunState;
use App\Scheduling\ScheduleRunSummary;
use App\Scheduling\WebCronPolicy;
use App\Scheduling\WebCronTokenStore;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 웹 크론 엔드포인트의 접근·응답 계약 (A-5 · §11.15).
 *
 * 이 주소는 **인증 없이 도달할 수 있는 실행 표면**이다. 그래서 두 가지를 함께 고정한다:
 *
 * - 누가 부를 수 있는가 (번호 · 허용 IP)
 * - 통과한 뒤의 응답 형태 (실행 0건이어도 200)
 *
 * 후자를 재는 이유는 외부 크론 서비스가 비-200 을 장애로 보고 알림을 쏟기 때문이다 —
 * "지금은 돌 것이 없다" 는 장애가 아니다.
 *
 * @scenario caller=external, token=valid, token=invalid, token=rotated
 */
class WebCronEndpointTest extends TestCase
{
    // 러너가 실행 이력을 DB 에 남기므로 이 클래스도 격리가 필요하다 — 없으면
    // 커밋된 이력 행이 남아 뒤따르는 테스트의 모집단을 오염시킨다.
    use RefreshDatabase;

    /** 호출 주소 (라우트 이름으로 도출 — 리터럴을 다시 적지 않는다) */
    private const ENDPOINT = '/api/system/cron/run';

    /** 케이스 전용 토큰 파일 */
    private string $tokenPath;

    /** 케이스 전용 상태 파일 */
    private string $statePath;

    /**
     * 테스트 준비 — 토큰·상태 파일을 케이스 전용 경로로 격리하고 스케줄을 비운다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(6));
        $this->tokenPath = storage_path('framework/testing/web-cron-token-'.$suffix.'.json');
        $this->statePath = storage_path('framework/testing/web-cron-state-'.$suffix.'.json');

        File::ensureDirectoryExists(dirname($this->tokenPath));

        $this->app->instance(WebCronTokenStore::class, new WebCronTokenStore($this->tokenPath));
        $this->app->instance(ScheduleRunState::class, new ScheduleRunState($this->statePath));

        // 코어 예약이 실제로 돌지 않도록 스케줄을 비운다 — 이 테스트가 재는 것은
        // 접근 판정과 응답 형태이지 예약 실행 자체가 아니다.
        app(ConsoleKernelContract::class)->bootstrap();
        $this->app->instance(Schedule::class, new Schedule('UTC'));
    }

    /**
     * 케이스 전용 토큰 파일과 상태 파일을 지운 뒤 부모 정리를 돌린다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ([$this->tokenPath, $this->statePath] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    /**
     * 번호가 없으면 401 이다.
     *
     * @scenario caller=external, entry_point=web, token=missing
     *
     * @effects missing_token_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_call_without_a_token_is_rejected(): void
    {
        app(WebCronTokenStore::class)->rotate();

        $this->getJson(self::ENDPOINT)->assertStatus(401);
    }

    /**
     * 번호가 틀리면 401 이다.
     *
     * @scenario caller=external, token=invalid, token_channel=query
     *
     * @effects invalid_token_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_call_with_a_wrong_token_is_rejected(): void
    {
        app(WebCronTokenStore::class)->rotate();

        $this->getJson(self::ENDPOINT.'?token=not-the-right-one')->assertStatus(401);
    }

    /**
     * 번호가 발급되지 않은 사이트는 어떤 값으로도 통과하지 못한다.
     *
     * 미발급 상태를 "비교 대상 없음 → 통과" 로 다루면 그 사이트의 예약 실행이
     * 무인증 표면이 된다.
     *
     * @scenario caller=external, token=unissued, token_channel=query
     *
     * @effects unissued_token_never_matches
     *
     * @return void 반환값 없음
     */
    public function test_call_before_any_token_is_issued_is_rejected(): void
    {
        $this->getJson(self::ENDPOINT.'?token=anything')->assertStatus(401);
    }

    /**
     * 재발급하면 이전 번호는 즉시 무효가 된다.
     *
     * @scenario caller=external, token=rotated, token=valid, token_channel=query
     *
     * @effects rotated_token_invalidates_the_previous_address
     *
     * @return void 반환값 없음
     */
    public function test_rotated_token_invalidates_the_previous_one(): void
    {
        $store = app(WebCronTokenStore::class);
        $old = $store->rotate();
        $new = $store->rotate();

        $this->assertNotSame($old, $new);
        $this->getJson(self::ENDPOINT.'?token='.$old)->assertStatus(401);
        $this->getJson(self::ENDPOINT.'?token='.$new)->assertOk();
    }

    /**
     * 유효한 번호면 실행 0건이어도 200 + 요약이다.
     *
     * @scenario caller=external, token=valid, token_channel=query
     *
     * @effects authenticated_call_always_returns_200
     *
     * @return void 반환값 없음
     */
    public function test_authenticated_call_returns_200_even_with_nothing_to_run(): void
    {
        $token = app(WebCronTokenStore::class)->rotate();

        $this->getJson(self::ENDPOINT.'?token='.$token)
            ->assertOk()
            ->assertJsonPath('data.ran', true)
            ->assertJsonPath('data.executed', 0);
    }

    /**
     * 번호를 헤더로도 받는다 (접근 로그에 쿼리스트링이 남는 환경 대응).
     *
     * @scenario caller=external, token=valid, token_channel=header
     *
     * @effects token_is_accepted_from_the_header
     *
     * @return void 반환값 없음
     */
    public function test_token_is_accepted_from_the_header(): void
    {
        $token = app(WebCronTokenStore::class)->rotate();

        $this->getJson(self::ENDPOINT, [WebCronTokenStore::TOKEN_HEADER => $token])
            ->assertOk()
            ->assertJsonPath('data.ran', true);
    }

    /**
     * 허용 IP 목록에 없는 호출자는 403 이다.
     *
     * @scenario caller=external, token=valid, ip_allow_list=mismatch
     *
     * @effects disallowed_ip_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_call_from_a_disallowed_ip_is_rejected(): void
    {
        $token = app(WebCronTokenStore::class)->rotate();
        $this->setSetting('cron_allowed_ips', '203.0.113.7');

        $this->getJson(self::ENDPOINT.'?token='.$token)->assertStatus(403);
    }

    /**
     * 허용 IP 목록이 비어 있으면 제한하지 않는다.
     *
     * @scenario caller=external, token=valid, ip_allow_list=empty
     *
     * @effects empty_ip_allow_list_permits_any_caller
     *
     * @return void 반환값 없음
     */
    public function test_empty_ip_allow_list_permits_any_caller(): void
    {
        $token = app(WebCronTokenStore::class)->rotate();
        $this->setSetting('cron_allowed_ips', '');

        $this->getJson(self::ENDPOINT.'?token='.$token)->assertOk();
    }

    /**
     * 실행 방식이 "서버 크론만" 이면 인증을 통과해도 실행하지 않는다 (200 유지).
     *
     * @scenario caller=external, token=valid, cron_mode=cron
     *
     * @effects cron_only_mode_does_not_run_on_web_call
     *
     * @return void 반환값 없음
     */
    public function test_cron_only_mode_does_not_run(): void
    {
        $token = app(WebCronTokenStore::class)->rotate();
        $this->setSetting('cron_mode', WebCronPolicy::MODE_CRON);

        $this->getJson(self::ENDPOINT.'?token='.$token)
            ->assertOk()
            ->assertJsonPath('data.ran', false)
            ->assertJsonPath('data.reason', 'web_call_disabled');
    }

    /**
     * 최소 간격 미만의 호출은 실행 없이 200 으로 되돌아간다 (중복 호출 차단).
     *
     * @scenario caller=external, token=valid, min_interval=within
     *
     * @effects throttled_call_returns_200_without_running
     *
     * @return void 반환값 없음
     */
    public function test_call_within_the_minimum_interval_is_throttled(): void
    {
        $token = app(WebCronTokenStore::class)->rotate();
        $this->setSetting('cron_min_interval', 5);

        $this->getJson(self::ENDPOINT.'?token='.$token)
            ->assertOk()
            ->assertJsonPath('data.ran', true);

        $this->getJson(self::ENDPOINT.'?token='.$token)
            ->assertOk()
            ->assertJsonPath('data.ran', false)
            ->assertJsonPath('data.reason', 'min_interval');
    }

    /**
     * 최소 간격이 지나면 다시 실행된다.
     *
     * 앞 케이스만 재면 "영원히 막는" 구현도 초록이다.
     *
     * @scenario caller=external, token=valid, min_interval=elapsed
     *
     * @effects call_after_the_interval_runs_again
     *
     * @return void 반환값 없음
     */
    public function test_call_after_the_interval_runs_again(): void
    {
        $token = app(WebCronTokenStore::class)->rotate();
        $this->setSetting('cron_min_interval', 5);

        $this->getJson(self::ENDPOINT.'?token='.$token)->assertJsonPath('data.ran', true);

        // 마지막 호출 기록을 간격 밖으로 되돌린다.
        app(ScheduleRunState::class)->recordInvocation(CarbonImmutable::now()->subMinutes(10));

        $this->getJson(self::ENDPOINT.'?token='.$token)->assertJsonPath('data.ran', true);
    }

    /**
     * 최소 간격이 0 이면 제한하지 않는다.
     *
     * @scenario caller=external, token=valid, min_interval=zero
     *
     * @effects zero_interval_disables_throttling
     *
     * @return void 반환값 없음
     */
    public function test_zero_interval_disables_throttling(): void
    {
        $token = app(WebCronTokenStore::class)->rotate();
        $this->setSetting('cron_min_interval', 0);

        $this->getJson(self::ENDPOINT.'?token='.$token)->assertJsonPath('data.ran', true);
        $this->getJson(self::ENDPOINT.'?token='.$token)->assertJsonPath('data.ran', true);
    }

    /**
     * 상한을 넘는 번호는 **401** 이다 (검증 오류 422 가 아니다).
     *
     * 이 면의 계약은 「인증 실패만 401/403」이라, 형식 위반이 422 로 나가면 같은 사실
     * (「번호가 틀렸다」)이 두 가지 응답으로 갈린다 — 외부 크론 서비스는 둘을 다르게
     * 다루고, 운영자는 왜 갈리는지 알 수 없다.
     *
     * @scenario caller=external, token=invalid, token_channel=query
     *
     * @effects oversized_token_is_unauthorized_not_unprocessable
     *
     * @return void 반환값 없음
     */
    public function test_oversized_token_is_rejected_as_unauthorized(): void
    {
        app(WebCronTokenStore::class)->rotate();

        $this->getJson(self::ENDPOINT.'?token='.str_repeat('a', 4096))
            ->assertStatus(401);
    }

    /**
     * 배열로 실린 번호(`?token[]=x`)도 **401** 이다 (검증 오류 422 가 아니다).
     *
     * 번호에는 형식 규칙을 걸지 않는다는 요청 클래스의 계약과 같은 이유다 — 형태가 틀린
     * 번호도 「번호가 맞지 않는다」로 수렴해야 응답이 한 가지로 남는다.
     *
     * @scenario caller=external, token=invalid, token_channel=query
     *
     * @effects oversized_token_is_unauthorized_not_unprocessable
     *
     * @return void 반환값 없음
     */
    public function test_array_token_is_rejected_as_unauthorized(): void
    {
        $token = app(WebCronTokenStore::class)->rotate();

        $this->getJson(self::ENDPOINT.'?token[]='.$token)
            ->assertStatus(401);
    }

    /**
     * 러너가 터져도 인증을 통과한 호출은 **200** 이다.
     *
     * 500 이 나가면 외부 크론 서비스가 「사이트 장애」로 읽고 알림을 쏟아, 운영자는
     * 예약이 아니라 사이트가 죽었다고 판단한다. 실행 실패는 장애가 아니라 보고할 사실이다.
     *
     * @scenario caller=external, token=valid, token_channel=query
     *
     * @effects runner_failure_still_returns_200
     *
     * @return void 반환값 없음
     */
    public function test_runner_failure_still_returns_200(): void
    {
        $token = app(WebCronTokenStore::class)->rotate();

        // 러너가 **실행 중에** 예외를 던지도록 갈아 끼운다 — 캐시 장애·확장 등록 예외가
        // 실제 경로다. 생성 시점에 던지면 컨트롤러가 만들어지기 전이라 다른 축을 재게 된다.
        $this->mock(ScheduleRunner::class, function ($mock): void {
            $mock->shouldReceive('run')->andThrow(new \RuntimeException('러너 중단'));
        });

        $this->getJson(self::ENDPOINT.'?token='.$token)
            ->assertOk()
            ->assertJsonPath('data.aborted', true);
    }

    /**
     * 최소 간격에 걸려 건너뛴 호출은 **점검 시각을 전진시키지 않는다** (D17 무유실).
     *
     * 러너 단위 시뮬레이션은 「스킵됐다고 가정」하고 러너를 직접 부르므로 이 축을 재지
     * 못한다 — 스킵 분기에 상태 기록이 추가되면 그 사이에 낀 고정 시각 예약이 소급 창
     * 밖으로 밀려 영영 실행되지 않는데, 오류도 로그도 남지 않는다.
     *
     * @scenario caller=external, token=valid, min_interval=within
     *
     * @effects throttled_call_does_not_advance_the_checkpoint
     *
     * @return void 반환값 없음
     */
    public function test_throttled_call_does_not_advance_the_checkpoint(): void
    {
        $token = app(WebCronTokenStore::class)->rotate();
        $this->setSetting('cron_min_interval', 5);

        $state = app(ScheduleRunState::class);

        // 점검 시각을 **뚜렷하게 과거**로 심는다. 같은 초 안에서 비교하면 전진했는지
        // 아닌지가 구분되지 않아, 스킵 분기에 상태 기록을 넣어도 초록이 유지된다.
        $checkpoint = CarbonImmutable::now()->subHours(3);
        $state->recordRun(new ScheduleRunSummary(
            startedAt: $checkpoint,
            finishedAt: $checkpoint,
            due: 0,
            executed: 0,
            failed: 0,
            skipped: 0,
            truncated: false,
            events: [],
        ), advanceCheckpoint: true);

        // 방금 부른 것으로 기록해 최소 간격에 걸리게 한다.
        $state->recordInvocation(CarbonImmutable::now());

        $this->getJson(self::ENDPOINT.'?token='.$token)
            ->assertOk()
            ->assertJsonPath('data.ran', false)
            ->assertJsonPath('data.reason', 'min_interval');

        $state->refresh();

        $this->assertSame(
            $checkpoint->toIso8601String(),
            $state->lastCheckedAt()?->toIso8601String(),
            '건너뛴 호출이 점검 시각을 전진시켰다 — 그 사이 도래한 예약이 소급 창 밖으로 밀린다.',
        );
    }

    /**
     * 호출 주소는 라우트 이름으로 등록되어 있고 정적 확장자로 끝나지 않는다.
     *
     * 확장자로 끝나면 정적 최적화 블록이 있는 서버에서 그 주소만 404 가 된다 (R33).
     *
     * @scenario entry_point=web
     *
     * @effects cron_route_has_no_static_extension
     *
     * @return void 반환값 없음
     */
    public function test_cron_route_is_named_and_has_no_static_extension(): void
    {
        $route = Route::getRoutes()->getByName('api.public.system.cron.run');

        $this->assertNotNull($route, '웹 크론 라우트에 name() 이 없습니다.');
        $this->assertSame(ltrim(self::ENDPOINT, '/'), $route->uri());
        $this->assertDoesNotMatchRegularExpression(
            '/\.(js|mjs|css|json|map|xml)$/',
            $route->uri(),
            '웹 크론 주소가 정적 확장자로 끝나면 정적 최적화 서버에서 404 가 된다.',
        );
    }

    /**
     * 호출 번호는 **어떤 로그에도** 남지 않는다.
     *
     * 이 번호는 인증 없는 실행 권한을 가진 자격증명이라, 로그에 한 번 실리면 그
     * 파일을 읽을 수 있는 모든 경로가 실행 권한을 얻는다. 로그는 백업·수집기·지원
     * 문의 첨부로 흘러가므로 회수도 되지 않는다.
     *
     * 판정은 **실제로 디스크에 쓰인 로그 파일**로 한다. 로그 이벤트를 듣는 방식은
     * 한 번 시도했다가 버렸다 — 되돌림 검산에서 토큰을 실제로 기록해도 초록이었다
     * (이벤트가 잡히지 않아 훑을 모집단이 비었고, 빈 모집단은 조용히 통과한다).
     * 그래서 카나리아 한 줄을 먼저 남겨 **포착 경로가 살아 있음을 먼저 단언**한 뒤
     * 그 파일에서 토큰을 찾는다.
     *
     * @scenario caller=external, token=valid, token=invalid, token_channel=query, token_channel=header
     *
     * @effects web_cron_token_never_reaches_any_log
     *
     * @return void 반환값 없음
     */
    public function test_token_is_never_written_to_any_log(): void
    {
        $logPath = storage_path('framework/testing/web-cron-log-'.bin2hex(random_bytes(4)).'.log');
        $canary = 'canary-'.bin2hex(random_bytes(4));

        Config::set('logging.default', 'g7_cron_probe');
        Config::set('logging.channels.g7_cron_probe', [
            'driver' => 'single',
            'path' => $logPath,
            'level' => 'debug',
        ]);
        $this->resetLogManager();

        $token = app(WebCronTokenStore::class)->rotate();
        $this->assertNotSame('', $token);

        Log::info($canary);

        // 통과 · 잘못된 번호 · 헤더 전달 — 번호가 요청에 실리는 전 경로.
        $this->getJson(self::ENDPOINT.'?token='.$token);
        $this->getJson(self::ENDPOINT.'?token=wrong-'.$token);
        $this->getJson(self::ENDPOINT, [WebCronTokenStore::TOKEN_HEADER => $token]);

        $written = is_file($logPath) ? (string) file_get_contents($logPath) : '';

        // 포착 경로가 살아 있는가 — 이것이 없으면 아래 단언이 빈 문자열을 훑는다.
        $this->assertStringContainsString(
            $canary,
            $written,
            '로그 포착 경로가 죽었다 — 아래 토큰 단언이 아무것도 재지 못한다.',
        );

        $this->assertStringNotContainsString(
            $token,
            $written,
            '호출 번호가 로그 파일에 실렸다 — 그 파일을 읽을 수 있는 모든 경로가 인증 없는 '
            .'실행 권한을 얻는다. 로그는 백업·수집기·지원 문의로 흘러가 회수되지 않는다.',
        );

        $this->resetLogManager();
        @unlink($logPath);
    }

    /**
     * 로그 매니저를 버려 새 채널 설정이 실제로 적용되게 합니다.
     *
     * 이미 해석된 매니저는 옛 채널을 캐시하고 있어, 설정만 바꾸면 기록이 원래 파일로
     * 계속 간다 — 그러면 포착 파일이 비어 단언이 아무것도 재지 못한다.
     *
     * @return void 반환값 없음
     */
    private function resetLogManager(): void
    {
        $this->app->forgetInstance('log');
        Log::clearResolvedInstances();
    }

    /**
     * `system` 카테고리 설정 한 건을 런타임에 주입합니다.
     *
     * 런타임이 실제로 읽는 자리(`g7_settings.core.*`)에 직접 넣는다 — 설정 파일에
     * 쓰면 그 값을 config 로 옮기는 것은 부팅 시점의 Provider 라, 같은 요청 안에서는
     * 반영되지 않는다. 파일 → config 반영 경로는 설정 저장 테스트가 따로 잰다.
     *
     * @param  string  $key  필드명 (카테고리 접두사 없이)
     * @param  mixed  $value  값
     * @return void 반환값 없음
     */
    private function setSetting(string $key, mixed $value): void
    {
        Config::set("g7_settings.core.system.{$key}", $value);
    }

    /**
     * 다른 호출이 간격 판정 잠금을 쥔 동안 들어온 호출은 실행하지 않고 간격 미도달로 답한다.
     *
     * 판정(`throttleRemainingSeconds`)과 기록(`recordInvocation`)이 따로 놓여 있으면, 같은 순간에
     * 도착한 두 호출이 둘 다 「직전 호출 없음」을 읽고 함께 러너를 돌린다. 판정과 기록은 한 잠금
     * 안에서 하고, 잠금을 얻지 못한 호출은 앞 호출이 곧 기록할 것이므로 간격 미도달로 본다.
     *
     * @scenario caller=external, token=valid, min_interval=within
     *
     * @effects concurrent_call_does_not_pass_the_interval_check_twice
     *
     * @return void 반환값 없음
     */
    public function test_call_while_another_holds_the_interval_lock_does_not_run(): void
    {
        $token = app(WebCronTokenStore::class)->rotate();
        $this->setSetting('cron_min_interval', 5);

        $lock = Cache::lock(WebCronPolicy::INVOCATION_LOCK_KEY, WebCronPolicy::INVOCATION_LOCK_SECONDS);
        $this->assertTrue($lock->get(), '시험 준비 — 잠금을 먼저 쥐어야 한다');

        try {
            $this->getJson(self::ENDPOINT.'?token='.$token)
                ->assertOk()
                ->assertJsonPath('data.ran', false)
                ->assertJsonPath('data.reason', 'min_interval');

            $state = app(ScheduleRunState::class);
            $state->refresh();
            $this->assertNull($state->lastInvokedAt(), '잠금 밖에서 판정을 통과해 호출을 기록했다');
        } finally {
            $lock->release();
        }

        // 잠금이 풀리면 정상으로 돈다 — 「영원히 막는」 구현이 초록이 되지 않게.
        $this->getJson(self::ENDPOINT.'?token='.$token)->assertJsonPath('data.ran', true);
    }
}

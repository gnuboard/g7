<?php

namespace App\Benchmark\Axes;

use App\Benchmark\BenchmarkIdentity;
use App\Benchmark\Contracts\BenchmarkAxisRunner;
use App\Benchmark\DTO\BenchmarkProfile;
use App\Benchmark\DTO\BenchmarkResult;
use App\Benchmark\DTO\BenchmarkRunOptions;
use App\Benchmark\QueryCollector;
use App\Enums\BenchmarkAxis;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * 화면 응답 축 실행기 — 화면 1장의 응답 시간 + 실행 쿼리 건수 + N+1 후보
 *
 * 사용자가 체감하는 단위가 "화면 한 장"이므로 네 축 중 값어치가 가장 큽니다. 목록 SELECT
 * 는 빠른데 화면이 느린 경우(관계 지연 로딩으로 인한 N+1, 권한 조회 반복 등)를 잡는 것이
 * 이 축의 목적입니다.
 *
 * 실행 방식은 라우트를 **HTTP 커널로 내부 요청** 처리하는 것입니다. `Route::dispatch` 로
 * 라우트만 때리면 전역 미들웨어(인증·권한·로케일·타임존)를 건너뛰어 화면과 다른 것을 재게
 * 됩니다. 커널을 쓰면 실제 요청과 같은 경로를 지납니다.
 *
 * 계측 계정 생성과 요청 처리 전체를 롤백되는 트랜잭션으로 감싸므로, 계측이 데이터에 흔적을
 * 남기지 않습니다(`BenchmarkIdentity::withRolledBackTransaction`).
 */
class ScreenAxisRunner implements BenchmarkAxisRunner
{
    public function __construct(
        private readonly BenchmarkIdentity $identity,
        private readonly QueryCollector $collector,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function axis(): BenchmarkAxis
    {
        return BenchmarkAxis::Screen;
    }

    /**
     * {@inheritDoc}
     */
    public function run(BenchmarkProfile $profile, BenchmarkRunOptions $options, ?\Closure $onProgress = null): BenchmarkResult
    {
        if ($profile->mutates() && ! $options->allowWrite) {
            return BenchmarkResult::skipped($profile, '데이터를 변경하는 화면입니다. --allow-write 를 붙여 실행하세요.');
        }

        [$uri, $uriError] = $this->resolveUri($profile);

        if ($uriError !== null) {
            return BenchmarkResult::skipped($profile, $uriError);
        }

        // `--as` 로 지정한 계정은 트랜잭션 진입 전에 확인한다 — 없는 계정으로 계측을 시작하면
        // 롤백 구간 안에서 실패해 사유만 흐려진다.
        if ($options->asUser !== null && $this->identity->findExistingUser($options->asUser) === null) {
            return BenchmarkResult::skipped($profile, "계측에 사용할 계정을 찾을 수 없습니다: {$options->asUser}");
        }

        $method = strtoupper((string) $profile->option('method', 'GET'));
        $query = (array) $profile->option('query', []);
        $query['per_page'] ??= $options->perPage;

        // 데이터를 변경하는 화면은 반복 측정 시 2회차부터 조건이 달라진다(중복 키, 재고 소진).
        // 롤백은 계측 종료 시 한 번이므로 회차 간에는 되돌려지지 않는다 → 1회만 잰다.
        $runs = $profile->mutates() ? 1 : max(1, $options->runs);
        $notes = [];

        if ($profile->mutates()) {
            $notes[] = '데이터 변경 화면이라 1회만 측정했습니다 (회차 간 조건 변화 방지).';
        }

        try {
            $measured = $this->identity->withRolledBackTransaction(
                fn () => $this->measure($profile, (string) $uri, $method, $query, $runs, $options, $onProgress)
            );
        } catch (\Throwable $e) {
            return BenchmarkResult::skipped($profile, '계측 실패: '.$e->getMessage());
        }

        $summary = $measured['summary'];

        foreach ($summary['n_plus_one'] as $candidate) {
            $notes[] = sprintf('N+1 후보 %d회: %s', $candidate['count'], $candidate['sql']);
        }

        if ($summary['n_plus_one'] === []) {
            $notes[] = 'N+1 후보 없음 (같은 SQL 5회 이상 반복 없음).';
        }

        return new BenchmarkResult(
            profile: $profile,
            headers: ['요청', '상태', '응답(ms)', '쿼리(건)', 'DB(ms)'],
            rows: [[
                $method.' '.$uri,
                (string) $measured['status'],
                number_format($measured['total_ms'], 1),
                number_format($summary['count']),
                number_format($summary['db_ms'], 1),
            ]],
            metrics: [
                'uri' => $uri,
                'method' => $method,
                'query' => $query,
                'status' => $measured['status'],
                'runs' => $runs,
                'total_ms' => $measured['total_ms'],
                'query_count' => $summary['count'],
                'db_ms' => $summary['db_ms'],
                'n_plus_one' => $summary['n_plus_one'],
                'acting_user' => $measured['acting_user'],
            ],
            notes: $notes,
        );
    }

    /**
     * 계측 대상 URI 를 해석합니다.
     *
     * 라우트명 선언을 권장하는 이유는 두 가지입니다 — 프리픽스를 문자열로 조립하지 않아도
     * 되고, 라우트가 사라지면 계측이 조용히 404 를 재는 대신 사유를 남기고 건너뜁니다.
     *
     * 예외를 던지지 않고 사유 문자열을 함께 돌려주는 이유는, 이 축의 실패 계약이
     * `BenchmarkResult::skipped` 이기 때문입니다 — 던지고 곧바로 잡는 우회로를 만들지 않습니다.
     *
     * @param  BenchmarkProfile  $profile  프로파일
     * @return array{0: string|null, 1: string|null} [경로, 실패 사유]
     */
    private function resolveUri(BenchmarkProfile $profile): array
    {
        $routeName = $profile->option('route');

        if (is_string($routeName) && $routeName !== '') {
            if (Route::getRoutes()->getByName($routeName) === null) {
                return [null, "등록되지 않은 라우트명: {$routeName} (해당 확장이 설치되어 있는지 확인)"];
            }

            return [route($routeName, (array) $profile->option('route_params', []), false), null];
        }

        return ['/'.ltrim((string) $profile->option('uri'), '/'), null];
    }

    /**
     * 내부 요청을 반복 실행해 응답 시간 중앙값과 쿼리 요약을 냅니다.
     *
     * @param  BenchmarkProfile  $profile  프로파일
     * @param  string  $uri  경로
     * @param  string  $method  HTTP 메서드
     * @param  array<string, mixed>  $query  쿼리스트링
     * @param  int  $runs  측정 횟수
     * @param  BenchmarkRunOptions  $options  실행 옵션
     * @param  \Closure|null  $onProgress  진행 콜백
     * @return array{status: int, total_ms: float, summary: array<string, mixed>, acting_user: array<string, mixed>} 계측 결과
     */
    private function measure(
        BenchmarkProfile $profile,
        string $uri,
        string $method,
        array $query,
        int $runs,
        BenchmarkRunOptions $options,
        ?\Closure $onProgress,
    ): array {
        $notify = $onProgress ?? static fn (string $message) => null;

        $issued = $this->identity->issueToken(
            (array) $profile->option('permissions', []),
            $options->asUser !== null ? $this->identity->findExistingUser($options->asUser) : null
        );

        $notify(sprintf(
            '인증: %s (%s)',
            $issued['user']->email,
            $issued['ephemeral'] ? '계측용 임시 계정 — 종료 시 롤백' : '지정 계정'
        ));

        $samples = [];
        $status = 0;
        $queries = [];

        // 첫 회는 라우트 매칭·컨테이너 해석 워밍이라 버린다 (측정 회차와 별도로 1회 더 실행)
        for ($i = 0; $i <= $runs; $i++) {
            $collected = $this->collector->collect(
                fn () => $this->dispatch($uri, $method, $query, $issued['token'])
            );

            /** @var array{status: int, ms: float} $outcome */
            $outcome = $collected['value'];
            $status = $outcome['status'];

            if ($i === 0) {
                continue;
            }

            $samples[] = $outcome['ms'];
            $queries = $collected['queries'];
        }

        sort($samples);

        return [
            'status' => $status,
            'total_ms' => $samples[intdiv(count($samples), 2)],
            'summary' => $this->collector->summarize($queries),
            'acting_user' => [
                'id' => $issued['user']->id,
                'email' => $issued['user']->email,
                'ephemeral' => $issued['ephemeral'],
            ],
        ];
    }

    /**
     * 내부 요청 1회를 처리하고 소요 시간을 잽니다.
     *
     * 커널이 컨테이너의 `request` 인스턴스와 인증 가드 상태를 갈아치우므로, 처리 후
     * 원상 복구합니다 — 복구하지 않으면 이어지는 회차/프로파일이 앞 요청의 인증 상태를
     * 물려받아 계측이 서로 오염됩니다.
     *
     * @param  string  $uri  경로
     * @param  string  $method  HTTP 메서드
     * @param  array<string, mixed>  $query  쿼리스트링/바디
     * @param  string  $token  Sanctum 토큰
     * @return array{status: int, ms: float} 상태 코드와 소요 시간
     */
    private function dispatch(string $uri, string $method, array $query, string $token): array
    {
        $previousRequest = app()->bound('request') ? app('request') : null;

        $request = Request::create($uri, $method, $query);
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('Authorization', 'Bearer '.$token);

        $start = microtime(true);

        try {
            $response = app(HttpKernel::class)->handle($request);
            $status = $response->getStatusCode();
            // 스트리밍 응답은 본문 생성까지가 사용자 체감 시간이라 여기서 소비한다
            $response->getContent();
        } finally {
            $ms = (microtime(true) - $start) * 1000;

            Auth::forgetGuards();

            if ($previousRequest !== null) {
                app()->instance('request', $previousRequest);
            }
        }

        return ['status' => $status, 'ms' => $ms];
    }
}

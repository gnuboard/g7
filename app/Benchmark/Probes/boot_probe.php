<?php

use App\Extension\HookManager;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

/**
 * 부팅 축 자식 프로브 — 새 PHP 프로세스 하나에서 웹 요청 1건을 처음부터(오토로드·앱 생성·부트스트랩)
 * 처리하고 계측값을 JSON 한 줄로 출력합니다.
 *
 * `g7:bench --axis=boot` 가 회차마다 이 스크립트를 새 프로세스로 실행합니다(`BootAxisRunner`).
 * 이미 부팅된 앱 안에서 요청을 처리하는 화면 축은 부팅 비용을 잴 수 없어서 따로 둡니다.
 *
 * 사용: php boot_probe.php <경로> [Bearer 토큰]
 * 환경: APP_RUNNING_IN_CONSOLE=false — 웹 요청과 같은 분기(템플릿 로딩 등)를 타게 합니다(실행기가 넣습니다).
 *
 * 이 스크립트는 파일을 쓰지 않습니다. 결과는 표준 출력 마지막 줄 `G7_BOOT_PROBE ` 접두 JSON 입니다.
 */
$startedAt = hrtime(true);
$elapsedMs = static fn (int $from, int $to): float => round(($to - $from) / 1e6, 2);

$root = dirname(__DIR__, 3);
$path = $argv[1] ?? '/api/consent';
$token = $argv[2] ?? '';

chdir($root);
define('LARAVEL_START', microtime(true));

require $root.'/vendor/autoload.php';
$autoloadedAt = hrtime(true);

/** @var Application $app */
$app = require $root.'/bootstrap/app.php';
$createdAt = hrtime(true);

$queries = [];
$cache = ['hit' => 0, 'missed' => 0, 'written' => 0, 'keys' => []];
$countKey = static function (string $key) use (&$cache): void {
    $cache['keys'][$key] = ($cache['keys'][$key] ?? 0) + 1;
};

// 리스너는 디스패처 하나에만 한 번 건다 — 연결·저장소가 디스패처를 컨테이너에서 받으므로 부팅 초반 쿼리까지 잡힌다.
// (DB 매니저와 디스패처 양쪽에 걸면 같은 쿼리가 두 번 집계된다.)
$events = $app['events'];
$events->listen(QueryExecuted::class, static function ($event) use (&$queries): void {
    $queries[] = ['sql' => preg_replace('/\s+/', ' ', $event->sql), 'time' => (float) $event->time];
});
$events->listen(CacheHit::class, static function ($event) use (&$cache, $countKey): void {
    $cache['hit']++;
    $countKey('HIT '.$event->key);
});
$events->listen(CacheMissed::class, static function ($event) use (&$cache, $countKey): void {
    $cache['missed']++;
    $countKey('MISS '.$event->key);
});
$events->listen(KeyWritten::class, static function ($event) use (&$cache, $countKey): void {
    $cache['written']++;
    $countKey('WRITE '.$event->key);
});

$kernel = $app->make(Kernel::class);
$server = ['HTTP_ACCEPT' => 'application/json'];
if ($token !== '') {
    $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
}

$baseUrl = rtrim((string) (getenv('G7_BOOT_PROBE_BASE_URL') ?: 'http://localhost'), '/');
$request = Request::create($baseUrl.$path, 'GET', [], [], [], $server);
$app->instance('request', $request);

$kernel->bootstrap();
$bootstrappedAt = hrtime(true);
$queriesAtBoot = count($queries);

$response = $kernel->handle($request);
$handledAt = hrtime(true);
$kernel->terminate($request, $response);
$terminatedAt = hrtime(true);

// 리스너 수: 이름 리스너 + 와일드카드 리스너(`hook.*` 등). 계측 리스너 4개는 뺀다.
$dispatcher = $app['events'];
$listenerCount = 0;
foreach ($dispatcher->getRawListeners() as $listeners) {
    $listenerCount += count($listeners);
}
$wildcardCount = 0;
if (property_exists($dispatcher, 'wildcards')) {
    $wildcards = (new ReflectionProperty($dispatcher, 'wildcards'))->getValue($dispatcher);
    foreach ($wildcards as $listeners) {
        $wildcardCount += count($listeners);
    }
}

$countCallbacks = static function (array $registry): int {
    $total = 0;
    foreach ($registry as $byPriority) {
        foreach ($byPriority as $callbacks) {
            $total += count($callbacks);
        }
    }

    return $total;
};

$sqlCounts = array_count_values(array_column($queries, 'sql'));
arsort($sqlCounts);
arsort($cache['keys']);

echo 'G7_BOOT_PROBE '.json_encode([
    'path' => $path,
    'status' => $response->getStatusCode(),
    'ms' => [
        'autoload' => $elapsedMs($startedAt, $autoloadedAt),
        'app_create' => $elapsedMs($autoloadedAt, $createdAt),
        'bootstrap' => $elapsedMs($createdAt, $bootstrappedAt),
        'handle' => $elapsedMs($bootstrappedAt, $handledAt),
        'terminate' => $elapsedMs($handledAt, $terminatedAt),
        'total' => $elapsedMs($startedAt, $terminatedAt),
    ],
    'db' => [
        'count' => count($queries),
        'during_boot' => $queriesAtBoot,
        'time_ms' => round(array_sum(array_column($queries, 'time')), 2),
        'repeated' => array_filter($sqlCounts, static fn (int $count): bool => $count > 1),
        'statements' => $sqlCounts,
    ],
    'cache' => $cache,
    'included_files' => count(get_included_files()),
    'providers' => count($app->getLoadedProviders()),
    'event_listeners' => max(0, $listenerCount - 4),
    'event_wildcard_listeners' => $wildcardCount,
    'hook_actions' => $countCallbacks(HookManager::getHooks()),
    'hook_filters' => $countCallbacks(HookManager::getFilters()),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;

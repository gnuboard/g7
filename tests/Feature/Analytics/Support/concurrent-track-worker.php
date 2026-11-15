<?php

/**
 * 방문 적재 동시성 시험의 워커 프로세스.
 *
 * `VisitServiceConcurrencyTest` 가 여러 개를 동시에 띄운다. 각 워커는 testing 환경으로 앱을 부팅하고,
 * 정해진 시각(장벽)까지 기다렸다가 같은(또는 다른) 방문자 키로 비콘을 연달아 적재한다. 결과는
 * 표준 출력에 JSON 한 줄로 남긴다 — 적재된 방문 ID, 고른 경로(방문자 키 잠금 여부), 롤백 횟수.
 *
 * 롤백 횟수는 적재 트랜잭션이 교착(1213)·잠금 대기 초과로 되돌려진 횟수다. 재시도(`STORE_ATTEMPTS`)가
 * 다시 해서 성공하면 실패 목록에는 남지 않으므로, 교착이 났는지는 이 수로만 알 수 있다.
 *
 * 인자: <방문자 키> <비콘 수> <장벽 시각(유닉스 초, 소수)> <URL 꼬리표>
 */

use App\Analytics\TrackingRequest;
use App\Analytics\VisitorStoreLock;
use App\Extension\Consent\ConsentManager;
use App\Extension\HookManager;
use App\Services\Analytics\VisitService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Event;

[$script, $visitorKey, $count, $startAt, $tag] = $argv + [null, null, '1', '0', 'concurrency'];

$basePath = dirname(__DIR__, 4);

require $basePath.'/vendor/autoload.php';

$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing')) {
    fwrite(STDERR, "testing 환경에서만 실행합니다.\n");
    exit(2);
}

config(['g7_settings.core.analytics.privacy_mode' => 'full']);
config(['g7_settings.core.analytics.enabled' => true]);

// 동의 제공자를 비운다. 워커는 별도 프로세스라 테스트의 격리를 물려받지 못하고, 캐시 저장소(특히
// 개발 사이트와 접두가 같은 Redis)에 남은 확장 상태로 동의 관리 플러그인이 켜진 것으로 부팅될 수
// 있다. 그러면 동의 전 방문자는 익명 모드가 되어 쿠키 키가 아닌 익명 키로 적재되고, 이 시험이 재는
// 「같은 방문자 키」 경로를 벗어난다.
HookManager::clearFilter(ConsentManager::REGISTERED_PROVIDERS_FILTER);

$rollbacks = 0;
Event::listen(TransactionRolledBack::class, function () use (&$rollbacks): void {
    $rollbacks++;
});

$service = $app->make(VisitService::class);
$serialized = $app->make(VisitorStoreLock::class)->isAvailable();

while (microtime(true) < (float) $startAt) {
    usleep(1000);
}

$visitIds = [];
$actionIds = [];

for ($i = 0; $i < (int) $count; $i++) {
    $result = $service->track(new TrackingRequest(
        type: 'pageview',
        url: 'https://example.test/concurrency/'.$tag.'/'.getmypid().'/'.$i,
        title: '동시성 '.$tag,
        ip: '203.0.113.61',
        userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0.0.0 Safari/537.36',
        browserLanguage: 'ko-KR',
        resolution: '1536x864',
        cookies: ['g7_vid' => $visitorKey],
        receivedAt: now()->format('Y-m-d H:i:s'),
    ));

    $visitIds[] = $result['visit_id'];
    $actionIds[] = $result['action_id'];
}

echo json_encode([
    'serialized' => $serialized,
    'store' => config('cache.default'),
    'visit_ids' => $visitIds,
    'action_ids' => $actionIds,
    'rollbacks' => $rollbacks,
]).PHP_EOL;

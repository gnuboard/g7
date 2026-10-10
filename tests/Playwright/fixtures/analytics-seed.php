<?php

/**
 * 방문자 통계 실측·E2E 시드 도구 (이슈 #682 S3)
 *
 * 저장소 루트에서 단독으로 실행한다 — 앱을 직접 부팅하므로 tinker 인용 문제가 없다.
 *
 *   php tests/Playwright/fixtures/analytics-seed.php month                    30일 × 3유형 × 하루 20방문 + 봇 요청 50 (기존 시드는 지우고 다시)
 *   php tests/Playwright/fixtures/analytics-seed.php live                     지금 사이트를 보고 있는 방문 5건 (실시간 화면용)
 *   php tests/Playwright/fixtures/analytics-seed.php privacy-visitor          IP 가 남은 방문자 1명 — 출력 `SEED_VISITOR_KEY=…`
 *   php tests/Playwright/fixtures/analytics-seed.php cleanup-privacy-visitor  위 방문자 삭제
 *   php tests/Playwright/fixtures/analytics-seed.php cleanup-month            month·live 시드 삭제
 *   php tests/Playwright/fixtures/analytics-seed.php cleanup-onview           화면 열 때 집계 E2E 의 표식 방문(해상도 1113x1113) 삭제
 *
 * 시드 행은 방문자 키 접두(`seed682`·`live682`·`e2e682`)로 구분한다 — 실제 방문 기록과 섞이지 않고
 * 이 도구만 지운다. IP 는 문서용 대역(RFC 5737)만 쓴다. 집계는 하지 않는다 — 시드 뒤
 * `analytics:archive --period=day --from=… --to=… --force` 와 `--period=hour` 를 돌린다.
 */

use App\Analytics\AnalyticsClock;
use App\Contracts\Repositories\Analytics\ActionNameRepositoryInterface;
use App\Enums\Analytics\ActionType;
use App\Enums\Analytics\DeviceType;
use App\Enums\Analytics\ReferrerType;
use App\Enums\Analytics\VisitorType;
use App\Enums\Analytics\VisitSource;
use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsBotHit;
use App\Models\Analytics\AnalyticsConversion;
use App\Models\Analytics\AnalyticsVisit;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

const SEED_PREFIXES = ['seed682', 'live682'];
const E2E_PREFIX = 'e2e682';

/** 사이트 페이지 — [경로, 제목, 가중치] */
const SEED_PAGES = [
    ['/', '홈', 30],
    ['/board/free', '자유게시판', 25],
    ['/boards', '게시판 목록', 15],
    ['/board/notice', '공지사항', 10],
    ['/shop', '쇼핑', 10],
    ['/shop/product/3', '상품 3', 6],
    ['/page/about', '소개', 4],
];

/**
 * 가중치 목록에서 하나를 고릅니다.
 *
 * @param  array<int, array<int, mixed>>  $items  [값..., 가중치] 목록 (가중치는 마지막 원소)
 * @return array<int, mixed> 고른 항목
 */
function seed_pick(array $items): array
{
    $total = array_sum(array_map(static fn (array $i): int => (int) end($i), $items));
    $roll = mt_rand(1, $total);

    foreach ($items as $item) {
        $roll -= (int) end($item);
        if ($roll <= 0) {
            return $item;
        }
    }

    return $items[0];
}

/**
 * 사이트 주소의 사전 이름(호스트/경로)을 사전에 넣고 id 를 돌려줍니다.
 *
 * @param  string  $type  사전 종류 (url·title·download·outlink·search)
 * @param  string  $name  이름
 * @return int 사전 행 id
 */
function seed_name(string $type, string $name): int
{
    /** @var ActionNameRepositoryInterface $names */
    $names = app(ActionNameRepositoryInterface::class);

    return $names->firstOrCreateByHash($type, $name, $type === 'url' ? 2 : null)->id;
}

/**
 * 페이지 경로를 이 사이트의 사전 이름으로 바꿉니다.
 *
 * @param  string  $path  경로
 * @return string 사전 이름 (`호스트/경로`)
 */
function seed_url_name(string $path): string
{
    $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';

    return strtolower($host).$path;
}

/**
 * 방문 하나와 그 액션·전환을 만듭니다.
 *
 * @param  array<string, mixed>  $visit  방문 속성 (visitor_key·visitor_type·user_id·first_action_at·visit_date·ip 등)
 * @param  array<int, array<string, mixed>>  $actions  액션 목록 (type·name·title·spent·result_count)
 * @param  array<int, array<string, mixed>>  $conversions  전환 목록 (goal_key·label·revenue)
 * @return AnalyticsVisit 만든 방문
 */
function seed_visit(array $visit, array $actions, array $conversions = []): AnalyticsVisit
{
    /** @var Carbon $at */
    $at = $visit['first_action_at']->copy();
    $pageviews = array_values(array_filter($actions, static fn (array $a): bool => $a['type'] === ActionType::Pageview));
    $rows = [];
    $total = 0;

    foreach ($actions as $index => $action) {
        $spent = $action['type'] === ActionType::Pageview ? (int) ($action['spent'] ?? 0) : null;
        $rows[] = [$action, $at->copy(), $spent];
        $total += (int) ($spent ?? 0);
        $at->addSeconds(max(5, (int) ($spent ?? 5)));
    }

    $entry = $pageviews[0] ?? null;
    $exit = $pageviews === [] ? null : $pageviews[count($pageviews) - 1];

    $model = AnalyticsVisit::query()->create(array_merge([
        'visitor_key_type' => 'cookie',
        'user_hash' => null,
        'last_action_at' => $at->copy(),
        'actions_count' => count($actions),
        'pageviews_count' => count($pageviews),
        'total_time_seconds' => $total,
        'is_bounce' => count($pageviews) === 1,
        'entry_action_name_id' => $entry ? seed_name('url', seed_url_name($entry['name'])) : null,
        'exit_action_name_id' => $exit ? seed_name('url', seed_url_name($exit['name'])) : null,
        'source' => VisitSource::Beacon,
        'ip_hash' => null,
        'region_code' => null,
        'city' => null,
        'os_version' => null,
        'custom_dimensions' => null,
    ], $visit));

    foreach ($rows as [$action, $occurredAt, $spent]) {
        $type = $action['type'];
        $nameType = match ($type) {
            ActionType::Download => 'download',
            ActionType::Outlink => 'outlink',
            ActionType::SiteSearch => 'search',
            default => 'url',
        };
        $name = $nameType === 'url' ? seed_url_name($action['name']) : $action['name'];

        AnalyticsAction::query()->create([
            'visit_id' => $model->id,
            'visitor_key' => $model->visitor_key,
            'action_type' => $type,
            'action_name_id' => seed_name($nameType, $name),
            'page_title_id' => isset($action['title']) ? seed_name('title', $action['title']) : null,
            'occurred_at' => $occurredAt,
            'time_spent_seconds' => $spent,
            'search_result_count' => $action['result_count'] ?? null,
            'search_tab' => $type === ActionType::SiteSearch ? 'all' : null,
            'custom_dimensions' => null,
            'created_at' => now(),
        ]);
    }

    foreach ($conversions as $conversion) {
        AnalyticsConversion::query()->create([
            'visit_id' => $model->id,
            'visitor_key' => $model->visitor_key,
            'goal_key' => $conversion['goal_key'],
            'extension_type' => $conversion['extension_type'] ?? null,
            'extension_identifier' => $conversion['extension_identifier'] ?? null,
            'label' => $conversion['label'] ?? null,
            'revenue' => $conversion['revenue'] ?? null,
            'currency' => isset($conversion['revenue']) ? 'KRW' : null,
            'occurred_at' => $at->copy(),
            'custom_dimensions' => null,
            'created_at' => now(),
        ]);
    }

    return $model;
}

/**
 * 방문 하나의 액션 목록을 무작위로 만듭니다.
 *
 * @return array<int, array<string, mixed>> 액션 목록
 */
function seed_random_actions(): array
{
    $actions = [];
    $count = mt_rand(1, 100) <= 38 ? 1 : mt_rand(2, 4);

    for ($i = 0; $i < $count; $i++) {
        [$path, $title] = seed_pick(SEED_PAGES);
        $actions[] = ['type' => ActionType::Pageview, 'name' => $path, 'title' => $title, 'spent' => $i === $count - 1 ? 0 : mt_rand(15, 240)];
    }

    if (mt_rand(1, 100) <= 12) {
        $keyword = seed_pick([['그누보드', 5], ['방문자 통계', 3], ['배송 조회', 2], ['없는검색어', 1]])[0];
        $actions[] = ['type' => ActionType::SiteSearch, 'name' => $keyword, 'result_count' => $keyword === '없는검색어' ? 0 : mt_rand(1, 40)];
    }

    if (mt_rand(1, 100) <= 9) {
        $actions[] = ['type' => ActionType::Download, 'name' => seed_pick([['report.pdf', 3], ['manual.hwp', 2], ['price.xlsx', 1]])[0]];
    }

    if (mt_rand(1, 100) <= 6) {
        $actions[] = ['type' => ActionType::Outlink, 'name' => seed_pick([['https://example.org/partner', 2], ['https://example.net/docs', 1]])[0]];
    }

    return $actions;
}

/**
 * 방문 환경(유입·국가·기기·언어)을 무작위로 만듭니다.
 *
 * @return array<string, mixed> 방문 속성
 */
function seed_random_environment(): array
{
    $ref = seed_pick([
        [ReferrerType::Direct, null, null, 50],
        [ReferrerType::Search, 'google.com', '그누보드 방문자 통계', 18],
        [ReferrerType::Search, 'search.naver.com', '그누보드7', 7],
        [ReferrerType::Website, 'example.org', null, 15],
        [ReferrerType::Campaign, 'newsletter', null, 10],
    ]);
    [$device, $browser, $os, $resolution] = seed_pick([
        [DeviceType::Desktop, 'Chrome', 'Windows', '1920x1080', 40],
        [DeviceType::Desktop, 'Edge', 'Windows', '2560x1440', 12],
        [DeviceType::Desktop, 'Safari', 'macOS', '1440x900', 8],
        [DeviceType::Mobile, 'Chrome', 'Android', '412x915', 22],
        [DeviceType::Mobile, 'Safari', 'iOS', '390x844', 13],
        [DeviceType::Tablet, 'Safari', 'iOS', '820x1180', 5],
    ]);
    [$country, $language, $locale] = seed_pick([
        ['KR', 'ko-KR', 'ko', 70],
        ['JP', 'ja-JP', 'ja', 12],
        ['US', 'en-US', 'en', 13],
        [null, 'en-GB', 'en', 5],
    ]);

    return [
        'referrer_type' => $ref[0],
        'referrer_name' => $ref[1],
        'referrer_url' => $ref[1] ? 'https://'.$ref[1].'/' : null,
        'referrer_keyword' => $ref[2],
        'campaign_name' => $ref[0] === ReferrerType::Campaign ? 'autumn_sale' : null,
        'campaign_source' => $ref[0] === ReferrerType::Campaign ? 'newsletter' : null,
        'campaign_medium' => $ref[0] === ReferrerType::Campaign ? 'email' : null,
        'country_code' => $country,
        'device_type' => $device,
        'browser_name' => $browser,
        'browser_version' => (string) mt_rand(118, 140),
        'os_name' => $os,
        'screen_resolution' => $resolution,
        'browser_language' => $language,
        'locale' => $locale,
    ];
}

/**
 * 접두로 표시한 시드 방문과 그 액션·전환을 지웁니다.
 *
 * @param  array<int, string>  $prefixes  방문자 키 접두 목록
 * @return array<string, int> 지운 건수
 */
function seed_delete(array $prefixes): array
{
    $ids = AnalyticsVisit::query()
        ->where(function ($q) use ($prefixes): void {
            foreach ($prefixes as $prefix) {
                $q->orWhere('visitor_key', 'like', $prefix.'%');
            }
        })
        ->pluck('id');

    $deleted = ['actions' => 0, 'conversions' => 0, 'visits' => 0];

    foreach ($ids->chunk(500) as $chunk) {
        $deleted['actions'] += AnalyticsAction::query()->whereIn('visit_id', $chunk)->delete();
        $deleted['conversions'] += AnalyticsConversion::query()->whereIn('visit_id', $chunk)->delete();
        $deleted['visits'] += AnalyticsVisit::query()->whereIn('id', $chunk)->delete();
    }

    return $deleted;
}

$mode = $argv[1] ?? 'month';
mt_srand(682);

$tz = AnalyticsClock::siteTimezone();
$now = Carbon::now('UTC');
$today = Carbon::now($tz)->startOfDay();

$adminId = User::query()->whereHas('roles', fn ($q) => $q->where('identifier', 'admin'))->orderBy('id')->value('id');
$memberId = User::query()->whereDoesntHave('roles', fn ($q) => $q->where('identifier', 'admin'))->orderBy('id')->value('id');

switch ($mode) {
    case 'month':
        $removed = seed_delete(SEED_PREFIXES);
        $bots = AnalyticsBotHit::query()->where('user_agent', 'like', '%seed682%')->delete();
        $created = ['visits' => 0, 'actions' => 0, 'conversions' => 0, 'bot_hits' => 0];
        $pool = [];
        $seen = [];

        DB::transaction(function () use (&$created, &$pool, &$seen, $today, $now, $adminId, $memberId): void {
            foreach ([VisitorType::Guest, VisitorType::Member, VisitorType::Admin] as $type) {
                $pool[$type->value] = array_map(static fn (int $i): string => 'seed682'.$type->value[0].bin2hex(random_bytes(6)).$i, range(1, 40));
            }

            for ($d = 29; $d >= 0; $d--) {
                $day = $today->copy()->subDays($d);

                foreach ([VisitorType::Guest, VisitorType::Member, VisitorType::Admin] as $type) {
                    for ($v = 0; $v < 20; $v++) {
                        $key = $pool[$type->value][mt_rand(0, 39)];
                        $start = $day->copy()->addSeconds(mt_rand(0, 86400 - 3600))->utc();

                        if ($start->greaterThan($now->copy()->subMinutes(20))) {
                            // 오늘은 지금 이전까지만 — 미래 방문은 원장에 있을 수 없다.
                            $start = $now->copy()->subMinutes(mt_rand(30, 600));
                        }

                        $actions = seed_random_actions();
                        $conversions = [];
                        if ($type === VisitorType::Member && mt_rand(1, 100) <= 4) {
                            $conversions[] = ['goal_key' => 'sirsoft-ecommerce.order_completed', 'extension_type' => 'module', 'extension_identifier' => 'sirsoft-ecommerce', 'label' => '주문 완료', 'revenue' => [45000, 12900, 88000][mt_rand(0, 2)]];
                        } elseif ($type === VisitorType::Guest && mt_rand(1, 100) <= 2) {
                            $conversions[] = ['goal_key' => 'core.user_registered', 'label' => '회원 가입'];
                        }

                        $model = seed_visit(array_merge(seed_random_environment(), [
                            'visitor_key' => $key,
                            'visitor_type' => $type,
                            'user_id' => match ($type) {
                                VisitorType::Admin => $adminId,
                                VisitorType::Member => $memberId,
                                default => null,
                            },
                            'visit_date' => $start->copy()->setTimezone($day->getTimezone())->toDateString(),
                            'first_action_at' => $start,
                            'ip' => ($type === VisitorType::Guest ? '198.51.100.' : '203.0.113.').(abs(crc32($key)) % 250 + 1),
                            'is_returning' => isset($seen[$key]),
                        ]), $actions, $conversions);

                        $seen[$key] = true;
                        $created['visits']++;
                        $created['actions'] += count($actions);
                        $created['conversions'] += count($conversions);
                        unset($model);
                    }
                }
            }

            for ($b = 0; $b < 50; $b++) {
                [$bot, $ua] = seed_pick([
                    ['Googlebot', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html) seed682', 45],
                    ['bingbot', 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm) seed682', 25],
                    ['Yeti', 'Mozilla/5.0 (compatible; Yeti/1.1; +https://naver.me/spd) seed682', 20],
                    ['DuckDuckBot', 'DuckDuckBot/1.1; (+http://duckduckgo.com/duckduckbot.html) seed682', 10],
                ]);
                [$path] = seed_pick(SEED_PAGES);
                AnalyticsBotHit::query()->create([
                    'bot_name' => $bot,
                    'user_agent' => $ua,
                    'url' => $path,
                    'url_hash' => md5($path),
                    'ip' => '192.0.2.'.mt_rand(1, 250),
                    'cache_status' => mt_rand(1, 100) <= 60 ? 'hit' : 'miss',
                    'occurred_at' => $today->copy()->subDays(mt_rand(0, 29))->addSeconds(mt_rand(0, 80000))->utc()->min($now),
                    'created_at' => now(),
                ]);
                $created['bot_hits']++;
            }
        });

        echo json_encode(['mode' => $mode, 'removed' => $removed, 'removed_bot_hits' => $bots, 'created' => $created,
            'from' => $today->copy()->subDays(29)->toDateString(), 'to' => $today->toDateString()], JSON_UNESCAPED_UNICODE).PHP_EOL;
        break;

    case 'live':
        seed_delete(['live682']);
        for ($i = 0; $i < 5; $i++) {
            $start = $now->copy()->subMinutes(4 - $i)->subSeconds(40);
            seed_visit(array_merge(seed_random_environment(), [
                'visitor_key' => 'live682'.bin2hex(random_bytes(6)).$i,
                'visitor_type' => VisitorType::Guest,
                'user_id' => null,
                'visit_date' => $start->copy()->setTimezone($tz)->toDateString(),
                'first_action_at' => $start,
                'ip' => '198.51.100.'.(200 + $i),
                'is_returning' => false,
            ]), [
                ['type' => ActionType::Pageview, 'name' => $i % 2 === 0 ? '/board/free' : '/', 'title' => '실시간', 'spent' => 20],
                ['type' => ActionType::Pageview, 'name' => '/boards', 'title' => '게시판 목록', 'spent' => 0],
            ]);
        }
        echo json_encode(['mode' => $mode, 'created' => 5], JSON_UNESCAPED_UNICODE).PHP_EOL;
        break;

    case 'privacy-visitor':
        seed_delete([E2E_PREFIX]);
        $key = E2E_PREFIX.bin2hex(random_bytes(8));
        $start = $now->copy()->subMinutes(45);
        seed_visit(array_merge(seed_random_environment(), [
            'visitor_key' => $key,
            'visitor_type' => VisitorType::Guest,
            'user_id' => null,
            'visit_date' => $start->copy()->setTimezone($tz)->toDateString(),
            'first_action_at' => $start,
            'ip' => '203.0.113.77',
            'country_code' => 'KR',
            'is_returning' => false,
        ]), [
            ['type' => ActionType::Pageview, 'name' => '/board/free', 'title' => '자유게시판', 'spent' => 62],
            ['type' => ActionType::Download, 'name' => 'report.pdf'],
            ['type' => ActionType::SiteSearch, 'name' => '그누보드', 'result_count' => 12],
            ['type' => ActionType::Pageview, 'name' => '/boards', 'title' => '게시판 목록', 'spent' => 0],
        ], [
            ['goal_key' => 'core.user_registered', 'label' => '회원 가입'],
        ]);
        echo 'SEED_VISITOR_KEY='.$key.PHP_EOL;
        break;

    case 'cleanup-privacy-visitor':
        echo json_encode(['mode' => $mode, 'deleted' => seed_delete([E2E_PREFIX])], JSON_UNESCAPED_UNICODE).PHP_EOL;
        break;

    case 'cleanup-month':
        $deleted = seed_delete(SEED_PREFIXES);
        $deleted['bot_hits'] = AnalyticsBotHit::query()->where('user_agent', 'like', '%seed682%')->delete();
        echo json_encode(['mode' => $mode, 'deleted' => $deleted], JSON_UNESCAPED_UNICODE).PHP_EOL;
        break;

    case 'cleanup-onview':
        // 화면 열 때 집계 E2E 가 비콘으로 남긴 표식 방문(해상도 1113x1113)을 지운다 — 뒤이어 오늘치를 다시 집계한다.
        $ids = AnalyticsVisit::query()->where('screen_resolution', '1113x1113')->pluck('id');
        $actions = AnalyticsAction::query()->whereIn('visit_id', $ids)->delete();
        $visits = AnalyticsVisit::query()->whereIn('id', $ids)->delete();
        echo json_encode(['mode' => $mode, 'deleted' => ['visits' => $visits, 'actions' => $actions]], JSON_UNESCAPED_UNICODE).PHP_EOL;
        break;

    default:
        fwrite(STDERR, "알 수 없는 모드: {$mode}\n");
        exit(1);
}

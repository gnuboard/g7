<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsClock;
use App\Analytics\AnalyticsConfig;
use App\Analytics\IpAddress;
use App\Analytics\VisitorRecognizer;
use App\Contracts\Repositories\Analytics\ActionNameRepositoryInterface;
use App\Contracts\Repositories\Analytics\ActionRepositoryInterface;
use App\Contracts\Repositories\Analytics\ConversionRepositoryInterface;
use App\Contracts\Repositories\Analytics\SaltRepositoryInterface;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;
use App\Extension\HookManager;
use App\Models\Analytics\AnalyticsVisit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 정보주체 요청 처리
 *
 * 「내 기록을 보여 달라」「지워 달라」는 요청에 답합니다. 주소·회원 번호·방문자 키
 * 중 하나로 찾아 방문 목록과 그 안의 액션·전환을 내주거나 지웁니다.
 *
 * 지우는 것은 원장뿐입니다 — 집계에는 개인을 가리키는 값이 없어 지울 것이 없고,
 * 집계를 함께 지우면 그 기간 통계가 통째로 사라집니다.
 *
 * 익명 모드로 모은 구간은 주소를 저장하지 않고 그날의 솔트로 만든 해시만 남깁니다.
 * 솔트는 며칠 뒤 지워지므로 오래된 구간은 주소로 찾을 수 없고, 화면이 그 한계를
 * 함께 안내합니다.
 */
class DataSubjectService
{
    /**
     * 주소 해시로 찾아볼 수 있는 날 수
     *
     * 오늘과 어제의 솔트만 남아 있어 그 이틀치만 다시 계산할 수 있습니다.
     */
    private const HASH_LOOKBACK_DAYS = 2;

    /**
     * 정보주체 처리기를 생성합니다.
     *
     * @param  VisitRepositoryInterface  $visits  방문 원장 저장소
     * @param  ActionRepositoryInterface  $actions  액션 원장 저장소
     * @param  ConversionRepositoryInterface  $conversions  전환 저장소
     * @param  ActionNameRepositoryInterface  $names  이름 사전 저장소
     * @param  SaltRepositoryInterface  $salts  솔트 저장소
     */
    public function __construct(
        private readonly VisitRepositoryInterface $visits,
        private readonly ActionRepositoryInterface $actions,
        private readonly ConversionRepositoryInterface $conversions,
        private readonly ActionNameRepositoryInterface $names,
        private readonly SaltRepositoryInterface $salts,
    ) {}

    /**
     * 정보주체의 방문을 찾습니다.
     *
     * @param  array<string, mixed>  $subject  찾을 기준 (ip, user_id, visitor_key)
     * @param  AnalyticsConfig  $config  설정
     * @param  string|null  $from  시작일 (Y-m-d)
     * @param  string|null  $to  종료일 (Y-m-d)
     * @return array{visits: Collection<int, AnalyticsVisit>, notice: string|null}
     *                                                                             찾은 방문과 한계 안내
     */
    public function find(array $subject, AnalyticsConfig $config, ?string $from = null, ?string $to = null): array
    {
        return [
            'visits' => $this->visits->findBySubject($this->resolveSubject($subject, $config), $from, $to),
            'notice' => $this->notice($subject),
        ];
    }

    /**
     * 정보주체의 방문과 그 안의 활동을 내보낼 모양으로 만듭니다.
     *
     * @param  array<string, mixed>  $subject  찾을 기준
     * @param  AnalyticsConfig  $config  설정
     * @param  string|null  $from  시작일 (Y-m-d)
     * @param  string|null  $to  종료일 (Y-m-d)
     * @return array<string, mixed> 내보낼 내용
     */
    public function export(array $subject, AnalyticsConfig $config, ?string $from = null, ?string $to = null): array
    {
        $resolved = $this->resolveSubject($subject, $config);
        $found = ['visits' => $this->visits->findBySubject($resolved, $from, $to), 'notice' => $this->notice($subject)];
        $visits = $found['visits'];
        $visitIds = $visits->pluck('id')->all();

        $actions = $this->actions->groupedForVisits($visitIds);
        $conversions = $this->conversions->findForVisits($visitIds)->groupBy('visit_id');
        $nameMap = $this->names->findManyById(
            $actions->flatten(1)->flatMap(fn ($action): array => [$action->action_name_id, $action->page_title_id])->all(),
        );

        // 확장이 이 정보주체에 대해 자기 표에 둔 것도 같은 파일에 실린다. 열람권은 「이 사이트가
        // 나에 대해 아는 것」 전부이지 코어 원장만이 아니다. 확장은 `{식별자} => 내용` 으로 돌려준다.
        // 운영자가 고른 기간(없으면 null = 전 기간)도 넘긴다 — 방문 ID 로 묶이지 않는 확장 표도
        // 코어와 같은 범위만 내보내게 한다.
        $extensions = HookManager::applyFilters('core.analytics.subject_export', [], $resolved, $visitIds, $from, $to);

        HookManager::doAction('core.analytics.subject_exported', [
            'subject' => array_keys(array_filter($subject)),
            'visits' => count($visitIds),
        ]);

        return [
            'extensions' => is_array($extensions) ? $extensions : [],
            'exported_at' => AnalyticsClock::now()->toIso8601String(),
            'subject' => array_keys(array_filter($subject)),
            'notice' => $found['notice'],
            'visits' => $visits->map(fn ($visit): array => [
                'visit_id' => $visit->id,
                'visitor_key' => $visit->visitor_key,
                'visitor_type' => $this->enumValue($visit->visitor_type),
                // 회원 신원. 열람권 이행 파일에 「나에 대해 무엇을 아는가」로 실려야 하는 값이고,
                // 방문자 상세 화면의 회원 칸도 이 값으로 회원을 찾는다(#682 S3 라운드 5, §4.11).
                // 가명화·소급 익명화를 돌린 구간은 `user_id` 가 지워지고 해시만 남는다.
                'user_id' => $visit->user_id,
                'user_hash' => $visit->user_hash,
                'first_action_at' => $visit->first_action_at?->toIso8601String(),
                'last_action_at' => $visit->last_action_at?->toIso8601String(),
                'ip' => $visit->ip !== null ? (string) $visit->ip : null,
                // 이 방문에 대해 원장이 아는 환경·지역·언어 값 전부. 정보주체 열람 파일이 「나에 대해
                // 무엇을 아는가」를 빠짐없이 답해야 하고, 방문자 상세 화면도 이 값을 그린다(#682 S4
                // 라운드 8 「방문자 정보에는 해상도 등 부수정보가 모두 나와야 한다」).
                'source' => $this->enumValue($visit->source),
                'country_code' => $visit->country_code,
                'region_code' => $visit->region_code,
                'city' => $visit->city,
                'browser_name' => $visit->browser_name,
                'browser_version' => $visit->browser_version,
                'os_name' => $visit->os_name,
                'os_version' => $visit->os_version,
                'device_type' => $this->enumValue($visit->device_type),
                'screen_resolution' => $visit->screen_resolution,
                'browser_language' => $visit->browser_language,
                'locale' => $visit->locale,
                'is_returning' => $visit->is_returning,
                'is_bounce' => $visit->is_bounce,
                // 유입과 방문 규모는 정보주체가 「나에 대해 무엇을 아는가」를 볼 때 함께
                // 읽어야 하는 값이고, 방문자 상세 CSV(부록 D)가 그대로 내보낸다.
                'referrer_type' => $this->enumValue($visit->referrer_type),
                'referrer_name' => $visit->referrer_name,
                // 전체 유입 URL. 집계표는 호스트(referrer_name)까지만 쌓아 「어느 글에서 왔는지」를
                // 알 수 없는데, 원장에는 처음부터 이 값이 있었다. 익명화 설정이 켜진 구간은
                // 저장 시점에 이미 도메인까지만 남아 여기서도 도메인만 나온다(#682 라운드 4, 검수 지적 ⑥).
                'referrer_url' => $visit->referrer_url,
                'referrer_keyword' => $visit->referrer_keyword,
                'campaign_name' => $visit->campaign_name,
                'campaign_source' => $visit->campaign_source,
                'campaign_medium' => $visit->campaign_medium,
                'pageviews' => $visit->pageviews_count,
                'total_time_seconds' => $visit->total_time_seconds,
                // 확장이 넣은 차원 값도 이 방문에 대해 사이트가 아는 것이다.
                'custom_dimensions' => $visit->custom_dimensions,
                'actions' => $actions->get($visit->id, collect())->map(fn ($action): array => [
                    'occurred_at' => $action->occurred_at?->toIso8601String(),
                    'action_type' => $this->enumValue($action->action_type),
                    'name' => isset($nameMap[$action->action_name_id])
                        ? (string) $nameMap[$action->action_name_id]->name
                        : null,
                    // 검색 액션의 결과 건수. 검색어만 보이면 「빈손으로 돌아간 검색」을 이 화면에서
                    // 가려낼 수 없어 행동 화면의 「결과 없음」 열로 건너가야 한다(§4.10 방문자 상세
                    // 「검색어(+결과 수)」·§4.11 「검색 "그누보드" 결과 12건」). 원장에는 수집 시점부터
                    // 있던 값이고(`VisitService`), 검색이 아닌 액션에서는 null 이다.
                    'search_result_count' => $action->search_result_count,
                    'time_spent_seconds' => $action->time_spent_seconds,
                    'custom_dimensions' => $action->custom_dimensions,
                ])->all(),
                'conversions' => $conversions->get($visit->id, collect())->map(fn ($conversion): array => [
                    'occurred_at' => $conversion->occurred_at?->toIso8601String(),
                    'goal_key' => $conversion->goal_key,
                    'label' => $conversion->label,
                    'revenue' => $conversion->revenue,
                    'currency' => $conversion->currency,
                    'custom_dimensions' => $conversion->custom_dimensions,
                ])->all(),
            ])->all(),
        ];
    }

    /**
     * 정보주체의 원장을 지웁니다.
     *
     * @param  array<string, mixed>  $subject  찾을 기준
     * @param  AnalyticsConfig  $config  설정
     * @param  string|null  $from  시작일 (Y-m-d)
     * @param  string|null  $to  종료일 (Y-m-d)
     * @return array{visits: int, actions: int, conversions: int} 삭제 건수
     */
    public function delete(array $subject, AnalyticsConfig $config, ?string $from = null, ?string $to = null): array
    {
        $resolved = $this->resolveSubject($subject, $config);
        $visits = $this->visits->findBySubject($resolved, $from, $to);
        $visitIds = $visits->pluck('id')->all();

        // 코어 원장에 방문이 없어도 확장은 자기 표에 이 사람의 기록을 가질 수 있다 — 삭제권은
        // 코어 원장만이 아니라 사이트 전체에 대한 것이므로 확장 단계는 늘 돈다.
        //
        // 확장 삭제는 코어 삭제와 **같은 트랜잭션**에서 돈다. 확장이 던지면 코어 삭제도 함께
        // 되돌아가 「일부만 지워졌는데 완료로 보이는」 상태가 생기지 않는다. 확장은 필터
        // `core.analytics.subject_delete` 에서 자기 표를 지우고 `{식별자} => 지운 건수` 를 더한다.
        // 운영자가 고른 기간(없으면 null = 전 기간)도 넘긴다 — 기간을 모르는 확장은 그 주만
        // 지우라는 요청에 전 기간을 지운다(되돌릴 수 없다).
        $counts = DB::transaction(function () use ($resolved, $visitIds, $from, $to): array {
            $extensions = HookManager::applyFilters('core.analytics.subject_delete', [], $resolved, $visitIds, $from, $to);

            // 액션·전환을 먼저 지우고 방문을 지운다 — 연쇄 삭제에 기대지 않고 순서를
            // 코드가 소유한다. 집계는 건드리지 않는다.
            return [
                'actions' => $visitIds === [] ? 0 : $this->actions->deleteForVisits($visitIds),
                'conversions' => $visitIds === [] ? 0 : $this->conversions->deleteForVisits($visitIds),
                'visits' => $visitIds === [] ? 0 : $this->visits->deleteByIds($visitIds),
                'extensions' => self::extensionCounts($extensions),
            ];
        });

        if ($visitIds === [] && $counts['extensions'] === []) {
            return $counts;
        }

        HookManager::doAction('core.analytics.subject_deleted', [
            'subject' => array_keys(array_filter($subject)),
            'counts' => $counts,
        ]);

        return $counts;
    }

    /**
     * 확장이 돌려준 삭제 건수에서 형식이 맞는 것만 남깁니다.
     *
     * @param  mixed  $counts  필터 결과 (식별자 → 건수)
     * @return array<string, int> 식별자 → 건수
     */
    private static function extensionCounts(mixed $counts): array
    {
        $valid = [];

        foreach (is_array($counts) ? $counts : [] as $identifier => $count) {
            if (is_string($identifier) && $identifier !== '' && is_numeric($count)) {
                $valid[$identifier] = (int) $count;
            }
        }

        return $valid;
    }

    /**
     * 찾을 기준을 저장된 값과 같은 모양으로 바꿉니다.
     *
     * 주소는 저장할 때 마스킹을 거치므로, 찾을 때도 같은 마스킹을 입력에 적용해야
     * 맞습니다. 익명 모드 구간은 주소 대신 해시로 찾습니다.
     *
     * 회원 번호도 같습니다 — 가명화가 켜졌거나 소급 익명화를 돌린 구간은 `user_id`
     * 가 지워지고 `user_hash` 만 남으므로, 두 값을 함께 만들어 어느 쪽으로 저장된
     * 구간이든 찾습니다. 해시가 고정 키(HMAC)라 날짜에 상관없이 계산됩니다.
     *
     * @param  array<string, mixed>  $subject  찾을 기준
     * @param  AnalyticsConfig  $config  설정
     * @return array<string, mixed> 저장값과 맞춘 기준
     */
    private function resolveSubject(array $subject, AnalyticsConfig $config): array
    {
        $resolved = [
            'visitor_key' => $subject['visitor_key'] ?? null,
            'user_id' => $subject['user_id'] ?? null,
        ];

        $userId = $subject['user_id'] ?? null;

        if (is_numeric($userId) && (int) $userId > 0) {
            $resolved['user_hash'] = VisitorRecognizer::userHash((int) $userId);
        }

        $ip = $subject['ip'] ?? null;

        if (is_string($ip) && $ip !== '') {
            $resolved['ip'] = $config->ipMaskOctets > 0
                ? IpAddress::mask($ip, $config->ipMaskOctets)
                : $ip;
            $resolved['ip_hashes'] = $this->hashesForRecentSalts($ip);
        }

        return $resolved;
    }

    /**
     * 남아 있는 솔트로 주소 해시 후보를 만듭니다.
     *
     * @param  string  $ip  원본 주소
     * @return array<int, string> 해시 후보 목록
     */
    private function hashesForRecentSalts(string $ip): array
    {
        $hashes = [];
        $today = Carbon::parse(AnalyticsClock::today());

        for ($offset = 0; $offset < self::HASH_LOOKBACK_DAYS; $offset++) {
            $salt = $this->salts->findForDate($today->copy()->subDays($offset)->toDateString());

            if ($salt !== null) {
                $hashes[] = hash_hmac('sha256', $ip, $salt);
            }
        }

        return $hashes;
    }

    /**
     * 찾기의 한계를 알리는 문구 키를 돌려줍니다.
     *
     * @param  array<string, mixed>  $subject  찾을 기준
     * @return string|null 안내 문구 (한계가 없으면 null)
     */
    private function notice(array $subject): ?string
    {
        if (empty($subject['ip'])) {
            return null;
        }

        return __('analytics.privacy.ip_lookup_limited', ['days' => self::HASH_LOOKBACK_DAYS]);
    }

    /**
     * Enum 이거나 문자열인 값에서 문자열을 꺼냅니다.
     *
     * @param  mixed  $value  대상 값
     * @return string|null 문자열 값
     */
    private function enumValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }
}

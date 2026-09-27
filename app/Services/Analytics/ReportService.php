<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsClock;
use App\Analytics\GeoLabelTranslator;
use App\Analytics\UrlNormalizer;
use App\Contracts\Analytics\DimensionRegistryInterface;
use App\Contracts\Analytics\GoalRegistryInterface;
use App\Contracts\Repositories\Analytics\BotHitRepositoryInterface;
use App\Contracts\Repositories\Analytics\StatsRepositoryInterface;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;
use App\Enums\Analytics\StatsDimension;
use App\Enums\Analytics\VisitorType;

/**
 * 상위 목록 리포트
 *
 * 차원 하나를 골라 그 라벨들을 방문 수 순으로 보여 줍니다. 인기 페이지·유입처·
 * 브라우저·국가가 모두 이 한 갈래를 탑니다 — 차원 이름만 다릅니다.
 *
 * 저장된 라벨은 기계가 읽기 좋은 값입니다(국가 코드, 접두를 뗀 URL). 화면에 그대로
 * 내면 운영자가 못 읽으므로 보여 줄 문자열을 함께 만듭니다.
 */
class ReportService
{
    /**
     * 화면에 낼 수 있는 리포트 이름 목록
     *
     * 차원 이름 그대로인 것과, 차원에 별칭을 붙인 것이 함께 있습니다.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'entry_pages' => StatsDimension::EntryPage->value,
        'exit_pages' => StatsDimension::ExitPage->value,
        'bot_pages' => StatsDimension::BotPage->value,
        'goals' => StatsDimension::Goal->value,
    ];

    /**
     * 봇 화면이 함께 보여 주는 최근 요청 건수
     */
    private const RECENT_BOT_HITS = 50;

    /**
     * 라벨이 주소인 차원 — 화면에서 풀어 보여 주므로 검색도 인코딩된 저장 형태를 함께 찾는다.
     */
    private const URL_DIMENSIONS = [
        StatsDimension::PageUrl->value,
        StatsDimension::EntryPage->value,
        StatsDimension::ExitPage->value,
        StatsDimension::BotPage->value,
    ];

    /**
     * 리포트 조회기를 생성합니다.
     *
     * @param  StatsRepositoryInterface  $stats  집계 저장소
     * @param  BotHitRepositoryInterface  $botHits  봇 요청 원장 저장소
     * @param  VisitRepositoryInterface  $visits  방문 원장 저장소 — 집계에 없는 전체 유입 주소를 읽는다
     * @param  GoalRegistryInterface  $goals  전환(목표) 등록부 — 목표 키를 이름으로 푼다
     * @param  DimensionRegistryInterface  $extensionDimensions  확장 차원 등록부
     */
    public function __construct(
        private readonly StatsRepositoryInterface $stats,
        private readonly BotHitRepositoryInterface $botHits,
        private readonly VisitRepositoryInterface $visits,
        private readonly GoalRegistryInterface $goals,
        private readonly DimensionRegistryInterface $extensionDimensions,
    ) {}

    /**
     * 한 유입처의 전체 유입 주소를 조회합니다.
     *
     * 유입 사이트 목록은 호스트(`referrer_name`)만 쌓인 집계를 읽어 「google.com 에서 340」까지만
     * 말한다. 어느 검색 결과·어느 글에서 왔는지는 원장의 `referrer_url` 에만 있으므로 이 한 갈래만
     * 집계가 아니라 원장을 직접 읽는다. 익명화(`referrer_anonymization`)를 켠 구간은 저장 시점에
     * 이미 도메인까지만 남아 그 기간에는 호스트와 같은 한 줄만 나온다(#682 라운드 4, 검수 지적 ⑥).
     *
     * @param  string  $host  유입처 호스트
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  array<string, mixed>  $options  page·per_page·visitor_types
     * @return array{rows: array<int, array{url: string, visits: int, hits: int}>, page: int, per_page: int, has_more: bool} 주소 목록과 쪽 정보
     */
    public function referrerUrls(string $host, string $from, string $to, array $options = []): array
    {
        return $this->visits->referrerUrls($host, $from, $to, $options);
    }

    /**
     * 최근 봇 요청을 조회합니다.
     *
     * 집계는 「어느 봇이 얼마나」까지만 답한다. 어떤 주소를 언제 긁어 갔는지는
     * 원장에만 있어, 봇 화면이 집계 표 아래에 이 목록을 함께 깐다.
     *
     * @return array<int, array<string, mixed>> 최근 봇 요청 목록
     */
    public function recentBotHits(): array
    {
        return $this->botHits->recent(self::RECENT_BOT_HITS)
            ->map(fn ($hit): array => [
                'bot_name' => $hit->bot_name,
                'url' => $hit->url,
                'ip' => $hit->ip !== null ? (string) $hit->ip : null,
                'cache_status' => $hit->cache_status instanceof \BackedEnum
                    ? (string) $hit->cache_status->value
                    : $hit->cache_status,
                'occurred_at' => AnalyticsClock::toSiteDateTimeString($hit->occurred_at),
            ])
            ->all();
    }

    /**
     * 리포트 이름이 유효한지 확인합니다.
     *
     * 확장 차원(`ext:{식별자}:{키}`)은 지금 등록된 것과, 확장을 끄거나 데이터를 보존하고 지운 뒤
     * **집계 행이 남아 있는 것**을 받습니다 — 쌓인 수치는 확장이 사라져도 원값 라벨로 읽혀야 합니다.
     * 등록도 집계도 없는 임의 이름은 받지 않습니다.
     *
     * @param  string  $report  리포트 이름
     * @return bool 유효하면 true
     */
    public function isValidReport(string $report): bool
    {
        return isset(self::ALIASES[$report])
            || StatsDimension::isValid($report)
            || $this->extensionDimensions->findByStatsDimension($report) !== null
            || (str_starts_with($report, 'ext:') && in_array($report, $this->stats->extensionDimensionNames(), true));
    }

    /**
     * 등록된 확장 차원·목표 목록을 반환합니다.
     *
     * 행동 화면이 차원·전환 탭의 선택지로 씁니다. 원장 이름 대신 선언한 이름을 보여 주려면
     * 등록부를 거쳐야 합니다.
     *
     * @return array{dimensions: array<int, array<string, mixed>>, goals: array<int, array<string, mixed>>} 등록 목록
     */
    public function registered(): array
    {
        $dimensions = array_values(array_map(fn ($definition) => $definition->toArray(), $this->extensionDimensions->all()));
        $active = array_column($dimensions, 'dimension');

        // 꺼졌거나 데이터를 보존하고 지운 확장의 차원도 집계가 남아 있으면 고를 수 있어야 한다 —
        // 선언이 없으니 이름은 원값으로, 꺼진 확장이라는 표시를 붙여 보낸다.
        foreach ($this->stats->extensionDimensionNames() as $name) {
            if (in_array($name, $active, true)) {
                continue;
            }

            $key = substr($name, strlen('ext:'));
            $separator = strpos($key, ':');

            $dimensions[] = [
                'key' => $key,
                'dimension' => $name,
                'owner_type' => null,
                'owner_identifier' => $separator === false ? $key : substr($key, 0, $separator),
                'scope' => null,
                'label' => __('analytics.report.inactive_dimension', ['key' => $separator === false ? $key : substr($key, $separator + 1)]),
                'value_type' => null,
                'values' => [],
                'max_cardinality' => null,
                'pii' => false,
                'inactive' => true,
            ];
        }

        return [
            'dimensions' => $dimensions,
            'goals' => array_values(array_map(fn ($definition) => $definition->toArray(), $this->goals->all())),
        ];
    }

    /**
     * 리포트 이름을 차원 이름으로 바꿉니다.
     *
     * @param  string  $report  리포트 이름
     * @return string 차원 이름
     */
    public function dimensionFor(string $report): string
    {
        return self::ALIASES[$report] ?? $report;
    }

    /**
     * 리포트의 기간 합계(요청 수·라벨 수)를 만듭니다.
     *
     * 목록은 쪽으로 잘려 오므로, 화면이 그 행을 더해 KPI 를 그리면 라벨이 한 쪽보다 많은
     * 기간에는 첫 쪽의 합만 보이고 쪽을 넘기면 KPI 가 그 쪽의 합으로 바뀐다(#682 S3 라운드 14).
     *
     * @param  string  $report  리포트 이름
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  array<string, mixed>  $options  조회 옵션 (visitor_types)
     * @return array{hits: int, labels: int} 기간 합계
     */
    public function totals(string $report, string $from, string $to, array $options = []): array
    {
        $dimension = $this->dimensionFor($report);

        return $this->stats->dimensionTotals(
            $dimension,
            $from,
            $to,
            $options['visitor_types'] ?? $this->defaultTypes($dimension),
        );
    }

    /**
     * 상위 목록을 만듭니다.
     *
     * @param  string  $report  리포트 이름
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  array<string, mixed>  $options  조회 옵션 (visitor_types, page, per_page, sort, search, locale)
     * @return array{rows: array<int, array<string, mixed>>, page: int, per_page: int, has_more: bool} 상위 목록
     */
    public function report(string $report, string $from, string $to, array $options = []): array
    {
        $dimension = $this->dimensionFor($report);
        $types = $options['visitor_types'] ?? $this->defaultTypes($dimension);
        $page = max(1, (int) ($options['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($options['per_page'] ?? 25)));

        // 다음 쪽이 있는지 알려면 한 건 더 읽어 보는 수밖에 없다 — 합산 질의에
        // COUNT 를 한 번 더 거는 것보다 싸다.
        $rows = $this->stats->dimension($dimension, $from, $to, [
            'visitor_types' => $types,
            'limit' => $perPage + 1,
            'offset' => ($page - 1) * $perPage,
            'sort' => $this->sortColumn($options['sort'] ?? null),
            'search' => $this->searchTerms($options['search'] ?? null, $dimension),
        ]);

        $hasMore = $rows->count() > $perPage;
        $rows = $rows->take($perPage);

        // 차원에 따라 라벨마다 부가 값이 붙는다(내부 검색어의 「결과 없음」 건수 등).
        // 집계 행에 있는 값을 응답에서 버리면 화면이 그 열을 채울 길이 없다.
        $extra = $this->stats->dimensionExtra(
            $dimension,
            $from,
            $to,
            $rows->pluck('label_hash')->map(fn ($hash): string => (string) $hash)->all(),
            $types,
        );

        // 확장 차원의 원값(게시판 번호 등)은 그 확장이 선언한 풀이 함수로 이름을 얻는다.
        // 확장이 비활성이면 등록부에 없어 원값 그대로 보인다.
        $options['extension_labels'] = $this->extensionDimensions
            ->findByStatsDimension($dimension)
            ?->resolveLabels($rows->pluck('label')->map(fn ($label): string => (string) $label)->all()) ?? [];

        return [
            'rows' => $rows->map(fn ($row): array => $this->presentRow($row, $dimension, $options, $extra))->all(),
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $hasMore,
        ];
    }

    /**
     * 합산 행 하나를 화면이 읽을 모양으로 바꿉니다.
     *
     * @param  object  $row  합산 행
     * @param  string  $dimension  차원 이름
     * @param  array<string, mixed>  $options  조회 옵션
     * @param  array<string, array<string, int>>  $extra  라벨 묶음 키별 부가 값
     * @return array<string, mixed> 화면용 행
     */
    private function presentRow(object $row, string $dimension, array $options, array $extra = []): array
    {
        $visits = (int) $row->visits;
        $label = (string) $row->label;

        return [
            'label' => $label,
            'label_display' => $this->displayLabel($label, $dimension, $options),
            'visits' => $visits,
            'hits' => (int) $row->hits,
            'entries' => (int) $row->entries,
            'exits' => (int) $row->exits,
            'bounce_rate' => $visits > 0 ? round((int) $row->bounces / $visits * 100, 1) : 0.0,
            'avg_time' => $visits > 0 ? (int) round((int) $row->total_time_seconds / $visits) : 0,
            'pages_per_visit' => $visits > 0 ? round((int) $row->hits / $visits, 2) : 0.0,
            // 부가 값이 없는 차원이 대부분이다 — 그 자리는 빈 객체로 두어 화면이
            // 언제나 같은 모양을 읽게 한다.
            'extra' => $extra[(string) $row->label_hash] ?? [],
        ];
    }

    /**
     * 저장된 라벨을 사람이 읽을 문자열로 바꿉니다.
     *
     * @param  string  $label  저장된 라벨
     * @param  string  $dimension  차원 이름
     * @param  array<string, mixed>  $options  조회 옵션
     * @return string 화면용 문자열
     */
    private function displayLabel(string $label, string $dimension, array $options): string
    {
        $locale = (string) ($options['locale'] ?? app()->getLocale());

        return match ($dimension) {
            StatsDimension::Country->value => GeoLabelTranslator::country($label, $locale),
            StatsDimension::Region->value => GeoLabelTranslator::region($label, $locale),
            StatsDimension::City->value => GeoLabelTranslator::city($label, $locale),
            StatsDimension::PageUrl->value,
            StatsDimension::EntryPage->value,
            StatsDimension::ExitPage->value,
            StatsDimension::BotPage->value => UrlNormalizer::forDisplay($label),
            // 목표 키(`core.user_registered`)는 선언한 이름으로 — 확장이 사라졌으면 키 그대로.
            StatsDimension::Goal->value => $this->goals->find($label)?->localizedLabel($locale) ?? $label,
            default => $options['extension_labels'][$label] ?? $label,
        };
    }

    /**
     * 검색어를 저장 라벨과 맞춰 볼 형태들로 바꿉니다.
     *
     * 주소 차원의 라벨은 브라우저가 인코딩한 채 저장되는데 화면은 풀어서 보여 줍니다
     * (`UrlNormalizer::forDisplay`). 화면에 보이는 「테스트」로 찾으면 저장값 「%ED%85%8C…」와 맞지
     * 않으므로, 공백·비ASCII 글자를 브라우저와 같게 인코딩한 형태도 함께 찾습니다.
     *
     * @param  string|null  $search  입력한 검색어
     * @param  string  $dimension  차원 이름
     * @return array<int, string> 찾을 형태 목록 (비었으면 검색 안 함)
     */
    private function searchTerms(?string $search, string $dimension): array
    {
        if ($search === null || trim($search) === '') {
            return [];
        }

        $terms = [$search];

        if (in_array($dimension, self::URL_DIMENSIONS, true)) {
            $encoded = preg_replace_callback('/[^\x21-\x7E]/u', fn (array $m): string => rawurlencode($m[0]), $search);

            if (is_string($encoded) && $encoded !== $search) {
                $terms[] = $encoded;
            }
        }

        return $terms;
    }

    /**
     * 정렬 기준 이름을 집계 열로 바꿉니다.
     *
     * @param  string|null  $sort  요청한 정렬 기준
     * @return string 집계 열 이름
     */
    private function sortColumn(?string $sort): string
    {
        return in_array($sort, ['visits', 'hits', 'entries', 'exits'], true) ? $sort : 'visits';
    }

    /**
     * 차원에 맞는 기본 방문자 유형을 돌려줍니다.
     *
     * 봇 차원은 봇 행에만 있으므로 사람 유형으로 물으면 언제나 비어 있습니다.
     *
     * @param  string  $dimension  차원 이름
     * @return array<int, string> 유형 목록
     */
    private function defaultTypes(string $dimension): array
    {
        if (in_array($dimension, [StatsDimension::BotName->value, StatsDimension::BotPage->value], true)) {
            return [VisitorType::Bot->value];
        }

        return [VisitorType::Guest->value, VisitorType::Member->value, VisitorType::Admin->value];
    }
}

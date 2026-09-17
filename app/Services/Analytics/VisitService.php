<?php

namespace App\Services\Analytics;

use App\Analytics\ActionNameHasher;
use App\Analytics\AnalyticsClock;
use App\Analytics\AnalyticsConfig;
use App\Analytics\ExclusionCounter;
use App\Analytics\GeoLocator;
use App\Analytics\NotificationCooldown;
use App\Analytics\TrackingRequest;
use App\Analytics\UrlNormalizer;
use App\Analytics\VisitExclusionPipeline;
use App\Analytics\VisitorRecognizer;
use App\Contracts\Repositories\Analytics\ActionNameRepositoryInterface;
use App\Contracts\Repositories\Analytics\ActionRepositoryInterface;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;
use App\Enums\Analytics\ActionType;
use App\Enums\Analytics\CollectFailureReason;
use App\Enums\Analytics\VisitSource;
use App\Extension\HookManager;
use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 방문 적재
 *
 * 수집 요청 하나를 받아 방문과 액션을 남깁니다. 세 경로(비콘·서버 폴백·내부 검색)가
 * 모두 이 서비스로 모이므로, 방문을 묶는 규칙이 한곳에만 있습니다.
 *
 * 이 서비스는 **예외를 밖으로 던지지 않습니다**. 수집이 실패했다고 방문자가 보던
 * 화면이 깨지거나 큐 잡이 재시도로 쌓이면 안 되기 때문입니다. 실패는 전용 로그로
 * 남고, 응답은 늘 정상입니다.
 */
class VisitService
{
    /**
     * 서버 폴백과 첫 비콘을 한 페이지뷰·한 방문으로 묶는 시간 창 (초)
     *
     * 브라우저가 HTML 을 받고 스크립트를 띄워 첫 비콘을 보내기까지의 간격을 덮는다.
     */
    public const MERGE_WINDOW_SECONDS = 60;

    /**
     * 방문 적재기를 생성합니다.
     *
     * @param  VisitRepositoryInterface  $visits  방문 원장 저장소
     * @param  ActionRepositoryInterface  $actions  액션 원장 저장소
     * @param  ActionNameRepositoryInterface  $names  이름 사전 저장소
     * @param  VisitExclusionPipeline  $exclusions  제외 판정기
     * @param  VisitorRecognizer  $recognizer  방문자 식별기
     * @param  VisitEnricher  $enricher  방문 속성 조립기
     * @param  PrivacyService  $privacy  개인정보 처리기
     * @param  CollectFailureLogger  $failures  실패 기록기
     * @param  ExclusionCounter  $counter  제외 사유 카운터
     * @param  NotificationCooldown  $cooldown  알림 쿨다운
     */
    public function __construct(
        private readonly VisitRepositoryInterface $visits,
        private readonly ActionRepositoryInterface $actions,
        private readonly ActionNameRepositoryInterface $names,
        private readonly VisitExclusionPipeline $exclusions,
        private readonly VisitorRecognizer $recognizer,
        private readonly VisitEnricher $enricher,
        private readonly PrivacyService $privacy,
        private readonly CollectFailureLogger $failures,
        private readonly ExclusionCounter $counter,
        private readonly NotificationCooldown $cooldown,
    ) {}

    /**
     * 방문자 식별값만 정합니다 (적재하지 않습니다).
     *
     * 큐 경로에서 씁니다. 쿠키는 응답 헤더로만 나갈 수 있으므로 적재를 워커에게
     * 넘기더라도 식별값과 쿠키는 이 요청 안에서 정해야 합니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @param  AnalyticsConfig  $config  설정
     * @return array{key: string, key_type: string, new_cookie: string|null, is_returning: bool|null}
     *                                                                                                방문자 식별 결과
     */
    public function identify(TrackingRequest $request, AnalyticsConfig $config): array
    {
        return $this->recognizer->resolve($request, $config, $this->privacy->isAnonymous($config));
    }

    /**
     * 수집 요청을 적재합니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @return array{visit_id: int|null, action_id: int|null, excluded: string|null, new_cookie: string|null}
     *                                                                                                        적재 결과 (제외됐으면 사유, 쿠키를 심어야 하면 그 값)
     */
    public function track(TrackingRequest $request): array
    {
        $result = ['visit_id' => null, 'action_id' => null, 'excluded' => null, 'new_cookie' => null];
        $config = null;

        try {
            $config = AnalyticsConfig::fromSettings();
            $anonymous = $this->privacy->isAnonymous($config);
            $consent = $this->privacy->hasAnalyticsConsent($request, $config);

            // 국가 필터는 지역 판정이 있어야 하므로 방문 조립 뒤에 다시 보지 않고
            // 여기서는 언어 추정만으로 1차 판정한다 — GeoIP 가 켜져 있으면 조립 단계의
            // 값이 더 정확하지만, 그 전에 거를 수 있는 요청을 DB 까지 끌고 가지 않는다.
            $countryHint = GeoLocator::countryFromLanguage($request->browserLanguage);

            $excluded = $this->exclusions->evaluate($request, $config, $countryHint, $consent);

            if ($excluded !== null) {
                $this->counter->increment($excluded);
                $result['excluded'] = $excluded->value;

                return $result;
            }

            $identity = $this->recognizer->resolve($request, $config, $anonymous);
            $result['new_cookie'] = $identity['new_cookie'];

            $stored = DB::transaction(fn (): array => $this->store($request, $config, $identity, $anonymous));

            $result['visit_id'] = $stored['visit_id'];
            $result['action_id'] = $stored['action_id'];

            if ($stored['visit_id'] !== null) {
                // 확장이 적재 결과를 관찰할 수 있게 알린다. 실패해도 적재는 이미 끝났다.
                HookManager::doAction('core.analytics.after_track', [
                    'visit_id' => $stored['visit_id'],
                    'action_id' => $stored['action_id'],
                    'action_type' => $request->type,
                    'dto' => $request->toArray(),
                ]);
            }
        } catch (\Throwable $e) {
            // 전용 실패 로그는 진단 화면이 읽고, 애플리케이션 로그는 로그 수집기가 읽는다.
            // 둘 중 하나만 남기면 다른 쪽을 보는 운영자는 아무 신호도 받지 못한다.
            Log::warning('방문자 통계 적재 실패', [
                'type' => $request->type,
                'source' => $request->source,
                'error' => $e->getMessage(),
            ]);

            // 설정 로드 자체가 실패한 경우를 위해 다시 읽지 않는다 — 여기서 또 던지면
            // 「예외를 밖으로 던지지 않는다」는 계약이 깨진다.
            $this->failures->log(
                CollectFailureReason::DbError,
                $e->getMessage(),
                $request,
                $config?->ipMaskOctets ?? 0,
            );
        }

        return $result;
    }

    /**
     * 트랜잭션 안에서 방문과 액션을 적재합니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @param  AnalyticsConfig  $config  설정
     * @param  array{key: string, key_type: string, new_cookie: string|null, is_returning: bool|null}  $identity  방문자 식별 결과
     * @param  bool  $anonymous  익명 모드 여부
     * @return array{visit_id: int|null, action_id: int|null} 적재 결과
     */
    private function store(TrackingRequest $request, AnalyticsConfig $config, array $identity, bool $anonymous): array
    {
        $visit = null;

        // 쿠키를 방금 받은 첫 비콘이면, 응답 뒤에 돌아 쿠키를 못 심은 서버 폴백이 임시 키로
        // 만들어 둔 방문을 먼저 찾아 이어받는다. 그러지 않으면 한 사람의 첫 로드가 방문
        // 2건(하나는 이탈 100%)이 되어 순방문자와 이탈률이 구조적으로 부푼다.
        if ($this->isFirstBeaconWithFreshCookie($request, $identity)) {
            $visit = $this->visits->adoptServerVisit(
                $this->recognizer->configId($request),
                $identity['key'],
                AnalyticsClock::now()->subSeconds(self::MERGE_WINDOW_SECONDS),
            );

            if ($visit !== null) {
                $this->actions->renameVisitorKey($visit->id, $identity['key']);
            }
        }

        $visit ??= $this->visits->findOpenVisitForUpdate(
            $identity['key'],
            AnalyticsClock::visitWindowStart($config->visitTimeoutMinutes),
        );

        if ($visit === null) {
            $visit = $this->visits->create($this->enricher->build($request, $config, $identity, $anonymous));
        }

        // 한 방문이 끝없이 늘어나면 조회가 느려지고 저장소를 잠식한다. 상한을 넘으면
        // 이후 액션을 버리되, 왜 버렸는지는 남겨 운영자가 알 수 있게 한다.
        if ($visit->actions_count >= $config->maxActionsPerVisit) {
            $this->failures->log(
                CollectFailureReason::MaxActions,
                '방문당 액션 상한('.$config->maxActionsPerVisit.'건)을 넘었습니다.',
                $request,
                $config->ipMaskOctets,
            );

            // 실패 로그는 넘칠 때마다 남기고 알림만 하루 한 번 보낸다 — 이 판정은
            // 수집 요청마다 돌아, 상한을 넘긴 방문 하나가 계속 비콘을 보내면 같은
            // 알림이 관리자 알림함을 가득 채운다.
            if ($this->cooldown->shouldNotify('visit_limit_exceeded')) {
                HookManager::doAction('core.analytics.visit_limit_exceeded', [
                    'visitor_key' => substr($visit->visitor_key, 0, 8),
                    'actions' => $visit->actions_count,
                    'limit' => $config->maxActionsPerVisit,
                    'ip_display' => $visit->ip ?? '-',
                ]);
            }

            return ['visit_id' => $visit->id, 'action_id' => null];
        }

        // 직전 페이지뷰는 새 액션을 넣기 **전에** 찾아 둔다. 넣은 뒤에 찾으면 방금
        // 넣은 행이 잡혀 직전 페이지의 체류 시간이 영영 확정되지 않는다.
        $previousPageview = $request->prevTimeSpent !== null && $request->prevTimeSpent > 0
            ? $this->actions->findLatestForVisit($visit->id, ActionType::Pageview->value)
            : null;

        $recorded = $this->recordAction($request, $visit);
        $this->updateVisitCounters($request, $visit, $recorded['action'], $previousPageview, $recorded['merged']);

        return ['visit_id' => $visit->id, 'action_id' => $recorded['action']?->id];
    }

    /**
     * 쿠키를 방금 발급받은 방문의 첫 비콘인지 판정합니다.
     *
     * 즉시 적재 경로는 `new_cookie` 가, 큐 경로는 컨트롤러가 정한 키와 「쿠키 없었음」
     * 판정이 그 신호다. 두 경로 모두 `is_returning === false` 로 모인다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @param  array{key: string, key_type: string, new_cookie: string|null, is_returning: bool|null}  $identity  방문자 식별 결과
     * @return bool 첫 비콘이면 true
     */
    private function isFirstBeaconWithFreshCookie(TrackingRequest $request, array $identity): bool
    {
        return $request->source === VisitSource::Beacon->value
            && $request->init
            && $identity['key_type'] === 'cookie'
            && $identity['is_returning'] === false;
    }

    /**
     * 액션을 남기거나, 서버가 방금 남긴 같은 페이지 행을 채웁니다.
     *
     * 서버 폴백이 먼저 페이지뷰를 적고 곧이어 브라우저 비콘이 같은 페이지를 알리면
     * 새 행을 만들 경우 페이지뷰가 두 배가 됩니다. 첫 비콘은 방금 서버가 적은 행을
     * 찾아 그 행을 채웁니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @param  AnalyticsVisit  $visit  대상 방문
     * @return array{action: AnalyticsAction|null, merged: bool}
     *                                                           적재된 액션과, 기존 행을 채운 것인지 여부
     */
    private function recordAction(TrackingRequest $request, AnalyticsVisit $visit): array
    {
        $normalized = UrlNormalizer::normalize($request->url);
        $nameType = $this->nameTypeFor($request);
        $nameValue = $this->nameValueFor($request, $normalized['name']);

        $actionName = $this->names->firstOrCreateByHash(
            $nameType,
            $nameValue,
            $nameType === 'url' ? $normalized['url_prefix'] : null,
        );

        $titleId = null;
        if ($request->title !== null && $request->title !== '') {
            $titleId = $this->names->firstOrCreateByHash('title', $request->title)->id;
        }

        $now = AnalyticsClock::now();
        $isPageview = $request->type === ActionType::Pageview->value;

        if ($isPageview && $request->init && $request->source === VisitSource::Beacon->value) {
            $merged = $this->actions->findRecentServerPageview(
                $visit->id,
                $actionName->id,
                $now->copy()->subSeconds(self::MERGE_WINDOW_SECONDS),
            );

            if ($merged !== null) {
                $this->actions->update($merged, array_filter([
                    'page_title_id' => $titleId,
                ], fn ($value) => $value !== null));

                // 같은 페이지뷰를 서버가 이미 세었다 — 카운터를 다시 올리면 2배가 된다.
                return ['action' => $merged, 'merged' => true];
            }
        }

        $action = $this->actions->create([
            'visit_id' => $visit->id,
            'visitor_key' => $visit->visitor_key,
            'action_type' => $request->type,
            'action_name_id' => $actionName->id,
            'page_title_id' => $titleId,
            'occurred_at' => $now,
            // 마지막 액션의 체류 시간은 알 수 없다 — 다음 액션이 도착할 때 확정한다.
            'time_spent_seconds' => null,
            'search_result_count' => $request->searchTotal,
            'search_tab' => $request->searchTab,
            'custom_dimensions' => $request->dimensions === [] ? null : $request->dimensions,
            'created_at' => $now,
        ]);

        return ['action' => $action, 'merged' => false];
    }

    /**
     * 방문의 집계 컬럼을 갱신합니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @param  AnalyticsVisit  $visit  대상 방문
     * @param  object|null  $action  적재된 액션
     * @param  object|null  $previousPageview  이 액션 직전의 페이지뷰 (체류 시간 확정 대상)
     * @param  bool  $merged  서버가 이미 세어 둔 행을 채운 것인지 여부
     * @return void
     */
    private function updateVisitCounters(
        TrackingRequest $request,
        AnalyticsVisit $visit,
        ?object $action,
        ?object $previousPageview,
        bool $merged = false,
    ): void {
        if ($action === null) {
            return;
        }

        $isPageview = $request->type === ActionType::Pageview->value;

        // 병합이면 이 페이지뷰는 서버 폴백이 이미 세었다. 다시 올리면 2배가 된다.
        $pageviews = $visit->pageviews_count + ($isPageview && ! $merged ? 1 : 0);

        $attributes = [
            'actions_count' => $visit->actions_count + ($merged ? 0 : 1),
            'pageviews_count' => $pageviews,
            'last_action_at' => AnalyticsClock::now(),
            'exit_action_name_id' => $action->action_name_id,
            // 페이지를 하나만 보고 떠나면 이탈이다. 두 번째 페이지가 오는 순간 풀린다.
            'is_bounce' => $pageviews <= 1,
        ];

        if ($visit->entry_action_name_id === null) {
            $attributes['entry_action_name_id'] = $action->action_name_id;
        }

        // 브라우저가 알려 준 직전 페이지 체류 시간으로 그 액션을 확정한다.
        // 병합(D14)으로 같은 행이 다시 쓰인 경우에는 확정할 「직전」이 없다.
        if ($previousPageview !== null
            && $previousPageview->id !== $action->id
            && $previousPageview->time_spent_seconds === null
            && $request->prevTimeSpent !== null
            && $request->prevTimeSpent > 0
        ) {
            $this->actions->update($previousPageview, ['time_spent_seconds' => $request->prevTimeSpent]);
            $attributes['total_time_seconds'] = $visit->total_time_seconds + $request->prevTimeSpent;
        }

        $this->visits->update($visit, $attributes);
    }

    /**
     * 액션 종류에 맞는 사전 종류를 정합니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @return string 사전 종류
     */
    private function nameTypeFor(TrackingRequest $request): string
    {
        return match ($request->type) {
            ActionType::Download->value => 'download',
            ActionType::Outlink->value => 'outlink',
            ActionType::SiteSearch->value => 'search',
            default => 'url',
        };
    }

    /**
     * 사전에 넣을 이름을 정합니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @param  string  $normalizedUrl  정규화된 방문 URL
     * @return string 사전 이름
     */
    private function nameValueFor(TrackingRequest $request, string $normalizedUrl): string
    {
        $value = match ($request->type) {
            ActionType::Download->value, ActionType::Outlink->value => $request->target ?? $normalizedUrl,
            ActionType::SiteSearch->value => $request->searchQuery ?? '',
            default => $normalizedUrl,
        };

        return mb_substr($value, 0, 1024);
    }

    /**
     * 사전 해시를 만듭니다.
     *
     * @param  string  $type  사전 종류
     * @param  string  $name  사전 이름
     * @return string 해시
     */
    public static function nameHash(string $type, string $name): string
    {
        return ActionNameHasher::hash($type, $name);
    }
}

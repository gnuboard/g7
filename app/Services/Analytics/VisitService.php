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
use App\Contracts\Repositories\Analytics\ConversionRepositoryInterface;
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
     * 전환 금액의 상한
     *
     * `analytics_conversions.revenue` 가 `decimal(15,2)` 라 정수부는 13자리까지다.
     * 넘는 값을 그대로 넘기면 적재가 통째로 실패해 전환도 액션도 남지 않는다.
     */
    private const MAX_REVENUE = 9999999999999.99;

    /**
     * 방문 적재기를 생성합니다.
     *
     * @param  VisitRepositoryInterface  $visits  방문 원장 저장소
     * @param  ActionRepositoryInterface  $actions  액션 원장 저장소
     * @param  ActionNameRepositoryInterface  $names  이름 사전 저장소
     * @param  ConversionRepositoryInterface  $conversions  전환 원장 저장소
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
        private readonly ConversionRepositoryInterface $conversions,
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

        // 서버 폴백이 먼저 연 방문에 첫 비콘이 합류할 때는 회원 여부를 비콘 쪽이 정한다
        // (아래 보강). 그 밖에 방문 도중 로그인·로그아웃이 일어났다면 그때부터는 다른
        // 사람의 방문이므로 이어 붙이지 않고 새 방문을 연다(§8 M1-T5).
        $otherIdentityVisit = null;
        if ($visit !== null
            && ! $this->isFirstBeaconJoiningServerVisit($request, $visit)
            && ! $this->enricher->identityMatches($request, $config, $visit)
        ) {
            $otherIdentityVisit = $visit;
            $visit = null;
        }

        if ($visit === null) {
            $visit = $this->visits->create($this->enricher->build($request, $config, $identity, $anonymous));
        }

        // 페이지 요청과 첫 비콘의 신원이 어긋난 전체 로드(웹 세션과 토큰의 수명이 달라 한쪽만
        // 로그인 상태인 구간)에서는 서버가 같은 페이지를 다른 신원의 방문에 이미 적어 두었다.
        // 그 자리표시를 거두지 않으면 한 번 연 페이지가 두 방문에 한 번씩 세어진다 — 화면에
        // 보이는 신원은 비콘 쪽이므로 비콘 쪽 방문에만 남긴다.
        if ($otherIdentityVisit !== null) {
            $this->releaseServerPlaceholder($request, $otherIdentityVisit);
        }

        // 서버 폴백이 먼저 연 방문에 첫 비콘이 합류하면, 서버가 알 수 없던 값(해상도·보던
        // 로케일·빠진 유입)을 채우고 출처를 비콘으로 바꾼다(D14). 그러지 않으면 전체 로드로
        // 시작한 거의 모든 방문이 해상도 없이 「서버로만 잡힌 방문」으로 남는다.
        if ($this->isFirstBeaconJoiningServerVisit($request, $visit)) {
            $this->visits->update($visit, $this->enricher->enrichFromFirstBeacon($request, $config, $visit));
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
        $this->recordConversion($request, $visit);

        return ['visit_id' => $visit->id, 'action_id' => $recorded['action']?->id];
    }

    /**
     * 다른 신원의 방문에 서버가 남긴 자리표시 페이지뷰를 거둡니다.
     *
     * 첫 비콘(`init`)이 신원이 달라 열린 방문을 이어받지 못했을 때만 부릅니다. 그 방문의 가장
     * 최근 액션이 이 비콘과 같은 URL 의 페이지뷰이고, 제목이 없으며(서버는 화면 제목을 모른다 —
     * 비콘 기록은 늘 제목을 싣는다), 병합 창(60초) 안에 적혔다면 이 로드의 서버 폴백이 남긴
     * 자리표시다. 지우고 그 방문의 카운터·이탈 페이지를 남은 기록 기준으로 되돌린다.
     * 조건이 하나라도 어긋나면 그 신원이 실제로 본 기록이므로 건드리지 않는다.
     *
     * @param  TrackingRequest  $request  첫 비콘 요청
     * @param  AnalyticsVisit  $otherVisit  신원이 달라 이어받지 않은 열린 방문
     * @return void 없음
     */
    private function releaseServerPlaceholder(TrackingRequest $request, AnalyticsVisit $otherVisit): void
    {
        if ($request->type !== ActionType::Pageview->value
            || ! $request->init
            || $request->source !== VisitSource::Beacon->value
        ) {
            return;
        }

        $latest = $this->actions->findLatestForVisit($otherVisit->id);
        if ($latest === null) {
            return;
        }

        $normalized = UrlNormalizer::normalize($request->url);
        $nameId = $this->names->firstOrCreateByHash(
            $this->nameTypeFor($request),
            $this->nameValueFor($request, $normalized['name']),
            $normalized['url_prefix'],
        )->id;

        $latestType = $latest->action_type instanceof ActionType ? $latest->action_type->value : (string) $latest->action_type;
        $isPlaceholder = $latestType === ActionType::Pageview->value
            && (int) $latest->action_name_id === (int) $nameId
            && $latest->page_title_id === null
            && $latest->occurred_at !== null
            && $latest->occurred_at->greaterThanOrEqualTo(AnalyticsClock::now()->subSeconds(self::MERGE_WINDOW_SECONDS));

        if (! $isPlaceholder) {
            return;
        }

        $this->actions->delete($latest);

        $actions = max(0, (int) $otherVisit->actions_count - 1);
        if ($actions === 0) {
            // 자리표시만 담긴 방문 — 남길 기록이 없다.
            $this->visits->deleteByIds([$otherVisit->id]);

            return;
        }

        $pageviews = max(0, (int) $otherVisit->pageviews_count - 1);
        $remaining = $this->actions->findLatestForVisit($otherVisit->id);
        // 이탈 페이지는 남은 페이지뷰 중 마지막이다 — 마지막 액션이 내려받기면 그 파일 이름이 된다.
        $remainingPage = $this->actions->findLatestForVisit($otherVisit->id, ActionType::Pageview->value);

        $this->visits->update($otherVisit, [
            'actions_count' => $actions,
            'pageviews_count' => $pageviews,
            'is_bounce' => $pageviews <= 1,
            'exit_action_name_id' => $remainingPage?->action_name_id,
            'last_action_at' => $remaining?->occurred_at ?? $otherVisit->first_action_at,
        ]);
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
     * 서버 폴백이 연 방문에 합류하는 첫 비콘인지 판정합니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @param  AnalyticsVisit  $visit  대상 방문
     * @return bool 서버 출처 방문에 합류하는 첫 비콘이면 true
     */
    private function isFirstBeaconJoiningServerVisit(TrackingRequest $request, AnalyticsVisit $visit): bool
    {
        $visitSource = $visit->source instanceof VisitSource ? $visit->source->value : (string) $visit->source;

        return $request->source === VisitSource::Beacon->value
            && $request->init
            && $visitSource === VisitSource::Server->value;
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
     * 목표 달성(전환)을 원장에 남깁니다.
     *
     * 액션 1행만으로는 「무엇을 얼마에 달성했는지」가 남지 않습니다. 전환은 금액·통화·
     * 라벨을 따로 들고 확장별로 집계되어야 하므로 전용 원장에 한 행을 더 씁니다.
     * 액션과 같은 트랜잭션 안이므로 둘 중 하나만 남는 일이 없습니다.
     *
     * 목표 키는 `{확장식별자}.{목표}` 또는 `core.{목표}` 형태입니다. 앞자리를 떼어
     * `extension_identifier` 로 남겨 두어야, 확장을 데이터까지 지우며 제거할 때 그
     * 확장이 남긴 전환만 골라 지울 수 있습니다.
     *
     * 등록된 목표인지 확인하는 레지스트리는 아직 없습니다(계획서 §4.13-1, S4). 그때까지는
     * 들어온 키를 그대로 받습니다 — 버리면 그 전환은 어디에도 남지 않기 때문입니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @param  AnalyticsVisit  $visit  대상 방문
     * @return void
     */
    private function recordConversion(TrackingRequest $request, AnalyticsVisit $visit): void
    {
        if ($request->type !== ActionType::Goal->value) {
            return;
        }

        $goalKey = $request->goalKey;

        if ($goalKey === null || $goalKey === '') {
            return;
        }

        $attributes = $request->goalAttributes;
        $owner = $this->goalOwner($goalKey);

        $this->conversions->create([
            'visit_id' => $visit->id,
            'visitor_key' => $visit->visitor_key,
            'goal_key' => $goalKey,
            'extension_type' => $owner['type'],
            'extension_identifier' => $owner['identifier'],
            'label' => self::goalLabel($attributes),
            'revenue' => self::goalRevenue($attributes),
            'currency' => self::goalCurrency($attributes),
            'occurred_at' => AnalyticsClock::now(),
            'custom_dimensions' => $request->dimensions === [] ? null : $request->dimensions,
        ]);
    }

    /**
     * 목표 키에서 공급한 확장을 가려냅니다.
     *
     * 코어 목표(`core.`)와 앞자리가 없는 키는 확장 소유가 아닙니다. 모듈인지 플러그인인지는
     * 설치된 확장 목록을 봐야 알 수 있는데, 수집 경로에서 확장 목록을 매번 읽으면 비콘마다
     * 부하가 붙습니다. 지금은 식별자만 남기고 종류는 비워 둡니다 — 삭제 시 정리는
     * 식별자로 찾습니다(`deleteForExtension`).
     *
     * @param  string  $goalKey  목표 키
     * @return array{type: string|null, identifier: string|null} 확장 종류와 식별자
     */
    private function goalOwner(string $goalKey): array
    {
        $separator = strpos($goalKey, '.');

        if ($separator === false || $separator === 0) {
            return ['type' => null, 'identifier' => null];
        }

        $prefix = substr($goalKey, 0, $separator);

        if ($prefix === 'core') {
            return ['type' => null, 'identifier' => null];
        }

        return ['type' => null, 'identifier' => $prefix];
    }

    /**
     * 전환 속성에서 표시 라벨을 꺼냅니다.
     *
     * @param  array<string, mixed>  $attributes  전환 속성
     * @return string|null 라벨
     */
    private static function goalLabel(array $attributes): ?string
    {
        $label = $attributes['label'] ?? null;

        if (! is_string($label) && ! is_numeric($label)) {
            return null;
        }

        $label = trim((string) $label);

        return $label === '' ? null : mb_substr($label, 0, 255);
    }

    /**
     * 전환 속성에서 금액을 꺼냅니다.
     *
     * 음수는 매출이 아니므로 버립니다. 컬럼이 `decimal(15,2)` 라 상한을 넘는 값은
     * 적재가 통째로 실패하므로 여기서 걸러 냅니다.
     *
     * @param  array<string, mixed>  $attributes  전환 속성
     * @return string|null 금액 (소수 2자리 문자열)
     */
    private static function goalRevenue(array $attributes): ?string
    {
        $revenue = $attributes['revenue'] ?? null;

        if (! is_numeric($revenue)) {
            return null;
        }

        $revenue = (float) $revenue;

        if ($revenue < 0 || $revenue > self::MAX_REVENUE) {
            return null;
        }

        return number_format($revenue, 2, '.', '');
    }

    /**
     * 전환 속성에서 통화 코드를 꺼냅니다.
     *
     * @param  array<string, mixed>  $attributes  전환 속성
     * @return string|null 통화 코드 (대문자 3자리)
     */
    private static function goalCurrency(array $attributes): ?string
    {
        $currency = $attributes['currency'] ?? null;

        if (! is_string($currency) || ! preg_match('/^[A-Za-z]{3}$/', $currency)) {
            return null;
        }

        return strtoupper($currency);
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
            // 페이지를 하나만 보고 떠나면 이탈이다. 두 번째 페이지가 오는 순간 풀린다.
            'is_bounce' => $pageviews <= 1,
        ];

        // 진입·이탈은 페이지다. 내려받기·검색·외부 링크·이벤트는 방문 안의 행동일 뿐이라, 그 이름(파일명·
        // 검색어)을 실으면 이탈 페이지 리포트와 실시간 「보고 있는 페이지」에 파일 이름이 뜬다. 페이지뷰 없이
        // 시작한 방문은 첫 페이지뷰가 올 때 진입 페이지를 얻는다.
        if ($isPageview) {
            $attributes['exit_action_name_id'] = $action->action_name_id;

            if ($visit->entry_action_name_id === null) {
                $attributes['entry_action_name_id'] = $action->action_name_id;
            }
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
            // 트래커가 만들어 보낸 표시 이름(`download` 속성 → 링크 글자 → 파일명)이 있으면
            // 그것이 사전 이름이다 — 주소를 담으면 타임라인과 리포트가 파일이 아니라 주소를 센다.
            ActionType::Download->value, ActionType::Outlink->value => $request->name ?? $request->target ?? $normalizedUrl,
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

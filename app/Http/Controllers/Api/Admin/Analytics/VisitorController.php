<?php

namespace App\Http\Controllers\Api\Admin\Analytics;

use App\Analytics\AnalyticsClock;
use App\Analytics\AnalyticsConfig;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;
use App\Enums\Analytics\PrivacyMode;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\Analytics\VisitorIndexRequest;
use App\Http\Requests\Admin\Analytics\VisitorShowRequest;
use App\Services\Analytics\DataSubjectService;
use Illuminate\Http\JsonResponse;

/**
 * 방문자 프로필 API
 *
 * 방문자 한 사람이 무엇을 언제 봤는지 시간순으로 보여 줍니다. 개인정보 권한이 있는
 * 사람만 볼 수 있습니다.
 *
 * 익명 모드로 모은 구간은 방문자 키가 날마다 바뀌므로 「같은 사람」을 하루 안에서만
 * 묶을 수 있습니다. 화면이 그 한계를 함께 안내하도록 응답에 적어 보냅니다.
 */
class VisitorController extends AdminBaseController
{
    /**
     * 방문자 컨트롤러를 생성합니다.
     *
     * @param  DataSubjectService  $subjects  정보주체 처리기
     * @param  VisitRepositoryInterface  $visits  방문 원장 저장소
     */
    public function __construct(
        private readonly DataSubjectService $subjects,
        private readonly VisitRepositoryInterface $visits,
    ) {
        parent::__construct();
    }

    /**
     * 방문자 목록을 조회합니다.
     *
     * 방문 하나하나가 아니라 「사람」 단위로 묶어, 몇 번 왔고 마지막이 언제였는지를
     * 보여 줍니다. 여기서 한 사람을 고르면 `show` 가 그 사람의 활동을 펼칩니다.
     *
     * @param  VisitorIndexRequest  $request  목록 요청
     * @return JsonResponse 방문자 목록 응답
     */
    public function index(VisitorIndexRequest $request): JsonResponse
    {
        [$from, $to] = $request->resolvedRange();

        $result = $this->visits->visitorSummaries($from, $to, [
            'search' => $request->input('q'),
            'visitor_type' => $request->input('type'),
            'page' => (int) $request->input('page', 1),
            'per_page' => (int) $request->input('per_page', 25),
        ]);

        $config = AnalyticsConfig::fromSettings();

        return $this->success('analytics.visitors.index_success', [
            'from' => $from,
            'to' => $to,
            'data' => array_map(fn (object $row): array => [
                'visitor_key' => (string) $row->visitor_key,
                // 합산 질의의 결과라도 모델을 거치면 유형이 Enum 으로 캐스팅돼 온다.
                'visitor_type' => $row->visitor_type instanceof \BackedEnum
                    ? (string) $row->visitor_type->value
                    : (string) $row->visitor_type,
                'visits' => (int) $row->visits,
                'pageviews' => (int) $row->pageviews,
                'last_action_at' => $row->last_action_at,
                'country_code' => $row->country_code,
                'device_type' => $row->device_type,
                'browser_name' => $row->browser_name,
                'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
            ], $result['rows']),
            'pagination' => [
                'page' => (int) $request->input('page', 1),
                'per_page' => (int) $request->input('per_page', 25),
                'has_more' => $result['has_more'],
            ],
            'mode' => $config->privacyMode->value,
            'mode_notice' => $config->privacyMode === PrivacyMode::Anonymous
                ? __('analytics.visitors.anonymous_mode_notice')
                : null,
        ]);
    }

    /**
     * 방문자 한 사람의 방문과 활동을 조회합니다.
     *
     * @param  VisitorShowRequest  $request  조회 요청
     * @param  string  $visitorKey  볼 방문자 키 (목록에서 고른 값)
     * @return JsonResponse 프로필 응답
     */
    public function show(VisitorShowRequest $request, string $visitorKey): JsonResponse
    {
        $config = AnalyticsConfig::fromSettings();
        $anonymous = $config->privacyMode === PrivacyMode::Anonymous;

        // 익명 모드는 방문자 키가 날마다 바뀌어 하루를 넘겨 「같은 사람」으로 이을 수 없다.
        // 그런데도 과거 구간을 그대로 받으면, 우연히 같은 키를 쓴 다른 날의 다른 사람이
        // 한 프로필에 섞여 보인다. 조회 창을 오늘로 좁혀 그 섞임을 애초에 막는다.
        [$from, $to] = $anonymous
            ? [AnalyticsClock::today(), AnalyticsClock::today()]
            : [$request->input('from'), $request->input('to')];

        $profile = $this->subjects->export(['visitor_key' => $visitorKey], $config, $from, $to);

        return $this->success('analytics.visitors.fetch_success', array_merge($profile, [
            // 화면의 「방문자 카드」가 읽을 한 덩어리. 방문이 0건이어도 카드가 비지 않도록
            // 키·유형만이라도 채워 둔다 — 목록에서 고른 값이 곧 그 사람의 신원이다.
            'visitor' => $this->visitorCard($visitorKey, $profile['visits']),
            'mode' => $config->privacyMode->value,
            'mode_notice' => $anonymous
                ? __('analytics.visitors.anonymous_mode_notice')
                : null,
        ]));
    }

    /**
     * 방문 목록에서 방문자 카드 한 덩어리를 만듭니다.
     *
     * @param  string  $visitorKey  방문자 키
     * @param  array<int, array<string, mixed>>  $visits  내보낸 방문 목록
     * @return array<string, mixed> 방문자 카드
     */
    private function visitorCard(string $visitorKey, array $visits): array
    {
        // 가장 최근 방문이 그 사람의 「지금 모습」이다 — 기기·브라우저·지역은 바뀔 수 있고
        // 화면은 마지막으로 본 값을 보여 준다. export() 는 최신순으로 내려준다.
        $latest = $visits[0] ?? null;

        return [
            'visitor_key' => $visitorKey,
            'visitor_type' => $latest['visitor_type'] ?? null,
            'country_code' => $latest['country_code'] ?? null,
            'device_type' => $latest['device_type'] ?? null,
            'browser_name' => $latest['browser_name'] ?? null,
            'os_name' => $latest['os_name'] ?? null,
            'visits' => count($visits),
            'first_action_at' => $visits !== [] ? end($visits)['first_action_at'] ?? null : null,
            'last_action_at' => $latest['last_action_at'] ?? null,
        ];
    }
}

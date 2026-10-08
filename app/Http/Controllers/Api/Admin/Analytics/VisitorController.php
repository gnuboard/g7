<?php

namespace App\Http\Controllers\Api\Admin\Analytics;

use App\Analytics\AnalyticsClock;
use App\Analytics\AnalyticsConfig;
use App\Analytics\CodeLabelTranslator;
use App\Analytics\GeoLabelTranslator;
use App\Analytics\IpAddress;
use App\Analytics\LanguageLabelTranslator;
use App\Analytics\UrlNormalizer;
use App\Contracts\Analytics\GoalRegistryInterface;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\Analytics\PrivacyMode;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\Analytics\VisitorIndexRequest;
use App\Http\Requests\Admin\Analytics\VisitorShowRequest;
use App\Http\Resources\Traits\ExposesMemberContact;
use App\Models\User;
use App\Services\Analytics\CsvExporter;
use App\Services\Analytics\DataSubjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
    use ExposesMemberContact;

    /**
     * 방문자 컨트롤러를 생성합니다.
     *
     * @param  DataSubjectService  $subjects  정보주체 처리기
     * @param  VisitRepositoryInterface  $visits  방문 원장 저장소
     * @param  UserRepositoryInterface  $users  회원 저장소 — 원장에 번호만 남은 회원을 이름으로 푼다
     * @param  CsvExporter  $csv  CSV 내보내기
     * @param  GoalRegistryInterface  $goals  목표 등록부 — 타임라인 전환을 선언된 이름으로 푼다
     */
    public function __construct(
        private readonly DataSubjectService $subjects,
        private readonly VisitRepositoryInterface $visits,
        private readonly UserRepositoryInterface $users,
        private readonly CsvExporter $csv,
        private readonly GoalRegistryInterface $goals,
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
        $members = $this->membersFor($result['rows']);

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
                'last_action_at' => AnalyticsClock::toSiteDateTimeString($row->last_action_at),
                'country_code' => $row->country_code,
                // 코드(`KR`·`desktop`)는 그대로 두고 화면이 읽을 이름을 덧붙인다(#682 S4 라운드 8).
                'country_name' => self::countryName($row->country_code),
                'device_type' => $row->device_type,
                'device_type_label' => CodeLabelTranslator::label('analytics.device_type', $row->device_type),
                'browser_name' => $row->browser_name,
                'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
                // 회원 방문자는 번호만 보여서는 누군지 알 수 없다 — 화면이 이름을 쓰고
                // 「회원 정보 보기」로 건너뛸 수 있게 uuid 까지 함께 싣는다. 비회원은 null 이다.
                'user' => $this->memberSummary($members[(int) ($row->user_id ?? 0)] ?? null),
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
     * 목록 한 쪽에 실린 회원들을 한 번에 읽습니다.
     *
     * 행마다 읽으면 한 쪽에 25 질의가 난다 — 쪽 안의 회원 번호를 모아 한 번만 읽는다.
     *
     * @param  array<int, object>  $rows  방문자 합산 행
     * @return array<int, User> 회원 번호로 찾는 회원
     */
    private function membersFor(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (object $row): int => (int) ($row->user_id ?? 0),
            $rows,
        ))));

        if ($ids === []) {
            return [];
        }

        return $this->users->findManyByIdsKeyed($ids)->all();
    }

    /**
     * 회원 한 사람을 화면이 읽을 모양으로 바꿉니다.
     *
     * 이메일은 공통 판정(`ExposesMemberContact`)을 따른다 — 활동 로그·알림 이력과 같은
     * 규칙을 써야 한 화면만 주소록 수집 경로가 되는 일이 없다.
     *
     * @param  User|null  $user  회원 (비회원 방문이면 null)
     * @return array{uuid: string, name: string, email: string|null}|null 화면용 회원 또는 null
     */
    private function memberSummary(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'uuid' => (string) $user->uuid,
            'name' => (string) $user->name,
            'email' => $this->canExposeMemberEmail(null, (int) $user->id) ? $user->email : null,
        ];
    }

    /**
     * 방문자 한 사람의 방문과 활동을 조회합니다.
     *
     * @param  VisitorShowRequest  $request  조회 요청
     * @param  string  $visitorKey  볼 방문자 키 (목록에서 고른 값)
     * @return JsonResponse|StreamedResponse 프로필 응답 또는 CSV 내려받기
     */
    public function show(VisitorShowRequest $request, string $visitorKey): JsonResponse|StreamedResponse
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

        if ($request->wantsCsv()) {
            return $this->exportProfileCsv($profile, $visitorKey, (string) $from, (string) $to);
        }

        // 화면용 가공을 한 번만 하고 카드도 같은 값을 쓴다 — 카드에 원본을 넘기면 목록의 시각은
        // 사이트 표준시인데 카드만 UTC 로 남아, 같은 화면 안에서 두 기준이 공존한다.
        $screenVisits = $this->visitsForScreen($profile['visits'] ?? [], $config->ipMaskOctets);

        return $this->success('analytics.visitors.fetch_success', array_merge($profile, [
            // 타임라인이 읽힐 이름을 덧붙인다. 저장 이름(`name`)은 정보주체 내보내기 파일이 그대로 싣는
            // 값이라 손대지 않는다 — 화면만 사이트 호스트를 떼고 퍼센트 인코딩을 푼 이름을 쓴다.
            'visits' => $screenVisits,
            // 조회 시각도 같은 화면의 다른 시각과 같은 기준으로 싣는다(내보내기 파일은 ISO8601 그대로).
            'exported_at' => self::siteTime($profile['exported_at'] ?? null),
            // 화면의 「방문자 카드」가 읽을 한 덩어리. 방문이 0건이어도 카드가 비지 않도록
            // 키·유형만이라도 채워 둔다 — 목록에서 고른 값이 곧 그 사람의 신원이다.
            'visitor' => $this->visitorCard($visitorKey, $screenVisits),
            'mode' => $config->privacyMode->value,
            // 화면이 찍는 주소는 설정대로 뒤 옥텟을 지운 값이다(`DataSubjectService::export`).
            // 그 사실을 함께 싣지 않으면 운영자가 마스킹된 값을 원래 주소로 읽고, 정보주체가
            // 「내 접속 기록이 맞느냐」 물었을 때 그 주소로 답하게 된다(§4.10 「IP(마스킹 표시)」).
            'ip_mask_octets' => $config->ipMaskOctets,
            'mode_notice' => $anonymous
                ? __('analytics.visitors.anonymous_mode_notice')
                : null,
        ]));
    }

    /**
     * 방문자 활동 내역을 CSV 로 내보냅니다.
     *
     * 액션 하나가 한 줄이고 방문 정보는 줄마다 되풀이됩니다(부록 D) — 정보주체가
     * 열람을 요구했을 때 스프레드시트로 건네줄 수 있는 모양입니다. 액션이 없는 방문도
     * 한 줄로 남깁니다. 방문했다는 사실 자체가 기록이라 빠지면 안 됩니다.
     *
     * @param  array<string, mixed>  $profile  내보낸 프로필
     * @param  string  $visitorKey  방문자 키
     * @param  string  $from  조회 시작일 (Y-m-d, 파일명에 싣는다 — 부록 D)
     * @param  string  $to  조회 종료일 (Y-m-d, 파일명에 싣는다 — 부록 D)
     * @return StreamedResponse 내려받기 응답
     */
    private function exportProfileCsv(array $profile, string $visitorKey, string $from = '', string $to = ''): StreamedResponse
    {
        $rows = function () use ($profile): iterable {
            foreach ($profile['visits'] as $visit) {
                // 시각 열은 화면·파일 이름과 같은 사이트 표준시로 싣는다. UTC 원값을 그대로 쓰면 파일 이름의
                // 기간(사이트 날짜)과 행의 날짜가 다른 하루를 가리킨다 — 서울 사이트의 10-06 아침 방문이
                // 「…_2026-10-06.csv」 안에 `2026-10-05T21:…` 로 찍혔다(7.1.0 실측 O-E4).
                $base = [
                    $visit['visit_id'],
                    self::siteTime($visit['first_action_at'] ?? null),
                    self::siteTime($visit['last_action_at'] ?? null),
                    $visit['visitor_type'],
                    $visit['country_code'],
                    $visit['device_type'],
                    $visit['browser_name'],
                    $visit['os_name'],
                    $visit['referrer_type'],
                    $visit['referrer_name'],
                    // 전체 유입 URL — 집계표에는 호스트까지만 쌓여 어느 글에서 왔는지 알 수 없다.
                    $visit['referrer_url'],
                    $visit['pageviews'],
                    $visit['total_time_seconds'],
                ];

                if ($visit['actions'] === []) {
                    yield array_merge($base, [null, null, null, null]);

                    continue;
                }

                foreach ($visit['actions'] as $action) {
                    yield array_merge($base, [
                        self::siteTime($action['occurred_at'] ?? null),
                        $action['action_type'],
                        $action['name'],
                        $action['time_spent_seconds'],
                    ]);
                }
            }
        };

        // 파일명에 기간을 넣는다(부록 D `analytics-{report}-{from}_{to}.csv`). 같은 사람을 두
        // 기간으로 내려받으면 이름이 같아 앞 파일을 덮었다 — 내용이 다른 파일이 같은 이름을 쓰면
        // 정보주체에게 건넬 때 어느 구간인지 알 수 없다.
        $suffix = $from !== '' && $to !== '' ? sprintf('-%s_%s', $from, $to) : '';

        return $this->csv->stream(
            sprintf('analytics-visitor-%s%s.csv', $visitorKey, $suffix),
            [
                'visit_id', 'first_action_at', 'last_action_at', 'visitor_type',
                'country_code', 'device_type', 'browser', 'os',
                'referrer_type', 'referrer_name', 'referrer_url', 'pageviews', 'total_time_seconds',
                'action_occurred_at', 'action_type', 'action_name', 'time_spent_seconds',
            ],
            $rows(),
        );
    }

    /**
     * 타임라인 액션에 화면용 이름을 덧붙입니다.
     *
     * @param  array<int, array<string, mixed>>  $visits  내보내기 모양의 방문 목록
     * @param  int  $maskOctets  현재 IP 마스킹 옥텟 수 (방문마다 주소가 가려진 값인지 판정)
     * @return array<int, array<string, mixed>> 화면용 이름이 붙은 방문 목록
     */
    private function visitsForScreen(array $visits, int $maskOctets = 0): array
    {
        return array_map(function (array $visit) use ($maskOctets): array {
            $visit['actions'] = array_map(static function (array $action): array {
                $name = $action['name'] ?? null;
                $action['name_display'] = is_string($name) ? UrlNormalizer::forDisplay($name) : $name;
                $action['occurred_at'] = self::siteTime($action['occurred_at'] ?? null);

                return $action;
            }, $visit['actions'] ?? []);

            $visit['conversions'] = array_map(function (array $conversion): array {
                $conversion['occurred_at'] = self::siteTime($conversion['occurred_at'] ?? null);
                // 전환 탭(`ReportService`)과 같은 이름으로 부른다. `label` 은 목표 이름이 아니라 전환
                // 속성(주문번호 등)이라, 속성 없이 기록되는 코어 목표는 원키가 찍혔다. 등록부에서
                // 사라진 목표(꺼진 확장)는 원키 그대로다.
                $goalKey = $conversion['goal_key'] ?? null;
                $conversion['goal_label'] = is_string($goalKey)
                    ? ($this->goals->find($goalKey)?->localizedLabel(app()->getLocale()) ?? $goalKey)
                    : null;

                return $conversion;
            }, $visit['conversions'] ?? []);

            $visit['first_action_at'] = self::siteTime($visit['first_action_at'] ?? null);
            $visit['last_action_at'] = self::siteTime($visit['last_action_at'] ?? null);
            // 그 방문의 주소가 지금 설정대로 가려진 값인지 — 설정 전 기록은 원래 주소다(7.1.0 실측 O-E8).
            $visit['ip_masked'] = IpAddress::isMaskedBy(is_string($visit['ip'] ?? null) ? $visit['ip'] : null, $maskOctets);
            // 저장 값(`desktop`·`search`)은 내보내기 파일이 그대로 싣는다 — 화면만 읽을 이름을 덧붙인다.
            $visit['device_type_label'] = CodeLabelTranslator::label('analytics.device_type', $visit['device_type'] ?? null);
            $visit['referrer_type_label'] = CodeLabelTranslator::label('analytics.referrer_type', $visit['referrer_type'] ?? null);
            $visit['country_name'] = self::countryName($visit['country_code'] ?? null);
            $visit['browser_language_label'] = LanguageLabelTranslator::language($visit['browser_language'] ?? null);
            $visit['locale_label'] = LanguageLabelTranslator::language($visit['locale'] ?? null);

            return $visit;
        }, $visits);
    }

    /**
     * 내보낸 ISO8601 시각을 화면이 쓸 사이트 표준시 문자열로 바꿉니다.
     *
     * 방문자 통계의 집계 경계는 사이트 자정에 잠겨 있으므로 화면의 시각도 사이트 기준이어야
     * 합니다 — 보는 사람 기준으로 보이면 「오늘 KPI」와 목록의 날짜가 서로 다른 하루를
     * 가리킵니다(#682 S3 라운드 6, 설계 결정). 정보주체 JSON 내보내기는 오프셋이 붙은 ISO8601 이
     * 정본이라 `DataSubjectService` 쪽은 그대로 두고 화면과 CSV 경로에서 바꿉니다 — CSV 는 파일 이름의
     * 기간이 사이트 날짜이므로 행의 시각도 같은 기준이어야 합니다(7.1.0 실측 O-E4).
     *
     * @param  mixed  $value  ISO8601 문자열 또는 null
     * @return string|null Y-m-d H:i:s 형식 (값이 없으면 null)
     */
    private static function siteTime(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return AnalyticsClock::toSiteDateTimeString(Carbon::parse($value));
    }

    /**
     * 국가 코드의 화면 이름을 찾습니다.
     *
     * @param  mixed  $code  국가 코드
     * @return string|null 국가 이름 (값이 없으면 null, 번역이 없으면 코드)
     */
    private static function countryName(mixed $code): ?string
    {
        return is_string($code) && $code !== '' ? GeoLabelTranslator::country($code) : null;
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

        // 회원 신원. 목록 화면과 같은 값을 같은 모양으로 싣는다 — 상세에만 없으면 목록에서
        // 이름을 보고 들어온 운영자가 카드에서 그 사람을 다시 잃는다(#682 S3 라운드 5, §4.11).
        // 가명화 구간은 `user_id` 가 지워지고 해시만 남으므로 해시도 함께 내려보낸다.
        $userId = 0;
        $userHash = null;

        foreach ($visits as $visit) {
            $userId = (int) ($visit['user_id'] ?? 0) ?: $userId;
            $userHash ??= $visit['user_hash'] ?? null;
        }

        $members = $userId > 0 ? $this->users->findManyByIdsKeyed([$userId])->all() : [];

        return [
            'visitor_key' => $visitorKey,
            'visitor_type' => $latest['visitor_type'] ?? null,
            'country_code' => $latest['country_code'] ?? null,
            'country_name' => $latest['country_name'] ?? null,
            'region_code' => $latest['region_code'] ?? null,
            'city' => $latest['city'] ?? null,
            'device_type' => $latest['device_type'] ?? null,
            'device_type_label' => $latest['device_type_label'] ?? null,
            'browser_name' => $latest['browser_name'] ?? null,
            'browser_version' => $latest['browser_version'] ?? null,
            'os_name' => $latest['os_name'] ?? null,
            'os_version' => $latest['os_version'] ?? null,
            'screen_resolution' => $latest['screen_resolution'] ?? null,
            'browser_language' => $latest['browser_language'] ?? null,
            'browser_language_label' => $latest['browser_language_label'] ?? null,
            'locale' => $latest['locale'] ?? null,
            'locale_label' => $latest['locale_label'] ?? null,
            'visits' => count($visits),
            'first_action_at' => $visits !== [] ? end($visits)['first_action_at'] ?? null : null, // visitsForScreen 이 이미 사이트 표준시로 바꾼 값
            'last_action_at' => $latest['last_action_at'] ?? null,
            'user_id' => $userId > 0 ? $userId : null,
            'user_hash' => $userHash,
            'user' => $this->memberSummary($members[$userId] ?? null),
            // 재방문 여부. 방문이 두 번 이상이면 다시 온 사람이다. 익명 모드에서는 키가 날마다
            // 바뀌어 「하루 안에서만」 묶이므로 화면이 `mode_notice` 로 그 한계를 함께 알린다.
            'is_returning' => count($visits) > 1,
        ];
    }
}

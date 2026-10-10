<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsClock;
use App\Analytics\AnalyticsConfig;
use App\Analytics\CodeLabelTranslator;
use App\Analytics\GeoLabelTranslator;
use App\Analytics\UrlNormalizer;
use App\Contracts\Repositories\Analytics\ActionNameRepositoryInterface;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\Analytics\VisitorType;
use App\Extension\HookManager;
use App\Models\User;

/**
 * 현재 접속자 현황
 *
 * 최근 몇 분 안에 움직인 방문자를 보여 줍니다. 집계 표가 아니라 원장을 직접 읽습니다 —
 * 집계는 한 시간에 한 번 도는데, 지금 누가 있는지는 그 사이를 기다릴 수 없습니다.
 *
 * 주소는 개인정보 권한을 가진 사람에게만 채워 보냅니다.
 */
class RealtimeService
{
    /**
     * 목록에 담을 최근 방문 수
     */
    private const LIST_LIMIT = 50;

    /**
     * 방문자 키에서 화면에 보일 앞자리 길이
     */
    private const KEY_PREFIX_LENGTH = 8;

    /**
     * 현재 접속자로 볼 수 있는 가장 짧은·긴 구간 (분)
     *
     * 설정 화면이 같은 범위를 막지만, 이 값은 화면이 아니라 이 기능의 뜻이 정하는
     * 한계다 — 1분보다 짧으면 「지금」이 표본 하나도 못 담고, 한 시간을 넘기면 그것은
     * 현재 접속자가 아니라 지난 시간의 방문이다. API 로 직접 들어오는 값도 있으므로
     * 여기서 자른다.
     */
    private const MIN_WINDOW_MINUTES = 1;

    /**
     * @see self::MIN_WINDOW_MINUTES
     */
    private const MAX_WINDOW_MINUTES = 60;

    /**
     * 현황 조회기를 생성합니다.
     *
     * @param  VisitRepositoryInterface  $visits  방문 원장 저장소
     * @param  ActionNameRepositoryInterface  $names  이름 사전 저장소
     * @param  UserRepositoryInterface  $users  회원 저장소
     */
    public function __construct(
        private readonly VisitRepositoryInterface $visits,
        private readonly ActionNameRepositoryInterface $names,
        private readonly UserRepositoryInterface $users,
    ) {}

    /**
     * 현재 접속자 현황을 만듭니다.
     *
     * @param  AnalyticsConfig  $config  설정
     * @param  array<string, mixed>  $options  조회 옵션 (minutes, visitor_types, include_ip)
     * @return array{count: int, window_minutes: int, visitors: array<int, array<string, mixed>>} 현황
     */
    public function snapshot(AnalyticsConfig $config, array $options = []): array
    {
        // audit:allow service-config-value-clamp 현재 접속자 구간의 상·하한은 설정이 아니라
        // 이 기능의 정의가 정한다 — 이름 있는 상수로 승격해 뜻을 드러냈다.
        $minutes = max(self::MIN_WINDOW_MINUTES, min(
            self::MAX_WINDOW_MINUTES,
            (int) ($options['minutes'] ?? $config->realtimeWindowMinutes),
        ));
        $types = $options['visitor_types'] ?? $this->humanTypes();
        $includeIp = (bool) ($options['include_ip'] ?? false);

        $rows = $this->visits->realtime($minutes, $types, self::LIST_LIMIT);
        $nameMap = $this->names->findManyById($rows->pluck('exit_action_name_id')->all());

        // 회원 신원. 지금 보고 있는 사람이 누구인지 알아도 그 자리에서 회원 화면으로 건너갈 수
        // 없으면 목록에서 다시 찾아야 한다(§4.10 「privacy 권한이면 IP 열 + 프로필 링크」).
        // 주소와 같은 권한으로 가리고, 행마다 질의하지 않도록 한 쪽 분을 한 번에 읽는다.
        $memberMap = $includeIp
            ? $this->users->findManyByIdsKeyed(array_values(array_unique(array_filter(
                $rows->pluck('user_id')->map(static fn ($id): int => (int) $id)->all(),
            ))))->all()
            : [];

        return [
            'count' => $this->visits->realtimeCount($minutes, $types),
            'window_minutes' => $minutes,
            'visitors' => $rows->map(function ($visit) use ($nameMap, $includeIp, $memberMap): array {
                $nameId = $visit->exit_action_name_id;

                return [
                    'visitor_key' => mb_substr((string) $visit->visitor_key, 0, self::KEY_PREFIX_LENGTH),
                    'page' => $nameId !== null && isset($nameMap[$nameId]) ? UrlNormalizer::forDisplay((string) $nameMap[$nameId]->name) : null,
                    'last_action_at' => AnalyticsClock::toSiteDateTimeString($visit->last_action_at),
                    'visitor_type' => $this->enumValue($visit->visitor_type),
                    'country_code' => $visit->country_code,
                    // 코드는 그대로 두고 화면이 읽을 이름을 덧붙인다(#682 S4 라운드 8).
                    'country_name' => is_string($visit->country_code) && $visit->country_code !== '' ? GeoLabelTranslator::country($visit->country_code) : null,
                    'device_type' => $this->enumValue($visit->device_type),
                    'device_type_label' => CodeLabelTranslator::label('analytics.device_type', $visit->device_type),
                    'browser_name' => $visit->browser_name,
                    'ip' => $includeIp && $visit->ip !== null ? (string) $visit->ip : null,
                    'user' => $this->memberSummary($memberMap[(int) ($visit->user_id ?? 0)] ?? null),
                    // 확장이 이 방문에 붙이는 값(장바구니 금액 등). 코어 필드를 덮지 못하게 한 칸 아래에 둔다.
                    'extensions' => $this->extensionFields($visit),
                ];
            })->all(),
        ];
    }

    /**
     * 회원 한 사람을 화면이 쓸 만큼만 간추립니다.
     *
     * 목록 화면(`VisitorController::memberSummary`)과 같은 모양으로 싣습니다 — 두 화면이
     * 같은 사람을 다르게 그리면 운영자가 같은 사람인지 알 수 없습니다. 이메일은 노출 정책을
     * 타는 값이라 싣지 않습니다 — 현재 접속자 표는 이름과 이동만 필요합니다.
     *
     * @param  User|null  $user  회원
     * @return array<string, string>|null 간추린 회원 (회원 방문이 아니면 null)
     */
    private function memberSummary(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'uuid' => (string) $user->uuid,
            'name' => (string) $user->name,
        ];
    }

    /**
     * 사람 방문자 유형 목록을 돌려줍니다.
     *
     * @return array<int, string> 유형 목록 (봇 제외)
     */
    private function humanTypes(): array
    {
        return [VisitorType::Guest->value, VisitorType::Member->value, VisitorType::Admin->value];
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

    /**
     * 확장이 현재 접속자 행에 붙이는 값을 모읍니다.
     *
     * 필터 `core.analytics.realtime_fields` — 인자: 빈 배열, 방문 모델. 확장은 `{식별자} => 값`
     * 으로 돌려줍니다. 방송은 이 목록을 싣지 않고 화면이 다시 조회하는 신호로만 쓰므로, 값은
     * 조회 응답에만 들어갑니다.
     *
     * @param  object  $visit  방문
     * @return array<string, mixed> 식별자 → 값
     */
    private function extensionFields(object $visit): array
    {
        $fields = HookManager::applyFilters('core.analytics.realtime_fields', [], $visit);

        return array_filter(
            is_array($fields) ? $fields : [],
            fn ($value, $key): bool => is_string($key) && $key !== '',
            ARRAY_FILTER_USE_BOTH,
        );
    }
}

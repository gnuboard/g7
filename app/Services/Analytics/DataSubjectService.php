<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsClock;
use App\Analytics\AnalyticsConfig;
use App\Analytics\IpAddress;
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
        $found = $this->find($subject, $config, $from, $to);
        $visits = $found['visits'];
        $visitIds = $visits->pluck('id')->all();

        $actions = $this->actions->groupedForVisits($visitIds);
        $conversions = $this->conversions->findForVisits($visitIds)->groupBy('visit_id');
        $nameMap = $this->names->findManyById(
            $actions->flatten(1)->flatMap(fn ($action): array => [$action->action_name_id, $action->page_title_id])->all(),
        );

        HookManager::doAction('core.analytics.subject_exported', [
            'subject' => array_keys(array_filter($subject)),
            'visits' => count($visitIds),
        ]);

        return [
            'exported_at' => AnalyticsClock::now()->toIso8601String(),
            'subject' => array_keys(array_filter($subject)),
            'notice' => $found['notice'],
            'visits' => $visits->map(fn ($visit): array => [
                'visit_id' => $visit->id,
                'visitor_key' => $visit->visitor_key,
                'visitor_type' => $this->enumValue($visit->visitor_type),
                'first_action_at' => $visit->first_action_at?->toIso8601String(),
                'last_action_at' => $visit->last_action_at?->toIso8601String(),
                'ip' => $visit->ip !== null ? (string) $visit->ip : null,
                'country_code' => $visit->country_code,
                'browser_name' => $visit->browser_name,
                'os_name' => $visit->os_name,
                'device_type' => $this->enumValue($visit->device_type),
                'actions' => $actions->get($visit->id, collect())->map(fn ($action): array => [
                    'occurred_at' => $action->occurred_at?->toIso8601String(),
                    'action_type' => $this->enumValue($action->action_type),
                    'name' => isset($nameMap[$action->action_name_id])
                        ? (string) $nameMap[$action->action_name_id]->name
                        : null,
                    'time_spent_seconds' => $action->time_spent_seconds,
                ])->all(),
                'conversions' => $conversions->get($visit->id, collect())->map(fn ($conversion): array => [
                    'occurred_at' => $conversion->occurred_at?->toIso8601String(),
                    'goal_key' => $conversion->goal_key,
                    'label' => $conversion->label,
                    'revenue' => $conversion->revenue,
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
        $visits = $this->visits->findBySubject($this->resolveSubject($subject, $config), $from, $to);
        $visitIds = $visits->pluck('id')->all();

        if ($visitIds === []) {
            return ['visits' => 0, 'actions' => 0, 'conversions' => 0];
        }

        // 액션·전환을 먼저 지우고 방문을 지운다 — 연쇄 삭제에 기대지 않고 순서를
        // 코드가 소유한다. 집계는 건드리지 않는다.
        $counts = DB::transaction(fn (): array => [
            'actions' => $this->actions->deleteForVisits($visitIds),
            'conversions' => $this->conversions->deleteForVisits($visitIds),
            'visits' => $this->visits->deleteByIds($visitIds),
        ]);

        HookManager::doAction('core.analytics.subject_deleted', [
            'subject' => array_keys(array_filter($subject)),
            'counts' => $counts,
        ]);

        return $counts;
    }

    /**
     * 찾을 기준을 저장된 값과 같은 모양으로 바꿉니다.
     *
     * 주소는 저장할 때 마스킹을 거치므로, 찾을 때도 같은 마스킹을 입력에 적용해야
     * 맞습니다. 익명 모드 구간은 주소 대신 해시로 찾습니다.
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

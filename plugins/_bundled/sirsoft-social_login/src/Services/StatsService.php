<?php

namespace Plugins\Sirsoft\SocialLogin\Services;

use App\Helpers\TimezoneHelper;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptOutcome;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\AttemptRepositoryInterface;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\ProviderRepositoryInterface;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\SocialAccountRepositoryInterface;

/**
 * 통계 화면 — 제공자 × (가입·로그인·실패·연결 수·소셜 전용 회원).
 *
 * 가입·로그인·실패는 기간 안 시도 기록에서 세고(관리자 연결 테스트는 뺀다), 연결 수·소셜 전용 회원은 지금 값이다.
 * 로그인은 코어 로그인 인계로 넘긴 건수(`handoff`)다 — 인계 뒤 2단계 인증에서 멈춘 회원도 포함한다.
 *
 * @since 1.0.0
 */
class StatsService
{
    /** 허용 기간(일) */
    public const PERIODS = [7, 30, 90];

    /**
     * @param  AttemptRepositoryInterface  $attempts  시도 저장소
     * @param  SocialAccountRepositoryInterface  $accounts  연결 저장소
     * @param  ProviderRepositoryInterface  $providers  제공자 저장소
     * @param  ProviderSettings  $settings  표시 이름
     */
    public function __construct(
        private readonly AttemptRepositoryInterface $attempts,
        private readonly SocialAccountRepositoryInterface $accounts,
        private readonly ProviderRepositoryInterface $providers,
        private readonly ProviderSettings $settings,
    ) {}

    /**
     * 기간 통계를 돌려줍니다.
     *
     * 기간은 사이트 시간대 오늘에서 `$days` 일 전 자정부터다. 시도 시각은 UTC 로 저장돼 있으므로 질의 경계만
     * 사이트 자정을 UTC 로 옮긴 값을 쓴다(앱 시간대 자정으로 자르면 서울 사이트에서 첫날 아침 9시간이 빠지고,
     * 서울 자정 직후에는 전날 하루가 섞인다). `since` 도 그 경계를 사이트 시간대 문자열로 보인다.
     *
     * @param  int  $days  기간(일) — 7·30·90
     * @return array<string, mixed> days·since·rows·totals
     */
    public function forPeriod(int $days): array
    {
        $startDate = now()->setTimezone(TimezoneHelper::getSiteTimezone())->subDays($days)->toDateString();
        $since = TimezoneHelper::fromSiteDateStartOfDay($startDate);
        $outcomes = $this->attempts->outcomeCountsByProvider($since);
        $linked = $this->accounts->countsByProvider();
        $socialOnly = $this->accounts->socialOnlyCountsByProvider();

        $keys = $this->providers->all()->pluck('key')->all();
        foreach (array_merge(array_keys($outcomes), array_keys($linked)) as $key) {
            if (! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        $rows = [];
        foreach ($keys as $key) {
            $counts = $outcomes[$key] ?? [];
            $rows[] = [
                'provider_key' => $key,
                'label' => $this->settings->label($key),
                'signups' => (int) ($counts[AttemptOutcome::Signup->value] ?? 0),
                'logins' => (int) ($counts[AttemptOutcome::Handoff->value] ?? 0) + (int) ($counts[AttemptOutcome::Login->value] ?? 0),
                'links' => (int) ($counts[AttemptOutcome::Link->value] ?? 0),
                'failures' => (int) ($counts[AttemptOutcome::Failed->value] ?? 0) + (int) ($counts[AttemptOutcome::Denied->value] ?? 0),
                'linked_accounts' => (int) ($linked[$key] ?? 0),
                'social_only_users' => (int) ($socialOnly[$key] ?? 0),
            ];
        }

        $totals = [];
        foreach (['signups', 'logins', 'links', 'failures', 'linked_accounts', 'social_only_users'] as $column) {
            $totals[$column] = array_sum(array_column($rows, $column));
        }

        return [
            'days' => $days,
            'since' => TimezoneHelper::toSiteDateTimeString($since),
            'rows' => $rows,
            'totals' => $totals,
        ];
    }
}

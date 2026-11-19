<?php

namespace App\Scheduling;

use App\Support\RuntimeRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * 웹 크론 호출 정책 — 운영자 설정의 단일 해석 지점.
 *
 * 설정 키를 읽는 곳이 여럿이면 기본값과 경계값이 조용히 갈라진다. 엔드포인트·미들웨어·
 * 관리자 화면 응답이 전부 이 클래스를 지난다.
 *
 * 실행 방식(`system.cron_mode`)의 뜻:
 *
 * - `auto`   — 실행 모드 판정에 동조한다. CLI 를 쓸 수 없는 환경이면 주소 호출을 열고,
 *              쓸 수 있으면 서버 크론을 전제로 주소 호출도 함께 연다(운영자가 둘 중
 *              무엇을 걸었는지 서버는 알 수 없으므로 막지 않는다).
 * - `web`    — 주소 호출만.
 * - `cron`   — 서버 크론만. 주소 호출은 인증되더라도 실행하지 않는다.
 * - `both`   — 둘 다.
 *
 * @since 7.1.0
 */
class WebCronPolicy
{
    /** 실행 방식 — 자동 (실행 모드 판정 연동). */
    public const MODE_AUTO = 'auto';

    /** 실행 방식 — 주소 호출만. */
    public const MODE_WEB = 'web';

    /** 실행 방식 — 서버 크론만. */
    public const MODE_CRON = 'cron';

    /** 실행 방식 — 둘 다. */
    public const MODE_BOTH = 'both';

    /** 최소 간격 판정과 호출 기록을 한 임계 구역으로 묶는 잠금 키. */
    public const INVOCATION_LOCK_KEY = 'schedule:web-cron:invocation';

    /** 그 잠금의 만료(초) — 판정과 기록 사이만 쥐므로 짧다. 쥔 프로세스가 죽어도 이 시간 뒤 풀린다. */
    public const INVOCATION_LOCK_SECONDS = 10;

    /**
     * 허용되는 실행 방식 목록 (허용값 표가 소비).
     *
     * @return list<string> 실행 방식 값 목록
     */
    public static function modes(): array
    {
        return [self::MODE_AUTO, self::MODE_WEB, self::MODE_CRON, self::MODE_BOTH];
    }

    /**
     * 저장된 실행 방식을 반환합니다 (미지원 값은 auto 폴백).
     *
     * @return string 실행 방식
     */
    public function mode(): string
    {
        $mode = (string) g7_core_settings('system.cron_mode', self::MODE_AUTO);

        return in_array($mode, self::modes(), true) ? $mode : self::MODE_AUTO;
    }

    /**
     * 주소 호출이 실행으로 이어지는 설정인지 판정합니다.
     *
     * @return bool 허용 여부
     */
    public function webCallAllowed(): bool
    {
        // 이 서버의 스케줄이 꺼져 있으면(G7_SCHEDULER=off·stage 역할) 웹 크론·방문 트리거 모두 실행하지 않는다
        if (! RuntimeRole::schedulerEnabled()) {
            return false;
        }

        return in_array($this->mode(), [self::MODE_AUTO, self::MODE_WEB, self::MODE_BOTH], true);
    }

    /**
     * 최소 호출 간격(분).
     *
     * 간격 미만 호출은 실행 없이 정상 응답한다 — 호출 **횟수** 제한이지 실행 누락
     * 사유가 아니다. 다음 유효 호출의 소급 창이 그 사이 시각을 덮는다 (D17).
     *
     * @return int 분 (0 이면 제한 없음)
     */
    public function minIntervalMinutes(): int
    {
        $configured = g7_core_settings('system.cron_min_interval', null);

        $value = is_numeric($configured)
            ? (int) $configured
            : (int) config('core.cron.min_interval_minutes', 5);

        return max(0, min($value, (int) config('core.settings_limits.system_cron_min_interval_max', 1440)));
    }

    /**
     * 최소 호출 간격까지 남은 시간(초)을 계산합니다.
     *
     * 웹 크론 주소(`WebCronController`)와 방문 트리거(`RunScheduleOnVisit`)가 함께 쓰는
     * 판정이다 — 두 진입점이 각자 계산하면 경계값이 조용히 갈라진다. 진입점은 이 계산을
     * 직접 부르지 않고 `claimInvocation()` 을 거친다 — 판정과 호출 기록을 한 임계 구역으로
     * 묶는 것은 그쪽이다. 이 메서드는 시각만 받는 순수 계산이다.
     *
     * @param  CarbonImmutable|null  $lastInvokedAt  직전 호출 시각 (기록이 없으면 null)
     * @param  CarbonImmutable  $now  기준 시각
     * @param  int  $floorMinutes  간격 하한(분) — 설정이 이보다 작으면 이 값을 쓴다
     * @return int 남은 초 (0 이면 실행 가능)
     */
    public function throttleRemainingSeconds(?CarbonImmutable $lastInvokedAt, CarbonImmutable $now, int $floorMinutes = 0): int
    {
        $interval = max($this->minIntervalMinutes(), $floorMinutes);

        if ($interval <= 0 || $lastInvokedAt === null) {
            return 0;
        }

        $elapsed = $lastInvokedAt->diffInSeconds($now, absolute: false);

        return max(0, ($interval * 60) - (int) $elapsed);
    }

    /**
     * 최소 간격 판정과 호출 기록을 **한 임계 구역에서** 합니다.
     *
     * 판정과 기록이 따로 놓이면 같은 순간에 도착한 두 호출(외부 크론 서비스의 재시도, 방문
     * 트리거 여럿)이 둘 다 「직전 호출 없음」을 읽고 함께 러너를 돈다. 그래서 캐시 잠금
     * (`Cache::lock` — 파일·DB·Redis 캐시 모두 지원하고 CLI·프로세스 함수를 쓰지 않아 무CLI
     * 호스팅에서도 성립한다) 안에서 상태를 다시 읽고 판정한 뒤, 통과하면 그 자리에서 기록한다.
     *
     * 잠금을 얻지 못한 호출은 다른 호출이 판정·기록하는 중이라는 뜻이다 — 그 호출이 곧
     * 호출 시각을 남기므로 간격 전체가 남은 것으로 답한다(실행 없이 정상 응답, 작업은 다음
     * 유효 호출의 소급 창이 덮는다). 간격이 0 이면 제한이 없으므로 잠그지 않는다.
     *
     * 캐시 저장소가 잠금을 제공하지 못하면(장애) 잠금 없이 종전처럼 판정한다 — 예약 실행
     * 자체를 막는 것보다 드문 중복 실행이 낫고, 그 사실은 경고로 남긴다.
     *
     * @param  ScheduleRunState  $state  점검·실행 기록
     * @param  CarbonImmutable  $now  기준 시각 (통과하면 이 시각이 호출 시각으로 기록된다)
     * @param  int  $floorMinutes  간격 하한(분) — 설정이 이보다 작으면 이 값을 쓴다
     * @return int 남은 초 (0 이면 통과했고 호출 시각이 기록됐다)
     */
    public function claimInvocation(ScheduleRunState $state, CarbonImmutable $now, int $floorMinutes = 0): int
    {
        $interval = max($this->minIntervalMinutes(), $floorMinutes);

        $decide = function () use ($state, $now, $floorMinutes): int {
            // 잠금을 기다리는 사이 다른 호출이 기록했을 수 있다 — 판정 직전에 다시 읽는다.
            $state->refresh();

            $remaining = $this->throttleRemainingSeconds($state->lastInvokedAt(), $now, $floorMinutes);

            if ($remaining === 0) {
                $state->recordInvocation($now);
            }

            return $remaining;
        };

        if ($interval <= 0) {
            return $decide();
        }

        try {
            $lock = Cache::lock(self::INVOCATION_LOCK_KEY, self::INVOCATION_LOCK_SECONDS);
            $acquired = $lock->get();
        } catch (\Throwable $e) {
            Log::warning('웹 크론 간격 판정 잠금을 얻지 못해 잠금 없이 판정합니다.', ['error' => $e->getMessage()]);

            return $decide();
        }

        if (! $acquired) {
            return $interval * 60;
        }

        try {
            return $decide();
        } finally {
            $lock->release();
        }
    }

    /**
     * 허용 IP 목록 (비우면 제한 없음).
     *
     * @return list<string> IP 또는 CIDR 표기 목록
     */
    public function allowedIps(): array
    {
        $raw = (string) g7_core_settings('system.cron_allowed_ips', '');

        if (trim($raw) === '') {
            return [];
        }

        // 줄바꿈·쉼표 어느 쪽으로 적어도 같게 읽는다. `\R` 은 UTF-8 모드가 아니면
        // 바이트 단위로 판정해 한글 바이트를 개행으로 오인하므로 쓰지 않는다.
        $parts = preg_split('~\r\n|\r|\n|,~', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn ($ip) => $ip !== ''));
    }

    /**
     * 호출자 IP 가 허용 목록에 드는지 판정합니다.
     *
     * @param  string|null  $ip  호출자 IP
     * @return bool 허용 여부 (목록이 비어 있으면 언제나 true)
     */
    public function ipAllowed(?string $ip): bool
    {
        $allowed = $this->allowedIps();

        if ($allowed === []) {
            return true;
        }

        if (! is_string($ip) || $ip === '') {
            return false;
        }

        // IpUtils 가 IPv4/IPv6 와 CIDR 표기를 함께 판정한다 — 접두사 문자열 비교로
        // 대신하면 `10.1.1.1` 규칙이 `10.1.1.10` 까지 통과시킨다.
        return IpUtils::checkIp($ip, $allowed);
    }

    /**
     * 방문 트리거 폴백이 켜져 있는지 판정합니다 (D5 — 기본 꺼짐).
     *
     * @return bool 사용 여부
     */
    public function visitFallbackEnabled(): bool
    {
        return (bool) g7_core_settings('system.cron_visit_fallback', false);
    }
}

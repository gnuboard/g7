<?php

namespace App\CoreUpdate;

use App\Support\ExecutionEnvironment;

/**
 * 웹 코어 업데이트 한 요청의 실행 시간 예산.
 *
 * 한 요청이 한 단계를 맡아도, 그 단계가 서버의 요청 시간 제한(`max_execution_time`)보다
 * 길면 PHP 가 치명 오류로 요청을 끊는다. 그러면 단계는 실패로도 성공으로도 기록되지
 * 않고 점유만 남는다. 이 클래스는 세 가지를 한 곳에서 정한다:
 *
 * 1. **요청 수명** — 이 요청이 실제로 살 수 있는 시간. 점유의 만료 창은 이 값에서
 *    나온다. 선언이 실제보다 길면 이미 죽은 요청의 점유가 그만큼 남아 다음 요청을
 *    막고, 짧으면 살아 있는 요청을 다른 요청이 죽은 것으로 본다.
 * 2. **작업 몫** — 나눠서 처리할 수 있는 단계(사전 점검의 트리 훑기, 적용 대상 산출)가
 *    한 요청에서 쓸 시간. 요청 수명의 절반이되 프록시 응답 제한(통상 60초)보다 짧게 둔다.
 * 3. **화면 대기 시간** — 화면이 단계 요청 응답을 기다릴 시간(요청 수명 + 여유).
 *
 * 요청 수명은 **요청한 값이 아니라 실제로 적용된 값**이다. `set_time_limit()` 은
 * `disable_functions` 로 막혀 있거나 호스팅이 ini 를 잠그면 조용히 무시되므로, 호출한
 * 뒤 `max_execution_time` 을 다시 읽어 그 값을 쓴다(설치기 단계 실행과 같은 규칙).
 *
 * @since 7.2.0
 */
class CoreUpdateTimeBudget
{
    /** 한 단계 요청에 요청하는 실행 시간(초). 공유 호스팅은 이보다 짧게 잠겨 있을 수 있다. */
    public const REQUESTED_SECONDS = 300;

    /** 요청 수명을 넘긴 점유를 죽은 것으로 보기까지의 여유(초). */
    public const CLAIM_GRACE_SECONDS = 30;

    /** 화면이 요청 수명 뒤에 더 기다리는 시간(초) — 응답 직렬화·전송 몫. */
    public const CLIENT_GRACE_SECONDS = 30;

    /** 나눠 처리하는 작업이 한 요청에서 쓰는 최대 시간(초) — 프록시 응답 제한 안쪽. */
    public const MAX_SLICE_SECONDS = 40;

    /** 나눠 처리하는 작업의 최소 몫(초) — 매우 짧은 제한에서도 한 번에 조금은 진행한다. */
    public const MIN_SLICE_SECONDS = 5;

    /**
     * 몫에서 남겨 두는 응답 마무리 시간(초).
     *
     * 멈춤은 항목 하나를 끝낸 뒤에 묻고, 멈춘 뒤에도 진행분 저장·상태 응답이 남는다. 마감을 몫과 같게 두면
     * 조각이 몫을 1~2초 넘긴다. 그만큼을 마감에서 미리 뺀다.
     */
    public const FINISH_MARGIN_SECONDS = 3;

    /** 실행 시간 제한을 읽은 시각 (`begin()` 이후). */
    private ?float $startedAt = null;

    /** 이 요청에 적용된 수명(초). 0 이면 제한 없음. */
    private int $limitSeconds = 0;

    /** 조각의 몫을 정하는 기준 수명(초) — 늘리기 전 제한과 지금 제한 중 짧은 쪽. 0 이면 제한 없음. */
    private ?int $sliceBasisSeconds = null;

    /**
     * @param  \Closure|null  $clock  현재 시각(초, 부동소수) 공급자 — 테스트 주입용
     * @param  int|null  $limitOverride  적용 수명 고정값(초) — 테스트 주입용 (null 이면 ini 를 읽는다)
     */
    public function __construct(
        private readonly ?\Closure $clock = null,
        private readonly ?int $limitOverride = null,
    ) {}

    /**
     * 요청한 수명과 실제 적용치 중 선언해도 되는 값을 돌려줍니다.
     *
     * ini 값을 인자로 받는다 — 함수 안에서 `ini_get()` 을 부르면 그 분기를 원하는 값으로
     * 시험할 방법이 없다.
     *
     * @param  int  $requested  요청한 수명(초)
     * @param  string|int|false|null  $iniValue  `ini_get('max_execution_time')` 결과
     * @return int 선언할 수명(초). 0 이면 제한 없음(CLI 기본값 등)
     */
    public static function effectiveLimit(int $requested, string|int|false|null $iniValue): int
    {
        // 값을 못 읽으면 요청값을 쓴다 — 「확인 불가」를 0(무제한)으로 접으면 점유 만료 창이
        // 15분 기본값으로 늘어난다.
        if ($iniValue === false || $iniValue === null || trim((string) $iniValue) === '') {
            return max(0, $requested);
        }

        $effective = (int) trim((string) $iniValue);

        if ($effective <= 0) {
            return 0;
        }

        return $effective;
    }

    /**
     * 몫을 세기 시작할 시각을 정합니다.
     *
     * 실행 제한을 이 자리에서 다시 걸었으면(또는 제한이 없으면) 지금부터 센다. 다시 걸지 못했으면 서버의 시계는
     * 요청 시작부터 돌고 있으므로 요청 시작 시각부터 센다(알 수 없으면 지금).
     *
     * @param  bool  $timerFromNow  제한이 지금부터 다시 걸렸는지 (제한 없음 포함)
     * @param  float  $now  지금
     * @param  float|null  $requestStart  요청 시작 시각 (`REQUEST_TIME_FLOAT`)
     * @return float 시작 시각
     */
    public static function effectiveStart(bool $timerFromNow, float $now, ?float $requestStart): float
    {
        if ($timerFromNow || $requestStart === null || $requestStart > $now) {
            return $now;
        }

        return $requestStart;
    }

    /**
     * 이 요청의 실행 시간 제한을 정하고 시계를 시작합니다.
     *
     * `set_time_limit()` 은 호출 시점부터 다시 센다. 그래서 단계 수행 직전에 부른다.
     *
     * @return int 적용된 수명(초). 0 이면 제한 없음
     */
    public function begin(): int
    {
        $this->startedAt = $this->now();

        if ($this->limitOverride !== null) {
            return $this->limitSeconds = max(0, $this->limitOverride);
        }

        $before = self::effectiveLimit(0, ExecutionEnvironment::hasFunction('ini_get') ? ini_get('max_execution_time') : false);
        $rearmed = false;

        // 제한이 없으면(0) 건드리지 않는다. 있으면 요청값과 지금 값 중 큰 쪽으로 다시 건다 —
        // 더 긴 제한을 가진 서버에서 줄이지 않고, 같은 값이라도 다시 걸면 부팅에 쓴 시간이
        // 빠진 채 단계 시작부터 센다.
        if ($before > 0 && ExecutionEnvironment::hasFunction('set_time_limit')) {
            $rearmed = @set_time_limit(max($before, self::REQUESTED_SECONDS)) !== false;
        }

        // 다시 걸지 못했으면(함수 차단·실패) 서버의 시계는 요청 시작부터 돌고 있다 — 몫도 그 자리부터 센다.
        // 그러지 않으면 부팅에 쓴 시간만큼 마감이 실제 제한 뒤로 밀린다.
        $requestStart = isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT'])
            ? (float) $_SERVER['REQUEST_TIME_FLOAT']
            : null;
        $this->startedAt = self::effectiveStart($rearmed || $before <= 0, $this->startedAt, $requestStart);

        $ini = ExecutionEnvironment::hasFunction('ini_get') ? ini_get('max_execution_time') : false;
        $this->limitSeconds = self::effectiveLimit(self::REQUESTED_SECONDS, $ini);
        $this->sliceBasisSeconds = self::sliceBasis($before, $this->limitSeconds);

        return $this->limitSeconds;
    }

    /**
     * 조각의 몫을 정할 기준 수명을 고릅니다 — 늘리기 전 제한과 지금 제한 중 짧은 쪽.
     *
     * 실행 제한을 늘렸어도 서버 밖의 제한(php-fpm `request_terminate_timeout`, 프록시 응답 제한)은 PHP 가 읽을 수
     * 없고, 대개 원래 `max_execution_time` 과 같은 값으로 맞춰져 있다. 늘린 값으로 조각을 잡으면 그 제한에 끊긴다.
     * 점유 만료 창은 늘린 값(실제 수명)을 그대로 쓴다.
     *
     * @param  int  $before  늘리기 전 제한(초, 0 이면 제한 없음)
     * @param  int  $after  지금 제한(초, 0 이면 제한 없음)
     * @return int 기준 수명(초, 0 이면 제한 없음)
     */
    public static function sliceBasis(int $before, int $after): int
    {
        if ($before <= 0) {
            return max(0, $after);
        }

        if ($after <= 0) {
            return $before;
        }

        return min($before, $after);
    }

    /**
     * 적용 수명을 바꾸지 않고 읽기만 합니다 (상태 조회 요청용).
     *
     * 상태 조회는 단계를 돌리지 않으므로 제한을 올리지 않는다. 대신 「단계 요청이 받게 될
     * 수명」의 상한을 알려 준다 — `set_time_limit` 이 가용하면 요청값, 아니면 지금 ini 값.
     *
     * @return int 단계 요청의 예상 수명(초). 0 이면 제한 없음
     */
    public function probe(): int
    {
        if ($this->limitOverride !== null) {
            return max(0, $this->limitOverride);
        }

        $ini = ExecutionEnvironment::hasFunction('ini_get') ? ini_get('max_execution_time') : false;
        $current = self::effectiveLimit(self::REQUESTED_SECONDS, $ini);

        if ($current > 0 && ExecutionEnvironment::hasFunction('set_time_limit')) {
            return max($current, self::REQUESTED_SECONDS);
        }

        return $current;
    }

    /**
     * 적용된 수명(초).
     *
     * @return int 0 이면 제한 없음
     */
    public function limitSeconds(): int
    {
        return $this->limitSeconds;
    }

    /**
     * 점유 만료 창(초) — 수명 + 여유. 제한이 없으면 기본 창을 쓴다.
     *
     * @return int 초
     */
    public function claimStaleSeconds(): int
    {
        return $this->limitSeconds > 0
            ? $this->limitSeconds + self::CLAIM_GRACE_SECONDS
            : CoreUpdateRunState::CLAIM_STALE_SECONDS;
    }

    /**
     * 나눠 처리하는 작업이 이 요청에서 쓸 몫(초).
     *
     * @return float 초
     */
    public function sliceSeconds(): float
    {
        $basis = $this->sliceBasisSeconds ?? $this->limitSeconds;

        if ($basis <= 0) {
            return (float) self::MAX_SLICE_SECONDS;
        }

        return (float) max(self::MIN_SLICE_SECONDS, min(self::MAX_SLICE_SECONDS, intdiv($basis, 2)));
    }

    /**
     * 나눠 처리하는 작업이 실제로 쓸 시간(초) — 몫에서 응답 마무리 시간을 뺀 값 (최소 1초).
     *
     * @return float 초
     */
    public function workSeconds(): float
    {
        return max(1.0, $this->sliceSeconds() - self::FINISH_MARGIN_SECONDS);
    }

    /**
     * 작업 시간의 지정 비율까지 쓴 시점(초, 시계 기준).
     *
     * @param  float  $fraction  작업 시간 가운데 쓸 비율 (0~1)
     * @return float 마감 시각
     */
    public function deadline(float $fraction = 1.0): float
    {
        $start = $this->startedAt ?? $this->now();

        return $start + $this->workSeconds() * max(0.0, min(1.0, $fraction));
    }

    /**
     * 마감을 넘겼는지 판정하는 함수를 돌려줍니다 (나눠 처리하는 순회가 주기적으로 묻는다).
     *
     * @param  float  $fraction  몫 가운데 쓸 비율 (0~1)
     * @return \Closure(): bool 마감을 넘겼으면 true
     */
    public function pauser(float $fraction = 1.0): \Closure
    {
        $deadline = $this->deadline($fraction);

        return fn (): bool => $this->now() >= $deadline;
    }

    /**
     * 현재 시각(초).
     *
     * @return float 시계 값
     */
    public function now(): float
    {
        return $this->clock !== null ? (float) ($this->clock)() : microtime(true);
    }
}

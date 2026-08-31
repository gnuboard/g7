<?php

namespace App\Scheduling;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 스케줄 러너의 점검·실행 기록 (실행 창 소급 판정의 근거 — D17).
 *
 * 두 가지를 보관한다:
 *
 * - **마지막 점검 시각** — 다음 호출의 소급 창 하한. 이 창이 없으면 호출 주기가
 *   성긴 환경(외부 크론 5~15분)에서 `dailyAt` 고정 시각의 그 분을 놓쳐 정리 작업이
 *   영영 실행되지 않는다.
 * - **이벤트별 마지막 실행 시각** — 같은 소급 창 안에서 한 이벤트가 두 번 실행되는
 *   것을 막는다. 창 안에서 여러 번 due 였던 주기 이벤트도 **1회만** 실행한다.
 *
 * 점검 시각은 그 호출이 due 이벤트를 **전부** 처리했을 때만 전진한다. 실행 상한으로
 * 중단되면 남겨 두어 다음 호출의 창이 그 구간을 다시 덮게 한다 (A-5 러너 동작 4).
 *
 * 저장소는 설정 카탈로그가 아니라 전용 파일이다 — 운영자가 편집할 값이 아니고,
 * 설정 조회 응답에 실릴 이유도 없다.
 *
 * @since 7.1.0
 */
class ScheduleRunState
{
    /** 상태 파일이 놓이는 디렉토리 (프로젝트 상대). */
    public const DIRECTORY = 'app/schedule';

    /** 상태 파일 이름. */
    public const FILENAME = 'run-state.json';

    /** @var array<string, mixed>|null 지연 로드된 상태 */
    private ?array $state = null;

    /**
     * @var bool 직전 쓰기가 실패했는지 여부
     *
     * 실패는 조용한 결함을 만든다 — 점검 시각이 남지 않아 다음 호출의 소급 창이
     * 상한까지 열리고 창 안의 예약이 전부 다시 실행된다. 그 사실을 화면까지 나르기
     * 위한 플래그다.
     */
    private bool $persistFailed = false;

    /**
     * @param  string|null  $path  상태 파일 경로 (테스트 주입용, 기본: storage/app/schedule/run-state.json)
     */
    public function __construct(private ?string $path = null)
    {
        $this->path ??= storage_path(
            str_replace('/', DIRECTORY_SEPARATOR, self::DIRECTORY).DIRECTORY_SEPARATOR.self::FILENAME,
        );
    }

    /**
     * 상태 파일 경로를 반환합니다.
     *
     * @return string 절대 경로
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * 마지막 점검 시각을 반환합니다.
     *
     * @return CarbonImmutable|null 기록이 없으면 null (첫 호출)
     */
    public function lastCheckedAt(): ?CarbonImmutable
    {
        return $this->parseTime($this->read()['last_checked_at'] ?? null);
    }

    /**
     * 이벤트의 마지막 실행 시각을 반환합니다.
     *
     * @param  string  $eventKey  이벤트 식별 키
     * @return CarbonImmutable|null 실행 기록이 없으면 null
     */
    public function lastRunAt(string $eventKey): ?CarbonImmutable
    {
        return $this->parseTime($this->read()['events'][$eventKey]['last_run_at'] ?? null);
    }

    /**
     * 이벤트의 실행 완료를 기록합니다 (실행이 **끝난 뒤에만** 부른다).
     *
     * @param  string  $eventKey  이벤트 식별 키
     * @param  CarbonImmutable  $at  실행 완료 시각
     * @param  bool  $failed  실패로 끝났는지
     */
    public function recordEventRun(string $eventKey, CarbonImmutable $at, bool $failed = false): void
    {
        $state = $this->read();

        $state['events'][$eventKey] = [
            'last_run_at' => $at->toIso8601String(),
            'last_status' => $failed ? 'failed' : 'success',
        ];

        $this->write($state);
    }

    /**
     * 한 번의 호출이 끝난 뒤 요약과 점검 시각을 기록합니다.
     *
     * @param  ScheduleRunSummary  $summary  실행 요약
     * @param  bool  $advanceCheckpoint  점검 시각을 전진시킬지 (due 전량 처리 시에만 true)
     */
    public function recordRun(ScheduleRunSummary $summary, bool $advanceCheckpoint): void
    {
        $state = $this->read();

        if ($advanceCheckpoint) {
            $state['last_checked_at'] = $summary->finishedAt->toIso8601String();
        }

        $state['last_run'] = $summary->toArray();

        // 실행이 실제로 일어난 호출만 "마지막 실행" 으로 남긴다 — 최소 호출 간격에
        // 걸려 되돌아간 호출까지 여기에 실으면 예약 미실행 경고(D19)가 영영 뜨지 않는다.
        if ($summary->executed > 0) {
            $state['last_executed_at'] = $summary->finishedAt->toIso8601String();
        }

        $this->write($state);
    }

    /**
     * 최소 호출 간격 판정에 쓰는 "마지막 호출 시각" 을 기록합니다.
     *
     * 실행 여부와 무관하게 전진한다 — 이 값은 호출 빈도 제한 전용이며, 예약 실행
     * 여부(`lastExecutedAt`)와 다른 축이다.
     *
     * @param  CarbonImmutable  $at  호출 시각
     */
    public function recordInvocation(CarbonImmutable $at): void
    {
        $state = $this->read();
        $state['last_invoked_at'] = $at->toIso8601String();

        $this->write($state);
    }

    /**
     * 마지막 호출 시각 (최소 간격 판정용).
     *
     * @return CarbonImmutable|null 기록이 없으면 null
     */
    public function lastInvokedAt(): ?CarbonImmutable
    {
        return $this->parseTime($this->read()['last_invoked_at'] ?? null);
    }

    /**
     * 예약 작업이 마지막으로 **실행된** 시각 (대시보드 경고 판정용 — D19).
     *
     * @return CarbonImmutable|null 실행 기록이 없으면 null
     */
    public function lastExecutedAt(): ?CarbonImmutable
    {
        return $this->parseTime($this->read()['last_executed_at'] ?? null);
    }

    /**
     * 마지막 호출의 실행 요약을 반환합니다 (화면 "마지막 실행" 표시용).
     *
     * @return array<string, mixed>|null 요약, 기록이 없으면 null
     */
    public function lastRunSummary(): ?array
    {
        $summary = $this->read()['last_run'] ?? null;

        return is_array($summary) ? $summary : null;
    }

    /**
     * 상태를 다시 읽도록 캐시를 비웁니다 (같은 요청 안에서 파일이 바뀐 경우).
     */
    public function refresh(): void
    {
        $this->state = null;
    }

    /**
     * 상태 파일을 읽습니다 (손상·부재 시 빈 상태).
     *
     * @return array<string, mixed> 상태 배열
     */
    private function read(): array
    {
        if ($this->state !== null) {
            return $this->state;
        }

        if (! is_file($this->path)) {
            return $this->state = [];
        }

        $decoded = json_decode((string) @file_get_contents($this->path), true);

        return $this->state = is_array($decoded) ? $decoded : [];
    }

    /**
     * 상태 파일을 씁니다.
     *
     * 쓰기에 실패해도 실행을 막지는 않는다. 다만 그 결과는 「소급 창이 **좁아진다**」가
     * 아니라 정반대다 — 점검 시각과 이벤트별 실행 시각이 남지 않으므로 다음 호출의
     * 창 하한이 늘 상한(기본 24시간)으로 열리고, **창 안의 모든 예약이 매 호출 전부
     * 다시 실행된다.** 쓰기 권한이 없는 호스팅이 바로 이 안의 대상 환경이라 실제로
     * 겪을 수 있는 상태이고, 증상은 오류가 아니라 「최소 간격마다 전체 정리 배치가
     * 도는 부하」로만 나타난다.
     *
     * 그래서 실패는 경고가 아니라 **오류**로 남기고, 그 사실을 호출자가 읽어 화면까지
     * 나를 수 있도록 플래그로 보관한다 — 로그를 볼 수 없는 운영자가 대다수다.
     *
     * @param  array<string, mixed>  $state  저장할 상태
     */
    private function write(array $state): void
    {
        $this->state = $state;

        $directory = dirname($this->path);

        if (! is_dir($directory)) {
            File::ensureDirectoryExists($directory, 0755, true);
        }

        $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            $this->persistFailed = true;
            Log::error('스케줄 러너 상태를 직렬화하지 못했습니다.', ['path' => $this->path]);

            return;
        }

        if (File::put($this->path, $encoded) === false) {
            $this->persistFailed = true;
            Log::error('스케줄 러너 상태를 저장하지 못했습니다.', ['path' => $this->path]);

            return;
        }

        $this->persistFailed = false;
    }

    /**
     * 직전 쓰기가 실패했는지 알려 줍니다.
     *
     * 이 값이 참이면 다음 호출의 소급 창이 상한까지 열려 창 안의 예약이 전부 다시
     * 실행된다 — 화면이 그 사실을 운영자에게 알릴 수 있도록 노출한다.
     *
     * @return bool 직전 쓰기 실패 여부
     */
    public function persistFailed(): bool
    {
        return $this->persistFailed;
    }

    /**
     * ISO8601 문자열을 시각으로 변환합니다.
     *
     * @param  mixed  $value  저장된 값
     * @return CarbonImmutable|null 해석 실패 시 null
     */
    private function parseTime(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}

<?php

namespace App\CoreUpdate;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 웹 코어 업데이트의 진행 상태 (요청 경계를 넘는 유일한 기억).
 *
 * 한 요청이 한 단계만 수행하므로, 다음 요청이 「어디까지 했는지」를 알 방법은 이
 * 파일뿐이다. 브라우저를 닫거나 새로고침해도 이어지는 성질(W6 ③)이 여기서 나온다.
 *
 * **점유(claim)** 를 함께 보관한다. `CoreUpdateService` 의 실행 잠금(`flock`)은 프로세스
 * 수명에 묶여 있어 요청 사이를 잇지 못한다 — 그 잠금만 믿으면 더블클릭으로 뜬 두 요청이
 * 서로 다른 단계를 동시에 돌린다. 그래서 점유는 **토큰 + 심박**으로 파일에 남기고,
 * 심박이 끊긴 지 오래된 점유만 회수한다(비정상 종료 복구).
 *
 * 상태 값:
 *
 * - `running` — 다음 단계를 기다리는 중
 * - `rolling_back` — 단계가 실패해 되돌리기를 기다리는 중 (D10 — 조작 없이 자동)
 * - `rolled_back` — 되돌리기 완료, 사이트는 이전 버전으로 정상
 * - `rollback_failed` — 되돌리기까지 실패, 수동 복구 절차 안내 대상
 * - `completed` — 업데이트 완료
 *
 * @since 7.1.0
 */
class CoreUpdateRunState
{
    /** 상태 파일이 놓이는 디렉토리 (storage 상대). */
    public const DIRECTORY = 'app/core-update';

    /** 상태 파일 이름. */
    public const FILENAME = 'web-run.json';

    /** 증분 적용 목록 사이드카 파일 이름 (상태 JSON 에 싣기에는 커질 수 있다). */
    public const APPLY_LIST_FILENAME = 'web-run-apply-list.json';

    /** 점유 심박이 이 초를 넘겨 끊기면 비정상 종료로 보고 회수한다. */
    public const CLAIM_STALE_SECONDS = 900;

    /** 진행 기록으로 보관하는 최대 줄 수 (오래된 줄부터 버린다). */
    public const MAX_LOG_LINES = 500;

    public const STATUS_RUNNING = 'running';

    public const STATUS_ROLLING_BACK = 'rolling_back';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    public const STATUS_ROLLBACK_FAILED = 'rollback_failed';

    public const STATUS_COMPLETED = 'completed';

    /** @var array<string, mixed>|null 지연 로드된 상태 */
    private ?array $state = null;

    /** @var bool 직전 쓰기가 실패했는지 */
    private bool $persistFailed = false;

    /**
     * @param  string|null  $path  상태 파일 경로 (테스트 주입용)
     */
    public function __construct(private ?string $path = null)
    {
        $this->path ??= storage_path(
            str_replace('/', DIRECTORY_SEPARATOR, self::DIRECTORY).DIRECTORY_SEPARATOR.self::FILENAME,
        );
    }

    /**
     * 상태 파일 경로.
     *
     * @return string 절대 경로
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * 증분 적용 목록 사이드카 경로.
     *
     * @return string 절대 경로
     */
    public function applyListPath(): string
    {
        return dirname($this->path).DIRECTORY_SEPARATOR.self::APPLY_LIST_FILENAME;
    }

    /**
     * 진행 중이거나 마무리를 기다리는 실행이 있는지 여부.
     *
     * @return bool 실행 존재 여부
     */
    public function exists(): bool
    {
        return $this->read() !== [];
    }

    /**
     * 전체 상태 배열을 반환합니다.
     *
     * @return array<string, mixed> 상태 (없으면 빈 배열)
     */
    public function all(): array
    {
        return $this->read();
    }

    /**
     * 실행 식별자.
     *
     * @return string|null 없으면 null
     */
    public function runId(): ?string
    {
        $id = $this->read()['run_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * 현재 상태 값.
     *
     * @return string|null `running`·`rolling_back`·`rolled_back`·`rollback_failed`·`completed`
     */
    public function status(): ?string
    {
        $status = $this->read()['status'] ?? null;

        return is_string($status) ? $status : null;
    }

    /**
     * 아직 더 진행할 것이 남았는지 여부 (다음 요청이 필요한 상태).
     *
     * @return bool 진행 필요 여부
     */
    public function isActive(): bool
    {
        return in_array($this->status(), [self::STATUS_RUNNING, self::STATUS_ROLLING_BACK], true);
    }

    /**
     * 다음에 수행할 단계.
     *
     * @return CoreUpdateStep|null 없으면 null (완료·되돌리기 대기)
     */
    public function nextStep(): ?CoreUpdateStep
    {
        return CoreUpdateStep::tryFromValue($this->read()['next_step'] ?? null);
    }

    /**
     * 완료된 단계 목록.
     *
     * @return list<string> 단계 값
     */
    public function completedSteps(): array
    {
        $steps = $this->read()['completed_steps'] ?? [];

        return is_array($steps) ? array_values(array_filter($steps, 'is_string')) : [];
    }

    /**
     * 실행 문맥(경로·스냅샷 등)의 값을 읽습니다.
     *
     * @param  string  $key  문맥 키
     * @param  mixed  $default  기본값
     * @return mixed 저장된 값
     */
    public function context(string $key, mixed $default = null): mixed
    {
        $context = $this->read()['context'] ?? [];

        return is_array($context) ? ($context[$key] ?? $default) : $default;
    }

    /**
     * 착수 선택을 복원합니다.
     *
     * @return CoreUpdateOptions 선택
     */
    public function options(): CoreUpdateOptions
    {
        $options = $this->read()['options'] ?? null;

        return CoreUpdateOptions::fromArray(is_array($options) ? $options : null);
    }

    /**
     * 새 실행을 시작합니다 (기존 상태를 대체).
     *
     * @param  CoreUpdateOptions  $options  착수 선택
     * @param  string  $fromVersion  현재 버전
     * @param  string|null  $toVersion  대상 버전 (ZIP 은 추출 후 판별하므로 null 가능)
     * @param  int|null  $userId  착수한 관리자 ID
     * @return string 실행 식별자
     */
    public function start(CoreUpdateOptions $options, string $fromVersion, ?string $toVersion, ?int $userId): string
    {
        $runId = (string) Str::uuid();
        $now = CarbonImmutable::now()->toIso8601String();

        $this->storeApplyList(null);

        $this->write([
            'run_id' => $runId,
            'status' => self::STATUS_RUNNING,
            'from_version' => $fromVersion,
            'to_version' => $toVersion,
            'options' => $options->toArray(),
            'next_step' => CoreUpdateStep::sequence()[0]->value,
            'completed_steps' => [],
            'context' => [],
            'logs' => [],
            'warnings' => [],
            'notices' => [],
            'error' => null,
            'rollback' => null,
            'started_by' => $userId,
            'started_at' => $now,
            'updated_at' => $now,
            'claim' => null,
        ]);

        return $runId;
    }

    /**
     * 상태의 일부를 갱신합니다 (얕은 병합 + `updated_at` 갱신).
     *
     * @param  array<string, mixed>  $changes  변경할 최상위 키
     */
    public function patch(array $changes): void
    {
        $state = $this->read();

        if ($state === []) {
            return;
        }

        $this->write(array_merge($state, $changes, [
            'updated_at' => CarbonImmutable::now()->toIso8601String(),
        ]));
    }

    /**
     * 실행 문맥에 값을 병합합니다.
     *
     * @param  array<string, mixed>  $values  문맥 키/값
     */
    public function mergeContext(array $values): void
    {
        $state = $this->read();

        if ($state === []) {
            return;
        }

        $context = is_array($state['context'] ?? null) ? $state['context'] : [];

        $this->patch(['context' => array_merge($context, $values)]);
    }

    /**
     * 진행 기록에 줄을 덧붙입니다.
     *
     * @param  string  $message  기록 내용
     */
    public function appendLog(string $message): void
    {
        $state = $this->read();

        if ($state === []) {
            return;
        }

        $logs = is_array($state['logs'] ?? null) ? $state['logs'] : [];
        $logs[] = '['.CarbonImmutable::now()->format('H:i:s').'] '.$message;

        if (count($logs) > self::MAX_LOG_LINES) {
            $logs = array_slice($logs, -self::MAX_LOG_LINES);
        }

        $this->patch(['logs' => $logs]);
    }

    /**
     * 운영자에게 보일 경고를 덧붙입니다 (중복 제거).
     *
     * @param  array<int, string>  $messages  경고 문구
     */
    public function appendWarnings(array $messages): void
    {
        $this->appendMessages('warnings', $messages);
    }

    /**
     * 운영자에게 보일 통보를 덧붙입니다 (실패는 아니지만 알아야 하는 사실).
     *
     * @param  array<int, string>  $messages  통보 문구
     */
    public function appendNotices(array $messages): void
    {
        $this->appendMessages('notices', $messages);
    }

    /**
     * 진행 기록을 반환합니다.
     *
     * @return list<string> 기록 줄
     */
    public function logs(): array
    {
        $logs = $this->read()['logs'] ?? [];

        return is_array($logs) ? array_values(array_filter($logs, 'is_string')) : [];
    }

    /**
     * 증분 적용 목록을 사이드카 파일에 씁니다.
     *
     * 상태 JSON 에 싣지 않는 이유는 크기다 — 메이저 업데이트의 적용 목록은 수천 줄이
     * 될 수 있고, 그 배열이 매 단계마다 다시 직렬화되면 진행 자체가 느려진다.
     *
     * @param  list<string>|null  $applyList  적용 대상 상대 경로 (null 이면 파일 삭제)
     */
    public function storeApplyList(?array $applyList): void
    {
        $path = $this->applyListPath();

        if ($applyList === null) {
            if (is_file($path)) {
                @unlink($path);
            }

            return;
        }

        File::ensureDirectoryExists(dirname($path), 0755, true);
        File::put($path, (string) json_encode(array_values($applyList), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 증분 적용 목록을 읽습니다.
     *
     * @return list<string>|null 없으면 null (= 전체 덮어쓰기로 회귀)
     */
    public function loadApplyList(): ?array
    {
        $path = $this->applyListPath();

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : null;
    }

    /**
     * 실행 점유를 시도합니다.
     *
     * 같은 토큰이 이미 보유 중이면 재획득으로 본다 — 브라우저가 순차 호출하는 정상
     * 경로다. 다른 토큰이 보유 중이면 심박이 끊긴 지 오래됐을 때만 회수한다.
     *
     * @param  string  $token  이 요청의 점유 토큰
     * @return bool 점유 성공 여부
     */
    public function claim(string $token): bool
    {
        $state = $this->read();

        if ($state === []) {
            return false;
        }

        $claim = is_array($state['claim'] ?? null) ? $state['claim'] : null;

        if ($claim !== null && ($claim['token'] ?? null) !== $token) {
            $heartbeat = $this->parseTime($claim['heartbeat_at'] ?? null);

            // 심박 시각을 읽을 수 없으면 **보유 중으로 본다** — 판정 불능을 「오래됨」으로
            // 접으면 진행 중인 다른 요청의 점유를 빼앗는다 (fail-closed).
            if ($heartbeat === null) {
                return false;
            }

            if ($heartbeat->diffInSeconds(CarbonImmutable::now(), true) < self::CLAIM_STALE_SECONDS) {
                return false;
            }
        }

        $this->patch(['claim' => [
            'token' => $token,
            'heartbeat_at' => CarbonImmutable::now()->toIso8601String(),
        ]]);

        return true;
    }

    /**
     * 점유를 놓습니다.
     *
     * @param  string  $token  이 요청의 점유 토큰
     */
    public function releaseClaim(string $token): void
    {
        $state = $this->read();

        if ($state === []) {
            return;
        }

        $claim = is_array($state['claim'] ?? null) ? $state['claim'] : null;

        if ($claim === null || ($claim['token'] ?? null) !== $token) {
            return;
        }

        $this->patch(['claim' => null]);
    }

    /**
     * 실행 기록을 지웁니다 (완료·되돌리기 확인 후).
     */
    public function clear(): void
    {
        $this->state = [];

        foreach ([$this->path, $this->applyListPath()] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * 다시 읽도록 캐시를 비웁니다.
     */
    public function refresh(): void
    {
        $this->state = null;
    }

    /**
     * 직전 쓰기가 실패했는지 알려 줍니다.
     *
     * 실패하면 다음 요청이 진행 지점을 잃는다 — 화면이 그 사실을 말할 수 있어야 한다.
     *
     * @return bool 쓰기 실패 여부
     */
    public function persistFailed(): bool
    {
        return $this->persistFailed;
    }

    /**
     * 문자열 목록을 중복 없이 덧붙입니다.
     *
     * @param  string  $key  상태 키
     * @param  array<int, string>  $messages  문구
     */
    private function appendMessages(string $key, array $messages): void
    {
        $messages = array_values(array_filter($messages, static fn ($m): bool => is_string($m) && $m !== ''));

        if ($messages === []) {
            return;
        }

        $state = $this->read();

        if ($state === []) {
            return;
        }

        $existing = is_array($state[$key] ?? null) ? $state[$key] : [];

        $this->patch([$key => array_values(array_unique(array_merge($existing, $messages)))]);
    }

    /**
     * 상태 파일을 읽습니다 (부재·손상 시 빈 상태).
     *
     * @return array<string, mixed> 상태
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

        if ($encoded === false || File::put($this->path, $encoded) === false) {
            $this->persistFailed = true;
            Log::error('웹 코어 업데이트 진행 상태를 저장하지 못했습니다.', ['path' => $this->path]);

            return;
        }

        $this->persistFailed = false;
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

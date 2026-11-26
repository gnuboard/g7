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

    /** 적용 대상 산출 진행분 사이드카 파일 이름 (나눠 처리하는 단계의 중간 결과). */
    public const PLAN_PROGRESS_FILENAME = 'web-run-plan-progress.json';

    /** 이번 배포본의 번들 출하 목록 사이드카 파일 이름 (적용 단계가 사이트 목록과 대조한다) */
    public const SHIPPED_FILES_FILENAME = 'web-run.shipped.json';

    /**
     * 점유가 만료 창을 선언하지 않았을 때(구 상태 파일, 실행 제한 없음) 쓰는 기본 창.
     *
     * 단계 요청은 자기 요청 수명 + 여유를 점유에 선언한다(`CoreUpdateTimeBudget`). 이 값은
     * 그 선언이 없을 때만 쓴다 — 고정 15분을 모든 점유에 적용하면, 300초 제한에 죽은
     * 요청의 점유가 10분 넘게 남아 그동안 사이트가 점검 모드에 갇힌다.
     */
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

    /** @var array<string, mixed>|null 직전 `claim()` 이 만료로 회수한 이전 점유 */
    private ?array $reclaimed = null;

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
     * 이번 배포본의 번들 출하 목록 사이드카 경로.
     *
     * @return string 절대 경로
     */
    public function shippedFilesPath(): string
    {
        return dirname($this->path).DIRECTORY_SEPARATOR.self::SHIPPED_FILES_FILENAME;
    }

    /**
     * 번들 항목 자식 프로세스의 새 표식 경로 (실행 상태 디렉토리 안, 항목마다 다른 이름).
     *
     * @return string 절대 경로
     */
    public function newBundledChildMarkerPath(): string
    {
        return dirname($this->path).DIRECTORY_SEPARATOR.'bundled-child_'.bin2hex(random_bytes(8)).'.lock';
    }

    /**
     * 이번 배포본의 번들 출하 목록을 씁니다 (상태 JSON 에 싣기엔 크다 — 적용 목록과 같은 이유).
     *
     * @param  array<string, string>|null  $files  경로 → md5 (null 이면 파일 삭제)
     * @return void 반환값 없음
     */
    public function storeShippedFiles(?array $files): void
    {
        $path = $this->shippedFilesPath();

        if ($files === null) {
            if (is_file($path)) {
                @unlink($path);
            }

            return;
        }

        File::ensureDirectoryExists(dirname($path), 0775, true);
        $this->writeAtomically($path, (string) json_encode($files, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 이번 배포본의 번들 출하 목록을 읽습니다.
     *
     * @return array<string, string>|null 없으면 null (= 출하 목록 대조 없이 적용)
     */
    public function loadShippedFiles(): ?array
    {
        $path = $this->shippedFilesPath();

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? array_filter($decoded, static fn ($hash, $key): bool => is_string($key) && is_string($hash), ARRAY_FILTER_USE_BOTH) : null;
    }

    /**
     * 적용 대상 산출 진행분 사이드카 경로.
     *
     * @return string 절대 경로
     */
    public function planProgressPath(): string
    {
        return dirname($this->path).DIRECTORY_SEPARATOR.self::PLAN_PROGRESS_FILENAME;
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
        $this->storePlanProgress(null);

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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
     */
    public function appendLog(string $message): void
    {
        $state = $this->read();

        if ($state === []) {
            return;
        }

        $logs = is_array($state['logs'] ?? null) ? $state['logs'] : [];
        // 누적 순번 — 줄 상한에서 앞쪽이 잘려도 계속 는다(화면이 같은 단계 안의 진행을 판정하는 근거).
        $sequence = (int) ($state['log_seq'] ?? count($logs)) + 1;
        $logs[] = '['.CarbonImmutable::now()->format('H:i:s').'] '.$message;

        if (count($logs) > self::MAX_LOG_LINES) {
            $logs = array_slice($logs, -self::MAX_LOG_LINES);
        }

        $this->patch(['logs' => $logs, 'log_seq' => $sequence]);
    }

    /**
     * 진행 기록의 누적 순번 — 지금까지 남긴 줄 수(줄 상한으로 잘린 줄 포함).
     *
     * @return int 순번
     */
    public function logSequence(): int
    {
        $state = $this->read();

        return (int) ($state['log_seq'] ?? count(is_array($state['logs'] ?? null) ? $state['logs'] : []));
    }

    /**
     * 운영자에게 보일 경고를 덧붙입니다 (중복 제거).
     *
     * @param  array<int, string>  $messages  경고 문구
     * @return void 반환값 없음
     */
    public function appendWarnings(array $messages): void
    {
        $this->appendMessages('warnings', $messages);
    }

    /**
     * 더는 사실이 아닌 경고를 걷습니다 (예: 되돌리기가 원래대로 돌려놓은 파일에 대한 적용 단계 안내).
     *
     * 같은 문구의 경고만 지운다 — 다른 단계가 낸 경고는 그대로 둔다.
     *
     * @param  array<int, string>  $messages  걷을 경고 문구
     * @return int 실제로 걷은 경고 수
     */
    public function withdrawWarnings(array $messages): int
    {
        $messages = array_values(array_filter($messages, static fn ($m): bool => is_string($m) && $m !== ''));
        $state = $this->read();

        if ($messages === [] || $state === []) {
            return 0;
        }

        $existing = is_array($state['warnings'] ?? null) ? array_values($state['warnings']) : [];
        $kept = array_values(array_filter($existing, static fn ($w): bool => ! in_array($w, $messages, true)));
        $withdrawn = count($existing) - count($kept);

        if ($withdrawn > 0) {
            $this->patch(['warnings' => $kept]);
        }

        return $withdrawn;
    }

    /**
     * 운영자에게 보일 통보를 덧붙입니다 (실패는 아니지만 알아야 하는 사실).
     *
     * @param  array<int, string>  $messages  통보 문구
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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

        File::ensureDirectoryExists(dirname($path), 0775, true);
        $this->writeAtomically($path, (string) json_encode(array_values($applyList), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
     * 적용 대상 산출의 진행분을 씁니다 (나눠 처리하는 동안의 커서와 중간 결과).
     *
     * @param  array<string, mixed>|null  $progress  진행분 (null 이면 파일 삭제)
     * @return void 반환값 없음
     */
    public function storePlanProgress(?array $progress): void
    {
        $path = $this->planProgressPath();

        if ($progress === null) {
            if (is_file($path)) {
                @unlink($path);
            }

            return;
        }

        File::ensureDirectoryExists(dirname($path), 0775, true);
        $this->writeAtomically($path, (string) json_encode($progress, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 적용 대상 산출의 진행분을 읽습니다.
     *
     * @return array<string, mixed>|null 없거나 읽을 수 없으면 null (처음부터 산출)
     */
    public function loadPlanProgress(): ?array
    {
        $path = $this->planProgressPath();

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 실행 점유를 시도합니다.
     *
     * 같은 토큰이 이미 보유 중이면 재획득으로 본다 — 브라우저가 순차 호출하는 정상
     * 경로다. 다른 토큰이 보유 중이면 그 점유가 **스스로 선언한 만료 창**을 넘겼을 때만
     * 회수한다. 창을 선언하지 않은 점유(구 상태 파일)는 `CLAIM_STALE_SECONDS` 를 쓴다.
     *
     * 회수한 이전 점유는 `lastReclaimedClaim()` 으로 읽는다 — 그 점유가 단계를 돌리던
     * 중이었다면 그 요청은 실패도 성공도 기록하지 못하고 죽은 것이다.
     *
     * @param  string  $token  이 요청의 점유 토큰
     * @param  string|null  $step  이 요청이 수행할 단계 값 (되돌리기는 `rollback`)
     * @param  int|null  $staleAfterSeconds  이 점유의 만료 창(초) — 요청 수명 + 여유
     * @return bool 점유 성공 여부
     */
    public function claim(string $token, ?string $step = null, ?int $staleAfterSeconds = null): bool
    {
        $this->reclaimed = null;
        $state = $this->read();

        if ($state === []) {
            return false;
        }

        $claim = is_array($state['claim'] ?? null) ? $state['claim'] : null;

        if ($claim !== null && ($claim['token'] ?? null) !== $token) {
            if (! $this->claimIsStale($claim)) {
                return false;
            }

            $this->reclaimed = $claim;
        }

        $now = CarbonImmutable::now()->toIso8601String();

        $this->patch(['claim' => [
            'token' => $token,
            'heartbeat_at' => $now,
            'started_at' => $now,
            'step' => $step,
            'stale_after' => $staleAfterSeconds !== null && $staleAfterSeconds > 0 ? $staleAfterSeconds : null,
        ]]);

        return true;
    }

    /**
     * 직전 `claim()` 이 만료로 회수한 이전 점유.
     *
     * @return array<string, mixed>|null 회수하지 않았으면 null
     */
    public function lastReclaimedClaim(): ?array
    {
        return $this->reclaimed;
    }

    /**
     * 지금 점유 상태를 화면용으로 요약합니다.
     *
     * 화면은 이 값으로 「서버가 아직 그 단계를 돌리는 중」인지 판정해, 응답이 끊긴 뒤에도
     * 같은 단계를 겹쳐 부르지 않고 점유가 풀릴 때까지 기다린다.
     *
     * @return array{held: bool, step: string|null, started_at: string|null, stale_after: int}|null 점유가 없으면 null
     */
    public function claimSummary(): ?array
    {
        $claim = $this->read()['claim'] ?? null;

        if (! is_array($claim)) {
            return null;
        }

        return [
            'held' => ! $this->claimIsStale($claim),
            'step' => is_string($claim['step'] ?? null) ? $claim['step'] : null,
            'started_at' => is_string($claim['started_at'] ?? null) ? $claim['started_at'] : ($claim['heartbeat_at'] ?? null),
            'stale_after' => $this->claimStaleSeconds($claim),
        ];
    }

    /**
     * 점유 토큰이 지금 보유자인지 확인합니다.
     *
     * @param  string  $token  점유 토큰
     * @return bool 보유 중이면 true
     */
    public function holdsClaim(string $token): bool
    {
        $claim = $this->read()['claim'] ?? null;

        return is_array($claim) && ($claim['token'] ?? null) === $token;
    }

    /**
     * 점유가 만료 창을 넘겼는지 판정합니다.
     *
     * 심박 시각을 읽을 수 없으면 **보유 중으로 본다** — 판정 불능을 「오래됨」으로 접으면
     * 진행 중인 다른 요청의 점유를 빼앗는다 (fail-closed).
     *
     * @param  array<string, mixed>  $claim  점유 기록
     * @return bool 만료됐으면 true
     */
    private function claimIsStale(array $claim): bool
    {
        $heartbeat = $this->parseTime($claim['heartbeat_at'] ?? null);

        if ($heartbeat === null) {
            return false;
        }

        return $heartbeat->diffInSeconds(CarbonImmutable::now(), true) >= $this->claimStaleSeconds($claim);
    }

    /**
     * 점유의 만료 창(초).
     *
     * @param  array<string, mixed>  $claim  점유 기록
     * @return int 초
     */
    private function claimStaleSeconds(array $claim): int
    {
        $declared = $claim['stale_after'] ?? null;

        return is_int($declared) && $declared > 0 ? $declared : self::CLAIM_STALE_SECONDS;
    }

    /**
     * 점유를 놓습니다.
     *
     * @param  string  $token  이 요청의 점유 토큰
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
     */
    public function clear(): void
    {
        $this->state = [];

        foreach ([$this->path, $this->applyListPath(), $this->planProgressPath(), $this->shippedFilesPath()] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }

            // 교체 직전에 끊긴 요청이 남긴 임시 파일도 함께 걷는다.
            foreach (glob($path.'.tmp.*') ?: [] as $leftover) {
                @unlink($leftover);
            }
        }

        // 번들 항목 자식 표식 — 실행이 끝났으면 더 볼 일이 없다.
        foreach (glob(dirname($this->path).DIRECTORY_SEPARATOR.'bundled-child_*') ?: [] as $marker) {
            @unlink($marker);
        }
    }

    /**
     * 다시 읽도록 캐시를 비웁니다.
     *
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
     */
    private function write(array $state): void
    {
        $this->state = $state;

        $directory = dirname($this->path);

        if (! is_dir($directory)) {
            File::ensureDirectoryExists($directory, 0775, true);
        }

        $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false || ! $this->writeAtomically($this->path, $encoded)) {
            $this->persistFailed = true;
            Log::error('웹 코어 업데이트 진행 상태를 저장하지 못했습니다.', ['path' => $this->path]);

            return;
        }

        $this->persistFailed = false;
    }

    /**
     * 파일을 원자적으로 교체합니다 — 같은 폴더의 임시 파일에 다 쓴 뒤 이름을 바꾼다.
     *
     * 직접 덮어쓰면 쓰는 도중에 요청이 끊길 때(시간 초과·프로세스 종료) 반쪽 JSON 이 남고, 다음 요청이 그것을 읽지
     * 못해 진행 지점(나눠 이어 가던 커서)을 잃는다. 이름 바꾸기는 같은 폴더 안에서 한 번에 일어나므로 읽는 쪽은 언제나
     * 이전 내용이나 새 내용 전체를 본다. 부분 쓰기(디스크 부족)는 실패로 본다.
     *
     * Windows 는 다른 요청이 같은 파일을 읽는 순간 이름 바꾸기가 거부될 수 있어 잠시 뒤 다시 시도하고, 그래도 안 되면
     * 직접 덮어쓴다 — 진행 지점을 아예 못 남기는 것보다 낫다.
     *
     * @param  string  $path  대상 파일
     * @param  string  $content  전체 내용
     * @return bool 저장했으면 true
     */
    private function writeAtomically(string $path, string $content): bool
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            File::ensureDirectoryExists($directory, 0775, true);
        }

        $tempPath = $path.'.tmp.'.bin2hex(random_bytes(6));
        $written = @file_put_contents($tempPath, $content);

        if ($written === false || $written !== strlen($content)) {
            @unlink($tempPath);

            return false;
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($this->replaceFile($tempPath, $path)) {
                return true;
            }

            usleep(50_000);
        }

        @unlink($tempPath);

        return File::put($path, $content) === strlen($content);
    }

    /**
     * 임시 파일을 대상 자리로 옮깁니다 (원자적 교체의 마지막 한 걸음 — 시험이 끊김을 흉내 내려고 바꿔 끼운다).
     *
     * @param  string  $tempPath  임시 파일
     * @param  string  $path  대상 파일
     * @return bool 옮겼으면 true
     */
    protected function replaceFile(string $tempPath, string $path): bool
    {
        return @rename($tempPath, $path);
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

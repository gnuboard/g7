<?php

namespace App\Services;

use App\Contracts\Repositories\JobQueueRepositoryInterface;
use App\Contracts\Repositories\QueueJobLogRepositoryInterface;
use App\Extension\HookManager;
use App\Models\FailedJob;
use App\Models\QueueJobLog;
use App\Services\Queue\QueueWorkerRegistry;
use App\Support\Query\BoundedPage;
use App\Support\Queue\ThrottledQueueWarning;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Queue\Events\JobRetryRequested;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\PrunableFailedJobProvider;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 큐 운영 서비스 — 대기·실패·처리 기록 조회, 실패 작업 재시도·삭제, 보관 기간 정리 (#17)
 *
 * 재시도는 `Artisan::call('queue:retry')` 를 쓰지 않고 프레임워크 RetryCommand 의 절차를 그대로 옮긴다
 * (failer 조회 → JobRetryRequested → attempts·retryUntil 초기화 → pushRaw → forget). 다른 점 둘:
 *
 * 1. 원래 연결·큐를 QueueWorkerRegistry::resolve() 로 다시 해석한다. 워커가 보지 않는 연결·큐로 되넣으면
 *    그 작업은 영영 처리되지 않는다(#780). 폴백하면 사례를 남긴다.
 * 2. 해석된 연결이 sync·null 이면 거부한다 — SyncQueue::pushRaw() 는 아무것도 하지 않으므로 그대로 두면
 *    작업은 사라지고 실패 기록만 지워진다.
 *
 * 결과는 건별로 돌려준다(`{uuid, ok, message}`). 일괄 요청의 상한(100)은 FormRequest 가 건다.
 */
class JobQueueService
{
    /** 대기 건수를 셀 수 있는 드라이버 */
    private const MEASURABLE_DRIVER = 'database';

    /** 워커 없이 요청 안에서 끝나는(되넣을 곳이 없는) 드라이버 */
    private const NON_WORKER_DRIVERS = ['sync', 'null'];

    /** 처리 기록·실패 작업 보관 기간 기본값·범위(일) — 설정 drivers.queue_record_retention_days */
    public const RETENTION_DEFAULT_DAYS = 7;

    public const RETENTION_MIN_DAYS = 1;

    public const RETENTION_MAX_DAYS = 90;

    /**
     * @param  JobQueueRepositoryInterface  $jobQueueRepository  대기·실패 표 조회
     * @param  QueueJobLogRepositoryInterface  $queueJobLogRepository  처리 기록 조회·정리
     * @param  QueueWorkerRegistry  $registry  큐 워커 등록 목록 (연결·큐 해석, 상태 보고)
     * @param  QueueFactory  $queue  큐 연결 팩토리 (재시도 pushRaw)
     * @param  FailedJobProviderInterface  $failer  실패 작업 저장소 (재시도·삭제·정리)
     * @param  Dispatcher  $events  이벤트 디스패처 (JobRetryRequested)
     */
    public function __construct(
        private readonly JobQueueRepositoryInterface $jobQueueRepository,
        private readonly QueueJobLogRepositoryInterface $queueJobLogRepository,
        private readonly QueueWorkerRegistry $registry,
        private readonly QueueFactory $queue,
        private readonly FailedJobProviderInterface $failer,
        private readonly Dispatcher $events,
    ) {}

    /**
     * 큐 요약을 반환합니다 (스케줄 상태 카드가 그대로 쓴다).
     *
     * @return array{driver: string|null, connection: string, measurable: bool, pending_count: int|null, failed_count: int, workers: array<string, mixed>} 요약
     */
    public function summary(): array
    {
        return $this->buildSummary($this->registry->statusReport());
    }

    /**
     * 큐 탭 상태를 반환합니다 — 요약 + 워커 상태 보고서 전문 + 등록된 연결·큐.
     *
     * @return array<string, mixed> 상태 (summary 키 + report·entries·connections)
     */
    public function status(): array
    {
        $report = $this->registry->statusReport();

        return [
            ...$this->buildSummary($report),
            'report' => $report,
            'entries' => $this->registry->entries(),
            'connections' => $this->registry->registeredConnections(),
        ];
    }

    /**
     * 대기 작업 한 페이지를 조회합니다.
     *
     * 대기 건수를 셀 수 있는 것은 database 드라이버뿐이다. 그 밖의 드라이버(redis·sqs 등)는 빈 페이지와
     * `measurable=false` 를 돌려주고 화면이 안내 문구를 띄운다.
     *
     * @param  array<string, mixed>  $filters  검증된 필터 (connection 이 없으면 기본 연결)
     * @return array{page: BoundedPage|LengthAwarePaginator, measurable: bool, connection: string, driver: string|null} 조회 결과
     */
    public function paginatePending(array $filters): array
    {
        $perPage = (int) ($filters['per_page'] ?? 20);
        $connection = ! empty($filters['connection']) && $filters['connection'] !== 'all'
            ? (string) $filters['connection']
            : $this->registry->defaultConnection();
        $driver = $this->registry->driverOf($connection);

        if ($driver !== self::MEASURABLE_DRIVER) {
            return [
                'page' => new LengthAwarePaginator([], 0, $perPage, 1),
                'measurable' => false,
                'connection' => $connection,
                'driver' => $driver,
            ];
        }

        return [
            'page' => $this->jobQueueRepository->paginatePending(
                $this->databaseOf($connection),
                $this->tableOf($connection),
                $filters,
                $perPage,
            ),
            'measurable' => true,
            'connection' => $connection,
            'driver' => $driver,
        ];
    }

    /**
     * 실패 작업 한 페이지를 조회합니다.
     *
     * @param  array<string, mixed>  $filters  검증된 필터
     * @return BoundedPage 실패 작업 페이지
     */
    public function paginateFailed(array $filters): BoundedPage
    {
        return $this->jobQueueRepository->paginateFailed($filters, (int) ($filters['per_page'] ?? 20));
    }

    /**
     * 실패 작업 1건을 찾습니다.
     *
     * @param  string  $uuid  failed_jobs.uuid
     * @return FailedJob|null 실패 작업 (없으면 null)
     */
    public function findFailed(string $uuid): ?FailedJob
    {
        return $this->jobQueueRepository->findFailedByUuid($uuid);
    }

    /**
     * 처리 기록 한 페이지를 조회합니다.
     *
     * @param  array<string, mixed>  $filters  검증된 필터
     * @return BoundedPage 처리 기록 페이지
     */
    public function paginateLogs(array $filters): BoundedPage
    {
        return $this->queueJobLogRepository->paginate($filters, (int) ($filters['per_page'] ?? 20));
    }

    /**
     * 처리 기록 1건을 찾습니다.
     *
     * @param  int  $id  처리 기록 ID
     * @return QueueJobLog|null 처리 기록 (없으면 null)
     */
    public function findLog(int $id): ?QueueJobLog
    {
        return $this->queueJobLogRepository->find($id);
    }

    /**
     * 실패 작업 1건을 다시 큐에 넣습니다.
     *
     * @param  string  $uuid  failed_jobs.uuid
     * @return array{uuid: string, ok: bool, message: string, connection?: string|null, queue?: string|null} 결과
     */
    public function retry(string $uuid): array
    {
        HookManager::doAction('core.queue_job.before_retry', $uuid);

        $result = $this->performRetry($uuid);

        HookManager::doAction('core.queue_job.after_retry', $uuid, $result);

        return $result;
    }

    /**
     * 실패 작업 여러 건을 다시 큐에 넣습니다. 한 건이 실패해도 나머지는 계속한다.
     *
     * @param  list<string>  $uuids  failed_jobs.uuid 목록 (상한은 FormRequest)
     * @return list<array{uuid: string, ok: bool, message: string}> 건별 결과
     */
    public function retryMany(array $uuids): array
    {
        HookManager::doAction('core.queue_job.before_retry_many', $uuids);

        $results = array_map(fn (string $uuid): array => $this->performRetry($uuid), array_values($uuids));

        HookManager::doAction('core.queue_job.after_retry_many', $uuids, $results);

        return $results;
    }

    /**
     * 실패 작업 1건을 지웁니다.
     *
     * @param  string  $uuid  failed_jobs.uuid
     * @return array{uuid: string, ok: bool, message: string} 결과
     */
    public function deleteFailed(string $uuid): array
    {
        HookManager::doAction('core.queue_job.before_delete', $uuid);

        $result = $this->performDelete($uuid);

        HookManager::doAction('core.queue_job.after_delete', $uuid, $result);

        return $result;
    }

    /**
     * 실패 작업 여러 건을 지웁니다.
     *
     * @param  list<string>  $uuids  failed_jobs.uuid 목록 (상한은 FormRequest)
     * @return list<array{uuid: string, ok: bool, message: string}> 건별 결과
     */
    public function deleteFailedMany(array $uuids): array
    {
        HookManager::doAction('core.queue_job.before_delete_many', $uuids);

        $results = array_map(fn (string $uuid): array => $this->performDelete($uuid), array_values($uuids));

        HookManager::doAction('core.queue_job.after_delete_many', $uuids, $results);

        return $results;
    }

    /**
     * 처리 기록·실패 작업 보관 기간(일)을 반환합니다 (설정 drivers.queue_record_retention_days).
     *
     * @return int 보관 기간 (1~90, 미설정·범위 밖이면 기본 7)
     */
    public function retentionDays(): int
    {
        $days = g7_core_settings('drivers.queue_record_retention_days', self::RETENTION_DEFAULT_DAYS);

        return is_numeric($days) && (int) $days >= self::RETENTION_MIN_DAYS && (int) $days <= self::RETENTION_MAX_DAYS
            ? (int) $days
            : self::RETENTION_DEFAULT_DAYS;
    }

    /**
     * 보관 기간이 지난 처리 기록과 실패 작업을 지웁니다 (queue:prune-records).
     *
     * @param  int|null  $days  보관 기간(일) — null 이면 설정값
     * @return array{days: int, before: string, logs: int, failed: int} 정리 결과
     */
    public function pruneRecords(?int $days = null): array
    {
        $days ??= $this->retentionDays();
        $before = CarbonImmutable::now()->subDays($days);

        HookManager::doAction('core.queue_job.before_prune', $days);

        $logs = $this->queueJobLogRepository->deleteOlderThan($before);
        // failer 가 정리를 지원하지 않는 드라이버(null 등)면 건너뛴다.
        $failed = $this->failer instanceof PrunableFailedJobProvider ? (int) $this->failer->prune($before) : 0;

        $result = ['days' => $days, 'before' => $before->toIso8601String(), 'logs' => $logs, 'failed' => $failed];

        HookManager::doAction('core.queue_job.after_prune', $days, $result);

        return $result;
    }

    /**
     * 워커 상태 보고서로 요약을 만듭니다.
     *
     * @param  array<string, mixed>  $report  QueueWorkerRegistry::statusReport()
     * @return array{driver: string|null, connection: string, measurable: bool, pending_count: int|null, failed_count: int, workers: array<string, mixed>} 요약
     */
    private function buildSummary(array $report): array
    {
        $connection = $this->registry->defaultConnection();
        $driver = $this->registry->driverOf($connection);
        $measurable = $driver === self::MEASURABLE_DRIVER;
        $rows = is_array($report['rows'] ?? null) ? $report['rows'] : [];
        $alive = count(array_filter($rows, fn (array $row): bool => ($row['warnings'] ?? []) === []));

        return [
            'driver' => $driver,
            'connection' => $connection,
            'measurable' => $measurable,
            'pending_count' => $measurable
                ? $this->jobQueueRepository->countPending($this->databaseOf($connection), $this->tableOf($connection))
                : null,
            'failed_count' => $this->jobQueueRepository->countFailed(),
            'workers' => [
                'registered' => count($rows),
                'alive' => $alive,
                'warning_count' => count($rows) - $alive,
                'heartbeat_stale_after_minutes' => $report['heartbeat_stale_after_minutes'] ?? null,
                'long_worker' => $report['long_worker']['status'] ?? null,
            ],
        ];
    }

    /**
     * 실패 작업 1건의 재시도를 수행합니다 (훅 없음 — 단건·일괄 진입점이 훅을 건다).
     *
     * @param  string  $uuid  failed_jobs.uuid
     * @return array{uuid: string, ok: bool, message: string, connection?: string|null, queue?: string|null} 결과
     */
    private function performRetry(string $uuid): array
    {
        $job = $this->failer->find($uuid);

        if ($job === null) {
            return $this->result($uuid, false, 'queue.messages.failed_not_found');
        }

        $requestedConnection = is_string($job->connection ?? null) && $job->connection !== '' ? $job->connection : null;
        $requestedQueue = is_string($job->queue ?? null) && $job->queue !== '' ? $job->queue : null;
        $placement = $this->registry->resolve($requestedConnection, $requestedQueue);
        $connection = $placement['connection'] ?? $this->registry->defaultConnection();
        $driver = $this->registry->driverOf($connection);

        if ($driver === null || in_array($driver, self::NON_WORKER_DRIVERS, true)) {
            return $this->result($uuid, false, 'queue.messages.retry_no_worker');
        }

        if ($placement['fallback'] !== []) {
            $info = [
                'listener' => 'queue.retry',
                'hook' => 'core.queue_job.retry',
                'requested_connection' => $requestedConnection,
                'requested_queue' => $requestedQueue,
                'reasons' => $placement['fallback'],
            ];
            $this->registry->recordFallback($info);
            ThrottledQueueWarning::warn(
                'retry-fallback|'.$requestedConnection.'|'.$requestedQueue,
                '실패 작업을 등록되지 않은 연결·큐 대신 기본 연결·큐로 되넣습니다',
                $info,
            );
        }

        try {
            $decoded = json_decode((string) $job->payload, true);
            $decoded = is_array($decoded) ? $decoded : [];
            $hasCommand = isset($decoded['data']['command']);
            $instance = $hasCommand ? $this->instanceFromPayload($decoded) : null;

            // 직렬화도 암호화도 아닌 작업 객체는 되넣어도 워커가 풀지 못한다 — 프레임워크도 여기서 멈춘다.
            if ($hasCommand && $instance === null) {
                return $this->result($uuid, false, 'queue.messages.retry_failed');
            }

            $this->events->dispatch(new JobRetryRequested($job));

            $queue = $this->queue->connection($connection);
            $payload = $this->refreshRetryUntil($this->resetAttempts($decoded), $instance);

            $queue->pushRaw($payload, $placement['queue'], $this->queueableOptions($queue, $instance, $payload, $placement['queue']));
        } catch (Throwable $e) {
            Log::warning('실패 작업 재시도 실패', ['uuid' => $uuid, 'exception' => $e->getMessage()]);

            return $this->result($uuid, false, 'queue.messages.retry_failed');
        }

        $this->failer->forget($uuid);

        return [
            ...$this->result($uuid, true, 'queue.messages.retry_success'),
            'connection' => $connection,
            'queue' => $placement['queue'],
        ];
    }

    /**
     * 실패 작업 1건의 삭제를 수행합니다 (훅 없음).
     *
     * @param  string  $uuid  failed_jobs.uuid
     * @return array{uuid: string, ok: bool, message: string} 결과
     */
    private function performDelete(string $uuid): array
    {
        return $this->failer->forget($uuid)
            ? $this->result($uuid, true, 'queue.messages.delete_success')
            : $this->result($uuid, false, 'queue.messages.failed_not_found');
    }

    /**
     * 건별 결과 한 줄을 만듭니다.
     *
     * @param  string  $uuid  failed_jobs.uuid
     * @param  bool  $ok  성공 여부
     * @param  string  $messageKey  다국어 키
     * @return array{uuid: string, ok: bool, message: string} 결과
     */
    private function result(string $uuid, bool $ok, string $messageKey): array
    {
        return ['uuid' => $uuid, 'ok' => $ok, 'message' => __($messageKey)];
    }

    /**
     * payload 의 시도 횟수를 0 으로 되돌립니다 (RetryCommand::resetAttempts 와 같음).
     *
     * @param  array<string, mixed>  $decoded  디코드된 payload
     * @return array<string, mixed> 갱신된 payload
     */
    private function resetAttempts(array $decoded): array
    {
        if (isset($decoded['attempts'])) {
            $decoded['attempts'] = 0;
        }

        return $decoded;
    }

    /**
     * 작업의 retryUntil 시각을 지금 기준으로 다시 계산합니다 (RetryCommand::refreshRetryUntil 과 같음).
     *
     * @param  array<string, mixed>  $decoded  디코드된 payload
     * @param  mixed  $instance  payload 의 작업 객체 (없으면 null)
     * @return string 갱신된 payload JSON
     */
    private function refreshRetryUntil(array $decoded, mixed $instance): string
    {
        if (is_object($instance) && ! $instance instanceof \__PHP_Incomplete_Class && method_exists($instance, 'retryUntil')) {
            $retryUntil = $instance->retryUntil();
            $decoded['retryUntil'] = $retryUntil instanceof DateTimeInterface ? $retryUntil->getTimestamp() : $retryUntil;
        }

        return (string) json_encode($decoded, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 연결이 요구하는 큐 옵션을 구합니다 (RetryCommand::getQueueableOptions 와 같음).
     *
     * @param  object  $queue  큐 연결
     * @param  mixed  $instance  payload 의 작업 객체 (없으면 null)
     * @param  string  $payload  payload JSON
     * @param  string|null  $queueName  큐 이름
     * @return array<string, mixed> 옵션
     */
    private function queueableOptions(object $queue, mixed $instance, string $payload, ?string $queueName): array
    {
        if ($instance === null || ! method_exists($queue, 'getQueueableOptions')) {
            return [];
        }

        return $queue->getQueueableOptions($instance, $queueName, $payload);
    }

    /**
     * payload 의 작업 객체를 꺼냅니다 (RetryCommand::getInstanceFromPayload 와 같음).
     *
     * payload 는 프레임워크 failer 가 기록한 내부 값이다 — 외부 입력이 아니다.
     *
     * @param  array<string, mixed>  $decoded  디코드된 payload (data.command 가 있다)
     * @return mixed 작업 객체 (직렬화도 암호화도 아닌 값이면 null)
     */
    private function instanceFromPayload(array $decoded): mixed
    {
        $command = (string) $decoded['data']['command'];

        if (str_starts_with($command, 'O:')) {
            return unserialize($command);
        }

        if (app()->bound(Encrypter::class)) {
            return unserialize(app(Encrypter::class)->decrypt($command));
        }

        return null;
    }

    /**
     * 큐 연결이 쓰는 DB 연결 이름을 반환합니다.
     *
     * @param  string  $connection  큐 연결 이름
     * @return string|null DB 연결 이름 (null = 기본 DB 연결)
     */
    private function databaseOf(string $connection): ?string
    {
        $database = config('queue.connections.'.$connection.'.connection');

        return is_string($database) && $database !== '' ? $database : null;
    }

    /**
     * 큐 연결의 대기 작업 표 이름을 반환합니다.
     *
     * @param  string  $connection  큐 연결 이름
     * @return string 표 이름
     */
    private function tableOf(string $connection): string
    {
        return (string) config('queue.connections.'.$connection.'.table', 'jobs');
    }
}

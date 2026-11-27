<?php

namespace App\Listeners\Queue;

use App\Contracts\Repositories\QueueJobLogRepositoryInterface;
use App\Enums\QueueJobLogStatus;
use App\Models\QueueJobLog;
use App\Services\JobPayloadParser;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * 큐 작업 처리 기록 리스너
 *
 * 워커가 처리를 마친 작업 1건마다 queue_job_logs 에 1행을 남긴다.
 *
 * - `JobProcessing` 에서 시작 시각을 기억한다.
 * - `JobProcessed` 는 재시도 대기로 돌려보내지도(released) 실패하지도 않았을 때만 성공으로 남긴다.
 * - `JobFailed` 는 최종 실패를 남긴다(failed_jobs 로 옮겨진 작업).
 *
 * sync 연결은 요청 안에서 작업을 바로 돌리므로 요청마다 insert 가 생긴다 — 버퍼에 모았다가 요청 종료
 * (`app()->terminating`)에 한 번에 넣는다. 워커 연결은 한 건씩 바로 넣는다(워커는 오래 사는 프로세스라
 * 종료 시점까지 기다리면 기록이 늦고, 죽으면 잃는다).
 *
 * 설정 `drivers.queue_job_log_enabled`(기본 true)로 끄고, `config('queue.job_log.excluded_jobs')` 에 작업
 * 클래스를 적어 뺀다. 기록이 실패해도 작업 처리를 막지 않는다. 컨테이너에 싱글턴으로 묶는다(버퍼·시작 시각 공유).
 */
// audit:allow listener-must-implement-hooklistenerinterface reason: 훅이 아니라 Laravel 큐 이벤트(JobProcessing·JobProcessed·JobFailed) 리스너 — EventServiceProvider::boot() 가 Event::listen 으로 직접 등록하는 명시 등록 패턴 (하트비트 리스너와 동일)
class RecordQueueJobLog
{
    /** 버퍼가 이 크기에 이르면 요청 종료를 기다리지 않고 넣는다 */
    public const BUFFER_FLUSH_SIZE = 100;

    /**
     * 작업별 시작 시각 (spl_object_id => 마이크로초 단위 unix 시각)
     *
     * @var array<int, float>
     */
    private array $startedAt = [];

    /**
     * sync 연결 기록 버퍼
     *
     * @var list<array<string, mixed>>
     */
    private array $buffer = [];

    /** 요청 종료 시 일괄 insert 를 걸었는지 */
    private bool $terminatingRegistered = false;

    /** 기록 표가 있는지 (프로세스 안 캐시 — 업그레이드 도중 표가 없을 수 있다) */
    private ?bool $tableExists = null;

    /**
     * @param  QueueJobLogRepositoryInterface  $repository  처리 기록 저장소
     */
    public function __construct(
        private readonly QueueJobLogRepositoryInterface $repository,
    ) {}

    /**
     * 작업 시작 시각을 기억합니다.
     *
     * @param  JobProcessing  $event  작업 시작 이벤트
     * @return void 반환값 없음
     */
    public function handleProcessing(JobProcessing $event): void
    {
        $this->startedAt[spl_object_id($event->job)] = microtime(true);
    }

    /**
     * 처리 완료를 기록합니다 — 재시도 대기·실패로 끝난 경우는 건너뛴다.
     *
     * @param  JobProcessed  $event  작업 완료 이벤트
     * @return void 반환값 없음
     */
    public function handleProcessed(JobProcessed $event): void
    {
        if ($event->job->isReleased() || $event->job->hasFailed()) {
            unset($this->startedAt[spl_object_id($event->job)]);

            return;
        }

        $this->record($event->connectionName, $event->job, QueueJobLogStatus::Processed, null);
    }

    /**
     * 최종 실패를 기록합니다.
     *
     * @param  JobFailed  $event  작업 실패 이벤트
     * @return void 반환값 없음
     */
    public function handleFailed(JobFailed $event): void
    {
        $this->record($event->connectionName, $event->job, QueueJobLogStatus::Failed, $event->exception);
    }

    /**
     * 버퍼에 모인 기록을 넣습니다.
     *
     * @return void 반환값 없음
     */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $rows = $this->buffer;
        $this->buffer = [];
        $this->insert($rows);
    }

    /**
     * 기록 1행을 만들어 넣거나 버퍼에 모읍니다.
     *
     * @param  string  $connection  큐 연결 이름
     * @param  Job  $job  작업
     * @param  QueueJobLogStatus  $status  처리 결과
     * @param  Throwable|null  $exception  실패 예외
     * @return void 반환값 없음
     */
    private function record(string $connection, Job $job, QueueJobLogStatus $status, ?Throwable $exception): void
    {
        $key = spl_object_id($job);
        $started = $this->startedAt[$key] ?? null;
        unset($this->startedAt[$key]);

        try {
            if (! $this->enabled()) {
                return;
            }

            $parsed = JobPayloadParser::fromDecoded($job->payload());

            if (in_array($parsed['job_class'], (array) config('queue.job_log.excluded_jobs', []), true)) {
                return;
            }

            $finished = microtime(true);
            $row = [
                'uuid' => $parsed['uuid'] ?? $job->uuid(),
                'connection' => Str::limit($connection, 50, ''),
                'queue' => Str::limit((string) $job->getQueue(), 100, ''),
                'job_class' => Str::limit($parsed['job_class'], 255, ''),
                'display_name' => Str::limit($parsed['display_name'], 255, ''),
                'status' => $status->value,
                'attempts' => max(1, min(65535, $job->attempts())),
                'started_at' => $started !== null ? $this->millis($started) : null,
                'finished_at' => $this->millis($finished),
                'duration_ms' => $started !== null ? (int) round(($finished - $started) * 1000) : null,
                'exception_class' => $exception !== null ? Str::limit($exception::class, 255, '') : null,
                'exception_message' => $exception !== null
                    ? Str::limit($exception->getMessage(), QueueJobLog::EXCEPTION_MESSAGE_LIMIT, '')
                    : null,
                'schedule_id' => $parsed['schedule_id'],
                // audit:allow datetime-display-user-timezone reason: DB 저장값(앱 시간대) — 기계값, 표시는 Resource 가 변환
                'created_at' => Carbon::now()->format('Y-m-d H:i:s'),
            ];

            if ($connection === 'sync' || $this->isSyncDriver($connection)) {
                $this->buffer($row);

                return;
            }

            $this->insert([$row]);
        } catch (Throwable $e) {
            // 기록은 부가 기능이다 — 작업 처리를 막지 않는다.
            Log::warning('큐 처리 기록 실패', ['connection' => $connection, 'exception' => $e->getMessage()]);
        }
    }

    /**
     * sync 기록을 버퍼에 모으고, 처음이면 요청 종료 시 일괄 insert 를 겁니다.
     *
     * @param  array<string, mixed>  $row  기록 행
     * @return void 반환값 없음
     */
    private function buffer(array $row): void
    {
        $this->buffer[] = $row;

        if (count($this->buffer) >= self::BUFFER_FLUSH_SIZE) {
            $this->flush();

            return;
        }

        if (! $this->terminatingRegistered) {
            $this->terminatingRegistered = true;
            app()->terminating(fn () => $this->flush());
        }
    }

    /**
     * 기록 행을 넣습니다 (실패해도 던지지 않는다).
     *
     * @param  list<array<string, mixed>>  $rows  기록 행
     * @return void 반환값 없음
     */
    private function insert(array $rows): void
    {
        try {
            $this->repository->insertMany($rows);
        } catch (Throwable $e) {
            Log::warning('큐 처리 기록 저장 실패', ['rows' => count($rows), 'exception' => $e->getMessage()]);
        }
    }

    /**
     * 기록이 켜져 있고 표가 있는지 판정합니다.
     *
     * @return bool 기록할 수 있으면 true
     */
    private function enabled(): bool
    {
        if (! filter_var(g7_core_settings('drivers.queue_job_log_enabled', true), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        return $this->tableExists ??= Schema::hasTable('queue_job_logs');
    }

    /**
     * 연결의 드라이버가 sync 인지 판정합니다.
     *
     * @param  string  $connection  큐 연결 이름
     * @return bool sync 드라이버면 true
     */
    private function isSyncDriver(string $connection): bool
    {
        return config('queue.connections.'.$connection.'.driver') === 'sync';
    }

    /**
     * 마이크로초 unix 시각을 밀리초 정밀도 저장 문자열로 바꿉니다.
     *
     * 쿼리 빌더가 DateTime 바인딩을 초 단위로 포맷하므로 timestamp(3) 컬럼에는 문자열로 넣는다.
     *
     * @param  float  $seconds  마이크로초 단위 unix 시각
     * @return string 'Y-m-d H:i:s.v' (앱 시간대)
     */
    private function millis(float $seconds): string
    {
        return Carbon::createFromTimestampMs((int) round($seconds * 1000))
            ->setTimezone(config('app.timezone'))
            // audit:allow datetime-display-user-timezone reason: DB 저장값(앱 시간대, timestamp(3) 컬럼) — 기계값
            ->format('Y-m-d H:i:s.v');
    }
}

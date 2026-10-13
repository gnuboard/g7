<?php

namespace App\Services\Queue;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\Analytics\QueueStatsRepositoryInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * 큐 워커 레지스트리 (#139)
 *
 * 「이 서버가 실제로 워커를 돌리는 연결·큐」를 운영자가 환경설정 → 드라이버 탭 「큐 워커」에 등록한다.
 * 훅 리스너·코어 잡은 등록된 연결·큐만 쓰고, 등록되지 않은 곳을 가리키면 기본 연결·기본 큐로 폴백한다 —
 * 아무 워커도 보지 않는 큐에 잡을 쌓아 두면 그 일은 영영 실행되지 않기 때문이다.
 *
 * 워커 생존 신호(하트비트)는 큐별로 **표시만** 한다. 판단은 등록 목록으로만 한다 —
 * 하트비트는 서버별 기본 캐시에 남으므로 다중 서버 파일 캐시면 다른 서버의 워커는 보이지 않는다.
 *
 * 설정 키:
 *  - `drivers.queue_connections`: string[] — 비면 「현재 큐 드라이버 연결 하나」
 *  - `drivers.queue_names`: {연결: string[]} — 키가 없으면 `*-long` 연결은 `["long"]`, 그 밖은 `["default"]`
 */
class QueueWorkerRegistry
{
    /** 하트비트 캐시 키 접두사 — `queue:heartbeat:{connection}:{queue}` = unix ts */
    public const HEARTBEAT_PREFIX = 'queue:heartbeat:';

    /** 하트비트를 남긴 연결·큐 목록 캐시 키 */
    public const HEARTBEAT_INDEX = 'queue:heartbeat:index';

    /** timeout 을 retry_after 아래로 줄인 횟수·마지막 사례 캐시 키 접두사 */
    public const CLAMP_PREFIX = 'queue:placement:timeout_clamped:';

    /** 미등록 연결·큐로 폴백한 횟수·마지막 사례 캐시 키 접두사 */
    public const FALLBACK_PREFIX = 'queue:placement:fallback:';

    /** 하트비트·배치 카운터 보관 시간(초) */
    private const TTL_SECONDS = 86400;

    /** 대기 건수를 셀 수 있는 드라이버 */
    private const MEASURABLE_DRIVERS = ['database'];

    /** 등록할 수 없는 드라이버 — 워커가 없는 드라이버 */
    private const NON_WORKER_DRIVERS = ['sync', 'null'];

    /**
     * 프로세스 안에서 마지막으로 하트비트를 쓴 시각 (스로틀)
     *
     * @var array<string, int>
     */
    private static array $lastBeatAt = [];

    /**
     * @param  QueueStatsRepositoryInterface  $queueStats  큐 대기 건수 조회
     * @param  CacheInterface  $cache  하트비트·배치 카운터 저장소 (서버별 기본 캐시)
     */
    public function __construct(
        private readonly QueueStatsRepositoryInterface $queueStats,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 현재 큐 드라이버(기본 연결) 이름을 반환합니다.
     *
     * @return string 기본 연결 이름 (예: sync, database, redis)
     */
    public function defaultConnection(): string
    {
        return (string) config('queue.default', 'sync');
    }

    /**
     * 연결의 드라이버를 반환합니다.
     *
     * @param  string  $connection  연결 이름
     * @return string|null 드라이버 (정의되지 않은 연결이면 null)
     */
    public function driverOf(string $connection): ?string
    {
        $driver = config("queue.connections.{$connection}.driver");

        return is_string($driver) ? $driver : null;
    }

    /**
     * 운영자가 등록할 수 있는 연결 목록을 반환합니다 — 워커가 있는 드라이버만.
     *
     * @return list<array{name: string, driver: string}> 연결 이름·드라이버
     */
    public function registrableConnections(): array
    {
        $options = [];

        foreach ((array) config('queue.connections', []) as $name => $connection) {
            $driver = is_array($connection) ? ($connection['driver'] ?? null) : null;

            if (! is_string($driver) || in_array($driver, self::NON_WORKER_DRIVERS, true)) {
                continue;
            }

            $options[] = ['name' => (string) $name, 'driver' => $driver];
        }

        return $options;
    }

    /**
     * 등록할 수 있는 연결 이름 목록을 반환합니다.
     *
     * @return list<string> 연결 이름
     */
    public function registrableConnectionNames(): array
    {
        return array_column($this->registrableConnections(), 'name');
    }

    /**
     * 등록된 연결 목록을 반환합니다 — 정의되지 않았거나 워커가 없는 연결은 뺍니다.
     *
     * 설정이 비어 있으면 「현재 큐 드라이버 연결 하나」로 본다. 기본 연결은 늘 등록된 것으로 친다.
     *
     * @return list<string> 연결 이름 (기본 연결이 sync 면 빈 목록일 수 있다)
     */
    public function registeredConnections(): array
    {
        $registrable = $this->registrableConnectionNames();
        $configured = g7_core_settings('drivers.queue_connections', []);
        $connections = [];

        foreach (is_array($configured) ? $configured : [] as $connection) {
            if (is_string($connection) && in_array($connection, $registrable, true) && ! in_array($connection, $connections, true)) {
                $connections[] = $connection;
            }
        }

        $default = $this->defaultConnection();
        if (in_array($default, $registrable, true) && ! in_array($default, $connections, true)) {
            array_unshift($connections, $default);
        }

        return $connections;
    }

    /**
     * 연결에 등록된 큐 이름 목록을 반환합니다.
     *
     * @param  string  $connection  연결 이름
     * @return list<string> 큐 이름 (설정이 없으면 기본값)
     */
    public function queueNamesFor(string $connection): array
    {
        $configured = g7_core_settings('drivers.queue_names', []);
        $names = is_array($configured) ? ($configured[$connection] ?? null) : null;
        $queues = [];

        foreach (is_array($names) ? $names : [] as $name) {
            if (is_string($name) && $name !== '' && ! in_array($name, $queues, true)) {
                $queues[] = $name;
            }
        }

        return $queues !== [] ? $queues : $this->defaultQueueNamesFor($connection);
    }

    /**
     * 큐 목록 설정이 없는 연결의 기본 큐 이름을 반환합니다.
     *
     * @param  string  $connection  연결 이름
     * @return list<string> `*-long` 연결이면 `["long"]`, 그 밖은 `["default"]`
     */
    public function defaultQueueNamesFor(string $connection): array
    {
        return $this->isLongConnection($connection)
            ? [(string) config('queue.worker_registry.long_queue', 'long')]
            : ['default'];
    }

    /**
     * 긴 작업 전용 연결 이름인지 판정합니다.
     *
     * @param  string  $connection  연결 이름
     * @return bool `-long` 접미사로 끝나면 true
     */
    public function isLongConnection(string $connection): bool
    {
        return str_ends_with($connection, (string) config('queue.worker_registry.long_connection_suffix', '-long'));
    }

    /**
     * 등록된 연결·큐 쌍 전체를 반환합니다.
     *
     * @return list<array{connection: string, queue: string}> 연결·큐 (등록이 없으면 기본 연결의 default 큐)
     */
    public function entries(): array
    {
        $entries = [];

        foreach ($this->registeredConnections() as $connection) {
            foreach ($this->queueNamesFor($connection) as $queue) {
                $entries[] = ['connection' => $connection, 'queue' => $queue];
            }
        }

        return $entries !== [] ? $entries : [['connection' => $this->defaultConnection(), 'queue' => 'default']];
    }

    /**
     * 연결이 등록됐는지 판정합니다 — 기본 연결은 늘 참입니다.
     *
     * @param  string  $connection  연결 이름
     * @return bool 등록 여부
     */
    public function isConnectionRegistered(string $connection): bool
    {
        return $connection === $this->defaultConnection()
            || in_array($connection, $this->registeredConnections(), true);
    }

    /**
     * 연결·큐 쌍이 등록됐는지 판정합니다.
     *
     * @param  string  $connection  연결 이름
     * @param  string  $queue  큐 이름
     * @return bool 등록 여부
     */
    public function isRegistered(string $connection, string $queue): bool
    {
        return $this->isConnectionRegistered($connection)
            && in_array($queue, $this->queueNamesFor($connection), true);
    }

    /**
     * 요청한 연결·큐를 등록 목록에 맞춰 해석합니다.
     *
     * 등록되지 않은 연결이면 기본 연결(null)로, 등록되지 않은 큐면 그 연결의 기본 큐(null)로 바꾸고 사유를 돌려준다.
     * 해석된 연결이 sync 면 큐 이름은 의미가 없어(요청 안에서 바로 실행) 그대로 둔다.
     *
     * @param  string|null  $connection  요청한 연결 (null = 기본 연결)
     * @param  string|null  $queue  요청한 큐 (null = 연결의 기본 큐)
     * @return array{connection: string|null, queue: string|null, fallback: list<string>} 해석 결과와 폴백 사유
     */
    public function resolve(?string $connection, ?string $queue): array
    {
        $fallback = [];

        if ($connection !== null && ! $this->isConnectionRegistered($connection)) {
            $fallback[] = 'connection_unregistered';
            $connection = null;
        }

        $effective = $connection ?? $this->defaultConnection();

        if ($queue !== null
            && ! in_array($this->driverOf($effective), self::NON_WORKER_DRIVERS, true)
            && ! $this->isRegistered($effective, $queue)) {
            $fallback[] = 'queue_unregistered';
            $queue = null;
        }

        return ['connection' => $connection, 'queue' => $queue, 'fallback' => $fallback];
    }

    /**
     * 연결의 retry_after(초)를 반환합니다.
     *
     * @param  string|null  $connection  연결 이름 (null = 기본 연결)
     * @return int|null retry_after (개념이 없는 드라이버면 null)
     */
    public function retryAfter(?string $connection): ?int
    {
        $value = config('queue.connections.'.($connection ?? $this->defaultConnection()).'.retry_after');

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * 긴 작업을 보낼 연결·큐를 반환합니다.
     *
     * 「{기본 연결}-long」 연결과 그 연결의 long 큐가 **둘 다 등록**됐을 때만 돌려준다. 기본 연결이 sync 면 없다.
     *
     * @return array{connection: string, queue: string}|null 긴 작업 배치 (없으면 null)
     */
    public function longPlacement(): ?array
    {
        $default = $this->defaultConnection();

        if (in_array($this->driverOf($default), self::NON_WORKER_DRIVERS, true)) {
            return null;
        }

        $connection = $default.config('queue.worker_registry.long_connection_suffix', '-long');
        $queue = (string) config('queue.worker_registry.long_queue', 'long');

        if ($this->driverOf($connection) === null || ! in_array($connection, $this->registeredConnections(), true)) {
            return null;
        }

        return in_array($queue, $this->queueNamesFor($connection), true)
            ? ['connection' => $connection, 'queue' => $queue]
            : null;
    }

    /**
     * 워커 생존 신호를 남깁니다 — 워커 루프·잡 시작마다 불리므로 30초에 한 번만 씁니다.
     *
     * 워커를 절대 죽이지 않는다 — 캐시가 실패해도 조용히 넘어간다.
     *
     * @param  string  $connection  워커 연결 이름
     * @param  string|null  $queues  워커가 보는 큐 (쉼표 목록 가능)
     * @return void 반환값 없음
     */
    public function recordHeartbeat(string $connection, ?string $queues): void
    {
        try {
            $now = Carbon::now()->getTimestamp();
            $interval = (int) config('queue.worker_registry.heartbeat_interval_seconds', 30);
            $written = [];

            foreach (explode(',', (string) ($queues ?: 'default')) as $queue) {
                $queue = trim($queue);
                if ($queue === '') {
                    continue;
                }

                $key = $connection.':'.$queue;
                if (isset(self::$lastBeatAt[$key]) && $now - self::$lastBeatAt[$key] < $interval) {
                    continue;
                }

                self::$lastBeatAt[$key] = $now;
                $this->cache->put(self::HEARTBEAT_PREFIX.$key, $now, self::TTL_SECONDS);
                $written[] = $key;
            }

            if ($written !== []) {
                $index = $this->cache->get(self::HEARTBEAT_INDEX, []);
                $index = array_values(array_unique([...(is_array($index) ? $index : []), ...$written]));
                $this->cache->put(self::HEARTBEAT_INDEX, $index, self::TTL_SECONDS);
            }
        } catch (Throwable) {
            // 생존 신호는 표시용이다 — 워커의 본업을 막지 않는다.
        }
    }

    /**
     * 연결·큐의 마지막 생존 신호 시각을 반환합니다.
     *
     * @param  string  $connection  연결 이름
     * @param  string  $queue  큐 이름
     * @return int|null unix 시각 (신호가 없으면 null)
     */
    public function lastHeartbeat(string $connection, string $queue): ?int
    {
        try {
            $value = $this->cache->get(self::HEARTBEAT_PREFIX.$connection.':'.$queue);

            return is_numeric($value) ? (int) $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * 리스너 timeout 을 retry_after 아래로 줄인 사례를 셉니다.
     *
     * @param  array<string, mixed>  $info  사례 (listener, hook, connection, queue, requested, applied, retry_after)
     * @return void 반환값 없음
     */
    public function recordTimeoutClamp(array $info): void
    {
        $this->recordCounter(self::CLAMP_PREFIX, $info, true);
    }

    /**
     * 미등록 연결·큐를 기본값으로 폴백한 사례를 셉니다.
     *
     * @param  array<string, mixed>  $info  사례 (listener, hook, requested_connection, requested_queue, reasons)
     * @return void 반환값 없음
     */
    public function recordFallback(array $info): void
    {
        $this->recordCounter(self::FALLBACK_PREFIX, $info, false);
    }

    /**
     * 드라이버 탭 저장값의 큐 워커 등록을 정규화합니다.
     *
     * - 연결 목록이 비면 저장하려는 큐 드라이버의 연결 하나(sync 면 빈 목록)
     * - 등록한 연결 중 큐 목록이 없는 연결은 기본 큐(`*-long` 은 long, 그 밖은 default)
     * - 등록하지 않은 연결의 큐 목록 키는 버린다(연결을 뺀 뒤 남은 목록)
     *
     * 두 키가 다 제출되지 않았으면 손대지 않는다(다른 필드만 저장하는 요청은 기존 값을 유지).
     *
     * @param  array<string, mixed>  $drivers  drivers 탭 제출값
     * @return array<string, mixed> 정규화한 제출값
     */
    public function normalizeForSave(array $drivers): array
    {
        if (! array_key_exists('queue_connections', $drivers) && ! array_key_exists('queue_names', $drivers)) {
            return $drivers;
        }

        $registrable = $this->registrableConnectionNames();
        $connections = [];

        foreach ((array) ($drivers['queue_connections'] ?? []) as $connection) {
            if (is_string($connection) && in_array($connection, $registrable, true) && ! in_array($connection, $connections, true)) {
                $connections[] = $connection;
            }
        }

        $driver = (string) ($drivers['queue_driver'] ?? $this->defaultConnection());
        if ($connections === [] && in_array($driver, $registrable, true)) {
            $connections[] = $driver;
        }

        $submitted = is_array($drivers['queue_names'] ?? null) ? $drivers['queue_names'] : [];
        $names = [];

        foreach ($connections as $connection) {
            $queues = [];
            foreach ((array) ($submitted[$connection] ?? []) as $queue) {
                if (is_string($queue) && $queue !== '' && ! in_array($queue, $queues, true)) {
                    $queues[] = $queue;
                }
            }

            $names[$connection] = $queues !== [] ? $queues : $this->defaultQueueNamesFor($connection);
        }

        $drivers['queue_connections'] = $connections;
        $drivers['queue_names'] = $names;

        return $drivers;
    }

    /**
     * 드라이버 탭 「큐 워커」 구역의 상태 보고서를 만듭니다.
     *
     * @return array<string, mixed> 상태 보고서 (docs/backend/api/settings.md 「큐 워커 상태」 계약)
     */
    public function statusReport(): array
    {
        $default = $this->defaultConnection();
        $staleSeconds = (int) config('queue.worker_registry.heartbeat_stale_seconds', 300);
        $pendingByQueue = $this->queueStats->pendingJobsByQueue();
        $now = Carbon::now()->getTimestamp();
        $rows = [];

        if (! in_array($this->driverOf($default), self::NON_WORKER_DRIVERS, true)) {
            foreach ($this->entries() as ['connection' => $connection, 'queue' => $queue]) {
                $driver = $this->driverOf($connection);
                $measurable = in_array($driver, self::MEASURABLE_DRIVERS, true);
                $pending = $measurable ? (int) ($pendingByQueue[$queue] ?? 0) : null;
                $beat = $this->lastHeartbeat($connection, $queue);
                $alive = $beat !== null && $now - $beat <= $staleSeconds;

                $warnings = [];
                if (! $alive) {
                    $warnings[] = ($pending ?? 0) > 0 ? 'pending_without_worker' : 'no_heartbeat';
                }

                $rows[] = [
                    'connection' => $connection,
                    'queue' => $queue,
                    'driver' => $driver,
                    'pending' => $pending,
                    'pending_measurable' => $measurable,
                    'last_heartbeat_at' => $beat !== null ? Carbon::createFromTimestamp($beat)->toIso8601String() : null,
                    'warnings' => $warnings,
                    'clamped_timeout_count' => $this->counterValue(self::CLAMP_PREFIX.'count:'.$connection.':'.$queue),
                    // 긴 작업 큐를 retry_after 가 짧은 연결로 띄우면 실행 중인 잡을 다른 워커가 다시 집는다.
                    'long_queue_hint' => $queue === config('queue.worker_registry.long_queue', 'long') && ! $this->isLongConnection($connection),
                ];
            }
        }

        return [
            'driver' => $default,
            'measured_at' => Carbon::createFromTimestamp($now)->toIso8601String(),
            'heartbeat_stale_after_minutes' => intdiv($staleSeconds, 60),
            'rows' => $rows,
            'long_worker' => $this->longWorkerStatus(),
            'commands' => $this->supervisorCommands(),
            'placement' => [
                'timeout_clamped' => $this->counterSummary(self::CLAMP_PREFIX),
                'fallback' => $this->counterSummary(self::FALLBACK_PREFIX),
            ],
        ];
    }

    /**
     * 긴 작업 전용 워커의 등록 단계를 판정합니다.
     *
     * @return array{connection: string|null, queue: string, status: string} registered(연결·큐 둘 다) · partial(연결만) · not_registered
     */
    private function longWorkerStatus(): array
    {
        $default = $this->defaultConnection();
        $queue = (string) config('queue.worker_registry.long_queue', 'long');

        if (in_array($this->driverOf($default), self::NON_WORKER_DRIVERS, true)) {
            return ['connection' => null, 'queue' => $queue, 'status' => 'not_registered'];
        }

        $connection = $default.config('queue.worker_registry.long_connection_suffix', '-long');
        $status = 'not_registered';

        if ($this->driverOf($connection) !== null && in_array($connection, $this->registeredConnections(), true)) {
            $status = in_array($queue, $this->queueNamesFor($connection), true) ? 'registered' : 'partial';
        }

        return ['connection' => $connection, 'queue' => $queue, 'status' => $status];
    }

    /**
     * 등록된 연결마다 Supervisor 에 넣을 워커 실행 명령을 만듭니다.
     *
     * @return list<array{connection: string, queues: string, command: string}> 연결별 명령
     */
    private function supervisorCommands(): array
    {
        if (in_array($this->driverOf($this->defaultConnection()), self::NON_WORKER_DRIVERS, true)) {
            return [];
        }

        $commands = [];

        foreach ($this->registeredConnections() as $connection) {
            $queues = implode(',', $this->queueNamesFor($connection));
            $command = "php artisan queue:work {$connection} --queue={$queues} --sleep=3 --tries=3 --max-time=3600";

            if ($this->isLongConnection($connection)) {
                $command .= ' --timeout=3600';
            }

            $commands[] = ['connection' => $connection, 'queues' => $queues, 'command' => $command];
        }

        return $commands;
    }

    /**
     * 배치 카운터를 올리고 마지막 사례를 남깁니다.
     *
     * @param  string  $prefix  카운터 접두사
     * @param  array<string, mixed>  $info  사례
     * @param  bool  $perQueue  연결·큐별 카운터도 함께 올릴지
     * @return void 반환값 없음
     */
    private function recordCounter(string $prefix, array $info, bool $perQueue): void
    {
        // 표시용 카운터라 원자적 증가가 아니어도 된다 — 동시에 두 프로세스가 올리면 1 덜 셀 수 있다.
        try {
            $this->cache->put($prefix.'count', $this->counterValue($prefix.'count') + 1, self::TTL_SECONDS);
            $this->cache->put($prefix.'last', [...$info, 'at' => Carbon::now()->toIso8601String()], self::TTL_SECONDS);

            if ($perQueue) {
                $key = $prefix.'count:'.($info['connection'] ?? $this->defaultConnection()).':'.($info['queue'] ?? 'default');
                $this->cache->put($key, $this->counterValue($key) + 1, self::TTL_SECONDS);
            }
        } catch (Throwable) {
            // 표시용 카운터다 — 디스패치를 막지 않는다.
        }
    }

    /**
     * 카운터 값을 읽습니다.
     *
     * @param  string  $key  캐시 키
     * @return int 값 (없으면 0)
     */
    private function counterValue(string $key): int
    {
        try {
            return (int) $this->cache->get($key, 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * 카운터 요약을 만듭니다.
     *
     * @param  string  $prefix  카운터 접두사
     * @return array{count: int, last: array<string, mixed>|null} 횟수와 마지막 사례
     */
    private function counterSummary(string $prefix): array
    {
        try {
            $last = $this->cache->get($prefix.'last');
        } catch (Throwable) {
            $last = null;
        }

        return ['count' => $this->counterValue($prefix.'count'), 'last' => is_array($last) ? $last : null];
    }

    /**
     * 프로세스 안의 하트비트 스로틀 기록을 비웁니다 (테스트 격리용).
     *
     * @return void 반환값 없음
     */
    public static function resetProcessState(): void
    {
        self::$lastBeatAt = [];
    }
}

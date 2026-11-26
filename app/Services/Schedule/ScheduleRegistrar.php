<?php

namespace App\Services\Schedule;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\ScheduleRepositoryInterface;
use App\Enums\ScheduleDispatchMode;
use App\Enums\ScheduleFrequency;
use App\Enums\ScheduleType;
use App\Extension\Cache\CacheDriverFactory;
use App\Extension\Cache\CacheScope;
use App\Models\Schedule as ScheduleModel;
use App\Support\RuntimeRole;
use App\Support\ScheduleCommandValidator;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * DB 스케줄 등록기
 *
 * `routes/console.php` 의 한 줄이 이 클래스를 부른다. 활성 스케줄 행마다 `Schedule::call` 이벤트를
 * `schedule:{id}` 이름으로 등록하고, 실제 실행은 디스패처(바로 실행 / 큐로 보내기)에 맡긴다.
 *
 * - 이벤트 이름이 행마다 달라야 `onOneServer` 잠금을 서로 공유하지 않는다(같은 분에 겹친 작업이 건너뛰지 않게).
 * - Laravel `withoutOverlapping` 은 쓰지 않는다 — 큐 모드는 투입 직후 잠금이 풀리므로
 *   중복 방지는 실행기의 캐시 잠금(`schedule:run:{id}`)이 모든 경로(틱·수동·큐)를 맡는다.
 * - 이름이 `schedule:` 로 시작하는 이벤트는 이 등록기가 관리한다(isManaged) — 웹 크론 러너는
 *   이 이벤트를 그대로 돌리고 이력은 실행기에 맡긴다. 러너가 정한 계기·실행자는 `ScheduleRunContext` 로 넘어온다.
 * - 이 서버에 없는 Artisan 명령·작업(Job) 클래스의 행은 실행하지 않고 건너뛴다 — 같은 DB 를 쓰는 다른 서버(먼저 새 판을 받은
 *   검수 서버)가 공유 `schedules` 에 넣거나 바꾼 행을 이 서버가 매 주기 실패로 돌리지 않게. 판정은 실행 시점의 건너뛰기 필터다 —
 *   등록은 `routes/console.php` 를 읽는 콘솔 커널 부팅 도중에 일어나 그때 명령 목록을 읽으면 부팅이 다시 돈다. 경고는 행·대상마다
 *   하루 한 번(캐시 키)만 남긴다. 판정 자체가 예외면(콘솔 커널의 명령 하나가 생성 중 예외 등) 건너뛰지 않는다(fail-open) — 예외가
 *   필터 밖으로 새면 모든 DB 스케줄 행의 필터 판정이 예외가 돼 웹 크론 러너가 그 시점의 실행 전체를 멈춘다.
 */
class ScheduleRegistrar
{
    /** DB 스케줄 이벤트 이름 접두사 */
    public const EVENT_PREFIX = 'schedule:';

    /** 스케줄러 생존 신호 이벤트 이름 */
    public const HEARTBEAT_EVENT = 'schedule:heartbeat';

    /** 「이 서버에 없는 대상」 경고를 한 번만 남기는 캐시 키 접두사 */
    private const MISSING_TARGET_WARNED_PREFIX = 'schedule.registrar.missing_target.';

    /** 같은 행·대상의 경고를 다시 남기기까지의 간격(초) — 매 분 `schedule:run` 이 같은 경고를 쌓지 않게 */
    private const MISSING_TARGET_WARN_TTL = 86400;

    /** 「대상 판정 실패」 경고를 한 번만 남기는 캐시 키 접두사 — 같은 예외는 행이 여럿이어도 하루 한 번 */
    private const TARGET_CHECK_FAILED_WARNED_PREFIX = 'schedule.registrar.target_check_failed.';

    /**
     * 이 프로세스가 읽은 콘솔 커널 명령 목록 (명령명 → 명령). 실행 시점 판정에서 처음 한 번만 읽는다.
     *
     * @var array<string, mixed>|null
     */
    private ?array $registeredCommands = null;

    /**
     * 이 프로세스에서 콘솔 커널 명령 목록 읽기가 실패했을 때의 예외 — 행마다 다시 읽어 같은 예외를 되풀이하지 않는다.
     */
    private ?Throwable $registeredCommandsError = null;

    /**
     * @param  ScheduleRepositoryInterface  $scheduleRepository  스케줄 저장소
     * @param  ScheduleConditionEvaluator  $conditions  실행 조건 해석기
     */
    public function __construct(
        private readonly ScheduleRepositoryInterface $scheduleRepository,
        private readonly ScheduleConditionEvaluator $conditions,
    ) {}

    /**
     * 생존 신호와 활성 스케줄 행을 스케줄러에 등록합니다.
     *
     * 등록 실패가 artisan 전체를 막지 않도록 예외는 로그로만 남긴다(설치 전·업그레이드 도중 포함).
     *
     * @param  Schedule  $schedule  Laravel 스케줄러
     * @return void 반환값 없음
     */
    public function register(Schedule $schedule): void
    {
        // 스케줄이 꺼진 서버(stage 역할 또는 G7_SCHEDULER=off)는 아무것도 등록하지 않는다 — 판정은 RuntimeRole::schedulerEnabled() 하나
        if (! RuntimeRole::schedulerEnabled()) {
            return;
        }

        $schedule->call(static fn () => app(ScheduleHeartbeat::class)->beat())
            ->name(self::HEARTBEAT_EVENT)
            ->everyMinute();

        try {
            // 설치 전(테이블 없음)은 조용히 건너뛴다.
            if (! Schema::hasTable('schedules')) {
                return;
            }

            // 업그레이드 도중(새 컬럼 마이그레이션 전)에는 행의 실행 방식을 알 수 없다 — 등록하지 않는다.
            if (! Schema::hasColumn('schedules', 'dispatch_mode')) {
                Log::warning('DB 스케줄 등록 건너뜀 — schedules 테이블이 아직 새 스키마가 아닙니다 (마이그레이션 필요)');

                return;
            }

            $rows = $this->registrableRows();
        } catch (Throwable $e) {
            Log::warning('DB 스케줄 등록 건너뜀', ['error' => $e->getMessage()]);

            return;
        }

        foreach ($rows as $row) {
            try {
                $this->registerRow($schedule, $row);
            } catch (Throwable $e) {
                // 한 행의 잘못된 값(깨진 cron 식 등)이 나머지 등록을 막지 않게 한다.
                Log::warning('DB 스케줄 행 등록 실패', ['schedule_id' => $row->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * 이 등록기가 관리하는 이벤트인지 판정합니다.
     *
     * @param  Event  $event  스케줄 이벤트
     * @return bool 이름이 `schedule:` 로 시작하면 true
     */
    public function isManaged(Event $event): bool
    {
        return str_starts_with((string) $event->description, self::EVENT_PREFIX);
    }

    /**
     * 등록할 행을 등록 순서대로 반환합니다.
     *
     * 소유 확장이 비활성인 행은 뺀다. 순서는 30초 주기 → 바로 실행 → 큐로 보내기 —
     * 같은 분에 도는 작업 중 가벼운 것이 먼저 돌고, 무거운 바로 실행 작업이 30초 작업을 밀지 않게 한다.
     *
     * @return Collection<int, ScheduleModel> 등록 대상 행
     */
    private function registrableRows(): Collection
    {
        return collect($this->scheduleRepository->getActiveSchedules())
            ->filter(static fn (ScheduleModel $row): bool => $row->ownerIsActive())
            ->sortBy(static fn (ScheduleModel $row): array => [
                $row->frequency === ScheduleFrequency::EveryThirtySeconds ? 0 : 1,
                $row->dispatch_mode === ScheduleDispatchMode::Queue ? 1 : 0,
                (int) $row->id,
            ])
            ->values();
    }

    /**
     * 행의 실행 대상(Artisan 명령·작업 클래스)이 이 서버의 코드에 있는지 판정합니다.
     *
     * Artisan 은 콘솔 커널에 등록된 명령, 행이 선언한 `command_class`, 활성 확장의 명령 디렉터리(웹 크론처럼 확장 명령이 커널에
     * 등록되지 않는 웹 요청 경로) 중 하나에 있으면 있다고 본다. 명령명을 읽을 수 없는 형식은 여기서 거르지 않는다 — 실행기의
     * 검증이 사유와 함께 거부한다. 쉘·URL 은 대상이 코드가 아니므로 늘 true.
     *
     * @param  ScheduleModel  $row  스케줄 행
     * @return bool 대상이 있으면 true
     */
    private function targetExistsOnThisServer(ScheduleModel $row): bool
    {
        return match ($row->type) {
            ScheduleType::Artisan => $this->artisanCommandExists($row),
            ScheduleType::Job => class_exists(ltrim(trim((string) $row->command), '\\')),
            default => true,
        };
    }

    /**
     * 행의 Artisan 명령이 이 서버에 있는지 판정합니다.
     *
     * 콘솔 커널에 없으면 실행기(`ScheduleExecutor::registerCommandClassForWeb`)가 웹 요청에서 등록하는 것과 같은 클래스 — 행의
     * `command_class`, 없으면 활성 확장의 명령 디렉터리에서 찾은 클래스 — 를 같은 조건(커맨드 하위 클래스이고 명령 이름이 같음)으로
     * 본다. 선언한 `command_class` 가 그 조건에 맞지 않으면 실행기처럼 명령 디렉터리로 다시 찾지 않는다(실행기도 등록하지 않는다).
     *
     * @param  ScheduleModel  $row  스케줄 행
     * @return bool 명령이 있거나 명령명을 읽을 수 없으면 true
     *
     * @throws Throwable 콘솔 커널 명령 목록·명령 클래스를 읽다 난 예외 (호출부가 fail-open 으로 처리)
     */
    private function artisanCommandExists(ScheduleModel $row): bool
    {
        $name = ScheduleCommandValidator::extractArtisanCommandName((string) $row->command);
        if ($name === null) {
            return true;
        }

        if (array_key_exists($name, $this->registeredCommands())) {
            return true;
        }

        $class = (string) ($row->command_class ?? '');
        if ($class === '') {
            $class = (string) (ScheduleCommandValidator::resolveExtensionCommandClass($name) ?? '');
        }

        return $class !== '' && $this->isCommandClassNamed($class, $name);
    }

    /**
     * 이 프로세스의 콘솔 커널 명령 목록을 돌려줍니다 — 부팅이 끝난 뒤(실행 시점)에만 불린다.
     *
     * 처음 한 번만 읽는다. 읽기가 실패하면 그 예외를 기억해 두고 이후 호출에도 같은 예외를 던진다(행마다 커널을 다시 읽지 않게).
     *
     * @return array<string, mixed> 명령명 → 명령
     *
     * @throws Throwable 명령 목록을 읽지 못했을 때
     */
    private function registeredCommands(): array
    {
        if ($this->registeredCommandsError !== null) {
            throw $this->registeredCommandsError;
        }

        if ($this->registeredCommands === null) {
            try {
                $this->registeredCommands = app(ConsoleKernel::class)->all();
            } catch (Throwable $e) {
                $this->registeredCommandsError = $e;

                throw $e;
            }
        }

        return $this->registeredCommands;
    }

    /**
     * 클래스가 주어진 이름의 Artisan 커맨드인지 판정합니다 — 실행기의 등록 조건과 같다.
     *
     * @param  string  $class  커맨드 클래스
     * @param  string  $name  행의 명령명
     * @return bool 커맨드 하위 클래스이고 명령 이름이 같으면 true
     */
    private function isCommandClassNamed(string $class, string $name): bool
    {
        if (! class_exists($class) || ! is_subclass_of($class, Command::class)) {
            return false;
        }

        return app($class)->getName() === $name;
    }

    /**
     * 대상 판정이 예외로 끝난 것을 경고로 남깁니다 — 같은 예외는 행이 여럿이어도 하루 한 번만.
     *
     * 판정 실패는 보통 행이 아니라 서버 쪽 원인(콘솔 커널 명령 하나의 생성 예외 등)이라 모든 행이 같은 예외를 낸다. 키는 예외
     * 클래스·메시지로 만든다. 캐시 저장소 오류면 경고를 그대로 남긴다.
     *
     * @param  ScheduleModel  $row  처음 실패한 스케줄 행 (진단용)
     * @param  Throwable  $e  판정 중 예외
     * @return void 반환값 없음
     */
    private function warnTargetCheckFailedOnce(ScheduleModel $row, Throwable $e): void
    {
        $key = self::TARGET_CHECK_FAILED_WARNED_PREFIX.sha1($e::class.'|'.$e->getMessage());

        try {
            $cache = $this->warningCache();
            if ($cache->has($key)) {
                return;
            }
            $cache->put($key, true, self::MISSING_TARGET_WARN_TTL);
        } catch (Throwable) {
            // 캐시를 읽을 수 없으면 한 번 거르기를 포기하고 경고를 남긴다
        }

        Log::warning('DB 스케줄 대상 판정 실패 — 건너뛰지 않고 실행합니다', [
            'schedule_id' => (int) $row->id,
            'type' => $row->type?->value,
            'command' => $row->command,
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * 이 서버에 없는 대상 때문에 건너뛴 행을 경고로 남깁니다 — 같은 행·대상은 하루 한 번만.
     *
     * 매 분 새 프로세스로 도는 `schedule:run` 이 매번 같은 경고를 쌓지 않도록 캐시 키로 한 번만 남긴다. 키는 코드 범위다 —
     * 「이 서버의 코드에 대상이 없다」는 판정이라 코드가 다른 검수 서버와 운영 서버가 나눠 갖는다. 대상이 바뀌면(command 갱신)
     * 키가 달라 다시 남긴다. 캐시 저장소 오류면 경고를 그대로 남긴다(드라이버가 쓰기 실패를 삼킨다).
     *
     * @param  ScheduleModel  $row  스케줄 행
     * @return void 반환값 없음
     */
    private function warnMissingTargetOnce(ScheduleModel $row): void
    {
        $key = self::MISSING_TARGET_WARNED_PREFIX.(int) $row->id.'.'.sha1($row->type?->value.'|'.(string) $row->command);
        $cache = $this->warningCache();

        try {
            if ($cache->has($key)) {
                return;
            }
            $cache->put($key, true, self::MISSING_TARGET_WARN_TTL);
        } catch (Throwable) {
            // 캐시를 읽을 수 없으면 한 번 거르기를 포기하고 경고를 남긴다
        }

        Log::warning('DB 스케줄 실행 건너뜀 — 이 서버에 없는 명령·작업입니다', [
            'schedule_id' => (int) $row->id,
            'type' => $row->type?->value,
            'command' => $row->command,
            'extension_type' => $row->extension_type?->value,
            'extension_identifier' => $row->extension_identifier,
        ]);
    }

    /**
     * 「이 서버에 없는 대상」 경고의 한 번 거르기에 쓰는 코어 캐시 드라이버 (코드 범위).
     *
     * @return CacheInterface 코어 캐시 드라이버
     */
    private function warningCache(): CacheInterface
    {
        return CacheDriverFactory::core(CacheScope::Code);
    }

    /**
     * 행 하나를 `schedule:{id}` 이벤트로 등록합니다.
     *
     * @param  Schedule  $schedule  Laravel 스케줄러
     * @param  ScheduleModel  $row  스케줄 행
     * @return void 반환값 없음
     */
    private function registerRow(Schedule $schedule, ScheduleModel $row): void
    {
        $id = (int) $row->id;

        // 계기·실행자는 실행 문맥에서 읽는다 — 서버 크론에서는 늘 예약 실행이고, 웹 러너가 관리자의
        // [지금 실행]으로 돌릴 때만 수동 실행과 누른 사람이 실린다. 결과는 문맥에 돌려줘 러너 요약이
        // 실행기가 마감한 성공·실패를 그대로 센다.
        $event = $schedule->call(static function () use ($id): void {
            try {
                ScheduleRunContext::report(
                    app(ScheduleDispatcher::class)->dispatch($id, ScheduleRunContext::trigger(), ScheduleRunContext::userId()),
                );
            } catch (Throwable $e) {
                ScheduleRunContext::report($e);
                // 실행 실패는 실행기가 이력으로 남긴다 — 여기는 실행 전 거부(비활성 확장, 수동 실행 중복 등)만 온다.
                Log::warning('DB 스케줄 실행 거부', ['schedule_id' => $id, 'error' => $e->getMessage()]);
            }
        })->name(self::EVENT_PREFIX.$id);

        if ($row->frequency === ScheduleFrequency::EveryThirtySeconds) {
            $event->everyThirtySeconds();
        } else {
            $event->cron((string) $row->expression);
        }

        // 행 시간대가 없으면 스케줄러 전체 기준(app.schedule_timezone)을 그대로 쓴다.
        if (! empty($row->timezone)) {
            $event->timezone((string) $row->timezone);
        }

        if ($row->run_in_maintenance) {
            $event->evenInMaintenanceMode();
        }

        if ($row->on_one_server) {
            $event->onOneServer();
        }

        // 이 서버에 없는 명령·작업은 매 주기 실패 이력·오류 로그·실패 알림만 쌓는다 — 실행하지 않고 건너뛴다 (실행 역할과 무관).
        // 판정은 실행 시점이다 — 등록은 콘솔 커널 부팅 도중이라 그때 명령 목록을 읽으면 부팅이 다시 돈다.
        // 판정이 예외면 건너뛰지 않는다(fail-open) — 예외가 새면 모든 행의 필터 판정이 예외가 돼 웹 크론 실행 전체가 멈춘다.
        $event->skip(function () use ($row): bool {
            try {
                if ($this->targetExistsOnThisServer($row)) {
                    return false;
                }
            } catch (Throwable $e) {
                $this->warnTargetCheckFailedOnce($row, $e);

                return false;
            }
            $this->warnMissingTargetOnce($row);

            return true;
        });

        if (! empty($row->enabled_condition)) {
            $conditions = $this->conditions;
            $event->when(static fn (): bool => $conditions->passes($row));
        }
    }
}

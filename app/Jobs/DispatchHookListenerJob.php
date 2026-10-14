<?php

// audit:allow job-generator-needs-scale-test 큐 옵션 적용·이벤트 ID 운반만 추가 — 잡 1건이 리스너 1건을 부르는 단위는
// 그대로다. 수신자 수에 따라 커지는 규모 경로(알림 대량 발송)는 tests/scenarios/notification-fanout-dedupe.yaml 의 scale 이 커버.

namespace App\Jobs;

use App\Exceptions\RetryHookListenerLaterException;
use App\Extension\HookArgumentSerializer;
use App\Extension\HookContextCapture;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 훅 리스너를 큐에서 실행하는 범용 Job
 *
 * HookListenerRegistrar가 doAction 리스너를 큐로 디스패치할 때 사용합니다.
 * 리스너 클래스명과 메서드명, 직렬화된 인자를 저장하고,
 * 큐 워커에서 실행 시 DI 컨테이너로 리스너를 재생성하여 호출합니다.
 *
 * 리스너가 `getSubscribedHooks()` 의 `'queue'` 옵션을 선언하면 디스패치 직전에 아래 프로퍼티가 채워진다
 * (ListenerQueueOptions::apply). 라라벨은 이 값을 **디스패치 시점** 잡 객체에서 읽어 payload 에 굳히므로,
 * 재시도 중에 설정이 바뀌어도 그 잡은 처음 값을 유지한다. 옵션이 없으면 종전 기본값 그대로다(#139).
 *
 * 새 프로퍼티는 모두 기본값이 있는 일반 프로퍼티다 — unserialize 는 생성자를 돌리지 않으므로,
 * 업데이트 전에 쌓인 옛 payload 에서도 미초기화 오류 없이 기본값으로 읽힌다.
 */
class DispatchHookListenerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * 최대 재시도 횟수
     */
    public int $tries = 3;

    /**
     * 제한 시간(초) — null 이면 워커의 --timeout
     */
    public ?int $timeout = null;

    /**
     * 재시도 간격(초) — 정수 또는 시도별 목록
     *
     * @var int|array<int, int>|null
     */
    public int|array|null $backoff = null;

    /**
     * 재시도 마감 시각 — 있으면 tries 대신 이 시각까지 재시도한다(라라벨 규칙)
     */
    public ?\DateTimeInterface $retryUntil = null;

    /**
     * 실패로 처리할 예외 횟수 — 재예약·release 는 세지 않는다
     */
    public ?int $maxExceptions = null;

    /**
     * 제한 시간 초과를 재시도 없이 실패로 처리할지
     */
    public bool $failOnTimeout = false;

    /**
     * 훅 발화(리스너 잡) 단위 식별자 — 재시도·재예약된 사본도 같은 값을 갖는다
     *
     * 리스너가 「이 사건에서 이미 한 일」을 가려낼 때 쓴다(예: 알림 수신자별 중복 방지 키). 실행 중에는
     * HookContextCapture::eventId() 로 읽는다. 옛 payload 는 null 이라 큐 잡 uuid 로 대신한다.
     */
    public ?string $eventId = null;

    /**
     * @param  string  $listenerClass  리스너 FQCN
     * @param  string  $method  실행할 메서드명
     * @param  array  $serializedArgs  HookArgumentSerializer로 직렬화된 인자 배열
     * @param  array  $context  HookContextCapture::capture() 결과 (Auth/Request/Locale 스냅샷)
     */
    public function __construct(
        public readonly string $listenerClass,
        public readonly string $method,
        public readonly array $serializedArgs,
        public readonly array $context = [],
    ) {
        $this->afterCommit = true;
        $this->eventId = (string) Str::uuid();
    }

    /**
     * Job을 실행합니다.
     *
     * @return void 반환값 없음
     */
    public function handle(): void
    {
        // 큐 드라이버가 sync 면 이 Job 은 원 HTTP 요청과 **같은 컨테이너**에서 실행된다.
        // restore() 가 재구성 Request 를 바인딩하면 그 뒤로 이어지는 같은 요청의 코드는
        // 헤더·입력·메서드가 전부 지워진 가짜 GET 요청을 보게 된다 — 뒤 순위 훅 리스너가
        // 전송 헤더를 읽지 못해 조용히 건너뛰는 사고가 여기서 난다.
        // 워커 프로세스에서는 원래 바인딩이 없어 아래 원복이 아무 일도 하지 않는다.
        $previousRequest = app()->bound('request') ? app('request') : null;
        // 데몬 워커는 컨테이너를 작업 간에 재사용하므로, 복원한 진입점도 끝나면 되돌린다
        // — 남겨 두면 다음 작업(다른 진입점에서 온 것)이 이 값을 물려받는다.
        $previousOrigin = app()->bound(HookContextCapture::ORIGIN_BINDING)
            ? app(HookContextCapture::ORIGIN_BINDING)
            : null;
        // 인증 사용자도 같은 이유로 되돌린다. restore() 는 컨텍스트에 user_id 가 없으면 인증을 건드리지
        // 않으므로, 앞 작업(회원)이 setUser() 한 사용자가 다음 작업(CLI·스케줄러)에 남아 활동 로그
        // 행위자가 그 회원으로 기록됐다(#138 실측). hasUser() 로 읽어 인증 해석을 새로 일으키지 않는다.
        $previousUser = Auth::hasUser() ? Auth::user() : null;
        // 이벤트 ID 도 같은 이유로 되돌린다 — 데몬 워커의 다음 잡이 앞 잡의 ID 를 물려받으면 안 된다.
        $previousEventId = app()->bound(HookContextCapture::EVENT_ID_BINDING)
            ? app(HookContextCapture::EVENT_ID_BINDING)
            : null;

        try {
            HookContextCapture::restore($this->context);

            $eventId = $this->eventId ?? $this->job?->uuid();
            if ($eventId !== null && $eventId !== '') {
                app()->instance(HookContextCapture::EVENT_ID_BINDING, (string) $eventId);
            }

            $args = HookArgumentSerializer::deserialize($this->serializedArgs);

            try {
                $listener = app($this->listenerClass);
            } catch (BindingResolutionException $e) {
                // 리스너를 만들 수 없다는 것은 그 확장이 이 런타임에 배선되지 않았다는 뜻이다.
                // Action 훅은 부가 작업이므로 호출자의 작업까지 함께 실패시키지 않고 기록만 남긴다
                // (큐 드라이버가 sync 인 환경에서 원 요청이 500 이 되는 것을 막는다).
                Log::warning('훅 리스너를 해석할 수 없어 건너뜁니다 (확장 미배선)', [
                    'listener' => $this->listenerClass,
                    'method' => $this->method,
                    'exception' => $e->getMessage(),
                ]);

                return;
            }

            try {
                $listener->{$this->method}(...$args);
            } catch (RetryHookListenerLaterException $e) {
                // 다른 사본이 같은 일을 처리 중이라 잠시 뒤 다시 실행한다 — 실패가 아니다.
                // sync 드라이버는 다시 실행할 큐가 없으므로 기록만 남긴다(요청 안에서 끝까지 기다리지 않는다).
                if ($this->job !== null && ! $this->job instanceof SyncJob) {
                    $this->release($e->delaySeconds);
                } else {
                    Log::warning('훅 리스너가 재실행을 요청했지만 sync 드라이버라 다시 실행하지 않습니다', [
                        'listener' => $this->listenerClass,
                        'method' => $this->method,
                        'delay_seconds' => $e->delaySeconds,
                        'reason' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            if ($previousRequest !== null) {
                app()->instance('request', $previousRequest);
            }

            if ($previousOrigin !== null) {
                app()->instance(HookContextCapture::ORIGIN_BINDING, $previousOrigin);
            } else {
                app()->forgetInstance(HookContextCapture::ORIGIN_BINDING);
            }

            if ($previousUser !== null) {
                Auth::setUser($previousUser);
            } else {
                Auth::forgetUser();
            }

            if ($previousEventId !== null) {
                app()->instance(HookContextCapture::EVENT_ID_BINDING, $previousEventId);
            } else {
                app()->forgetInstance(HookContextCapture::EVENT_ID_BINDING);
            }
        }
    }

    /**
     * Job 실패 시 로그를 기록합니다.
     *
     * @param  \Throwable  $exception  발생한 예외
     * @return void 반환값 없음
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('훅 리스너 큐 작업 실패', [
            'listener' => $this->listenerClass,
            'method' => $this->method,
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }

    /**
     * Job의 표시 이름을 반환합니다.
     *
     * @return string 리스너 클래스와 메서드를 담은 표시 이름
     */
    public function displayName(): string
    {
        return "HookListener:{$this->listenerClass}@{$this->method}";
    }
}

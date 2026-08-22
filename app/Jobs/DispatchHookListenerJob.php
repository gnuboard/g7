<?php

// audit:allow job-generator-needs-scale-test 이번 변경은 리스너 1건을 해석하는 지점에 가드를 더한 것으로,
// 처리 건수에 따라 동작이 달라지는 경로(배치·생성기)를 도입하지 않는다. 디스패치 단위 의미는 종전과 동일.

namespace App\Jobs;

use App\Extension\HookArgumentSerializer;
use App\Extension\HookContextCapture;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * 훅 리스너를 큐에서 실행하는 범용 Job
 *
 * HookListenerRegistrar가 doAction 리스너를 큐로 디스패치할 때 사용합니다.
 * 리스너 클래스명과 메서드명, 직렬화된 인자를 저장하고,
 * 큐 워커에서 실행 시 DI 컨테이너로 리스너를 재생성하여 호출합니다.
 */
class DispatchHookListenerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * 최대 재시도 횟수
     */
    public int $tries = 3;

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
    }

    /**
     * Job을 실행합니다.
     */
    public function handle(): void
    {
        // 큐 드라이버가 sync 면 이 Job 은 원 HTTP 요청과 **같은 컨테이너**에서 실행된다.
        // restore() 가 재구성 Request 를 바인딩하면 그 뒤로 이어지는 같은 요청의 코드는
        // 헤더·입력·메서드가 전부 지워진 가짜 GET 요청을 보게 된다 — 뒤 순위 훅 리스너가
        // 전송 헤더를 읽지 못해 조용히 건너뛰는 사고가 여기서 난다.
        // 워커 프로세스에서는 원래 바인딩이 없어 아래 원복이 아무 일도 하지 않는다.
        $previousRequest = app()->bound('request') ? app('request') : null;

        try {
            HookContextCapture::restore($this->context);

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

            $listener->{$this->method}(...$args);
        } finally {
            if ($previousRequest !== null) {
                app()->instance('request', $previousRequest);
            }
        }
    }

    /**
     * Job 실패 시 로그를 기록합니다.
     *
     * @param  \Throwable  $exception  발생한 예외
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
     */
    public function displayName(): string
    {
        return "HookListener:{$this->listenerClass}@{$this->method}";
    }
}

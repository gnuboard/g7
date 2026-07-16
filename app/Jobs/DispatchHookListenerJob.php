<?php

namespace App\Jobs;

use App\Extension\HookArgumentSerializer;
use App\Extension\HookContextCapture;
use Illuminate\Bus\Queueable;
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
     *
     * @var int
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
     *
     * @return void
     */
    public function handle(): void
    {
        // 큐 워커 컨텍스트에 원래 요청의 Auth/Request/Locale 복원
        // (Job 종료 시 컨테이너 폐기되므로 finally 원복 불필요)
        HookContextCapture::restore($this->context);

        $args = HookArgumentSerializer::deserialize($this->serializedArgs);
        app($this->listenerClass)->{$this->method}(...$args);
    }

    /**
     * Job 실패 시 로그를 기록합니다.
     *
     * @param  \Throwable  $exception  발생한 예외
     * @return void
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
     * @return string
     */
    public function displayName(): string
    {
        return "HookListener:{$this->listenerClass}@{$this->method}";
    }
}

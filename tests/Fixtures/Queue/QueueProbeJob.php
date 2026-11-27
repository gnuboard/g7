<?php

namespace Tests\Fixtures\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * 큐 관리 테스트용 탐침 작업
 *
 * 실제 직렬화·워커 경로를 그대로 타도록 진짜 큐 작업으로 둔다. `$shouldFail` 로 실패를 켜고, `$released` 로
 * 재시도 대기(release)를 켠다. 처리 횟수는 `$handled` 로 센다. 테스트마다 {@see self::reset()} 으로 초기화한다.
 */
class QueueProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 최종 실패까지 시도 횟수 */
    public int $tries = 1;

    /** 처리할 때 예외를 던질지 */
    public static bool $shouldFail = false;

    /** 처리할 때 재시도 대기로 돌려보낼지 */
    public static bool $released = false;

    /** 처리 횟수 */
    public static int $handled = 0;

    /**
     * 표지를 받아 탐침 작업을 만듭니다.
     *
     * @param  string  $label  payload 검색 대상으로 쓰는 표지
     */
    public function __construct(public readonly string $label = 'probe') {}

    /**
     * 작업을 처리합니다.
     *
     * @return void 반환값 없음
     *
     * @throws RuntimeException $shouldFail 이 켜졌을 때
     */
    public function handle(): void
    {
        self::$handled++;

        if (self::$released) {
            $this->release(60);

            return;
        }

        if (self::$shouldFail) {
            throw new RuntimeException('probe failure: '.$this->label);
        }
    }

    /**
     * 정적 상태를 초기화합니다.
     *
     * @return void 반환값 없음
     */
    public static function reset(): void
    {
        self::$shouldFail = false;
        self::$released = false;
        self::$handled = 0;
    }
}

<?php

namespace Plugins\Gnuboard7\HelloPlugin\Services;

use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Log;

/**
 * Hello 플러그인 로그 서비스
 *
 * `storage/logs/hello-plugin.log` 파일에 기록을 남기는 단순한 래퍼입니다.
 * 학습용 샘플이므로 Monolog 채널을 런타임에 즉석 구성합니다.
 */
class HelloLogService
{
    /**
     * 로그 채널 이름
     */
    public const CHANNEL = 'gnuboard7-hello_plugin';

    /**
     * 로그 파일 상대 경로 (storage_path 기준)
     */
    public const LOG_FILE = 'logs/hello-plugin.log';

    /**
     * 메시지를 hello-plugin.log 에 기록합니다.
     *
     * @param  string  $message  기록할 메시지
     * @param  array<string, mixed>  $context  부가 컨텍스트
     * @return void
     */
    public function log(string $message, array $context = []): void
    {
        $this->resolveChannel()->info($message, $context);
    }

    /**
     * 전용 Monolog 채널을 획득합니다.
     *
     * 채널 설정이 등록되어 있지 않으면 런타임에 설정을 주입해 생성합니다.
     *
     * @return \Psr\Log\LoggerInterface 로거 인스턴스
     */
    private function resolveChannel(): \Psr\Log\LoggerInterface
    {
        $logManager = Log::getFacadeRoot();

        // Laravel 의 LogManager 일 경우에만 동적 채널 구성
        if ($logManager instanceof LogManager) {
            $config = config('logging.channels.'.self::CHANNEL);
            if ($config === null) {
                config([
                    'logging.channels.'.self::CHANNEL => [
                        'driver' => 'single',
                        'path' => storage_path(self::LOG_FILE),
                        'level' => 'debug',
                    ],
                ]);
            }
        }

        return Log::channel(self::CHANNEL);
    }
}

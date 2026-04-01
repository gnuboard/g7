<?php

namespace App\ActivityLog;

use Monolog\Logger;

/**
 * Monolog 커스텀 채널 팩토리.
 *
 * config/logging.php의 'activity' 채널에서 via 클래스로 사용됩니다.
 * Laravel의 custom 드라이버가 __invoke($config)를 호출하여 Monolog Logger를 반환합니다.
 */
class ActivityLogChannel
{
    /**
     * 활동 로그 전용 Monolog Logger 인스턴스를 생성합니다.
     *
     * @param array $config 채널 설정
     * @return Logger
     */
    public function __invoke(array $config): Logger
    {
        $logger = new Logger('activity');
        $logger->pushProcessor(new ActivityLogProcessor);
        $logger->pushHandler(new ActivityLogHandler);

        return $logger;
    }
}

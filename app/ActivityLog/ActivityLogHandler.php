<?php

namespace App\ActivityLog;

use App\Enums\ActivityLogType;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * 활동 로그 전용 Monolog Handler.
 *
 * Monolog context에서 구조화 데이터를 추출하여 activity_logs 테이블에 저장합니다.
 * ActivityLogChannel에서 등록되어 Log::channel('activity') 호출 시 동작합니다.
 */
class ActivityLogHandler extends AbstractProcessingHandler
{
    /**
     * @param Level $level 최소 로그 레벨
     * @param bool $bubble 버블링 여부
     */
    public function __construct(Level $level = Level::Debug, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    /**
     * 로그 레코드를 activity_logs 테이블에 저장합니다.
     *
     * @param LogRecord $record Monolog 로그 레코드
     * @return void
     */
    protected function write(LogRecord $record): void
    {
        if (! config('activity_log.enabled', true)) {
            return;
        }

        $context = $record->context;

        $data = [
            'log_type' => $context['log_type'] ?? ActivityLogType::System,
            'action' => $record->message,
            'description_key' => $context['description_key'] ?? null,
            'description_params' => $context['description_params'] ?? null,
            'properties' => $context['properties'] ?? null,
            'changes' => $context['changes'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'ip_address' => $context['ip_address'] ?? null,
            'user_agent' => $this->truncateUserAgent($context['user_agent'] ?? null),
        ];

        $loggable = $context['loggable'] ?? null;
        if ($loggable instanceof Model) {
            $data['loggable_type'] = $loggable->getMorphClass();
            $data['loggable_id'] = $loggable->getKey();
        } elseif (isset($context['loggable_type'], $context['loggable_id'])) {
            $data['loggable_type'] = $context['loggable_type'];
            $data['loggable_id'] = $context['loggable_id'];
        }

        ActivityLog::create($data);
    }

    /**
     * User-Agent 문자열을 최대 500자로 자릅니다.
     *
     * @param string|null $userAgent User-Agent 문자열
     * @return string|null 잘린 User-Agent
     */
    private function truncateUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null) {
            return null;
        }

        return mb_substr($userAgent, 0, 500);
    }
}

<?php

namespace App\ActivityLog;

use App\Extension\HookContextCapture;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * 활동 로그 전용 Monolog Processor.
 *
 * 요청별 공통 데이터(user_id, ip_address, user_agent)를 자동 주입합니다.
 * 리스너에서 명시적으로 전달하지 않은 경우에만 주입됩니다.
 *
 * CLI(Artisan 커맨드·스케줄러)에서 시작된 작업이면 properties.via = 'cli' 를 남겨, 행위자가 없는
 * 로그를 관리자 화면이 "시스템(CLI)" 로 구분할 수 있게 한다(#138). 웹 요청은 표기하지 않는다 —
 * 표기 부재가 곧 웹이며, 모든 웹 로그의 properties 계약을 바꾸지 않기 위함이다.
 */
class ActivityLogProcessor implements ProcessorInterface
{
    /**
     * 로그 레코드에 요청 컨텍스트 정보를 자동 주입합니다.
     *
     * @param  LogRecord  $record  Monolog 로그 레코드
     * @return LogRecord 컨텍스트가 주입된 로그 레코드
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $record->context;

        // 리스너에서 명시적으로 전달하지 않은 경우에만 자동 주입
        $context['user_id'] ??= Auth::id();
        $context['ip_address'] ??= Request::ip();
        $context['user_agent'] ??= Request::userAgent();

        if (HookContextCapture::origin() === 'cli') {
            $properties = is_array($context['properties'] ?? null) ? $context['properties'] : [];
            $properties['via'] ??= 'cli';
            $context['properties'] = $properties;
        }

        return $record->with(context: $context);
    }
}

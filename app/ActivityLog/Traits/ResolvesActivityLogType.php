<?php

namespace App\ActivityLog\Traits;

use App\Enums\ActivityLogType;
use Illuminate\Support\Facades\Log;

/**
 * 활동 로그 기록 및 로그 타입 자동 결정 트레이트
 *
 * Service 훅이 관리자/사용자/시스템 등 다양한 컨텍스트에서 호출될 수 있으므로
 * 요청 경로를 기반으로 log_type을 동적으로 결정합니다.
 *
 * - /api/admin/* 경로 → ActivityLogType::Admin
 * - 그 외 HTTP 요청 → ActivityLogType::User
 * - CLI/스케줄러(request 없음) → ActivityLogType::System
 */
trait ResolvesActivityLogType
{
    /**
     * 요청 경로를 기반으로 로그 타입을 결정합니다.
     *
     * @return ActivityLogType 결정된 로그 타입
     */
    protected function resolveLogType(): ActivityLogType
    {
        $request = request();

        if ($request && $request->path() !== '/') {
            return $request->is('api/admin/*')
                ? ActivityLogType::Admin
                : ActivityLogType::User;
        }

        return ActivityLogType::System;
    }

    /**
     * 활동 로그를 기록합니다.
     *
     * context에 log_type이 명시되지 않으면 resolveLogType()으로 자동 결정합니다.
     *
     * @param string $action 액션명 (예: 'user.create')
     * @param array $context Monolog context 배열
     */
    protected function logActivity(string $action, array $context): void
    {
        $context['log_type'] ??= $this->resolveLogType();

        try {
            Log::channel('activity')->info($action, $context);
        } catch (\Exception $e) {
            Log::error('Failed to record activity log', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

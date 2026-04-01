<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * 확장 업데이트 후 큐 워커 재시작 리스너
 *
 * 모듈/플러그인 업데이트 성공 시 큐 워커를 재시작하여
 * 새로운 코드가 즉시 반영되도록 합니다.
 */
class ExtensionUpdateQueueListener implements HookListenerInterface
{
    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.modules.after_update' => ['method' => 'handleAfterUpdate', 'priority' => 100],
            'core.plugins.after_update' => ['method' => 'handleAfterUpdate', 'priority' => 100],
        ];
    }

    /**
     * 훅 이벤트 처리 (기본 핸들러)
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     */
    public function handle(...$args): void
    {
        // 기본 핸들러는 사용하지 않음 (개별 메서드로 라우팅됨)
    }

    /**
     * 확장 업데이트 후 큐 워커를 재시작합니다.
     *
     * @param  string  $identifier  확장 식별자
     * @param  array  $result  업데이트 결과 배열 (['success' => bool, ...])
     * @param  array|null  $info  확장 정보
     */
    public function handleAfterUpdate(string $identifier, array $result, ?array $info = null): void
    {
        // 업데이트 실패 시 재시작 불필요
        if (! ($result['success'] ?? false)) {
            return;
        }

        try {
            Artisan::call('queue:restart');

            Log::info('확장 업데이트 후 큐 워커 재시작 완료', [
                'identifier' => $identifier,
            ]);
        } catch (\Throwable $e) {
            // 큐 재시작 실패는 업데이트 자체에 영향을 주지 않음
            Log::warning('확장 업데이트 후 큐 워커 재시작 실패', [
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

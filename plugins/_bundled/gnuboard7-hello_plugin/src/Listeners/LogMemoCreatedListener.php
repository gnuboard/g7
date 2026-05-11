<?php

namespace Plugins\Gnuboard7\HelloPlugin\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\HookManager;
use App\Services\PluginSettingsService;
use Plugins\Gnuboard7\HelloPlugin\Services\HelloLogService;

/**
 * Hello 모듈 메모 생성 훅 로그 리스너 (Action 훅 시연)
 *
 * Hello 모듈이 발행하는 `gnuboard7-hello_module.memo.created` Action 훅을 구독해
 * 플러그인 설정의 `log_enabled` 가 true 일 때만 로그 파일에 기록합니다.
 * 기록 직후 자체 훅 `gnuboard7-hello_plugin.log.written` 을 발행해
 * 플러그인이 훅 소비자이자 생산자가 될 수 있음을 시연합니다.
 */
class LogMemoCreatedListener implements HookListenerInterface
{
    /**
     * 플러그인 식별자
     */
    private const PLUGIN_ID = 'gnuboard7-hello_plugin';

    /**
     * 구독할 훅 목록 반환
     *
     * Action 훅은 type 생략 시 기본값 'action' 으로 처리됩니다.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'gnuboard7-hello_module.memo.created' => [
                'method' => 'onMemoCreated',
                'priority' => 10,
            ],
        ];
    }

    /**
     * HookListenerInterface 기본 핸들러 (미사용)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    /**
     * 메모 생성 시 로그 파일에 기록합니다.
     *
     * @param  mixed  ...$args  훅 인자 (첫 번째: Memo 모델)
     * @return void
     */
    public function onMemoCreated(...$args): void
    {
        // 설정 확인 — log_enabled 가 false 면 조용히 스킵
        /** @var PluginSettingsService $settings */
        $settings = app(PluginSettingsService::class);
        $enabled = (bool) $settings->get(self::PLUGIN_ID, 'log_enabled', true);

        if (! $enabled) {
            return;
        }

        $memo = $args[0] ?? null;

        $context = [
            'memo_id' => is_object($memo) ? ($memo->id ?? null) : null,
            'memo_uuid' => is_object($memo) ? ($memo->uuid ?? null) : null,
            'memo_title' => is_object($memo) ? ($memo->title ?? null) : null,
        ];

        /** @var HelloLogService $logService */
        $logService = app(HelloLogService::class);
        $logService->log('[HelloPlugin] Memo created hook received', $context);

        // 플러그인 자체 훅 발행 데모 — 다른 확장이 이 훅을 구독할 수 있습니다
        HookManager::doAction(
            'gnuboard7-hello_plugin.log.written',
            '[HelloPlugin] Memo created hook received',
            $context,
        );
    }
}

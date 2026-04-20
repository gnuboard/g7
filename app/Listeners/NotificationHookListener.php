<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\HookManager;
use App\Models\NotificationDefinition;
use App\Notifications\GenericNotification;
use App\Services\NotificationDefinitionService;
use App\Services\NotificationRecipientResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 알림 훅 리스너
 *
 * 코어 훅 이벤트 발생 시 알림 정의를 조회하여 GenericNotification을 발송합니다.
 */
class NotificationHookListener implements HookListenerInterface
{
    public function __construct(
        private readonly NotificationDefinitionService $definitionService,
    ) {}

    /**
     * 구독할 훅 목록을 반환합니다.
     *
     * DB 기반 동적 구독이므로 정적 메서드에서는 빈 배열을 반환하고,
     * boot 시점에 registerDynamicHooks()로 동적 구독합니다.
     */
    public static function getSubscribedHooks(): array
    {
        return [];
    }

    /**
     * 기본 핸들러 (미사용 — 동적 핸들러에서 처리).
     *
     * @param  mixed  ...$args
     */
    public function handle(...$args): void {}

    /**
     * notification_definitions에서 정의된 훅을 동적으로 구독합니다.
     *
     * ServiceProvider boot() 시점에 호출됩니다.
     */
    public function registerDynamicHooks(): void
    {
        if (! Schema::hasTable('notification_definitions')) {
            return;
        }

        try {
            $definitions = $this->definitionService->getAllActive();
        } catch (\Throwable $e) {
            Log::warning('NotificationHookListener: 알림 정의 로드 실패', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        foreach ($definitions as $definition) {
            $hooks = $definition->hooks ?? [];
            foreach ($hooks as $hook) {
                HookManager::addAction($hook, function (...$args) use ($definition) {
                    $this->dispatch($definition, $args);
                }, priority: 30);
            }
        }
    }

    /**
     * 훅 발화 시 알림을 발송합니다.
     *
     * 수신자 결정 우선순위:
     * 1. definition.recipients 설정 → NotificationRecipientResolver 사용
     * 2. extract_data 필터의 notifiables 배열
     * 3. extract_data 필터의 단일 notifiable (레거시 호환)
     *
     * @param NotificationDefinition $definition 알림 정의
     * @param array $args 훅 파라미터
     * @return void
     */
    private function dispatch(NotificationDefinition $definition, array $args): void
    {
        $extracted = HookManager::applyFilters(
            "{$definition->hook_prefix}.notification.extract_data",
            ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []],
            $definition->type,
            $args
        );

        $data = $extracted['data'] ?? [];
        $context = $extracted['context'] ?? [];

        // 활성 템플릿을 순회하며 채널별 독립 발송
        $templates = $definition->templates()->where('is_active', true)->get();
        if ($templates->isEmpty()) {
            return;
        }

        $resolver = app(NotificationRecipientResolver::class);

        foreach ($templates as $template) {
            // 채널별 수신자 결정: template.recipients 사용
            $recipientRules = $template->recipients ?? [];

            if (! empty($recipientRules)) {
                $notifiables = $resolver->resolve($recipientRules, $context);
            }
            // 레거시 fallback: extract_data의 notifiables
            elseif (! empty($extracted['notifiables'])) {
                $notifiables = collect($extracted['notifiables']);
            }
            // 레거시 fallback: 단일 notifiable
            elseif ($extracted['notifiable']) {
                $notifiables = collect([$extracted['notifiable']]);
            } else {
                continue;
            }

            if ($notifiables->isEmpty()) {
                continue;
            }

            foreach ($notifiables as $notifiable) {
                try {
                    $notifiableData = $data;
                    if (isset($notifiableData['name']) && $notifiableData['name'] === '{recipient_name}') {
                        $notifiableData['name'] = $notifiable->name ?? '';
                    }

                    $notification = new GenericNotification(
                        type: $definition->type,
                        hookPrefix: $definition->hook_prefix,
                        data: $notifiableData,
                        extensionType: $definition->extension_type,
                        extensionIdentifier: $definition->extension_identifier,
                        channel: $template->channel,
                    );

                    $notifiable->notify($notification);

                    HookManager::doAction('core.notification.after_send', $notifiable, $definition, $data);
                } catch (\Throwable $e) {
                    Log::error('NotificationHookListener: 알림 발송 실패', [
                        'type' => $definition->type,
                        'channel' => $template->channel,
                        'notifiable' => $notifiable->id ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}

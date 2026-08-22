<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\HookListenerRegistrar;
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
     *
     * @return array<string, array<string, mixed>> 빈 배열 (동적 등록)
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
        // 설치 완료 상태에서는 Schema introspection 을 건너뜀 (매 요청 information_schema 쿼리 제거).
        // 인스톨러 이전/마이그레이션 전 환경에서는 기존 hasTable 폴백으로 안전하게 스킵.
        if (! config('app.installer_completed') && ! Schema::hasTable('notification_definitions')) {
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
                // 발송을 큐로 디스패치한다 — 다른 코어/모듈 리스너(getSubscribedHooks → HookListenerRegistrar)
                // 가 큐로 가는 것과 동일한 정책. 직접 발송하면 요청 스레드에서 동기 실행되어, 수신자가
                // 많을 때(예: admin role 전체) 호출 요청(주문 생성 등)이 전체 발송 완료까지 막힌다.
                // 동적 구독은 정적 매핑으로 표현할 수 없으므로 Registrar 의 동적 진입점에 위임한다 —
                // 큐 래핑 로직(DispatchHookListenerJob/직렬화/컨텍스트 캡처)의 소유권을 Registrar 에
                // 유지하여 정적/동적 등록을 일관되게 만든다. 큐 드라이버가 sync 면 즉시 실행(하위호환).
                // $definition 을 boundArgs 로 고정 전달 → 워커가 dispatchForDefinition($definition, ...$args) 호출.
                HookListenerRegistrar::registerDynamicAction(
                    hookName: $hook,
                    listenerClass: self::class,
                    method: 'dispatchForDefinition',
                    boundArgs: [$definition],
                    priority: 30,
                );
            }
        }
    }

    /**
     * 큐 워커에서 호출되는 발송 진입점.
     *
     * registerDynamicHooks 가 등록한 큐 작업이 (NotificationDefinition $definition, ...$hookArgs)
     * 형태로 복원해 호출한다. 원래 훅 인자($hookArgs)를 배열로 모아 dispatch() 에 위임한다.
     *
     * @param  NotificationDefinition  $definition  알림 정의 (큐 직렬화 후 PK 로 복원됨)
     * @param  mixed  ...$hookArgs  원래 훅 발화 시 전달된 인자들
     */
    public function dispatchForDefinition(NotificationDefinition $definition, ...$hookArgs): void
    {
        $this->dispatch($definition, $hookArgs);
    }

    /**
     * 훅 발화 시 알림을 발송합니다.
     *
     * 수신자 결정 우선순위:
     * 1. definition.recipients 설정 → NotificationRecipientResolver 사용
     * 2. extract_data 필터의 notifiables 배열
     * 3. extract_data 필터의 단일 notifiable (레거시 호환)
     *
     * @param  NotificationDefinition  $definition  알림 정의
     * @param  array  $args  훅 파라미터
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

        // Listener 가 명시적으로 skip 을 요청한 경우 발송 중단
        // (e.g. 모듈 환경설정의 notify_* 플래그가 OFF 일 때, 템플릿 recipients 기반 해석이
        //  Listener 의 정책 gate 를 우회하는 문제 해결 — 2026-04-24 sirsoft-board report_policy)
        if (! empty($context['skip'])) {
            return;
        }

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

<?php

namespace App\Notifications;

use App\Contracts\Notifications\ChannelReadinessCheckerInterface;
use App\Enums\ExtensionOwnerType;
use App\Extension\HookManager;
use App\Mail\DbTemplateMail;
use App\Services\NotificationChannelService;
use App\Services\NotificationDefinitionService;
use App\Services\NotificationLogService;
use App\Services\NotificationTemplateService;
use Illuminate\Support\Facades\Log;

class GenericNotification extends BaseNotification
{
    /**
     * @param string $type 알림 타입 (welcome, order_confirmed 등)
     * @param string $hookPrefix 훅 접두사 (core.auth, sirsoft-ecommerce 등)
     * @param array $data 변수 데이터 (['name' => '홍길동', ...])
     * @param string $extensionType 확장 타입 (core, module, plugin)
     * @param string $extensionIdentifier 확장 식별자 (core, sirsoft-board 등)
     */
    /**
     * @param string $type 알림 타입 (welcome, order_confirmed 등)
     * @param string $hookPrefix 훅 접두사 (core.auth, sirsoft-ecommerce 등)
     * @param array $data 변수 데이터 (['name' => '홍길동', ...])
     * @param string $extensionType 확장 타입 (core, module, plugin)
     * @param string $extensionIdentifier 확장 식별자 (core, sirsoft-board 등)
     * @param string|null $channel 대상 채널 (지정 시 해당 채널만 발송, null이면 기존 다채널 로직)
     */
    public function __construct(
        private readonly string $type,
        private readonly string $hookPrefix,
        private readonly array $data = [],
        private readonly string $extensionType = 'core',
        private readonly string $extensionIdentifier = 'core',
        private readonly ?string $channel = null,
    ) {}

    /**
     * 훅 접두사 반환.
     *
     * @return string
     */
    protected function getHookPrefix(): string
    {
        return $this->hookPrefix;
    }

    /**
     * 알림 유형 반환.
     *
     * @return string
     */
    protected function getNotificationType(): string
    {
        return $this->type;
    }

    /**
     * 발송 채널 결정 — notification_definitions.channels 기반 + readiness 필터.
     *
     * 1. definition.channels 조회
     * 2. 훅 필터 적용 (플러그인 채널 추가/제거)
     * 3. readiness 필터 (미설정 채널 제외 + skipped 로깅)
     *
     * @param object $notifiable
     * @return array
     */
    public function via(object $notifiable): array
    {
        // 대상 채널이 지정된 경우 (template별 발송) — 확장 토글 + readiness 확인
        if ($this->channel !== null) {
            try {
                // 0단계: 확장 단위 채널 전역 활성 여부 확인
                $channelService = app(NotificationChannelService::class);
                if (! $channelService->isChannelEnabledForExtension(
                    $this->extensionType,
                    $this->extensionIdentifier,
                    $this->channel
                )) {
                    $this->logSkippedChannels([
                        ['channel' => $this->channel, 'reason' => 'notification.channel_disabled_by_extension'],
                    ], $notifiable);

                    return [];
                }

                $readinessChecker = app(ChannelReadinessCheckerInterface::class);
                if (! $readinessChecker->isReady($this->channel)) {
                    $this->logSkippedChannels([
                        ['channel' => $this->channel, 'reason' => $readinessChecker->check($this->channel)['reason']],
                    ], $notifiable);

                    return [];
                }

                return [$this->channel];
            } catch (\Throwable $e) {
                return [$this->channel];
            }
        }

        // 레거시: 다채널 자동 결정 (게시판 등 직접 발송 호환)
        $definitionService = app(NotificationDefinitionService::class);
        $definition = $definitionService->resolve($this->type);
        $channels = $definition?->channels ?? ['mail'];

        $channels = HookManager::applyFilters(
            "{$this->hookPrefix}.notification.channels",
            $channels,
            $this->type,
            $notifiable
        );

        // 확장 토글 + readiness + 템플릿 존재 필터
        try {
            $channelService = app(NotificationChannelService::class);
            $readinessChecker = app(ChannelReadinessCheckerInterface::class);
            $templateService = app(NotificationTemplateService::class);
            $readyChannels = [];
            $skippedChannels = [];

            foreach ($channels as $channel) {
                // 0단계: 확장 단위 채널 전역 활성 여부 확인
                if (! $channelService->isChannelEnabledForExtension(
                    $this->extensionType,
                    $this->extensionIdentifier,
                    $channel
                )) {
                    $skippedChannels[] = [
                        'channel' => $channel,
                        'reason' => 'notification.channel_disabled_by_extension',
                    ];

                    continue;
                }

                // 1단계: 채널 readiness 검사 (설정 완료 여부)
                if (! $readinessChecker->isReady($channel)) {
                    $skippedChannels[] = [
                        'channel' => $channel,
                        'reason' => $readinessChecker->check($channel)['reason'],
                    ];

                    continue;
                }

                // 2단계: 활성 템플릿 존재 여부 검사
                // 템플릿이 없으면 채널 제외 — 빈 subject/body로 DB에 저장되는 것을 방지
                $template = $templateService->resolve($this->type, $channel);
                if (! $template) {
                    $skippedChannels[] = [
                        'channel' => $channel,
                        'reason' => __('notification.channel_skipped_no_template', [
                            'channel' => $channel,
                            'type' => $this->type,
                        ]),
                    ];

                    continue;
                }

                $readyChannels[] = $channel;
            }

            if (! empty($skippedChannels)) {
                $this->logSkippedChannels($skippedChannels, $notifiable);
            }

            return $readyChannels;
        } catch (\Throwable $e) {
            // readiness 서비스 실패 시 기존 동작 유지 (모든 채널 발송 시도)
            Log::warning('ChannelReadiness 서비스 실패, 기존 채널 전체 발송', [
                'type' => $this->type,
                'error' => $e->getMessage(),
            ]);

            return $channels;
        }
    }

    /**
     * 미설정 채널 건너뛰기를 notification_logs에 기록합니다.
     *
     * @param array $skippedChannels [{channel, reason}]
     * @param object $notifiable
     * @return void
     */
    private function logSkippedChannels(array $skippedChannels, object $notifiable): void
    {
        try {
            $logService = app(NotificationLogService::class);
            foreach ($skippedChannels as $skipped) {
                $logService->logSkipped([
                    'channel' => $skipped['channel'],
                    'notification_type' => $this->type,
                    'extension_type' => $this->extensionType,
                    'extension_identifier' => $this->extensionIdentifier,
                    'recipient_identifier' => $notifiable->email ?? (string) ($notifiable->getKey() ?? ''),
                    'recipient_name' => $notifiable->name ?? null,
                    'recipient_user_id' => $notifiable->getKey() ?? null,
                    'error_message' => __($skipped['reason'] ?? 'notification.readiness.unknown'),
                    'source' => 'notification',
                    'sent_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Skipped 채널 로깅 실패', [
                'channels' => array_column($skippedChannels, 'channel'),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 메일 채널 처리 — notification_templates에서 mail 채널 템플릿 조회.
     *
     * @param object $notifiable
     * @return DbTemplateMail
     */
    public function toMail(object $notifiable): DbTemplateMail
    {
        $templateService = app(NotificationTemplateService::class);
        $template = $templateService->resolve($this->type, 'mail');

        $ownerType = ExtensionOwnerType::tryFrom($this->extensionType) ?? ExtensionOwnerType::Core;

        if (! $template || ! $template->is_active) {
            return DbTemplateMail::skipped(
                recipientEmail: $notifiable->email ?? '',
                templateType: $this->type,
                extensionType: $ownerType,
                extensionIdentifier: $this->extensionIdentifier,
                recipientName: $notifiable->name ?? null,
            );
        }

        $locale = $notifiable->locale ?? app()->getLocale();
        $rendered = $template->replaceVariables($this->data, $locale);

        return new DbTemplateMail(
            renderedSubject: $rendered['subject'],
            renderedBody: $rendered['body'],
            recipientEmail: $notifiable->email ?? '',
            templateType: $this->type,
            extensionType: $ownerType,
            extensionIdentifier: $this->extensionIdentifier,
            source: 'notification',
            recipientName: $notifiable->name ?? null,
        );
    }

    /**
     * 데이터베이스 채널 처리 — notification_templates에서 database 채널 템플릿 조회.
     *
     * @param object $notifiable
     * @return array
     */
    public function toArray(object $notifiable): array
    {
        $templateService = app(NotificationTemplateService::class);
        $template = $templateService->resolve($this->type, 'database');

        $locale = $notifiable->locale ?? app()->getLocale();

        if ($template && $template->is_active) {
            $rendered = $template->replaceVariables($this->data, $locale);

            // click_url 패턴이 있으면 변수 치환하여 포함 (알림센터 클릭 시 이동 URL)
            $clickUrl = $template->click_url
                ? $template->replaceVariablesInString($template->click_url, $this->data)
                : null;

            return [
                'type' => $this->type,
                'subject' => $rendered['subject'],
                'body' => $rendered['body'],
                'click_url' => $clickUrl,
                'data' => $this->data,
            ];
        }

        return [
            'type' => $this->type,
            'data' => $this->data,
        ];
    }

    /**
     * 미래 채널 자동 위임 (fcm 등).
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if (str_starts_with($method, 'to')) {
            $channel = lcfirst(substr($method, 2));

            return HookManager::applyFilters(
                "{$this->hookPrefix}.notification.to_{$channel}",
                $this->data,
                $this->type,
                $parameters[0] ?? null
            );
        }

        throw new \BadMethodCallException("Method [{$method}] does not exist on " . static::class);
    }

    /**
     * 알림 데이터 반환.
     *
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * 알림 타입 반환.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * 확장 타입 반환.
     *
     * @return string
     */
    public function getExtensionType(): string
    {
        return $this->extensionType;
    }

    /**
     * 확장 식별자 반환.
     *
     * @return string
     */
    public function getExtensionIdentifier(): string
    {
        return $this->extensionIdentifier;
    }
}

<?php

namespace App\Extension\Helpers;

use App\Contracts\Repositories\NotificationDefinitionRepositoryInterface;
use App\Notifications\Identity\IdentityNotificationType;
use Illuminate\Support\Facades\Log;

/**
 * (호환층) 확장의 `getIdentityMessages()` 선언을 본인인증 알림 정의 선언으로 바꿉니다.
 *
 * 7.1.0 부터 본인인증 메시지는 알림 정의(category=identity)다. 번들 확장은 `getNotificationDefinitions()`
 * 에 직접 선언하지만, 7.0.x 계약(`getIdentityMessages()`)으로 선언한 서드파티 확장도 계속 동작하도록
 * Manager 가 이 변환을 거쳐 알림 정의 동기화에 합류시킨다(@deprecated 7.1.0 — 8.0 에서 변환 제거).
 *
 * 변환 규칙:
 * - type = 옛 범위(provider_default/purpose/policy) → `identity.default` / `identity.purpose.{p}` /
 *   `identity.policy.{key}` (IdentityNotificationType::fromLegacyScope)
 * - category = identity, hook_prefix = 확장 식별자, hooks 없음, 채널 = 템플릿 채널(기본 mail)
 * - provider_id 가 코어 메일이 아니면 경고만 남기고 채널 축으로 흡수한다 — 알림 정의에는 provider 축이 없다
 *   (메일 provider = mail 채널 템플릿). 번들 프로바이더(KCP·이니시스·TOTP)는 메시지를 선언하지 않는다.
 * - 같은 type 을 다른 소유자가 이미 가졌으면(예: 코어 목적 정의와 겹침) 건너뛰고 경고한다 — 남의 정의를
 *   덮어쓰면 그 소유자의 재시딩과 서로 뺏고 빼앗긴다.
 */
class IdentityMessageDeclarationConverter
{
    /** @var string 코어 메일 본인인증 프로바이더 ID */
    private const MAIL_PROVIDER_ID = 'g7:core.mail';

    /**
     * @param  NotificationDefinitionRepositoryInterface  $definitions  소유자 충돌 판정용 정의 저장소
     */
    public function __construct(
        private readonly NotificationDefinitionRepositoryInterface $definitions,
    ) {}

    /**
     * 옛 본인인증 메시지 선언 목록을 알림 정의 선언 목록으로 바꿉니다.
     *
     * @param  array<int|string, array<string, mixed>>  $messages  `getIdentityMessages()` 결과
     * @param  string  $extensionType  소유 확장 유형 (module | plugin)
     * @param  string  $extensionIdentifier  소유 확장 식별자
     * @return array<int, array<string, mixed>> 알림 정의 선언 목록 (각 항목에 type 포함)
     */
    public function convert(array $messages, string $extensionType, string $extensionIdentifier): array
    {
        $converted = [];

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $type = IdentityNotificationType::fromLegacyScope(
                (string) ($message['scope_type'] ?? ''),
                isset($message['scope_value']) ? (string) $message['scope_value'] : null
            );

            if ($type === null) {
                Log::warning('[IDV 호환] 알 수 없는 범위의 본인인증 메시지 선언을 건너뜁니다', [
                    'extension' => "{$extensionType}/{$extensionIdentifier}",
                    'scope_type' => $message['scope_type'] ?? null,
                    'scope_value' => $message['scope_value'] ?? null,
                ]);

                continue;
            }

            $providerId = (string) ($message['provider_id'] ?? self::MAIL_PROVIDER_ID);
            if ($providerId !== self::MAIL_PROVIDER_ID) {
                Log::warning('[IDV 호환] 메일 외 프로바이더의 메시지 선언을 메일 채널 템플릿으로 흡수합니다', [
                    'extension' => "{$extensionType}/{$extensionIdentifier}",
                    'provider_id' => $providerId,
                    'type' => $type,
                ]);
            }

            if ($this->ownedByAnother($type, $extensionType, $extensionIdentifier)) {
                Log::warning('[IDV 호환] 같은 type 을 다른 소유자가 이미 가져 본인인증 메시지 선언을 건너뜁니다', [
                    'extension' => "{$extensionType}/{$extensionIdentifier}",
                    'type' => $type,
                ]);

                continue;
            }

            $templates = array_values(array_filter(
                (array) ($message['templates'] ?? []),
                static fn ($t) => is_array($t) && isset($t['channel'])
            ));

            $converted[] = [
                'type' => $type,
                'category' => IdentityNotificationType::CATEGORY,
                'hook_prefix' => $extensionIdentifier,
                'name' => $message['name'] ?? ['ko' => $type, 'en' => $type],
                'description' => $message['description'] ?? null,
                'channels' => array_values(array_unique(array_column($templates, 'channel'))) ?: ['mail'],
                'hooks' => [],
                'variables' => is_array($message['variables'] ?? null)
                    ? $message['variables']
                    : IdentityNotificationType::commonVariables(),
                'templates' => $templates,
            ];
        }

        return $converted;
    }

    /**
     * 같은 type 의 정의가 다른 소유자 것인지 판정합니다.
     *
     * @param  string  $type  알림 type
     * @param  string  $extensionType  선언한 확장 유형
     * @param  string  $extensionIdentifier  선언한 확장 식별자
     * @return bool 다른 소유자가 이미 가졌으면 true
     */
    private function ownedByAnother(string $type, string $extensionType, string $extensionIdentifier): bool
    {
        $existing = $this->definitions->findByType($type);

        return $existing !== null
            && ($existing->extension_type !== $extensionType || $existing->extension_identifier !== $extensionIdentifier);
    }
}

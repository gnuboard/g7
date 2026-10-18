<?php

namespace Tests\Support\Concerns;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;

/**
 * 코어·모듈·플러그인이 **선언한** 알림 정의를 한 모집단으로 모읍니다.
 *
 * DB 가 아니라 선언을 읽는 이유: 선언이 SSoT 이고 DB 는 그 사본입니다.
 * 선언을 보면 확장 설치 여부와 무관하게 전수를 볼 수 있고, 운영자가 UI 로 바꾼
 * `user_overrides` 가 계약 판정을 흐리지 않습니다.
 *
 * 정의가 늘 때 테스트를 손보지 않아도 새 정의가 자동으로 모집단에 들어옵니다 —
 * 손으로 쓴 목록이 낡아 "새 정의가 조용히 통과" 하는 것을 막습니다.
 */
trait CollectsNotificationDeclarations
{
    /**
     * 알림 인프라가 인정하는 수신자 타입 어휘.
     *
     * `UpdateNotificationTemplateRequest` 의 화이트리스트 · 관리자 UI 의 수신자
     * 드롭다운과 같은 목록입니다. 여기 없는 타입은 운영자가 화면에서 고칠 수 없습니다.
     *
     * @var array<int, string>
     */
    protected array $recognizedRecipientTypes = ['trigger_user', 'related_user', 'role', 'specific_users'];

    /**
     * 선언된 알림 정의 전수를 반환합니다.
     *
     * @return array<int, array{extension_type: string, extension_identifier: string, definition: array<string, mixed>}>
     */
    protected function declaredNotificationDefinitions(): array
    {
        $collected = [];

        foreach (config('core.notification_definitions') ?? [] as $type => $definition) {
            $definition['type'] = $type;
            $collected[] = [
                'extension_type' => 'core',
                'extension_identifier' => 'core',
                'definition' => $definition,
            ];
        }

        foreach (app(ModuleManager::class)->getAllModules() as $module) {
            if (! method_exists($module, 'getNotificationDefinitions')) {
                continue;
            }

            foreach ($module->getNotificationDefinitions() as $definition) {
                $collected[] = [
                    'extension_type' => 'module',
                    'extension_identifier' => $module->getIdentifier(),
                    'definition' => $definition,
                ];
            }
        }

        foreach (app(PluginManager::class)->getAllPlugins() as $plugin) {
            if (! method_exists($plugin, 'getNotificationDefinitions')) {
                continue;
            }

            foreach ($plugin->getNotificationDefinitions() as $definition) {
                $collected[] = [
                    'extension_type' => 'plugin',
                    'extension_identifier' => $plugin->getIdentifier(),
                    'definition' => $definition,
                ];
            }
        }

        return $collected;
    }

    /**
     * 정의를 `확장/타입` 한 줄 라벨로 만듭니다 (실패 메시지용).
     *
     * @param  array{extension_identifier: string, definition: array<string, mixed>}  $row  수집 행
     * @return string 라벨
     */
    protected function notificationLabel(array $row): string
    {
        return $row['extension_identifier'].'/'.($row['definition']['type'] ?? '(type 없음)');
    }

    /**
     * 텍스트에서 `{placeholder}` 키를 뽑습니다.
     *
     * @param  string  $text  검사할 문자열
     * @return array<int, string> 중복 제거된 키 목록
     */
    protected function extractPlaceholders(string $text): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * 템플릿의 다국어 필드를 한 문자열로 이어 붙입니다.
     *
     * @param  array<string, mixed>  $template  템플릿 선언
     * @param  array<int, string>  $fields  대상 필드
     * @return string 이어 붙인 문자열
     */
    protected function flattenTemplateText(array $template, array $fields = ['subject', 'body']): string
    {
        $parts = [];

        foreach ($fields as $field) {
            $value = $template[$field] ?? null;
            if (is_array($value)) {
                foreach ($value as $localized) {
                    $parts[] = (string) $localized;
                }
            } elseif ($value !== null) {
                $parts[] = (string) $value;
            }
        }

        return implode(' ', $parts);
    }
}

<?php

namespace App\Support;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use Illuminate\Support\Facades\Log;

/**
 * 전역 상태 공개 계약 집계 (`state_contracts`)
 *
 * 다른 확장이 **읽는** 전역 상태(`_global.{이름}`)는 소유자가 manifest 에 이름·설명·모양·쓰기 허용 주체를
 * 선언합니다. 화면 전용 임시 값은 선언하지 않습니다. 코어 예약 계약 + 활성 모듈·플러그인·템플릿
 * 선언을 모아 프론트(`G7Config.stateContracts` → `G7Core.stateContracts`)와 설치·활성화 충돌 검사가 함께 씁니다.
 *
 * 선언 모양:
 * ```json
 * "state_contracts": {
 *   "preferredCurrency": {
 *     "description": { "ko": "…", "en": "…" },
 *     "schema": { "type": "string" },
 *     "writers": ["@template"]
 *   }
 * }
 * ```
 *
 * `writers` 의 `@template` 은 활성 템플릿을 뜻합니다. 소유자는 언제나 쓸 수 있습니다.
 *
 * @since 7.1.0
 */
class StateContracts
{
    /** 코어 소유자 키 */
    public const CORE_OWNER = 'core';

    /** 활성 템플릿을 뜻하는 쓰기 주체 */
    public const WRITER_TEMPLATE = '@template';

    /**
     * 코어 예약 계약 (결정 D13).
     *
     * @var array<string, array{description: array<string, string>, schema: array<string, mixed>, writers: array<int, string>}>
     */
    public const CORE_CONTRACTS = [
        'consent' => [
            'description' => ['ko' => '방문자 동의 스냅샷 (코어 동의 런타임이 씀)', 'en' => 'Visitor consent snapshot (written by the core consent runtime)'],
            'schema' => ['type' => 'object'],
            'writers' => [],
        ],
        'identityChallenge' => [
            'description' => ['ko' => '진행 중인 본인인증 요청', 'en' => 'Identity verification challenge in progress'],
            'schema' => ['type' => 'object'],
            'writers' => [self::WRITER_TEMPLATE],
        ],
        'currentUser' => [
            'description' => ['ko' => '로그인한 회원 정보', 'en' => 'Signed-in member'],
            'schema' => ['type' => ['object', 'null']],
            'writers' => [self::WRITER_TEMPLATE],
        ],
        'settings' => [
            'description' => ['ko' => '코어 공개 설정 (관리자 템플릿이 저장 직후 갱신)', 'en' => 'Public core settings (refreshed by the admin template after saving)'],
            'schema' => ['type' => 'object'],
            'writers' => [self::WRITER_TEMPLATE],
        ],
        'plugins' => [
            'description' => ['ko' => '활성 플러그인 공개 설정 (식별자별)', 'en' => 'Public settings of active plugins (by identifier)'],
            'schema' => ['type' => 'object'],
            'writers' => [],
        ],
        'modules' => [
            'description' => ['ko' => '활성 모듈 공개 설정 (식별자별)', 'en' => 'Public settings of active modules (by identifier)'],
            'schema' => ['type' => 'object'],
            'writers' => [],
        ],
        'appConfig' => [
            'description' => ['ko' => '앱 설정 (기기·환경)', 'en' => 'App configuration (device, environment)'],
            'schema' => ['type' => 'object'],
            'writers' => [],
        ],
    ];

    /**
     * 계약 이름 규칙 — 전역 상태 최상위 키로 쓸 수 있는 camelCase 식별자.
     */
    private const NAME_PATTERN = '/^[a-z][A-Za-z0-9]{0,63}$/';

    /**
     * manifest 의 `state_contracts` 선언을 정규화합니다. 형식이 틀린 항목은 버립니다.
     *
     * @param  mixed  $raw  manifest 값
     * @return array<string, array{description: array<string, string>, schema: array<string, mixed>, writers: array<int, string>}>
     *                                                                                                                             정규화한 선언
     */
    public static function normalize(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $name => $definition) {
            if (! is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1 || ! is_array($definition)) {
                continue;
            }

            $description = $definition['description'] ?? [];
            if (is_string($description)) {
                $description = ['ko' => $description];
            }

            $writers = $definition['writers'] ?? [];

            $normalized[$name] = [
                'description' => is_array($description)
                    ? array_filter($description, fn ($text, $locale) => is_string($locale) && is_string($text), ARRAY_FILTER_USE_BOTH)
                    : [],
                'schema' => is_array($definition['schema'] ?? null) ? $definition['schema'] : [],
                'writers' => is_array($writers)
                    ? array_values(array_filter($writers, fn ($writer) => is_string($writer) && $writer !== ''))
                    : [],
            ];
        }

        return $normalized;
    }

    /**
     * 코어 예약 + 활성 확장 선언 전체를 집계합니다.
     *
     * 같은 이름을 둘이 선언하면(설치 검사를 우회한 경우) 먼저 온 소유자만 남기고 경고를 남깁니다.
     * 소스 하나가 실패해도 나머지는 집계합니다.
     *
     * @return array<string, array{owner: string, description: array<string, string>, schema: array<string, mixed>, writers: array<int, string>}>
     *                                                                                                                                            이름 → 계약
     */
    public static function all(): array
    {
        $contracts = [];
        foreach (self::CORE_CONTRACTS as $name => $definition) {
            $contracts[$name] = ['owner' => self::CORE_OWNER] + $definition;
        }

        foreach (self::declaredByActiveExtensions() as $owner => $declared) {
            foreach ($declared as $name => $definition) {
                if (isset($contracts[$name])) {
                    Log::warning('StateContracts: 같은 상태 계약을 둘이 선언했습니다 — 먼저 온 소유자만 사용합니다', [
                        'name' => $name,
                        'kept' => $contracts[$name]['owner'],
                        'ignored' => $owner,
                    ]);

                    continue;
                }

                $contracts[$name] = ['owner' => $owner] + $definition;
            }
        }

        return $contracts;
    }

    /**
     * 계약 이름의 소유자 키를 반환합니다 (없으면 null).
     *
     * @param  string  $name  계약 이름
     * @return string|null 소유자 키 (`core`·`module:{id}`·`plugin:{id}`·`template:{id}`)
     */
    public static function ownerOf(string $name): ?string
    {
        return self::all()[$name]['owner'] ?? null;
    }

    /**
     * 활성 확장이 선언한 계약을 소유자 키별로 모읍니다.
     *
     * @return array<string, array<string, array{description: array<string, string>, schema: array<string, mixed>, writers: array<int, string>}>>
     *                                                                                                                                            소유자 키 → 선언
     */
    public static function declaredByActiveExtensions(): array
    {
        $declared = [];

        try {
            foreach (app(ModuleManager::class)->getActiveModules() as $module) {
                if (method_exists($module, 'getStateContracts')) {
                    $declared['module:'.$module->getIdentifier()] = $module->getStateContracts();
                }
            }
        } catch (\Throwable $e) {
            Log::warning('StateContracts: 모듈 집계 실패 - '.$e->getMessage());
        }

        try {
            foreach (app(PluginManager::class)->getActivePlugins() as $plugin) {
                if (method_exists($plugin, 'getStateContracts')) {
                    $declared['plugin:'.$plugin->getIdentifier()] = $plugin->getStateContracts();
                }
            }
        } catch (\Throwable $e) {
            Log::warning('StateContracts: 플러그인 집계 실패 - '.$e->getMessage());
        }

        // 템플릿은 PHP 확장 클래스가 없고 manifest 배열로 다뤄진다 — 유형별 활성 템플릿은 하나뿐이다.
        try {
            $templateManager = app(TemplateManager::class);

            foreach (['admin', 'user'] as $type) {
                $template = $templateManager->getActiveTemplate($type);

                if (! is_array($template) || ! isset($template['identifier'])) {
                    continue;
                }

                $declared['template:'.$template['identifier']] = self::normalize($template['state_contracts'] ?? []);
            }
        } catch (\Throwable $e) {
            Log::warning('StateContracts: 템플릿 집계 실패 - '.$e->getMessage());
        }

        return array_filter($declared);
    }

    /**
     * 프론트로 내보낼 모양으로 집계합니다 (`G7Config.stateContracts`).
     *
     * @return array<string, array{owner: string, description: array<string, string>, schema: array<string, mixed>, writers: array<int, string>}>
     *                                                                                                                                            이름 → 계약
     */
    public static function forFrontend(): array
    {
        try {
            return self::all();
        } catch (\Throwable $e) {
            Log::warning('StateContracts: 프론트 집계 실패 - '.$e->getMessage());

            return [];
        }
    }
}

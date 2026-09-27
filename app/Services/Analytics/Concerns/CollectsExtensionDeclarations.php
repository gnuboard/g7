<?php

namespace App\Services\Analytics\Concerns;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;

/**
 * 활성 확장의 선언 getter 를 격리 호출로 모으는 공통 동작
 *
 * 목표·차원 등록부가 함께 씁니다. 확장 하나의 getter 가 던지거나 형식이 틀려도 나머지
 * 선언은 살리고, 버린 이유는 `warnings` 로 남깁니다 — 조용히 버리면 그 확장의 전환·차원이
 * 통계에서 까닭 없이 사라집니다.
 *
 * 사용하는 클래스는 `ModuleManagerInterface $moduleManager`·`PluginManagerInterface
 * $pluginManager` 속성과 `array $warnings` 속성을 가져야 합니다.
 */
trait CollectsExtensionDeclarations
{
    /**
     * 활성 모듈·플러그인에서 선언을 모읍니다.
     *
     * @param  string  $getter  선언 getter 이름
     * @return array<int, array{type: string, identifier: string, instance: object, items: array<int|string, mixed>}>
     *                                                                                                                공급자별 선언
     */
    protected function collectDeclarations(string $getter): array
    {
        $sources = [];

        foreach ([
            'module' => fn (): array => $this->moduleManager->getActiveModules(),
            'plugin' => fn (): array => $this->pluginManager->getActivePlugins(),
        ] as $type => $list) {
            try {
                $extensions = $list();
            } catch (\Throwable $e) {
                $this->warnings[] = "{$type} 목록 조회 실패 — {$e->getMessage()}";

                continue;
            }

            foreach ($extensions as $extension) {
                if (! is_object($extension) || ! method_exists($extension, $getter)) {
                    continue;
                }

                $identifier = method_exists($extension, 'getIdentifier')
                    ? (string) $extension->getIdentifier()
                    : $extension::class;

                try {
                    $declared = $extension->{$getter}();
                } catch (\Throwable $e) {
                    $this->warnings[] = "{$identifier}: {$getter}() 호출 실패 — {$e->getMessage()}";

                    continue;
                }

                if (! is_array($declared)) {
                    $this->warnings[] = "{$identifier}: {$getter}() 는 배열을 반환해야 합니다.";

                    continue;
                }

                $sources[] = [
                    'type' => $type,
                    'identifier' => $identifier,
                    'instance' => $extension,
                    'items' => $declared,
                ];
            }
        }

        return $sources;
    }

    /**
     * 선언 키 형식을 검사합니다.
     *
     * 키는 원장·집계 이름의 일부가 되므로 구분자(`.`·`:`)나 공백이 섞이면 공급자를 되읽을 수
     * 없게 됩니다.
     *
     * @param  mixed  $key  선언 키
     * @return bool 영소문자·숫자·밑줄 1~50자면 true
     */
    protected static function isValidDeclarationKey(mixed $key): bool
    {
        return is_string($key) && preg_match('/^[a-z0-9_]{1,50}$/', $key) === 1;
    }
}

<?php

namespace App\Extension\Helpers;

use App\Extension\Testing\ExtensionTestAllowlist;
use App\Providers\ModuleServiceProvider;
use App\Providers\PluginServiceProvider;

/**
 * 같은 요청·프로세스 안에서 새로 놓인 확장의 서비스 프로바이더를 등록합니다.
 *
 * 코어의 확장 프로바이더 등록기(`ModuleServiceProvider`·`PluginServiceProvider` 의 `register()`)는
 * 앱이 부팅할 때 `modules/`·`plugins/` 에 **이미 있는** 디렉터리만 훑는다. 그런데 새 확장 설치는
 * 같은 요청(또는 같은 CLI 프로세스) 안에서 `_bundled`·`_pending`·zip 에서 디렉터리를 새로 복사한 뒤
 * 마이그레이션·시더를 돌린다. 그 확장의 프로바이더가 등록된 적이 없으면 `$repositories`(인터페이스 →
 * 구현체 바인딩)가 없어, 시더가 저장소 인터페이스를 해석하는 순간 `BindingResolutionException` 이 난다.
 *
 * 확장 코드가 처음 실행되기 직전(설치·활성화·언어팩 시더 재실행)에 이 도우미를 불러, 부팅 때와
 * **같은 규칙**으로 찾은 프로바이더를 등록한다. 이미 등록된 프로바이더는 건너뛴다. 확장 오토로드
 * 매핑(`ExtensionManager::registerExtensionAutoloadPaths`)이 먼저 등록돼 있어야 한다.
 */
final class ExtensionProviderRegistrar
{
    /**
     * 확장의 프로바이더가 등록돼 있지 않으면 등록합니다.
     *
     * @param  string  $type  확장 유형 (`modules` | `plugins`)
     * @param  string  $dirName  확장 디렉터리명 (식별자)
     * @return array<int, class-string> 이번 호출이 새로 등록한 프로바이더 클래스 목록
     */
    public static function ensureRegistered(string $type, string $dirName): array
    {
        // 부팅 등록기와 같은 규칙 — 테스트 환경 확장 격리 allowlist 밖이면 등록하지 않는다
        $allowlistType = $type === 'modules' ? 'module' : 'plugin';
        if (ExtensionTestAllowlist::isActive() && ! ExtensionTestAllowlist::isAllowed($allowlistType, $dirName)) {
            return [];
        }

        $directory = base_path($type.DIRECTORY_SEPARATOR.$dirName);

        $classes = match ($type) {
            'modules' => ModuleServiceProvider::providerClassesIn($directory),
            'plugins' => PluginServiceProvider::providerClassesIn($directory),
            default => [],
        };

        $registered = [];
        foreach ($classes as $class) {
            if (app()->getProvider($class) !== null) {
                continue;
            }

            app()->register($class);
            $registered[] = $class;
        }

        return $registered;
    }
}

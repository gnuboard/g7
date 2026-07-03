<?php

namespace App\Extension;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 정적 훅 매핑 캐시 관리자.
 *
 * 코어(app/Listeners 재귀 스캔) + 모듈/플러그인(getHookListeners)의 정적 훅 리스너를
 * 사전 계산하여 bootstrap/cache/hooks.php 에 저장하고, 부팅 시 이 캐시를 읽어
 * 매 요청 디렉토리 스캔·리플렉션·getSubscribedHooks() 클래스 로딩을 제거한다.
 *
 * 캐시는 "무엇을 등록할지 목록"만 제공하며, 실제 등록은 여전히 boot 에서 수행되므로
 * 등록↔발화 순서 계약은 불변이다. 캐시 미스(파일 부재/설치 직후/캐시 클리어 후)는
 * 항상 스캔 폴백으로 안전하게 동작한다.
 *
 * 오토로드 캐시(autoload-extensions.php)와 동일 위치·생명주기를 갖는다:
 * 확장 install/activate/deactivate/uninstall/update 시
 * ExtensionManager::updateComposerAutoload() 가 함께 재생성한다.
 *
 * @see HookListenerRegistrar::registerFromCache()
 */
class HookCacheManager
{
    /**
     * 캐시 파일 경로.
     */
    protected string $cacheFilePath;

    /**
     * Request-scoped 메모리 캐시 (read() 결과).
     *
     * HookCacheManager 는 싱글톤이 아니라 부팅 경로에서 매번 새 인스턴스로
     * 생성되므로(app(HookCacheManager::class)), 요청 단위 캐시는 static 이어야 한다.
     * 세 상태를 구분한다:
     *   - null   = 아직 read() 전 (미초기화). PHP static 프로퍼티는 unset() 불가하므로
     *              무효화(generate/clear)는 이 값으로 되돌린다.
     *   - false  = 이번 요청 캐시 미스 확정 (파일 부재/손상) — 재시도 없이 폴백 유지.
     *   - array  = 유효 캐시 데이터.
     *
     * @var array{core: array, modules: array, plugins: array}|false|null
     */
    private static array|false|null $memo = null;

    public function __construct()
    {
        $this->cacheFilePath = base_path('bootstrap/cache/hooks.php');
    }

    /**
     * 캐시 파일 경로를 반환합니다.
     *
     * @return string bootstrap/cache/hooks.php 절대 경로
     */
    public function getCacheFilePath(): string
    {
        return $this->cacheFilePath;
    }

    /**
     * 캐시 파일 존재 여부를 반환합니다.
     *
     * @return bool 캐시 파일이 존재하면 true
     */
    public function isCached(): bool
    {
        return File::exists($this->cacheFilePath);
    }

    /**
     * 캐시 파일을 읽어 반환합니다.
     *
     * 파일이 없거나 구조가 손상된 경우 null 을 반환하여 호출측이 스캔 폴백하도록 한다.
     *
     * @return array{core: array<int, array<string, mixed>>, modules: array<string, array<int, array<string, mixed>>>, plugins: array<string, array<int, array<string, mixed>>>}|null
     */
    public function read(): ?array
    {
        // Request-scoped 메모리 캐시: 한 요청 동안 부팅 경로에서
        // core/module/plugin 등록이 이 메서드를 여러 번 호출한다
        // (호출당 86KB 캐시 파일 재파싱 — OPcache 부재 시 요청당 십수 ms 누적).
        // 캐시 파일 내용은 요청 수명 동안 불변이므로 첫 로드 결과를 static 에 보관해
        // 파일 접근을 요청당 1회로 고정한다. self::$memo 는 세 상태를 구분한다:
        //   - 미설정(unset): 아직 read() 호출 전
        //   - false: 이번 요청에서 캐시 미스(파일 부재/손상) 확정 — 재시도 없이 폴백 유지
        //   - array: 유효 캐시 데이터
        if (self::$memo !== null) {
            return self::$memo === false ? null : self::$memo;
        }

        if (! $this->isCached()) {
            self::$memo = false;

            return null;
        }

        try {
            $data = require $this->cacheFilePath;
        } catch (\Throwable $e) {
            Log::warning('훅 캐시 파일 로드 실패 — 스캔 폴백', [
                'path' => $this->cacheFilePath,
                'error' => $e->getMessage(),
            ]);
            self::$memo = false;

            return null;
        }

        if (! is_array($data) || ! isset($data['core'], $data['modules'], $data['plugins'])) {
            Log::warning('훅 캐시 파일 구조가 올바르지 않습니다 — 스캔 폴백', [
                'path' => $this->cacheFilePath,
            ]);
            self::$memo = false;

            return null;
        }

        self::$memo = $data;

        return $data;
    }

    /**
     * 캐시 파일을 삭제합니다.
     *
     * @return bool 삭제 성공 또는 파일 부재 시 true
     */
    public function clear(): bool
    {
        self::$memo = null;

        if (! $this->isCached()) {
            return true;
        }

        return File::delete($this->cacheFilePath);
    }

    /**
     * 정적 훅 매핑을 스캔·리플렉션하여 캐시 파일로 생성합니다.
     *
     * 모듈/플러그인 리스너는 로드된 Module/Plugin 인스턴스의 getHookListeners() 에서
     * 수집하므로, 호출 전에 각 Manager 의 loadModules()/loadPlugins() 가 선행되어야 한다.
     *
     * @param  ModuleManager  $moduleManager  로드 완료된 모듈 매니저
     * @param  PluginManager  $pluginManager  로드 완료된 플러그인 매니저
     * @return array{core: int, modules: int, plugins: int} 소스별 리스너 수
     */
    public function generate(ModuleManager $moduleManager, PluginManager $pluginManager): array
    {
        $cache = [
            'core' => $this->collectCoreListeners(),
            'modules' => $this->collectExtensionListeners($moduleManager->getActiveModules()),
            'plugins' => $this->collectExtensionListeners($pluginManager->getActivePlugins()),
        ];

        $content = $this->buildCacheFileContent($cache);

        $dir = dirname($this->cacheFilePath);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        File::put($this->cacheFilePath, $content);

        // 방금 쓴 새 매핑으로 request-scoped 메모리 캐시를 갱신
        // (이후 read() 가 옛 memo 를 반환하지 않도록 무효화).
        self::$memo = null;

        $counts = [
            'core' => count($cache['core']),
            'modules' => array_sum(array_map('count', $cache['modules'])),
            'plugins' => array_sum(array_map('count', $cache['plugins'])),
        ];

        Log::info('훅 매핑 캐시 파일 생성 완료', [
            'path' => $this->cacheFilePath,
            'core' => $counts['core'],
            'modules' => $counts['modules'],
            'plugins' => $counts['plugins'],
        ]);

        return $counts;
    }

    /**
     * app/Listeners/ 재귀 스캔으로 코어 리스너 항목을 수집합니다.
     *
     * registerCoreHookListeners() 스캔 로직과 동일하게 HookListenerInterface 구현체만 대상.
     *
     * @return array<int, array<string, mixed>> [['listener' => FQCN, 'hooks' => [...], 'dynamic' => bool], ...]
     */
    protected function collectCoreListeners(): array
    {
        $listenersPath = app_path('Listeners');

        if (! is_dir($listenersPath)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($listenersPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $entries = [];

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace($listenersPath.DIRECTORY_SEPARATOR, '', $file->getPathname());
            $relativePath = str_replace('.php', '', $relativePath);
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);
            $listenerClass = 'App\\Listeners\\'.$relativePath;

            $entry = $this->buildListenerEntry($listenerClass);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * 로드된 모듈/플러그인 인스턴스에서 훅 리스너 항목을 수집합니다.
     *
     * @param  array<string, ModuleInterface|PluginInterface>  $extensions  식별자(디렉토리명) => 확장 인스턴스
     * @return array<string, array<int, array<string, mixed>>> 확장 식별자 => 리스너 항목 목록
     */
    protected function collectExtensionListeners(array $extensions): array
    {
        $result = [];

        foreach ($extensions as $extension) {
            if (! method_exists($extension, 'getHookListeners')) {
                continue;
            }

            $identifier = $extension->getIdentifier();
            $entries = [];

            foreach ($extension->getHookListeners() as $listenerClass) {
                $entry = $this->buildListenerEntry($listenerClass);
                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }

            if (! empty($entries)) {
                $result[$identifier] = $entries;
            }
        }

        return $result;
    }

    /**
     * 단일 리스너 클래스의 캐시 항목을 생성합니다.
     *
     * HookListenerInterface 미구현·클래스 부재 시 null 을 반환하여 제외합니다.
     * 사전 계산된 getSubscribedHooks() 결과와 동적 훅 여부(registerDynamicHooks 존재)를 함께 저장합니다.
     *
     * @param  string  $listenerClass  리스너 FQCN
     * @return array{listener: string, hooks: array<string, array<string, mixed>>, dynamic: bool}|null
     */
    protected function buildListenerEntry(string $listenerClass): ?array
    {
        if (! class_exists($listenerClass)) {
            Log::warning('훅 캐시 생성: 리스너 클래스를 찾을 수 없습니다', ['listener' => $listenerClass]);

            return null;
        }

        if (! in_array(HookListenerInterface::class, class_implements($listenerClass), true)) {
            return null;
        }

        try {
            $hooks = $listenerClass::getSubscribedHooks();
        } catch (\Throwable $e) {
            Log::warning('훅 캐시 생성: getSubscribedHooks() 오류 — 항목 제외', [
                'listener' => $listenerClass,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return [
            'listener' => $listenerClass,
            'hooks' => $hooks,
            'dynamic' => method_exists($listenerClass, 'registerDynamicHooks'),
        ];
    }

    protected function buildCacheFileContent(array $cache): string
    {
        $exported = var_export($cache, true);
        $generatedAt = now()->toDateTimeString();

        $header = "<?php\n\n"
            ."/**\n"
            ." * 정적 훅 매핑 캐시\n"
            ." *\n"
            ." * 이 파일은 자동 생성됩니다. 직접 수정하지 마세요.\n"
            ." * Generated at: {$generatedAt}\n"
            ." *\n"
            ." * @see \\App\\Extension\\HookCacheManager::generate()\n"
            ." */\n\n";

        return $header."return {$exported};\n";
    }
}

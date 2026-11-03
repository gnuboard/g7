<?php

namespace App\Extension;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 확장이 선언한 예약 커맨드의 이름 → 클래스 매핑 캐시.
 *
 * 이 캐시가 있는 이유는 **실행 방식에 따라 커맨드 집합이 달라지지 않게** 하기 위해서다.
 * 확장은 관례대로 `runningInConsole()` 안에서만 자기 커맨드를 등록하는데(Laravel 표준),
 * 무CLI 호스팅의 웹 크론은 웹 요청 안에서 `Artisan::call()` 로 실행하므로 그 조건이 거짓이라
 * 커맨드가 등록되지 않는다. 그러면 예약 작업이 「그런 커맨드가 없다」로 매번 실패한다
 * (G8 실측 2026-09-02 · 계획서 §9.11.3 F4 · D42).
 *
 * 확장 소스는 고치지 않는다 — 그 게이트는 Laravel 관례라 제3자 확장은 관례대로 쓸 것이고,
 * 확장마다 고치는 방식은 「확장 저자가 알아야 할 규칙」을 남긴다. 코어가 보장한다.
 *
 * **등록 범위는 확장이 선언한 것뿐이다.** 대상은 `getSchedules()` 에 적힌 커맨드 이름이며,
 * 확장 소스를 훑어 나온 커맨드를 전부 등록하지 않는다 — 그러면 확장이 노출하겠다고 선언한 적
 * 없는 커맨드까지 등록된다. 파일 탐색은 **등록 범위가 아니라 이름 → 클래스 조회 수단**이다.
 *
 * 조회는 인스턴스를 만들지 않는다 — 클래스의 `$signature`(없으면 `$name`) 기본값을 리플렉션으로
 * 읽어 맞춘다. 확장이 조회를 건너뛰게 하려면 선언 항목에 `command_class` 를 적으면 된다.
 *
 * 생명주기는 훅 캐시와 같다(`ExtensionManager::regenerateHookCache()`) — 매핑이 바뀌는 시점이
 * 확장 install/activate/deactivate/uninstall/update 로 동일하기 때문이다.
 */
class ScheduleCommandCacheManager
{
    /**
     * 캐시 파일 경로.
     */
    protected string $cacheFilePath;

    /**
     * Request-scoped 메모리 캐시.
     *
     * HookCacheManager 와 같은 3상태 규약이다 — null=미초기화 / false=이번 요청 미스 확정 /
     * array=유효 데이터. 무효화는 null 로 되돌린다(PHP static 은 unset 불가).
     *
     * @var array{commands: array<string, class-string>, unresolved: array<string, string>}|false|null
     */
    private static array|false|null $memo = null;

    /**
     * 캐시 파일 경로를 `bootstrap/cache/extension-schedule-commands.php` 로 정합니다.
     */
    public function __construct()
    {
        $this->cacheFilePath = base_path('bootstrap/cache/extension-schedule-commands.php');
    }

    /**
     * 캐시 파일 경로를 반환합니다.
     *
     * @return string bootstrap/cache/extension-schedule-commands.php 절대 경로
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
     * 파일이 없거나 구조가 손상된 경우 null 을 반환해 호출측이 라이브 해석으로 폴백하게 한다.
     *
     * @return array{commands: array<string, class-string>, unresolved: array<string, string>}|null
     */
    public function read(): ?array
    {
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
            Log::warning('예약 커맨드 매핑 캐시 로드 실패 — 라이브 해석 폴백', [
                'path' => $this->cacheFilePath,
                'error' => $e->getMessage(),
            ]);
            self::$memo = false;

            return null;
        }

        if (! is_array($data) || ! isset($data['commands']) || ! is_array($data['commands'])) {
            Log::warning('예약 커맨드 매핑 캐시 구조가 올바르지 않습니다 — 라이브 해석 폴백', [
                'path' => $this->cacheFilePath,
            ]);
            self::$memo = false;

            return null;
        }

        $data['unresolved'] = is_array($data['unresolved'] ?? null) ? $data['unresolved'] : [];

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
     * 활성 확장을 훑어 매핑을 만들고 캐시 파일에 씁니다.
     *
     * @param  ModuleManager  $moduleManager  로드된 모듈 매니저
     * @param  PluginManager  $pluginManager  로드된 플러그인 매니저
     * @return array{commands: array<string, class-string>, unresolved: array<string, string>} 생성된 매핑
     */
    public function generate(ModuleManager $moduleManager, PluginManager $pluginManager): array
    {
        $map = $this->resolveLive($moduleManager, $pluginManager);

        $dir = dirname($this->cacheFilePath);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        File::put($this->cacheFilePath, $this->buildCacheFileContent($map));

        self::$memo = null;

        Log::info('예약 커맨드 매핑 캐시 생성 완료', [
            'path' => $this->cacheFilePath,
            'resolved' => count($map['commands']),
            'unresolved' => count($map['unresolved']),
        ]);

        return $map;
    }

    /**
     * 캐시 없이 활성 확장에서 매핑을 해석합니다 (캐시 부재 시 폴백).
     *
     * @param  ModuleManager  $moduleManager  로드된 모듈 매니저
     * @param  PluginManager  $pluginManager  로드된 플러그인 매니저
     * @return array{commands: array<string, class-string>, unresolved: array<string, string>} 해석 결과
     */
    public function resolveLive(ModuleManager $moduleManager, PluginManager $pluginManager): array
    {
        $commands = [];
        $unresolved = [];

        $this->collect($moduleManager->getActiveModules(), 'module', $commands, $unresolved);
        $this->collect($pluginManager->getActivePlugins(), 'plugin', $commands, $unresolved);

        ksort($commands);
        ksort($unresolved);

        return ['commands' => $commands, 'unresolved' => $unresolved];
    }

    /**
     * 확장 목록에서 선언된 예약 커맨드를 수집해 클래스로 해석합니다.
     *
     * @param  array<string, ModuleInterface|PluginInterface>  $extensions  식별자 => 확장 인스턴스
     * @param  string  $type  확장 유형(module|plugin) — 미해석 항목의 소유자 표기에 쓴다
     * @param  array<string, class-string>  $commands  해석 결과 누적(참조)
     * @param  array<string, string>  $unresolved  미해석 누적(참조)
     * @return void 반환값 없음
     */
    protected function collect(array $extensions, string $type, array &$commands, array &$unresolved): void
    {
        foreach ($extensions as $extension) {
            if (! method_exists($extension, 'getSchedules')) {
                continue;
            }

            $owner = $type.':'.$extension->getIdentifier();

            try {
                $schedules = $extension->getSchedules();
            } catch (\Throwable $e) {
                Log::warning('예약 커맨드 매핑: getSchedules() 오류 — 확장 제외', [
                    'extension' => $owner,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (! is_array($schedules)) {
                continue;
            }

            $root = $this->extensionSourcePath($extension);

            foreach ($schedules as $config) {
                if (! is_array($config) || empty($config['command'])) {
                    continue;
                }

                $name = $this->commandNameOf((string) $config['command']);

                if ($name === '' || isset($commands[$name])) {
                    continue;
                }

                // 확장이 명시했으면 조회하지 않는다.
                $declared = $config['command_class'] ?? null;

                if (is_string($declared) && $declared !== '') {
                    if ($this->isCommandClass($declared)) {
                        $commands[$name] = $declared;

                        continue;
                    }

                    Log::warning('예약 커맨드 매핑: command_class 가 커맨드 클래스가 아닙니다', [
                        'extension' => $owner,
                        'command' => $name,
                        'class' => $declared,
                    ]);
                }

                $class = $root === null ? null : $this->findCommandClass($root, $name);

                if ($class !== null) {
                    $commands[$name] = $class;

                    continue;
                }

                // 조용히 넘기지 않는다 — 지금 고치는 결함이 「조용히 안 도는 것」이다.
                // 다만 코어가 소유한 커맨드를 확장이 예약하는 경우도 여기에 걸리므로
                // (그 커맨드는 코어가 이미 등록한다) 오류가 아니라 원장으로 남긴다.
                $unresolved[$name] = $owner;
            }
        }
    }

    /**
     * 선언 문자열에서 커맨드 이름만 잘라냅니다 (`x:y --flag` → `x:y`).
     *
     * @param  string  $declared  선언 문자열
     * @return string 커맨드 이름
     */
    protected function commandNameOf(string $declared): string
    {
        $name = strtok(trim($declared), " \t\n");

        return $name === false ? '' : $name;
    }

    /**
     * 확장의 소스 루트(`src`) 절대 경로를 반환합니다.
     *
     * `AbstractModule::getModulePath()`·`AbstractPlugin::getPluginPath()` 는 protected 라
     * 외부에서 부를 수 없다. 계약 표면을 넓히는 대신 그 둘이 쓰는 것과 **같은 방식**으로
     * 도출한다 — 확장 클래스 파일이 놓인 디렉토리가 곧 확장 루트다.
     *
     * @param  object  $extension  확장 인스턴스 (리플렉션만 쓰므로 계약을 요구하지 않는다)
     * @return string|null 존재하면 절대 경로, 아니면 null
     */
    protected function extensionSourcePath(object $extension): ?string
    {
        try {
            $file = (new \ReflectionClass($extension))->getFileName();
        } catch (\Throwable) {
            return null;
        }

        if ($file === false) {
            return null;
        }

        $src = dirname($file).DIRECTORY_SEPARATOR.'src';

        return is_dir($src) ? $src : null;
    }

    /**
     * 확장 소스에서 주어진 이름을 선언한 커맨드 클래스를 찾습니다.
     *
     * 관례상 커맨드는 `src/Console/**` 아래에 있으므로 그 하위를 먼저 본다. 못 찾으면
     * `src/**` 전체로 넓힌다 — 실측상 한 확장(`sirsoft-message_bizppurio`)이
     * `src/Console/Commands/` 가 아니라 `src/Console/` 에 두고 있어, 경로 관례를 전제하면
     * 그 확장이 그대로 샌다. 넓은 탐색은 좁은 탐색이 실패했을 때만 돈다.
     *
     * @param  string  $srcRoot  확장 소스 루트 절대 경로
     * @param  string  $name  찾을 커맨드 이름
     * @return class-string|null 찾았으면 FQCN, 없으면 null
     */
    protected function findCommandClass(string $srcRoot, string $name): ?string
    {
        $console = $srcRoot.DIRECTORY_SEPARATOR.'Console';

        foreach ([is_dir($console) ? $console : null, $srcRoot] as $scope) {
            if ($scope === null) {
                continue;
            }

            $found = $this->scanForCommandName($scope, $name);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * 디렉토리 하위 PHP 파일에서 주어진 커맨드 이름을 선언한 클래스를 찾습니다.
     *
     * @param  string  $directory  탐색 루트
     * @param  string  $name  찾을 커맨드 이름
     * @return class-string|null 찾았으면 FQCN, 없으면 null
     */
    protected function scanForCommandName(string $directory, string $name): ?string
    {
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
        } catch (\Throwable) {
            return null;
        }

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $class = $this->classNameOfFile($file->getPathname());

            if ($class === null || ! $this->isCommandClass($class)) {
                continue;
            }

            if ($this->declaredCommandName($class) === $name) {
                return $class;
            }
        }

        return null;
    }

    /**
     * 파일 머리에서 네임스페이스와 클래스명을 읽어 FQCN 을 만듭니다.
     *
     * PSR-4 관례를 전제하지 않는다 — 파일이 스스로 선언한 것을 읽는다.
     *
     * @param  string  $path  PHP 파일 절대 경로
     * @return string|null FQCN, 판독 실패 시 null
     */
    protected function classNameOfFile(string $path): ?string
    {
        $head = @file_get_contents($path, false, null, 0, 8192);

        if ($head === false) {
            return null;
        }

        if (! preg_match('~^\s*namespace\s+([^;\s]+)\s*;~m', $head, $ns)) {
            return null;
        }

        if (! preg_match('~^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)~m', $head, $cls)) {
            return null;
        }

        return $ns[1].'\\'.$cls[1];
    }

    /**
     * 주어진 클래스가 인스턴스화 가능한 Artisan 커맨드인지 판정합니다.
     *
     * @param  string  $class  FQCN
     * @return bool 커맨드면 true
     */
    protected function isCommandClass(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        try {
            $reflection = new \ReflectionClass($class);
        } catch (\Throwable) {
            return false;
        }

        return ! $reflection->isAbstract() && $reflection->isSubclassOf(Command::class);
    }

    /**
     * 커맨드 클래스가 선언한 이름을 인스턴스화 없이 읽습니다.
     *
     * `$signature` 가 있으면 그 앞부분, 없으면 `$name` 이다. 두 프로퍼티 모두 protected
     * 이지만 기본값은 리플렉션으로 읽을 수 있다.
     *
     * @param  string  $class  커맨드 FQCN
     * @return string|null 선언된 이름, 없으면 null
     */
    protected function declaredCommandName(string $class): ?string
    {
        try {
            $defaults = (new \ReflectionClass($class))->getDefaultProperties();
        } catch (\Throwable) {
            return null;
        }

        $declared = $defaults['signature'] ?? $defaults['name'] ?? null;

        if (! is_string($declared) || trim($declared) === '') {
            return null;
        }

        $name = $this->commandNameOf($declared);

        return $name === '' ? null : $name;
    }

    /**
     * 캐시 파일 내용을 만듭니다.
     *
     * @param  array{commands: array<string, class-string>, unresolved: array<string, string>}  $map  매핑
     * @return string 파일 내용
     */
    protected function buildCacheFileContent(array $map): string
    {
        $exported = var_export($map, true);
        $generatedAt = now()->toDateTimeString();

        $header = "<?php\n\n"
            ."/**\n"
            ." * 확장 예약 커맨드 이름 → 클래스 매핑 캐시\n"
            ." *\n"
            ." * 이 파일은 자동 생성됩니다. 직접 수정하지 마세요.\n"
            ." * Generated at: {$generatedAt}\n"
            ." *\n"
            ." * @see \\App\\Extension\\ScheduleCommandCacheManager::generate()\n"
            ." */\n\n";

        return $header."return {$exported};\n";
    }
}

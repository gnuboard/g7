<?php

namespace App\Extension;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Extension\Helpers\GithubHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/**
 * 확장(모듈/플러그인) 공통 관리자
 *
 * 모듈과 플러그인에서 공통으로 사용되는 기능을 제공합니다.
 * 런타임 오토로드 방식을 사용하여 composer.json 수정 없이 확장 클래스를 로드합니다.
 */
class ExtensionManager
{
    protected string $modulesPath;

    protected string $pluginsPath;

    /**
     * 오토로드 파일 경로
     */
    protected string $autoloadFilePath;

    public function __construct(
        protected ModuleRepositoryInterface $moduleRepository,
        protected PluginRepositoryInterface $pluginRepository
    ) {
        $this->modulesPath = base_path('modules');
        $this->pluginsPath = base_path('plugins');
        $this->autoloadFilePath = base_path('bootstrap/cache/autoload-extensions.php');
    }

    /**
     * 설치된 모듈/플러그인의 오토로드 파일을 생성합니다.
     *
     * composer.json을 수정하지 않고, bootstrap/cache/autoload-extensions.php 파일을 생성하여
     * 런타임에 Composer ClassLoader에 PSR-4 네임스페이스를 등록합니다.
     *
     * 테스트 환경(APP_ENV=testing)에서는 자동으로 스킵됩니다.
     */
    public function updateComposerAutoload(): void
    {
        // 테스트 환경에서 오토로드 업데이트 스킵 (성능 최적화)
        // phpunit.xml에서 APP_ENV=testing으로 설정됨
        if (app()->environment('testing')) {
            return;
        }

        $this->generateAutoloadFile();

        // 현재 프로세스의 Composer ClassLoader 에도 갱신된 PSR-4 를 즉시 반영.
        // 파일 쓰기만으로는 다음 요청 부트스트랩 시점부터 적용되므로, 업데이트 실행
        // 흐름(copyToActive → updateComposerAutoload → runUpgradeSteps) 내에서
        // 신규 네임스페이스(beta 업그레이드로 추가된 Seeder/Model 등) 의 autoload 가
        // 실패하지 않도록 런타임 재등록을 수행한다.
        $this->reregisterRuntimeAutoload();
    }

    /**
     * 현재 프로세스의 Composer ClassLoader 에 갱신된 PSR-4 매핑을 재등록합니다.
     *
     * `generateAutoloadFile()` 은 autoload-extensions.php 를 디스크에 다시 쓰지만,
     * 이 파일은 CoreServiceProvider::register() / public/index.php 진입점에서만
     * 로드되므로, 동일 프로세스 내부에서 PSR-4 네임스페이스가 추가·변경된 경우
     * 다음 부트스트랩 이전까지 신규 매핑이 반영되지 않는다.
     *
     * 본 메서드는 `Composer\Autoload\ClassLoader::getRegisteredLoaders()` 로 현재
     * 프로세스에 등록된 ClassLoader 를 조회하여 `addPsr4()` 를 다시 호출해 신규
     * 매핑을 즉시 유효화한다. 기존 매핑에 경로가 추가되거나 새 네임스페이스가
     * 등록되며, 동일 매핑은 중복 없이 merge 된다.
     */
    protected function reregisterRuntimeAutoload(): void
    {
        if (! class_exists(\Composer\Autoload\ClassLoader::class, false)) {
            return;
        }

        if (! file_exists($this->autoloadFilePath)) {
            return;
        }

        $loaders = \Composer\Autoload\ClassLoader::getRegisteredLoaders();
        if (empty($loaders)) {
            return;
        }

        foreach ($loaders as $loader) {
            self::registerExtensionAutoload($loader);
            break;
        }
    }

    /**
     * 오토로드 파일을 생성합니다.
     *
     * bootstrap/cache/autoload-extensions.php 파일에
     * 설치된 모듈/플러그인의 PSR-4 매핑과 classmap을 저장합니다.
     */
    public function generateAutoloadFile(): void
    {
        // 모듈별 오토로드 수집
        $moduleAutoloads = $this->collectModuleAutoloads();

        // 플러그인별 오토로드 수집
        $pluginAutoloads = $this->collectPluginAutoloads();

        // PSR-4 병합
        $psr4 = array_merge(
            $moduleAutoloads['psr4'],
            $pluginAutoloads['psr4']
        );

        // Classmap 병합 (module.php, plugin.php 등)
        $classmap = array_merge(
            $moduleAutoloads['classmap'],
            $pluginAutoloads['classmap']
        );

        // Files 병합 (헬퍼 함수 등)
        $files = array_merge(
            $moduleAutoloads['files'],
            $pluginAutoloads['files']
        );

        // Vendor autoloads 병합 (모듈/플러그인의 composer 의존성)
        $vendorAutoloads = array_merge(
            $moduleAutoloads['vendor_autoloads'],
            $pluginAutoloads['vendor_autoloads']
        );

        // 파일 내용 생성
        $content = $this->buildAutoloadFileContent($psr4, $classmap, $files, $vendorAutoloads);

        // 디렉토리 확인
        $dir = dirname($this->autoloadFilePath);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        // 파일 저장
        File::put($this->autoloadFilePath, $content);

        Log::info('확장 오토로드 파일 생성 완료', [
            'path' => $this->autoloadFilePath,
            'psr4_count' => count($psr4),
            'classmap_count' => count($classmap),
            'files_count' => count($files),
            'vendor_autoloads_count' => count($vendorAutoloads),
        ]);
    }

    /**
     * 오토로드 파일 내용을 생성합니다.
     *
     * @param  array  $psr4  PSR-4 네임스페이스 매핑
     * @param  array  $classmap  클래스맵 파일 목록
     * @param  array  $files  헬퍼 파일 목록
     * @return string PHP 파일 내용
     */
    protected function buildAutoloadFileContent(array $psr4, array $classmap, array $files = [], array $vendorAutoloads = []): string
    {
        $generatedAt = now()->toDateTimeString();

        // JSON으로 변환 후 PHP 배열 문법으로 변환 (깔끔한 들여쓰기)
        $psr4Json = json_encode($psr4, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $classmapJson = json_encode($classmap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $filesJson = json_encode($files, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $vendorAutoloadsJson = json_encode($vendorAutoloads, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // JSON을 PHP 배열 문법으로 변환
        $psr4Php = $this->jsonToPhpArray($psr4Json, 1);
        $classmapPhp = $this->jsonToPhpArray($classmapJson, 1);
        $filesPhp = $this->jsonToPhpArray($filesJson, 1);
        $vendorAutoloadsPhp = $this->jsonToPhpArray($vendorAutoloadsJson, 1);

        return <<<PHP
<?php

/**
 * 확장(모듈/플러그인) 오토로드 설정
 *
 * 이 파일은 자동 생성됩니다. 직접 수정하지 마세요.
 * Generated at: {$generatedAt}
 *
 * @see \\App\\Extension\\ExtensionManager::generateAutoloadFile()
 */

return [
    'psr4' => {$psr4Php},
    'classmap' => {$classmapPhp},
    'files' => {$filesPhp},
    'vendor_autoloads' => {$vendorAutoloadsPhp},
];

PHP;
    }

    /**
     * JSON 문자열을 PHP 배열 문법으로 변환합니다.
     *
     * @param  string  $json  JSON 문자열
     * @param  int  $baseIndent  기본 들여쓰기 레벨
     * @return string PHP 배열 문자열
     */
    protected function jsonToPhpArray(string $json, int $baseIndent = 0): string
    {
        // JSON을 PHP 배열 문법으로 변환
        $php = str_replace(['{', '}', ':'], ['[', ']', ' =>'], $json);

        // 들여쓰기 조정
        $lines = explode("\n", $php);
        $result = [];
        $indent = str_repeat('    ', $baseIndent);

        foreach ($lines as $i => $line) {
            if ($i === 0) {
                // 첫 줄 (여는 괄호)는 그대로
                $result[] = $line;
            } elseif ($i === count($lines) - 1) {
                // 마지막 줄 (닫는 괄호)
                $result[] = $indent.$line;
            } else {
                // 중간 줄 - JSON의 기본 4칸 들여쓰기에 base 들여쓰기 추가
                $result[] = $indent.$line;
            }
        }

        return implode("\n", $result);
    }

    /**
     * Composer ClassLoader에 확장 오토로드를 등록합니다.
     *
     * public/index.php 및 artisan에서 호출됩니다.
     *
     * @param  \Composer\Autoload\ClassLoader  $loader  Composer ClassLoader 인스턴스
     */
    public static function registerExtensionAutoload($loader): void
    {
        $autoloadFile = base_path('bootstrap/cache/autoload-extensions.php');

        if (! file_exists($autoloadFile)) {
            return;
        }

        $autoloads = require $autoloadFile;

        // PSR-4 네임스페이스 등록
        if (! empty($autoloads['psr4'])) {
            foreach ($autoloads['psr4'] as $namespace => $paths) {
                // 경로가 배열인 경우와 문자열인 경우 모두 처리
                $paths = (array) $paths;
                foreach ($paths as $path) {
                    $absolutePath = base_path($path);
                    if (is_dir($absolutePath)) {
                        $loader->addPsr4($namespace, $absolutePath);
                    }
                }
            }
        }

        // Classmap 파일 로드 (module.php, plugin.php)
        if (! empty($autoloads['classmap'])) {
            foreach ($autoloads['classmap'] as $file) {
                $absolutePath = base_path($file);
                if (file_exists($absolutePath)) {
                    require_once $absolutePath;
                }
            }
        }

        // Files 로드 (헬퍼 함수 등)
        if (! empty($autoloads['files'])) {
            foreach ($autoloads['files'] as $file) {
                $absolutePath = base_path($file);
                if (file_exists($absolutePath)) {
                    require_once $absolutePath;
                }
            }
        }

        // Vendor autoloads 로드 (모듈/플러그인의 composer 의존성)
        if (! empty($autoloads['vendor_autoloads'])) {
            foreach ($autoloads['vendor_autoloads'] as $vendorAutoload) {
                $absolutePath = base_path($vendorAutoload);
                if (file_exists($absolutePath)) {
                    require_once $absolutePath;
                }
            }
        }
    }

    /**
     * 설치된 모듈의 오토로드 설정을 수집합니다.
     *
     * @return array ['psr4' => [...], 'classmap' => [...], 'files' => [...]]
     */
    protected function collectModuleAutoloads(): array
    {
        $psr4 = [];
        $classmap = [];
        $files = [];
        $vendorAutoloads = [];

        if (! File::exists($this->modulesPath)) {
            return ['psr4' => $psr4, 'classmap' => $classmap, 'files' => $files, 'vendor_autoloads' => $vendorAutoloads];
        }

        // 데이터베이스 테이블이 존재하지 않으면 빈 배열 반환 (마이그레이션 전)
        if (! Schema::hasTable('modules')) {
            return ['psr4' => $psr4, 'classmap' => $classmap, 'files' => $files, 'vendor_autoloads' => $vendorAutoloads];
        }

        // 설치된 모듈의 identifier 목록 가져오기
        $installedModules = $this->moduleRepository->getAll();
        $installedIdentifiers = $installedModules->pluck('identifier')->toArray();

        $moduleDirs = File::directories($this->modulesPath);

        foreach ($moduleDirs as $moduleDir) {
            $moduleName = basename($moduleDir);

            // _bundled, _pending 등 내부 디렉토리 건너뛰기
            if (str_starts_with($moduleName, '_')) {
                continue;
            }

            $composerFile = $moduleDir.'/composer.json';

            if (File::exists($composerFile)) {
                $moduleComposer = json_decode(File::get($composerFile), true);

                // 디렉토리명이 곧 identifier (예: sirsoft-sample)
                $identifier = $moduleName;

                // 설치된 모듈만 처리
                if (! in_array($identifier, $installedIdentifiers)) {
                    continue;
                }

                // PSR-4 오토로드 추가
                if (isset($moduleComposer['autoload']['psr-4'])) {
                    foreach ($moduleComposer['autoload']['psr-4'] as $namespace => $path) {
                        // 경로가 배열인 경우 처리
                        if (is_array($path)) {
                            $psr4[$namespace] = array_map(
                                fn ($p) => 'modules/'.$moduleName.'/'.$p,
                                $path
                            );
                        } else {
                            $psr4[$namespace] = 'modules/'.$moduleName.'/'.$path;
                        }
                    }
                }

                // module.php를 classmap에 추가
                $moduleFile = $moduleDir.'/module.php';
                if (File::exists($moduleFile)) {
                    $classmap[] = 'modules/'.$moduleName.'/module.php';
                }

                // Files 오토로드 추가 (헬퍼 함수 등)
                if (isset($moduleComposer['autoload']['files'])) {
                    foreach ($moduleComposer['autoload']['files'] as $file) {
                        $files[] = 'modules/'.$moduleName.'/'.$file;
                    }
                }

                // Vendor autoload 추가 (모듈 자체 composer 의존성)
                $vendorAutoloadFile = $moduleDir.'/vendor/autoload.php';
                if (File::exists($vendorAutoloadFile)) {
                    $vendorAutoloads[] = 'modules/'.$moduleName.'/vendor/autoload.php';
                }
            }
        }

        return ['psr4' => $psr4, 'classmap' => $classmap, 'files' => $files, 'vendor_autoloads' => $vendorAutoloads];
    }

    /**
     * 설치된 플러그인의 오토로드 설정을 수집합니다.
     *
     * @return array ['psr4' => [...], 'classmap' => [...], 'files' => [...]]
     */
    protected function collectPluginAutoloads(): array
    {
        $psr4 = [];
        $classmap = [];
        $files = [];
        $vendorAutoloads = [];

        if (! File::exists($this->pluginsPath)) {
            return ['psr4' => $psr4, 'classmap' => $classmap, 'files' => $files, 'vendor_autoloads' => $vendorAutoloads];
        }

        // 데이터베이스 테이블이 존재하지 않으면 빈 배열 반환 (마이그레이션 전)
        if (! Schema::hasTable('plugins')) {
            return ['psr4' => $psr4, 'classmap' => $classmap, 'files' => $files, 'vendor_autoloads' => $vendorAutoloads];
        }

        // 설치된 플러그인의 identifier 목록 가져오기
        $installedPlugins = $this->pluginRepository->getAll();
        $installedIdentifiers = $installedPlugins->pluck('identifier')->toArray();

        $pluginDirs = File::directories($this->pluginsPath);

        foreach ($pluginDirs as $pluginDir) {
            $pluginName = basename($pluginDir);

            // _bundled, _pending 등 내부 디렉토리 건너뛰기
            if (str_starts_with($pluginName, '_')) {
                continue;
            }

            $composerFile = $pluginDir.'/composer.json';

            if (File::exists($composerFile)) {
                $pluginComposer = json_decode(File::get($composerFile), true);

                // 디렉토리명이 곧 identifier (예: sirsoft-payment)
                $identifier = $pluginName;

                // 설치된 플러그인만 처리
                if (! in_array($identifier, $installedIdentifiers)) {
                    continue;
                }

                // PSR-4 오토로드 추가
                if (isset($pluginComposer['autoload']['psr-4'])) {
                    foreach ($pluginComposer['autoload']['psr-4'] as $namespace => $path) {
                        // 경로가 배열인 경우 처리
                        if (is_array($path)) {
                            $psr4[$namespace] = array_map(
                                fn ($p) => 'plugins/'.$pluginName.'/'.$p,
                                $path
                            );
                        } else {
                            $psr4[$namespace] = 'plugins/'.$pluginName.'/'.$path;
                        }
                    }
                }

                // plugin.php를 classmap에 추가
                $pluginFile = $pluginDir.'/plugin.php';
                if (File::exists($pluginFile)) {
                    $classmap[] = 'plugins/'.$pluginName.'/plugin.php';
                }

                // Files 오토로드 추가 (헬퍼 함수 등)
                if (isset($pluginComposer['autoload']['files'])) {
                    foreach ($pluginComposer['autoload']['files'] as $file) {
                        $files[] = 'plugins/'.$pluginName.'/'.$file;
                    }
                }

                // Vendor autoload 추가 (플러그인 자체 composer 의존성)
                $vendorAutoloadFile = $pluginDir.'/vendor/autoload.php';
                if (File::exists($vendorAutoloadFile)) {
                    $vendorAutoloads[] = 'plugins/'.$pluginName.'/vendor/autoload.php';
                }
            }
        }

        return ['psr4' => $psr4, 'classmap' => $classmap, 'files' => $files, 'vendor_autoloads' => $vendorAutoloads];
    }

    /**
     * 모듈 identifier를 네임스페이스로 변환합니다.
     *
     * 예: 'sirsoft-ecommerce' → 'Modules\Sirsoft\Ecommerce\'
     *
     * @param  string  $identifier  모듈 식별자 (vendor-module 형식)
     * @return string PSR-4 네임스페이스
     */
    public static function moduleIdentifierToNamespace(string $identifier): string
    {
        return self::identifierToNamespace($identifier, 'Modules');
    }

    /**
     * 플러그인 identifier를 네임스페이스로 변환합니다.
     *
     * 예: 'sirsoft-payment' → 'Plugins\Sirsoft\Payment\'
     *
     * @param  string  $identifier  플러그인 식별자 (vendor-plugin 형식)
     * @return string PSR-4 네임스페이스
     */
    public static function pluginIdentifierToNamespace(string $identifier): string
    {
        return self::identifierToNamespace($identifier, 'Plugins');
    }

    /**
     * 확장 identifier를 네임스페이스로 변환합니다.
     *
     * identifier 형식: 'vendor-name' (예: 'sirsoft-ecommerce', 'sirsoft-payment')
     * 변환 결과: '{prefix}\Vendor\Name\' (예: 'Modules\Sirsoft\Ecommerce\')
     *
     * @param  string  $identifier  확장 식별자
     * @param  string  $prefix  네임스페이스 접두어 (Modules 또는 Plugins)
     * @return string PSR-4 네임스페이스
     */
    protected static function identifierToNamespace(string $identifier, string $prefix): string
    {
        // identifier를 '-'로 분리하고 각 부분을 PascalCase로 변환
        // 언더스코어도 단어 구분자로 처리 (예: daum_postcode → DaumPostcode)
        $parts = array_map(
            fn ($part) => str_replace(' ', '', ucwords(str_replace('_', ' ', $part))),
            explode('-', $identifier)
        );

        // 네임스페이스 조합: Prefix\Vendor\Name\
        return $prefix.'\\'.implode('\\', $parts).'\\';
    }

    /**
     * 디렉토리명(vendor-name)을 네임스페이스(Vendor\Name)로 변환합니다.
     *
     * 하이픈('-')은 네임스페이스 구분자('\')로, 언더스코어('_')는 PascalCase 단어 경계로 처리됩니다.
     * 예: 'sirsoft-daum_postcode' → 'Sirsoft\DaumPostcode'
     *
     * @param  string  $directoryName  디렉토리명 (예: sirsoft-ecommerce, sirsoft-daum_postcode)
     * @return string 네임스페이스 (예: Sirsoft\Ecommerce, Sirsoft\DaumPostcode)
     */
    public static function directoryToNamespace(string $directoryName): string
    {
        $parts = explode('-', $directoryName);

        $namespace = array_map(function ($part) {
            return str_replace(' ', '', ucwords(str_replace('_', ' ', $part)));
        }, $parts);

        return implode('\\', $namespace);
    }

    /**
     * 확장 식별자의 형식을 검증합니다.
     *
     * ValidExtensionIdentifier Rule을 직접 호출하여 검증하고,
     * 실패 시 InvalidArgumentException을 throw합니다.
     *
     * @param  string  $identifier  확장 식별자
     *
     * @throws \InvalidArgumentException 식별자 형식이 올바르지 않을 때
     */
    public static function validateIdentifierFormat(string $identifier): void
    {
        $failed = false;
        $message = '';

        (new \App\Rules\ValidExtensionIdentifier)->validate(
            'identifier',
            $identifier,
            function ($msg) use (&$failed, &$message) {
                $failed = true;
                $message = $msg;
            }
        );

        if ($failed) {
            throw new \InvalidArgumentException($message);
        }
    }

    /**
     * 확장(모듈/플러그인)의 composer.json에서 PSR-4 매핑을 읽어 동적으로 오토로드를 등록합니다.
     *
     * 설치 시점에는 autoload-extensions.php가 아직 갱신되지 않으므로,
     * 시더 실행 전에 해당 확장의 네임스페이스를 Composer ClassLoader에 등록해야 합니다.
     *
     * @param  string  $type  확장 타입 ('modules' 또는 'plugins')
     * @param  string  $dirName  확장 디렉토리명 (예: 'sirsoft-ecommerce')
     */
    public static function registerExtensionAutoloadPaths(string $type, string $dirName): void
    {
        $composerFile = base_path("{$type}/{$dirName}/composer.json");

        if (! file_exists($composerFile)) {
            return;
        }

        $composerJson = json_decode(file_get_contents($composerFile), true);

        if (empty($composerJson['autoload']['psr-4'])) {
            return;
        }

        $loader = require base_path('vendor/autoload.php');

        foreach ($composerJson['autoload']['psr-4'] as $namespace => $path) {
            $paths = is_array($path) ? $path : [$path];

            foreach ($paths as $p) {
                $absolutePath = base_path("{$type}/{$dirName}/{$p}");
                if (is_dir($absolutePath)) {
                    $loader->addPsr4($namespace, $absolutePath);
                }
            }
        }
    }

    /**
     * 확장의 composer.json에 외부 패키지 의존성이 있는지 확인합니다.
     *
     * php, ext-* 확장을 제외한 외부 패키지만 확인합니다.
     *
     * @param  string  $type  확장 타입 ('modules' 또는 'plugins')
     * @param  string  $dirName  확장 디렉토리명 (예: 'sirsoft-ecommerce')
     * @return bool 외부 패키지 의존성 존재 여부
     */
    public function hasComposerDependencies(string $type, string $dirName): bool
    {
        return ! empty($this->getComposerDependencies($type, $dirName));
    }

    /**
     * 지정 경로의 composer.json에 외부 패키지 의존성이 있는지 확인합니다.
     *
     * php, ext-* 확장을 제외한 외부 패키지만 확인합니다.
     *
     * @param  string  $path  composer.json이 있는 디렉토리 경로
     * @return bool 외부 패키지 의존성 존재 여부
     */
    public function hasComposerDependenciesAt(string $path): bool
    {
        return ! empty($this->getComposerDependenciesAt($path));
    }


    /**
     * 확장의 composer.json에서 외부 패키지 의존성 목록을 반환합니다.
     *
     * php, ext-* 확장을 제외한 외부 패키지만 반환합니다.
     *
     * @param  string  $type  확장 타입 ('modules' 또는 'plugins')
     * @param  string  $dirName  확장 디렉토리명 (예: 'sirsoft-ecommerce')
     * @return array 패키지 의존성 목록 ['vendor/package' => 'version', ...]
     */
    public function getComposerDependencies(string $type, string $dirName): array
    {
        $composerFile = base_path("{$type}/{$dirName}/composer.json");

        if (! file_exists($composerFile)) {
            return [];
        }

        $composerJson = json_decode(file_get_contents($composerFile), true);
        $require = $composerJson['require'] ?? [];

        // php, ext-* 제외
        return array_filter($require, function (string $package) {
            return $package !== 'php' && ! str_starts_with($package, 'ext-');
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * 지정 경로의 composer.json에서 외부 패키지 의존성 목록을 반환합니다.
     *
     * php, ext-* 확장을 제외한 외부 패키지만 반환합니다.
     *
     * @param  string  $path  composer.json이 있는 디렉토리 경로
     * @return array 패키지 의존성 목록 ['vendor/package' => 'version', ...]
     */
    public function getComposerDependenciesAt(string $path): array
    {
        $composerFile = $path.DIRECTORY_SEPARATOR.'composer.json';

        if (! file_exists($composerFile)) {
            return [];
        }

        $composerJson = json_decode(file_get_contents($composerFile), true);
        $require = $composerJson['require'] ?? [];

        // php, ext-* 제외
        return array_filter($require, function (string $package) {
            return $package !== 'php' && ! str_starts_with($package, 'ext-');
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * 스테이징과 활성 디렉토리의 composer.json/composer.lock이 동일한지 확인합니다.
     *
     * 두 파일이 모두 동일하면 composer install을 스킵할 수 있습니다.
     * 활성 디렉토리 미존재, vendor/ 미존재 시 false를 반환합니다.
     *
     * @param  string  $stagingPath  스테이징(신규) 디렉토리
     * @param  string  $activePath  활성(현재) 디렉토리
     * @return bool 두 파일이 모두 동일하면 true
     */
    public function isComposerUnchanged(string $stagingPath, string $activePath): bool
    {
        // 활성 디렉토리 또는 vendor/ 미존재 → 반드시 설치 필요
        if (! is_dir($activePath) || ! is_dir($activePath.DIRECTORY_SEPARATOR.'vendor')) {
            return false;
        }

        $stagingJson = $stagingPath.DIRECTORY_SEPARATOR.'composer.json';
        $activeJson = $activePath.DIRECTORY_SEPARATOR.'composer.json';

        // composer.json이 한쪽이라도 없으면 변경된 것으로 간주
        if (! file_exists($stagingJson) || ! file_exists($activeJson)) {
            return false;
        }

        // composer.json 비교
        if (md5_file($stagingJson) !== md5_file($activeJson)) {
            Log::info('composer.json 변경 감지', [
                'staging' => $stagingPath,
                'active' => $activePath,
            ]);

            return false;
        }

        // composer.lock 비교
        $stagingLock = $stagingPath.DIRECTORY_SEPARATOR.'composer.lock';
        $activeLock = $activePath.DIRECTORY_SEPARATOR.'composer.lock';
        $stagingLockExists = file_exists($stagingLock);
        $activeLockExists = file_exists($activeLock);

        // 한쪽만 존재하면 변경된 것으로 간주
        if ($stagingLockExists !== $activeLockExists) {
            Log::info('composer.lock 존재 여부 불일치', [
                'staging_exists' => $stagingLockExists,
                'active_exists' => $activeLockExists,
            ]);

            return false;
        }

        // 둘 다 존재하면 내용 비교
        if ($stagingLockExists && $activeLockExists) {
            if (md5_file($stagingLock) !== md5_file($activeLock)) {
                Log::info('composer.lock 변경 감지', [
                    'staging' => $stagingPath,
                    'active' => $activePath,
                ]);

                return false;
            }
        }

        Log::info('composer 의존성 변경 없음 — 스킵 가능', [
            'staging' => $stagingPath,
            'active' => $activePath,
        ]);

        return true;
    }

    /**
     * 확장의 composer install을 실행합니다.
     *
     * @param  string  $type  확장 타입 ('modules' 또는 'plugins')
     * @param  string  $dirName  확장 디렉토리명 (예: 'sirsoft-ecommerce')
     * @param  bool  $noDev  dev 의존성 제외 여부
     * @param  Command|null  $command  Artisan 커맨드 인스턴스 (출력용)
     * @return bool 성공 여부
     */
    public function runComposerInstall(string $type, string $dirName, bool $noDev = true, ?Command $command = null): bool
    {
        $extensionPath = base_path("{$type}/{$dirName}");

        return $this->runComposerInstallAt($extensionPath, $noDev, $command);
    }

    /**
     * 지정된 경로에서 composer install을 실행합니다.
     *
     * 확장 활성 디렉토리뿐 아니라, _pending 스테이징 경로 등
     * 임의의 경로에서도 composer install을 실행할 수 있습니다.
     *
     * @param  string  $extensionPath  composer.json이 있는 디렉토리 경로
     * @param  bool  $noDev  dev 의존성 제외 여부
     * @param  Command|null  $command  Artisan 커맨드 인스턴스 (출력용)
     * @return bool 성공 여부
     */
    public function runComposerInstallAt(string $extensionPath, bool $noDev = true, ?Command $command = null): bool
    {
        $composerFile = $extensionPath.'/composer.json';

        if (! file_exists($composerFile)) {
            return false;
        }

        try {
            $composerBinary = $this->findComposerBinary();
        } catch (\RuntimeException $e) {
            Log::error('Composer 바이너리를 찾을 수 없습니다', ['error' => $e->getMessage()]);
            $command?->error('❌ '.$e->getMessage());

            return false;
        }

        // Composer 실행 명령어 구성
        $phpBinary = config('process.php_binary', 'php');
        if (str_contains($composerBinary, ' ')) {
            // 공백 포함 = 전체 실행 명령어 (예: "/usr/local/php84/bin/php /home/user/g7/composer.phar")
            $commandArgs = array_merge(explode(' ', $composerBinary), ['install', '--no-interaction', '--optimize-autoloader']);
        } elseif (str_ends_with($composerBinary, '.phar')) {
            $commandArgs = [$phpBinary, $composerBinary, 'install', '--no-interaction', '--optimize-autoloader'];
        } else {
            $commandArgs = [$composerBinary, 'install', '--no-interaction', '--optimize-autoloader'];
        }

        if ($noDev) {
            $commandArgs[] = '--no-dev';
        }

        // Windows 환경에서는 cmd /c 사용
        if (PHP_OS_FAMILY === 'Windows') {
            $commandArgs = array_merge(['cmd', '/c'], $commandArgs);
        }

        $process = new Process($commandArgs);
        $process->setWorkingDirectory($extensionPath);
        $process->setTimeout(300); // 5분 타임아웃

        // 인스톨러(install-worker.php)와 동일한 환경변수 구성
        // 웹 서버 환경에서는 COMPOSER_HOME, TEMP 등이 없거나 쓰기 불가능할 수 있음
        $env = [];
        foreach (['PATH', 'SystemRoot', 'TEMP', 'TMP', 'APPDATA', 'LOCALAPPDATA', 'USERPROFILE'] as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $env[$key] = $value;
            }
        }

        // Composer 관련 환경변수
        $composerHome = storage_path('composer');
        if (! is_dir($composerHome)) {
            @mkdir($composerHome, 0755, true);
        }
        $env['COMPOSER_HOME'] = $composerHome;
        $env['HOME'] = $composerHome;

        // Windows: TEMP 디렉토리가 없거나 쓰기 불가능하면 storage/temp 사용
        if (PHP_OS_FAMILY === 'Windows') {
            if (! isset($env['TEMP']) || ! is_dir($env['TEMP']) || ! is_writable($env['TEMP'])) {
                $tempDir = storage_path('temp');
                if (! is_dir($tempDir)) {
                    @mkdir($tempDir, 0755, true);
                }
                $env['TEMP'] = $tempDir;
                $env['TMP'] = $tempDir;
            }
        }

        $process->setEnv($env);

        $process->run(function ($outputType, $buffer) use ($command) {
            if ($command) {
                $command->getOutput()->write($buffer);
            }
        });

        if ($process->isSuccessful()) {
            Log::info('확장 Composer 의존성 설치 완료', [
                'path' => $extensionPath,
            ]);

            return true;
        }

        Log::warning('확장 Composer 의존성 설치 실패', [
            'path' => $extensionPath,
            'exit_code' => $process->getExitCode(),
            'error' => $process->getErrorOutput(),
        ]);

        return false;
    }

    /**
     * Composer 바이너리 경로를 감지합니다.
     *
     * 감지 순서:
     * 1. 환경변수 COMPOSER_BINARY
     * 2. PATH의 composer
     * 3. 루트 디렉토리의 composer.phar
     *
     * @return string Composer 바이너리 경로
     *
     * @throws \RuntimeException Composer를 찾을 수 없는 경우
     */
    private function findComposerBinary(): string
    {
        // 1. config('process.composer_binary') 우선 확인
        $configBinary = config('process.composer_binary');
        if ($configBinary) {
            // 공백 포함 = 전체 실행 명령어 → file_exists 체크 불필요
            if (str_contains($configBinary, ' ') || file_exists($configBinary)) {
                return $configBinary;
            }
        }

        // 2. PATH에서 composer 검색
        $whichCommand = PHP_OS_FAMILY === 'Windows' ? ['where', 'composer'] : ['which', 'composer'];
        $process = new Process($whichCommand);
        $process->run();

        if ($process->isSuccessful()) {
            $path = trim(explode("\n", trim($process->getOutput()))[0]);
            if (! empty($path)) {
                return $path;
            }
        }

        // 3. 루트 디렉토리의 composer.phar
        $pharPath = base_path('composer.phar');
        if (file_exists($pharPath)) {
            return $pharPath;
        }

        throw new \RuntimeException(
            'Composer 바이너리를 찾을 수 없습니다. COMPOSER_BINARY 환경변수를 설정하거나 composer를 PATH에 추가하세요.'
        );
    }

    /**
     * 여러 확장이 동일 패키지를 사용하는 경우를 감지합니다.
     *
     * @return array 중복 패키지 정보 ['package/name' => ['modules/ext1', 'plugins/ext2'], ...]
     */
    public function detectDuplicatePackages(): array
    {
        $packageUsage = [];

        // 모듈 패키지 수집
        if (File::exists($this->modulesPath)) {
            foreach (File::directories($this->modulesPath) as $moduleDir) {
                $moduleName = basename($moduleDir);
                if (str_starts_with($moduleName, '_')) {
                    continue;
                }
                $deps = $this->getComposerDependencies('modules', $moduleName);
                foreach (array_keys($deps) as $package) {
                    $packageUsage[$package][] = "modules/{$moduleName}";
                }
            }
        }

        // 플러그인 패키지 수집
        if (File::exists($this->pluginsPath)) {
            foreach (File::directories($this->pluginsPath) as $pluginDir) {
                $pluginName = basename($pluginDir);
                if (str_starts_with($pluginName, '_')) {
                    continue;
                }
                $deps = $this->getComposerDependencies('plugins', $pluginName);
                foreach (array_keys($deps) as $package) {
                    $packageUsage[$package][] = "plugins/{$pluginName}";
                }
            }
        }

        // 2개 이상 확장에서 사용하는 패키지만 반환
        return array_filter($packageUsage, fn ($users) => count($users) > 1);
    }

    // ──────────────────────────────────────────────────
    //  GitHub 다운로드 유틸리티 (GithubHelper로 위임)
    //
    //  `allow_url_fopen=Off` 공유 호스팅 대응을 위해 실제 HTTP 호출은
    //  `GithubHelper`의 Http 파사드 기반 구현을 사용합니다.
    // ──────────────────────────────────────────────────

    /**
     * 사용 가능한 아카이브 추출 전략을 구성합니다.
     *
     * @return array 추출 전략 배열
     */
    public function buildExtractionStrategies(): array
    {
        $strategies = [];

        // 1단계: ZipArchive (PHP zip 확장)
        if (class_exists(\ZipArchive::class)) {
            $strategies[] = [
                'archive_type' => 'zipball',
                'method' => 'extractWithZipArchive',
                'label' => 'ZipArchive',
            ];
        }

        // 2단계: unzip 명령어 (Linux만)
        if (PHP_OS_FAMILY !== 'Windows' && $this->isUnzipAvailable()) {
            $strategies[] = [
                'archive_type' => 'zipball',
                'method' => 'extractWithUnzip',
                'label' => 'unzip',
            ];
        }

        return $strategies;
    }

    /**
     * ZipArchive를 사용하여 아카이브를 추출합니다.
     *
     * @param  string  $zipPath  ZIP 파일 경로
     * @param  string  $extractDir  추출 대상 디렉토리
     * @return void
     *
     * @throws \RuntimeException 추출 실패 시
     */
    public function extractWithZipArchive(string $zipPath, string $extractDir): void
    {
        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException(__('settings.core_update.zip_extract_failed'));
        }

        $zip->extractTo($extractDir);
        $zip->close();
    }

    /**
     * unzip 명령어를 사용하여 아카이브를 추출합니다.
     *
     * @param  string  $zipPath  ZIP 파일 경로
     * @param  string  $extractDir  추출 대상 디렉토리
     * @return void
     *
     * @throws \RuntimeException 추출 실패 시
     */
    public function extractWithUnzip(string $zipPath, string $extractDir): void
    {
        $escapedZip = escapeshellarg($zipPath);
        $escapedDir = escapeshellarg($extractDir);

        exec("unzip -o {$escapedZip} -d {$escapedDir} 2>&1", $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException(__('settings.core_update.unzip_command_failed', [
                'code' => $exitCode,
                'output' => implode("\n", array_slice($output, -5)),
            ]));
        }
    }

    /**
     * unzip 명령어 사용 가능 여부를 확인합니다.
     *
     * @return bool
     */
    public function isUnzipAvailable(): bool
    {
        exec('which unzip 2>/dev/null', $output, $exitCode);

        return $exitCode === 0;
    }

    /**
     * GitHub에서 확장을 다운로드하고 추출합니다.
     *
     * 코어 업데이트의 downloadUpdate()와 동일한 폴백 체인을 사용합니다.
     * (zipball/ZipArchive → zipball/unzip)
     *
     * 모든 HTTP 호출은 `GithubHelper` (Http 파사드 기반)로 위임되어
     * `allow_url_fopen=Off` 환경에서도 정상 동작합니다.
     *
     * @param  string  $owner  GitHub 저장소 소유자
     * @param  string  $repo  GitHub 저장소 이름
     * @param  string  $version  버전 태그
     * @param  string  $destDir  추출된 파일을 저장할 디렉토리
     * @param  string  $token  GitHub Personal Access Token (확장별 토큰, 기본 빈 문자열)
     * @return string 추출된 소스 디렉토리 경로
     *
     * @throws \RuntimeException 다운로드/추출 실패 시
     */
    public function downloadAndExtractFromGitHub(string $owner, string $repo, string $version, string $destDir, string $token = ''): string
    {
        $extractDir = $destDir.DIRECTORY_SEPARATOR.'extracted';

        $strategies = $this->buildExtractionStrategies();
        if (empty($strategies)) {
            throw new \RuntimeException(__('settings.core_update.no_extract_method_available'));
        }

        $lastError = null;

        foreach ($strategies as $strategy) {
            $archiveType = $strategy['archive_type'];
            $extractMethod = $strategy['method'];
            $label = $strategy['label'];

            $archiveUrl = GithubHelper::resolveArchiveUrl($owner, $repo, $version, $archiveType, $token);
            if (! $archiveUrl) {
                continue;
            }

            $extension = $archiveType === 'zipball' ? '.zip' : '.tar.gz';
            $archivePath = $destDir.DIRECTORY_SEPARATOR.'download'.$extension;

            try {
                GithubHelper::downloadArchive($archiveUrl, $archivePath, $token);

                if (File::isDirectory($extractDir)) {
                    File::deleteDirectory($extractDir);
                }
                File::ensureDirectoryExists($extractDir);

                $this->$extractMethod($archivePath, $extractDir);

                // GitHub 아카이브는 owner-repo-hash/ 형태로 압축해제됨
                $extractedDirs = File::directories($extractDir);
                if (empty($extractedDirs)) {
                    throw new \RuntimeException(__('settings.core_update.extract_empty'));
                }

                $sourcePath = $extractedDirs[0];

                File::delete($archivePath);

                return $sourcePath;
            } catch (\Exception $e) {
                $lastError = $e;

                if (File::exists($archivePath)) {
                    File::delete($archivePath);
                }
                if (File::isDirectory($extractDir)) {
                    File::deleteDirectory($extractDir);
                }

                Log::warning("GitHub 다운로드 폴백: {$label} 실패", [
                    'owner' => $owner,
                    'repo' => $repo,
                    'version' => $version,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw new \RuntimeException(
            __('settings.core_update.all_extract_methods_failed'),
            0,
            $lastError
        );
    }

    /**
     * 외부 ZIP 파일을 추출하여 소스 디렉토리 경로를 반환합니다.
     *
     * GitHub zipball 처럼 owner-repo-hash/ 래퍼 디렉토리로 감싼 경우와
     * ZIP 루트가 곧바로 확장 소스인 경우를 모두 지원합니다.
     * 추출 후 extractDir 내용이 단일 디렉토리뿐이면 그 디렉토리를 반환하고,
     * 그 외에는 extractDir 자체를 반환합니다.
     *
     * downloadAndExtractFromGitHub 와 동일한 폴백 체인(ZipArchive → unzip)을
     * 사용하며, GitHub 호출은 수행하지 않습니다.
     *
     * @param  string  $zipPath  외부 ZIP 파일 경로
     * @param  string  $destDir  추출 작업 디렉토리 (함수가 'extracted' 하위에 추출)
     * @return string 확장 소스 디렉토리 경로 (래퍼 감지 후)
     *
     * @throws \RuntimeException 추출 실패 또는 지원 추출 수단 부재 시
     */
    public function extractFromZip(string $zipPath, string $destDir): string
    {
        if (! File::exists($zipPath)) {
            throw new \RuntimeException(__('settings.core_update.zip_file_not_found', ['path' => $zipPath]));
        }

        $strategies = $this->buildExtractionStrategies();
        if (empty($strategies)) {
            throw new \RuntimeException(__('settings.core_update.no_extract_method_available'));
        }

        $extractDir = $destDir.DIRECTORY_SEPARATOR.'extracted';
        if (File::isDirectory($extractDir)) {
            File::deleteDirectory($extractDir);
        }
        File::ensureDirectoryExists($extractDir);

        $lastError = null;
        foreach ($strategies as $strategy) {
            $method = $strategy['method'];
            $label = $strategy['label'];

            try {
                $this->$method($zipPath, $extractDir);

                return $this->resolveExtractedRoot($extractDir);
            } catch (\Throwable $e) {
                $lastError = $e;

                // 다음 전략 시도 전 extractDir 초기화
                if (File::isDirectory($extractDir)) {
                    File::deleteDirectory($extractDir);
                }
                File::ensureDirectoryExists($extractDir);

                Log::warning("ZIP 추출 폴백: {$label} 실패", [
                    'zip' => $zipPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw new \RuntimeException(
            __('settings.core_update.all_extract_methods_failed'),
            0,
            $lastError
        );
    }

    /**
     * 추출 디렉토리에서 확장 소스 루트를 판별합니다.
     *
     * - 하위에 파일이 있고 디렉토리가 0개 또는 2개 이상 → extractDir 자체가 소스
     * - 하위에 디렉토리 1개만 있고 파일 없음 → 그 디렉토리가 래퍼 → 하위 반환
     * - 혼합(파일 + 단일 디렉토리)인 경우 → extractDir 자체 반환 (manifest 루트에 있다고 가정)
     *
     * @param  string  $extractDir  추출 대상 디렉토리
     * @return string 확장 소스 디렉토리 경로
     */
    protected function resolveExtractedRoot(string $extractDir): string
    {
        $dirs = File::directories($extractDir);
        $files = File::files($extractDir);

        if (count($dirs) === 1 && count($files) === 0) {
            return $dirs[0];
        }

        return $extractDir;
    }

    /**
     * 외부 ZIP 소스를 스테이징 전단계까지 준비합니다.
     *
     * 각 확장 Manager(Module/Plugin/Template)의 --zip 업데이트 경로에서
     * 공용으로 사용하는 헬퍼입니다. ZIP 을 임시 디렉토리에 추출하고 manifest 를
     * 검증(파일 존재, identifier 일치, version 존재)한 뒤 결과를 반환합니다.
     *
     * 호출자는 반환된 temp_dir 을 반드시 정리해야 합니다 (try-finally 로 감싸는 것을 권장).
     *
     * @param  string  $zipPath  외부 ZIP 파일 경로
     * @param  string  $identifier  기대하는 확장 식별자 (manifest 와 일치해야 함)
     * @param  string  $manifestName  manifest 파일명 ('module.json' | 'plugin.json' | 'template.json')
     * @return array{temp_dir: string, extracted_dir: string, to_version: string, manifest: array}
     *
     * @throws \RuntimeException ZIP 추출 실패 / manifest 누락 / identifier 불일치 / version 누락 시
     */
    public function prepareZipSource(string $zipPath, string $identifier, string $manifestName): array
    {
        $tempDir = storage_path('app/temp/ext_zip_'.uniqid());
        File::ensureDirectoryExists($tempDir);

        try {
            $extractedDir = $this->extractFromZip($zipPath, $tempDir);

            $manifestPath = $extractedDir.DIRECTORY_SEPARATOR.$manifestName;
            if (! File::exists($manifestPath)) {
                throw new \RuntimeException(__('extensions.errors.zip_missing_manifest', [
                    'file' => $manifestName,
                    'zip' => $zipPath,
                ]));
            }

            $manifest = json_decode(File::get($manifestPath), true);
            if (! is_array($manifest)) {
                throw new \RuntimeException(__('extensions.errors.zip_invalid_manifest', [
                    'file' => $manifestName,
                ]));
            }

            $manifestId = $manifest['identifier'] ?? null;
            if ($manifestId !== $identifier) {
                throw new \RuntimeException(__('extensions.errors.zip_identifier_mismatch', [
                    'expected' => $identifier,
                    'actual' => $manifestId ?? '(missing)',
                ]));
            }

            $version = $manifest['version'] ?? null;
            if (! is_string($version) || $version === '') {
                throw new \RuntimeException(__('extensions.errors.zip_missing_version', [
                    'file' => $manifestName,
                ]));
            }

            return [
                'temp_dir' => $tempDir,
                'extracted_dir' => $extractedDir,
                'to_version' => $version,
                'manifest' => $manifest,
            ];
        } catch (\Throwable $e) {
            if (File::isDirectory($tempDir)) {
                File::deleteDirectory($tempDir);
            }
            throw $e;
        }
    }
}

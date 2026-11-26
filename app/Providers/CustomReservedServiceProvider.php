<?php

namespace App\Providers;

use App\Support\Custom\CustomViewFinder;
use Composer\Autoload\ClassLoader;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;

/**
 * custom 예약 자리 연결 — 라라벨 개발자가 평소 쓰는 기본 위치를 코어 업데이트와 무관하게 쓰게 한다.
 *
 * - `custom/vendor/autoload.php`(개발자 전용 composer) — 코어·확장 오토로드 **다음에** 로드
 * - `.html` 뷰 = 파일 그대로 (custom 뷰에서만, {@see CustomViewFinder})
 * - `lang/custom` = 번역 네임스페이스 `custom` (`__('custom::board.title')`)
 * - `database/migrations/custom` = 마이그레이션 경로 (`php artisan migrate` 에 포함)
 * - `App\Custom\CustomServiceProvider` — 있으면 **마지막에** 등록 (Twig 엔진·주소 필터·바인딩을 개발자가 한곳에서)
 *
 * 파일·폴더가 없으면 아무 일도 하지 않는다. 라우트(`routes/custom.php`)는 `routes/web.php` 가 맨 앞에서 불러온다.
 * 이 프로바이더는 bootstrap/providers.php 의 **마지막** 항목이어야 한다.
 *
 * @since 7.2.0
 */
class CustomReservedServiceProvider extends ServiceProvider
{
    /** 개발자 서비스 프로바이더 FQCN */
    public const DEVELOPER_PROVIDER = 'App\\Custom\\CustomServiceProvider';

    /** 번역 네임스페이스 */
    public const LANG_NAMESPACE = 'custom';

    /**
     * 개발자 composer 오토로드를 등록합니다.
     *
     * @return void 반환값 없음
     */
    public function register(): void
    {
        self::registerCustomAutoload(base_path('custom'.DIRECTORY_SEPARATOR.'vendor'));
    }

    /**
     * 개발자 composer 의 클래스 로더를 코어 로더 **뒤에** 등록합니다.
     *
     * `custom/vendor/autoload.php` 를 그대로 require 하면 Composer 가 로더를 맨 앞에 붙인다(prepend). 그러면
     * 같은 패키지가 양쪽에 있을 때 개발자 버전이 먼저 쓰이고, 라라벨의 `Application::inferBasePath()` 가 첫 로더의
     * vendor 를 보고 기준 경로를 `custom/` 으로 잘못 잡는다(시험 앱 생성이 깨진다). 그래서 생성된 매핑으로
     * 로더를 직접 만들어 뒤에 붙이고, files 자동 로드는 Composer 와 같은 전역 표식으로 한 번만 읽는다.
     *
     * @param  string  $vendorDir  개발자 vendor 디렉터리
     * @return void 반환값 없음
     */
    public static function registerCustomAutoload(string $vendorDir): void
    {
        $composerDir = $vendorDir.DIRECTORY_SEPARATOR.'composer';
        if (! is_file($composerDir.DIRECTORY_SEPARATOR.'autoload_psr4.php')
            || isset(ClassLoader::getRegisteredLoaders()[$vendorDir])) {
            return;
        }

        $loader = new ClassLoader($vendorDir);
        $map = static fn (string $name): array => is_file($composerDir.DIRECTORY_SEPARATOR.$name)
            ? (array) require $composerDir.DIRECTORY_SEPARATOR.$name
            : [];

        foreach ($map('autoload_namespaces.php') as $namespace => $paths) {
            $loader->set($namespace, $paths);
        }
        foreach ($map('autoload_psr4.php') as $namespace => $paths) {
            $loader->setPsr4($namespace, $paths);
        }
        $loader->addClassMap($map('autoload_classmap.php'));
        $loader->register(false);

        foreach ($map('autoload_files.php') as $identifier => $file) {
            if (empty($GLOBALS['__composer_autoload_files'][$identifier])) {
                $GLOBALS['__composer_autoload_files'][$identifier] = true;
                require $file;
            }
        }
    }

    /**
     * 뷰·번역·마이그레이션·개발자 프로바이더를 연결합니다.
     *
     * @return void 반환값 없음
     */
    public function boot(): void
    {
        $this->registerHtmlViews();

        $langPath = lang_path(self::LANG_NAMESPACE);
        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, self::LANG_NAMESPACE);
        }

        $migrationPath = database_path('migrations'.DIRECTORY_SEPARATOR.'custom');
        if (is_dir($migrationPath)) {
            $this->loadMigrationsFrom($migrationPath);
        }

        // 개발자 프로바이더는 boot 단계 끝에서 등록한다 — 등록 즉시 register·boot 가 돌아, 코어·모듈·플러그인
        // 프로바이더가 모두 boot 된 뒤에 개발자 코드가 실행된다(라우트는 그 뒤 booted 콜백에서 로드된다).
        $developerProvider = $this->developerProviderClass();
        if (class_exists($developerProvider)) {
            $this->app->register($developerProvider);
        }
    }

    /**
     * 개발자 서비스 프로바이더 FQCN.
     *
     * @return string FQCN
     */
    protected function developerProviderClass(): string
    {
        return self::DEVELOPER_PROVIDER;
    }

    /**
     * `.html` 을 파일 그대로 내보내는 뷰 확장자로 등록합니다 (custom 뷰 한정).
     *
     * @return void 반환값 없음
     */
    private function registerHtmlViews(): void
    {
        /** @var Factory $factory */
        $factory = $this->app->make('view');
        $finder = $factory->getFinder();

        if (! $finder instanceof CustomViewFinder && $finder instanceof FileViewFinder) {
            $finder = CustomViewFinder::fromFinder($finder);
            $factory->setFinder($finder);
            $this->app->instance('view.finder', $finder);
        }

        if (! in_array('html', $finder->getExtensions(), true)) {
            $factory->addExtension('html', 'file');
        }
    }
}

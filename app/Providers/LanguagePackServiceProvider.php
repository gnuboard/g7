<?php

namespace App\Providers;

use App\Contracts\Repositories\LanguagePackRepositoryInterface;
use App\Contracts\Repositories\LanguagePackTranslationRepositoryInterface;
use App\Enums\LanguagePackScope;
use App\Extension\HookManager;
use App\Listeners\LanguagePack\MergeFrontendLanguage;
use App\Listeners\LanguagePack\RunSeedersOnLanguagePackLifecycle;
use App\Listeners\LanguagePack\SyncDatabaseTranslations;
use App\Models\LanguagePack;
use App\Repositories\LanguagePackRepository;
use App\Repositories\LanguagePackTranslationRepository;
use App\Services\LanguagePack\LanguagePackBundledRegistrar;
use App\Services\LanguagePack\LanguagePackRegistry;
use App\Services\LanguagePack\LanguagePackSeedInjector;
use App\Services\LanguagePack\LanguagePackTranslator;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\TranslationServiceProvider;
use Throwable;

/**
 * 언어팩 시스템의 부팅을 담당하는 서비스 프로바이더.
 *
 *  1. Repository 인터페이스 ⇒ 구현체 바인딩
 *  2. LanguagePackRegistry 싱글톤 등록
 *  3. Translator 를 LanguagePackTranslator 로 교체 (코어 폴백 지원)
 *  4. 부팅 후 활성 언어팩의 backend 디렉토리를 Translator 에 등록
 *  5. config('app.supported_locales') / locale_names 동적 갱신
 */
class LanguagePackServiceProvider extends ServiceProvider
{
    /**
     * 컨테이너 바인딩을 등록합니다.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->bind(
            LanguagePackRepositoryInterface::class,
            LanguagePackRepository::class
        );

        $this->app->bind(
            LanguagePackTranslationRepositoryInterface::class,
            LanguagePackTranslationRepository::class
        );

        $this->app->singleton(LanguagePackRegistry::class, function (Application $app) {
            return new LanguagePackRegistry(
                $app->make(LanguagePackRepositoryInterface::class)
            );
        });

        $this->app->singleton(LanguagePackSeedInjector::class, function (Application $app) {
            return new LanguagePackSeedInjector(
                $app->make(LanguagePackRegistry::class)
            );
        });

        $this->app->singleton(LanguagePackBundledRegistrar::class, function (Application $app) {
            return new LanguagePackBundledRegistrar(
                $app->make(LanguagePackRepositoryInterface::class),
                $app->make(LanguagePackRegistry::class),
                $app->make(\App\Contracts\Extension\CacheInterface::class),
            );
        });

        $this->registerTranslatorOverride();
    }

    /**
     * 부팅 시 활성 언어팩의 번역 경로를 Translator 에 등록합니다.
     *
     * DB 가 준비되지 않았거나(설치 전) language_packs 테이블이 없으면 조용히 건너뜁니다.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->app->booted(function () {
            if (! $this->isRegistryReady()) {
                return;
            }

            try {
                /** @var LanguagePackRegistry $registry */
                $registry = $this->app->make(LanguagePackRegistry::class);

                foreach ($registry->getActivePacks() as $pack) {
                    $this->registerActivePack($pack);
                }

                $this->refreshSupportedLocales($registry);
                $this->registerSeedFilters();
                $this->registerEventListeners();
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * HookManager 필터에 LanguagePackSeedInjector 의 메서드를 등록합니다.
     *
     * 시더가 applyFilters() 를 호출하면 활성 코어 언어팩의 seed/*.json 으로 다국어 키가 보강됩니다.
     *
     * @return void
     */
    private function registerSeedFilters(): void
    {
        $injector = $this->app->make(LanguagePackSeedInjector::class);

        HookManager::addFilter('core.permissions.config', function ($config) use ($injector) {
            return $injector->injectCorePermissions(is_array($config) ? $config : []);
        });

        HookManager::addFilter('core.roles.config', function ($roles) use ($injector) {
            return $injector->injectCoreRoles(is_array($roles) ? $roles : []);
        });

        HookManager::addFilter('core.menus.config', function ($menus) use ($injector) {
            return $injector->injectCoreMenus(is_array($menus) ? $menus : []);
        });

        HookManager::addFilter('seed.notifications.translations', function ($definitions) use ($injector) {
            return $injector->injectNotifications(is_array($definitions) ? $definitions : []);
        });

        HookManager::addFilter('seed.identity_messages.translations', function ($definitions) use ($injector) {
            return $injector->injectIdentityMessages(is_array($definitions) ? $definitions : []);
        });

        $this->registerExtensionSeedFilters($injector);

        // 프론트엔드 다국어 데이터 병합 (TemplateService::getLanguageDataWithModules 의 마지막 단계)
        $merger = $this->app->make(MergeFrontendLanguage::class);
        HookManager::addFilter('template.language.merge', function ($data, $templateIdentifier = '', $locale = 'ko') use ($merger) {
            return $merger(is_array($data) ? $data : [], (string) $templateIdentifier, (string) $locale);
        });
    }

    /**
     * 활성 모듈/플러그인 언어팩의 seed/*.json 마다 ext entity 시드 필터(`seed.{target}.{entity}.translations`)에
     * LanguagePackSeedInjector::injectExtensionEntity() 를 자동 결선합니다.
     *
     * 시더가 매칭 키(code/slug/key/identifier) 중 하나로 entry 를 식별하므로, entries[0] 에서 매칭 키 후보를
     * 우선순위로 자동 감지합니다. 후보 미발견 시 'code' 를 기본값으로 사용합니다.
     *
     * @param  LanguagePackSeedInjector  $injector  주입기
     * @return void
     */
    public function registerExtensionSeedFilters(LanguagePackSeedInjector $injector): void
    {
        $registry = $this->app->make(LanguagePackRegistry::class);
        $candidates = collect()
            ->merge($registry->getActivePacks(LanguagePackScope::Module->value))
            ->merge($registry->getActivePacks(LanguagePackScope::Plugin->value));

        foreach ($candidates as $pack) {
            $seedDir = $pack->resolveDirectory().DIRECTORY_SEPARATOR.'seed';
            if (! is_dir($seedDir)) {
                continue;
            }
            $target = (string) $pack->target_identifier;
            if ($target === '') {
                continue;
            }
            foreach (glob($seedDir.DIRECTORY_SEPARATOR.'*.json') as $seedFile) {
                $entity = pathinfo($seedFile, PATHINFO_FILENAME);
                if ($entity === 'notifications') {
                    HookManager::addFilter("seed.{$target}.notifications.translations", function ($definitions) use ($injector, $target) {
                        return $injector->injectExtensionNotifications(is_array($definitions) ? $definitions : [], $target);
                    });

                    continue;
                }
                if ($entity === 'identity_messages') {
                    HookManager::addFilter("seed.{$target}.identity_messages.translations", function ($definitions) use ($injector, $target) {
                        return $injector->injectExtensionIdentityMessages(is_array($definitions) ? $definitions : [], $target);
                    });

                    continue;
                }
                if ($entity === 'menus') {
                    // 모듈 admin_menus 동기화 시 ModuleManager 가 발행하는 필터에 결선 (모듈 전용).
                    HookManager::addFilter("module.{$target}.admin_menus.translations", function ($menus) use ($injector, $target) {
                        return $injector->injectExtensionMenus(is_array($menus) ? $menus : [], $target);
                    });

                    continue;
                }
                if ($entity === 'roles') {
                    // 모듈/플러그인 roles 동기화 시 발행되는 필터에 결선.
                    $scopeStr = $pack->scope;
                    HookManager::addFilter("{$scopeStr}.{$target}.roles.translations", function ($roles) use ($injector, $target, $scopeStr) {
                        return $injector->injectExtensionRoles(is_array($roles) ? $roles : [], $target, $scopeStr);
                    });

                    continue;
                }
                if ($entity === 'permissions') {
                    // 모듈/플러그인 permissions 트리 동기화 시 발행되는 필터에 결선.
                    $scopeStr = $pack->scope;
                    HookManager::addFilter("{$scopeStr}.{$target}.permissions.translations", function ($config) use ($injector, $target, $scopeStr) {
                        return $injector->injectExtensionPermissions(is_array($config) ? $config : [], $target, $scopeStr);
                    });

                    continue;
                }
                HookManager::addFilter("seed.{$target}.{$entity}.translations", function ($entries) use ($injector, $target, $entity) {
                    if (! is_array($entries) || empty($entries)) {
                        return $entries;
                    }
                    $matchKey = $this->detectMatchKey($entries[0] ?? []);

                    return $injector->injectExtensionEntity($entries, $target, $entity, $matchKey);
                });
            }
        }
    }

    /**
     * 시드 entry 에서 매칭 키 컬럼을 자동 감지합니다.
     *
     * 우선순위: code > slug > key > identifier > id. 미발견 시 'code'.
     *
     * @param  array<string, mixed>  $entry  시드 entry
     * @return string 매칭 키 컬럼명
     */
    private function detectMatchKey(array $entry): string
    {
        foreach (['code', 'slug', 'key', 'identifier', 'id'] as $key) {
            if (array_key_exists($key, $entry)) {
                return $key;
            }
        }

        return 'code';
    }

    /**
     * `core.language_packs.activated` / `core.language_packs.deactivated` 액션 훅에
     * SyncDatabaseTranslations 리스너를 연결합니다.
     *
     * G7 표준 훅 메커니즘(HookManager::doAction → addAction) 으로 일원화 — 별도 Event 클래스 사용 안 함.
     * 훅 명명은 모듈/플러그인/템플릿 (`core.{type}.activated/deactivated/installed/updated/uninstalled`) 와 동일.
     *
     * @return void
     */
    private function registerEventListeners(): void
    {
        $listener = $this->app->make(SyncDatabaseTranslations::class);

        HookManager::addAction('core.language_packs.activated', function ($pack) use ($listener) {
            $listener->handleActivated($pack);
        });

        HookManager::addAction('core.language_packs.deactivated', function ($pack) use ($listener) {
            $listener->handleDeactivated($pack);
        });

        // entity 시더 자동 재실행 (board_types/claim_reasons 등 다국어 JSON 데이터의 활성 locale 동기화)
        $reseeder = $this->app->make(RunSeedersOnLanguagePackLifecycle::class);

        HookManager::addAction('core.language_packs.activated', function ($pack) use ($reseeder) {
            $reseeder->handleActivated($pack);
        });

        HookManager::addAction('core.language_packs.deactivated', function ($pack) use ($reseeder) {
            $reseeder->handleDeactivated($pack);
        });

        $this->registerBundledRegistrarHooks();
    }

    /**
     * 확장(모듈/플러그인/템플릿) 설치/제거/업데이트 후크에 가상 등록 리스너를 연결합니다.
     *
     * 모듈 설치 후 모듈의 lang 디렉토리를 스캔하여 `bundled_with_extension` 가상 레코드를
     * `language_packs` 테이블에 자동 등록합니다 (계획서 §3.6).
     *
     * @return void
     */
    private function registerBundledRegistrarHooks(): void
    {
        $registrar = $this->app->make(LanguagePackBundledRegistrar::class);

        $syncFor = function (string $scope, string $identifier, ?array $info, string $relativeBase) use ($registrar) {
            $vendor = $this->resolveVendor($identifier, $info);
            $version = (string) ($info['version'] ?? '1.0.0');
            $langDir = $relativeBase.'/'.$identifier.'/lang';
            if (! \Illuminate\Support\Facades\File::isDirectory(base_path($langDir))) {
                $langDir = $relativeBase.'/'.$identifier.'/resources/lang';
            }
            $registrar->syncFromExtension($scope, $identifier, $vendor, $version, $langDir);
        };

        // 모듈
        HookManager::addAction('core.modules.after_install', function ($identifier, $info = null) use ($syncFor) {
            $syncFor('module', (string) $identifier, is_array($info) ? $info : null, 'modules');
        });
        HookManager::addAction('core.modules.after_update', function ($identifier, $result = null, $info = null) use ($syncFor) {
            $syncFor('module', (string) $identifier, is_array($info) ? $info : null, 'modules');
        });
        HookManager::addAction('core.modules.after_uninstall', function ($identifier) use ($registrar) {
            $registrar->cleanupForExtension('module', (string) $identifier);
        });

        // 플러그인
        HookManager::addAction('core.plugins.after_install', function ($identifier, $info = null) use ($syncFor) {
            $syncFor('plugin', (string) $identifier, is_array($info) ? $info : null, 'plugins');
        });
        HookManager::addAction('core.plugins.after_update', function ($identifier, $result = null, $info = null) use ($syncFor) {
            $syncFor('plugin', (string) $identifier, is_array($info) ? $info : null, 'plugins');
        });
        HookManager::addAction('core.plugins.after_uninstall', function ($identifier) use ($registrar) {
            $registrar->cleanupForExtension('plugin', (string) $identifier);
        });

        // 템플릿
        HookManager::addAction('core.templates.after_install', function ($identifier, $info = null) use ($syncFor) {
            $syncFor('template', (string) $identifier, is_array($info) ? $info : null, 'templates');
        });
        HookManager::addAction('core.templates.after_update', function ($templateOrId, $data = null) use ($syncFor) {
            $identifier = is_object($templateOrId) ? ($templateOrId->identifier ?? '') : (string) $templateOrId;
            if ($identifier !== '') {
                $syncFor('template', $identifier, is_array($data) ? $data : null, 'templates');
            }
        });
        HookManager::addAction('core.templates.after_uninstall', function ($identifier) use ($registrar) {
            $registrar->cleanupForExtension('template', (string) $identifier);
        });

        // PO #6: 호스트 확장 비활성화 시 종속 언어팩 cascade 비활성화
        HookManager::addAction('core.modules.after_deactivate', function ($identifier) use ($registrar) {
            $registrar->deactivateForExtension('module', (string) $identifier);
        });
        HookManager::addAction('core.plugins.after_deactivate', function ($identifier) use ($registrar) {
            $registrar->deactivateForExtension('plugin', (string) $identifier);
        });
        HookManager::addAction('core.templates.after_deactivate', function ($identifier) use ($registrar) {
            $registrar->deactivateForExtension('template', (string) $identifier);
        });
    }

    /**
     * 확장 manifest 또는 identifier 로부터 vendor 를 추출합니다.
     *
     * @param  string  $identifier  확장 식별자
     * @param  array<string, mixed>|null  $info  manifest 정보
     * @return string vendor 문자열
     */
    private function resolveVendor(string $identifier, ?array $info): string
    {
        if (is_array($info) && ! empty($info['vendor'])) {
            return (string) $info['vendor'];
        }

        $segments = explode('-', $identifier);

        return $segments[0] ?? 'unknown';
    }

    /**
     * Laravel 의 Translator 바인딩을 LanguagePackTranslator 로 교체합니다.
     *
     * Illuminate\Translation\TranslationServiceProvider 가 'translator' 와
     * 'translation.loader' 를 등록하는 시점 이후에 본 메서드가 호출되어야 하므로,
     * 부트스트랩 순서상 본 ServiceProvider 는 TranslationServiceProvider 다음에 등록됩니다.
     *
     * @return void
     */
    private function registerTranslatorOverride(): void
    {
        $this->app->extend('translator', function ($translator, Application $app) {
            $loader = $app->make('translation.loader');
            $locale = $app->getLocale();

            $decorated = new LanguagePackTranslator($loader, $locale);
            $decorated->setFallback($app['config']->get('app.fallback_locale'));

            return $decorated;
        });
    }

    /**
     * 활성 언어팩 1건을 Translator 에 등록합니다.
     *
     * @param  LanguagePack  $pack  활성 언어팩
     * @return void
     */
    private function registerActivePack(LanguagePack $pack): void
    {
        $directory = $pack->resolveDirectory().DIRECTORY_SEPARATOR.'backend';
        if (! File::isDirectory($directory)) {
            return;
        }

        if ($pack->scope === LanguagePackScope::Core->value) {
            $translator = $this->app->make('translator');
            if ($translator instanceof LanguagePackTranslator) {
                $localeDir = $directory.DIRECTORY_SEPARATOR.$pack->locale;
                if (File::isDirectory($localeDir)) {
                    $translator->addCoreFallbackPath($pack->locale, $localeDir);
                } else {
                    // backend 직속에 PHP 배열을 두는 평탄형 구조도 지원
                    $translator->addCoreFallbackPath($pack->locale, $directory);
                }
            }

            return;
        }

        // module/plugin/template 은 네임스페이스 기반 등록.
        //
        // Laravel FileLoader::addNamespace 는 단일 hint 만 보유하며 덮어쓰는 구조이므로,
        // loadTranslationsFrom() 으로 직접 등록하면 모듈 자체 src/lang 의 ko/en 등록을
        // 덮어써 ko 가 raw key 로 떨어지는 회귀가 발생한다. 따라서 LanguagePackTranslator
        // 의 namespace fallback 메커니즘에 등록해 표준 hint 를 유지한 채 ja 등 추가
        // locale 만 보완한다.
        if (! empty($pack->target_identifier)) {
            $translator = $this->app->make('translator');
            if ($translator instanceof LanguagePackTranslator) {
                $localeDir = $directory.DIRECTORY_SEPARATOR.$pack->locale;
                $fallbackDir = File::isDirectory($localeDir) ? $localeDir : $directory;
                $translator->addNamespaceFallbackPath(
                    namespace: (string) $pack->target_identifier,
                    locale: $pack->locale,
                    path: $fallbackDir,
                );
            }
        }
    }

    /**
     * config('app.supported_locales') / locale_names / translatable_locales 를 갱신합니다.
     *
     * @param  LanguagePackRegistry  $registry  레지스트리
     * @return void
     */
    private function refreshSupportedLocales(LanguagePackRegistry $registry): void
    {
        $activeLocales = $registry->getActiveCoreLocales();

        // translatable_locales 도 함께 갱신 — 활성 코어 언어팩의 locale 이 데이터 입력
        // 화이트리스트(LocaleRequiredTranslatable / TranslatableField Rule)에 포함되지 않으면
        // 모듈/플러그인의 다국어 폼이 ja 등을 거부하는 회귀가 발생한다.
        config([
            'app.supported_locales' => $activeLocales,
            'app.locale_names' => $registry->getLocaleNames(),
            'app.translatable_locales' => $activeLocales,
        ]);
    }

    /**
     * Registry 사용 가능 여부를 확인합니다.
     *
     * 설치 완료 플래그(config('app.installer_completed'))가 true 인 환경에서는 hasTable 호출을 생략하여
     * 부팅 비용을 줄입니다. 마이그레이션 명령 실행 중이거나 .env 가 부재하면 안전하게 스킵합니다.
     *
     * @return bool 준비 여부
     */
    private function isRegistryReady(): bool
    {
        if (! file_exists(base_path('.env'))) {
            return false;
        }

        if ($this->app->runningInConsole()
            && in_array($_SERVER['argv'][1] ?? null, ['migrate', 'migrate:fresh', 'migrate:rollback', 'migrate:reset', 'db:wipe'], true)) {
            return false;
        }

        if (config('app.installer_completed') === true) {
            return true;
        }

        try {
            return Schema::hasTable('language_packs');
        } catch (QueryException $e) {
            return false;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * 의존하는 다른 ServiceProvider 가 먼저 등록되어야 함을 명시합니다.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            LanguagePackRepositoryInterface::class,
            LanguagePackRegistry::class,
        ];
    }

    /**
     * Laravel 기본 TranslationServiceProvider 클래스 참조 (참고용).
     *
     * @return string
     */
    public static function dependsOn(): string
    {
        return TranslationServiceProvider::class;
    }
}

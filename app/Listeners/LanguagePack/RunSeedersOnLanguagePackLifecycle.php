<?php

namespace App\Listeners\LanguagePack;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Enums\LanguagePackScope;
use App\Models\LanguagePack;
use App\Providers\LanguagePackServiceProvider;
use App\Services\LanguagePack\LanguagePackRegistry;
use App\Services\LanguagePack\LanguagePackSeedInjector;
use Database\Seeders\IdentityMessageDefinitionSeeder;
use Database\Seeders\IdentityPolicySeeder;
use Database\Seeders\NotificationDefinitionSeeder;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 언어팩 활성/비활성 라이프사이클에서 entity 시더를 자동 재실행합니다.
 *
 * scope 별 라우팅:
 *   - core 언어팩 → 코어 entity 시더 + 모든 활성 모듈/플러그인 시더 재실행
 *   - module 언어팩 → 해당 target 모듈만 재실행
 *   - plugin 언어팩 → 해당 target 플러그인만 재실행
 *
 * 동작 보장:
 *   - 시더 재실행 전 LanguagePackRegistry 캐시 무효화 + supported_locales 갱신
 *   - 신규 활성 언어팩의 seed/{entity}.json 필터를 동적으로 추가 등록 (boot 시점에만 등록되던 회귀 보완)
 *   - 시더는 GenericEntitySyncHelper 의 sync + cleanupStale 패턴이라 idempotent — user_overrides 보존
 *   - dot-path sub-key 단위 보존: 사용자가 ko 만 수정한 row 는 ko 보존, 신규 locale (ja) 자동 추가
 *
 * @since 7.0.0-beta.4
 */
// audit:allow listener-must-implement-hooklistenerinterface reason: LanguagePackServiceProvider 가 HookManager::addAction 으로 직접 등록하는 명시 등록 패턴
class RunSeedersOnLanguagePackLifecycle
{
    /** @var array<int, class-string> 코어 entity 시더 — translation 필터를 사용하는 시더만 등록 */
    private const CORE_ENTITY_SEEDERS = [
        NotificationDefinitionSeeder::class,
        IdentityMessageDefinitionSeeder::class,
        IdentityPolicySeeder::class,
    ];

    /**
     * @param  Application  $app  Laravel application instance (Artisan call 용)
     * @param  ModuleRepositoryInterface  $moduleRepository  활성 모듈 식별자 조회용 Repository
     * @param  PluginRepositoryInterface  $pluginRepository  활성 플러그인 식별자 조회용 Repository
     */
    public function __construct(
        private Application $app,
        private ModuleRepositoryInterface $moduleRepository,
        private PluginRepositoryInterface $pluginRepository,
    ) {}

    /**
     * 언어팩 활성화 시 호출됩니다.
     *
     * @param  LanguagePack  $pack  활성화된 언어팩
     */
    public function handleActivated(LanguagePack $pack): void
    {
        $this->reseedForScope($pack);
    }

    /**
     * 언어팩 비활성화 시에도 시더 재실행하여 비활성 locale 키가 다른 활성 팩 기준으로 정리되도록 합니다.
     *
     * 정책: DB 데이터는 보존하되, 활성 locale 변경 시점에 시더가 다시 한 번 활성 locale 머지를
     * 수행하여 일관된 상태를 유지합니다.
     *
     * @param  LanguagePack  $pack  비활성화된 언어팩
     */
    public function handleDeactivated(LanguagePack $pack): void
    {
        $this->reseedForScope($pack);
    }

    /**
     * 언어팩의 scope 에 따라 적절한 시더를 재실행합니다.
     */
    private function reseedForScope(LanguagePack $pack): void
    {
        try {
            // 1. 캐시 무효화 + supported_locales 갱신 (이미 LanguagePackService 가 처리하지만 안전망)
            $this->refreshRegistry();

            // 2. 신규 활성 언어팩의 seed/{entity}.json 필터를 동적 등록 (boot 시점에 미등록된 신규 팩 보완)
            $this->refreshExtensionSeedFilters();

            // 3. scope 별 시더 재실행
            match ($pack->scope) {
                LanguagePackScope::Core->value => $this->reseedCoreScope(),
                LanguagePackScope::Module->value => $this->reseedExtensionTarget('module', $pack->target_identifier),
                LanguagePackScope::Plugin->value => $this->reseedExtensionTarget('plugin', $pack->target_identifier),
                default => null,
            };
        } catch (Throwable $e) {
            // 시더 실패가 언어팩 활성화 트랜잭션을 깨뜨리지 않도록 로그만 남김
            Log::error('[language-pack] 시더 재실행 실패', [
                'pack' => $pack->identifier,
                'scope' => $pack->scope,
                'target' => $pack->target_identifier,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * LanguagePackRegistry 의 인스턴스 캐시를 무효화하고 supported_locales 를 갱신합니다.
     */
    private function refreshRegistry(): void
    {
        // Singleton 캐시된 registry/injector 가 stale 활성 팩 목록을 들고 있을 수 있으므로 forget
        $this->app->forgetInstance(LanguagePackRegistry::class);
        $this->app->forgetInstance(LanguagePackSeedInjector::class);

        $registry = $this->app->make(LanguagePackRegistry::class);
        config([
            'app.supported_locales' => $registry->getActiveCoreLocales(),
            'app.locale_names' => $registry->getLocaleNames(),
            'app.translatable_locales' => $registry->getActiveCoreLocales(),
        ]);
    }

    /**
     * 활성 모듈/플러그인 언어팩의 seed/*.json 필터를 동적으로 재등록합니다.
     *
     * boot 시점 등록된 generic filter 가 활성 팩 기준으로 결정되므로,
     * 활성화 직후의 신규 팩은 자체 entity 시드 필터가 누락된 상태. 본 호출로 보완.
     *
     * 멱등성: 동일 키에 클로저를 추가 등록해도 LanguagePackSeedInjector 는 활성 팩에서
     * 한 번만 sub-key 머지하므로 결과 동일.
     */
    private function refreshExtensionSeedFilters(): void
    {
        /** @var LanguagePackServiceProvider|null $provider */
        $provider = $this->app->getProvider(LanguagePackServiceProvider::class);
        if ($provider === null) {
            return;
        }
        $injector = $this->app->make(LanguagePackSeedInjector::class);
        $provider->registerExtensionSeedFilters($injector);
    }

    /**
     * 코어 entity 시더 + 모든 활성 모듈/플러그인 시더를 재실행합니다.
     */
    private function reseedCoreScope(): void
    {
        $this->runCoreEntitySeeders();
        $this->runAllActiveModuleSeeders();
        $this->runAllActivePluginSeeders();
    }

    /**
     * 코어 entity 시더 (translation 필터를 사용하는 것) 만 재실행.
     */
    private function runCoreEntitySeeders(): void
    {
        foreach (self::CORE_ENTITY_SEEDERS as $seederClass) {
            try {
                $seeder = $this->app->make($seederClass);
                if (method_exists($seeder, 'run')) {
                    $seeder->run();
                }
            } catch (Throwable $e) {
                Log::warning("[language-pack] 코어 시더 실행 실패: {$seederClass}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 모든 활성 모듈의 시더를 재실행합니다 (module:seed 명령).
     */
    private function runAllActiveModuleSeeders(): void
    {
        foreach ($this->moduleRepository->getActiveModuleIdentifiers() as $identifier) {
            $this->runModuleSeeder($identifier);
        }
    }

    /**
     * 모든 활성 플러그인의 시더를 재실행합니다 (plugin:seed 명령).
     */
    private function runAllActivePluginSeeders(): void
    {
        foreach ($this->pluginRepository->getActivePluginIdentifiers() as $identifier) {
            $this->runPluginSeeder($identifier);
        }
    }

    /**
     * 단일 확장의 시더를 재실행합니다.
     */
    private function reseedExtensionTarget(string $type, ?string $targetIdentifier): void
    {
        if ($targetIdentifier === null || $targetIdentifier === '') {
            return;
        }

        if ($type === 'module') {
            $this->runModuleSeeder($targetIdentifier);
        } elseif ($type === 'plugin') {
            $this->runPluginSeeder($targetIdentifier);
        }
    }

    /**
     * 모듈 시더를 안전하게 호출합니다.
     */
    private function runModuleSeeder(string $identifier): void
    {
        try {
            Artisan::call('module:seed', [
                'identifier' => $identifier,
                '--force' => true,
            ]);
        } catch (Throwable $e) {
            Log::warning("[language-pack] module:seed 실패: {$identifier}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 플러그인 시더를 안전하게 호출합니다.
     */
    private function runPluginSeeder(string $identifier): void
    {
        try {
            Artisan::call('plugin:seed', [
                'identifier' => $identifier,
                '--force' => true,
            ]);
        } catch (Throwable $e) {
            Log::warning("[language-pack] plugin:seed 실패: {$identifier}", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}

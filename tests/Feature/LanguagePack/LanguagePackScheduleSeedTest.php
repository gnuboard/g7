<?php

namespace Tests\Feature\LanguagePack;

use App\Contracts\Repositories\LanguagePackTranslationRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Extension\HookManager;
use App\Listeners\LanguagePack\RunSeedersOnLanguagePackLifecycle;
use App\Listeners\LanguagePack\SyncDatabaseTranslations;
use App\Models\LanguagePack;
use App\Models\Schedule;
use App\Providers\LanguagePackServiceProvider;
use App\Services\LanguagePack\LanguagePackRegistry;
use App\Services\LanguagePack\LanguagePackSeedInjector;
use Database\Seeders\CoreScheduleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use ReflectionClassConstant;
use Tests\TestCase;

/**
 * 스케줄 label·description 의 언어팩 seed 경로 테스트 (7.2.0).
 *
 * 코어 팩은 시딩 필터(core.schedules.config)·팩 켜기 DB 주입·끄기 제거 세 길로, 확장 팩은
 * seed.{id}.schedules.translations(matchKey=name)·DB 주입으로 들어간다. 직접 등록 행은 어느 팩도 건드리지 않는다.
 *
 * 조합은 메서드마다 @scenario 로 단다(매니페스트 schedule-language-pack-seed.yaml).
 */
class LanguagePackScheduleSeedTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> 테스트가 만든 팩 디렉터리 */
    private array $packDirs = [];

    /**
     * 만든 팩 디렉터리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->packDirs as $dir) {
            File::deleteDirectory($dir);
        }
        parent::tearDown();
    }

    /**
     * seed/schedules.json 을 가진 활성 ja 팩을 만듭니다.
     *
     * @param  string  $identifier  팩 식별자 (디렉터리명)
     * @param  LanguagePackScope  $scope  팩 범위
     * @param  string|null  $target  대상 확장 식별자 (코어는 null)
     * @param  array<string, array<string, string>>  $seed  스케줄 name 별 번역 맵 (label·description)
     * @return LanguagePack 생성된 팩
     */
    private function makePack(string $identifier, LanguagePackScope $scope, ?string $target, array $seed): LanguagePack
    {
        $dir = base_path('lang-packs/'.$identifier);
        File::ensureDirectoryExists($dir.'/seed');
        File::put($dir.'/seed/schedules.json', json_encode($seed, JSON_UNESCAPED_UNICODE));
        $this->packDirs[] = $dir;

        $pack = LanguagePack::query()->create([
            'identifier' => $identifier,
            'vendor' => 'test',
            'scope' => $scope->value,
            'target_identifier' => $target,
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Active->value,
            'is_protected' => false,
            'manifest' => [],
            'source_type' => 'zip',
        ]);

        $this->app->forgetInstance(LanguagePackRegistry::class);
        $this->app->forgetInstance(LanguagePackSeedInjector::class);

        return $pack;
    }

    /**
     * 시딩 필터 — 활성 코어 ja 팩의 seed 가 카탈로그 label·description 에 ja 를 더한다.
     *
     * @scenario locale_pack=core_ja_active, owner=core
     *
     * @effects core_pack_injects_catalog_labels
     *
     * @return void 반환값 없음
     */
    public function test_core_seed_filter_injects_catalog(): void
    {
        $this->makePack('test-core-ja-sched', LanguagePackScope::Core, null, [
            'sanctum-prune' => ['label' => 'トークン整理', 'description' => '期限切れトークンを削除'],
            'not-in-catalog' => ['label' => '無視'],
        ]);

        $catalog = $this->app->make(LanguagePackSeedInjector::class)->injectCoreSchedules(config('core.schedules'));

        $this->assertSame('トークン整理', $catalog['sanctum-prune']['label']['ja']);
        $this->assertSame('期限切れトークンを削除', $catalog['sanctum-prune']['description']['ja']);
        $this->assertSame(config('core.schedules.sanctum-prune.label.ko'), $catalog['sanctum-prune']['label']['ko']);
        $this->assertArrayNotHasKey('not-in-catalog', $catalog);
    }

    /**
     * 팩 켜기 — 코어 소유 행에만 ja 를 넣고(직접 등록·모듈 행 제외), 끄기 — 넣은 ja 를 걷되 운영자 수정은 남긴다.
     *
     * @scenario locale_pack=core_ja_active, owner=core
     *
     * @effects pack_activation_injects_core_rows_only, pack_deactivation_strips_injected_locale
     *
     * @return void 반환값 없음
     */
    public function test_core_pack_activation_and_deactivation(): void
    {
        // @scenario locale_pack=core_ja_active, owner=module
        // @scenario locale_pack=core_ja_active, owner=custom
        // @scenario locale_pack=core_ja_deactivated, owner=core
        // @scenario locale_pack=core_ja_deactivated, owner=module
        // @scenario locale_pack=core_ja_deactivated, owner=custom
        $core = Schedule::factory()->ownedBy(ExtensionOwnerType::Core, 'core')->create(['name' => 'probe-core', 'label' => ['ko' => '코어']]);
        $kept = Schedule::factory()->ownedBy(ExtensionOwnerType::Core, 'core')->create([
            'name' => 'probe-kept', 'label' => ['ko' => '보존', 'ja' => '運営者'],
        ]);
        Schedule::whereKey($kept->id)->toBase()->update(['user_overrides' => json_encode(['label.ja'])]);
        $custom = Schedule::factory()->create(['name' => 'probe-core', 'label' => ['ko' => '직접']]);
        $module = Schedule::factory()->ownedBy(ExtensionOwnerType::Module, 'vendor-m')->create(['name' => 'probe-core', 'label' => ['ko' => '모듈']]);

        $pack = $this->makePack('test-core-ja-sched', LanguagePackScope::Core, null, [
            'probe-core' => ['label' => 'コア', 'description' => '説明'],
            'probe-kept' => ['label' => 'パック'],
        ]);

        $this->app->make(SyncDatabaseTranslations::class)->handleActivated($pack);

        $this->assertSame('コア', $core->fresh()->label['ja']);
        $this->assertSame('説明', $core->fresh()->description['ja']);
        $this->assertSame('運営者', $kept->fresh()->label['ja'], '운영자 수정(label.ja)은 팩이 덮지 않는다');
        $this->assertArrayNotHasKey('ja', $custom->fresh()->label);
        $this->assertArrayNotHasKey('ja', $module->fresh()->label);
        $this->assertNull($core->fresh()->user_overrides, '팩 주입은 운영자 수정으로 기록되지 않는다');

        $this->app->make(LanguagePackTranslationRepositoryInterface::class)->stripLocaleFromPack($pack);

        $this->assertArrayNotHasKey('ja', $core->fresh()->label);
        $this->assertSame('運営者', $kept->fresh()->label['ja'], '운영자 수정 로케일은 팩을 꺼도 남는다');
        $this->assertSame(['ko' => '직접'], $custom->fresh()->label, '끄기도 직접 등록 행을 건드리지 않는다');
        $this->assertSame(['ko' => '모듈'], $module->fresh()->label, '코어 팩 끄기는 모듈 행을 건드리지 않는다');
    }

    /**
     * 모듈 팩 — 그 모듈 소유 행에만 넣고, 동기화 필터(seed.{id}.schedules.translations)도 name 으로 매칭한다.
     *
     * @scenario locale_pack=module_ja_active, owner=module
     *
     * @effects extension_pack_injects_by_name
     *
     * @return void 반환값 없음
     */
    public function test_module_pack_injects_by_name(): void
    {
        // @scenario locale_pack=module_ja_active, owner=core
        // @scenario locale_pack=module_ja_active, owner=custom
        $owned = Schedule::factory()->ownedBy(ExtensionOwnerType::Module, 'vendor-m')->create(['name' => 'aggregate', 'label' => ['ko' => '집계']]);
        $otherModule = Schedule::factory()->ownedBy(ExtensionOwnerType::Module, 'vendor-x')->create(['name' => 'aggregate', 'label' => ['ko' => '다른']]);
        $coreSame = Schedule::factory()->ownedBy(ExtensionOwnerType::Core, 'core')->create(['name' => 'aggregate', 'label' => ['ko' => '코어']]);
        $customSame = Schedule::factory()->create(['name' => 'aggregate', 'label' => ['ko' => '직접']]);

        $pack = $this->makePack('test-module-vendor-m-ja', LanguagePackScope::Module, 'vendor-m', [
            'aggregate' => ['label' => '集計'],
        ]);

        $this->app->make(SyncDatabaseTranslations::class)->handleActivated($pack);
        $this->assertSame('集計', $owned->fresh()->label['ja']);
        $this->assertArrayNotHasKey('ja', $otherModule->fresh()->label);
        $this->assertArrayNotHasKey('ja', $coreSame->fresh()->label, '모듈 팩은 같은 이름의 코어 행에 넣지 않는다');
        $this->assertArrayNotHasKey('ja', $customSame->fresh()->label, '모듈 팩은 직접 등록 행에 넣지 않는다');

        $provider = new LanguagePackServiceProvider($this->app);
        $provider->registerExtensionSeedFilters($this->app->make(LanguagePackSeedInjector::class));
        try {
            $filtered = HookManager::applyFilters('seed.vendor-m.schedules.translations', [
                ['name' => 'aggregate', 'label' => ['ko' => '집계'], 'command' => 'vendor-m:aggregate'],
            ]);
        } finally {
            HookManager::clearFilter('seed.vendor-m.schedules.translations');
        }
        $this->assertSame('集計', $filtered[0]['label']['ja']);
    }

    /**
     * 언어팩 켜기/끄기 재실행 대상 코어 시더에 CoreScheduleSeeder 가 들어 있다.
     *
     * @effects language_pack_reseed_includes_schedule_seeder
     *
     * @return void 반환값 없음
     */
    public function test_language_pack_reseed_includes_schedule_seeder(): void
    {
        $seeders = (new ReflectionClassConstant(RunSeedersOnLanguagePackLifecycle::class, 'CORE_ENTITY_SEEDERS'))->getValue();

        $this->assertContains(CoreScheduleSeeder::class, $seeders);
    }
}

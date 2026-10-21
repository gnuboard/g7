<?php

namespace Tests\Feature\Console\Commands;

use App\Enums\LanguagePackDeactivationReason;
use App\Enums\LanguagePackSourceType;
use App\Enums\LanguagePackStatus;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Models\LanguagePack;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `module:activate`·`plugin:activate`·`template:activate` 가 호스트를 끌 때 함께 꺼진 언어팩을 다시 켜는지 검증합니다
 * (#145 F7, D5).
 *
 * 관리자 화면은 「언어팩 다시 켜기」 창으로 묻지만 CLI 는 아무것도 하지 않아, CLI 로 복구한 사이트(템플릿이 꺼져 관리자
 * 화면에 들어갈 수 없던 #137 경로)에 언어팩이 꺼진 채 남았다. CLI 는 기본으로 함께 켜고 `--without-language-packs` 로 생략한다.
 * 매니저는 대역으로 바꿔 활성화 성공만 돌려준다 — 이 테스트의 관심사는 그 뒤의 언어팩 복구다.
 */
class ActivateRestoresLanguagePacksTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 대상 확장의 언어팩을 만듭니다.
     *
     * @param  string  $scope  스코프 (module, plugin, template)
     * @param  string  $target  대상 확장 식별자
     * @param  string  $locale  로케일
     * @param  string|null  $reason  비활성 사유
     * @return LanguagePack 만든 언어팩
     */
    private function pack(string $scope, string $target, string $locale, ?string $reason = LanguagePackDeactivationReason::HostDeactivated->value): LanguagePack
    {
        return LanguagePack::create([
            'identifier' => $target.'-'.$locale,
            'vendor' => 'test',
            'scope' => $scope,
            'target_identifier' => $target,
            'locale' => $locale,
            'locale_name' => strtoupper($locale),
            'locale_native_name' => strtoupper($locale),
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Inactive->value,
            'deactivated_reason' => $reason,
            'deactivated_at' => now(),
            'is_protected' => true,
            'manifest' => [],
            'source_type' => LanguagePackSourceType::Bundled->value,
        ]);
    }

    /**
     * 모듈 활성화만 성공으로 돌려주는 매니저 대역을 넣고 비활성 모듈 기록을 만듭니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @return void 반환값 없음
     */
    private function fakeModule(string $identifier): void
    {
        Module::factory()->create(['identifier' => $identifier, 'status' => 'inactive']);
        $manager = Mockery::mock(ModuleManager::class);
        $manager->shouldReceive('loadModules');
        $manager->shouldReceive('activateModule')->andReturnUsing(function () use ($identifier) {
            Module::where('identifier', $identifier)->update(['status' => 'active']);

            return ['success' => true, 'layouts_registered' => 0];
        });
        $this->app->instance(ModuleManager::class, $manager);
    }

    /**
     * 기본 실행은 연쇄로 꺼진 언어팩을 모두 켜고 몇 개를 켰는지 알린다.
     *
     * @scenario case=module_default
     *
     * @effects cli_activate_restores_host_language_packs
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_module_activate_restores_language_packs(): void
    {
        $this->fakeModule('test-cli-host');
        $packs = [$this->pack('module', 'test-cli-host', 'ko'), $this->pack('module', 'test-cli-host', 'en'), $this->pack('module', 'test-cli-host', 'ja')];
        $manual = $this->pack('module', 'test-cli-host', 'zh', null); // 운영자가 직접 끈 팩은 건드리지 않는다

        $this->artisan('module:activate', ['identifier' => 'test-cli-host'])
            ->expectsOutputToContain(__('modules.commands.activate.language_packs_restored', ['count' => 3, 'list' => 'ko, en, ja']))
            ->assertSuccessful();

        foreach ($packs as $pack) {
            $this->assertSame(LanguagePackStatus::Active->value, $pack->refresh()->status);
            $this->assertNull($pack->deactivated_reason);
        }
        $this->assertSame(LanguagePackStatus::Inactive->value, $manual->refresh()->status);
    }

    /**
     * `--without-language-packs` 는 켜지 않고 남긴 목록만 알리며, 표시도 그대로 둬 관리자 화면에서 다시 물을 수 있다.
     *
     * @scenario case=module_without_option
     *
     * @effects cli_activate_without_language_packs_keeps_marks
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_without_option_keeps_language_packs(): void
    {
        $this->fakeModule('test-cli-host2');
        $pack = $this->pack('module', 'test-cli-host2', 'ko');

        $this->artisan('module:activate', ['identifier' => 'test-cli-host2', '--without-language-packs' => true])
            ->expectsOutputToContain(__('modules.commands.activate.language_packs_kept', ['list' => 'ko']))
            ->assertSuccessful();

        $pack->refresh();
        $this->assertSame(LanguagePackStatus::Inactive->value, $pack->status);
        $this->assertSame(LanguagePackDeactivationReason::HostDeactivated->value, $pack->deactivated_reason);
    }

    /**
     * 플러그인 활성화도 언어팩을 함께 켠다.
     *
     * @scenario case=plugin_default
     *
     * @effects cli_activate_restores_host_language_packs
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_plugin_activate_restores_language_packs(): void
    {
        Plugin::factory()->create(['identifier' => 'test-cli-plugin', 'status' => 'inactive']);
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('loadPlugins');
        $manager->shouldReceive('activatePlugin')->andReturnUsing(function () {
            Plugin::where('identifier', 'test-cli-plugin')->update(['status' => 'active']);

            return ['success' => true, 'layouts_registered' => 0];
        });
        $this->app->instance(PluginManager::class, $manager);
        $pack = $this->pack('plugin', 'test-cli-plugin', 'ko');

        $this->artisan('plugin:activate', ['identifier' => 'test-cli-plugin'])
            ->expectsOutputToContain(__('plugins.commands.activate.language_packs_restored', ['count' => 1, 'list' => 'ko']))
            ->assertSuccessful();

        $this->assertSame(LanguagePackStatus::Active->value, $pack->refresh()->status);
    }

    /**
     * 템플릿 활성화도 언어팩을 함께 켠다 (#137 복구 경로).
     *
     * @scenario case=template_default
     *
     * @effects cli_activate_restores_host_language_packs
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_template_activate_restores_language_packs(): void
    {
        Template::factory()->create(['identifier' => 'test-cli-template', 'type' => 'user', 'status' => 'inactive']);
        $manager = Mockery::mock(TemplateManager::class);
        $manager->shouldReceive('loadTemplates');
        $manager->shouldReceive('ensureLoaded');
        $manager->shouldReceive('activateTemplate')->andReturnUsing(function () {
            Template::where('identifier', 'test-cli-template')->update(['status' => 'active']);

            return ['success' => true];
        });
        $this->app->instance(TemplateManager::class, $manager);
        $pack = $this->pack('template', 'test-cli-template', 'ko');

        $this->artisan('template:activate', ['identifier' => 'test-cli-template'])
            ->expectsOutputToContain(__('templates.commands.activate.language_packs_restored', ['count' => 1, 'list' => 'ko']))
            ->assertSuccessful();

        $this->assertSame(LanguagePackStatus::Active->value, $pack->refresh()->status);
    }

    /**
     * 세 활성화 명령의 `--without-language-packs` 옵션 설명이 다국어 파일 문구다.
     *
     * @scenario case=option_description
     *
     * @effects cli_activate_option_description_is_localized
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_option_description_comes_from_lang(): void
    {
        $commands = Artisan::all();

        foreach (['module:activate' => 'modules', 'plugin:activate' => 'plugins', 'template:activate' => 'templates'] as $name => $prefix) {
            $option = $commands[$name]->getDefinition()->getOption('without-language-packs');
            $expected = __($prefix.'.commands.activate.option_without_language_packs');

            $this->assertNotSame($prefix.'.commands.activate.option_without_language_packs', $expected, '전제 — 다국어 키가 있어야 한다');
            $this->assertSame($expected, $option->getDescription(), "{$name} 옵션 설명");
            $this->assertFalse($option->acceptValue(), "{$name} 옵션은 값 없는 스위치");
        }
    }
}

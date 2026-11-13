<?php

namespace Tests\Feature\LanguagePack;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Extension\Helpers\ExtensionLangTexts;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Support\LifecycleWarnings;
use App\Listeners\LanguagePack\RunSeedersOnLanguagePackLifecycle;
use App\Models\LanguagePack;
use App\Services\LanguagePack\LanguagePackRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

/**
 * 확장이 선언한 회원 항목의 이름이 그 확장의 활성 언어팩으로 번역되는지 검증한다 (7.2.0).
 *
 * 확장(게시판 알림 4칸, 이커머스 통화와 배송 국가, 마케팅 동의 등)은 항목 이름을 자기 언어 파일에서 읽어 선언하고,
 * 선언 동기화가 그 이름을 DB 행에 다국어 JSON 으로 저장한다. 확장이 자기 `src/lang` 만 읽으면 ja 언어팩의
 * `lang-packs/_bundled/*-ja/backend/ja/fields.php` 가 있어도 일본어 화면에서 확장 항목만 한국어로 보였다(실측 C-O4). 팩을 켤 때 선언을 다시
 * 맞추지 않으면 팩을 나중에 설치한 사이트에서도 같은 증상이 남는다.
 *
 * 조합은 메서드마다 @scenario 로 단다(매니페스트 extension-field-language-pack-texts.yaml).
 */
class LanguagePackExtensionFieldTextsTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> 테스트가 만든 디렉터리 */
    private array $dirs = [];

    /**
     * 만든 디렉터리를 지우고 Mockery 기대를 닫습니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            File::deleteDirectory($dir);
        }
        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * 확장 자체 언어 디렉터리를 만듭니다 (ko, en 의 fields.php).
     *
     * @return string 언어 디렉터리 경로
     */
    private function makeOwnLangDirectory(): string
    {
        $dir = storage_path('framework/testing/ext-lang-texts-'.uniqid());
        foreach (['ko' => '댓글 알림', 'en' => 'Comment notification'] as $locale => $name) {
            File::ensureDirectoryExists($dir.'/'.$locale);
            File::put($dir.'/'.$locale.'/fields.php', '<?php return '.var_export(['user' => ['notify_comment' => ['name' => $name]]], true).';');
        }
        $this->dirs[] = $dir;

        return $dir;
    }

    /**
     * 대상 확장의 활성 언어팩을 만듭니다.
     *
     * @param  string  $target  대상 확장 식별자
     * @param  string  $locale  팩 로케일
     * @param  array<string, mixed>  $fields  backend/{locale}/fields.php 내용
     * @param  LanguagePackStatus  $status  팩 상태
     * @return LanguagePack 생성된 팩
     */
    private function makeExtensionPack(string $target, string $locale, array $fields, LanguagePackStatus $status = LanguagePackStatus::Active): LanguagePack
    {
        $identifier = 'test-module-'.$target.'-'.$locale.'-fields';
        $dir = base_path('lang-packs/'.$identifier);
        File::ensureDirectoryExists($dir.'/backend/'.$locale);
        File::put($dir.'/backend/'.$locale.'/fields.php', '<?php return '.var_export($fields, true).';');
        $this->dirs[] = $dir;

        $pack = LanguagePack::query()->create([
            'identifier' => $identifier,
            'vendor' => 'test',
            'scope' => LanguagePackScope::Module->value,
            'target_identifier' => $target,
            'locale' => $locale,
            'locale_name' => $locale,
            'locale_native_name' => $locale,
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => $status->value,
            'is_protected' => false,
            'manifest' => [],
            'source_type' => 'zip',
        ]);

        app(LanguagePackRegistry::class)->invalidate();

        return $pack;
    }

    /**
     * 활성 언어팩이 확장에 없는 언어(ja)를 더하고, 확장 자체 파일이 가진 언어(ko, en)는 팩이 덮지 않는다.
     *
     * @scenario case=active_pack_adds_locale
     *
     * @effects extension_field_name_includes_active_pack_locale
     *
     * @return void 반환값 없음
     */
    public function test_active_pack_adds_missing_locale_without_overriding_own_files(): void
    {
        $own = $this->makeOwnLangDirectory();
        $this->makeExtensionPack('test-ext-fields', 'ja', ['user' => ['notify_comment' => ['name' => 'コメント通知']]]);
        $this->makeExtensionPack('test-ext-fields', 'en', ['user' => ['notify_comment' => ['name' => 'PACK OVERRIDE']]]);

        $texts = ExtensionLangTexts::collect('test-ext-fields', $own, 'fields', 'user.notify_comment.name');

        $this->assertSame('コメント通知', $texts['ja'] ?? null, '활성 ja 팩의 이름이 선언에 실려야 한다');
        $this->assertSame('댓글 알림', $texts['ko']);
        $this->assertSame('Comment notification', $texts['en'], '확장 자체 파일의 언어는 팩이 덮지 않는다');
    }

    /**
     * 꺼진 팩과 다른 확장을 대상으로 한 팩은 읽지 않는다.
     *
     * @scenario case=inactive_or_other_target_ignored
     *
     * @effects inactive_or_foreign_pack_not_read
     *
     * @return void 반환값 없음
     */
    public function test_inactive_pack_and_other_target_are_ignored(): void
    {
        $own = $this->makeOwnLangDirectory();
        $this->makeExtensionPack('test-ext-fields', 'ja', ['user' => ['notify_comment' => ['name' => '無効']]], LanguagePackStatus::Inactive);
        $this->makeExtensionPack('test-other-ext', 'zh', ['user' => ['notify_comment' => ['name' => '评论通知']]]);

        $texts = ExtensionLangTexts::collect('test-ext-fields', $own, 'fields', 'user.notify_comment.name');

        $this->assertEqualsCanonicalizing(['ko', 'en'], array_keys($texts));
    }

    /**
     * 확장 범위 언어팩을 켜고 끄면 그 확장의 항목 선언을 다시 동기화한다 — 시더 명령은 선언을 맞추지 않는다.
     *
     * @scenario case=pack_lifecycle_resyncs_fields
     *
     * @effects pack_toggle_resyncs_extension_field_definitions
     *
     * @return void 반환값 없음
     */
    public function test_pack_toggle_resyncs_target_extension_field_definitions(): void
    {
        Artisan::shouldReceive('call')->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('');
        $modules = Mockery::mock(ModuleRepositoryInterface::class);
        $modules->shouldReceive('getActiveModuleIdentifiers')->andReturn(['acme-board']);
        $plugins = Mockery::mock(PluginRepositoryInterface::class);
        $plugins->shouldReceive('getActivePluginIdentifiers')->andReturn(['acme-consent']);

        $moduleManager = Mockery::mock(ModuleManager::class);
        $moduleManager->shouldReceive('resyncFieldDefinitions')->with('acme-board')->twice()->andReturn(true);
        $pluginManager = Mockery::mock(PluginManager::class);
        $pluginManager->shouldReceive('resyncFieldDefinitions')->with('acme-consent')->once()->andReturn(true);
        $this->app->instance(ModuleManager::class, $moduleManager);
        $this->app->instance(PluginManager::class, $pluginManager);

        $listener = new class($this->app, $modules, $plugins) extends RunSeedersOnLanguagePackLifecycle
        {
            /**
             * 시험 식별자는 활성 디렉터리가 없으므로 파일이 있다고 봅니다.
             *
             * @param  string  $type  modules 또는 plugins
             * @param  string  $identifier  확장 식별자
             * @return bool 항상 true
             */
            protected function hasActiveFiles(string $type, string $identifier): bool
            {
                return true;
            }
        };

        $modulePack = new LanguagePack;
        $modulePack->identifier = 'acme-module-acme-board-ja';
        $modulePack->scope = LanguagePackScope::Module->value;
        $modulePack->target_identifier = 'acme-board';
        $modulePack->locale = 'ja';
        $pluginPack = new LanguagePack;
        $pluginPack->identifier = 'acme-plugin-acme-consent-ja';
        $pluginPack->scope = LanguagePackScope::Plugin->value;
        $pluginPack->target_identifier = 'acme-consent';
        $pluginPack->locale = 'ja';

        $mark = LifecycleWarnings::current()->mark();

        $listener->handleActivated($modulePack);
        $listener->handleDeactivated($modulePack);
        $listener->handleActivated($pluginPack);

        $this->assertSame([], LifecycleWarnings::current()->since($mark), '항목 선언 재동기화는 경고 없이 끝나야 한다');
    }
}

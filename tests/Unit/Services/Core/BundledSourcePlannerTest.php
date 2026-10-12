<?php

namespace Tests\Unit\Services\Core;

use App\Contracts\Repositories\LanguagePackRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Services\Core\BundledSourcePlanner;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BundledSourcePlanner 단위 테스트 (공개 #146)
 *
 * 릴리스 인벤토리 읽기, 배치/미배치 판정(설치·디스크·의존·선택·언어팩·숨김), zip 접두사,
 * 기록 파일 해시를 DB 없이(Repository 목) 검증합니다.
 */
class BundledSourcePlannerTest extends TestCase
{
    private string $root;

    private string $release;

    private string $fakeBase;

    private string $originalBasePath;

    private string $originalStoragePath;

    /** @var array<string, array<int, string>> 유형별 설치 식별자 */
    private array $installed = ['module' => [], 'plugin' => [], 'template' => [], 'language_pack' => []];

    private bool $dbFails = false;

    /**
     * 격리 배포본·base·storage 디렉토리를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('test_bundled_planner_'.uniqid());
        $this->release = $this->root.DIRECTORY_SEPARATOR.'release';
        $this->fakeBase = $this->root.DIRECTORY_SEPARATOR.'base';
        File::ensureDirectoryExists($this->release);
        File::ensureDirectoryExists($this->fakeBase);
        File::ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.'storage');

        $this->originalBasePath = base_path();
        $this->originalStoragePath = storage_path();
        app()->setBasePath($this->fakeBase);
        app()->useStoragePath($this->root.DIRECTORY_SEPARATOR.'storage');
    }

    /**
     * base·storage 경로를 되돌리고 임시 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        app()->setBasePath($this->originalBasePath);
        app()->useStoragePath($this->originalStoragePath);
        if (File::isDirectory($this->root)) {
            File::deleteDirectory($this->root);
        }

        parent::tearDown();
    }

    /**
     * 디렉토리 인벤토리는 디렉토리명과 identifier 가 같은 manifest 만 인정하고 코어 버전·언어팩 대상을 읽습니다.
     *
     * @effects release_inventory_reads_manifests_and_core_version
     *
     * @return void 반환값 없음
     */
    public function test_reads_inventory_from_directory(): void
    {
        $this->writeRelease();
        $this->writeJson($this->release, 'plugins/_bundled/sirsoft-gdpr_old_123/plugin.json', ['identifier' => 'sirsoft-gdpr', 'version' => '0.1.0']);

        $inv = $this->planner()->readInventoryFromDirectory($this->release);

        $this->assertSame('7.1.2', $inv->coreVersion);
        $this->assertTrue($inv->has('plugin:sirsoft-gdpr'));
        $this->assertSame('1.1.0', $inv->get('plugin:sirsoft-gdpr')['version']);
        $this->assertCount(1, $inv->keysForIdentifier('sirsoft-gdpr'), '임시 사본 디렉토리는 인벤토리에 들지 않는다');
        $this->assertSame('plugin', $inv->get('language_pack:g7-plugin-sirsoft-gdpr-ja')['scope']);
        $this->assertSame('sirsoft-gdpr', $inv->get('language_pack:g7-plugin-sirsoft-gdpr-ja')['target_identifier']);
        $this->assertSame(['sirsoft-ecommerce'], $inv->get('plugin:sirsoft-tosspayments')['dependencies']['modules']);
        $this->assertTrue($inv->get('module:gnuboard7-hello_module')['hidden']);
    }

    /**
     * 설치된 번들은 installed, 디스크에만 있는 번들은 on_disk 로 배치되고, 둘 다 아니면 미배치입니다.
     *
     * @scenario install_state=installed,disk_state=present
     *
     * @effects placement_follows_install_and_disk_state
     *
     * @return void 반환값 없음
     */
    public function test_plan_places_installed_and_on_disk_bundles_only(): void
    {
        $this->writeRelease();
        $this->installed['module'] = ['sirsoft-board'];
        File::ensureDirectoryExists($this->fakeBase.'/plugins/_bundled/sirsoft-ckeditor5');

        $planner = $this->planner();
        $plan = $planner->plan($planner->readInventoryFromDirectory($this->release));

        $this->assertSame('installed', $plan->placed['module:sirsoft-board']['reason']);
        $this->assertSame('on_disk', $plan->placed['plugin:sirsoft-ckeditor5']['reason']);
        $this->assertArrayHasKey('plugin:sirsoft-gdpr', $plan->unplaced);
        $this->assertArrayHasKey('template:sirsoft-basic', $plan->unplaced);
        $this->assertContains('plugins/_bundled/sirsoft-gdpr', $plan->skipPaths());
        $this->assertNotContains('modules/_bundled/sirsoft-board', $plan->skipPaths());
    }

    /**
     * 배치 집합의 새 판 manifest 의존성은 직접·추이·템플릿→모듈 모두 의존 사유로 함께 배치됩니다.
     *
     * @scenario dependency=transitive
     *
     * @effects dependency_closure_is_placed_with_required_by
     *
     * @return void 반환값 없음
     */
    public function test_plan_places_dependency_closure_with_required_by(): void
    {
        $this->writeRelease();
        // 추이: tosspayments → ecommerce → (새 판에서) board
        $this->writeJson($this->release, 'modules/_bundled/sirsoft-ecommerce/module.json', [
            'identifier' => 'sirsoft-ecommerce', 'version' => '1.4.0', 'name' => ['ko' => '쇼핑몰'],
            'dependencies' => ['modules' => ['sirsoft-board' => '>=1.0.0'], 'plugins' => []],
        ]);
        $this->installed['plugin'] = ['sirsoft-tosspayments'];
        $this->installed['template'] = ['sirsoft-basic'];

        $planner = $this->planner();
        $plan = $planner->plan($planner->readInventoryFromDirectory($this->release));

        $this->assertSame('dependency', $plan->placed['module:sirsoft-ecommerce']['reason']);
        $this->assertContains('plugin:sirsoft-tosspayments', $plan->placed['module:sirsoft-ecommerce']['required_by']);
        $this->assertContains('template:sirsoft-basic', $plan->placed['module:sirsoft-ecommerce']['required_by']);
        $this->assertSame('dependency', $plan->placed['module:sirsoft-board']['reason']);
        $this->assertContains('module:sirsoft-ecommerce', $plan->placed['module:sirsoft-board']['required_by']);
        $this->assertSame('dependency', $plan->placed['plugin:sirsoft-daum_postcode']['reason']);
    }

    /**
     * 운영자 선택 none/all/식별자는 선택 사유로 배치되고, 고른 항목의 의존은 의존 사유로 함께 배치됩니다.
     *
     * @scenario selection=ids
     *
     * @effects selection_places_chosen_bundles_and_their_dependencies
     *
     * @param  string  $raw  --bundled-sources 값
     * @param  array<int, string>  $placed  배치돼야 하는 키
     * @param  array<int, string>  $unplaced  미배치여야 하는 키
     * @return void 반환값 없음
     */
    #[DataProvider('selectionProvider')]
    public function test_plan_applies_selection(string $raw, array $placed, array $unplaced): void
    {
        $this->writeRelease();

        $planner = $this->planner();
        $inv = $planner->readInventoryFromDirectory($this->release);
        $sel = $planner->resolveSelection($inv, $raw);
        $plan = $planner->plan($inv, $sel['mode'] === 'all' ? 'all' : $sel['keys']);

        foreach ($placed as $key) {
            $this->assertArrayHasKey($key, $plan->placed, "{$raw}: {$key} 배치");
        }
        foreach ($unplaced as $key) {
            $this->assertArrayHasKey($key, $plan->unplaced, "{$raw}: {$key} 미배치");
        }
    }

    /**
     * 선택 값별 기대 배치·미배치 키를 돌려줍니다.
     *
     * @return array<string, array{0: string, 1: array<int, string>, 2: array<int, string>}> 데이터셋
     */
    public static function selectionProvider(): array
    {
        return [
            'none' => ['none', [], ['plugin:sirsoft-gdpr', 'module:sirsoft-ecommerce']],
            'all' => ['all', ['plugin:sirsoft-gdpr', 'module:sirsoft-ecommerce', 'module:gnuboard7-hello_module'], []],
            'ids_with_dependency' => ['sirsoft-tosspayments', ['plugin:sirsoft-tosspayments', 'module:sirsoft-ecommerce'], ['plugin:sirsoft-gdpr']],
            'type_prefixed' => ['plugin:sirsoft-gdpr', ['plugin:sirsoft-gdpr', 'language_pack:g7-plugin-sirsoft-gdpr-ja'], ['module:sirsoft-ecommerce']],
        ];
    }

    /**
     * 선택 해석은 모호한 식별자·배포본에 없는 식별자·알 수 없는 유형을 오류로 거부합니다.
     *
     * @scenario selection=invalid
     *
     * @effects invalid_selection_is_rejected
     *
     * @param  string  $raw  --bundled-sources 값
     * @param  string  $needle  오류 메시지에 들어야 하는 문자열
     * @return void 반환값 없음
     */
    #[DataProvider('invalidSelectionProvider')]
    public function test_resolve_selection_rejects_invalid(string $raw, string $needle): void
    {
        $this->writeRelease();
        // 모호: 같은 식별자가 모듈·플러그인 양쪽에 있음
        $this->writeJson($this->release, 'modules/_bundled/sirsoft-gdpr/module.json', ['identifier' => 'sirsoft-gdpr', 'version' => '1.0.0']);

        $planner = $this->planner();
        $inv = $planner->readInventoryFromDirectory($this->release);

        try {
            $planner->resolveSelection($inv, $raw);
            $this->fail('오류가 나야 한다');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    /**
     * 잘못된 선택 값과 메시지 단서를 돌려줍니다.
     *
     * @return array<string, array{0: string, 1: string}> 데이터셋
     */
    public static function invalidSelectionProvider(): array
    {
        return [
            'ambiguous' => ['sirsoft-gdpr', 'plugin:sirsoft-gdpr'],
            'unknown_id' => ['acme-nothing', 'acme-nothing'],
            'unknown_type' => ['widget:sirsoft-gdpr', 'widget'],
        ];
    }

    /**
     * 빈 값은 null(묻거나 기본 none), 인벤토리 없이 부르면 문법만 검사합니다.
     *
     * @effects invalid_selection_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_resolve_selection_empty_and_syntax_only(): void
    {
        $planner = $this->planner();

        $this->assertNull($planner->resolveSelection(null, ''));
        $this->assertSame('ids', $planner->resolveSelection(null, 'plugin:anything')['mode']);
        $this->expectException(\InvalidArgumentException::class);
        $planner->resolveSelection(null, 'widget:x');
    }

    /**
     * 언어팩 규칙: core 팩은 항상, 확장 팩은 대상이 배치·설치되거나 팩 자신이 설치·디스크에 있을 때만 배치됩니다.
     *
     * @scenario domain=language_pack
     *
     * @effects language_pack_follows_core_target_and_self_state
     *
     * @return void 반환값 없음
     */
    public function test_plan_language_pack_rules(): void
    {
        $this->writeRelease();
        $this->installed['module'] = ['sirsoft-board'];
        $this->installed['language_pack'] = ['g7-plugin-sirsoft-ckeditor5-ja'];
        File::ensureDirectoryExists($this->fakeBase.'/lang-packs/_bundled/g7-template-sirsoft-basic-ja');

        $planner = $this->planner();
        $plan = $planner->plan($planner->readInventoryFromDirectory($this->release));

        $this->assertSame('core', $plan->placed['language_pack:g7-core-ja']['reason']);
        $this->assertSame('target_placed', $plan->placed['language_pack:g7-module-sirsoft-board-ja']['reason']);
        $this->assertSame('installed', $plan->placed['language_pack:g7-plugin-sirsoft-ckeditor5-ja']['reason'], '팩 단독 설치');
        $this->assertSame('on_disk', $plan->placed['language_pack:g7-template-sirsoft-basic-ja']['reason']);
        $this->assertArrayHasKey('language_pack:g7-plugin-sirsoft-gdpr-ja', $plan->unplaced);
        $this->assertContains('lang-packs/_bundled/g7-plugin-sirsoft-gdpr-ja', $plan->skipPaths());
    }

    /**
     * 숨김 샘플은 같은 규칙으로 판정되고 기록에 hidden 플래그를 남깁니다.
     *
     * @scenario hidden=true
     *
     * @effects hidden_bundle_recorded_with_flag
     *
     * @return void 반환값 없음
     */
    public function test_hidden_bundle_is_judged_and_flagged(): void
    {
        $this->writeRelease();

        $planner = $this->planner();
        $plan = $planner->plan($planner->readInventoryFromDirectory($this->release));
        $record = $planner->writeRecord($plan, '7.1.2', 'core:update');

        $this->assertArrayHasKey('module:gnuboard7-hello_module', $plan->unplaced);
        $hello = collect($record['unplaced'])->firstWhere('identifier', 'gnuboard7-hello_module');
        $this->assertTrue($hello['hidden']);
        $this->assertFileExists($planner->recordPath());
    }

    /**
     * zip 인벤토리는 래퍼 폴더 접두사를 config/app.php 위치로 판정하고 평탄한 zip 도 읽습니다.
     *
     * @scenario source_kind=zip
     *
     * @effects zip_inventory_reads_names_without_extracting
     *
     * @param  string  $prefix  zip 내부 접두사
     * @return void 반환값 없음
     */
    #[DataProvider('zipPrefixProvider')]
    public function test_reads_inventory_from_zip(string $prefix): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive 확장이 없는 환경');
        }
        $this->writeRelease();
        $zipPath = $this->root.DIRECTORY_SEPARATOR.'release.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        foreach (File::allFiles($this->release) as $file) {
            $zip->addFile($file->getPathname(), $prefix.str_replace('\\', '/', $file->getRelativePathname()));
        }
        $zip->close();

        $inv = $this->planner()->readInventoryFromZip($zipPath);

        $this->assertNotNull($inv);
        $this->assertSame('7.1.2', $inv->coreVersion);
        $this->assertNull($inv->root);
        $this->assertSame(
            array_keys($this->planner()->readInventoryFromDirectory($this->release)->all()),
            array_keys($inv->all()),
        );
    }

    /**
     * zip 접두사 변종을 돌려줍니다.
     *
     * @return array<string, array{0: string}> 데이터셋
     */
    public static function zipPrefixProvider(): array
    {
        return [
            'wrapper' => ['g7-7.1.2/'],
            'flat' => [''],
        ];
    }

    /**
     * ZipArchive 가 없으면 null 을 돌려줘 호출자가 추출 뒤로 질문을 미룹니다.
     *
     * @effects zip_inventory_reads_names_without_extracting
     *
     * @return void 반환값 없음
     */
    public function test_zip_inventory_returns_null_without_ziparchive(): void
    {
        $planner = new class(...$this->repos()) extends BundledSourcePlanner
        {
            /**
             * ZipArchive 부재를 흉내 냅니다.
             *
             * @return bool 항상 false
             */
            protected function zipArchiveAvailable(): bool
            {
                return false;
            }
        };

        $this->assertNull($planner->readInventoryFromZip(__FILE__));
    }

    /**
     * 기록 해시는 순서와 무관하게 같고, 항목이 바뀌면 달라지며, 손상 기록은 null 입니다.
     *
     * @effects record_hash_is_stable_and_changes_with_items
     *
     * @return void 반환값 없음
     */
    public function test_record_hash_stability_and_corruption(): void
    {
        $a = ['type' => 'plugin', 'identifier' => 'a', 'version' => '1.0.0'];
        $b = ['type' => 'module', 'identifier' => 'b', 'version' => '1.0.0'];
        $this->assertSame(BundledSourcePlanner::hashUnplaced([$a, $b]), BundledSourcePlanner::hashUnplaced([$b, $a]));
        $this->assertNotSame(BundledSourcePlanner::hashUnplaced([$a, $b]), BundledSourcePlanner::hashUnplaced([$a]));

        $this->writeRelease();
        $planner = $this->planner();
        $before = $planner->writeRecord($planner->plan($planner->readInventoryFromDirectory($this->release)), '7.1.2', 'core:update');
        $after = $planner->removeFromRecord(['plugin:sirsoft-gdpr'], 'core:bundled-sources');
        $this->assertNotSame($before['hash'], $after['hash']);
        $this->assertNull(collect($after['unplaced'])->firstWhere('identifier', 'sirsoft-gdpr'));

        File::put($planner->recordPath(), '{broken');
        Log::spy();
        $this->assertNull($planner->readRecord());
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains((string) $message, '손상'));
    }

    /**
     * DB 를 읽을 수 없으면 활성 디렉토리 존재로 설치 여부를 판정합니다.
     *
     * @effects installed_falls_back_to_active_directory
     *
     * @return void 반환값 없음
     */
    public function test_installed_falls_back_to_active_directory_when_db_fails(): void
    {
        $this->writeRelease();
        $this->dbFails = true;
        $this->writeJson($this->fakeBase, 'plugins/sirsoft-gdpr/plugin.json', ['identifier' => 'sirsoft-gdpr']);

        $planner = $this->planner();
        $plan = $planner->plan($planner->readInventoryFromDirectory($this->release));

        $this->assertSame('installed', $plan->placed['plugin:sirsoft-gdpr']['reason']);
    }

    /**
     * 설치된 확장의 의존 대상(활성 manifest + 인벤토리)을 모읍니다.
     *
     * @effects dependency_closure_is_placed_with_required_by
     *
     * @return void 반환값 없음
     */
    public function test_installed_dependency_keys(): void
    {
        $this->writeRelease();
        $this->installed['plugin'] = ['acme-shop'];
        $this->writeJson($this->fakeBase, 'plugins/acme-shop/plugin.json', [
            'identifier' => 'acme-shop', 'dependencies' => ['modules' => ['sirsoft-ecommerce' => '*']],
        ]);

        $planner = $this->planner();
        $keys = $planner->installedDependencyKeys($planner->readInventoryFromDirectory($this->release));

        $this->assertArrayHasKey('module:sirsoft-ecommerce', $keys);
    }

    /**
     * 테스트용 플래너를 만듭니다.
     *
     * @return BundledSourcePlanner 플래너
     */
    private function planner(): BundledSourcePlanner
    {
        return new BundledSourcePlanner(...$this->repos());
    }

    /**
     * 설치 상태를 흉내 내는 Repository 목 4종을 만듭니다.
     *
     * @return array{0: ModuleRepositoryInterface, 1: PluginRepositoryInterface, 2: TemplateRepositoryInterface, 3: LanguagePackRepositoryInterface} 목 목록
     */
    private function repos(): array
    {
        $make = function (string $class, string $type) {
            $mock = Mockery::mock($class);
            if ($this->dbFails) {
                $mock->shouldReceive('getInstalledIdentifiers')->andThrow(new \RuntimeException('no table'));
            } else {
                $mock->shouldReceive('getInstalledIdentifiers')->andReturnUsing(fn () => $this->installed[$type]);
            }

            return $mock;
        };

        $lp = Mockery::mock(LanguagePackRepositoryInterface::class);
        $lp->shouldReceive('allForUpdateCheck')->andReturnUsing(fn () => new EloquentCollection(array_map(
            fn ($id) => (object) ['identifier' => $id],
            $this->installed['language_pack'],
        )));

        return [
            $make(ModuleRepositoryInterface::class, 'module'),
            $make(PluginRepositoryInterface::class, 'plugin'),
            $make(TemplateRepositoryInterface::class, 'template'),
            $lp,
        ];
    }

    /**
     * 공식 배포본을 흉내 낸 픽스처 배포본을 씁니다.
     *
     * @return void 반환값 없음
     */
    private function writeRelease(): void
    {
        File::ensureDirectoryExists($this->release.'/config');
        File::put($this->release.'/config/app.php', "<?php return ['version' => env('APP_VERSION', '7.1.2')];");

        $ext = fn (string $id, array $deps = [], array $extra = []) => array_merge([
            'identifier' => $id, 'version' => '1.1.0', 'name' => ['ko' => $id, 'en' => $id],
            'dependencies' => ['modules' => $deps['modules'] ?? [], 'plugins' => $deps['plugins'] ?? []],
        ], $extra);

        $this->writeJson($this->release, 'modules/_bundled/sirsoft-board/module.json', $ext('sirsoft-board'));
        $this->writeJson($this->release, 'modules/_bundled/sirsoft-ecommerce/module.json', $ext('sirsoft-ecommerce'));
        $this->writeJson($this->release, 'modules/_bundled/gnuboard7-hello_module/module.json', $ext('gnuboard7-hello_module', [], ['hidden' => true]));
        $this->writeJson($this->release, 'plugins/_bundled/sirsoft-gdpr/plugin.json', $ext('sirsoft-gdpr'));
        $this->writeJson($this->release, 'plugins/_bundled/sirsoft-ckeditor5/plugin.json', $ext('sirsoft-ckeditor5'));
        $this->writeJson($this->release, 'plugins/_bundled/sirsoft-daum_postcode/plugin.json', $ext('sirsoft-daum_postcode'));
        $this->writeJson($this->release, 'plugins/_bundled/sirsoft-tosspayments/plugin.json', $ext('sirsoft-tosspayments', ['modules' => ['sirsoft-ecommerce' => '>=1.3.0']]));
        $this->writeJson($this->release, 'templates/_bundled/sirsoft-basic/template.json', $ext('sirsoft-basic', [
            'modules' => ['sirsoft-ecommerce' => '>=1.3.0'], 'plugins' => ['sirsoft-daum_postcode' => '>=1.0.0'],
        ]));

        $pack = fn (string $id, string $scope, ?string $target) => [
            'identifier' => $id, 'version' => '1.1.0', 'scope' => $scope, 'target_identifier' => $target, 'locale' => 'ja',
            'name' => ['ko' => $id],
        ];
        $this->writeJson($this->release, 'lang-packs/_bundled/g7-core-ja/language-pack.json', $pack('g7-core-ja', 'core', null));
        $this->writeJson($this->release, 'lang-packs/_bundled/g7-module-sirsoft-board-ja/language-pack.json', $pack('g7-module-sirsoft-board-ja', 'module', 'sirsoft-board'));
        $this->writeJson($this->release, 'lang-packs/_bundled/g7-plugin-sirsoft-gdpr-ja/language-pack.json', $pack('g7-plugin-sirsoft-gdpr-ja', 'plugin', 'sirsoft-gdpr'));
        $this->writeJson($this->release, 'lang-packs/_bundled/g7-plugin-sirsoft-ckeditor5-ja/language-pack.json', $pack('g7-plugin-sirsoft-ckeditor5-ja', 'plugin', 'sirsoft-ckeditor5-x'));
        $this->writeJson($this->release, 'lang-packs/_bundled/g7-template-sirsoft-basic-ja/language-pack.json', $pack('g7-template-sirsoft-basic-ja', 'template', 'sirsoft-basic'));
    }

    /**
     * 루트 아래 상대경로에 JSON 파일을 씁니다.
     *
     * @param  string  $root  루트 디렉토리
     * @param  string  $relative  슬래시 구분 상대경로
     * @param  array<string, mixed>  $data  JSON 내용
     * @return void 반환값 없음
     */
    private function writeJson(string $root, string $relative, array $data): void
    {
        $file = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        File::ensureDirectoryExists(dirname($file));
        File::put($file, (string) json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}

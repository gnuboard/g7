<?php

namespace Tests\Feature\Console\Commands\Extension;

use App\Enums\ExtensionStatus;
use App\Models\Module;
use App\Models\Template;
use App\Services\Extension\ExtensionInventoryService;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * `extension:list [--type=…] [--json]` — 설치·번들 확장 상태를 쓰기 없이 출력한다 (#888).
 *
 * 스크립트가 `*:install`·`*:activate` 를 쳐 보고 종료 코드로 상태를 추측하지 않도록 둔 읽기 전용 창구다. `--json` 형식은
 * `schema: 1` 로 고정한다. 픽스처는 가짜 식별자(`test-extlist-*`)의 DB 행과 매니페스트 폴더이며 tearDown 에서 지운다.
 */
class ListExtensionsCommandTest extends TestCase
{
    use DatabaseTransactions;

    /** schema 1 의 최상위 키 */
    private const TOP_KEYS = ['schema', 'core_version', 'core_version_on_disk', 'items'];

    /** schema 1 의 항목 키 */
    private const ITEM_KEYS = ['identifier', 'type', 'template_type', 'status', 'version', 'active_dir_version', 'bundled_version', 'update_available', 'vendor_mode', 'layout_modified_count'];

    /** 테스트가 만든 디스크 경로 */
    private array $paths = [];

    /**
     * 픽스처 폴더를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            File::deleteDirectory($path);
        }

        parent::tearDown();
    }

    /**
     * `--json` 출력의 키 구성이 schema 1 그대로다 (스냅샷).
     *
     * @scenario case=json_schema_snapshot
     *
     * @effects extension_list_json_schema_is_fixed
     *
     * @return void 반환값 없음
     */
    public function test_json_schema_is_fixed(): void
    {
        $this->moduleRow('test-extlist-active', ExtensionStatus::Active->value, '1.0.0');

        $json = $this->listJson('module');

        $this->assertSame(self::TOP_KEYS, array_keys($json));
        $this->assertSame(1, $json['schema']);
        $this->assertSame(config('app.version'), $json['core_version']);
        $this->assertNotEmpty($json['items']);
        foreach ($json['items'] as $item) {
            $this->assertSame(self::ITEM_KEYS, array_keys($item), '항목 키가 바뀌면 schema 를 올려야 한다');
            $this->assertSame('module', $item['type']);
        }
    }

    /**
     * 상태 변종 — active·inactive·updating 은 DB 상태 그대로, 번들에만 있는 확장은 `not_installed`, 활성 폴더 판 ≠ DB 판이 드러난다.
     *
     * @scenario case=status_variants
     *
     * @effects extension_list_reports_db_and_manifest_versions
     *
     * @return void 반환값 없음
     */
    public function test_status_variants_and_version_drift(): void
    {
        $this->moduleRow('test-extlist-active', ExtensionStatus::Active->value, '1.0.0', updateAvailable: true);
        $this->moduleRow('test-extlist-inactive', ExtensionStatus::Inactive->value, '1.0.0');
        $this->moduleRow('test-extlist-updating', ExtensionStatus::Updating->value, '1.0.0');
        $this->moduleRow('test-extlist-drift', ExtensionStatus::Active->value, '1.0.0');
        $this->writeManifest(base_path('modules/test-extlist-drift'), 'module.json', 'test-extlist-drift', '1.1.0');
        $this->writeManifest(base_path('modules/_bundled/test-extlist-bundledonly'), 'module.json', 'test-extlist-bundledonly', '2.0.0');

        $items = collect($this->listJson('module')['items'])->keyBy('identifier');

        $this->assertSame('active', $items['test-extlist-active']['status']);
        $this->assertTrue($items['test-extlist-active']['update_available'], 'DB 에 저장된 값을 그대로 싣는다');
        $this->assertSame('inactive', $items['test-extlist-inactive']['status']);
        $this->assertSame('updating', $items['test-extlist-updating']['status']);

        $this->assertSame('1.0.0', $items['test-extlist-drift']['version']);
        $this->assertSame('1.1.0', $items['test-extlist-drift']['active_dir_version'], '활성 폴더 판이 DB 판과 다르면 그대로 보여야 한다');

        $bundledOnly = $items['test-extlist-bundledonly'];
        $this->assertSame('not_installed', $bundledOnly['status']);
        $this->assertNull($bundledOnly['version']);
        $this->assertSame('2.0.0', $bundledOnly['bundled_version']);
        $this->assertNull($bundledOnly['update_available']);
    }

    /**
     * 템플릿은 종류(admin·user)와 운영자가 고친 레이아웃 수를, 그 밖의 종류는 null 을 싣는다.
     *
     * @scenario case=template_fields
     *
     * @effects extension_list_reports_db_and_manifest_versions
     *
     * @return void 반환값 없음
     */
    public function test_template_fields(): void
    {
        Template::create(['identifier' => 'test-extlist-tpl', 'vendor' => 'test', 'name' => ['ko' => 't', 'en' => 't'], 'version' => '1.0.0', 'type' => 'user', 'status' => ExtensionStatus::Inactive->value, 'description' => ['ko' => '', 'en' => '']]);

        $item = collect($this->listJson('template')['items'])->firstWhere('identifier', 'test-extlist-tpl');

        $this->assertNotNull($item);
        $this->assertSame('user', $item['template_type']);
        $this->assertSame(0, $item['layout_modified_count']);
        $this->assertNull($item['vendor_mode']);
    }

    /**
     * 실행해도 DB 쓰기(INSERT·UPDATE·DELETE)와 캐시 쓰기가 0 이다. 표 출력도 같다.
     *
     * @scenario case=writes_nothing
     *
     * @effects extension_list_writes_nothing
     *
     * @return void 반환값 없음
     */
    public function test_writes_nothing(): void
    {
        $this->moduleRow('test-extlist-active', ExtensionStatus::Active->value, '1.0.0');

        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|truncate|alter|create|drop)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $cacheWrites = 0;
        Event::listen(KeyWritten::class, function () use (&$cacheWrites): void {
            $cacheWrites++;
        });

        $this->assertSame(0, Artisan::call('extension:list', ['--json' => true]));
        $this->assertSame(0, Artisan::call('extension:list'));

        $this->assertSame([], $writes, 'extension:list 가 DB 를 썼다');
        $this->assertSame(0, $cacheWrites, 'extension:list 가 캐시를 썼다');
    }

    /**
     * 설치 전(확장 테이블 없음)이면 종료 코드 1 과 「설치 전」 안내 — JSON 이면 `error: not_installed`.
     *
     * @scenario case=not_installed
     *
     * @effects extension_list_fails_before_install
     *
     * @return void 반환값 없음
     */
    public function test_not_installed_exits_one(): void
    {
        $this->mock(ExtensionInventoryService::class, fn (MockInterface $mock) => $mock->shouldReceive('isInstalled')->andReturnFalse());

        $this->assertSame(1, Artisan::call('extension:list', ['--json' => true]));
        $json = json_decode(trim(Artisan::output()), true);
        $this->assertSame(['schema' => 1, 'error' => 'not_installed', 'message' => (string) __('extensions.commands.list.not_installed')], $json);

        $this->assertSame(1, Artisan::call('extension:list'));
        $this->assertStringContainsString((string) __('extensions.commands.list.not_installed'), Artisan::output());
    }

    /**
     * 알 수 없는 종류는 입력 오류(종료 코드 2)다. 설치된 DB 에서는 설치 판정이 참이다.
     *
     * @scenario case=invalid_type
     *
     * @effects extension_list_fails_before_install
     *
     * @return void 반환값 없음
     */
    public function test_invalid_type_exits_two(): void
    {
        $this->assertSame(2, Artisan::call('extension:list', ['--type' => 'theme']));
        $this->assertStringContainsString((string) __('extensions.commands.list.invalid_type', ['type' => 'theme']), Artisan::output());
        $this->assertTrue(app(ExtensionInventoryService::class)->isInstalled());
    }

    /**
     * `--json` 출력을 읽습니다.
     *
     * @param  string  $type  종류
     * @return array<string, mixed> JSON 출력을 해석한 배열
     */
    private function listJson(string $type): array
    {
        $this->assertSame(0, Artisan::call('extension:list', ['--type' => $type, '--json' => true]));
        $json = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($json);

        return $json;
    }

    /**
     * 모듈 DB 행을 만듭니다.
     *
     * @param  string  $identifier  식별자
     * @param  string  $status  상태
     * @param  string  $version  판
     * @param  bool  $updateAvailable  저장된 업데이트 가능 여부
     * @return void 반환값 없음
     */
    private function moduleRow(string $identifier, string $status, string $version, bool $updateAvailable = false): void
    {
        Module::create(['identifier' => $identifier, 'vendor' => 'test', 'name' => ['ko' => $identifier, 'en' => $identifier], 'version' => $version, 'status' => $status, 'update_available' => $updateAvailable, 'description' => ['ko' => '', 'en' => '']]);
    }

    /**
     * 매니페스트만 든 픽스처 폴더를 만듭니다.
     *
     * @param  string  $dir  폴더
     * @param  string  $manifestName  매니페스트 파일명
     * @param  string  $identifier  식별자
     * @param  string  $version  판
     * @return void 반환값 없음
     */
    private function writeManifest(string $dir, string $manifestName, string $identifier, string $version): void
    {
        $this->paths[] = $dir;
        File::ensureDirectoryExists($dir);
        File::put($dir.'/'.$manifestName, (string) json_encode(['identifier' => $identifier, 'vendor' => 'test', 'name' => ['ko' => $identifier, 'en' => $identifier], 'version' => $version]));
        clearstatcache();
    }
}

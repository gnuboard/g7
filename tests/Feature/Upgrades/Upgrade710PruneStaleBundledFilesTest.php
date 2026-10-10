<?php

namespace Tests\Feature\Upgrades;

use App\Extension\Helpers\ExtensionPendingHelper;
use App\Extension\UpgradeContext;
use App\Upgrades\Data\V7_1_0\Migrations\PruneStaleBundledFiles;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;
use Tests\TestCase;

/**
 * 7.1.0 업그레이드 — 7.0.x 부모가 번들 디렉토리에 남긴 고아 파일 정리 (R1)
 *
 * 7.0.x 의 코어 업데이트(부모 프로세스가 7.0.x 코드로 돈다)는 번들 확장 디렉토리 안의 지워진 파일을 prune 모드에서만
 * 지운다. 그래서 7.1.0 에서 지운 `_bundled` 파일이 그대로 남고, 뒤이은 확장 갱신이 `_bundled` 를 활성 디렉토리로
 * 통째로 복사해 옛 조각(7.1.0 에서 지운 「resources/extensions/admin_order_list_test_badge.json」 등)이 살아 있는 조각으로 다시
 * 등록됐다 — 관리자 주문 목록 `order_datagrid.columns` 가 7.0.11 판 11열로 덮였다(실서버 실측).
 *
 * 이 테스트가 잠그는 것:
 *  - 동결 목록에 있고 본문이 7.0.x 판과 같은 `_bundled` 파일만 지운다 → 그 뒤 확장 갱신(copyToActive)이 만든
 *    활성 디렉토리에는 HEAD 조각만 남는다.
 *  - 본문이 바뀐 파일·목록 밖 파일·사용자 커스텀 확장·활성 디렉토리는 건드리지 않는다.
 *  - 비게 된 하위 디렉토리는 정리하되 확장 루트는 남기고, 두 번 돌려도 같다.
 *  - 동결 목록의 경로는 모두 번들 확장 내부이고 현재 번들에 실재하지 않는다.
 *
 * 시나리오 SSoT: tests/scenarios/upgrade-710-prune-stale-bundled-files.yaml
 */
#[Group('core')]
#[Group('upgrade')]
class Upgrade710PruneStaleBundledFilesTest extends TestCase
{
    /**
     * 임시 설치 루트 (base_path 를 흉내 낸다).
     */
    private string $root;

    /**
     * 업그레이드 데이터 파일을 불러오고 임시 설치 루트를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // DataMigration 은 AbstractUpgradeStep 이 동적으로 require 하므로 autoload 대상이 아니다.
        require_once base_path('upgrades/data/7.1.0/migrations/09_PruneStaleBundledFiles.php');

        $this->root = storage_path('framework/testing/prune-stale-bundled-'.uniqid());
        File::ensureDirectoryExists($this->root);
    }

    /**
     * 임시 설치 루트를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * 7.0.11 → 7.1.0 실측 재현: 부모가 남긴 옛 조각이 `_bundled` 에서 지워지고, 이어지는 확장 갱신 뒤 활성
     * 디렉토리에는 HEAD 조각만 남는다. 코어 스텝은 아직 갱신 전인 활성 디렉토리를 건드리지 않는다.
     *
     * @scenario case=payment_fragments_7_0_11
     *
     * @effects stale_bundled_fragment_removed, update_copies_only_head_fragments, active_dir_untouched_by_core_step
     *
     * @return void 반환값 없음
     */
    public function test_stale_payment_fragments_are_removed_so_update_registers_only_head_fragments(): void
    {
        $kginicis = 'plugins/_bundled/sirsoft-pay_kginicis/resources/extensions';
        $nicepay = 'plugins/_bundled/sirsoft-pay_nicepayments/resources/extensions';
        $headKginicis = $this->seedHeadFragments('sirsoft-pay_kginicis');
        $headNicepay = $this->seedHeadFragments('sirsoft-pay_nicepayments');

        // 7.0.x 부모의 증분 적용이 남긴 상태 — 새 판 위에 7.0.11 판 옛 조각이 그대로 있다.
        $this->writeFile("{$kginicis}/admin_order_list_test_badge.json", $this->fixture('kginicis_admin_order_list_test_badge.7.0.11.json'));
        $this->writeFile("{$nicepay}/checkout_easy_pay.json", $this->fixture('nicepayments_checkout_easy_pay.7.0.11.json'));

        // 아직 갱신 전인 활성 디렉토리 (7.0.11 판이 쓰는 파일)
        $active = 'plugins/sirsoft-pay_kginicis/resources/extensions/admin_order_list_test_badge.json';
        $this->writeFile($active, $this->fixture('kginicis_admin_order_list_test_badge.7.0.11.json'));

        // 하한 — 재현 전제: 정리 전에는 갱신이 옛 조각까지 활성 디렉토리로 옮긴다.
        $this->assertContains('admin_order_list_test_badge', $this->fragmentKeysAfterUpdate('sirsoft-pay_kginicis', copy: false));

        $this->runPrune();

        $this->assertFileDoesNotExist($this->path("{$kginicis}/admin_order_list_test_badge.json"), '7.0.11 판 옛 조각이 _bundled 에 남았다');
        $this->assertFileDoesNotExist($this->path("{$nicepay}/checkout_easy_pay.json"), '7.1.0 에서 지운 간편결제 조각이 _bundled 에 남았다');
        $this->assertFileExists($this->path($active), '코어 스텝이 아직 갱신 전인 활성 디렉토리 파일을 지웠다');

        // 확장 갱신 = _bundled → 활성 디렉토리 원자 교체. 등록은 활성 디렉토리의 `resources/extensions/*.json` glob 이다.
        $this->assertSame($headKginicis, $this->fragmentKeysAfterUpdate('sirsoft-pay_kginicis'));
        $this->assertSame($headNicepay, $this->fragmentKeysAfterUpdate('sirsoft-pay_nicepayments'));
        $this->assertFileDoesNotExist($this->path($active), '갱신 뒤 활성 디렉토리에 옛 조각이 남았다');
    }

    /**
     * 본문이 7.0.x 판과 다른 파일·목록 밖 파일·사용자 커스텀 확장은 지우지 않고, 바뀐 파일은 경고로 남긴다.
     *
     * @scenario case=guarded_files
     *
     * @effects modified_file_kept_with_warning, unlisted_and_custom_files_kept
     *
     * @return void 반환값 없음
     */
    public function test_modified_unlisted_and_custom_files_are_kept(): void
    {
        $listed = 'plugins/_bundled/sirsoft-sample/resources/extensions/old.json';
        $modified = 'plugins/_bundled/sirsoft-sample/resources/extensions/edited.json';
        $unlisted = 'plugins/_bundled/sirsoft-sample/resources/extensions/live.json';
        $custom = 'plugins/_bundled/acme-custom/resources/extensions/old.json';

        $this->writeFile($listed, "{\"v\":1}\n");
        $this->writeFile($modified, "{\"v\":1,\"edited\":true}\n");
        $this->writeFile($unlisted, "{\"v\":1}\n");
        $this->writeFile($custom, "{\"v\":1}\n");

        $logger = $this->recordingLogger();
        Log::shouldReceive('channel')->with('upgrade')->andReturn($logger);

        $this->runPrune([
            $listed => [$this->hashOf("{\"v\":1}\n")],
            $modified => [$this->hashOf("{\"v\":1}\n")],
        ]);

        $this->assertFileDoesNotExist($this->path($listed));
        $this->assertFileExists($this->path($modified), '본문이 바뀐 파일을 지웠다 — 해시 가드가 없다');
        $this->assertFileExists($this->path($unlisted), '목록 밖 파일을 지웠다');
        $this->assertFileExists($this->path($custom), '사용자 커스텀 확장 파일을 지웠다');

        $warnings = array_filter($logger->records, static fn (array $r): bool => $r[0] === 'warning' && str_contains($r[1].json_encode($r[2]), 'edited.json'));
        $this->assertNotEmpty($warnings, '본문이 바뀌어 남긴 파일을 경고로 알리지 않았다');
    }

    /**
     * CRLF 로 체크아웃된 파일도 7.0.x 판으로 인식한다(해시는 LF 정규화 기준).
     *
     * @scenario case=line_endings
     *
     * @effects crlf_checkout_recognized
     *
     * @return void 반환값 없음
     */
    public function test_crlf_checkout_is_recognized_as_released_content(): void
    {
        $listed = 'templates/_bundled/sirsoft-sample/layouts/old.json';
        $this->writeFile($listed, "{\r\n    \"v\": 1\r\n}\r\n");

        $this->runPrune([$listed => [$this->hashOf("{\n    \"v\": 1\n}\n")]]);

        $this->assertFileDoesNotExist($this->path($listed));
    }

    /**
     * 비게 된 하위 디렉토리는 지우되 확장 루트와 내용이 남은 디렉토리는 둔다. 두 번째 실행은 아무것도 바꾸지 않는다.
     *
     * @scenario case=empty_dirs_and_rerun
     *
     * @effects emptied_subdirectories_pruned, extension_root_kept, prune_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_emptied_subdirectories_are_pruned_and_rerun_is_noop(): void
    {
        $deep = 'templates/_bundled/sirsoft-sample/layouts/overrides/sirsoft-other/index.json';
        $sibling = 'templates/_bundled/sirsoft-sample/layouts/home.json';
        $this->writeFile($deep, "{}\n");
        $this->writeFile($sibling, "{}\n");

        $manifest = [$deep => [$this->hashOf("{}\n")]];
        $this->runPrune($manifest);

        $this->assertDirectoryDoesNotExist($this->path('templates/_bundled/sirsoft-sample/layouts/overrides'), '비게 된 디렉토리가 남았다');
        $this->assertFileExists($this->path($sibling));
        $this->assertDirectoryExists($this->path('templates/_bundled/sirsoft-sample'));

        $this->runPrune($manifest);

        $this->assertFileExists($this->path($sibling), '재실행이 다른 파일을 건드렸다');
    }

    /**
     * 동결 목록: 모든 경로가 번들 확장 내부이고 현재 번들에 실재하지 않으며, 실측된 결제 3종 옛 조각을 담는다.
     *
     * @scenario case=frozen_manifest
     *
     * @effects manifest_paths_inside_bundled_extensions, manifest_paths_absent_from_current_bundle
     *
     * @return void 반환값 없음
     */
    public function test_frozen_manifest_targets_only_removed_bundled_files(): void
    {
        $manifest = json_decode((string) file_get_contents(base_path('upgrades/data/7.1.0/stale_bundled_files.snapshot.json')), true);
        $files = $manifest['files'] ?? [];

        $this->assertNotEmpty($files);

        foreach ($files as $relative => $hashes) {
            $this->assertMatchesRegularExpression('#^(modules|plugins|templates|lang-packs)/_bundled/[a-z0-9][a-z0-9_-]*-[a-z0-9_-]+/.+#', $relative, "번들 확장 내부 경로가 아니다: {$relative}");
            $this->assertStringNotContainsString('..', $relative);
            $this->assertFileDoesNotExist(base_path($relative), "현재 번들에 있는 파일이 정리 목록에 있다 — 살아 있는 파일을 지운다: {$relative}");
            $this->assertNotEmpty($hashes);
            foreach ($hashes as $hash) {
                $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
            }
        }

        foreach ([
            'plugins/_bundled/sirsoft-pay_kginicis/resources/extensions/admin_order_list_test_badge.json',
            'plugins/_bundled/sirsoft-pay_nhnkcp/resources/extensions/admin_order_list_test_badge.json',
            'plugins/_bundled/sirsoft-pay_nicepayments/resources/extensions/admin_order_list_test_badge.json',
            'plugins/_bundled/sirsoft-pay_nicepayments/resources/extensions/checkout_easy_pay.json',
        ] as $expected) {
            $this->assertArrayHasKey($expected, $files);
        }
    }

    /**
     * 기록만 하는 로거를 만듭니다.
     *
     * @return AbstractLogger 기록 로거 (`records` 속성에 [레벨, 메시지, 문맥] 으로 쌓인다)
     */
    private function recordingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger
        {
            /** @var array<int, array{0: string, 1: string, 2: array<mixed>}> */
            public array $records = [];

            /**
             * 로그 한 건을 기록합니다.
             *
             * @param  mixed  $level  레벨
             * @param  string|\Stringable  $message  메시지
             * @param  array<mixed>  $context  문맥
             * @return void 반환값 없음
             */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };
    }

    /**
     * 정리 마이그레이션을 임시 설치 루트에서 실행합니다.
     *
     * @param  array<string, array<int, string>>|null  $manifest  주입할 목록 (null 이면 동결 목록)
     * @return void 반환값 없음
     */
    private function runPrune(?array $manifest = null): void
    {
        (new PruneStaleBundledFiles($this->root, $manifest))->run(new UpgradeContext('7.0.11', '7.1.0', '7.1.0'));
    }

    /**
     * 저장소의 HEAD 조각 파일을 임시 `_bundled` 로 옮기고 그 조각 키 목록을 돌려줍니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @return array<int, string> 정렬된 조각 키
     */
    private function seedHeadFragments(string $identifier): array
    {
        $from = base_path("plugins/_bundled/{$identifier}/resources/extensions");
        $to = $this->path("plugins/_bundled/{$identifier}/resources/extensions");
        File::copyDirectory($from, $to);
        File::put($this->path("plugins/_bundled/{$identifier}/plugin.json"), (string) file_get_contents(base_path("plugins/_bundled/{$identifier}/plugin.json")));

        return $this->keysOf($from);
    }

    /**
     * `_bundled` → 활성 디렉토리 갱신 뒤 등록 대상이 될 조각 키를 돌려줍니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @param  bool  $copy  false 면 갱신 없이 `_bundled` 의 키를 본다 (갱신이 그대로 옮길 목록)
     * @return array<int, string> 정렬된 조각 키
     */
    private function fragmentKeysAfterUpdate(string $identifier, bool $copy = true): array
    {
        $bundled = $this->path("plugins/_bundled/{$identifier}");

        if (! $copy) {
            return $this->keysOf($bundled.'/resources/extensions');
        }

        $active = $this->path("plugins/{$identifier}");
        ExtensionPendingHelper::copyToActive($bundled, $active);

        return $this->keysOf($active.'/resources/extensions');
    }

    /**
     * 디렉토리의 `*.json` 조각 키(파일 이름)를 정렬해 돌려줍니다 — `AbstractPlugin::getLayoutExtensions()` 와 같은 glob.
     *
     * @param  string  $dir  조각 디렉토리
     * @return array<int, string> 정렬된 조각 키
     */
    private function keysOf(string $dir): array
    {
        $keys = array_map(static fn (string $file): string => basename($file, '.json'), glob($dir.'/*.json') ?: []);
        sort($keys);

        return $keys;
    }

    /**
     * 임시 설치 루트 아래에 파일을 씁니다.
     *
     * @param  string  $relative  설치 루트 기준 상대 경로
     * @param  string  $content  본문
     * @return void 반환값 없음
     */
    private function writeFile(string $relative, string $content): void
    {
        File::ensureDirectoryExists(dirname($this->path($relative)));
        File::put($this->path($relative), $content);
    }

    /**
     * 임시 설치 루트 기준 절대 경로를 돌려줍니다.
     *
     * @param  string  $relative  상대 경로
     * @return string 절대 경로
     */
    private function path(string $relative): string
    {
        return $this->root.'/'.$relative;
    }

    /**
     * 7.0.11 판 픽스처 본문을 읽습니다.
     *
     * @param  string  $name  픽스처 파일 이름
     * @return string 본문
     */
    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/Upgrades/V7_1_0/'.$name));
    }

    /**
     * 동결 목록과 같은 방식(LF 정규화 sha256)으로 본문 해시를 냅니다.
     *
     * @param  string  $content  본문
     * @return string 해시
     */
    private function hashOf(string $content): string
    {
        return hash('sha256', str_replace("\r\n", "\n", $content));
    }
}

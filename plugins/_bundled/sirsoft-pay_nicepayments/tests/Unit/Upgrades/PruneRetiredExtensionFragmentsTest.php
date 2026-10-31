<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use App\Upgrades\Data\Ext\Plugins\SirsoftPayNicepayments\V1_1_0\Migrations\PruneRetiredExtensionFragments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Group;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

/**
 * 걷어낸 조각(`admin_order_list_test_badge`·`checkout_easy_pay`)의 남은 파일·등록 행 정리 (R1).
 *
 * 코어 7.0.x 업데이트가 번들 디렉토리에 남긴 1.0.x 판 조각 파일이 확장 갱신으로 활성 디렉토리에 다시 옮겨져, 관리자
 * 주문 목록 `order_datagrid.columns` 를 1.0.x 판 11열로 통째 덮었다(실측). 이 테스트는 1.0.x(코어 7.0.11) 설치 상태 —
 * 옛 파일과 옛 행 — 를 픽스처로 만들고, 스텝 뒤 옛 파일·행이 없고 1.1.0 조각만 남는지 잠근다.
 */
#[Group('payment')]
#[Group('upgrade')]
class PruneRetiredExtensionFragmentsTest extends PluginTestCase
{
    /**
     * 확장 등록 테이블.
     */
    private const TABLE = 'template_layout_extensions';

    /**
     * 이 플러그인 식별자.
     */
    private const SELF = 'sirsoft-pay_nicepayments';

    /**
     * 걷어낸 조각 키 → 그 조각이 겨냥하던 레이아웃(백필이 채운 옛 키).
     *
     * @var array<string, string>
     */
    private const RETIRED = [
        'admin_order_list_test_badge' => 'admin_ecommerce_order_list',
        'checkout_easy_pay' => 'shop/checkout',
    ];

    /**
     * FK 대상 템플릿 ID.
     */
    private int $templateId;

    /**
     * 임시 플러그인 루트.
     */
    private string $pluginsRoot;

    /**
     * 업그레이드 데이터 마이그레이션을 불러오고 임시 플러그인 루트·템플릿을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // DataMigration 은 AbstractUpgradeStep 이 동적으로 require 하므로 일반 autoload 대상이 아니다.
        require_once base_path('plugins/_bundled/'.self::SELF.'/upgrades/data/1.1.0/migrations/PruneRetiredExtensionFragments.php');

        $this->templateId = $this->ensureTemplate();
        DB::table(self::TABLE)->where('template_id', $this->templateId)->delete();

        $this->pluginsRoot = storage_path('framework/testing/prune-retired-'.self::SELF.'-'.uniqid());
    }

    /**
     * 임시 플러그인 루트를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->pluginsRoot);

        parent::tearDown();
    }

    /**
     * 1.0.x 상태(옛 파일 + 옛 행)에서 스텝 뒤 옛 파일·행이 사라지고 1.1.0 조각 파일·행과 남의 행은 남는다.
     *
     * @scenario state=legacy_1_0_x
     *
     * @effects retired_fragment_files_removed, retired_fragment_rows_soft_deleted, head_fragments_and_other_rows_kept
     *
     * @return void 반환값 없음
     */
    public function test_옛_조각_파일과_행을_지우고_새_조각만_남긴다(): void
    {
        $headKeys = $this->seedHeadFragments();

        foreach (array_keys(self::RETIRED) as $key) {
            $this->writeFragment(self::SELF, $key);
            $this->writeFragment('_bundled/'.self::SELF, $key);
        }

        $retiredRows = [];
        foreach (self::RETIRED as $key => $target) {
            $retiredRows[] = $this->seedRow($target, $key, self::SELF);
            $retiredRows[] = $this->seedRow($target, $target, self::SELF); // 백필 옛 키 행
        }
        $headRow = $this->seedRow('admin_ecommerce_order_list', 'admin_order_list_payment_method_badge', self::SELF);
        $otherPlugin = $this->seedRow('admin_ecommerce_order_list', array_key_first(self::RETIRED), 'sirsoft-other_plugin');

        // 하한 — 재현 전제: 스텝 전에는 등록 glob 이 옛 조각을 잡는다.
        $this->assertContains(array_key_first(self::RETIRED), $this->activeKeys());

        $this->runMigration();

        $this->assertSame($headKeys, $this->activeKeys(), '활성 디렉토리에 1.1.0 조각만 남아야 한다');
        foreach (array_keys(self::RETIRED) as $key) {
            $this->assertFileDoesNotExist($this->fragmentPath('_bundled/'.self::SELF, $key), '번들 디렉토리에 옛 조각이 남았다');
        }
        foreach ($retiredRows as $id) {
            $this->assertNotNull(DB::table(self::TABLE)->where('id', $id)->value('deleted_at'), "옛 조각 행({$id})이 살아 있다");
        }
        $this->assertNull(DB::table(self::TABLE)->where('id', $headRow)->value('deleted_at'), '1.1.0 조각 행을 지웠다');
        $this->assertNull(DB::table(self::TABLE)->where('id', $otherPlugin)->value('deleted_at'), '다른 플러그인의 행을 지웠다 — source_identifier 스코프가 없다');
    }

    /**
     * 두 번 실행해도 결과가 같고, 정리할 것이 없어도 예외 없이 끝난다.
     *
     * @scenario state=rerun
     *
     * @effects retired_fragment_prune_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_두_번_실행해도_안전하다(): void
    {
        $key = array_key_first(self::RETIRED);
        $this->writeFragment(self::SELF, $key);
        $row = $this->seedRow(self::RETIRED[$key], $key, self::SELF);

        $this->runMigration();
        $deletedAt = DB::table(self::TABLE)->where('id', $row)->value('deleted_at');

        $this->runMigration();

        $this->assertNotNull($deletedAt);
        $this->assertSame($deletedAt, DB::table(self::TABLE)->where('id', $row)->value('deleted_at'), '재실행이 이미 지운 행을 다시 건드렸다');
        $this->assertFileDoesNotExist($this->fragmentPath(self::SELF, $key));
    }

    /**
     * 마이그레이션을 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function runMigration(): void
    {
        (new PruneRetiredExtensionFragments($this->pluginsRoot))->run(new UpgradeContext(
            fromVersion: '1.0.3',
            toVersion: '1.1.0',
            currentStep: '1.1.0',
            logChannel: 'extension-upgrade',
        ));
    }

    /**
     * 저장소의 1.1.0 조각 파일을 임시 활성 디렉토리로 옮기고 그 키 목록을 돌려줍니다.
     *
     * @return array<int, string> 정렬된 조각 키
     */
    private function seedHeadFragments(): array
    {
        $from = base_path('plugins/_bundled/'.self::SELF.'/resources/extensions');
        File::copyDirectory($from, $this->pluginsRoot.'/'.self::SELF.'/resources/extensions');

        return $this->keysOf($from);
    }

    /**
     * 임시 활성 디렉토리의 조각 키 — `AbstractPlugin::getLayoutExtensions()` 와 같은 `*.json` glob.
     *
     * @return array<int, string> 정렬된 조각 키
     */
    private function activeKeys(): array
    {
        return $this->keysOf($this->pluginsRoot.'/'.self::SELF.'/resources/extensions');
    }

    /**
     * 디렉토리의 `*.json` 조각 키를 정렬해 돌려줍니다.
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
     * 옛 조각 파일을 씁니다.
     *
     * @param  string  $dir  플러그인 루트 기준 디렉토리 (식별자 또는 `_bundled/식별자`)
     * @param  string  $key  조각 키
     * @return void 반환값 없음
     */
    private function writeFragment(string $dir, string $key): void
    {
        $path = $this->fragmentPath($dir, $key);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "{\"target_layout\": \"admin_ecommerce_order_list\"}\n");
    }

    /**
     * 조각 파일 경로를 돌려줍니다.
     *
     * @param  string  $dir  플러그인 루트 기준 디렉토리
     * @param  string  $key  조각 키
     * @return string 절대 경로
     */
    private function fragmentPath(string $dir, string $key): string
    {
        return $this->pluginsRoot.'/'.$dir.'/resources/extensions/'.$key.'.json';
    }

    /**
     * 등록 행을 하나 심고 그 ID 를 돌려줍니다.
     *
     * @param  string  $target  타겟 이름
     * @param  string  $fragmentKey  조각 식별자
     * @param  string  $source  출처 식별자
     * @return int 삽입된 행 ID
     */
    private function seedRow(string $target, string $fragmentKey, string $source): int
    {
        return (int) DB::table(self::TABLE)->insertGetId([
            'template_id' => $this->templateId,
            'extension_type' => 'overlay',
            'target_name' => $target,
            'fragment_key' => $fragmentKey,
            'source_type' => 'plugin',
            'source_identifier' => $source,
            'content' => '{}',
            'priority' => 100,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * FK 대상 템플릿 행을 확보합니다.
     *
     * @return int 템플릿 ID
     */
    private function ensureTemplate(): int
    {
        $existing = DB::table('templates')->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('templates')->insertGetId([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => json_encode(['ko' => 'admin', 'en' => 'admin']),
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

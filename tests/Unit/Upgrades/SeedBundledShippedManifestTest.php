<?php

namespace Tests\Unit\Upgrades;

use App\Extension\Helpers\BundledShippedManifest;
use App\Extension\UpgradeContext;
use App\Upgrades\Data\V7_2_0\Migrations\SeedBundledShippedManifest;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 7.2.0 데이터 스텝 `14_SeedBundledShippedManifest` — 7.1.x 에서 올라온 사이트에 번들 출하 목록을 이번 배포본에서 씨앗한다.
 *
 * 디스크가 아니라 배포본(업데이트 대기 폴더의 압축 해제본 중 루트 `index.php` 가 사이트와 같은 것)에서 만든다 — 디스크의 이전 판
 * 잔존·운영자 파일을 「출하했던 파일」로 기록하면 다음 업데이트가 지울 수 있다.
 *
 * 시나리오 SSoT: tests/scenarios/core-update-bundled-shipped-manifest.yaml
 */
class SeedBundledShippedManifestTest extends TestCase
{
    /** 임시 루트 */
    private string $root;

    /**
     * 임시 사이트·대기 폴더를 만들고 스텝 파일을 싣는다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 업그레이드 데이터 스텝은 오토로드되지 않는다 — 실행 시점과 같은 방식으로 싣는다.
        require_once base_path('upgrades/data/7.2.0/migrations/14_SeedBundledShippedManifest.php');

        $this->root = storage_path('framework/testing/seed-bundled-shipped-'.uniqid());
        File::ensureDirectoryExists($this->root.'/site/modules/_bundled/acme-shop');
        File::put($this->root.'/site/index.php', '<?php // 7.2.0');
        // 디스크에만 있는 운영자 파일 — 목록에 들어가면 안 된다
        File::put($this->root.'/site/modules/_bundled/acme-shop/operator.txt', 'mine');
        File::put($this->root.'/site/modules/_bundled/acme-shop/module.json', '{"v":2}');
    }

    /**
     * 임시 경로를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * 사이트와 같은 판의 배포본을 찾아 그 번들 파일로 목록을 만들고, 판을 적으며, 되돌릴 사본은 남기지 않는다.
     *
     * @scenario case=upgrade_step_seed
     *
     * @effects upgrade_step_seeds_from_release
     *
     * @return void 반환값 없음
     */
    public function test_seeds_the_manifest_from_the_matching_release(): void
    {
        $release = $this->root.'/pending/core_20261118_000000/extracted/g7-7.2.0';
        File::ensureDirectoryExists($release.'/modules/_bundled/acme-shop');
        File::put($release.'/index.php', '<?php // 7.2.0');
        File::put($release.'/modules/_bundled/acme-shop/module.json', '{"v":2}');

        $this->runStep();

        $this->assertSame(
            ['modules/_bundled/acme-shop/module.json' => md5('{"v":2}')],
            BundledShippedManifest::read($this->root.'/state'),
            '배포본이 아닌 디스크(운영자 파일 포함)로 목록을 만들었다',
        );
        $recorded = json_decode((string) file_get_contents(BundledShippedManifest::path($this->root.'/state')), true);
        $this->assertSame('7.2.0', $recorded['version'] ?? null);
        $this->assertFileDoesNotExist($this->root.'/state/'.BundledShippedManifest::PREVIOUS_FILENAME, '씨앗이 되돌릴 사본을 남겼다');
    }

    /**
     * 배포본을 찾지 못하면(판이 다른 압축 해제본뿐) 목록을 만들지 않고, 이미 목록이 있으면 건드리지 않는다.
     *
     * @scenario case=upgrade_step_seed
     *
     * @effects upgrade_step_skips_without_release_or_when_present
     *
     * @return void 반환값 없음
     */
    public function test_skips_without_a_matching_release_and_when_a_manifest_exists(): void
    {
        $other = $this->root.'/pending/core_20261118_000000/extracted';
        File::ensureDirectoryExists($other.'/modules/_bundled/acme-shop');
        File::put($other.'/index.php', '<?php // 다른 판');
        File::put($other.'/modules/_bundled/acme-shop/module.json', '{"v":9}');

        $this->runStep();
        $this->assertNull(BundledShippedManifest::read($this->root.'/state'), '사이트와 다른 판의 압축 해제본으로 목록을 만들었다');

        BundledShippedManifest::write(['kept' => 'x'], '7.2.0', $this->root.'/state', keepPrevious: false);
        File::put($other.'/index.php', '<?php // 7.2.0');
        $this->runStep();
        $this->assertSame(['kept' => 'x'], BundledShippedManifest::read($this->root.'/state'), '이미 있는 목록을 덮었다');
    }

    /**
     * 스텝을 7.1.6 → 7.2.0 컨텍스트로 돌린다.
     *
     * @return void 반환값 없음
     */
    private function runStep(): void
    {
        (new SeedBundledShippedManifest($this->root.'/site', $this->root.'/pending', $this->root.'/state'))
            ->run(new UpgradeContext('7.1.6', '7.2.0', '7.2.0'));
    }
}

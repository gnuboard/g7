<?php

namespace Tests\Unit\Extension;

use Illuminate\Support\Facades\File;
use Tests\Support\ExtensionVendorAutoloadMap;
use Tests\TestCase;

/**
 * PHPUnit 부트스트랩의 확장 vendor 맵은 `_bundled` 사본을 활성 설치본보다 먼저 쓴다.
 *
 * 확장 시험은 `_bundled` 코드를 검증한다(확장 자기 코드는 이미 `_bundled` 우선으로 등록). 그런데 제3자 패키지 맵은
 * 활성 디렉토리를 먼저 모아, 개발 사이트에 낡은 설치본이 있으면 시험이 그 설치본의 패키지를 읽어 거짓 결과를 냈고,
 * 설치·제거 도중에는 없는 파일을 include 해 실패했다(sirsoft-sso 는 플러그인 쪽 우회로 막아 왔다).
 *
 * 개발 사이트 설치본을 건드리지 않도록 임시 디렉토리에 활성·번들 두 사본을 만들어 판정한다.
 */
class ExtensionVendorBundledPriorityTest extends TestCase
{
    /** 임시 프로젝트 루트 */
    private string $tempRoot;

    /**
     * 테스트 준비 — 임시 프로젝트 루트 경로를 정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7-vendor-priority-'.bin2hex(random_bytes(6));
    }

    /**
     * 테스트 정리 — 임시 프로젝트 루트를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempRoot);

        parent::tearDown();
    }

    /**
     * 확장 vendor 사본 하나를 만듭니다 (classmap·PSR-4·PSR-0·files 맵).
     *
     * @param  string  $dir  확장 디렉토리 (활성 또는 `_bundled`)
     * @return string 그 사본의 vendor 디렉토리
     */
    private function makeVendorCopy(string $dir): string
    {
        $vendor = $dir.'/vendor';
        File::ensureDirectoryExists($vendor.'/composer');
        File::ensureDirectoryExists($vendor.'/acme/lib/src');
        File::put($vendor.'/acme/lib/bootstrap.php', '<?php');

        $maps = [
            'autoload_classmap.php' => ['Acme\\Lib\\Thing' => $vendor.'/acme/lib/src/Thing.php', 'Plugins\\Acme\\Own' => $vendor.'/own.php'],
            'autoload_psr4.php' => ['Acme\\Lib\\' => [$vendor.'/acme/lib/src']],
            'autoload_namespaces.php' => ['AcmeLegacy' => [$vendor.'/acme/legacy']],
            'autoload_files.php' => ['acme-boot' => $vendor.'/acme/lib/bootstrap.php'],
        ];
        foreach ($maps as $file => $map) {
            File::put($vendor.'/composer/'.$file, '<?php return '.var_export($map, true).';');
        }

        return $vendor;
    }

    /**
     * 같은 확장의 활성·번들 사본이 모두 있으면 classmap 과 PSR 검색 순서가 `_bundled` 를 먼저 가리킨다.
     *
     * @scenario ext_type=module|plugin, copies=active_and_bundled
     *
     * @effects bundled_vendor_wins_classmap, bundled_vendor_first_in_psr_search, bundled_files_required_first
     *
     * @return void 반환값 없음
     */
    public function test_bundled_vendor_copy_is_preferred_over_active_install(): void
    {
        foreach (['modules' => 'acme-mod', 'plugins' => 'acme-plugin'] as $type => $identifier) {
            File::deleteDirectory($this->tempRoot);
            $active = $this->makeVendorCopy($this->tempRoot.'/'.$type.'/'.$identifier);
            $bundled = $this->makeVendorCopy($this->tempRoot.'/'.$type.'/_bundled/'.$identifier);

            $map = ExtensionVendorAutoloadMap::collect($this->tempRoot);

            $this->assertSame($bundled.'/acme/lib/src/Thing.php', $map['classmap']['Acme\\Lib\\Thing'], $type);
            $this->assertSame([$bundled.'/acme/lib/src', $active.'/acme/lib/src'], $map['psr4']['Acme\\Lib\\'], $type);
            $this->assertSame([$bundled.'/acme/legacy', $active.'/acme/legacy'], $map['psr0']['AcmeLegacy'], $type);
            $this->assertSame($bundled.'/acme/lib/bootstrap.php', $map['files'][0], $type);
            $this->assertArrayNotHasKey('Plugins\\Acme\\Own', $map['classmap'], $type);
        }
    }

    /**
     * 활성 설치본만 있는 확장(번들 사본 없음)의 패키지도 그대로 모은다.
     *
     * @scenario ext_type=module|plugin, copies=active_only
     *
     * @effects active_only_vendor_still_collected
     *
     * @return void 반환값 없음
     */
    public function test_active_only_vendor_is_still_collected(): void
    {
        foreach (['modules', 'plugins'] as $type) {
            File::deleteDirectory($this->tempRoot);
            $active = $this->makeVendorCopy($this->tempRoot.'/'.$type.'/acme-only');

            $map = ExtensionVendorAutoloadMap::collect($this->tempRoot);

            $this->assertSame($active.'/acme/lib/src/Thing.php', $map['classmap']['Acme\\Lib\\Thing'], $type);
            $this->assertSame([$active.'/acme/lib/src'], $map['psr4']['Acme\\Lib\\'], $type);
        }
    }
}

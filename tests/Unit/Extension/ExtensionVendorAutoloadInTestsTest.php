<?php

namespace Tests\Unit\Extension;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 확장 vendor(제3자 composer 패키지)가 PHPUnit 프로세스에서도 오토로드되는지에 대한 회귀 테스트.
 *
 * `autoload-extensions.php` 의 `vendor_autoloads` 를 소비하는 진입점은 `public/index.php` 와
 * `artisan` 뿐이었다. 그래서 테스트 프로세스에서는 확장 전용 패키지가 오토로드되지 않았고,
 * 그 패키지를 쓰는 코드 경로가 통째로 테스트 불가였다 — 아무도 그 경로를 테스트하지 않는 동안
 * 이 결손은 드러나지 않는다 (공개 #125 에서 처음 드러났다).
 *
 * `tests/bootstrap.php` 가 그 등록을 하되, 확장 vendor 의 `autoload.php` 를 그대로 require
 * 하지 않고 제3자 항목만 골라 `vendorDir` 없는 로더로 등록한다. 그렇게 하지 않으면 두 가지가
 * 조용히 깨지며, 그 둘이 이 테스트의 나머지 두 축이다.
 */
class ExtensionVendorAutoloadInTestsTest extends TestCase
{
    /**
     * 확장 vendor 의 제3자 클래스가 테스트 프로세스에서 오토로드된다.
     *
     * 모집단 주의: 확장 활성 디렉토리와 그 `vendor/` 는 gitignore 대상이라, 확장 composer
     * 설치를 하지 않은 체크아웃에서는 모집단이 0 이 되어 이 단언이 공허하게 통과한다.
     * 그 상태에서도 아래 두 축(자기 네임스페이스 비하이재킹 / base path 무결성)은 유효하다.
     */
    #[Test]
    public function extension_third_party_packages_are_autoloadable(): void
    {
        $unloadable = [];
        $sampled = [];

        foreach ($this->discoverExtensionVendorClassmaps() as $classmapFile) {
            foreach (require $classmapFile as $fqcn => $path) {
                // 확장 자기 심볼은 애초에 등록 대상이 아니고, Composer 자신의 클래스는 루트 오토로더가
                // 이미 들고 있어 우리 등록이 없어도 통과한다 — 표본으로 쓰면 공허한 단언이 된다.
                if ($this->isExtensionOwnSymbol($fqcn) || str_starts_with($fqcn, 'Composer\\')) {
                    continue;
                }

                $sampled[] = $fqcn;

                if (! class_exists($fqcn) && ! interface_exists($fqcn) && ! trait_exists($fqcn)) {
                    $unloadable[] = $fqcn;
                }

                // 확장마다 대표 1건만 본다 — 맵 전체를 로드하면 스위트가 느려지고,
                // 오토로더가 걸렸는지 여부는 대표 1건으로 판정된다.
                break;
            }
        }

        $this->assertSame(
            [],
            $unloadable,
            '확장 vendor 의 제3자 클래스가 테스트 프로세스에서 오토로드되지 않았습니다 — '
            .'tests/bootstrap.php 의 확장 vendor 등록을 확인하세요. 표본: '
            .implode(', ', $sampled)
        );
    }

    /**
     * 확장 자기 네임스페이스가 vendor 오토로더에 하이재킹되지 않는다.
     *
     * 확장 vendor 의 Composer 오토로더는 확장 자신의 PSR-4 를 **활성** 디렉토리로 매핑하고
     * 자신을 prepend 로 등록한다. 그것을 그대로 쓰면 `tests/bootstrap.php` 가 앞서 prepend 한
     * `_bundled` 등록을 이겨서, 테스트가 `_bundled` 가 아니라 활성 디렉토리 사본을 검증하게
     * 된다 — `_bundled` 에서만 작업한다는 규율이 오류 없이 깨지는 형태다.
     */
    #[Test]
    public function bundled_extension_classes_are_not_hijacked_to_the_active_directory(): void
    {
        $hijacked = [];

        foreach (glob(base_path('modules/_bundled/*/module.php')) ?: [] as $manifest) {
            $identifier = basename(dirname($manifest));
            $class = $this->extensionEntryClass('Modules', $identifier);

            if ($class === null || ! class_exists($class)) {
                continue;
            }

            $file = (new \ReflectionClass($class))->getFileName();

            if ($file !== false && ! str_contains(str_replace('\\', '/', $file), '/modules/_bundled/')) {
                $hijacked[$identifier] = $file;
            }
        }

        $this->assertSame(
            [],
            $hijacked,
            '_bundled 확장의 클래스가 활성 디렉토리에서 로드되었습니다 — 확장 vendor 오토로더가 '
            .'자기 PSR-4 를 등록하고 있습니다.'
        );
    }

    /**
     * 등록된 Composer 로더 목록의 첫 항목이 루트 vendor 로 남는다.
     *
     * Composer 로더는 `vendorDir` 를 가지면 `ClassLoader::getRegisteredLoaders()` 맨 앞에
     * 자신을 넣는데, `Illuminate\Foundation\Testing\TestCase::createApplication()` 이
     * `Application::inferBasePath()` 로 그 첫 항목에서 base path 를 유추한다. 확장 vendor 의
     * 로더가 그 자리를 차지하면 이후 테스트의 앱 부팅이 `modules/{id}/bootstrap/app.php` 를
     * 찾다 실패한다. (운영 진입점은 basePath 를 명시 전달하므로 영향이 없다.)
     */
    #[Test]
    public function registered_loader_list_still_points_at_the_project_root(): void
    {
        $registered = array_keys(ClassLoader::getRegisteredLoaders());

        $this->assertNotEmpty($registered, '등록된 Composer 로더가 없습니다.');

        $this->assertSame(
            realpath(base_path('vendor')),
            realpath($registered[0]),
            'Composer 로더 목록의 첫 항목이 루트 vendor 가 아닙니다 — 확장 vendor 로더가 '
            .'vendorDir 를 갖고 등록되어 Application::inferBasePath() 를 오염시킵니다.'
        );
    }

    /**
     * 확장(모듈/플러그인)의 vendor classmap 파일 경로를 모읍니다.
     *
     * @return array<int, string> classmap 파일 절대 경로 목록
     */
    private function discoverExtensionVendorClassmaps(): array
    {
        $found = [];

        foreach (['modules', 'plugins'] as $type) {
            foreach ([$type, $type.'/_bundled'] as $dir) {
                foreach (glob(base_path($dir.'/*/vendor/composer/autoload_classmap.php')) ?: [] as $file) {
                    $found[] = $file;
                }
            }
        }

        return $found;
    }

    /**
     * 확장 자신의 네임스페이스에 속하는 심볼인지 판정합니다.
     *
     * @param  string  $symbol  FQCN 또는 PSR-4 접두사
     * @return bool 확장 자기 심볼이면 true
     */
    private function isExtensionOwnSymbol(string $symbol): bool
    {
        return str_starts_with($symbol, 'Modules\\') || str_starts_with($symbol, 'Plugins\\');
    }

    /**
     * 확장 식별자로부터 진입 클래스 FQCN 을 조립합니다.
     *
     * @param  string  $root  네임스페이스 루트 (Modules|Plugins)
     * @param  string  $identifier  확장 식별자 (vendor-name)
     * @return string|null FQCN. 식별자 형식이 아니면 null
     */
    private function extensionEntryClass(string $root, string $identifier): ?string
    {
        $parts = explode('-', $identifier);

        if (count($parts) < 2) {
            return null;
        }

        $vendor = ucfirst($parts[0]);
        $name = str_replace('_', '', ucwords($parts[1], '_'));
        $entry = $root === 'Modules' ? 'Module' : 'Plugin';

        return $root.'\\'.$vendor.'\\'.$name.'\\'.$entry;
    }
}

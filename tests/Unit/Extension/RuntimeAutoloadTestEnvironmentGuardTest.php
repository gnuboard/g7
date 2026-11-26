<?php

namespace Tests\Unit\Extension;

use App\Extension\ExtensionManager;
use Composer\Autoload\ClassLoader;
use Tests\TestCase;

/**
 * 테스트 프로세스의 런타임 오토로드 재등록은 실제 오토로드 캐시를 다시 걸지 않는다.
 *
 * `tests/bootstrap.php` 는 확장 자기 코드를 `_bundled` 사본으로 먼저(prepend) 등록하고, 활성 설치본의
 * PSR-4·진입 classmap 은 건너뛴다. 그런데 시험 안에서 `reregisterRuntimeAutoload()` 가 실제
 * `bootstrap/cache/autoload-extensions.php` 로 돌면 `src_classmap`(설치본 경로)이 주 Composer 로더의
 * classmap 에 들어가 PSR-4 보다 먼저 이기고, 설치본 `vendor/autoload.php` 의 로더들이 맨 앞에 붙는다.
 * 그 뒤 처음 로드되는 확장 클래스는 개발 사이트의 낡은 설치본에서 읽혀 거짓 결과가 난다.
 *
 * 실제 캐시 파일은 개발 환경에만 있어 없으면 건너뛴다(같은 판정을 하는 `ExtensionSourceClassmapTest` 선례).
 */
class RuntimeAutoloadTestEnvironmentGuardTest extends TestCase
{
    /**
     * 실제 캐시 경로로 재등록해도 설치본 소스 classmap 과 확장 vendor 로더가 붙지 않는다.
     *
     * @scenario cache_path=real
     *
     * @effects runtime_reregister_skips_real_cache_in_tests
     *
     * @return void 반환값 없음
     */
    public function test_real_cache_reregister_keeps_bundled_first_loading(): void
    {
        $autoloadFile = base_path('bootstrap/cache/autoload-extensions.php');
        if (! file_exists($autoloadFile)) {
            $this->markTestSkipped('autoload-extensions.php 미생성 (extension:update-autoload 필요)');
        }

        $srcClassmap = (require $autoloadFile)['src_classmap'] ?? [];
        if ($srcClassmap === []) {
            $this->markTestSkipped('설치된 확장의 src_classmap 이 비어 있음');
        }

        $loaders = ClassLoader::getRegisteredLoaders();
        $mainLoader = reset($loaders);
        $before = array_intersect_key($mainLoader->getClassMap(), $srcClassmap);
        $loaderDirsBefore = array_keys($loaders);

        app(ExtensionManager::class)->reregisterRuntimeAutoload();

        $added = array_diff_assoc(array_intersect_key($mainLoader->getClassMap(), $srcClassmap), $before);
        $this->assertSame([], $added, '설치본 소스 경로가 주 로더 classmap 에 들어가면 _bundled PSR-4 를 이긴다');
        $this->assertSame($loaderDirsBefore, array_keys(ClassLoader::getRegisteredLoaders()), '설치본 vendor 로더가 앞에 붙으면 안 된다');
    }
}

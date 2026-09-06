<?php

namespace Tests\Feature\Extension;

use App\Extension\Helpers\ExtensionPendingHelper;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 사용자 추가 에셋(`custom/`)의 확장 교체 생존 테스트
 *
 * `custom/` 은 운영자 소유 자리다. 확장 업데이트가 그것을 덮어쓰면 업데이트할 때마다
 * 운영자가 넣은 파일이 사라지고, 그 사실이 어디에도 남지 않는다 — 파일이 조용히
 * 없어질 뿐이다. 포럼 질문("custom.css 를 어디에 두나요")의 커뮤니티 답변대로
 * `src/styles/` 에 넣으면 정확히 그 일이 벌어진다.
 *
 * 교체 경로가 **둘**이라는 점이 핵심이다:
 *  1. 디렉토리 rename (정상 경로)
 *  2. 제자리 파일 동기화 (Windows 에서 하위 트리에 열린 핸들이 있어 rename 이 막힐 때)
 *
 * 한쪽만 보존하면 잠금 상황에서만 조용히 사라진다 — 재현이 어려운 결함이 된다.
 *
 * DB 를 쓰지 않는다 — 파일 교체 동작만 검증한다.
 */
class CustomAssetPreservationTest extends TestCase
{
    /** 테스트 작업 디렉토리 */
    private string $workspace;

    /** 기존 활성 디렉토리 */
    private string $activePath;

    /** 새 배포본 소스 */
    private string $sourcePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = storage_path('framework/testing/custom-preserve-'.uniqid());
        $this->activePath = $this->workspace.'/plugins/g7test-preserve';
        $this->sourcePath = $this->workspace.'/plugins/_bundled/g7test-preserve';

        File::ensureDirectoryExists($this->activePath.'/custom');
        File::ensureDirectoryExists($this->activePath.'/dist/js');
        File::ensureDirectoryExists($this->sourcePath.'/dist/js');

        // 운영자가 넣은 파일
        File::put($this->activePath.'/custom/custom.css', 'body{--operator:1}');
        File::put($this->activePath.'/custom/assets.json', '{"assets":[]}');

        // 구버전 산출물 (교체 시 정리되어야 한다)
        File::put($this->activePath.'/dist/js/old-hash.js', '// old');

        // 새 배포본
        File::put($this->sourcePath.'/dist/js/new-hash.js', '// new');
        File::put($this->sourcePath.'/plugin.json', '{"identifier":"g7test-preserve"}');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    /**
     * 보존 결과를 단언합니다.
     *
     * @return void
     */
    private function assertPreserved(): void
    {
        $this->assertFileExists(
            $this->activePath.'/custom/custom.css',
            '운영자가 넣은 파일이 확장 교체에 사라졌습니다.'
        );
        $this->assertSame(
            'body{--operator:1}',
            File::get($this->activePath.'/custom/custom.css'),
            '운영자 파일의 내용이 새 배포본으로 덮어써졌습니다.'
        );
        $this->assertFileExists($this->activePath.'/custom/assets.json');
    }

    /**
     * @scenario custom_source=convention_scan, custom_asset=css
     *
     * @effects custom_dir_survives_extension_update
     */
    #[Test]
    public function rename_경로에서_운영자_파일이_보존된다(): void
    {
        ExtensionPendingHelper::copyToActive($this->sourcePath, $this->activePath);

        $this->assertPreserved();
        $this->assertFileExists($this->activePath.'/dist/js/new-hash.js');
    }

    #[Test]
    public function rename_경로에서도_구버전_산출물은_정리된다(): void
    {
        // 보존 계층이 stale 산출물 청소를 무력화하면 배포본이 계속 부푼다
        ExtensionPendingHelper::copyToActive($this->sourcePath, $this->activePath);

        $this->assertFileDoesNotExist($this->activePath.'/dist/js/old-hash.js');
    }

    /**
     * @scenario custom_source=convention_scan, custom_asset=css
     *
     * @effects custom_dir_survives_inplace_sync_fallback
     */
    #[Test]
    public function 제자리_동기화_폴백_경로에서도_운영자_파일이_보존된다(): void
    {
        // rename 이 막히는 상황(Windows 잠금)의 폴백 경로를 직접 태운다
        $sync = new ReflectionMethod(ExtensionPendingHelper::class, 'syncDirectoryContents');
        $sync->setAccessible(true);

        // 스테이징에는 새 배포본 + 운영자 파일이 함께 있어야 한다 (copyToActive 가 그렇게 만든다)
        $staging = $this->workspace.'/staging';
        File::copyDirectory($this->sourcePath, $staging);
        File::copyDirectory($this->activePath.'/custom', $staging.'/custom');

        $sync->invoke(null, $staging, $this->activePath, null);

        $this->assertPreserved();
        $this->assertFileExists($this->activePath.'/dist/js/new-hash.js');
        $this->assertFileDoesNotExist($this->activePath.'/dist/js/old-hash.js');
    }

    #[Test]
    public function 스테이징에_운영자_디렉토리가_없어도_정리_대상이_되지_않는다(): void
    {
        // 소스에 없는 항목을 지우는 정리 로직이 custom/ 을 지우면 안 된다
        $stale = new ReflectionMethod(ExtensionPendingHelper::class, 'removeStaleEntries');
        $stale->setAccessible(true);

        $failures = [];
        $stale->invokeArgs(null, [$this->sourcePath, $this->activePath, &$failures, true]);

        $this->assertPreserved();
    }

    #[Test]
    public function 새_배포본이_같은_이름의_디렉토리를_담아도_운영자_파일이_우선한다(): void
    {
        File::ensureDirectoryExists($this->sourcePath.'/custom');
        File::put($this->sourcePath.'/custom/custom.css', 'body{--from-vendor:1}');

        ExtensionPendingHelper::copyToActive($this->sourcePath, $this->activePath);

        $this->assertPreserved();
    }

    #[Test]
    public function 대상_디렉토리가_없으면_그냥_복사한다(): void
    {
        $fresh = $this->workspace.'/plugins/g7test-fresh';

        ExtensionPendingHelper::copyToActive($this->sourcePath, $fresh);

        $this->assertFileExists($fresh.'/dist/js/new-hash.js');
        $this->assertDirectoryDoesNotExist($fresh.'/custom');
    }

    /**
     * 삭제는 보존이 아니라 보관이다 — 그리고 보관 사실은 호출자에게 도달해야 한다.
     *
     * 사본 경로가 로그에만 남으면 운영자는 로그를 뒤지지 않는 한 사본의 존재를 모른다.
     * 그러면 "확장을 지웠더니 내 CSS 가 사라졌다" 와 구분되지 않는다.
     *
     * @effects custom_dir_archived_on_uninstall
     */
    #[Test]
    public function 삭제는_운영자_파일을_보관하고_그_경로를_반환한다(): void
    {
        $archived = ExtensionPendingHelper::deleteExtensionDirectory(
            $this->workspace.'/plugins',
            'g7test-preserve'
        );

        // 확장은 실제로 지워진다 — 보존이 아니라 보관이다.
        $this->assertDirectoryDoesNotExist($this->activePath);

        // 보관 경로가 반환값으로 올라온다.
        $this->assertCount(1, $archived, '보관된 디렉토리 1건이 반환되어야 한다');
        $this->assertSame('custom', $archived[0]['directory']);

        // 반환된 경로에 운영자 파일이 실제로 있다 — 경로만 돌려주고 사본이 없으면 무의미하다.
        $this->assertFileExists($archived[0]['archive'].'/custom.css');
        $this->assertSame('body{--operator:1}', File::get($archived[0]['archive'].'/custom.css'));

        File::deleteDirectory(dirname($archived[0]['archive']));
    }

    /**
     * 보관할 것이 없으면 빈 배열이다 — 호출부가 "사본이 있다" 고 오인해선 안 된다.
     */
    #[Test]
    public function 운영자_파일이_없으면_보관_결과가_비어_있다(): void
    {
        File::deleteDirectory($this->activePath.'/custom');

        $archived = ExtensionPendingHelper::deleteExtensionDirectory(
            $this->workspace.'/plugins',
            'g7test-preserve'
        );

        $this->assertSame([], $archived);
    }
}

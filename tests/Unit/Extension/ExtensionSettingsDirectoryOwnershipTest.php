<?php

namespace Tests\Unit\Extension;

use App\Extension\ExtensionManager;
use App\Extension\Helpers\ExtensionPendingHelper;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 확장 설정 시드·업데이트 임시 폴더의 소유권 상속 회귀 테스트.
 *
 * sudo 코어 업데이트(번들 확장 업데이트 프롬프트)가 root 로 `storage/app/{modules,plugins}/{id}/settings/`
 * 를 만들면 그 서브트리는 restore_ownership 의도적 제외 경로라 코어 업데이트로도 되돌려지지 않고,
 * 이후 웹 프로세스의 설정 저장이 영구 실패한다. 임시 폴더의 부모 `storage/app/temp` 도 최초 생성자가
 * root 면 같은 결말이다(자식만 삭제된다). 소유권 축은 Windows 에서 실측할 수 없으므로 저장소 관례
 * (소스 훑기)로 상속 호출의 존재를 잠근다.
 */
class ExtensionSettingsDirectoryOwnershipTest extends TestCase
{
    /**
     * 클래스 소스에서 주석을 제거한 본문을 돌려줍니다.
     *
     * @param  string  $class  클래스 이름
     * @return string 주석을 뺀 소스
     */
    private function strippedSource(string $class): string
    {
        $path = (new \ReflectionClass($class))->getFileName();
        $stripped = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $stripped .= is_array($token) ? $token[1] : $token;
        }

        return $stripped;
    }

    /**
     * `makeDirectory($settingsDir …)` 뒤 5줄 안에 상속 호출이 있는지 판정합니다.
     *
     * @param  string  $class  매니저 클래스 이름
     * @return void 반환값 없음
     */
    private function assertMakeDirectoryInheritsOwnership(string $class): void
    {
        $source = $this->strippedSource($class);
        $offset = 0;
        $found = 0;

        while (($pos = strpos($source, 'File::makeDirectory($settingsDir', $offset)) !== false) {
            $window = substr($source, $pos, 400);
            $lines = array_slice(explode("\n", $window), 0, 6);

            $this->assertStringContainsString(
                'FilePermissionHelper::inheritOwnershipFromParent($settingsDir)',
                implode("\n", $lines),
                $class.': settings 디렉토리 생성 뒤 부모 소유권 상속이 없다 — sudo 코어 업데이트 뒤 그 확장의 설정 저장이 영구 실패한다'
            );

            $found++;
            $offset = $pos + 1;
        }

        $this->assertGreaterThan(0, $found, $class.': settings 디렉토리 생성 지점을 찾지 못했다');
    }

    /**
     * 모듈 설정 시드 디렉토리와 파일을 만든 뒤 부모 소유권을 상속한다.
     *
     * @effects settings_directory_seed_inherits_ownership
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 모듈_설정_시드_디렉토리와_파일은_부모_소유권을_상속한다(): void
    {
        $this->assertMakeDirectoryInheritsOwnership(ModuleManager::class);

        $source = $this->strippedSource(ModuleManager::class);
        $this->assertStringContainsString(
            "File::put(\$filePath, \$jsonContent);\n            FilePermissionHelper::inheritOwnershipFromParent(\$filePath);",
            $source,
            'ModuleManager: 설정 시드 파일 쓰기 뒤 부모 소유권 상속이 없다'
        );
    }

    /**
     * 플러그인 설정 시드 디렉토리와 파일을 만든 뒤 부모 소유권을 상속한다.
     *
     * @effects settings_directory_seed_inherits_ownership
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 플러그인_설정_시드_디렉토리와_파일은_부모_소유권을_상속한다(): void
    {
        $this->assertMakeDirectoryInheritsOwnership(PluginManager::class);

        $source = $this->strippedSource(PluginManager::class);
        $this->assertStringContainsString(
            "File::put(\$settingsPath, \$content);\n        FilePermissionHelper::inheritOwnershipFromParent(\$settingsPath);",
            $source,
            'PluginManager: 설정 시드 파일 쓰기 뒤 부모 소유권 상속이 없다'
        );
    }

    /**
     * 업데이트 임시 폴더 확보가 공유 헬퍼를 경유한다 — 복사본은 갈라진다.
     *
     * 7.2.0 부터 GitHub 받기(`acquireGithubUpdate`)와 ZIP 준비(`prepareZipSource`)는 세 매니저가 아니라
     * `ExtensionManager` 에 있다. 그래서 대상을 매니저 이름으로 고르지 않고, 네 클래스에서
     * `storage/app/temp` 아래에 임시 폴더를 잡는 자리를 세어 그 수만큼 헬퍼 호출이 있는지 본다 —
     * 받기 위치가 다시 옮겨도 모집단이 따라간다.
     *
     * @effects update_temp_directory_inherits_ownership
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 업데이트_임시_폴더는_공유_헬퍼로_확보한다(): void
    {
        $sites = 0;

        foreach ([ExtensionManager::class, ModuleManager::class, PluginManager::class, TemplateManager::class] as $class) {
            $source = $this->strippedSource($class);
            $tempDirs = preg_match_all('/\$tempDir\s*=\s*storage_path\(\s*\'app\/temp\//', $source);
            $sites += $tempDirs;

            $this->assertSame(
                $tempDirs,
                substr_count($source, 'ExtensionPendingHelper::ensureUpdateTempDirectory($tempDir)'),
                $class.': storage/app/temp 아래 임시 폴더를 잡는 자리마다 공유 헬퍼로 확보해야 한다'
            );
            $this->assertStringNotContainsString(
                "File::ensureDirectoryExists(\$tempDir);\n",
                $source,
                $class.': 임시 폴더를 직접 생성하는 옛 경로가 남아 있다 — 부모 storage/app/temp 가 root 로 굳는다'
            );
        }

        $this->assertGreaterThanOrEqual(2, $sites, '임시 폴더를 잡는 자리를 찾지 못했다 — 모집단 정규식이 소스와 어긋났다');
    }

    /**
     * 공유 헬퍼는 부모를 먼저 확보하고 자식에 소유권을 상속시킨다 (동작).
     *
     * @effects update_temp_directory_inherits_ownership
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 공유_헬퍼는_부모와_자식을_함께_확보한다(): void
    {
        $parent = storage_path('framework/testing/temp-parent-'.uniqid());
        $child = $parent.'/module_update_'.uniqid();

        try {
            ExtensionPendingHelper::ensureUpdateTempDirectory($child);

            $this->assertDirectoryExists($parent);
            $this->assertDirectoryExists($child);
            $this->assertTrue(is_writable($child));
        } finally {
            File::deleteDirectory($parent);
        }
    }
}

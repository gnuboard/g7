<?php

namespace Tests\Unit\Helpers;

use App\Extension\Helpers\FilePermissionHelper;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * SettingsMigrator owner 상속 회귀 테스트.
 *
 * sudo update 흐름에서 모듈/플러그인 upgrade step 이 root 로 실행될 때
 * `SettingsMigrator::writeJsonFile` 가 만드는 *.json 파일이 root 소유로 영구 잔존하는
 * 회귀 차단:
 *
 *   - 변경 1 로 storage/app/{modules,plugins} 가 chown 비대상이 된 후, settings *.json
 *     파일에 후속 PHP-FPM 이 update 시도 시 쓰기 실패하는 케이스 차단
 *   - writeJsonFile 후 부모 디렉토리(예: storage/app/modules/{id}/settings/) 의 owner/group
 *     을 상속하여 PHP-FPM 시드 시점 owner 가 PHP-FPM 이라면 자동 일치
 *
 * 본 테스트는 `FilePermissionHelper::inheritOwnershipFromParent` 가 public static 으로
 * 노출되어 외부(SettingsMigrator) 가 호출 가능한지 검증.
 */
class SettingsMigratorOwnershipTest extends TestCase
{
    /**
     * @var array<int, string>
     */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (File::isDirectory($dir)) {
                File::deleteDirectory($dir);
            }
        }

        parent::tearDown();
    }

    private function createTempDir(): string
    {
        $dir = storage_path('test_settings_owner_'.uniqid());
        File::ensureDirectoryExists($dir);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * inheritOwnershipFromParent 가 public static 으로 노출되어야 함.
     *
     * 변경 7-(a): `SettingsMigrator::writeJsonFile` 같은 외부 호출처가 본 helper 를
     * 사용해 부모 owner/group 을 상속하도록 노출.
     *
     * @return void
     */
    public function test_inherit_ownership_from_parent_is_public(): void
    {
        $reflection = new \ReflectionMethod(FilePermissionHelper::class, 'inheritOwnershipFromParent');

        $this->assertTrue(
            $reflection->isPublic(),
            'FilePermissionHelper::inheritOwnershipFromParent 가 public 이어야 외부 호출 가능 (SettingsMigrator 등)',
        );
        $this->assertTrue($reflection->isStatic(), 'static 메서드여야 함');
    }

    /**
     * inheritOwnershipFromParent — 부모 디렉토리 stat 기반으로 owner/group 적용.
     *
     * POSIX 환경 전용. 일반 user 권한에서는 다른 user 로 chown 시도가 실패할 수 있으나,
     * 자기 자신 owner 인 경우 chown 자체가 발생하지 않으므로 멱등.
     *
     * @return void
     */
    public function test_inherit_ownership_from_parent_idempotent_for_self_owner(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! function_exists('chown')) {
            $this->markTestSkipped('POSIX 환경 전용 (Windows 자동 스킵)');
        }

        $parent = $this->createTempDir();
        $child = $parent.'/child.json';
        file_put_contents($child, '{}');

        $parentOwnerBefore = fileowner($parent);
        $childOwnerBefore = fileowner($child);

        // 자기 자신 owner 면 부모와 자식 owner 가 동일 → 멱등
        FilePermissionHelper::inheritOwnershipFromParent($child);

        $this->assertSame($parentOwnerBefore, fileowner($parent));
        $this->assertSame($childOwnerBefore, fileowner($child));
        $this->assertSame($parentOwnerBefore, fileowner($child), '자식 owner 가 부모와 동일');
    }

    /**
     * SettingsMigrator::writeJsonFile 호출 후 *.json 의 owner 가 부모와 일치.
     *
     * `SettingsMigrator` 는 protected 메서드 + non-static 이라 직접 호출이 어려우므로,
     * 핵심 통합 보장 = "writeJsonFile 메서드 안에서 inheritOwnershipFromParent 호출" 을
     * reflection 으로 검증.
     *
     * @return void
     */
    public function test_settings_migrator_write_json_file_invokes_inherit_ownership(): void
    {
        $reflection = new \ReflectionClass(\App\Extension\Helpers\SettingsMigrator::class);
        $method = $reflection->getMethod('writeJsonFile');
        $body = file_get_contents($method->getFileName());

        // writeJsonFile 메서드 내부에 inheritOwnershipFromParent 호출이 존재해야 함
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        $methodBody = implode("\n", array_slice(
            explode("\n", $body),
            $startLine - 1,
            $endLine - $startLine + 1
        ));

        $this->assertStringContainsString(
            'inheritOwnershipFromParent',
            $methodBody,
            'SettingsMigrator::writeJsonFile 가 sudo update 시 root 로 만든 *.json 의 owner 를 부모로 상속해야 함 (FilePermissionHelper::inheritOwnershipFromParent 호출)',
        );
    }
}

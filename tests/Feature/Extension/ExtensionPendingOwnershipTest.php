<?php

namespace Tests\Feature\Extension;

use App\Extension\Helpers\ExtensionBackupHelper;
use App\Extension\Helpers\ExtensionPendingHelper;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 확장 설치/업데이트 복사의 디렉토리 소유권 상속 테스트 (#122 P3).
 *
 * 파일은 `FilePermissionHelper::copyFile` 이 부모 소유권을 상속시키는데 **디렉토리는
 * 그렇지 않았다.** sudo 로 실행된 설치/업데이트가 만든 디렉토리가 root 소유로 남으면
 * 이후 웹 프로세스의 쓰기가 그 디렉토리에서 막힌다. 형제 구현
 * `ExtensionBackupHelper::copyDirectoryWithProgress` 는 이미 같은 방어를 갖고 있었다
 * (계층 불균형).
 *
 * 소유권 자체는 CI/개발 환경에서 단일 계정이라 값으로 단언할 수 없다 — 그래서 **복사
 * 경로 세 곳 모두가 상속 호출을 갖는지**를 소스에서 검사하고, 복사 자체가 정상 동작하는지를
 * 실제 파일시스템으로 확인한다. 한 경로만 고치면 나머지가 조용히 옛 동작으로 남기 때문이다.
 */
class ExtensionPendingOwnershipTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/pending-ownership');
        File::deleteDirectory($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * 디렉토리를 만드는 복사 경로 전부가 부모 소유권을 상속시킨다.
     *
     * 대상 3곳: `copyDirectoryWithProgress`(rename 경로) / `syncDirectoryContents` ·
     * `overlayDirectory`(제자리 동기화 폴백). 폴백만 빠뜨리면 파일 잠금으로 그 경로로
     * 떨어진 교체에서만 소유권이 어긋나, Windows 잠금 상황에서만 재현되는 결함이 된다.
     *
     * @effects extension_copy_paths_inherit_directory_ownership
     */
    public function test_every_directory_creating_copy_path_inherits_ownership(): void
    {
        $source = (new \ReflectionClass(ExtensionPendingHelper::class))->getFileName();
        $content = (string) file_get_contents($source);

        $methods = ['copyDirectoryWithProgress', 'syncDirectoryContents', 'overlayDirectory'];

        foreach ($methods as $method) {
            $body = $this->methodBody($content, $method);

            $this->assertNotSame('', $body, "{$method} 을 소스에서 찾지 못했다 — 검사가 공허하다");
            $this->assertStringContainsString(
                'ensureDirectoryExists($dest',
                $body,
                "{$method} 이 디렉토리를 만들지 않는다 — 대상 목록이 낡았다"
            );
            $this->assertStringContainsString(
                'FilePermissionHelper::inheritOwnershipFromParent($dest)',
                $body,
                "{$method} 이 디렉토리 소유권을 상속시키지 않는다 — sudo 설치 후 root 소유로 남는다"
            );
        }
    }

    /**
     * 형제 구현(`ExtensionBackupHelper`)과 방어 수준이 같다 — 계층 불균형이 남지 않는다.
     *
     * @effects extension_copy_ownership_matches_sibling_helper
     */
    public function test_matches_sibling_backup_helper_defense(): void
    {
        $sibling = (string) file_get_contents(
            (new \ReflectionClass(ExtensionBackupHelper::class))->getFileName()
        );

        $this->assertStringContainsString(
            'FilePermissionHelper::inheritOwnershipFromParent($dest)',
            $sibling,
            '기준으로 삼은 형제 구현이 바뀌었다 — 이 테스트의 전제를 다시 확인할 것'
        );
    }

    /**
     * 상속 호출을 추가한 뒤에도 실제 복사가 정상 동작한다 (회귀 가드).
     *
     * @effects extension_copy_still_produces_expected_tree
     */
    public function test_copy_still_produces_expected_tree(): void
    {
        $source = $this->root.'/src';
        $dest = $this->root.'/dest';

        File::ensureDirectoryExists($source.'/nested/deep');
        File::put($source.'/root.txt', 'a');
        File::put($source.'/nested/deep/leaf.txt', 'b');

        $method = new \ReflectionMethod(ExtensionPendingHelper::class, 'copyDirectoryWithProgress');
        $method->setAccessible(true);
        $method->invoke(null, $source, $dest, $source, null);

        $this->assertFileExists($dest.'/root.txt');
        $this->assertFileExists($dest.'/nested/deep/leaf.txt');
        $this->assertSame('b', file_get_contents($dest.'/nested/deep/leaf.txt'));
    }

    /**
     * 메서드 본문을 대략 잘라냅니다 (다음 메서드 선언 직전까지).
     *
     * @param  string  $content  파일 전문
     * @param  string  $method  메서드명
     * @return string 본문 (못 찾으면 빈 문자열)
     */
    private function methodBody(string $content, string $method): string
    {
        $start = strpos($content, "function {$method}(");

        if ($start === false) {
            return '';
        }

        $rest = substr($content, $start);
        $next = preg_match(
            '/\n    (?:public|protected|private)\s+(?:static\s+)?function\s/',
            substr($rest, 1),
            $m,
            PREG_OFFSET_CAPTURE
        );

        return $next === 1 ? substr($rest, 0, $m[0][1] + 1) : $rest;
    }
}

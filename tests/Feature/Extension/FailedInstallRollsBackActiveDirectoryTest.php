<?php

namespace Tests\Feature\Extension;

use App\Extension\Helpers\ExtensionInstallRollbackHelper;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 회귀: 실패한 설치가 활성 디렉토리를 고아로 남기지 않는다
 *
 * 설치 흐름은 원본(`_pending`/`_bundled`)을 활성 디렉토리로 **먼저 복사한 뒤** 확장을
 * 로드해 코어 버전·의존성·언어 경로를 검증한다. 검증에 로드된 인스턴스가 필요해 순서를
 * 뒤집을 수 없는데, 검증이 실패하면 방금 만든 디렉토리가 그대로 남았다.
 *
 * 남은 디렉토리는 DB 행이 없어 `plugin:list` 에도 뜨지 않고 번들 병합에도 참여하지 않는다.
 * 오류도 경고도 없이 디스크만 점유하는 고아이고, 다음 설치 시도가 그것을 "이미 있는
 * 설치본" 으로 보고 원본 복사를 건너뛸 수 있다.
 */
class FailedInstallRollsBackActiveDirectoryTest extends TestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = storage_path('framework/testing/install-rollback-'.uniqid());
        File::ensureDirectoryExists($this->sandbox);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sandbox);

        parent::tearDown();
    }

    /**
     * 이번 설치가 만든 디렉토리는 제거한다.
     */
    public function test_directory_created_by_this_install_is_removed(): void
    {
        $active = $this->sandbox.DIRECTORY_SEPARATOR.'vendor-sample';
        File::ensureDirectoryExists($active);
        File::put($active.DIRECTORY_SEPARATOR.'plugin.json', '{}');

        $removed = ExtensionInstallRollbackHelper::removeIfCreatedByThisInstall(
            $active,
            existedBefore: false,
            identifier: 'vendor-sample',
            type: 'plugin',
        );

        $this->assertTrue($removed);
        $this->assertDirectoryDoesNotExist($active);
    }

    /**
     * 이미 있던 설치본은 건드리지 않는다.
     *
     * `--force` 재설치가 검증에서 실패한 경우다. 그 디렉토리는 운영자의 기존 설치본이므로
     * 여기서 지우면 실패한 재설치가 멀쩡히 돌던 확장을 없애버린다.
     */
    public function test_preexisting_directory_is_left_untouched(): void
    {
        $active = $this->sandbox.DIRECTORY_SEPARATOR.'vendor-existing';
        File::ensureDirectoryExists($active);
        File::put($active.DIRECTORY_SEPARATOR.'plugin.json', '{"keep":true}');

        $removed = ExtensionInstallRollbackHelper::removeIfCreatedByThisInstall(
            $active,
            existedBefore: true,
            identifier: 'vendor-existing',
            type: 'plugin',
        );

        $this->assertFalse($removed);
        $this->assertDirectoryExists($active);
        $this->assertStringContainsString('keep', File::get($active.DIRECTORY_SEPARATOR.'plugin.json'));
    }

    /**
     * 디렉토리가 아예 없으면 조용히 넘어간다 (복사 이전에 실패한 경우).
     */
    public function test_absent_directory_is_a_no_op(): void
    {
        $this->assertFalse(ExtensionInstallRollbackHelper::removeIfCreatedByThisInstall(
            $this->sandbox.DIRECTORY_SEPARATOR.'never-created',
            existedBefore: false,
            identifier: 'never-created',
            type: 'module',
        ));
    }

    /**
     * 세 관리자의 설치 경로가 모두 이 되돌리기를 경유한다.
     *
     * 한 곳만 배선하면 나머지 두 유형에서 같은 고아가 계속 생기고, 그 사실은 오류로
     * 드러나지 않는다. 배선이 나중에 조용히 빠지는 것도 여기서 막는다.
     *
     * @return void
     */
    public function test_all_three_managers_wire_the_rollback(): void
    {
        $targets = [
            [app_path('Extension/PluginManager.php'), 'installPlugin'],
            [app_path('Extension/ModuleManager.php'), 'installModule'],
            [app_path('Extension/TemplateManager.php'), 'installTemplate'],
        ];

        foreach ($targets as [$path, $method]) {
            $source = File::get($path);
            $body = $this->methodBody($source, $method);

            $this->assertNotSame('', $body, "{$method} 본문을 찾지 못했습니다.");
            $this->assertStringContainsString(
                'ExtensionInstallRollbackHelper::removeIfCreatedByThisInstall',
                $body,
                "{$method} 이 실패 시 활성 디렉토리를 되돌리지 않습니다."
            );
        }
    }

    /**
     * 소스에서 메서드 본문을 잘라냅니다 (중괄호 균형).
     *
     * @param  string  $source  PHP 소스 전문
     * @param  string  $method  메서드명
     * @return string 본문 (못 찾으면 빈 문자열)
     */
    private function methodBody(string $source, string $method): string
    {
        $start = strpos($source, 'public function '.$method.'(');

        if ($start === false) {
            return '';
        }

        $depth = 0;
        $began = false;
        $length = strlen($source);

        for ($i = $start; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
                $began = true;
            } elseif ($source[$i] === '}') {
                $depth--;

                if ($began && $depth === 0) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }

        return '';
    }
}

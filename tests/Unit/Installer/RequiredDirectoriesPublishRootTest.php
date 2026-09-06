<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;

/**
 * 설치 마법사의 쓰기 권한 검사 목록에 정적 게시 루트의 상위(`public/build`)가 포함된다.
 *
 * 설치 마법사는 웹 계정으로 실행되므로 다른 계정 소유의 디렉토리 권한을 스스로 고칠 수 없다.
 * 할 수 있는 것은 `REQUIRED_DIRECTORIES` 를 검사해 통과할 때까지 진행을 막고 운영자가 실행할
 * 명령을 보여 주는 것뿐이다. 그 목록에서 `public/build` 가 빠지면 마법사는 막지도 안내하지도
 * 않은 채 설치를 진행하고, 마지막 단계의 정적 게시가 `parent_not_writable` 로 실패한 뒤 대시보드에
 * 「초기 화면 파일 생성 실패」 알림이 뜬다 (7.0.10 신규 설치에서 실제 발생).
 */
class RequiredDirectoriesPublishRootTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $projectRoot = dirname(__DIR__, 3);

        if (! defined('BASE_PATH')) {
            define('BASE_PATH', $projectRoot);
        }

        if (! isset($_SERVER['SCRIPT_NAME'])) {
            $_SERVER['SCRIPT_NAME'] = '/install/index.php';
        }

        foreach (['PrivilegedDatabaseAccounts', 'OpcacheStatus'] as $supportClass) {
            if (! class_exists('App\\Support\\'.$supportClass, false)) {
                require_once $projectRoot.'/app/Support/'.$supportClass.'.php';
            }
        }

        require_once $projectRoot.'/public/install/includes/config.php';
    }

    public function test_required_directories_include_static_publish_root_parent(): void
    {
        $this->assertArrayHasKey(
            'public/build',
            REQUIRED_DIRECTORIES,
            '설치 마법사가 public/build 쓰기 권한을 검사하지 않는다 — 정적 게시가 설치 마지막 단계에서 조용히 실패한다'
        );
    }

    public function test_publish_root_parent_is_tracked_so_the_check_can_run(): void
    {
        $this->assertDirectoryExists(dirname(__DIR__, 3).'/public/build', 'public/build 가 저장소에 없으면 검사 대상 자체가 사라진다');
    }
}

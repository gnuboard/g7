<?php

namespace Tests\Unit\Helpers;

use App\Extension\Helpers\FilePermissionHelper;
use App\Services\ExtensionStaticCacheService;
use App\Support\PublicDiskHardening;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 웹서버가 직접 내주는 파일·폴더가 엄격한 umask 에서도 웹서버 계정이 읽을 수 있는 권한으로 만들어지는지 검증한다.
 *
 * 회귀: 파일·폴더 생성 퍼미션을 umask 에 맡긴 경로(코어 업데이트의 새 파일·폴더, 정적 게시 파일,
 * 공개 디스크 규칙 파일)가 umask 0027·0077 인 실행에서 0640·0600·0750 이 되어, PHP 와 다른 계정으로 도는
 * 웹서버가 403 을 냈다. POSIX 전용 — Windows 에서는 건너뛴다.
 */
class WebServedFileModeUnderUmaskTest extends TestCase
{
    private string $root;

    private int $previousUmask;

    /**
     * POSIX 가 아니면 건너뛰고, 임시 디렉토리(0755)를 만들고 현재 umask 를 보관합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX 퍼미션 전용');
        }

        $this->root = sys_get_temp_dir().'/g7-umask-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0755, true);
        chmod($this->root, 0755);
        $this->previousUmask = umask();
    }

    /**
     * umask 를 되돌리고 임시 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            umask($this->previousUmask);
            File::deleteDirectory($this->root);
        }

        parent::tearDown();
    }

    /**
     * 파일 퍼미션을 8진 문자열로 읽습니다.
     *
     * @param  string  $path  대상 경로
     * @return string 8진 퍼미션 (예: "644")
     */
    private function mode(string $path): string
    {
        clearstatcache(true, $path);

        return sprintf('%o', fileperms($path) & 0777);
    }

    /**
     * 코어 업데이트 복사가 umask 0027 에서 만든 새 폴더·파일은 부모(0755) 공개 범위를 따른다.
     *
     * @scenario seed_perms=0755, umask=0027
     *
     * @effects core_update_new_directory_and_file_follow_parent_mode
     *
     * @return void 반환값 없음
     */
    public function test_copy_directory_new_entries_follow_parent_mode_under_strict_umask(): void
    {
        $source = $this->root.'/src';
        mkdir($source.'/build/core', 0755, true);
        file_put_contents($source.'/build/core/app-abc123.js', 'x');
        $destination = $this->root.'/public';
        mkdir($destination, 0755);
        chmod($destination, 0755);

        umask(0027);
        FilePermissionHelper::copyDirectory($source, $destination);

        $this->assertSame('755', $this->mode($destination.'/build'));
        $this->assertSame('755', $this->mode($destination.'/build/core'));
        $this->assertSame('644', $this->mode($destination.'/build/core/app-abc123.js'));
    }

    /**
     * 새 파일 퍼미션은 원본의 실행 비트를 부모가 허용하는 범위에서만 남긴다.
     *
     * @scenario seed_perms=0750
     *
     * @effects core_update_new_file_keeps_source_exec_bit_within_parent
     *
     * @return void 반환값 없음
     */
    public function test_new_file_mode_keeps_exec_bit_within_parent(): void
    {
        $parent = $this->root.'/bin';
        mkdir($parent);
        chmod($parent, 0750);
        $source = $this->root.'/tool';
        file_put_contents($source, '#!/bin/sh');
        chmod($source, 0755);

        $this->assertSame('750', sprintf('%o', FilePermissionHelper::newFileModeFromParent($parent, $source)));
        $this->assertSame('640', sprintf('%o', FilePermissionHelper::newFileModeFromParent($parent)));
    }

    /**
     * 공개 디스크 규칙 파일은 umask 0077 에서도 모든 계정이 읽을 수 있다.
     *
     * @scenario umask=0077
     *
     * @effects public_disk_htaccess_readable_by_web_server
     *
     * @return void 반환값 없음
     */
    public function test_public_disk_htaccess_is_world_readable_under_strict_umask(): void
    {
        umask(0077);

        $this->assertTrue(PublicDiskHardening::ensureHtaccess($this->root));
        $this->assertSame('644', $this->mode($this->root.'/.htaccess'));
    }

    /**
     * 정적 게시 파일(작성·복사)은 umask 0077 에서도 0664 로 놓인다.
     *
     * @scenario umask=0077
     *
     * @effects static_publish_files_readable_by_web_server
     *
     * @return void 반환값 없음
     */
    public function test_static_publish_files_are_readable_under_strict_umask(): void
    {
        $service = app(ExtensionStaticCacheService::class);
        umask(0077);

        $written = $this->root.'/.htaccess';
        $put = new \ReflectionMethod($service, 'putVerified');
        $put->invoke($service, $written, 'rules');
        $this->assertSame('664', $this->mode($written));

        $source = $this->root.'/source.css';
        file_put_contents($source, 'a{}');
        $target = $this->root.'/out/source.css';
        $files = [];
        $copy = new \ReflectionMethod($service, 'copyFile');
        $copy->invokeArgs($service, [$source, $target, &$files, $this->root]);
        $this->assertSame('664', $this->mode($target));
    }
}

<?php

namespace Tests\Helpers;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Mockery;

/**
 * 확장 활성 디렉토리를 테스트 중 삭제로부터 보호하는 Trait
 *
 * ## File Facade Spy 패턴
 *
 * File 파사드의 deleteDirectory와 moveDirectory 메서드를 Mockery partial mock으로 감싸서,
 * 확장 활성 디렉토리 경로에 대한 삭제 및 이동 호출을 차단(no-op)합니다.
 * 나머지 모든 File 메서드와 다른 경로의 삭제/이동은 정상 동작합니다.
 *
 * moveDirectory 차단이 필요한 이유:
 * copyToActive()가 원자적 교체 시 활성 디렉토리를 _old_xxx로 rename한 뒤
 * _bundled 복사본으로 교체하는데, 이 rename이 차단되지 않으면
 * 테스트가 실제 활성 디렉토리를 파괴합니다.
 *
 * 확장 복사(copyToActive) 관련 테스트 작성/수정 시에도 이 패턴을 따라야 합니다:
 * - 실제 파일시스템 직접 조작 대신 File Facade를 통한 Mockery partial mock 사용
 * - File::getFacadeRoot() → Mockery::mock()->makePartial() → File::swap() 순서
 * - finally 블록에서 반드시 File::swap($original)로 원래 인스턴스 복원
 *
 * 차단 대상 경로 패턴: /(modules|plugins|templates)/[활성확장]
 * - modules/sirsoft-board → 차단 ✅
 * - plugins/sirsoft-daum_postcode → 차단 ✅
 * - templates/sirsoft-admin_basic → 차단 ✅
 * - modules/_bundled/sirsoft-board → 통과 (_ 접두사)
 * - modules/_pending/xxx → 통과 (_ 접두사)
 * - /tmp/any-other-path → 통과
 *
 * 사용법:
 * ```php
 * use Tests\Helpers\ProtectsExtensionDirectories;
 *
 * class MyTest extends TestCase
 * {
 *     use ProtectsExtensionDirectories;
 *
 *     protected function setUp(): void
 *     {
 *         parent::setUp();
 *         // ... 정당한 파일 작업 (좀비 정리, 복사 등)
 *         $this->setUpExtensionProtection();
 *         // ... Manager 로드, DB 시딩 등
 *     }
 *
 *     protected function tearDown(): void
 *     {
 *         $this->tearDownExtensionProtection();
 *         // ... 정리 작업
 *         parent::tearDown();
 *     }
 * }
 * ```
 */
trait ProtectsExtensionDirectories
{
    private Filesystem $originalFilesystem;

    /**
     * File 파사드 spy를 설정하여 확장 활성 디렉토리 삭제를 차단합니다.
     *
     * 차단 대상: modules/xxx, plugins/xxx, templates/xxx
     *            (xxx가 _ 또는 . 으로 시작하지 않는 경우)
     * 통과 대상: modules/_bundled/xxx, modules/_pending/xxx 등
     */
    protected function setUpExtensionProtection(): void
    {
        $this->originalFilesystem = File::getFacadeRoot();

        $mock = Mockery::mock($this->originalFilesystem)->makePartial();

        // 확장 활성 디렉토리 삭제 차단
        $mock->shouldReceive('deleteDirectory')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $directory, bool $preserve = false) {
                $normalized = str_replace('\\', '/', $directory);

                if (preg_match('#/(modules|plugins|templates)/(?![\._])[^/]+$#', $normalized)) {
                    return true;
                }

                return $this->originalFilesystem->deleteDirectory($directory, $preserve);
            });

        // 확장 활성 디렉토리 이동(rename) 차단
        // copyToActive()의 원자적 교체가 활성 디렉토리를 _old_xxx로 rename하는 것을 방지
        $mock->shouldReceive('moveDirectory')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $from, string $to, bool $overwrite = false) {
                $normalized = str_replace('\\', '/', $from);

                // 활성 디렉토리에서 밖으로 이동하는 것 차단 (예: templates/sirsoft-xxx → _pending/sirsoft-xxx_old_xxx)
                if (preg_match('#/(modules|plugins|templates)/(?![\._])[^/]+$#', $normalized)) {
                    return true;
                }

                return $this->originalFilesystem->moveDirectory($from, $to, $overwrite);
            });

        File::swap($mock);
    }

    /**
     * File 파사드를 원래 인스턴스로 복원합니다.
     */
    protected function tearDownExtensionProtection(): void
    {
        File::swap($this->originalFilesystem);
    }
}

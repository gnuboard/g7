<?php

namespace Tests\Unit\Services;

use App\Services\CoreUpdateService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 코어 업데이트의 쓰기 디렉토리 권한 강제가 웹서버 직접 서빙 경로를 막지 않는지 검증한다.
 *
 * 회귀: 확장 디렉토리(modules) 권한이 0770 인 사이트에서 코어 업데이트가 같은 0770 을
 * `public/build/ext` 에 강제해, php-fpm 계정과 다른 계정으로 도는 웹서버(www-data)가 정적 게시본에
 * 들어가지 못하고 `/build/ext/**` 가 전부 403 이 됐다(템플릿 외부 스타일·웹폰트 배너).
 */
class CoreUpdateServiceWebServedPermsTest extends TestCase
{
    /**
     * 웹서버가 직접 내주는 경로와 기준 권한·기대 권한 조합을 제공합니다.
     *
     * @return array<string, array{0: string, 1: int, 2: int}> 사례명 → [상대 경로, 기준 권한, 기대 권한]
     */
    public static function webServedProvider(): array
    {
        return [
            '정적 게시 루트 0770' => ['public/build/ext', 0770, 0775],
            '정적 게시 루트 0700' => ['public/build/ext', 0700, 0705],
            '역슬래시 경로' => ['public\\build\\ext', 0770, 0775],
            '앞 슬래시 경로' => ['/public/build/ext', 0770, 0775],
            '이미 0775' => ['public/build/ext', 0775, 0775],
        ];
    }

    /**
     * public/ 아래 경로는 기준 권한이 0770 이어도 other 읽기·진입(o+rx)을 남긴다.
     *
     * @scenario seed_perms=0770, storage_target=public_build_ext
     *
     * @effects web_served_writable_directory_keeps_other_read_execute
     *
     * @param  string  $relative  base_path 기준 상대 경로
     * @param  int  $reference  기준 권한
     * @param  int  $expected  기대 권한
     * @return void 반환값 없음
     */
    #[DataProvider('webServedProvider')]
    public function test_web_served_directory_keeps_other_read_and_execute(string $relative, int $reference, int $expected): void
    {
        $this->assertSame(
            sprintf('%o', $expected),
            sprintf('%o', CoreUpdateService::targetPermsForWritableDirectory($relative, $reference)),
        );
    }

    /**
     * 이미 있는 디렉토리의 other 비트는 기준 권한이 0770 이어도 지우지 않는다.
     *
     * 운영자가 쓰기 보장 목록에 `storage` 를 넣은 사이트에서 `/storage/**` 가 403 이 되는 것을 막는다.
     *
     * @scenario seed_perms=0770, storage_target=storage_root
     *
     * @effects writable_directory_keeps_existing_other_bits
     *
     * @return void 반환값 없음
     */
    public function test_existing_other_bits_are_preserved(): void
    {
        $this->assertSame('775', sprintf('%o', CoreUpdateService::targetPermsForWritableDirectory('storage', 0770, 0755)));
        $this->assertSame('770', sprintf('%o', CoreUpdateService::targetPermsForWritableDirectory('storage', 0770, 0700)));
        $this->assertSame('775', sprintf('%o', CoreUpdateService::targetPermsForWritableDirectory('public/build/ext', 0770, 0700)));
    }

    /**
     * 웹서버가 직접 읽지 않는 쓰기 경로와 기준 권한 조합을 제공합니다.
     *
     * @return array<string, array{0: string, 1: int}> 사례명 → [상대 경로, 기준 권한]
     */
    public static function privateProvider(): array
    {
        return [
            'storage/logs' => ['storage/logs', 0770],
            'modules' => ['modules', 0770],
            'bootstrap/cache' => ['bootstrap/cache', 0770],
            'publicity 같은 접두 오인 금지' => ['publicity/cache', 0770],
        ];
    }

    /**
     * public/ 밖 경로는 기준 권한을 그대로 쓴다(other 비트를 더하지 않는다).
     *
     * @scenario seed_perms=0770, storage_target=logs
     *
     * @effects non_web_served_writable_directory_keeps_reference_perms
     *
     * @param  string  $relative  base_path 기준 상대 경로
     * @param  int  $reference  기준 권한
     * @return void 반환값 없음
     */
    #[DataProvider('privateProvider')]
    public function test_non_web_served_directory_keeps_reference_perms(string $relative, int $reference): void
    {
        $this->assertSame(
            sprintf('%o', $reference),
            sprintf('%o', CoreUpdateService::targetPermsForWritableDirectory($relative, $reference)),
        );
    }
}

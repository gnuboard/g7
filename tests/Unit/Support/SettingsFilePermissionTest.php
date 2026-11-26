<?php

namespace Tests\Unit\Support;

use App\Support\SettingsFilePermission;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 사이트 설정 파일(`storage/app/settings/*.json`) 권한 판정 검증.
 *
 * 회귀 가드: 7.2.0 설치본에서 설정 파일 18개 중 `identity.json` 만 664 였다. 설정 파일을 만드는 코드가 권한을 정하지
 * 않아 만든 프로세스의 umask 를 따랐다(웹 요청·설치기 0600, 설치 중 확장 설치 하위 프로세스 0664).
 *
 * 규칙: 설정 파일 권한은 **설정 폴더가 허용하는 범위**(폴더 권한에서 실행 비트를 뺀 값)를 넘지 않는다. 범위를 넘는 비트만
 * 걷어내고 넓히지 않는다. 폴더가 이미 허용한 접근은 걷어내지 않으므로, 웹서버가 별도 계정(nobody 등)으로 「그 밖 사용자」
 * 권한을 통해 읽는 웹호스팅(폴더 755)에서도 읽기가 깨지지 않는다.
 */
class SettingsFilePermissionTest extends TestCase
{
    /**
     * 설정 파일 권한은 폴더 범위를 넘는 비트만 걷어낸다.
     *
     * @scenario file_state=created
     *
     * @effects settings_file_created_follows_directory_scope
     *
     * @param  int  $current  만든 직후(또는 지금) 파일 권한
     * @param  int  $directoryMode  설정 폴더 권한
     * @param  int  $expected  기대 권한
     * @return void 반환값 없음
     */
    #[DataProvider('createdModes')]
    public function test_created_file_follows_directory_scope(int $current, int $directoryMode, int $expected): void
    {
        $this->assertSame($expected, SettingsFilePermission::targetMode($current, $directoryMode));
    }

    /**
     * 새 파일의 변종 — 만든 프로세스의 umask 결과 × 폴더 권한.
     *
     * @return array<string, array{int, int, int}> 만든 직후 권한·폴더 권한 → 기대 권한
     */
    public static function createdModes(): array
    {
        return [
            'umask 002 로 664 · 폴더 700(이번 실측 서버) → 600' => [0664, 0700, 0600],
            'umask 002 로 664 · 폴더 770(그룹 공유 서버) → 660' => [0664, 0770, 0660],
            'umask 022 로 644 · 폴더 755(웹호스팅) → 644 그대로' => [0644, 0755, 0644],
            'umask 077 로 600 · 폴더 755 → 600 그대로(넓히지 않음)' => [0600, 0755, 0600],
            '형식 비트가 섞여도 권한만' => [0100664, 040700, 0600],
        ];
    }

    /**
     * 이미 있는 설정 파일도 같은 규칙 — 폴더 범위를 넘는 비트만 걷어낸다.
     *
     * @scenario file_state=existing
     *
     * @effects settings_file_existing_drops_bits_beyond_directory_scope
     *
     * @param  int  $current  지금 파일 권한
     * @param  int  $directoryMode  설정 폴더 권한
     * @param  int  $expected  기대 권한
     * @return void 반환값 없음
     */
    #[DataProvider('existingModes')]
    public function test_existing_file_drops_bits_beyond_directory_scope(int $current, int $directoryMode, int $expected): void
    {
        $this->assertSame($expected, SettingsFilePermission::targetMode($current, $directoryMode));
    }

    /**
     * 이미 있는 파일의 권한 변종.
     *
     * @return array<string, array{int, int, int}> 지금 권한·폴더 권한 → 기대 권한
     */
    public static function existingModes(): array
    {
        return [
            '664 · 폴더 700 → 600 (7.2.0 identity.json)' => [0664, 0700, 0600],
            '644 · 폴더 755 → 644 그대로 (웹호스팅 — nobody 읽기 유지)' => [0644, 0755, 0644],
            '666 · 폴더 770 → 660' => [0666, 0770, 0660],
        ];
    }

    /**
     * 권한을 바꿔야 하는지 판정한다 — 이미 범위 안이면 chmod 를 부르지 않는다.
     *
     * @scenario file_state=existing
     *
     * @effects settings_file_existing_drops_bits_beyond_directory_scope
     *
     * @return void 반환값 없음
     */
    public function test_needs_change_only_when_beyond_directory_scope(): void
    {
        $this->assertTrue(SettingsFilePermission::needsChange(0664, 0700));
        $this->assertFalse(SettingsFilePermission::needsChange(0600, 0700));
        $this->assertFalse(SettingsFilePermission::needsChange(0644, 0755));
    }
}

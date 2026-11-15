<?php

namespace App\Support;

use App\Contracts\Extension\StorageInterface;

/**
 * 공개 자산 디스크 해석기
 *
 * 운영자가 "이 저장소는 익명 읽기가 가능한 공개 저장소다" 라고 명시 선언한 디스크를
 * 해석하는 단일 지점입니다. `filesystems.disks.{disk}.url` 설정 유무는 "URL 문자열을
 * 만들 수 있는가" 일 뿐 "익명 읽기가 되는가" 가 아니므로 공개 판정의 근거가 될 수
 * 없습니다 — 비공개 버킷에 공개 URL 이 설정된 조합에서는 그 URL 이 403 을 돌려줍니다.
 *
 * 해석 결과는 memoize 하지 않습니다. `core.storage.public_asset_disk` 는 런타임에
 * 바뀔 수 있고(관리자 환경설정 저장, 테스트의 Config::set), 정적 캐시를 두면 그 변경이
 * 반영되지 않습니다.
 */
class PublicAssetDisk
{
    /**
     * 공개 자산 디스크 설정값을 해석합니다.
     *
     * 우선순위: 확장 개별 설정(override) > 코어 전역 설정(core.storage.public_asset_disk).
     * 미설정('')/'none'/config 에 존재하지 않는 디스크(고아 플러그인 디스크)는 null 로
     * 해석되어 호출측이 기존 디스크(스트리밍)로 폴백합니다.
     *
     * @param  string|null  $override  확장 개별 설정값 (''/null 이면 코어 전역 설정 사용)
     * @return string|null 사용할 디스크 이름 (스트리밍 유지면 null)
     */
    public static function resolve(?string $override = null): ?string
    {
        $disk = ($override !== null && $override !== '')
            ? $override
            : (string) config('core.storage.public_asset_disk', '');

        if ($disk === '' || $disk === 'none' || config("filesystems.disks.{$disk}") === null) {
            return null;
        }

        return $disk;
    }

    /**
     * 주어진 disk 가 "지금" 유효한 공개 자산 디스크인지 판정합니다.
     *
     * 직접 URL 발급 여부의 단일 판정점입니다. 반드시 resolve() 를 경유하므로
     * ''/'none'/고아 디스크가 등가 비교만으로 통과하지 않습니다.
     *
     * @param  string|null  $disk  행에 기록된 disk 값
     * @return bool 공개 자산 디스크와 일치하면 true
     */
    public static function isCurrent(?string $disk): bool
    {
        return $disk !== null && $disk !== '' && $disk === self::resolve();
    }

    /**
     * 기본 「공개 디스크로 내보낼 확장자」
     *
     * @var array<int, string>
     */
    public const DEFAULT_EXPORTABLE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

    /**
     * 공개 디스크로 내보낼 확장자 목록을 반환합니다.
     *
     * 공개 디스크의 파일은 서버(PHP)를 거치지 않고 웹서버·객체 저장소가 직접 내려보낸다. 그 주소에서는
     * 응답 헤더로 형식을 고정할 수 없으므로, 그대로 열려도 안전한 형식만 공개 디스크에 둔다.
     * 운영자는 환경설정 > 드라이버 「공개 디스크로 내보낼 확장자」 에서 목록을 바꿀 수 있다.
     * 설정이 비어 있으면 기본 목록을 쓴다.
     *
     * @return array<int, string> 확장자 목록 (소문자, 점 없음, 비어 있지 않음)
     *
     * @since 7.1.6
     */
    public static function exportableExtensions(): array
    {
        $configured = AllowedExtensions::normalize(config('core.storage.public_asset_extensions'));

        return $configured === [] ? self::DEFAULT_EXPORTABLE_EXTENSIONS : $configured;
    }

    /**
     * 주어진 확장자가 공개 디스크로 내보낼 확장자인지 판정합니다.
     *
     * @param  string  $extension  확장자 (점·대소문자 무관)
     * @return bool 목록 안이면 true
     *
     * @since 7.1.6
     */
    public static function exportsExtension(string $extension): bool
    {
        $extension = strtolower(ltrim(trim($extension), '.'));

        return $extension !== '' && in_array($extension, self::exportableExtensions(), true);
    }

    /**
     * 주어진 확장자의 파일을 저장할 공개 자산 디스크를 해석합니다.
     *
     * 공개 자산 디스크가 선언돼 있고 확장자가 「공개 디스크로 내보낼 확장자」 안일 때만 디스크 이름을
     * 돌려준다. 그 밖에는 null — 호출측이 기존(비공개) 디스크에 저장하고 서버를 거쳐 내려보낸다.
     *
     * @param  string  $extension  저장 파일의 확장자 (내용 기준으로 정한 값)
     * @param  string|null  $override  확장 개별 설정값 (''/null 이면 코어 전역 설정 사용)
     * @return string|null 사용할 공개 디스크 이름 (공개 디스크에 두지 않으면 null)
     *
     * @since 7.1.6
     */
    public static function resolveForExtension(string $extension, ?string $override = null): ?string
    {
        $disk = self::resolve($override);

        return $disk !== null && self::exportsExtension($extension) ? $disk : null;
    }

    /**
     * 올릴 파일의 확장자에 맞는 저장소를 고릅니다.
     *
     * 공개 자산 디스크를 카테고리 단위로 주입받는 업로드 서비스가 쓴다. 확장자가 「공개 디스크로 내보낼
     * 확장자」 안이면 주입 저장소 그대로, 밖이면 확장의 기본 디스크로 바꾼 저장소를 돌려준다 — 업로드는
     * 성공하고 파일은 서버를 거쳐 내려간다. 행에는 돌려받은 저장소의 `getDisk()` 를 기록한다.
     *
     * @param  StorageInterface  $storage  주입 저장소 (공개 자산 디스크일 수 있음)
     * @param  string  $extension  저장 파일의 확장자 (내용 기준으로 정한 값)
     * @param  string  $fallbackDisk  확장의 기본 디스크 이름
     * @return StorageInterface 이 파일을 저장할 저장소
     *
     * @since 7.1.6
     */
    public static function storageForExtension(StorageInterface $storage, string $extension, string $fallbackDisk): StorageInterface
    {
        if (self::exportsExtension($extension) || $storage->getDisk() === $fallbackDisk) {
            return $storage;
        }

        return $storage->withDisk($fallbackDisk);
    }

    /**
     * 행에 기록된 disk·경로에 직접 URL 을 발급해도 되는지 판정합니다.
     *
     * 행 disk 가 지금 유효한 공개 자산 디스크이고, 저장 경로의 확장자가 「공개 디스크로 내보낼 확장자」
     * 안일 때만 true. 목록 밖 확장자의 기존 행은 서버를 거치는 주소로 내려보낸다.
     *
     * @param  string|null  $rowDisk  행에 기록된 disk 값
     * @param  string  $path  저장 경로
     * @param  string|null  $override  확장 개별 설정값 (''/null 이면 코어 전역 설정 사용)
     * @return bool 직접 URL 을 발급해도 되면 true
     *
     * @since 7.1.6
     */
    public static function allowsDirectUrl(?string $rowDisk, string $path, ?string $override = null): bool
    {
        if ($rowDisk === null || $rowDisk === '' || $rowDisk !== self::resolve($override)) {
            return false;
        }

        return self::exportsExtension(self::extensionOf($path));
    }

    /**
     * 저장 계층이 이 쓰기를 거부해야 하는지 판정합니다(최후 관문).
     *
     * 대상 디스크가 Laravel `public` 디스크이거나 코어 전역 공개 자산 디스크이고, 경로의 확장자가
     * 「공개 디스크로 내보낼 확장자」 밖이면 true. 서비스 계층이 디스크를 잘못 고른 경우에도 그대로
     * 열리는 파일이 공개 디스크에 놓이지 않게 한다.
     *
     * 예외: 공개 자산 디스크가 일반 첨부 디스크(`attachment.disk`)와 같은 저장 공간이면 막지 않는다.
     * 저장소 드라이버와 공개 자산 디스크를 둘 다 객체 저장소로 고른 구성이 그렇다 — 그 디스크는 비공개
     * 첨부의 정상 저장 위치라서 막으면 pdf 같은 일반 첨부가 저장되지 않는다. 이때도 직접 주소는
     * `allowsDirectUrl()` 이 목록 안 확장자에만 내주고, 객체 저장소에는 내려받기 전용 표시가 실린다.
     * 확장 저장소도 같다 — 확장이 자기 기본 디스크로 쓰는 저장 공간(`$homeDisk`)이 공개 자산 디스크와 같으면
     * 그 디스크는 확장의 비공개 파일이 원래 놓이는 자리이므로 막지 않는다. 다른 디스크가 기본인 확장이
     * 공개 자산 디스크로 갈아 끼운 저장소는 그대로 막는다. Laravel `public` 디스크에는 이 예외가 없다.
     *
     * @param  string  $disk  쓰려는 디스크 이름
     * @param  string  $path  쓰려는 경로
     * @param  string|null  $homeDisk  쓰는 확장의 기본 디스크 (코어 저장소면 null)
     * @return bool 쓰기를 거부해야 하면 true
     *
     * @since 7.1.6
     */
    public static function refusesWrite(string $disk, string $path, ?string $homeDisk = null): bool
    {
        if ($disk !== 'public' && $disk !== self::resolve()) {
            return false;
        }

        if ($disk !== 'public' && ($disk === (string) config('attachment.disk', '') || $disk === $homeDisk)) {
            return false;
        }

        return ! self::exportsExtension(self::extensionOf($path));
    }

    /**
     * 경로의 마지막 확장자를 소문자로 반환합니다.
     *
     * @param  string  $path  파일 경로
     * @return string 확장자 (없으면 빈 문자열)
     */
    private static function extensionOf(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }
}

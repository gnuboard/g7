<?php

namespace App\Support;

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
}

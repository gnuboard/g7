<?php

namespace App\Support;

use App\Extension\AbstractModule;

/**
 * 비공개 첨부 저장소 디스크 해석기 
 *
 * 권한 확인 뒤 서버가 내려주는 비공개 원본(코어·게시판·페이지 첨부, 클레임 이미지, 본문 미디어, 내보내기 파일)을 어느 디스크에
 * 저장할지 정합니다. 관리자는 환경설정 > 드라이버 「비공개 첨부 저장소」에서 고르고, `SettingsServiceProvider` 가 그 값을
 * `core.storage.private_attachment_disk` 로 주입합니다(「기본」은 빈 문자열).
 *
 * - 「기본」(`default`·빈 값)이면 null — 호출측이 지금까지의 디스크를 쓴다(업데이트 후 기존 동작 불변).
 * - 고른 디스크가 `filesystems.disks` 에 정의돼 있지 않으면(디스크를 등록한 플러그인이 꺼짐) null — 같은 폴백.
 * - 해석 결과는 memoize 하지 않는다. 설정은 런타임에 바뀔 수 있고(관리자 저장, 테스트), 플러그인 디스크는 부팅이 끝난 뒤
 *   (`app->booted`) 정의되므로 쓰는 시점에 판정해야 한다.
 * - 코어 config 만 읽는다 — 확장 설정을 읽지 않으므로 확장 저장소 해석과 재귀 고리를 만들지 않는다.
 *
 * {@see PublicAssetDisk} 와 같은 꼴이다.
 *
 * @since 7.2.0
 */
class PrivateAttachmentDisk
{
    /** 「기본(사이트 스토리지 드라이버를 따름)」 선택값 */
    public const DEFAULT = 'default';

    /**
     * 관리자가 고른 비공개 첨부 저장소 디스크를 해석합니다.
     *
     * @return string|null 고른 디스크(「기본」·미정의 디스크면 null)
     */
    public static function resolve(): ?string
    {
        $disk = (string) config('core.storage.private_attachment_disk', '');

        if ($disk === '' || $disk === self::DEFAULT || config("filesystems.disks.{$disk}") === null) {
            return null;
        }

        return $disk;
    }

    /**
     * 코어 첨부(`attachments` 테이블)의 업로드 디스크를 정합니다.
     *
     * 우선순위: `ATTACHMENT_DISK` env 명시 > 비공개 첨부 저장소 선택 > (「기본」일 때) 지금까지의 규칙
     * (`storage_driver` 가 s3 이면 s3, 아니면 `attachments`) — 마지막 값은 `SettingsServiceProvider` 가 `attachment.disk` 에 미리 계산한다.
     *
     * @return string 업로드 디스크
     */
    public static function forCore(): string
    {
        $explicit = config('attachment.disk_explicit');
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        return self::resolve() ?? (string) config('attachment.disk', 'attachments');
    }

    /**
     * 확장의 비공개 원본 디스크를 정합니다 — 선택이 없으면 확장 기본 디스크.
     *
     * 확장별 env 고정(게시판 `SIRSOFT_BOARD_ATTACHMENT_DISK`)은 확장이 {@see AbstractModule::getPrivateAttachmentDiskPin()}
     * 으로 먼저 판정한다.
     *
     * @param  string  $extensionDefault  확장 기본 디스크(모듈 `modules`, 플러그인 `plugins`)
     * @return string 디스크 이름
     */
    public static function forExtension(string $extensionDefault): string
    {
        return self::resolve() ?? $extensionDefault;
    }

    /**
     * 관리자가 고른 값이 「기본」이 아닌데 이 서버에서 쓸 수 없는 디스크인지 확인합니다(화면 「사용 불가」 안내용).
     *
     * @return bool 고른 디스크가 정의돼 있지 않으면 true
     */
    public static function isOrphaned(): bool
    {
        $disk = (string) config('core.storage.private_attachment_disk', '');

        return $disk !== '' && $disk !== self::DEFAULT && config("filesystems.disks.{$disk}") === null;
    }
}

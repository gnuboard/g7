<?php

namespace Modules\Sirsoft\Page\Database\Seeders;

/**
 * 페이지 시드 한 번의 실행에서 시더가 새로 만든 페이지·첨부를 기록한다
 *
 * 활동 로그 샘플은 「이번 실행에서 시더가 만든」 페이지·첨부에만 로그를 만든다. 운영자가 만든 페이지나
 * 이미 있던 기본 페이지에 생성·발행 로그를 지어내면 실제 운영자 명의로 하지 않은 행동의 감사 기록이 생긴다.
 * 설치 시더(PageSeeder)가 실행마다 초기화한다.
 */
final class PageSampleRunRegistry
{
    /**
     * 이번 실행에서 만든 페이지 ID
     *
     * @var array<int, int>
     */
    private static array $pageIds = [];

    /**
     * 이번 실행에서 만든 첨부 ID
     *
     * @var array<int, int>
     */
    private static array $attachmentIds = [];

    /**
     * 기록을 비웁니다 (실행 시작).
     *
     * @return void 반환값 없음
     */
    public static function reset(): void
    {
        self::$pageIds = [];
        self::$attachmentIds = [];
    }

    /**
     * 만든 페이지 ID 를 더합니다.
     *
     * @param  int  $pageId  페이지 ID
     * @return void 반환값 없음
     */
    public static function addPage(int $pageId): void
    {
        self::$pageIds[] = $pageId;
    }

    /**
     * 만든 첨부 ID 를 더합니다.
     *
     * @param  int  $attachmentId  첨부 ID
     * @return void 반환값 없음
     */
    public static function addAttachment(int $attachmentId): void
    {
        self::$attachmentIds[] = $attachmentId;
    }

    /**
     * 이번 실행에서 만든 페이지 ID 목록.
     *
     * @return array<int, int> 페이지 ID
     */
    public static function pageIds(): array
    {
        return array_values(array_unique(self::$pageIds));
    }

    /**
     * 이번 실행에서 만든 첨부 ID 목록.
     *
     * @return array<int, int> 첨부 ID
     */
    public static function attachmentIds(): array
    {
        return array_values(array_unique(self::$attachmentIds));
    }
}

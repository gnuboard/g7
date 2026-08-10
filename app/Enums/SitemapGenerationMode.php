<?php

namespace App\Enums;

/**
 * 사이트맵 재생성 모드 Enum
 *
 * SitemapManager::regenerate 와 GenerateSitemapJob, seo:generate-sitemap 커맨드가
 * 공유하는 재생성 모드 도메인입니다.
 *
 * - Full: 저장소 상태와 무관하게 각 기여자를 스트리밍해 sitemap_urls 를 전량 대체한 뒤 파일 재작성.
 * - Auto: 저장소가 비어 있으면 Full, 아니면 Incremental 로 동작(스케줄러 기본).
 * - Incremental: 기여자 재쿼리 없이 저장소의 현재 델타만으로 파일 재작성.
 */
enum SitemapGenerationMode: string
{
    /**
     * 전체 재생성 (관리자 수동 = 항상 Full)
     */
    case Full = 'full';

    /**
     * 자동 판정 (빈 저장소=Full / 채워짐=Incremental)
     */
    case Auto = 'auto';

    /**
     * 증분 재생성 (저장소 델타만 파일로 반영)
     */
    case Incremental = 'incremental';

    /**
     * 저장소 상태를 반영해 실제 실행 모드(Full 또는 Incremental)로 해석합니다.
     *
     * Auto 는 저장소가 비어 있으면 Full, 아니면 Incremental 로 해석됩니다.
     *
     * @param  int  $visibleCount  저장소의 공개 URL 수
     * @return self 실제 실행 모드 (Full | Incremental)
     */
    public function resolve(int $visibleCount): self
    {
        return match ($this) {
            self::Full => self::Full,
            self::Incremental => self::Incremental,
            self::Auto => $visibleCount === 0 ? self::Full : self::Incremental,
        };
    }
}

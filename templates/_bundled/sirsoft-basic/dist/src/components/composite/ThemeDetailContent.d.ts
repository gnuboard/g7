import { default as React } from 'react';
import { SpecRow } from './BuyCardSticky';
import { ChangelogEntry } from './ChangelogTimeline';
export interface ThemeDetailContentProps {
    /** 게시판 글 ID (post-nav, breadcrumb 링크 등에 사용) */
    id?: number | string;
    /** 카테고리 (BUSINESS 등) */
    category?: string;
    /** 글 제목 (= 테마명) */
    title: string;
    /** 한 줄 요약 — buy-card 와 본문 상단에 사용 */
    summary?: string;
    /** 상세 설명 본문 (HTML, <br> 등 허용) */
    description?: string;
    /** 갤러리 이미지 배열 */
    gallery?: string[];
    /** 포함 구성 글머리표 */
    features?: string[];
    /** 변경 이력 */
    changelog?: ChangelogEntry[];
    /** 안내 callout (설치 안내 등) — HTML 허용 */
    installNote?: string;
    /** 가격 */
    price?: number | string;
    /** 평점/리뷰 */
    rating?: number;
    reviewCount?: number;
    /** 구매·데모 링크 */
    purchaseHref?: string;
    demoHref?: string;
    /** 사양 행 */
    specs?: SpecRow[];
    /** post-nav (이전·다음 글) */
    prevPost?: {
        id: number | string;
        title: string;
    };
    nextPost?: {
        id: number | string;
        title: string;
    };
    /** 목록 페이지 href */
    listHref?: string;
    /** 게시판 base href — post-nav 링크 구성용 */
    baseHref?: string;
    /** 상세 페이지 상단에 표시되는 게시판명 (큰 헤딩) */
    boardLabel?: string;
    /** 수정 버튼 노출 — post.is_owner 또는 abilities.write 바인딩 */
    canEdit?: boolean;
    /** 삭제 버튼 노출 — post.is_owner 또는 abilities.delete 바인딩 */
    canDelete?: boolean;
    /** 수정 페이지 URL — 기본 어드민 수정 화면 */
    editHref?: string;
    /** 삭제 API endpoint */
    deleteEndpoint?: string;
    /** 삭제 후 이동할 URL */
    afterDeleteHref?: string;
}
declare const ThemeDetailContent: React.FC<ThemeDetailContentProps>;
export default ThemeDetailContent;

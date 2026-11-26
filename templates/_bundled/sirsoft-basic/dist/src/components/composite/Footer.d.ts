import { default as React } from 'react';
interface SocialLinks {
    youtube?: string;
    instagram?: string;
    facebook?: string;
    x?: string;
    github?: string;
    discord?: string;
    blog?: string;
    /** 옛 이름 — `x` 와 같은 아이콘 */
    twitter?: string;
}
interface FooterLink {
    label: string;
    href: string;
}
interface FooterLinkGroup {
    title: string;
    links: FooterLink[];
}
interface FooterProps {
    /** 사이트 이름 */
    siteName?: string;
    /** 사이트 설명 */
    siteDescription?: string;
    /** 저작권 텍스트 — 운영자가 템플릿 설정(`footer.copyright`, 언어별)에 넣은 문구. 비면 기본 문구 */
    copyrightText?: string;
    /** 기본 저작권 문구 — 번역 문구(`footer.copyright`). 운영자 문구가 없을 때 쓴다 */
    defaultCopyrightText?: string;
    /** 소셜 미디어 링크 — 템플릿 설정 `footer.social_*` (빈 값은 그리지 않는다) */
    socialLinks?: SocialLinks;
    /** 「Powered by 그누보드7」 을 보일지 — 기본 true (템플릿 설정 `footer.show_powered_by`) */
    showPoweredBy?: boolean;
    /** 링크 그룹 (미지정 시 기본값 사용 — 직접 넘기면 아래 모듈 플래그와 무관하게 그대로 그린다) */
    linkGroups?: FooterLinkGroup[];
    /**
     * 게시판 모듈이 켜져 있는가 — false 면 기본 링크 그룹에서 「커뮤니티」 묶음을 뺀다.
     * 기본값 true: 레이아웃이 `_global.activeModules` 로 판정해 넘긴다. 값이 오지 않는 경우(루트 레이아웃을 keep 으로
     * 업데이트해 새 바인딩이 없는 사이트)에는 지금처럼 보인다.
     */
    boardEnabled?: boolean;
    /** 페이지 모듈이 켜져 있는가 — false 면 기본 링크 그룹에서 「정보」·「정책」 묶음(모두 페이지 모듈 문서)을 뺀다. 기본값 true(위와 같은 이유) */
    pageEnabled?: boolean;
    /**
     * 쇼핑몰 사업자 정보(법정 표시 항목) — 값이 있는 항목만 「라벨: 값」 줄로 그린다. 모두 비었거나 값이 오지 않으면
     * 블록을 그리지 않는다. 여기서는 값 유무만 본다.
     * 기본 레이아웃(`_user_base`)은 이 값을 넘기지 않는다 — 쇼핑몰 모듈이 `user_global_footer` 자리에 사업자 정보를 직접 그리므로
     * 여기서도 그리면 두 번 나온다. 속성은 7.1.6 에서 공개한 계약이라 남겨 둔다 — 이 값을 바인딩한 레이아웃은 쇼핑몰 조각과
     * 함께 두 번 그리게 되므로 바인딩을 걷는다.
     */
    businessInfo?: FooterBusinessInfo | null;
    /** 추가 CSS 클래스 */
    className?: string;
    /** 레이아웃 편집기 식별 속성(data-editor-*) — 시각적 루트에 spread */
    editorAttrs?: Record<string, unknown>;
}
/** 푸터에 싣는 사업자 정보 (전자상거래법 사이버몰 표시 항목) */
export interface FooterBusinessInfo {
    company_name?: string | null;
    ceo_name?: string | null;
    zipcode?: string | null;
    base_address?: string | null;
    detail_address?: string | null;
    phone?: string | null;
    email?: string | null;
    business_number?: string | null;
    mail_order_number?: string | null;
    hosting_provider?: string | null;
}
/**
 * 사이트 푸터 컴포넌트
 *
 * @example
 * ```json
 * // 레이아웃 JSON에서 사용
 * {
 *   "type": "composite",
 *   "name": "Footer",
 *   "props": {
 *     "siteName": "{{_global.settings.site_name}}",
 *     "siteDescription": "{{_global.settings.site_description}}",
 *     "copyrightText": "{{_global.settings.copyright_text}}",
 *     "socialLinks": {
 *       "github": "https://github.com/...",
 *       "twitter": "https://twitter.com/...",
 *       "discord": "https://discord.gg/..."
 *     }
 *   }
 * }
 * ```
 */
declare const Footer: React.FC<FooterProps>;
export default Footer;

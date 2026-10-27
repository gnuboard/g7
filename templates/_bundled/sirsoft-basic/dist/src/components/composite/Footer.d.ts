import { default as React } from 'react';
interface SocialLinks {
    github?: string;
    twitter?: string;
    discord?: string;
    facebook?: string;
    instagram?: string;
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
    /** 저작권 텍스트 */
    copyrightText?: string;
    /** 소셜 미디어 링크 */
    socialLinks?: SocialLinks;
    /** 링크 그룹 (미지정 시 기본값 사용 — 직접 넘기면 아래 모듈 플래그와 무관하게 그대로 그린다) */
    linkGroups?: FooterLinkGroup[];
    /**
     * 게시판 모듈이 켜져 있는가 — false 면 기본 링크 그룹에서 「커뮤니티」 묶음을 뺀다.
     * 기본값 true: 레이아웃이 `_global.activeModules` 로 판정해 넘긴다. 값이 오지 않는 경우(루트 레이아웃을 keep 으로
     * 업데이트해 새 바인딩이 없는 사이트)에는 지금처럼 보인다(#822).
     */
    boardEnabled?: boolean;
    /** 페이지 모듈이 켜져 있는가 — false 면 기본 링크 그룹에서 「정보」·「정책」 묶음(모두 페이지 모듈 문서)을 뺀다. 기본값 true(위와 같은 이유) */
    pageEnabled?: boolean;
    /** 추가 CSS 클래스 */
    className?: string;
    /** 레이아웃 편집기 식별 속성(data-editor-*) — 시각적 루트에 spread */
    editorAttrs?: Record<string, unknown>;
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

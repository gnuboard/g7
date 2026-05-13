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
    /** 링크 그룹 (미지정 시 기본값 사용) */
    linkGroups?: FooterLinkGroup[];
    /** 추가 CSS 클래스 */
    className?: string;
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

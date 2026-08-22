/**
 * Footer 컴포넌트
 *
 * 사이트 하단 푸터 컴포넌트입니다.
 * 사이트 정보, 링크 그룹, 소셜 링크, 저작권 표시를 포함합니다.
 *
 * @see 화면 구성:
 * ┌─────────────────────────────────────────────────────────────────┐
 * │ [사이트명]                                                       │
 * │ 함께 성장하는 커뮤니티                                            │
 * ├────────────┬────────────┬────────────┬────────────────────────────┤
 * │ 커뮤니티    │ 정보       │ 정책       │ [GitHub] [Twitter] [Discord]│
 * ├─────────────────────────────────────────────────────────────────┤
 * │ © 2026 사이트명. All rights reserved.        Made with ❤️        │
 * └─────────────────────────────────────────────────────────────────┘
 */

import React from 'react';

// 기본 컴포넌트 import
import { Div } from '../basic/Div';
import { A } from '../basic/A';
import { Button } from '../basic/Button';
import { H3 } from '../basic/H3';
import { H4 } from '../basic/H4';
import { P } from '../basic/P';
import { Ul } from '../basic/Ul';
import { Li } from '../basic/Li';
import { Footer as FooterBasic } from '../basic/Footer';
import { Icon } from '../basic/Icon';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

// G7 표준 반응형 breakpoint — 모바일(< 768) / 데스크톱(>= 1024).
// 레이아웃 편집기 프리뷰 호환을 위해 Tailwind md:/lg:/sm: 미디어쿼리 대신
// G7Core.useResponsive() width 기반 분기 사용 (편집 가능 템플릿은 G7 표준
// responsive 만 허용 — viewport 미디어쿼리는 프리뷰 overrideWidth 를 무시).
const TABLET_BREAKPOINT = 768;
const DESKTOP_BREAKPOINT = 1024;

// G7Core.dispatch() navigate 헬퍼
const navigate = (path: string) => {
  (window as any).G7Core?.dispatch?.({
    handler: 'navigate',
    params: { path },
  });
};

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
const Footer: React.FC<FooterProps> = ({
  siteName = '그누보드7',
  siteDescription,
  copyrightText,
  socialLinks = {},
  linkGroups,
  className = '',
  editorAttrs,
}) => {
  const currentYear = new Date().getFullYear();

  // G7 표준 반응형 — useResponsive() 로 프리뷰 overrideWidth 수신 (편집기 디바이스
  // 전환에 정상 반응). hook 미주입 시 window.innerWidth fallback.
  const G7Core = (window as any).G7Core;
  const useResponsive = G7Core?.useResponsive as (() => { width: number }) | undefined;
  const responsiveValue = useResponsive?.();
  const width =
    responsiveValue?.width ??
    (typeof window !== 'undefined' ? window.innerWidth : DESKTOP_BREAKPOINT);
  const isMobile = width < TABLET_BREAKPOINT;
  const isDesktop = width >= DESKTOP_BREAKPOINT;
  // 링크 그룹 그리드 컬럼 — 모바일 1열 / 태블릿 2열 / 데스크톱 5열.
  const gridColumns = isMobile ? 1 : isDesktop ? 5 : 2;

  // 기본 링크 그룹
  const defaultLinkGroups: FooterLinkGroup[] = [
    {
      title: t('footer.community'),
      links: [
        { label: t('nav.home'), href: '/' },
        { label: t('nav.popular'), href: '/boards/popular' },
        { label: t('footer.all_boards'), href: '/boards' },
      ],
    },
    {
      title: t('footer.info'),
      links: [
        { label: t('footer.about'), href: '/page/about' },
        { label: t('footer.faq'), href: '/page/faq' },
        { label: t('footer.contact'), href: '/page/contact' },
      ],
    },
    {
      title: t('footer.policy'),
      links: [
        { label: t('footer.terms'), href: '/page/terms' },
        { label: t('footer.privacy'), href: '/page/privacy' },
        { label: t('footer.refund'), href: '/page/refund' },
      ],
    },
  ];

  const groups = linkGroups || defaultLinkGroups;

  // 소셜 아이콘 이름 매핑
  const socialIconMap: Record<keyof SocialLinks, string> = {
    github: 'github',
    twitter: 'twitter',
    discord: 'discord',
    facebook: 'facebook',
    instagram: 'instagram',
  };

  return (
    <FooterBasic
      {...((editorAttrs ?? {}) as Record<string, never>)}
      className={`bg-gray-50 dark:bg-gray-900 border-t border-gray-200 dark:border-gray-800 ${className}`}
    >
      <Div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <Div
          className="grid gap-8"
          style={{ gridTemplateColumns: `repeat(${gridColumns}, minmax(0, 1fr))` }}
        >
          {/* 사이트 정보 — 데스크톱(5열)에서만 2칸 차지 */}
          <Div style={isDesktop ? { gridColumn: 'span 2 / span 2' } : undefined}>
            <H3 className="text-lg font-bold text-gray-900 dark:text-white">{siteName}</H3>
            {siteDescription && (
              <P className="mt-2 text-sm text-gray-600 dark:text-gray-400">{siteDescription}</P>
            )}

            {/* 소셜 링크 */}
            <Div className="mt-4 flex items-center gap-4">
              {Object.entries(socialLinks).map(([type, url]) =>
                url ? (
                  <A
                    key={type}
                    href={url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                    aria-label={type}
                  >
                    <Icon name={socialIconMap[type as keyof SocialLinks]} className="w-5 h-5" />
                  </A>
                ) : null
              )}
            </Div>
          </Div>

          {/* 링크 그룹 */}
          {groups.map((group, index) => (
            <Div key={index}>
              <H4 className="text-sm font-semibold text-gray-900 dark:text-white uppercase tracking-wider">
                {group.title}
              </H4>
              <Ul className="mt-4 space-y-2">
                {group.links.map((link, linkIndex) => (
                  <Li key={linkIndex}>
                    <Button
                      onClick={() => navigate(link.href)}
                      className="text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white cursor-pointer"
                    >
                      {link.label}
                    </Button>
                  </Li>
                ))}
              </Ul>
            </Div>
          ))}
        </Div>

        {/* 저작권 */}
        <Div
          className="mt-8 pt-8 border-t border-gray-200 dark:border-gray-800 flex justify-between items-center gap-4"
          style={{ flexDirection: isMobile ? 'column' : 'row' }}
        >
          <P className="text-sm text-gray-500 dark:text-gray-400">
            {copyrightText || `© ${currentYear} ${siteName}. All rights reserved.`}
          </P>
          <P className="text-sm text-gray-500 dark:text-gray-400">
            {t('footer.powered_by')}
          </P>
        </Div>
      </Div>
    </FooterBasic>
  );
};

export default Footer;

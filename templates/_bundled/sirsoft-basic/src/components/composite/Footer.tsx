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
import { LinkButton } from '../../support/LinkButton';
import { H3 } from '../basic/H3';
import { H4 } from '../basic/H4';
import { P } from '../basic/P';
import { Ul } from '../basic/Ul';
import { Li } from '../basic/Li';
import { Footer as FooterBasic } from '../basic/Footer';
import { Icon } from '../basic/Icon';
import { BREAKPOINTS, containerPaddingClass, useViewportWidth } from '../layout/viewportWidth';
import { Span } from '../basic/Span';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

// G7 표준 반응형 breakpoint — 모바일(< 768) / 데스크톱(>= 1024).
// 레이아웃 편집기 프리뷰 호환을 위해 Tailwind 반응형 접두(미디어 쿼리) 대신
// G7Core.useResponsive() width 기반 분기 사용 (편집 가능 템플릿은 G7 표준
// responsive 만 허용 — viewport 미디어쿼리는 프리뷰 overrideWidth 를 무시).
const TABLET_BREAKPOINT = BREAKPOINTS.md;
const DESKTOP_BREAKPOINT = BREAKPOINTS.lg;

// G7Core.dispatch() navigate 헬퍼
const navigate = (path: string) => {
  (window as any).G7Core?.dispatch?.({
    handler: 'navigate',
    params: { path },
  });
};

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

/**
 * 소셜 링크 아이콘 — 상표 아이콘은 Font Awesome brands(`fab`) 묶음이라 스타일을 함께 준다.
 * 블로그는 상표가 없어 일반(solid) 아이콘이다.
 */
const SOCIAL_ICONS: Record<keyof SocialLinks, { name: string; style: 'brands' | 'solid'; label: string }> = {
  youtube: { name: 'youtube', style: 'brands', label: 'YouTube' },
  instagram: { name: 'instagram', style: 'brands', label: 'Instagram' },
  facebook: { name: 'facebook', style: 'brands', label: 'Facebook' },
  x: { name: 'x-twitter', style: 'brands', label: 'X' },
  twitter: { name: 'x-twitter', style: 'brands', label: 'X' },
  github: { name: 'github', style: 'brands', label: 'GitHub' },
  discord: { name: 'discord', style: 'brands', label: 'Discord' },
  blog: { name: 'blog', style: 'solid', label: 'Blog' },
};

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

/** 푸터 사업자 정보 한 줄 */
interface BusinessLine {
  key: string;
  label: string;
  value: string;
  href?: string;
}

/**
 * 사업자 정보에서 값이 있는 항목만 표시 줄로 만든다.
 *
 * @param info 사업자 정보 (없으면 빈 목록)
 * @returns 표시할 줄 목록 (법정 표시 순서)
 */
function buildBusinessLines(info: FooterBusinessInfo | null | undefined): BusinessLine[] {
  if (!info || typeof info !== 'object') return [];

  const text = (value: unknown): string => (typeof value === 'string' ? value.trim() : '');
  const zipcode = text(info.zipcode);
  const address = [text(info.base_address), text(info.detail_address)].filter(Boolean).join(' ');
  const phone = text(info.phone);
  const email = text(info.email);

  const lines: BusinessLine[] = [
    { key: 'company_name', label: t('footer.business.company_name'), value: text(info.company_name) },
    { key: 'ceo_name', label: t('footer.business.ceo_name'), value: text(info.ceo_name) },
    { key: 'address', label: t('footer.business.address'), value: address ? (zipcode ? `(${zipcode}) ${address}` : address) : '' },
    { key: 'phone', label: t('footer.business.phone'), value: phone, href: phone ? `tel:${phone}` : undefined },
    { key: 'email', label: t('footer.business.email'), value: email, href: email ? `mailto:${email}` : undefined },
    { key: 'business_number', label: t('footer.business.business_number'), value: text(info.business_number) },
    { key: 'mail_order_number', label: t('footer.business.mail_order_number'), value: text(info.mail_order_number) },
    { key: 'hosting_provider', label: t('footer.business.hosting_provider'), value: text(info.hosting_provider) },
  ];

  return lines.filter((line) => line.value !== '');
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
  defaultCopyrightText,
  businessInfo,
  socialLinks = {},
  showPoweredBy = true,
  linkGroups,
  boardEnabled = true,
  pageEnabled = true,
  className = '',
  editorAttrs,
}) => {
  const currentYear = new Date().getFullYear();

  // G7 표준 반응형 — useResponsive() 로 프리뷰 overrideWidth 수신 (편집기 디바이스
  // 전환에 정상 반응). hook 미주입 시 window.innerWidth, 둘 다 없으면 엔진 기본 폭(1024).
  const width = useViewportWidth();
  const businessLines = buildBusinessLines(businessInfo);
  const isMobile = width < TABLET_BREAKPOINT;
  const isDesktop = width >= DESKTOP_BREAKPOINT;
  // 링크 그룹 그리드 컬럼 — 모바일 1열 / 태블릿 2열 / 데스크톱 5열.
  const gridColumns = isMobile ? 1 : isDesktop ? 5 : 2;

  // 기본 링크 그룹 — 커뮤니티는 게시판 모듈, 정보·정책은 페이지 모듈 문서라 꺼진 모듈의 묶음은 뺀다
  const communityGroup: FooterLinkGroup = {
    title: t('footer.community'),
    links: [
      { label: t('nav.home'), href: '/' },
      { label: t('nav.popular'), href: '/boards/popular' },
      { label: t('footer.all_boards'), href: '/boards' },
    ],
  };
  const pageGroups: FooterLinkGroup[] = [
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

  const defaultLinkGroups: FooterLinkGroup[] = [
    ...(boardEnabled ? [communityGroup] : []),
    ...(pageEnabled ? pageGroups : []),
  ];

  const groups = linkGroups || defaultLinkGroups;

  return (
    <FooterBasic
      {...((editorAttrs ?? {}) as Record<string, never>)}
      className={`bg-gray-50 dark:bg-gray-900 border-t border-gray-200 dark:border-gray-800 ${className}`}
    >
      <Div className={`max-w-7xl mx-auto ${containerPaddingClass(width)} py-12`}>
        <Div
          className="grid gap-8"
          style={{ gridTemplateColumns: `repeat(${gridColumns}, minmax(0, 1fr))` }}
        >
          {/* 사이트 정보 — 데스크톱(5열)에서만 2칸 차지 */}
          <Div style={isDesktop ? { gridColumn: 'span 2 / span 2' } : undefined}>
            {/* 이름·설명은 줄을 바꿔 모두 보인다 — 공백 없는 긴 이름도 칸을 넘지 않는다(#143). */}
            <H3 className="text-lg font-bold text-gray-900 dark:text-white break-words">{siteName}</H3>
            {siteDescription && (
              <P className="mt-2 text-sm text-gray-600 dark:text-gray-400 break-words">{siteDescription}</P>
            )}

            {/* 소셜 링크 — 아는 종류만, 값이 있는 것만 */}
            <Div className="mt-4 flex flex-wrap items-center gap-4" data-testid="footer-social-links">
              {Object.entries(socialLinks).map(([type, url]) => {
                const icon = SOCIAL_ICONS[type as keyof SocialLinks];

                return url && icon ? (
                  <A
                    key={type}
                    href={url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-muted hover:text-gray-700 dark:hover:text-gray-200"
                    aria-label={icon.label}
                  >
                    <Icon name={icon.name} iconStyle={icon.style} className="w-5 h-5" />
                  </A>
                ) : null;
              })}
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
                    <LinkButton
                      href={link.href}
                      onNavigate={() => navigate(link.href)}
                      className="text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white cursor-pointer"
                    >
                      {link.label}
                    </LinkButton>
                  </Li>
                ))}
              </Ul>
            </Div>
          ))}
        </Div>

        {/* 저작권 */}
        {/* 사업자 정보(법정 표시 항목) — 값이 있는 항목만, 저작권 줄 위 */}
        {businessLines.length > 0 && (
          <Div
            data-footer-business=""
            className="mt-8 pt-6 border-t border-gray-200 dark:border-gray-800 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted"
          >
            {businessLines.map((line) => (
              <Span key={line.key} data-footer-business-item={line.key} className="min-w-0 wrap-anywhere">
                {line.label}: {line.href ? (
                  <A href={line.href} className="hover:text-gray-700 dark:hover:text-gray-200 hover:underline">{line.value}</A>
                ) : line.value}
              </Span>
            ))}
          </Div>
        )}
        <Div
          className="mt-8 pt-8 border-t border-gray-200 dark:border-gray-800 flex justify-between items-center gap-4"
          style={{ flexDirection: isMobile ? 'column' : 'row' }}
        >
          {/* 저작권 줄은 「Powered by」 와 같은 flex 줄이라 줄어들 수 있게 하고 아무 데서나 줄을 바꾼다(#143). */}
          <P className="text-sm text-muted min-w-0 wrap-anywhere">
            {copyrightText || defaultCopyrightText || `© ${currentYear} ${siteName}. All rights reserved.`}
          </P>
          {showPoweredBy && (
            <P className="text-sm text-muted" data-testid="footer-powered-by">
              {t('footer.powered_by')}
            </P>
          )}
        </Div>
      </Div>
    </FooterBasic>
  );
};

export default Footer;

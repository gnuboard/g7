import React from 'react';
import { handleLinkClick, mergeQueryIntoHref, mergeRelForTarget, navigateTo, resolveLinkHref, useLocationSearch } from '../../support/navigationLink';

export interface AProps extends React.AnchorHTMLAttributes<HTMLAnchorElement> {
  /**
   * 비활성 링크 — href 를 빼고 `aria-disabled="true"`·`tabindex="-1"`, 클릭은 아무 일도 하지 않는다.
   * 단추의 `disabled` 와 같은 뜻이다(`<a>` 에는 `:disabled` 가상 클래스가 없으므로 꾸밈은 `aria-disabled:` 변형으로).
   */
  disabled?: boolean;
  /** 현재 주소의 쿼리를 href 에 합친다(목록 문맥 유지) — 보통 클릭과 새 탭이 같은 주소로 간다 */
  mergeQuery?: boolean;
  /**
   * 대체 주소 — href 가 비지 않았는데 이 사이트 주소가 아니면(실행 주소·다른 사이트·`//host`) 대신 이 주소를 href 로 단다.
   * 알림 이동 주소처럼 바깥에서 정해지는 href 가 가운데 클릭·「새 탭에서 열기」로 그대로 열리지 않게 한다 — 보통 클릭의
   * `navigate fallbackPath` 와 같은 착지점을 적는다(7.1.1). 비운 href 는 그대로 둔다(링크 아님).
   */
  fallbackHref?: string;
}

/**
 * href 가 이 사이트 주소가 아니면 대체 주소를 돌려줍니다.
 *
 * @param href 그릴 href
 * @param fallbackHref 대체 주소(없으면 href 그대로)
 * @return 실제로 달 href
 */
function resolveFallbackHref(href: AProps['href'], fallbackHref: string | undefined): AProps['href'] {
  if (fallbackHref === undefined || typeof href !== 'string' || href.trim() === '') return href;
  const link = resolveLinkHref(href);

  return link && !link.external ? href : fallbackHref;
}

/**
 * 기본 a(링크) 컴포넌트
 *
 * 화면 이동을 링크로 그려 가운데 클릭·Ctrl+클릭·우클릭 「새 탭에서 열기」가 되게 한다(#757).
 * - 레이아웃 액션(`<on type="click">`)이 있으면 그 액션이 클릭을 맡는다 — 수정키 클릭을 막지 않는 판정은 엔진이 한다.
 * - 액션이 없는 내부 링크는 보통 클릭만 SPA 로 옮기고(전체 다시 부르기 방지), 나머지는 브라우저 기본 동작이다.
 * - `target="_blank"` 면 rel 에 `noopener noreferrer` 를 합친다(넘겨받은 rel 토큰은 남긴다).
 */
export const A: React.FC<AProps> = ({
  children,
  className = '',
  href,
  disabled = false,
  mergeQuery = false,
  fallbackHref,
  onClick,
  target,
  rel,
  ...props
}) => {
  // 쿼리만 바꾸는 같은 화면 이동 뒤에도 링크 href 가 새 쿼리를 합치도록 주소를 구독한다(props 가 같아 엔진이 다시 그리지 않는다)
  useLocationSearch(mergeQuery && !disabled && typeof href === 'string');
  const finalRel = mergeRelForTarget(target, rel);
  if (disabled) {
    return (
      <a
        className={className}
        target={target}
        rel={finalRel}
        {...props}
        role="link"
        aria-disabled="true"
        tabIndex={-1}
        onClick={(event) => {
          event.preventDefault();
          event.stopPropagation();
        }}
      >
        {children}
      </a>
    );
  }

  const mergedHref = typeof href === 'string' && mergeQuery ? mergeQueryIntoHref(href) : href;
  const finalHref = resolveFallbackHref(mergedHref, fallbackHref);

  const handleClick = (event: React.MouseEvent<HTMLAnchorElement>) => {
    if (onClick) {
      onClick(event);
      return;
    }
    const link = resolveLinkHref(finalHref);
    if (!link || link.external) return;
    handleLinkClick(event, () => navigateTo(link.href));
  };

  return (
    <a
      className={className}
      href={finalHref}
      target={target}
      rel={finalRel}
      {...props}
      onClick={handleClick}
    >
      {children}
    </a>
  );
};

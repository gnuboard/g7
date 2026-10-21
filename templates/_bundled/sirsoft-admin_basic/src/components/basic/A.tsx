import React from 'react';
import { handleLinkClick, mergeQueryIntoHref, navigateTo, resolveLinkHref } from '../../support/navigationLink';

export interface AProps extends React.AnchorHTMLAttributes<HTMLAnchorElement> {
  /**
   * 비활성 링크 — href 를 빼고 `aria-disabled="true"`·`tabindex="-1"`, 클릭은 아무 일도 하지 않는다.
   * 단추의 `disabled` 와 같은 뜻이다(`<a>` 에는 `:disabled` 가상 클래스가 없으므로 꾸밈은 `aria-disabled:` 변형으로).
   */
  disabled?: boolean;
  /** 현재 주소의 쿼리를 href 에 합친다(목록 문맥 유지) — 보통 클릭과 새 탭이 같은 주소로 간다 */
  mergeQuery?: boolean;
}

/**
 * 새 창 링크의 rel — `target="_blank"` 인데 `noopener` 가 없으면 `noopener noreferrer` 를 더한다(기존 값은 지킨다).
 * 새 창이 `window.opener` 로 이 화면을 조작하지 못하게 한다(#757). 같은 창 링크는 받은 rel 그대로.
 *
 * @param target target 속성 값
 * @param rel 받은 rel 속성 값
 * @return 쓸 rel 값
 */
const relForTarget = (target: unknown, rel: unknown): string | undefined => {
  const given = typeof rel === 'string' ? rel : undefined;
  if (target !== '_blank') return given;
  const tokens = (given ?? '').split(/\s+/).filter(Boolean);
  if (tokens.includes('noopener')) return given;

  return [...tokens, 'noopener', 'noreferrer'].filter((v, i, all) => all.indexOf(v) === i).join(' ');
};

/**
 * 기본 a(링크) 컴포넌트
 *
 * 화면 이동을 링크로 그려 가운데 클릭·Ctrl+클릭·우클릭 「새 탭에서 열기」가 되게 한다(#757).
 * - 레이아웃 액션(`<on type="click">`)이 있으면 그 액션이 클릭을 맡는다 — 수정키 클릭을 막지 않는 판정은 엔진이 한다.
 * - 액션이 없는 내부 링크는 보통 클릭만 SPA 로 옮기고(전체 다시 부르기 방지), 나머지는 브라우저 기본 동작이다.
 */
export const A: React.FC<AProps> = ({
  children,
  className = '',
  href,
  disabled = false,
  mergeQuery = false,
  onClick,
  ...props
}) => {
  if (disabled) {
    return (
      <a
        className={className}
        {...props}
        rel={relForTarget(props.target, props.rel)}
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

  const finalHref = typeof href === 'string' && mergeQuery ? mergeQueryIntoHref(href) : href;

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
      {...props}
      rel={relForTarget(props.target, props.rel)}
      onClick={handleClick}
    >
      {children}
    </a>
  );
};

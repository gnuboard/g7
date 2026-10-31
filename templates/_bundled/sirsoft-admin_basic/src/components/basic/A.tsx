import React from 'react';
import { sitePath } from '../../support/apiUrl';
import { handleLinkClick, mergeQueryIntoHref, mergeRelForTarget, navigateTo, resolveLinkHref, useLocationSearch } from '../../support/navigationLink';

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
 * 기본 a(링크) 컴포넌트
 *
 * 화면 이동을 링크로 그려 가운데 클릭·Ctrl+클릭·우클릭 「새 탭에서 열기」가 되게 한다(#757).
 * - 레이아웃 액션(`<on type="click">`)이 있으면 그 액션이 클릭을 맡는다 — 수정키 클릭을 막지 않는 판정은 엔진이 한다.
 * - 액션이 없는 내부 링크는 보통 클릭만 SPA 로 옮기고(전체 다시 부르기 방지), 나머지는 브라우저 기본 동작이다.
 * - 그리는 href 에는 사이트 마운트 접두사를 붙인다(`sitePath()`). 가운데 클릭·「새 탭에서 열기」는 이 값을 그대로 열므로,
 *   접두사가 없으면 하위 폴더 배치(`https://host/g7/`)에서 링크가 사이트 밖으로 나간다 — 오류가 아니라 「엉뚱한 페이지가
 *   떴다」로만 나타난다. 레이아웃·템플릿의 루트 상대 `href` 가 모두 이 한 곳을 지나므로 호출부마다 붙이지 않는다.
 *   `sitePath()` 는 멱등이고 루트 상대(`/…`)가 아닌 값(절대 URL·`//`·`#앵커`·`mailto:`·`tel:`)은 그대로 돌려준다.
 *   보통 클릭의 SPA 이동에는 접두사 없는 경로를 넘긴다 — 라우터가 주소창에 쓸 때 붙인다.
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
  // 쿼리만 바꾸는 같은 화면 이동 뒤에도 href 가 새 쿼리를 합치도록 주소를 구독한다(props 가 같아 엔진이 다시 그리지 않는다)
  useLocationSearch(mergeQuery && !disabled && typeof href === 'string');

  if (disabled) {
    return (
      <a
        className={className}
        {...props}
        rel={mergeRelForTarget(props.target, props.rel)}
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
      href={typeof finalHref === 'string' ? sitePath(finalHref) : finalHref}
      {...props}
      rel={mergeRelForTarget(props.target, props.rel)}
      onClick={handleClick}
    >
      {children}
    </a>
  );
};

import React from 'react';

export interface AProps extends React.AnchorHTMLAttributes<HTMLAnchorElement> {
  /** 참이면 링크를 끈다 — href 를 빼고 aria-disabled="true"·tabIndex -1 을 달며 클릭을 막는다 */
  disabled?: boolean;
  /** 참이면 현재 주소의 쿼리를 href 에 합친다 — 목록 문맥(페이지·검색어)을 새 탭에서도 유지 */
  mergeQuery?: boolean;
}

/** 엔진 전역 — 레이아웃 렌더 중에만 있다 */
const g7 = (): any => (typeof window !== 'undefined' ? (window as any).G7Core : undefined);

/** 같은 사이트 안 주소인가 — '/' 로 시작하고 '//'·'/\'(브라우저가 다른 호스트로 읽음)가 아닌 것만 SPA 로 옮긴다 */
const isInternalHref = (href?: string): href is string =>
  typeof href === 'string' && /^\/(?![/\\])/.test(href);

/**
 * 새 창(`target="_blank"`) 링크의 rel — 이미 준 값은 지키고 `noopener noreferrer` 를 합친다.
 * 새 창이 `window.opener` 로 이 화면을 조작하거나 주소를 넘겨받지 못하게 한다.
 *
 * @param target 링크 target
 * @param rel 레이아웃이 준 rel
 * @returns 붙일 rel(새 창이 아니면 받은 값 그대로)
 */
const relFor = (target?: string, rel?: string): string | undefined => {
  if (target !== '_blank') return rel;
  const tokens = new Set((rel ?? '').split(/\s+/).filter(Boolean));
  tokens.add('noopener');
  tokens.add('noreferrer');

  return [...tokens].join(' ');
};

/**
 * 기본 a(링크) 컴포넌트
 *
 * - 레이아웃이 클릭 액션(`<on type="click">`)을 달았으면 클릭은 엔진이 맡는다. 엔진이 Ctrl·Cmd·Shift·가운데
 *   클릭을 브라우저에 넘겨 새 탭·새 창으로 열린다.
 * - 액션 없는 내부 링크를 보통 클릭하면 SPA 로 옮긴다(전체 다시 부르기 방지). 보통 클릭인지는
 *   `G7Core.navigation.isPlainLeftClick` 하나로만 판정한다 — 수정키·target·download·외부 주소는 브라우저 기본 동작.
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
        aria-disabled="true"
        tabIndex={-1}
        onClick={(event) => event.preventDefault()}
      >
        {children}
      </a>
    );
  }

  const resolvedHref = mergeQuery && href ? (g7()?.navigation?.mergeQueryIntoHref?.(href) ?? href) : href;

  const handleClick = (event: React.MouseEvent<HTMLAnchorElement>) => {
    if (onClick) {
      onClick(event);
      return;
    }
    if (event.defaultPrevented || !isInternalHref(resolvedHref)) return;
    const core = g7();
    if (!core?.navigation?.isPlainLeftClick?.(event)) return;
    event.preventDefault();
    core.dispatch?.({ handler: 'navigate', params: { path: resolvedHref } });
  };

  return (
    <a className={className} {...props} rel={relFor(props.target, props.rel)} href={resolvedHref} onClick={handleClick}>
      {children}
    </a>
  );
};

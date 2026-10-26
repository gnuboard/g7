// e2e:allow 학습용 숨김(hidden) 샘플 템플릿 — 운영 사이트에서 활성화되지 않아 브라우저 경로가 없다. 링크 판정·부트스트랩은 Vitest(__tests__/components/A.coreLinkApi·__tests__/bootstrap/initTemplate)로 잠그고, 같은 코어 링크 API 의 브라우저 확인은 tests/Playwright/specs/engine-713-binding-cache-links.spec.ts 가 맡는다
import React, { useSyncExternalStore } from 'react';

export interface AProps extends React.AnchorHTMLAttributes<HTMLAnchorElement> {
  /** 참이면 링크를 끈다 — href 를 빼고 aria-disabled="true"·tabIndex -1 을 달며 클릭을 막는다 */
  disabled?: boolean;
  /** 참이면 현재 주소의 쿼리를 href 에 합친다 — 목록 문맥(페이지·검색어)을 새 탭에서도 유지 */
  mergeQuery?: boolean;
}

/** 엔진 전역 — 레이아웃 렌더 중에만 있다 */
const g7 = (): any => (typeof window !== 'undefined' ? (window as any).G7Core : undefined);

/*
 * 링크 규칙(주소 구독·내부 링크 판정·새 창 rel)은 코어 한 곳(`G7Core.navigation`, engine-v1.83.0)에 있다 — 이 템플릿은 그것을
 * 부르기만 한다. 아래 대체는 그 API 가 없는 옛 코어에서만 쓰는 짧은 판정이다(샘플 템플릿이 규칙을 다시 만들지 않는다).
 */

/** 구독하지 않는 링크의 subscribe·snapshot — 같은 함수여야 React 가 다시 구독하지 않는다 */
const noSubscribe = (): (() => void) => () => {};
const noSnapshot = (): string => '';
const readLocationSearch = (): string => (typeof window === 'undefined' ? '' : window.location.search);

/**
 * 옛 코어용 대체 구독 — 코어 `subscribeLocationSearch`(7.1.2, 뒤로/앞으로·같은 화면 쿼리 교체)가 있으면 그것을 쓰고,
 * 그마저 없는 코어에서만 뒤로/앞으로(`popstate`)를 본다.
 *
 * @param callback 검색부가 바뀌었을 수 있을 때 부를 함수
 * @returns 구독 해제 함수
 */
const subscribeLocationFallback = (callback: () => void): (() => void) => {
  const subscribe = g7()?.navigation?.subscribeLocationSearch;
  if (typeof subscribe === 'function') {
    return subscribe(callback);
  }

  window.addEventListener('popstate', callback);

  return () => window.removeEventListener('popstate', callback);
};

/**
 * 옛 코어용 대체 훅 — 코어 `useLocationSearch` 와 같은 모양.
 *
 * @param enabled 현재 쿼리를 href 에 합치면 true
 * @returns 현재 주소 검색부(구독하지 않으면 '')
 */
const fallbackLocationSearch = (enabled: boolean): string =>
  useSyncExternalStore(
    enabled ? subscribeLocationFallback : noSubscribe,
    enabled ? readLocationSearch : noSnapshot,
    noSnapshot,
  );

/**
 * 렌더할 때 현재 쿼리를 href 에 합치는 링크(`mergeQuery`)가 주소 검색부를 구독한다 — 코어 훅(같은 React)을 그대로 부르고,
 * 없으면 대체 훅. 코어 유무는 렌더 사이에 바뀌지 않으므로 렌더마다 같은 훅 하나를 부른다.
 *
 * @param enabled 현재 쿼리를 href 에 합치면 true
 * @returns 현재 주소 검색부(구독하지 않으면 '')
 */
const useLocationSearch = (enabled: boolean): string => {
  const coreHook = g7()?.navigation?.useLocationSearch;
  const impl: (enabled: boolean) => string = typeof coreHook === 'function' ? coreHook : fallbackLocationSearch;

  return impl(enabled);
};

/**
 * 같은 사이트 안 주소면 SPA 로 옮길 경로를, 아니면 null — 코어 `resolveInternalHref`(엔진 navigate 와 같은 판정).
 * 옛 코어에서는 '/' 로 시작하고 '//'·'/\'(브라우저가 다른 호스트로 읽음)가 아닌 경로만 내부로 본다.
 *
 * @param href 링크 주소
 * @returns 내부 경로 또는 null
 */
const resolveInternalHref = (href?: string): string | null => {
  if (typeof href !== 'string' || href === '') return null;
  const fn = g7()?.navigation?.resolveInternalHref;
  if (typeof fn === 'function') return fn(href);

  return /^\/(?![/\\])/.test(href) ? href : null;
};

/**
 * 새 창(`target="_blank"`) 링크의 rel — 코어 `mergeRelForTarget`(빠진 noopener·noreferrer 만 덧붙이고 준 값은 지킨다).
 * 옛 코어에서는 같은 규칙으로 직접 합친다.
 *
 * @param target 링크 target
 * @param rel 레이아웃이 준 rel
 * @returns 붙일 rel(새 창이 아니면 받은 값 그대로)
 */
const relFor = (target?: string, rel?: string): string | undefined => {
  const fn = g7()?.navigation?.mergeRelForTarget;
  if (typeof fn === 'function') return fn(rel, target);
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
  // 쿼리만 바꾸는 같은 화면 이동 뒤에도 href 가 새 쿼리를 합치도록 주소를 구독한다(props 가 같아 엔진이 다시 그리지 않는다)
  useLocationSearch(mergeQuery && !disabled && typeof href === 'string' && href !== '');

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
    if (event.defaultPrevented) return;
    const path = resolveInternalHref(resolvedHref);
    if (path === null) return;
    const core = g7();
    if (!core?.navigation?.isPlainLeftClick?.(event)) return;
    event.preventDefault();
    core.dispatch?.({ handler: 'navigate', params: { path } });
  };

  return (
    <a className={className} {...props} rel={relFor(props.target, props.rel)} href={resolvedHref} onClick={handleClick}>
      {children}
    </a>
  );
};

/**
 * @file navigationLinks.coreLinkApi.test.tsx
 * @description 코어가 링크 컴포넌트에 주는 주소 구독 훅·내부 링크 판정·rel 규칙 (7.1.3, engine-v1.83.0)
 *
 * 링크·페이지 번호·탭은 같은 화면에서 쿼리만 바뀔 때 다시 그려져야 한다. 코어는 함수(`subscribeLocationSearch`·
 * `mergeQueryIntoHref`)만 주고 React 훅과 「같은 출처 내부 링크인가」·새 창 rel 규칙은 템플릿마다 따로 구현해, 사본이 갈라졌다
 * (sirsoft-admin_basic 은 `https://이 사이트//다른호스트` 를 내부 링크로 봤다). 코어가 셋을 제공한다:
 * - `useLocationSearch(enabled)` — 주소 검색부 구독 훅(구독하지 않으면 다시 그리지 않음)
 * - `resolveInternalHref(href)` — 같은 출처 내부 링크면 경로+쿼리+해시, 아니면 null (navPolicy 판정 하나)
 * - `mergeRelForTarget(rel, target)` — `_blank` 면 `noopener`·`noreferrer` 중 빠진 것만 덧붙이고 기존 토큰 보존
 *
 * @scenario-manifest tests/scenarios/engine-core-link-api.yaml
 *
 * @vitest-environment jsdom
 */
import { describe, it, expect, afterEach } from 'vitest';
import React from 'react';
import { render, act } from '@testing-library/react';
import { mergeRelForTarget, notifyLocationReplaced, resolveInternalHref, useLocationSearch } from '../navigationLinks';
import { initializeG7CoreGlobals } from '../G7CoreGlobals';

/**
 * 렌더 횟수를 세는 대역 컴포넌트.
 *
 * @param props enabled·onRender
 * @returns 현재 검색부
 */
function Probe({ enabled, onRender }: { enabled: boolean; onRender: (search: string) => void }) {
  const search = useLocationSearch(enabled);
  onRender(search);

  return React.createElement('span', { 'data-testid': 'search' }, search);
}

describe('코어 링크 API (engine-v1.83.0)', () => {
  afterEach(() => {
    window.history.replaceState(null, '', '/');
  });

  /**
   * @scenario surface=engine_useLocationSearch
   * @effects location_search_hook_rerenders_on_replace
   */
  it('useLocationSearch(true) 는 같은 화면 쿼리 교체(navigate replace) 뒤 다시 그리고, false 는 다시 그리지 않는다', () => {
    window.history.replaceState(null, '', '/board/notice?page=1');
    const seenOn: string[] = [];
    const seenOff: string[] = [];
    render(React.createElement(React.Fragment, null,
      React.createElement(Probe, { enabled: true, onRender: (s) => seenOn.push(s) }),
      React.createElement(Probe, { enabled: false, onRender: (s) => seenOff.push(s) }),
    ));
    expect(seenOn.at(-1)).toBe('?page=1');
    const offRenders = seenOff.length;

    act(() => {
      window.history.replaceState(null, '', '/board/notice?page=2');
      notifyLocationReplaced();
    });

    expect(seenOn.at(-1)).toBe('?page=2');
    expect(seenOff.length).toBe(offRenders);
    expect(seenOff.every((s) => s === '')).toBe(true);
  });

  /**
   * @scenario surface=engine_resolveInternalHref
   * @effects internal_href_single_judgment
   */
  it('resolveInternalHref — 같은 출처 내부 링크만 경로로 돌려준다', () => {
    const origin = window.location.origin;
    const vectors: Array<[unknown, string | null]> = [
      ['/a', '/a'],
      ['/a?b=1#c', '/a?b=1#c'],
      [`${origin}/a?b=1#c`, '/a?b=1#c'],
      ['//evil.example/a', null],
      [`${origin}//evil.example/a`, null],
      ['/\\evil.example/a', null],
      [`${origin}/\\/evil.example`, null],
      ['https://other.example/a', null],
      ['javascript:alert(1)', null],
      ['  JavaScript:alert(1)', null],
      ['data:text/html,x', null],
      ['#top', null],
      ['', null],
      [null, null],
    ];
    for (const [href, expected] of vectors) {
      expect([href, resolveInternalHref(href)]).toEqual([href, expected]);
    }
  });

  /**
   * @scenario surface=engine_mergeRelForTarget
   * @effects new_window_rel_merged_once
   */
  it('mergeRelForTarget — _blank 면 noopener·noreferrer 중 빠진 것만 덧붙이고, 아니면 받은 값 그대로', () => {
    expect(mergeRelForTarget(undefined, '_blank')).toBe('noopener noreferrer');
    expect(mergeRelForTarget('nofollow', '_blank')).toBe('nofollow noopener noreferrer');
    expect(mergeRelForTarget('noopener', '_blank')).toBe('noopener noreferrer');
    expect(mergeRelForTarget('NoReferrer noopener', '_blank')).toBe('NoReferrer noopener');
    expect(mergeRelForTarget('nofollow', '_self')).toBe('nofollow');
    expect(mergeRelForTarget(undefined, undefined)).toBeUndefined();
  });

  /**
   * @scenario surface=engine_globals
   * @effects core_link_api_exposed
   */
  it('G7Core.navigation 에 세 API 가 있다', () => {
    initializeG7CoreGlobals({
      getState: () => ({}) as any,
      transitionManager: { getIsPending: () => false, subscribe: () => () => {} },
      responsiveManager: {},
      webSocketManager: {} as any,
    } as any);
    const nav = (window as any).G7Core.navigation;
    expect(typeof nav.useLocationSearch).toBe('function');
    expect(nav.resolveInternalHref('/x')).toBe('/x');
    expect(nav.mergeRelForTarget(undefined, '_blank')).toBe('noopener noreferrer');
  });
});

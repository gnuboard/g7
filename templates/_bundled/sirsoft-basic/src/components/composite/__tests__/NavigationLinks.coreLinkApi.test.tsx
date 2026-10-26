/**
 * 링크가 코어 링크 API(주소 구독 훅·내부 링크 판정·새 창 rel)를 부르는지 (7.1.3, engine-v1.83.0)
 *
 * 종전에는 템플릿마다 주소 구독 훅·내부 링크 판정·rel 규칙 사본이 있어 엔진이 바뀌면 템플릿을 하나하나 고쳐야 했고(7.1.2),
 * 사본이 갈라졌다 — sirsoft-admin_basic 은 같은 출처 `https://이 사이트//다른호스트` 를 내부 링크로 봐 href·새 탭은 바깥으로
 * 가는데 보통 클릭만 엔진에서 거부됐고, rel 규칙은 세 갈래였다. 이제 링크는 코어 `G7Core.navigation` 을 부르고, 그 API 가
 * 없는 옛 코어에서만 짧은 대체로 동작한다.
 *
 * 엔진 판정 함수(resolveInternalHref·mergeRelForTarget·isPlainLeftClick)는 실제 것을 감싸 주입한다. 코어 훅은 엔진과
 * 템플릿의 React 사본이 달라 그대로 부를 수 없어 호출만 기록하는 대역을 넣는다(실물 환경은 같은 React).
 *
 * @scenario-manifest tests/scenarios/engine-core-link-api.yaml
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { A } from '../../basic/A';
import { resolveLinkHref } from '../../../support/navigationLink';
import {
  isPlainLeftClick as engineIsPlainLeftClick,
  mergeQueryIntoHref as engineMergeQueryIntoHref,
  mergeRelForTarget as engineMergeRelForTarget,
  resolveInternalHref as engineResolveInternalHref,
} from '@core/template-engine/navigationLinks';

let dispatch: ReturnType<typeof vi.fn>;

/** 링크가 SPA 로 옮기지 않은 클릭의 브라우저 기본 이동을 문서에서 막는다(jsdom 은 페이지 이동을 구현하지 않음) — 링크 처리기 뒤에 돈다 */
const stopDocumentNavigation = (event: Event) => event.preventDefault();

beforeEach(() => {
  dispatch = vi.fn();
  window.history.replaceState(null, '', '/list?page=2');
  document.addEventListener('click', stopDocumentNavigation);
});
afterEach(() => {
  document.removeEventListener('click', stopDocumentNavigation);
  cleanup();
  delete (window as any).G7Core;
  window.history.replaceState(null, '', '/');
});

/** 보통 클릭으로 SPA 이동했는지 — navigate 디스패치 경로 */
const navigatedTo = (): string[] =>
  dispatch.mock.calls.filter(([action]) => action?.handler === 'navigate').map(([action]) => action.params.path);

describe('링크 — 코어 링크 API 사용 (sirsoft-basic)', () => {
  /**
   * 링크가 코어 주소 구독 훅·내부 링크 판정·rel 규칙을 부른다.
   *
   * @scenario surface=tpl_sirsoft_basic
   *
   * @effects template_uses_core_link_api, new_window_rel_merged_once
   */
  it('링크는 주소 구독·내부 링크 판정·새 창 rel 을 코어 API 로 정한다', () => {
    const useLocationSearch = vi.fn(() => '');
    const resolveInternalHref = vi.fn(engineResolveInternalHref);
    const mergeRelForTarget = vi.fn(engineMergeRelForTarget);
    (window as any).G7Core = {
      t: (key: string) => key,
      dispatch,
      navigation: {
        isPlainLeftClick: engineIsPlainLeftClick,
        mergeQueryIntoHref: engineMergeQueryIntoHref,
        useLocationSearch,
        resolveInternalHref,
        mergeRelForTarget,
      },
    };

    render(
      <>
        <A href="/items/1" mergeQuery>목록 문맥</A>
        <A href="/items/2" target="_blank" rel="nofollow">새 창</A>
      </>,
    );

    expect(useLocationSearch).toHaveBeenCalledWith(true);
    expect(mergeRelForTarget).toHaveBeenCalledWith('nofollow', '_blank');
    expect((screen.getByRole('link', { name: '새 창' }).getAttribute('rel') ?? '').split(/\s+/).sort()).toEqual(['nofollow', 'noopener', 'noreferrer']);

    fireEvent.click(screen.getByRole('link', { name: '목록 문맥' }), { button: 0 });
    expect(resolveInternalHref).toHaveBeenCalled();
    expect(navigatedTo()).toEqual(['/items/1?page=2']);
  });

  /**
   * 같은 출처라도 경로가 `//` 로 시작하는 주소는 내부 링크가 아니다(엔진 navigate 와 같은 판정).
   *
   * @scenario surface=tpl_sirsoft_basic
   *
   * @effects internal_href_single_judgment
   */
  it('같은 출처 `//다른호스트` 경로·`//host` 주소는 보통 클릭도 SPA 로 옮기지 않는다', () => {
    (window as any).G7Core = {
      t: (key: string) => key,
      dispatch,
      navigation: {
        isPlainLeftClick: engineIsPlainLeftClick,
        mergeQueryIntoHref: engineMergeQueryIntoHref,
        useLocationSearch: () => '',
        resolveInternalHref: engineResolveInternalHref,
        mergeRelForTarget: engineMergeRelForTarget,
      },
    };

    render(
      <>
        <A href={`${window.location.origin}//evil.example/x`}>같은 출처 이중 빗금</A>
        <A href="//evil.example/x">프로토콜 상대</A>
        <A href={`${window.location.origin}/items/3`}>같은 출처 전체 주소</A>
      </>,
    );

    fireEvent.click(screen.getByRole('link', { name: '같은 출처 이중 빗금' }), { button: 0 });
    fireEvent.click(screen.getByRole('link', { name: '프로토콜 상대' }), { button: 0 });
    expect(navigatedTo()).toEqual([]);

    fireEvent.click(screen.getByRole('link', { name: '같은 출처 전체 주소' }), { button: 0 });
    expect(navigatedTo()).toHaveLength(1);
  });

  /**
   * 코어 링크 API 가 없는 옛 코어에서도 짧은 대체로 깨지지 않는다.
   *
   * @scenario surface=tpl_sirsoft_basic
   *
   * @effects template_old_core_fallback
   */
  it('코어 링크 API 가 없는 옛 코어에서는 `/` 경로만 SPA 로 옮기고 새 창 rel 을 직접 합친다', () => {
    (window as any).G7Core = {
      t: (key: string) => key,
      dispatch,
      navigation: { isPlainLeftClick: engineIsPlainLeftClick },
    };

    render(
      <>
        <A href="/items/4">경로</A>
        <A href={'/\\evil.example/x'}>역슬래시</A>
        <A href="/items/5" target="_blank">새 창</A>
      </>,
    );

    fireEvent.click(screen.getByRole('link', { name: '역슬래시' }), { button: 0 });
    expect(navigatedTo()).toEqual([]);
    fireEvent.click(screen.getByRole('link', { name: '경로' }), { button: 0 });
    expect(navigatedTo()).toEqual(['/items/4']);
    expect((screen.getByRole('link', { name: '새 창' }).getAttribute('rel') ?? '').split(/\s+/).sort()).toEqual(['noopener', 'noreferrer']);
  });

  /**
   * @scenario surface=tpl_sirsoft_basic
   *
   * @effects old_core_same_origin_absolute_is_internal
   */
  it('옛 코어에서도 같은 출처 전체 주소(알림·메뉴 주소)는 내부 링크로 본다 — 경로가 `//`·`/\\` 로 시작하면 아니다', () => {
    // 코어 링크 API 가 없는 7.1.1·7.1.2 코어 — 7.1.2 템플릿과 같은 결과여야 한다(새 탭으로 열리지 않게)
    (window as any).G7Core = {};
    const origin = window.location.origin;

    expect(resolveLinkHref(`${origin}/notifications/7?tab=all`)).toEqual({ href: `${origin}/notifications/7?tab=all`, external: false });
    expect(resolveLinkHref('/items/4')).toEqual({ href: '/items/4', external: false });
    expect(resolveLinkHref(`${origin}//evil.example/x`)).toEqual({ href: `${origin}//evil.example/x`, external: true });
    expect(resolveLinkHref('https://other.example/a')).toEqual({ href: 'https://other.example/a', external: true });
    expect(resolveLinkHref('//evil.example/x')).toBeNull();
    expect(resolveLinkHref('javascript:alert(1)')).toBeNull();
  });
});

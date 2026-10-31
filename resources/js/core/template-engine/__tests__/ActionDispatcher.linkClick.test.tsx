// e2e:allow 엔진 클릭 판정 단위 잠금 — 브라우저 새 탭 동작은 관리자 템플릿 admin-navigation-links E2E 가 잰다.
/**
 * 화면 이동 링크 — `<a href>` 클릭 처리 회귀 테스트 (실물 DynamicRenderer 렌더)
 *
 * 화면 이동이 링크여도 엔진이 click 마다 수정키를 보지 않고 `preventDefault()` 를 불러 Ctrl·Cmd·Shift+클릭
 * 새 탭이 막혔다(공개 문서도 「`<a href>` 기본 이동은 엔진이 자동으로 막는다」고 규정). 이제
 *
 * - 실제 주소 `<a href>` 를 보통 클릭이 아니게 누르면 막지도 실행하지도 않는다(브라우저가 새 탭·새 창).
 * - 진입점 액션 키 `preventDefault` — 생략 = 자동 · `true` = 언제나 막고 실행 · `false` = 막지 않고 실행.
 * - path 를 생략한 navigate 는 클릭한 링크의 href(같은 출처만)로 이동하고, `mergeQuery` 면 href 의 쿼리를 바탕으로
 *   현재 쿼리를 합친다.
 * - 가운데 버튼(auxclick)·우클릭(contextmenu) 에 엔진 리스너가 없다 — 브라우저 「새 탭에서 열기」 그대로.
 *
 * @since engine-v1.80.1
 */
import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen, cleanup, act } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';
import { isPlainLeftClick, mergeQueryIntoHref, isRealHref, toSameOriginPath } from '../navigationLinks';

const A: React.FC<Record<string, any>> = ({ children, text, ...rest }) => (
  <a {...rest}>{text}{children}</a>
);
const Button: React.FC<Record<string, any>> = ({ children, text, ...rest }) => (
  <button type="button" {...rest}>{text}{children}</button>
);
const Div: React.FC<Record<string, any>> = ({ children, text, ...rest }) => (
  <div {...rest}>{text}{children}</div>
);

/** 클릭을 보내고 기본 동작이 막혔는지와 비동기 액션 완료를 기다린다 */
async function click(el: Element, init: MouseEventInit = {}): Promise<boolean> {
  const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init });
  await act(async () => {
    el.dispatchEvent(event);
    await new Promise((r) => setTimeout(r, 0));
  });
  return event.defaultPrevented;
}

describe('화면 이동 링크 — 엔진 클릭 처리', () => {
  let registry: ComponentRegistry;
  let navigate: ReturnType<typeof vi.fn<(path: string, options?: unknown) => void>>;
  let dispatcher: ActionDispatcher;
  const translationContext: TranslationContext = { templateId: 'test-template', locale: 'ko' };

  const renderNode = (def: ComponentDefinition) =>
    render(
      <DynamicRenderer
        componentDef={def}
        dataContext={{}}
        translationContext={translationContext}
        registry={registry}
        bindingEngine={new DataBindingEngine()}
        translationEngine={new TranslationEngine()}
        actionDispatcher={dispatcher}
      />,
    );

  const link = (props: Record<string, any>, action: Record<string, any>): ComponentDefinition => ({
    id: 'lnk',
    type: 'basic',
    name: 'A',
    props: { 'data-testid': 'lnk', text: '이동', ...props },
    actions: [{ type: 'click', handler: 'navigate', ...action }] as any,
  });

  beforeEach(() => {
    registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      A: { component: A, metadata: { name: 'A', type: 'basic' } },
      Button: { component: Button, metadata: { name: 'Button', type: 'basic' } },
      Div: { component: Div, metadata: { name: 'Div', type: 'basic' } },
    };
    navigate = vi.fn<(path: string, options?: unknown) => void>();
    dispatcher = new ActionDispatcher({ navigate });
    window.history.replaceState(null, '', '/admin/users?search=kim&page=1');
  });

  afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    window.history.replaceState(null, '', '/');
  });

  // @scenario click=plain, element=link, prevent_key=omitted
  // @effects plain_click_spa_navigates
  it('보통 클릭: 기본 동작을 막고 path 없는 navigate 가 링크 href 로 SPA 이동한다', async () => {
    renderNode(link({ href: '/admin/users/create' }, { params: {} }));
    const prevented = await click(screen.getByTestId('lnk'));
    expect(prevented).toBe(true);
    expect(navigate).toHaveBeenCalledTimes(1);
    expect(navigate).toHaveBeenCalledWith('/admin/users/create', { replace: false });
  });

  // @scenario click=modified, element=link, prevent_key=omitted
  // @effects modified_click_left_to_browser
  it.each([
    ['Ctrl', { ctrlKey: true }],
    ['Cmd', { metaKey: true }],
    ['Shift', { shiftKey: true }],
    ['Alt', { altKey: true }],
  ])('%s+클릭: 막지도 실행하지도 않는다(브라우저가 새 탭·새 창)', async (_label, init) => {
    renderNode(link({ href: '/admin/users/create' }, { params: { path: '/admin/users/create' } }));
    const prevented = await click(screen.getByTestId('lnk'), init as MouseEventInit);
    expect(prevented).toBe(false);
    expect(navigate).not.toHaveBeenCalled();
  });

  // @scenario click=plain, element=link_target_blank, prevent_key=omitted
  // @effects modified_click_left_to_browser
  it('target="_blank"·download 링크는 보통 클릭도 브라우저에 맡긴다', async () => {
    renderNode(link({ href: '/manual.pdf', target: '_blank', rel: 'noopener' }, { params: {} }));
    expect(await click(screen.getByTestId('lnk'))).toBe(false);
    cleanup();
    renderNode(link({ href: '/export.csv', download: '' }, { params: {} }));
    expect(await click(screen.getByTestId('lnk'))).toBe(false);
    expect(navigate).not.toHaveBeenCalled();
  });

  // @scenario click=modified, element=link, prevent_key=true
  // @effects prevent_key_overrides_auto
  it('preventDefault: true 면 Ctrl+클릭도 막고 실행한다(종전 동작)', async () => {
    renderNode(link({ href: '/admin/users/create' }, { preventDefault: true, params: {} }));
    const prevented = await click(screen.getByTestId('lnk'), { ctrlKey: true });
    expect(prevented).toBe(true);
    expect(navigate).toHaveBeenCalledWith('/admin/users/create', { replace: false });
  });

  // @scenario click=plain, element=link, prevent_key=false
  // @effects prevent_key_overrides_auto
  it('preventDefault: false 면 막지 않고 실행한다', async () => {
    renderNode(link({ href: '/admin/users/create' }, { preventDefault: false, params: {} }));
    const prevented = await click(screen.getByTestId('lnk'));
    expect(prevented).toBe(false);
    expect(navigate).toHaveBeenCalledTimes(1);
  });

  // @scenario click=modified, element=link, prevent_key=false
  // @effects prevent_key_overrides_auto
  it('preventDefault: false 면 Ctrl+클릭도 막지 않고 액션은 실행한다(새 탭 + 이 탭의 액션)', async () => {
    renderNode(link({ href: '/admin/users/create' }, { preventDefault: false, params: {} }));
    const prevented = await click(screen.getByTestId('lnk'), { ctrlKey: true });
    expect(prevented).toBe(false);
    expect(navigate).toHaveBeenCalledTimes(1);
  });

  // @scenario click=modified, element=button, prevent_key=omitted
  // @effects non_link_unchanged
  it('href 없는 단추는 수정키 클릭도 종전처럼 막고 실행한다', async () => {
    renderNode({
      id: 'btn',
      type: 'basic',
      name: 'Button',
      props: { 'data-testid': 'btn', text: '이동' },
      actions: [{ type: 'click', handler: 'navigate', params: { path: '/admin/users/create' } }] as any,
    });
    const prevented = await click(screen.getByTestId('btn'), { ctrlKey: true });
    expect(prevented).toBe(true);
    expect(navigate).toHaveBeenCalledWith('/admin/users/create', { replace: false });
  });

  // @scenario click=plain, element=link, prevent_key=omitted
  // @effects path_from_link_href_merges_query
  it('path 없는 navigate + mergeQuery: href 의 쿼리가 현재 쿼리를 이기고 <query> 가 마지막에 덮는다', async () => {
    renderNode(link({ href: '/admin/users?page=2' }, { params: { mergeQuery: true } }));
    await click(screen.getByTestId('lnk'));
    expect(navigate).toHaveBeenLastCalledWith('/admin/users?search=kim&page=2', { replace: false });

    cleanup();
    navigate.mockClear();
    renderNode(link({ href: '/admin/users?page=2' }, { params: { mergeQuery: true, query: { sort: 'name', search: '' } } }));
    await click(screen.getByTestId('lnk'));
    expect(navigate).toHaveBeenLastCalledWith('/admin/users?page=2&sort=name', { replace: false });
  });

  // @scenario click=plain, element=link_external, prevent_key=omitted
  // @effects external_href_not_followed
  it('path 없는 navigate 는 외부·실행 스킴 href 를 따라가지 않는다', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    renderNode(link({ href: 'https://evil.example.com/x' }, { params: {} }));
    await click(screen.getByTestId('lnk'));
    expect(navigate).not.toHaveBeenCalled();
    warn.mockRestore();
  });

  // @scenario click=plain, element=link, prevent_key=omitted
  // @effects explicit_path_wins
  it('path 가 있으면 href 보다 path 가 이긴다(종전 규칙)', async () => {
    renderNode(link({ href: '/a' }, { params: { path: '/b' } }));
    await click(screen.getByTestId('lnk'));
    expect(navigate).toHaveBeenCalledWith('/b', { replace: false });
  });

  // @scenario click=modified, element=link_nested_in_clickable, prevent_key=omitted
  // @effects modified_click_left_to_browser
  it('클릭 가능한 부모(카드) 안의 링크를 Ctrl+클릭하면 부모 핸들러도 막지 않고 부모 액션도 돌지 않는다', async () => {
    renderNode({
      id: 'card',
      type: 'basic',
      name: 'Div',
      props: { 'data-testid': 'card' },
      actions: [{ type: 'click', handler: 'navigate', params: { path: '/parent-toggle' } }] as any,
      children: [link({ href: '/shop/products/1' }, { params: {} })],
    });
    const prevented = await click(screen.getByTestId('lnk'), { ctrlKey: true });
    expect(prevented).toBe(false);
    expect(navigate).not.toHaveBeenCalled();

    // 보통 클릭은 종전대로 링크 이동(부모 click 도 버블링으로 함께 돈다 — 종전 동작)
    await click(screen.getByTestId('lnk'));
    expect(navigate).toHaveBeenCalledWith('/shop/products/1', { replace: false });
  });

  // @scenario click=plain, element=link, prevent_key=omitted
  // @effects explicit_path_wins
  it('링크 진입점 체인 안에서 path 식이 빈 값으로 풀린 navigate 는 href 로 가지 않고 종전처럼 건너뛴다', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    renderNode(link({ href: '/admin/users' }, {
      handler: 'sequence',
      actions: [{ handler: 'navigate', params: { path: '{{_local.missingPath}}' } }],
    }));
    await click(screen.getByTestId('lnk'));
    expect(navigate).not.toHaveBeenCalled();
    warn.mockRestore();
  });

  // @scenario click=plain, element=link, prevent_key=omitted
  // @effects path_from_link_href_merges_query
  it('링크 href 의 #해시는 병합·쿼리 교체 뒤에도 남는다', async () => {
    renderNode(link({ href: '/admin/users?page=2#list' }, { params: { mergeQuery: true, query: {} } }));
    await click(screen.getByTestId('lnk'));
    expect(navigate).toHaveBeenLastCalledWith('/admin/users?search=kim&page=2#list', { replace: false });

    cleanup();
    renderNode(link({ href: '/admin/users?page=9#list' }, { params: { query: { k: 'v' } } }));
    await click(screen.getByTestId('lnk'));
    expect(navigate).toHaveBeenLastCalledWith('/admin/users?k=v#list', { replace: false });
  });

  // @scenario click=middle_or_context, element=link, prevent_key=omitted
  // @effects no_engine_listener_on_aux_or_context
  it('가운데 버튼(auxclick)·우클릭(contextmenu)·mousedown 에 엔진이 기본 동작을 막지 않는다', async () => {
    renderNode(link({ href: '/admin/users/create' }, { params: {} }));
    const el = screen.getByTestId('lnk');
    for (const type of ['auxclick', 'contextmenu', 'mousedown']) {
      const ev = new MouseEvent(type, { bubbles: true, cancelable: true, button: type === 'auxclick' ? 1 : 2 });
      el.dispatchEvent(ev);
      expect(ev.defaultPrevented).toBe(false);
    }
    expect(navigate).not.toHaveBeenCalled();
  });
});

describe('링크 판정·병합 규칙 — navigationLinks (G7Core.navigation 이 노출)', () => {
  beforeEach(() => {
    window.history.replaceState(null, '', '/admin/users?search=kim&page=1&tags[]=a&tags[]=b');
  });
  afterEach(() => {
    window.history.replaceState(null, '', '/');
  });

  // @scenario click=plain, element=link, prevent_key=omitted
  // @effects plain_click_spa_navigates
  it('isPlainLeftClick — 왼쪽 버튼·수정키 없음·target 없음/_self·download 없음', () => {
    const a = document.createElement('a');
    a.setAttribute('href', '/x');
    expect(isPlainLeftClick({ button: 0, currentTarget: a })).toBe(true);
    expect(isPlainLeftClick(undefined)).toBe(true);
    expect(isPlainLeftClick({ button: 1, currentTarget: a })).toBe(false);
    expect(isPlainLeftClick({ button: 0, ctrlKey: true })).toBe(false);
    expect(isPlainLeftClick({ button: 0, metaKey: true })).toBe(false);
    a.setAttribute('target', '_self');
    expect(isPlainLeftClick({ button: 0, currentTarget: a })).toBe(true);
    a.setAttribute('target', '_blank');
    expect(isPlainLeftClick({ button: 0, currentTarget: a })).toBe(false);
    a.removeAttribute('target');
    a.setAttribute('download', '');
    expect(isPlainLeftClick({ button: 0, currentTarget: a })).toBe(false);
  });

  // @scenario click=plain, element=link, prevent_key=omitted
  // @effects path_from_link_href_merges_query
  it('mergeQueryIntoHref — 현재 쿼리 + 링크 쿼리(링크 이김, 배열은 키 단위 교체), 같은 출처 아니면 그대로', () => {
    expect(mergeQueryIntoHref('/admin/users?page=2')).toBe('/admin/users?search=kim&page=2&tags%5B%5D=a&tags%5B%5D=b');
    expect(mergeQueryIntoHref('/admin/users/create')).toBe('/admin/users/create?search=kim&page=1&tags%5B%5D=a&tags%5B%5D=b');
    expect(mergeQueryIntoHref('/admin/users?tags[]=c')).toBe('/admin/users?search=kim&page=1&tags%5B%5D=c');
    expect(mergeQueryIntoHref('https://evil.example.com/x')).toBe('https://evil.example.com/x');
    expect(mergeQueryIntoHref('#top')).toBe('#top');
    expect(mergeQueryIntoHref('javascript:alert(1)')).toBe('javascript:alert(1)');
  });

  // @scenario click=plain, element=link_external, prevent_key=omitted
  // @effects external_href_not_followed
  it('isRealHref·toSameOriginPath — 빈 값·#·위험 스킴·외부 주소', () => {
    expect(isRealHref('')).toBe(false);
    expect(isRealHref('#')).toBe(false);
    expect(isRealHref(' JaVaScRiPt:alert(1)')).toBe(false);
    expect(isRealHref('/x')).toBe(true);
    expect(toSameOriginPath('/x?y=1#z')).toBe('/x?y=1#z');
    expect(toSameOriginPath(`${window.location.origin}/x`)).toBe('/x');
    expect(toSameOriginPath('//evil.example.com/x')).toBeNull();
    expect(toSameOriginPath('https://evil.example.com/x')).toBeNull();
  });
});

/**
 * renderItemChildren — 렌더 환경 통로 회귀 테스트 (이슈 #141)
 *
 * 셀 렌더 함수는 React 컴포넌트가 아니어서 ResponsiveProvider 의 미리보기 폭을 읽지 못하고
 * 전역 싱글톤(브라우저 창 폭)으로 `responsive` 분기를 골랐다. 같은 원인으로 셀이 직접 푸는
 * `$t:defer:` 는 호스트 언어로, 셀 액션은 호스트 dispatcher 에 묶였다. 렌더 환경
 * (`renderEnv` 또는 `componentContext.renderEnvRef.current`)이 오면 그 값을 따라야 한다.
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen, cleanup } from '@testing-library/react';
import { renderItemChildren } from '../RenderHelpers';
import { responsiveManager } from '../../ResponsiveManager';
import { DataBindingEngine } from '../../DataBindingEngine';
import { TranslationEngine } from '../../TranslationEngine';

/** 싱글톤(호스트) dispatcher 대역 — 셀이 여기에 묶이면 안 된다 */
const singletonDispatcher = vi.hoisted(() => ({
  bindActionsToProps: vi.fn((props: any) => ({ ...props, onClick: () => undefined })),
}));

vi.mock('../../ActionDispatcher', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  getActionDispatcher: () => singletonDispatcher,
}));

const Box: React.FC<{ className?: string; onClick?: any; children?: React.ReactNode; 'data-testid'?: string }> = ({
  className,
  onClick,
  children,
  ...rest
}) => (
  <div className={className} data-has-click={onClick ? 'yes' : 'no'} data-testid={rest['data-testid']}>
    {children}
  </div>
);

const componentMap = { Box };

/** 데스크톱 base · portable 오버라이드를 가진 셀 정의 */
const responsiveCell = (testId = 'cell') => ({
  id: testId,
  type: 'basic',
  name: 'Box',
  props: { className: 'base', 'data-testid': testId },
  responsive: { portable: { props: { className: 'portable' } } },
});

describe('renderItemChildren — 렌더 환경(폭)', () => {
  let originalWidth: number;

  beforeEach(() => {
    originalWidth = responsiveManager.getWidth();
    responsiveManager._setWidthForTesting(1441);
  });

  afterEach(() => {
    responsiveManager._setWidthForTesting(originalWidth);
    cleanup();
  });

  /**
   * @scenario preview_device=mobile, window_width=1441, render_path=cell_plain
   * @effects preview_cell_render_follows_preview_width
   */
  it('T-R1 일반 자식: 싱글톤 1441 + renderEnv 390 → portable', () => {
    const nodes = renderItemChildren([responsiveCell()], { row: {} }, componentMap, 'k', {
      renderEnv: { responsiveWidth: 390 },
    });
    render(<>{nodes}</>);
    expect(screen.getByTestId('cell').className).toBe('portable');
  });

  /**
   * @scenario preview_device=mobile, window_width=1441, render_path=cell_iteration
   * @effects preview_cell_render_follows_preview_width
   */
  it('T-R1 iteration 자식: 각 항목이 portable', () => {
    const def = {
      id: 'it',
      type: 'basic',
      name: 'Box',
      props: { className: 'base' },
      iteration: { source: 'row.items', item_var: 'opt' },
      responsive: { portable: { props: { className: 'portable' } } },
    };
    const nodes = renderItemChildren([def], { row: { items: [1, 2] } }, componentMap, 'k', {
      renderEnv: { responsiveWidth: 390 },
    });
    const { container } = render(<>{nodes}</>);
    expect(container.querySelectorAll('.portable')).toHaveLength(2);
    expect(container.querySelectorAll('.base')).toHaveLength(0);
  });

  /**
   * @scenario preview_device=mobile, window_width=1441, render_path=cell_nested
   * @effects preview_cell_render_follows_preview_width
   */
  it('T-R1 중첩 재귀 자식도 같은 폭을 쓴다', () => {
    const def = {
      id: 'outer',
      type: 'basic',
      name: 'Box',
      props: { className: 'outer' },
      children: [responsiveCell('inner')],
    };
    const nodes = renderItemChildren([def], { row: {} }, componentMap, 'k', {
      renderEnv: { responsiveWidth: 390 },
    });
    render(<>{nodes}</>);
    expect(screen.getByTestId('inner').className).toBe('portable');
  });

  /**
   * @scenario preview_device=mobile, window_width=1441, env_source=component_context_ref
   * @effects preview_cell_render_follows_preview_width
   */
  it('T-R2 componentContext.renderEnvRef.current 도 같은 결과', () => {
    const nodes = renderItemChildren([responsiveCell()], { row: {} }, componentMap, 'k', {
      componentContext: { renderEnvRef: { current: { responsiveWidth: 390 } } } as any,
    });
    render(<>{nodes}</>);
    expect(screen.getByTestId('cell').className).toBe('portable');
  });

  /**
   * @scenario preview_device=desktop, explicit_width=1280, env_width=390
   * @effects preview_render_env_priority_explicit_over_env_over_host
   */
  it('T-R3 명시 responsiveWidth 1280 이 env 390 을 이긴다 → base', () => {
    const nodes = renderItemChildren([responsiveCell()], { row: {} }, componentMap, 'k', {
      responsiveWidth: 1280,
      renderEnv: { responsiveWidth: 390 },
    });
    render(<>{nodes}</>);
    expect(screen.getByTestId('cell').className).toBe('base');
  });

  /**
   * @scenario render_env=absent, window_width=400
   * @effects preview_render_env_falls_back_to_host_outside_preview
   */
  it('T-R3 env·명시 모두 없으면 싱글톤 400 → portable (하위 호환)', () => {
    responsiveManager._setWidthForTesting(400);
    const nodes = renderItemChildren([responsiveCell()], { row: {} }, componentMap, 'k');
    render(<>{nodes}</>);
    expect(screen.getByTestId('cell').className).toBe('portable');
  });

  /**
   * @scenario responsive_key=custom_range, env_width=500|700
   * @effects preview_cell_render_follows_preview_width
   */
  it('T-R4 커스텀 범위 키 0-599: env 500 적용, 700 미적용', () => {
    const def = {
      id: 'c',
      type: 'basic',
      name: 'Box',
      props: { className: 'base', 'data-testid': 'c' },
      responsive: { '0-599': { props: { className: 'narrow' } } },
    };
    const first = renderItemChildren([def], { row: {} }, componentMap, 'a', {
      renderEnv: { responsiveWidth: 500 },
    });
    const view = render(<>{first}</>);
    expect(screen.getByTestId('c').className).toBe('narrow');
    view.unmount();

    const second = renderItemChildren([def], { row: {} }, componentMap, 'b', {
      renderEnv: { responsiveWidth: 700 },
    });
    render(<>{second}</>);
    expect(screen.getByTestId('c').className).toBe('base');
  });
});

describe('renderItemChildren — 렌더 환경(번역·액션·상태)', () => {
  let translationEngine: TranslationEngine;

  beforeEach(() => {
    translationEngine = new TranslationEngine();
    (translationEngine as any).translations.set('t:ko', { shop: { fee: '배송비' } });
    (translationEngine as any).translations.set('t:en', { shop: { fee: 'Shipping fee' } });
  });

  afterEach(() => cleanup());

  const deferCell = { id: 'tx', type: 'basic', name: 'Box', props: { 'data-testid': 'tx' }, text: '$t:defer:shop.fee' };

  /**
   * @scenario content_locale=en, host_locale=ko, render_path=cell_defer
   * @effects preview_cell_defer_translation_follows_content_locale
   */
  it('T-R5 env translationContext(en) 로 $t:defer: 를 번역한다', () => {
    const nodes = renderItemChildren([deferCell], { row: {} }, componentMap, 'k', {
      translationEngine,
      renderEnv: { translationContext: { templateId: 't', locale: 'en' } },
    });
    render(<>{nodes}</>);
    expect(screen.getByTestId('tx').textContent).toBe('Shipping fee');
  });

  /**
   * @scenario content_locale=en, explicit_locale=ko
   * @effects preview_render_env_priority_explicit_over_env_over_host
   */
  it('T-R5 명시 translationContext 가 env 를 이긴다', () => {
    const nodes = renderItemChildren([deferCell], { row: {} }, componentMap, 'k', {
      translationEngine,
      translationContext: { templateId: 't', locale: 'ko' },
      renderEnv: { translationContext: { templateId: 't', locale: 'en' } },
    });
    render(<>{nodes}</>);
    expect(screen.getByTestId('tx').textContent).toBe('배송비');
  });

  const actionCell = {
    id: 'btn',
    type: 'basic',
    name: 'Box',
    props: { 'data-testid': 'btn' },
    actions: [{ type: 'click', handler: 'setState', params: { target: 'local', x: 1 } }],
  };

  const makeDispatcher = () => ({
    bindActionsToProps: vi.fn((props: any) => ({ ...props, onClick: () => undefined })),
  });

  /**
   * @scenario dispatcher=preview_isolated, edit_mode=false
   * @effects preview_cell_actions_bind_to_preview_dispatcher
   */
  it('T-R6 env actionDispatcher 에 바인딩되고 싱글톤은 호출되지 않는다', () => {
    const envDispatcher = makeDispatcher();
    const singleton = singletonDispatcher;
    singleton.bindActionsToProps.mockClear();

    const nodes = renderItemChildren([actionCell], { row: {} }, componentMap, 'k', {
      renderEnv: { actionDispatcher: envDispatcher },
    });
    render(<>{nodes}</>);

    expect(envDispatcher.bindActionsToProps).toHaveBeenCalledTimes(1);
    expect(singleton.bindActionsToProps).not.toHaveBeenCalled();
    expect(screen.getByTestId('btn').getAttribute('data-has-click')).toBe('yes');
  });

  /**
   * @scenario dispatcher=preview_isolated, edit_mode=true
   * @effects preview_cell_actions_not_bound_in_edit_mode
   */
  it('T-R6 env isEditMode=true 면 어느 dispatcher 도 호출되지 않고 onClick 이 없다', () => {
    const envDispatcher = makeDispatcher();
    const singleton = singletonDispatcher;
    singleton.bindActionsToProps.mockClear();

    const nested = { id: 'wrap', type: 'basic', name: 'Box', children: [actionCell] };
    const nodes = renderItemChildren([nested], { row: {} }, componentMap, 'k', {
      renderEnv: { actionDispatcher: envDispatcher, isEditMode: true },
    });
    render(<>{nodes}</>);

    expect(envDispatcher.bindActionsToProps).not.toHaveBeenCalled();
    expect(singleton.bindActionsToProps).not.toHaveBeenCalled();
    expect(screen.getByTestId('btn').getAttribute('data-has-click')).toBe('no');
  });

  /**
   * 재귀(중첩 자식)로 내려가는 옵션이 getRemountKey·componentContext 를 잃지 않는다 — 잃으면
   * 셀 안 동적 id 컴포넌트의 remount 가 멎고(components-datagrid 사례 1), 확장 행 안 체크박스의
   * setLocal 이 부모 대신 전역 `_local` 로 간다(components-form 사례 1).
   *
   * @scenario render_path=cell_nested, options=getRemountKey|componentContext
   * @effects preview_render_env_priority_explicit_over_env_over_host
   */
  it('중첩 자식에도 getRemountKey·componentContext 가 그대로 전달된다', () => {
    const envDispatcher = makeDispatcher();
    const componentContext = { state: {}, setState: vi.fn() };
    const getRemountKey = vi.fn((id: string | undefined, fallback: string) => id ?? fallback);
    const nested = { id: 'wrap', type: 'basic', name: 'Box', children: [{ ...actionCell, id: 'toggle_{{row.id}}' }] };

    const nodes = renderItemChildren([nested], { row: { id: 7 } }, componentMap, 'k', {
      renderEnv: { actionDispatcher: envDispatcher },
      componentContext: componentContext as any,
      getRemountKey,
    });
    render(<>{nodes}</>);

    expect(getRemountKey).toHaveBeenCalledWith('toggle_7', expect.any(String));
    expect(envDispatcher.bindActionsToProps).toHaveBeenCalledWith(expect.anything(), expect.anything(), componentContext);
  });

  /**
   * @scenario render_env=present, component_context=absent
   * @effects preview_render_env_does_not_merge_state
   */
  it('T-R7 renderEnv 만 주면 _local 이 바뀌지 않는다 (상태 병합은 componentContext 전용)', () => {
    const probe = {
      id: 'p',
      type: 'basic',
      name: 'Box',
      props: { 'data-testid': 'p' },
      text: "{{_local.flag ?? 'none'}}",
    };
    const nodes = renderItemChildren([probe], { row: {}, _local: { flag: 'item' } }, componentMap, 'k', {
      bindingEngine: new DataBindingEngine(),
      renderEnv: { responsiveWidth: 390, state: { flag: 'leak' } } as any,
    });
    render(<>{nodes}</>);
    expect(screen.getByTestId('p').textContent).toBe('item');
  });
});

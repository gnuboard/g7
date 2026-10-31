// e2e:allow [에러 처리] 탭 저장 모양 회귀 단위(RTL) — 굽기 산출(최상위 actions)·코드 전용 동작 보존. 라이브는 이력의 Chrome MCP 매트릭스로 보강.
/**
 * ErrorHandlingForm.actionShape.test.tsx — [에러 처리] 탭이 저장된 동작 모양을 잃지 않는다 (#753)
 *
 * 관리자 > 템플릿 > 레이아웃 편집 > 페이지 설정 > 오류 처리 에서:
 *  ① 순차 실행 하위 목록을 `params.actions` 에서만 읽어, 굽기 산출 모양(최상위 `actions`)이면 빈 목록으로 보였다.
 *  ② 하위 목록을 고치면 `{handler, params:{actions}}` 로 덮어써 최상위 `actions`·다른 키(comment 등)가 사라졌다.
 *  ③ 칸이 다루지 않는 동작(conditions·switch·action-ref·if)은 선택칸이 빈 값이라, 손대면 원래 동작이 사라졌다.
 * 이제 두 모양을 다 읽고, 저장은 최상위 `actions` 로 하며 다른 키를 보존하고, 다루지 않는 동작은 읽기 전용 안내로 둔다.
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { ErrorHandlingForm } from '../../../components/page-settings/ErrorHandlingForm';
import { buildCoreActionRecipeSeed } from '../../../spec/coreActionRecipes';
import { clearWidgetRegistry } from '../../../spec/widgetRegistry';
import { registerCoreWidgets, resetCoreWidgetRegistration } from '../../../spec/registerCoreWidgets';

/** `$t:` 키를 그대로 반환(라벨 해석 단순화) */
const t = (k: string) => k;

/** 실제 코어 에러 레시피 */
const ERROR_RECIPES = buildCoreActionRecipeSeed().errorRecipes;

beforeEach(() => {
  cleanup();
  clearWidgetRegistry();
  resetCoreWidgetRegistration();
  registerCoreWidgets();
});

const renderForm = (value: Record<string, unknown>, onChange = vi.fn()) => {
  render(
    <ErrorHandlingForm value={value as never} onChange={onChange} t={t} errorConfigCodes={['404']} recipes={ERROR_RECIPES} pageCandidates={[]} />,
  );
  return onChange;
};

describe('ErrorHandlingForm — 저장 모양 보존 (#753)', () => {
  /**
   * @scenario stored_shape=top_level_actions
   * @effects editor_reads_both_sequence_shapes
   */
  it('굽기 산출 모양(최상위 actions)의 하위 동작이 목록에 보인다', () => {
    renderForm({
      '404': { handler: 'sequence', actions: [{ handler: 'toast', params: { message: 'a' } }, { handler: 'navigate', params: { path: '/x' } }] },
    });

    expect(screen.getByTestId('g7le-error-seq-404-item-0')).toBeTruthy();
    expect(screen.getByTestId('g7le-error-seq-404-item-1')).toBeTruthy();
  });

  /**
   * @scenario stored_shape=params_actions
   * @effects editor_reads_both_sequence_shapes
   */
  it('params.actions 모양도 그대로 읽는다 (회귀)', () => {
    renderForm({ '404': { handler: 'parallel', params: { actions: [{ handler: 'toast', params: { message: 'a' } }] } } });

    expect(screen.getByTestId('g7le-error-seq-404-item-0')).toBeTruthy();
  });

  /**
   * @scenario stored_shape=top_level_actions
   * @effects editor_saves_top_level_actions_and_keeps_other_keys
   */
  it('하위 동작을 고치면 최상위 actions 로 저장하고 다른 키를 보존한다', () => {
    const onChange = renderForm({
      '404': {
        handler: 'sequence',
        comment: '404 안내',
        params: { actions: [{ handler: 'toast', params: { message: 'a' } }, { handler: 'navigate', params: { path: '/x' } }], keep: 1 },
      },
    });

    fireEvent.click(screen.getByTestId('g7le-error-seq-404-remove-1'));

    const saved = onChange.mock.calls.at(-1)![0]['404'];
    expect(saved).toEqual({ handler: 'sequence', comment: '404 안내', params: { keep: 1 }, actions: [{ handler: 'toast', params: { message: 'a' } }] });
  });

  /**
   * @scenario stored_shape=params_actions
   * @effects editor_saves_top_level_actions_and_keeps_other_keys
   */
  it('params 에 actions 만 있었으면 저장 때 params 키 자체를 지운다', () => {
    const onChange = renderForm({
      '404': { handler: 'sequence', params: { actions: [{ handler: 'toast', params: { message: 'a' } }, { handler: 'toast', params: { message: 'b' } }] } },
    });

    fireEvent.click(screen.getByTestId('g7le-error-seq-404-remove-0'));

    const saved = onChange.mock.calls.at(-1)![0]['404'];
    expect(saved).toEqual({ handler: 'sequence', actions: [{ handler: 'toast', params: { message: 'b' } }] });
  });

  /**
   * @scenario stored_shape=code_only
   * @effects editor_keeps_code_only_actions_read_only
   */
  it.each([
    ['conditions', { handler: 'conditions', conditions: [{ if: '{{error.status === 404}}', then: { handler: 'toast', params: { message: 'x' } } }] }],
    ['switch', { handler: 'switch', params: { value: '{{error.status}}' }, cases: { '404': { handler: 'toast' } } }],
    ['action-ref', { actionRef: 'notFound' }],
    ['if 붙은 동작', { handler: 'toast', if: '{{error.status === 404}}', params: { message: 'x' } }],
    ['칸이 모르는 핸들러', { handler: 'sirsoft-ecommerce.claimApplyOrderMissing' }],
  ])('%s 는 읽기 전용 안내와 코드 미리보기만 보여 주고 고치지 않는다', (_label, action) => {
    const onChange = renderForm({ '404': action });

    expect(screen.getByTestId('g7le-error-code-only-404').textContent).toContain('layout_editor.error.handler_code_only');
    expect(screen.queryByTestId('g7le-error-handler-404')).toBeNull();
    fireEvent.click(screen.getByTestId('g7le-error-code-404'));
    expect(screen.getByTestId('g7le-error-code-view-404').textContent).toContain(JSON.stringify(action, null, 2).split('\n')[1].trim());
    expect(onChange).not.toHaveBeenCalled();
  });

  /**
   * 엔진은 처리기를 통째로 실행하므로(engine-v1.80.0) 처리기의 onSuccess·resultTo 도 돈다 — 칸이 다루지 않는 이 키들이
   * 칸에서 값을 고치는 순간 사라지면 저장한 동작이 조용히 바뀐다.
   *
   * @scenario stored_shape=single_with_extra_keys
   * @effects editor_saves_top_level_actions_and_keeps_other_keys
   */
  it('단일 동작을 고쳐도 칸이 다루지 않는 키(comment·onSuccess·onError·resultTo·errorHandling)를 보존한다', () => {
    const extra = {
      comment: '404 안내',
      onSuccess: { handler: 'navigate', params: { path: '/x' } },
      onError: { handler: 'toast', params: { message: 'e' } },
      resultTo: { target: '_local', key: 'r' },
      errorHandling: { '500': { handler: 'toast', params: { message: 'f' } } },
    };
    const onChange = renderForm({ '404': { handler: 'showErrorPage', params: { target: 'content' }, ...extra } });

    const box = screen.getByTestId('g7le-error-action-404-edit-param-target');
    const select = box.querySelector('select') as HTMLSelectElement;
    fireEvent.change(select, { target: { value: 'full' } });

    const saved = onChange.mock.calls.at(-1)![0]['404'];
    expect(saved).toMatchObject({ handler: 'showErrorPage', ...extra });
    expect(saved.params.target).toBe('full');
  });

  /**
   * 계획서 W7 「저장은 최상위 actions」 — 선택칸에서 처음 순차·동시 실행을 고를 때도 굽기 산출 모양으로 저장한다.
   *
   * @scenario stored_shape=top_level_actions
   * @effects editor_saves_top_level_actions_and_keeps_other_keys
   */
  it.each(['sequence', 'parallel'])('선택칸에서 처음 %s 를 고르면 최상위 actions 모양으로 저장한다', (handler) => {
    const onChange = renderForm({ '404': { handler: 'showErrorPage', params: { target: 'content' } } });

    fireEvent.change(screen.getByTestId('g7le-error-handler-404'), { target: { value: handler } });

    const saved = onChange.mock.calls.at(-1)![0]['404'];
    expect(saved.handler).toBe(handler);
    expect(Array.isArray(saved.actions)).toBe(true);
    expect(saved.params?.actions).toBeUndefined();
  });

  /**
   * @scenario stored_shape=code_only
   * @effects editor_keeps_code_only_actions_read_only
   */
  it('동작이 여럿인 배열은 첫 동작만 고쳐 나머지를 덮지 않도록 읽기 전용으로 둔다', () => {
    const onChange = renderForm({ '404': [{ handler: 'toast', params: { message: 'a' } }, { handler: 'navigate', params: { path: '/x' } }] });

    expect(screen.getByTestId('g7le-error-code-only-404')).toBeTruthy();
    expect(screen.queryByTestId('g7le-error-handler-404')).toBeNull();
    expect(onChange).not.toHaveBeenCalled();
  });
});

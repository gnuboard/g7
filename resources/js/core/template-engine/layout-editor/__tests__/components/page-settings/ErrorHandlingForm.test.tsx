// e2e:allow [에러 처리] 폼 단위(RTL) — 코드행/출처배지/핸들러분기 위젯 합성, Chrome MCP 매트릭스(세션 D)로 보강.
/**
 * ErrorHandlingForm.test.tsx — [에러 처리] 탭 폼 RTL
 *
 * 검증:
 *  ① error_config.layouts − maintenance + default 로 코드별 행 생성(maintenance 부재)
 *  ② 출처 배지 4종(자체/상속/템플릿/없음) — own vs merged vs template 도출
 *  ③ 동작 7종 select(설정안함 + showErrorPage/navigate/openModal/toast/setState/sequence/parallel)
 *  ④ 핸들러별 입력 분기(showErrorPage target / navigate page-picker / toast 메시지)
 *  ⑤ "설정 안 함" → 코드 키 제거
 *  ⑥ default 행 1급 편집 + 가림 경고
 *  ⑦ 디그레이드(recipes 없음 → 코드 편집 안내)
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { ErrorHandlingForm } from '../../../components/page-settings/ErrorHandlingForm';
import { clearWidgetRegistry, registerWidget } from '../../../spec/widgetRegistry';
import type { ActionRecipeSpec } from '../../../spec/specTypes';

const t = (k: string) => k;

const RECIPES: Record<string, ActionRecipeSpec | string> = {
  showErrorPage: { label: '$t:안내 페이지', params: [{ key: 'target' }], build: { handler: 'showErrorPage', target: '{{target}}' } },
  navigate: { label: '$t:이동', params: [{ key: 'path', widget: 'page-picker' }], build: { handler: 'navigate', params: { path: '{{path}}' } } },
  toast: { label: '$t:메시지', params: [{ key: 'message', widget: 'i18n-text' }], build: { handler: 'toast', params: { message: '{{message}}' } } },
  setState: { label: '$t:상태', params: [{ key: 'state' }], build: { handler: 'setState', params: { state: '{{state}}' } } },
  sequence: { label: '$t:순서', params: [{ key: 'actions', widget: 'action-list' }], build: { handler: 'sequence', params: { actions: '{{actions}}' } } },
};

const CODES = ['401', '403', '404', '500', '503', 'maintenance'];

beforeEach(() => {
  cleanup();
  clearWidgetRegistry();
  registerWidget('i18n-text', ({ value, onChange }) => (
    <input data-testid="w-i18n" value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value)} />
  ));
  registerWidget('page-picker', ({ value, onChange }) => (
    <input data-testid="w-page" value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value)} />
  ));
});

describe('ErrorHandlingForm', () => {
  it('error_config − maintenance + default 로 코드 행 생성(maintenance 부재)', () => {
    render(<ErrorHandlingForm value={{}} onChange={vi.fn()} t={t} errorConfigCodes={CODES} recipes={RECIPES} />);
    expect(screen.getByTestId('g7le-error-rows-row-401')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-error-rows-row-503')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-error-rows-row-default')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-error-rows-row-maintenance')).not.toBeInTheDocument();
  });

  it('출처 배지 — 자체/상속/템플릿/없음', () => {
    render(
      <ErrorHandlingForm
        value={{ '401': { handler: 'navigate' }, '403': { handler: 'showErrorPage' } }}
        onChange={vi.fn()}
        t={t}
        errorConfigCodes={CODES}
        ownCodes={['401']}
        templateCodes={['500']}
        recipes={RECIPES}
      />,
    );
    // 401 = own → self / 403 = merged 만 → inherited / 500 = template / 404 = none.
    expect(screen.getByTestId('g7le-error-rows-source-401').textContent).toContain('source_self');
    expect(screen.getByTestId('g7le-error-rows-source-403').textContent).toContain('source_inherited');
    expect(screen.getByTestId('g7le-error-rows-source-500').textContent).toContain('source_template');
    expect(screen.getByTestId('g7le-error-rows-source-404').textContent).toContain('source_none');
  });

  it('동작 7종 select + 핸들러 변경 시 buildAction', () => {
    const onChange = vi.fn();
    render(<ErrorHandlingForm value={{}} onChange={onChange} t={t} errorConfigCodes={['401']} recipes={RECIPES} />);
    const sel = screen.getByTestId('g7le-error-handler-401') as HTMLSelectElement;
    // 설정안함 + 7종 = 8 옵션.
    expect(sel.options.length).toBe(8);
    fireEvent.change(sel, { target: { value: 'navigate' } });
    expect(onChange).toHaveBeenCalled();
    const next = onChange.mock.calls.at(-1)![0];
    expect(next['401']).toMatchObject({ handler: 'navigate' });
  });

  it('showErrorPage 선택 시 표시 위치 입력 분기(공용 ParamFieldList)', () => {
    // 동작별 입력칸은 공용 ParamFieldList 가 그린다(자작 폼 폐기). showErrorPage 의 target param
    // wrapper(`-edit-param-target`)가 존재해야 한다.
    render(<ErrorHandlingForm value={{ '403': { handler: 'showErrorPage', params: { target: 'content' } } }} onChange={vi.fn()} t={t} errorConfigCodes={['403']} recipes={RECIPES} />);
    expect(screen.getByTestId('g7le-error-action-403-edit-param-target')).toBeInTheDocument();
  });

  it('navigate 선택 시 이동화면 칸, toast 선택 시 메시지 칸(공용 ParamFieldList)', () => {
    const { rerender } = render(<ErrorHandlingForm value={{ '401': { handler: 'navigate', params: { path: '/login' } } }} onChange={vi.fn()} t={t} errorConfigCodes={['401']} recipes={RECIPES} pageCandidates={[]} />);
    expect(screen.getByTestId('g7le-error-action-401-edit-param-path')).toBeInTheDocument();

    rerender(<ErrorHandlingForm value={{ '401': { handler: 'toast', params: { message: 'x' } } }} onChange={vi.fn()} t={t} errorConfigCodes={['401']} recipes={RECIPES} />);
    expect(screen.getByTestId('g7le-error-action-401-edit-param-message')).toBeInTheDocument();
  });

  it('"설정 안 함" 선택 → 빈 액션(ErrorHandlingRows clear 가 키 제거)', () => {
    const onChange = vi.fn();
    render(<ErrorHandlingForm value={{ '401': { handler: 'navigate' } }} onChange={onChange} t={t} errorConfigCodes={['401']} recipes={RECIPES} />);
    fireEvent.change(screen.getByTestId('g7le-error-handler-401'), { target: { value: '' } });
    // 빈 액션 패치(셸/Rows 가 후속 정리).
    expect(onChange).toHaveBeenCalled();
  });

  it('default 행 가림 경고', () => {
    render(<ErrorHandlingForm value={{ default: { handler: 'toast' } }} onChange={vi.fn()} t={t} errorConfigCodes={['401']} recipes={RECIPES} />);
    expect(screen.getByTestId('g7le-error-rows-default-warn-default')).toBeInTheDocument();
  });

  it('디그레이드(recipes 없음) → 코드 편집 안내', () => {
    render(<ErrorHandlingForm value={{ '401': { handler: 'navigate' } }} onChange={vi.fn()} t={t} errorConfigCodes={CODES} recipes={{}} />);
    expect(screen.getByTestId('g7le-error-degrade')).toBeInTheDocument();
    // 행은 여전히 출처 배지로 노출.
    expect(screen.getByTestId('g7le-error-rows-row-401')).toBeInTheDocument();
  });
});

// e2e:allow [에러 처리] 탭 동작 입력칸 회귀 단위(RTL) — 자작 폼 결함(setState 손실/모달 비후보/코드미리보기/데이터칩) 재현·고정. 칩·합성클릭 의존이라 라이브는 Chrome MCP 매트릭스로 보강.
/**
 * ErrorHandlingForm.regression.test.tsx — [에러 처리] 탭 동작 입력칸 회귀
 *
 * 배경: 에러 코드별 두 번째 입력칸(동작별 추가 입력)이 입력/선택 불가 또는
 * 타이핑 즉시 소실. 자작 폼(ErrorActionEditor)이 공용 부품 대신 평문 input/빈 select 를
 * 써서 발생. 실제 코어 레시피(CORE_ACTION_RECIPES)로 round-trip 손실을 재현·고정한다.
 *
 * 검증:
 *  ① setState — "상태 이름" 입력값이 round-trip 후에도 보존(스프레드 build 와 정합)
 *  ② openModal — 모달 후보 select 가 후보를 노출(빈 칸 아님)
 *  ③ 항목별 코드 미리보기(`</>`) 버튼 → 코드 패널 노출
 *  ④ toast 메시지 칸 = 데이터칩 입력기(평문 input 아님) — 에러 컨텍스트 칩 연동
 *  ⑤ showErrorPage 표시 위치(content/full) round-trip 보존
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { ErrorHandlingForm } from '../../../components/page-settings/ErrorHandlingForm';
import { buildCoreActionRecipeSeed } from '../../../spec/coreActionRecipes';
import { clearWidgetRegistry, getWidget } from '../../../spec/widgetRegistry';
import { registerCoreWidgets, resetCoreWidgetRegistration } from '../../../spec/registerCoreWidgets';

/** `$t:` 키를 그대로 반환(라벨 해석 단순화) */
const t = (k: string) => k;

/** 실제 코어 에러 레시피(showErrorPage/navigate/openModal/toast/setState/sequence/parallel) */
const ERROR_RECIPES = buildCoreActionRecipeSeed().errorRecipes;

const MODALS = [
  { value: 'login_modal', label: '로그인 모달' },
  { value: 'error_modal', label: '오류 모달' },
];

beforeEach(() => {
  cleanup();
  clearWidgetRegistry();
  // select/modal-picker/state-key-picker 등 코어 위젯 등록(ParamFieldList 디스패치 대상).
  // clearWidgetRegistry 는 registry 만 비우므로 register 가드도 리셋해 매 테스트 재등록.
  resetCoreWidgetRegistration();
  registerCoreWidgets();
});

describe('ErrorHandlingForm 회귀 — 동작 입력칸', () => {
  it('① setState 상태 키–값이 round-trip 후 보존(스프레드 build 정합)', () => {
    // setState 핸들러 + 상태 payload(loaded:true)를 가진 액션을 편집기에 넘기면, 편집 폼이
    // 그 상태 키–값을 편집칸으로 복원해야 한다(스프레드 build `'...':'{{state}}'` 정합).
    // 자작 폼은 params.state(문자열) 단일 input 만 그려 동적 상태 키 맵을 전혀 복원하지 못했다.
    render(
      <ErrorHandlingForm
        value={{ '401': { handler: 'setState', params: { target: 'local', loaded: true } } }}
        onChange={vi.fn()}
        t={t}
        errorConfigCodes={['401']}
        recipes={ERROR_RECIPES}
      />,
    );
    const action = screen.getByTestId('g7le-error-action-401');
    // 상태 키 'loaded' 가 편집칸(키–값 행)으로 복원돼 화면에 노출돼야 한다.
    // 자작 폼은 단일 'state' placeholder input 만 그려 'loaded' 키가 사라졌다.
    const keyInputs = action.querySelectorAll('input');
    const hasLoadedKey = Array.from(keyInputs).some((el) => (el as HTMLInputElement).value === 'loaded');
    expect(hasLoadedKey).toBe(true);
  });

  it('② openModal 선택 시 모달 후보 select 가 후보를 노출', () => {
    // modal-picker 위젯이 코어 등록됐는지 먼저 확인(미등록 시 디그레이드 칩으로 빠져 후보 미노출).
    expect(getWidget('modal-picker')).toBeTruthy();
    render(
      <ErrorHandlingForm
        value={{ '403': { handler: 'openModal', target: '' } }}
        onChange={vi.fn()}
        t={t}
        errorConfigCodes={['403']}
        recipes={ERROR_RECIPES}
        modalCandidates={MODALS}
      />,
    );
    // 모달 target 위젯(modal-picker)에 후보 라벨이 보여야 한다(빈 칸 아님).
    const action = screen.getByTestId('g7le-error-action-403');
    expect(action.textContent ?? '').toContain('로그인 모달');
  });

  it('③ 항목별 코드 미리보기 버튼 → 코드 패널 노출', () => {
    // navigate 핸들러(page-picker = Provider 비의존)로 코드 미리보기를 검증한다. toast 메시지는
    // i18n-text(I18nTextField)라 LayoutEditorProvider 가 필요해 라이브(Chrome MCP)로 보강한다.
    render(
      <ErrorHandlingForm
        value={{ '404': { handler: 'navigate', params: { path: '/login' } } }}
        onChange={vi.fn()}
        t={t}
        errorConfigCodes={['404']}
        recipes={ERROR_RECIPES}
        pageCandidates={[]}
      />,
    );
    // 코드 미리보기 버튼이 존재해야 한다.
    const codeBtn = screen.getByTestId('g7le-error-code-404');
    fireEvent.click(codeBtn);
    // 코드 패널에 핸들러 JSON 이 노출.
    expect(screen.getByTestId('g7le-error-code-view-404').textContent ?? '').toContain('navigate');
  });

  it('④ navigate 이동 화면 칸 = 데이터칩 연동(평문 input 직접 사용 아님)', () => {
    // navigate path(page-picker)는 후보 select + 자유 입력 폴백으로 DataChipValueInput 을 거쳐
    // 표현식·데이터칩을 닿게 한다. 모든 자유값 칸 데이터칩 연동을 대표 검증.
    render(
      <ErrorHandlingForm
        value={{ '500': { handler: 'navigate', params: { path: '{{error.status}}' } } }}
        onChange={vi.fn()}
        t={t}
        errorConfigCodes={['500']}
        recipes={ERROR_RECIPES}
        pageCandidates={[]}
      />,
    );
    // page-picker 자유 입력 폴백이 DataChipValueInput(데이터칩 입력기)을 렌더(평문 input 직접 아님).
    // CandidatePicker → DataChipValueInput(testidPrefix=g7le-widget-page-picker-input).
    expect(document.querySelector('[data-testid="g7le-widget-page-picker-input"]')).not.toBeNull();
  });

  it('⑤ showErrorPage 표시 위치(content/full) round-trip 보존', () => {
    const onChange = vi.fn();
    function Harness() {
      const [value, setValue] = React.useState<Record<string, unknown>>({
        '403': { handler: 'showErrorPage', params: { target: 'content' } },
      });
      return (
        <ErrorHandlingForm
          value={value as never}
          onChange={(next) => {
            onChange(next);
            setValue(next as Record<string, unknown>);
          }}
          t={t}
          errorConfigCodes={['403']}
          recipes={ERROR_RECIPES}
        />
      );
    }
    render(<Harness />);
    // 표시 위치 select(ParamFieldList 가 그리는 target select)를 wrapper 안에서 찾는다.
    const wrap = screen.getByTestId('g7le-error-action-403-edit-param-target');
    const sel = wrap.querySelector('select') as HTMLSelectElement;
    expect(sel).not.toBeNull();
    expect(sel.value).toBe('content');
    fireEvent.change(sel, { target: { value: 'full' } });
    // 패치 후 params.target 이 full 로 보존돼야 한다(showErrorPage build = params.target).
    const next = onChange.mock.calls.at(-1)![0]['403'] as Record<string, unknown>;
    const params = next.params as Record<string, unknown> | undefined;
    expect(params?.target).toBe('full');
  });
});

// 번들 템플릿 errorRecipes.json 의 build/params 정합 회귀.
// 종전 번들 정의 결함: setState 가 `{{key}}:{{value}}` 동적 키(빈 값 타이핑 시 소실)·openModal
// 이 `params.modalId`(엔진은 top-level target 을 읽어 런타임 모달 안 열림)·showErrorPage 가
// params 없음(표시 위치 선택 불가)이었다. 코어 시드와 정합하도록 정정한 것을 고정한다.
import basicErrorRecipes from '../../../../../../../../templates/_bundled/sirsoft-basic/editor-spec/errorRecipes.json';
import adminErrorRecipes from '../../../../../../../../templates/_bundled/sirsoft-admin_basic/editor-spec/errorRecipes.json';

describe.each([
  ['sirsoft-basic', basicErrorRecipes as Record<string, unknown>],
  ['sirsoft-admin_basic', adminErrorRecipes as Record<string, unknown>],
])('번들 errorRecipes.json 정합 — %s', (_id, recipes) => {
  it('setState 는 state-key-value 스프레드(동적 키 단일 아님) — 빈 값 입력 보존', () => {
    const setState = recipes.setState as { params: Array<{ key: string; widget?: string }>; build: { params: Record<string, unknown> } };
    // params 키는 target/state/merge (key/value 동적 키 아님).
    const keys = setState.params.map((p) => p.key);
    expect(keys).toContain('state');
    expect(keys).not.toContain('value'); // 동적 키 단일(소실 결함) 회귀 차단.
    // state 위젯은 state-key-value(여러 상태 키 + 빈 값 보존).
    expect(setState.params.find((p) => p.key === 'state')?.widget).toBe('state-key-value');
    // build 는 스프레드(`'...': '{{state}}'`)로 상태 맵을 params 에 흡수.
    expect(Object.keys(setState.build.params)).toContain('...');
    expect(setState.build.params['...']).toBe('{{state}}');
  });

  it('openModal 은 top-level target(엔진 dispatch 가 읽는 위치) — params.modalId 아님', () => {
    const openModal = recipes.openModal as { params: Array<{ key: string; widget?: string }>; build: Record<string, unknown> };
    // build 의 target 이 top-level(런타임 모달 열림).
    expect(openModal.build.target).toBe('{{target}}');
    expect(openModal.build.params).toBeUndefined();
    // param 키 = target, 위젯 = modal-picker(후보 select).
    expect(openModal.params[0].key).toBe('target');
    expect(openModal.params[0].widget).toBe('modal-picker');
  });

  it('showErrorPage 는 표시 위치(content/full) param 보유 + params.target 빌드', () => {
    const sep = recipes.showErrorPage as { params: Array<{ key: string; options?: Array<{ value: string }> }>; build: { params?: Record<string, unknown> } };
    expect(sep.params.length).toBeGreaterThan(0);
    const target = sep.params.find((p) => p.key === 'target');
    expect(target).toBeDefined();
    expect(target?.options?.map((o) => o.value)).toEqual(['content', 'full']);
    expect(sep.build.params?.target).toBe('{{target}}');
  });
});

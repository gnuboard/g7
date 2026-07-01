// e2e:allow 동작 params 편집 공용 부품 단위(RTL) — 데이터칩/표현식 입력기는 칩 입력기·합성 클릭 의존으로 Playwright 부적합. 단위 + Chrome MCP 매트릭스로 검증.
/**
 * ActionParamFields.test.tsx — 동작 params 편집 디스패치 RTL
 *
 * [화면 동작]·[동작]·[에러] 탭의 모든 액션 입력칸을 "데이터칩·표현식 친화"로 만든 디스패치를
 * 검증한다. 검증:
 *  ① text/data-chip/number 자유값 → DataChipValueInput(평문 input 아님)
 *  ② key-value → 키 평문 + 값 데이터칩(KeyValueChipEditor)
 *  ③ state-key-value → 키 = 상태키 검색(state-key-picker) + 값 데이터칩
 *  ④ select/toggle 고정값 → 등록 위젯(칩화 제외, "값 받는 칸만 칩")
 *  ⑤ dependsOn 게이팅 — 조건 불충족 param 숨김
 *  ⑥ 값 변경 → buildAction 재생성(round-trip)
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { ParamFieldList, ActionIfToggle } from '../../../components/page-settings/ActionParamFields';
import { normalizeActionRecipes } from '../../../spec/actionRecipeEngine';
import { registerCoreWidgets, resetCoreWidgetRegistration } from '../../../spec/registerCoreWidgets';
import { CORE_ACTION_RECIPES } from '../../../spec/coreActionRecipes';

const t = (k: string) => k;

beforeEach(() => {
  cleanup();
  resetCoreWidgetRegistration();
  registerCoreWidgets();
});

/** 단일 레시피를 normalize 해 그 recipe 를 돌려준다. */
function recipeOf(spec: Record<string, unknown>) {
  return normalizeActionRecipes({ r: spec })[0];
}

describe('ActionParamFields — 자유값 칸 데이터칩 친화 디스패치', () => {
  it('① text/number 위젯 → DataChipValueInput(평문 input 아님)', () => {
    const recipe = recipeOf({
      label: 'x',
      params: [
        { key: 'key', widget: 'text' },
        { key: 'dur', widget: 'number' },
      ],
      build: { handler: 'h', params: { key: '{{key}}', dur: '{{dur}}' } },
    });
    render(
      <ParamFieldList raw={{ handler: 'h' }} recipe={recipe} values={{}} t={t} pools={{}} onChange={vi.fn()} testIdPrefix="p" />,
    );
    // 데이터칩 입력기 컨테이너(DataChipValueInput testidPrefix = `p-chip-{key}`)가 렌더된다.
    expect(screen.getByTestId('p-chip-key')).toBeInTheDocument();
    expect(screen.getByTestId('p-chip-dur')).toBeInTheDocument();
    // 평문 분기 input 존재(칩 입력기 내부).
    expect(screen.getByTestId('p-chip-key-input')).toBeInTheDocument();
  });

  it('① 값 변경 → buildAction 재생성(round-trip)', () => {
    const onChange = vi.fn();
    const recipe = recipeOf({ label: 'x', params: [{ key: 'k', widget: 'text' }], build: { handler: 'saveToLocalStorage', params: { key: '{{k}}' } } });
    render(<ParamFieldList raw={{ handler: 'saveToLocalStorage' }} recipe={recipe} values={{}} t={t} pools={{}} onChange={onChange} testIdPrefix="p" />);
    fireEvent.change(screen.getByTestId('p-chip-k-input'), { target: { value: 'recentIds' } });
    expect(onChange).toHaveBeenCalled();
    const next = onChange.mock.calls.at(-1)![0];
    expect(next).toEqual({ handler: 'saveToLocalStorage', params: { key: 'recentIds' } });
  });

  it('② key-value → 키 평문 + 값 데이터칩(KeyValueChipEditor)', () => {
    const recipe = recipeOf({ label: 'x', params: [{ key: 'query', widget: 'key-value' }], build: { handler: 'navigate', params: { query: '{{query}}' } } });
    render(
      <ParamFieldList raw={{ handler: 'navigate' }} recipe={recipe} values={{ query: { tab: 'a' } }} t={t} pools={{}} onChange={vi.fn()} testIdPrefix="p" />,
    );
    // KeyValueMapField testIdPrefix = `p-kv-query` → 기존 행(tab=a) 키 input.
    expect(screen.getByTestId('p-kv-query-row-0')).toBeInTheDocument();
    expect(screen.getByTestId('p-kv-query-key-0')).toBeInTheDocument();
    // 값측은 데이터칩 입력기(KeyValueChipEditor 내부 DataChipValueInput).
    expect(screen.getByTestId('p-kv-query-value-0')).toBeInTheDocument();
  });

  it('③ state-key-value → 키 = 상태키 검색 위젯(state-key-picker) + 값 = 재귀 블럭 편집기', () => {
    const recipe = recipeOf({
      label: 'x',
      params: [{ key: 'state', widget: 'state-key-value' }],
      build: { handler: 'setState', params: { '...': '{{state}}' } },
    });
    render(
      <ParamFieldList
        raw={{ handler: 'setState' }}
        recipe={recipe}
        values={{ state: { form: '' } }}
        t={t}
        pools={{ stateKeyCandidates: [{ value: 'form', label: 'form' }] }}
        onChange={vi.fn()}
        testIdPrefix="p"
      />,
    );
    // 키칸이 state-key-picker(후보 select) 로 교체됨(행 + 추가행에 2개 이상).
    expect(screen.getAllByTestId('g7le-widget-state-key-picker').length).toBeGreaterThan(0);
    // 값측 = 재귀 블럭 편집기(InitialStateValueEditor) 의 종류 select. path = 상태키(form).
    expect(screen.getByTestId('g7le-initstate-type-form')).toBeInTheDocument();
  });

  // path-style setState: target 이 경로(`_local.isSubmittingOrder`)이고 값이 `{value:V}` 면,
  // `value` 는 상태 키 이름이 아니라 "그 경로에 넣을 값"이다. 단일 값 입력칸으로 표시하고
  // `value` 를 상태 키로 오인 표시하지 않는다. 불리언 true 는 타입 보존(InitialStateValueEditor).
  it('③-path setState path-style({target:_local.X, value:V}) → 값을 상태키 아닌 단일 값으로 표시', () => {
    const recipe = recipeOf({
      label: 'x',
      params: [
        { key: 'target', widget: 'select', options: [{ value: 'local' }, { value: 'global' }] },
        { key: 'state', widget: 'state-key-value' },
        { key: 'merge', widget: 'toggle' },
      ],
      build: { handler: 'setState', params: { target: '{{target}}', merge: '{{merge}}', '...': '{{state}}' } },
    });
    render(
      <ParamFieldList
        raw={{ handler: 'setState' }}
        recipe={recipe}
        values={{ target: '_local.isSubmittingOrder', state: { value: true } }}
        t={t}
        pools={{}}
        onChange={vi.fn()}
        testIdPrefix="p"
      />,
    );
    // path-value 단일 입력 분기 렌더(상태키 행 아님).
    expect(screen.getByTestId('p-skv-state-path-value')).toBeInTheDocument();
    // `value` 가 상태 키 행으로 표시되지 않음(키칸 row-value 부재).
    expect(screen.queryByTestId('p-skv-state-row-value')).not.toBeInTheDocument();
    // 불리언 true 종류 — InitialStateValueEditor 타입 select(g7le-initstate-type-{path}, path=value).
    expect(screen.getByTestId('g7le-initstate-type-value')).toBeInTheDocument();
  });

  it('③-path setState 값 변경 시 {value:...} shape 보존 (target 은 유지)', () => {
    const onChange = vi.fn();
    const recipe = recipeOf({
      label: 'x',
      params: [
        { key: 'target', widget: 'select', options: [{ value: 'local' }] },
        { key: 'state', widget: 'state-key-value' },
        { key: 'merge', widget: 'toggle' },
      ],
      build: { handler: 'setState', params: { target: '{{target}}', merge: '{{merge}}', '...': '{{state}}' } },
    });
    render(
      <ParamFieldList raw={{ handler: 'setState' }} recipe={recipe} values={{ target: '_local.flag', state: { value: false } }} t={t} pools={{}} onChange={onChange} testIdPrefix="p" />,
    );
    // boolean 토글을 true 로 — 타입 보존 + {value:true} shape. ToggleSwitch testid = g7le-initstate-value-{path}.
    const boolToggle = screen.getByTestId('g7le-initstate-value-value');
    fireEvent.click(boolToggle);
    const next = onChange.mock.calls.at(-1)?.[0];
    expect(next.params.target).toBe('_local.flag');
    expect(next.params.value).toBe(true);
  });

  it('④-body apiCall body 는 key-value 위젯 (객체 맵, [object Object]/JSON 분해 깨짐 회귀 차단)', () => {
    // apiCall body 는 항상 필드 맵이라 key-value 로 키별 데이터칩 편집(data-chip 단일 입력 금지).
    const recipe = normalizeActionRecipes({ r: CORE_ACTION_RECIPES.apiCall as unknown as Record<string, unknown> })[0];
    const bodyParam = recipe.params.find((p) => p.key === 'body');
    expect(bodyParam?.widget).toBe('key-value');
    // 렌더 시 객체 body 가 key-value 행으로 펼쳐짐([object Object] 아님).
    render(
      <ParamFieldList
        raw={{ handler: 'apiCall' }}
        recipe={recipe}
        values={{ body: { order_id: '{{x}}', amount: 1000 } }}
        t={t}
        pools={{}}
        onChange={vi.fn()}
        testIdPrefix="p"
      />,
    );
    // KeyValueMapField(KeyValueChipEditor) 가 body 객체를 키-값 행으로 펼친다(행 id 무관 — 2건 이상).
    const bodyRows = document.querySelectorAll('[data-testid^="p-kv-body-row-"]');
    expect(bodyRows.length).toBeGreaterThanOrEqual(2);
    // 어떤 입력칸에도 [object Object] 가 새지 않음.
    const objLeak = [...document.querySelectorAll('input')].some((i) => (i as HTMLInputElement).value.includes('[object Object]'));
    expect(objLeak).toBe(false);
  });

  // (B)setState 상태 값이 깊은 중첩 객체(`_local: {filter:{...}}`)여도
  // 블럭으로 펼쳐 편집한다. 종전 평면 KeyValueMapField 는 객체 값을 `String(v)` → "[object Object]"
  // 로 깨뜨렸다. 회귀 잠금: ① [object Object] 평문 미노출 ② 중첩 키가 블럭으로 펼쳐짐.
  it('(B) state-key-value 객체 값 → [object Object] 아닌 중첩 블럭 (회귀)', () => {
    const recipe = recipeOf({
      label: 'x',
      params: [{ key: 'state', widget: 'state-key-value' }],
      build: { handler: 'setState', params: { '...': '{{state}}' } },
    });
    render(
      <ParamFieldList
        raw={{ handler: 'setState' }}
        recipe={recipe}
        values={{ state: { _local: { filter: { searchField: "{{query.search_field || 'all'}}" } } } }}
        t={t}
        pools={{ stateKeyCandidates: [{ value: '_local', label: '_local' }] }}
        onChange={vi.fn()}
        testIdPrefix="p"
      />,
    );
    // ① "[object Object]" 문자열이 어디에도 노출되지 않는다(평면 정규화 회귀 차단).
    const inputs = [...document.querySelectorAll('input')].map((i) => (i as HTMLInputElement).value);
    expect(inputs).not.toContain('[object Object]');
    expect(document.body.textContent).not.toContain('[object Object]');
    // ② _local 값이 묶음(object) 블럭으로 펼쳐진다 — 종류 select = object, 하위 filter 키 블럭.
    const topType = screen.getByTestId('g7le-initstate-type-_local') as HTMLSelectElement;
    expect(topType.value).toBe('object');
    // 중첩 하위 키(filter)도 블럭으로 — depth path = `_local.filter`.
    expect(screen.getByTestId('g7le-initstate-type-_local.filter')).toBeInTheDocument();
    // 최심 문자 리프(searchField)는 데이터칩 입력기(표현식 1급) — 값이 `{{...}}` 식이라 표현식
    // 분해 트리/세그먼트로 렌더될 수 있으므로 컨테이너(testidPrefix) 존재로 단언(평문 input 단정 X).
    expect(screen.getByTestId('g7le-initstate-value-_local.filter.searchField')).toBeInTheDocument();
  });

  it('④ select/toggle 고정값 → 등록 위젯(칩화 제외)', () => {
    const recipe = recipeOf({
      label: 'x',
      params: [
        { key: 'type', widget: 'select', options: [{ value: 'info' }] },
        { key: 'merge', widget: 'toggle' },
      ],
      build: { handler: 'toast', params: { type: '{{type}}', merge: '{{merge}}' } },
    });
    render(<ParamFieldList raw={{ handler: 'toast' }} recipe={recipe} values={{}} t={t} pools={{}} onChange={vi.fn()} testIdPrefix="p" />);
    // 고정값 칸은 데이터칩 입력기로 바뀌지 않는다.
    expect(screen.queryByTestId('p-chip-type')).not.toBeInTheDocument();
    expect(screen.queryByTestId('p-chip-merge')).not.toBeInTheDocument();
  });

  it('⑤ dependsOn 게이팅 — 조건 불충족 시 param 숨김', () => {
    const recipe = recipeOf({
      label: 'x',
      params: [
        { key: 'replace', widget: 'toggle' },
        { key: 'overlay', widget: 'text', dependsOn: { param: 'replace', equals: true } },
      ],
      build: { handler: 'navigate', params: { replace: '{{replace}}', overlay: '{{overlay}}' } },
    });
    // replace 미충족(false/undefined) → overlay 숨김.
    const { rerender } = render(
      <ParamFieldList raw={{ handler: 'navigate' }} recipe={recipe} values={{}} t={t} pools={{}} onChange={vi.fn()} testIdPrefix="p" />,
    );
    expect(screen.queryByTestId('p-param-overlay')).not.toBeInTheDocument();
    expect(screen.getByTestId('p-param-replace')).toBeInTheDocument();
    // replace=true → overlay 노출.
    rerender(
      <ParamFieldList raw={{ handler: 'navigate' }} recipe={recipe} values={{ replace: true }} t={t} pools={{}} onChange={vi.fn()} testIdPrefix="p" />,
    );
    expect(screen.getByTestId('p-param-overlay')).toBeInTheDocument();
  });

  it('미등록/미지정 위젯 → 평문 폴백 아닌 데이터칩(안전 디그레이드)', () => {
    const recipe = recipeOf({ label: 'x', params: [{ key: 'mystery', widget: 'no-such-widget' }], build: { handler: 'h', params: { mystery: '{{mystery}}' } } });
    render(<ParamFieldList raw={{ handler: 'h' }} recipe={recipe} values={{}} t={t} pools={{}} onChange={vi.fn()} testIdPrefix="p" />);
    expect(screen.getByTestId('p-chip-mystery')).toBeInTheDocument();
  });

  // (A)저장할 값은 데이터 값(배열/식)이라 data-chip(DataChipValueInput).
  // i18n-text(prose, 복잡식 read-only 디그레이드) 아님. saveToLocalStorage 실 스펙으로 잠근다.
  it('(A) saveToLocalStorage value = data-chip → 복잡 표현식도 칩 입력기(read-only 디그레이드 아님)', () => {
    const recipe = normalizeActionRecipes(CORE_ACTION_RECIPES as never).find((r) => r.id === 'saveToLocalStorage')!;
    const expr = '{{[Number(route.id)].slice(0, 20)}}';
    render(
      <ParamFieldList raw={{ handler: 'saveToLocalStorage' }} recipe={recipe} values={{ key: 'k', value: expr }} t={t} pools={{}} onChange={vi.fn()} testIdPrefix="p" />,
    );
    // value 칸이 DataChipValueInput(테스트 prefix p-chip-value) — 표현식 분해 트리/칩 입력기.
    expect(screen.getByTestId('p-chip-value')).toBeInTheDocument();
    // i18n-text 위젯(번역키 read-only 배지)으로 렌더되지 않는다.
    expect(screen.queryByTestId('g7le-widget-i18n-text')).not.toBeInTheDocument();
  });

  // 스펙 분류 잠금 — prose(message) 는 i18n-text 유지, 데이터 값은 data-chip/text.
  it('(A) prose param(message) 는 i18n-text 유지 — 분류 회귀 차단', () => {
    const recipes = normalizeActionRecipes(CORE_ACTION_RECIPES as never);
    const widgetOf = (id: string, key: string) => recipes.find((r) => r.id === id)!.params.find((p) => p.key === key)?.widget;
    expect(widgetOf('toast', 'message')).toBe('i18n-text'); // prose
    expect(widgetOf('showAlert', 'target')).toBe('i18n-text'); // prose
    expect(widgetOf('saveToLocalStorage', 'value')).toBe('data-chip'); // 데이터 값
  });
});

// (C)실행 조건(if)은 식이라 bare input 아닌 데이터칩+표현식 입력기.
describe('ActionIfToggle — 실행조건 친화 입력', () => {
  it('(C) 펼치면 bare input 이 아니라 DataChipValueInput(칩+표현식)으로 렌더 + 값 변경 반영', () => {
    const onChange = vi.fn();
    render(<ActionIfToggle raw={{ handler: 'toast' }} t={t} onChange={onChange} testIdPrefix="p" />);
    // 토글 펼치기
    fireEvent.click(screen.getByText(/if_label/));
    // if-chip(DataChipValueInput) 평문 분기 input 존재 — bare <input data-testid="p-if-input"> 아님.
    const chipInput = screen.getByTestId('p-if-chip-input');
    expect(chipInput).toBeInTheDocument();
    fireEvent.change(chipInput, { target: { value: '{{user?.uuid}}' } });
    expect(onChange).toHaveBeenCalled();
    expect(onChange.mock.calls.at(-1)![0].if).toBe('{{user?.uuid}}');
  });
});

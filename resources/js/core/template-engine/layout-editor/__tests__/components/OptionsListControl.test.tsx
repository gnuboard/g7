/**
 * OptionsListControl.test.tsx — `options-list` 위젯 RTL
 *
 * 검증:
 *  - 옵션 배열 행 렌더 (value 입력 + label = 공통 다국어 위젯 I18nTextField)
 *  - 항목 추가/삭제/이동 → onChange(옵션 배열)
 *  - 마지막 항목 삭제 → onChange(undefined) (prop 삭제)
 *  - 정적-바인딩 가드: `{{...}}` 바인딩 값 → 디그레이드 표시(편집 비대상)
 *  - **7-b 옵션 라벨 다국어**: label 이 `$t:` 키면 raw 노출 없이 해석값 미리보기,
 *    평문 입력 → createCustomKey → `$t:custom.*` 토큰을 label 에 기록.
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup, waitFor } from '@testing-library/react';

// I18nTextField(라벨 위젯)가 읽는 컨텍스트/CRUD/해석 모킹.
vi.mock('../../LayoutEditorContext', () => ({
  useLayoutEditor: () => ({
    state: { templateIdentifier: 'sirsoft-basic', locale: 'ko', selectedRoute: { path: '/shop', layoutName: 'shop' } },
  }),
}));
const createCustomKey = vi.fn();
const updateCustomKeyValue = vi.fn();
const findCustomKeyRow = vi.fn();
const bustTranslationCache = vi.fn().mockResolvedValue(undefined);
vi.mock('../../hooks/useInlineEdit', () => ({
  createCustomKey: (...a: unknown[]) => createCustomKey(...a),
  updateCustomKeyValue: (...a: unknown[]) => updateCustomKeyValue(...a),
  findCustomKeyRow: (...a: unknown[]) => findCustomKeyRow(...a),
  bustTranslationCache: (...a: unknown[]) => bustTranslationCache(...a),
  EDITOR_TRANSLATIONS_REFRESHED_EVENT: 'g7le:editor-translations-refreshed',
}));
vi.mock('../../../TranslationEngine', () => ({
  TranslationEngine: {
    getInstance: () => ({
      translate: (key: string) => (key === 'shop.sort.latest' ? '최신순' : key),
    }),
  },
}));
vi.mock('../../components/LocaleSwitcher', () => ({
  readSupportedLocales: () => ['ko', 'en', 'ja'],
  localeDisplayLabel: (loc: string) => ({ ko: '한국어', en: 'English', ja: '日本語' }[loc] ?? loc),
}));
vi.mock('../../utils/authToken', () => ({ buildAuthHeaders: (h: Record<string, string>) => h }));

import { OptionsListControl } from '../../components/property-controls/OptionsListControl';
import type { EditorControlSpec } from '../../spec/specTypes';

const t = (k: string) => k;
const ctrl: EditorControlSpec = { widget: 'options-list', apply: { type: 'propValue', propKey: 'options' } };

afterEach(() => cleanup());
beforeEach(() => {
  createCustomKey.mockReset();
  updateCustomKeyValue.mockReset();
  findCustomKeyRow.mockReset();
  bustTranslationCache.mockClear();
});

const seed = [
  { value: 'a', label: 'A' },
  { value: 'b', label: 'B' },
];

/** 옵션 idx 의 라벨 I18nTextField 미리보기 입력칸. */
function labelPreview(idx: number): HTMLInputElement {
  return screen.getByTestId(`g7le-options-label-i18n-${idx}-preview`) as HTMLInputElement;
}

describe('OptionsListControl — 정적 옵션 편집', () => {
  it('옵션 배열을 행으로 렌더한다 (value 입력 + label 다국어 위젯)', () => {
    render(<OptionsListControl control={ctrl} value={seed} onChange={vi.fn()} t={t} />);
    expect(screen.getByTestId('g7le-options-row-0')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-options-row-1')).toBeInTheDocument();
    expect((screen.getByTestId('g7le-options-value-0') as HTMLInputElement).value).toBe('a');
    // 라벨은 I18nTextField 미리보기(평문 'B' 그대로).
    expect(labelPreview(1).value).toBe('B');
  });

  it('행은 3줄 구조다 (value 줄 / label 줄 / 액션 버튼 줄) — 겹침·가로 스크롤·라벨칸 협소 해소', () => {
    render(<OptionsListControl control={ctrl} value={seed} onChange={vi.fn()} t={t} />);
    const row = screen.getByTestId('g7le-options-row-0');
    // 행은 세로 컨테이너(value 줄 + label 줄 + 액션 줄).
    expect(row.style.flexDirection).toBe('column');
    // value 입력 / label 위젯 / 액션 버튼은 서로 다른 줄(부모)에 있어 각 입력이 전폭을 쓴다.
    const valueLine = (screen.getByTestId('g7le-options-value-0').parentElement) as HTMLElement;
    const labelLine = (screen.getByTestId('g7le-options-label-0').parentElement) as HTMLElement;
    const actionLine = (screen.getByTestId('g7le-options-up-0').parentElement) as HTMLElement;
    expect(labelLine).not.toBe(valueLine); // label 이 value 와 다른 줄(전폭 확보)
    expect(actionLine).not.toBe(valueLine);
    expect(actionLine).not.toBe(labelLine);
    // 액션 줄은 우측 정렬 + 이동/삭제 버튼을 함께 담는다.
    expect(actionLine.style.justifyContent).toBe('flex-end');
    expect(actionLine).toContainElement(screen.getByTestId('g7le-options-down-0'));
    expect(actionLine).toContainElement(screen.getByTestId('g7le-options-remove-0'));
  });

  it('빈 값이면 안내를 표시한다', () => {
    render(<OptionsListControl control={ctrl} value={undefined} onChange={vi.fn()} t={t} />);
    expect(screen.getByTestId('g7le-options-empty')).toBeInTheDocument();
  });

  it('"항목 추가" → 빈 옵션이 추가된 배열로 onChange', () => {
    const onChange = vi.fn();
    render(<OptionsListControl control={ctrl} value={seed} onChange={onChange} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-options-add'));
    expect(onChange).toHaveBeenCalledWith([...seed, { value: '', label: '' }]);
  });

  it('value 입력 변경 → 해당 행만 갱신된 배열로 onChange', () => {
    const onChange = vi.fn();
    render(<OptionsListControl control={ctrl} value={seed} onChange={onChange} t={t} />);
    fireEvent.change(screen.getByTestId('g7le-options-value-0'), { target: { value: 'x' } });
    expect(onChange).toHaveBeenCalledWith([{ value: 'x', label: 'A' }, { value: 'b', label: 'B' }]);
  });

  it('삭제 → 해당 항목 제거된 배열로 onChange', () => {
    const onChange = vi.fn();
    render(<OptionsListControl control={ctrl} value={seed} onChange={onChange} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-options-remove-0'));
    expect(onChange).toHaveBeenCalledWith([{ value: 'b', label: 'B' }]);
  });

  it('마지막 항목 삭제 → onChange(undefined) (prop 삭제)', () => {
    const onChange = vi.fn();
    render(<OptionsListControl control={ctrl} value={[{ value: 'a', label: 'A' }]} onChange={onChange} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-options-remove-0'));
    expect(onChange).toHaveBeenCalledWith(undefined);
  });

  it('아래로 이동 → 항목 순서 교환된 배열로 onChange', () => {
    const onChange = vi.fn();
    render(<OptionsListControl control={ctrl} value={seed} onChange={onChange} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-options-down-0'));
    expect(onChange).toHaveBeenCalledWith([{ value: 'b', label: 'B' }, { value: 'a', label: 'A' }]);
  });

  it('첫 행 위로 이동은 비활성 (경계 가드)', () => {
    render(<OptionsListControl control={ctrl} value={seed} onChange={vi.fn()} t={t} />);
    expect(screen.getByTestId('g7le-options-up-0')).toBeDisabled();
    expect(screen.getByTestId('g7le-options-down-1')).toBeDisabled();
  });
});

describe('OptionsListControl — 라벨 동적 다국어 (7-b)', () => {
  it('label 이 `$t:` 키면 raw 노출 없이 해석값 미리보기', () => {
    render(
      <OptionsListControl control={ctrl} value={[{ value: 'latest', label: '$t:shop.sort.latest' }]} onChange={vi.fn()} t={t} />,
    );
    const preview = labelPreview(0);
    // raw `$t:shop.sort.latest` 가 아니라 해석값 '최신순'.
    expect(preview.value).toBe('최신순');
    expect(preview.value).not.toMatch(/^\$t:/);
  });

  it('평문 라벨 입력 blur → createCustomKey 후 `$t:custom.*` 토큰을 label 에 기록', async () => {
    createCustomKey.mockResolvedValue({
      kind: 'ok',
      resource: { id: 9, translation_key: 'custom.shop.3', values: { ko: '인기순' }, lock_version: 0 },
    });
    const onChange = vi.fn();
    render(<OptionsListControl control={ctrl} value={[{ value: 'sales', label: '' }]} onChange={onChange} t={t} />);
    const preview = labelPreview(0);
    fireEvent.change(preview, { target: { value: '인기순' } });
    fireEvent.blur(preview);
    await waitFor(() => expect(createCustomKey).toHaveBeenCalledWith('sirsoft-basic', 'shop', 'ko', '인기순'));
    await waitFor(() => expect(onChange).toHaveBeenCalled());
    // 마지막 onChange 의 label 이 토큰으로 치환.
    const last = onChange.mock.calls[onChange.mock.calls.length - 1][0];
    expect(last[0].label).toBe('$t:custom.shop.3');
    expect(last[0].value).toBe('sales'); // value 는 식별자 유지
  });
});

describe('OptionsListControl — 정적-바인딩 가드', () => {
  it('데이터바인딩 값(`{{...}}`)이면 디그레이드 표시 (편집 비대상)', () => {
    render(<OptionsListControl control={ctrl} value="{{categories.data}}" onChange={vi.fn()} t={t} />);
    expect(screen.getByTestId('g7le-widget-options-list-bound')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-options-add')).not.toBeInTheDocument();
  });
});

//  (목록 칸 결선 회귀) — OptionsListControl 은 `WidgetProps.bindingCandidates` 를 받아
// 각 옵션 라벨의 I18nTextField 로 흘려야 `+데이터` 칩 삽입(키화) 입구가 뜬다. 종전엔 WidgetProps 에
// BindingCandidate 경로 자체가 없어 후보 0 → 라벨 칸에 `+데이터` 버튼 미노출(라이브 갭).
describe('OptionsListControl — 라벨 후보 풀 전달', () => {
  const scalarCandidate = {
    expression: '{{user.name}}', source: 'data_source' as const, sourceId: 'user',
    path: 'name', shape: 'scalar' as const, preview: '홍길동',
  };

  it('bindingCandidates 전달 시 평문 라벨 칸에 +데이터 버튼 노출', () => {
    render(
      <OptionsListControl control={ctrl} value={seed} onChange={vi.fn()} t={t} bindingCandidates={[scalarCandidate]} />,
    );
    expect(screen.getByTestId('g7le-options-label-i18n-0-plus-data-btn')).toBeInTheDocument();
  });

  it('bindingCandidates 미전달 시 +데이터 버튼 미노출 (디그레이드)', () => {
    render(<OptionsListControl control={ctrl} value={seed} onChange={vi.fn()} t={t} />);
    expect(screen.queryByTestId('g7le-options-label-i18n-0-plus-data-btn')).not.toBeInTheDocument();
  });
});

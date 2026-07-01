// e2e:allow 레이아웃 편집기 SEO og/twitter extra 키–값 에디터 — 합성 데이터칩 의존으로 Playwright 부적합, Chrome MCP 매트릭스(세션 D) + 단위 테스트로 검증
/**
 * KeyValueChipEditor.test.tsx — og.extra/twitter.extra 공용 키–값 RTL
 *
 * 검증:
 *  - 초기 배열 → 행 렌더(property/name 키 + content 값)
 *  - 행 추가/삭제
 *  - 값 데이터칩(DataChipValueInput) 후보 선택 → content 표현식
 *  - `{property,content}`(og) / `{name,content}`(twitter) 배열 직렬화
 *  - 빈 키 행은 직렬화에서 제외
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup, within } from '@testing-library/react';
import { KeyValueChipEditor, type KeyValueExtraItem } from '../../../components/page-settings/KeyValueChipEditor';
import type { BindingCandidate } from '../../../spec/bindingCandidates';

const t = (k: string) => k;
afterEach(() => cleanup());

const candidates: BindingCandidate[] = [
  { expression: '{{product.data.brand}}', source: 'data_source', sourceId: 'product', path: 'data.brand', shape: 'scalar', preview: '브랜드' },
];

describe('KeyValueChipEditor — 렌더/편집', () => {
  it('초기 배열을 property 키 + content 값 행으로 렌더한다', () => {
    const value: KeyValueExtraItem[] = [{ property: 'og:rating', content: '4.5' }];
    render(<KeyValueChipEditor value={value} onChange={vi.fn()} keyField="property" t={t} />);
    expect(screen.getByTestId('g7le-kv-chip-editor')).toBeInTheDocument();
    const keyInputs = screen.getAllByDisplayValue('og:rating');
    expect(keyInputs.length).toBe(1);
    expect(screen.getByDisplayValue('4.5')).toBeInTheDocument();
  });

  it('행 추가 → 키 입력 시 {property,content} 직렬화', () => {
    const onChange = vi.fn();
    render(<KeyValueChipEditor value={[]} onChange={onChange} keyField="property" t={t} />);
    fireEvent.click(screen.getByTestId('g7le-kv-chip-editor-add'));
    // 새 빈 행은 키가 비어 직렬화 제외 → onChange([]).
    expect(onChange).toHaveBeenLastCalledWith([]);
    // 키 입력 → 직렬화 등장.
    const keyInput = screen.getByTestId(/g7le-kv-chip-editor-key-/);
    fireEvent.change(keyInput, { target: { value: 'og:rating' } });
    expect(onChange).toHaveBeenLastCalledWith([{ property: 'og:rating', content: '' }]);
  });

  it('twitter 모드는 name 키로 직렬화한다', () => {
    const onChange = vi.fn();
    render(<KeyValueChipEditor value={[{ name: 'twitter:label1', content: '가격' }]} onChange={onChange} keyField="name" t={t} />);
    const valInput = screen.getByDisplayValue('가격');
    fireEvent.change(valInput, { target: { value: '무료배송' } });
    expect(onChange).toHaveBeenLastCalledWith([{ name: 'twitter:label1', content: '무료배송' }]);
  });

  it('값 데이터칩 후보 선택 → content 에 표현식 기록', () => {
    const onChange = vi.fn();
    render(<KeyValueChipEditor value={[{ property: 'og:brand', content: '' }]} onChange={onChange} keyField="property" t={t} candidates={candidates} />);
    // 피커 토글 → 검색 결과 후보 클릭.
    const toggles = screen.getAllByTestId(/g7le-inline-binding-search-toggle-/);
    fireEvent.click(toggles[0]);
    fireEvent.click(screen.getByTestId('g7le-inline-binding-candidate-{{product.data.brand}}'));
    expect(onChange).toHaveBeenLastCalledWith([{ property: 'og:brand', content: '{{product.data.brand}}' }]);
  });

  it('행 삭제 → 그 행 제외한 배열 방출', () => {
    const onChange = vi.fn();
    render(
      <KeyValueChipEditor
        value={[{ property: 'a', content: '1' }, { property: 'b', content: '2' }]}
        onChange={onChange}
        keyField="property"
        t={t}
      />,
    );
    const removes = screen.getAllByTestId(/g7le-kv-chip-editor-remove-/);
    fireEvent.click(removes[0]);
    expect(onChange).toHaveBeenLastCalledWith([{ property: 'b', content: '2' }]);
  });

  it('빈 키 행은 직렬화에서 제외한다', () => {
    const onChange = vi.fn();
    render(<KeyValueChipEditor value={[{ property: 'x', content: '1' }]} onChange={onChange} keyField="property" t={t} />);
    fireEvent.click(screen.getByTestId('g7le-kv-chip-editor-add')); // 빈 행 추가.
    // 빈 행은 빠지고 기존 x 행만.
    expect(onChange).toHaveBeenLastCalledWith([{ property: 'x', content: '1' }]);
  });

  // (B)키·값을 세로 스택으로 배치해 각 입력기 affordance(🔍/ƒx/??/≡)가
  // 가로로 겹치지 않게 한다(기능 축소 0 — 둘 다 full DataChipValueInput). 행 내부 = flex-column.
  it('(B) 행은 키·값을 세로 스택(flex-column)으로 배치 — 가로 겹침 방지', () => {
    // container 로 범위 한정 — 행 id 는 모듈 전역 seq 라 가변이고, batch 실행 시 다른 파일이
    // 남긴 동일 컴포넌트 DOM 과 정규식이 다중 매칭해 깨질 수 있다(격리). 이 render 의 container
    // 안에서만 행을 찾는다.
    const { container } = render(
      <KeyValueChipEditor
        value={[{ key: 'k', value: 'v' }]}
        onChange={vi.fn()}
        keyField="key"
        valueField="value"
        t={t}
        renderKeyInput={(key) => <input data-testid="custom-key" value={key.value} onChange={(e) => key.onChange(e.target.value)} />}
      />,
    );
    // 행 id 는 모듈 전역 seq 라 가변 — container 안의 첫 행 엘리먼트를 집는다.
    const row = container.querySelector('[data-testid^="g7le-kv-chip-editor-row-"]') as HTMLElement;
    expect(row).not.toBeNull();
    // 행 직속 첫 자식 = 키/값 세로 스택 컨테이너(flex-column) — 가로 겹침 방지 핵심.
    const stack = row.firstElementChild as HTMLElement;
    expect(getComputedStyle(stack).flexDirection).toBe('column');
    // 키(주입 입력) + 값(DataChipValueInput) 둘 다 같은 행 안에 렌더(세로로 쌓임).
    const keyInput = within(row).getByTestId('custom-key');
    const valueField = within(row).getByTestId(/g7le-kv-chip-editor-value-\d+$/);
    expect(keyInput).toBeInTheDocument();
    expect(valueField).toBeInTheDocument();
    // 키가 값보다 DOM 상 먼저(위) — 세로 스택 순서.
    expect(keyInput.compareDocumentPosition(valueField) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });
});

// e2e:allow 초기 상태 재귀 값 편집기 단위(RTL) — 타입 select/중첩 위젯 합성, Chrome MCP 매트릭스(세션 D)로 보강.
/**
 * InitialStateValueEditor.test.tsx — 재귀 값 편집기 RTL
 *
 * 검증:
 *  ① 6종 위젯 렌더 + 종류 전환 시 위젯 교체+기본값 리셋
 *  ② 묶음 하위 키 재귀(+ 하위 키 추가 → 중첩 patch 경로)
 *  ③ 목록 요소 재귀(+ 항목 추가 → items.N, 요소 ✕)
 *  ④ 숫자 parseFloat, toggle, null 고정
 *  ⑤ 점/인덱스 경로 testid
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { InitialStateValueEditor } from '../../../components/page-settings/InitialStateValueEditor';

const t = (k: string) => k;

beforeEach(() => cleanup());

function setup(value: unknown) {
  const onChange = vi.fn();
  render(<InitialStateValueEditor value={value} onChange={onChange} t={t} path="keyword" scope="local" />);
  return onChange;
}

describe('InitialStateValueEditor', () => {
  it('문자 값 — text 위젯 + 종류 표시', () => {
    const onChange = setup('hello');
    const input = screen.getByTestId('g7le-initstate-value-keyword');
    expect(input).toHaveValue('hello');
    fireEvent.change(input, { target: { value: 'world' } });
    expect(onChange).toHaveBeenCalledWith('world');
    expect((screen.getByTestId('g7le-initstate-type-keyword') as HTMLSelectElement).value).toBe('string');
  });

  it('숫자 값 — parseFloat', () => {
    const onChange = setup(1);
    fireEvent.change(screen.getByTestId('g7le-initstate-value-keyword'), { target: { value: '42' } });
    expect(onChange).toHaveBeenCalledWith(42);
  });

  it('불리언 — toggle', () => {
    const onChange = setup(false);
    fireEvent.click(screen.getByTestId('g7le-initstate-value-keyword'));
    expect(onChange).toHaveBeenCalledWith(true);
  });

  it('null — 고정 표시(편집 불가)', () => {
    setup(null);
    expect(screen.getByTestId('g7le-initstate-value-keyword').textContent).toBe('null');
  });

  it('종류 전환 시 기본값으로 리셋', () => {
    const onChange = setup('hello');
    fireEvent.change(screen.getByTestId('g7le-initstate-type-keyword'), { target: { value: 'number' } });
    expect(onChange).toHaveBeenCalledWith(0);
  });

  it('묶음 — 하위 키 재귀 추가(중첩 patch)', () => {
    const onChange = vi.fn();
    render(<InitialStateValueEditor value={{ status: 'active' }} onChange={onChange} t={t} path="filter" scope="local" />);
    // 자식 키 행.
    expect(screen.getByTestId('g7le-initstate-type-filter.status')).toBeInTheDocument();
    // 하위 키 추가.
    fireEvent.change(screen.getByTestId('g7le-initstate-subkey-input-filter'), { target: { value: 'page' } });
    fireEvent.click(screen.getByTestId('g7le-initstate-subkey-add-filter'));
    expect(onChange).toHaveBeenCalledWith({ status: 'active', page: '' });
  });

  // 하위 키 추가행도 1뎁스 addRow 와 동일하게 [이름][종류 select][추가].
  // 종전엔 종류 select 가 없어 하위 값을 항상 문자(`''`)로만 추가했다(묶음/숫자를 한 번에 못 만듦).
  it('묶음 — 하위 키 추가행에 종류 select(묶음 선택 → {} 로 추가)', () => {
    const onChange = vi.fn();
    render(<InitialStateValueEditor value={{}} onChange={onChange} t={t} path="filter" scope="local" />);
    // 하위 키 추가행에 종류 select 가 있다(회귀 잠금 — 종전엔 부재).
    const kindSelect = screen.getByTestId('g7le-initstate-subkey-kind-filter') as HTMLSelectElement;
    expect(kindSelect).toBeInTheDocument();
    expect(kindSelect.value).toBe('string'); // 기본 문자(기존 동작 보존).
    // 묶음 선택 후 하위 키 추가 → {} 로 생성(문자 `''` 아님).
    fireEvent.change(kindSelect, { target: { value: 'object' } });
    fireEvent.change(screen.getByTestId('g7le-initstate-subkey-input-filter'), { target: { value: 'nested' } });
    fireEvent.click(screen.getByTestId('g7le-initstate-subkey-add-filter'));
    expect(onChange).toHaveBeenCalledWith({ nested: {} });
  });

  it('목록 — 요소 재귀 추가/삭제(items.N)', () => {
    const onChange = vi.fn();
    render(<InitialStateValueEditor value={['a']} onChange={onChange} t={t} path="items" scope="local" />);
    expect(screen.getByTestId('g7le-initstate-type-items.0')).toBeInTheDocument();
    fireEvent.click(screen.getByTestId('g7le-initstate-item-add-items'));
    expect(onChange).toHaveBeenCalledWith(['a', '']);

    onChange.mockClear();
    fireEvent.click(screen.getByTestId('g7le-initstate-item-remove-items.0'));
    expect(onChange).toHaveBeenCalledWith([]);
  });

  it('실데이터 표본(manualAddress 다중 키) 로드→직렬화', () => {
    const onChange = vi.fn();
    render(
      <InitialStateValueEditor
        value={{ recipient_name: '', phone: '', address: '' }}
        onChange={onChange}
        t={t}
        path="manualAddress"
        scope="local"
      />,
    );
    expect(screen.getByTestId('g7le-initstate-type-manualAddress.recipient_name')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-initstate-type-manualAddress.phone')).toBeInTheDocument();
  });
});

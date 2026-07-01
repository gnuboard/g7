// e2e:allow JSON 텍스트 편집 공용 부품 단위(RTL) — 파싱/검증/재시드 로직, 라이브는 초기 상태/데이터 탭 Chrome MCP 매트릭스로 보강.
/**
 * JsonBlockField.test.tsx — 임의 JSON 값 텍스트 편집 공용 부품 RTL
 *
 * 검증:
 *  ① value → pretty JSON 시드
 *  ② 유효 JSON 입력 → onChange(파싱값) + validity true
 *  ③ 깨진 JSON → 오류 표시 + onChange 미호출 + validity false (저장 차단)
 *  ④ shape='object' 가드 — 배열/스칼라 입력 거부(validity false)
 *  ⑤ 빈 문자열 → emptyValue
 *  ⑥ 외부 value 재시드(편집 중이 아닐 때)
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { JsonBlockField } from '../../components/JsonBlockField';

const t = (k: string) => k;

beforeEach(() => cleanup());

describe('JsonBlockField', () => {
  it('value 를 pretty JSON 으로 시드한다', () => {
    render(<JsonBlockField value={{ a: 1 }} onChange={vi.fn()} t={t} />);
    const ta = screen.getByTestId('g7le-json-block') as HTMLTextAreaElement;
    expect(ta.value).toBe('{\n  "a": 1\n}');
  });

  it('유효 JSON 입력 → onChange(파싱값) + validity true', () => {
    const onChange = vi.fn();
    const onValid = vi.fn();
    render(<JsonBlockField value={{}} onChange={onChange} onValidityChange={onValid} t={t} />);
    fireEvent.change(screen.getByTestId('g7le-json-block'), { target: { value: '{"x": 2}' } });
    expect(onChange).toHaveBeenLastCalledWith({ x: 2 });
    expect(onValid).toHaveBeenLastCalledWith(true);
    expect(screen.queryByTestId('g7le-json-block-error')).toBeNull();
  });

  it('깨진 JSON → 오류 표시 + onChange 미호출 + validity false', () => {
    const onChange = vi.fn();
    const onValid = vi.fn();
    render(<JsonBlockField value={{}} onChange={onChange} onValidityChange={onValid} t={t} />);
    fireEvent.change(screen.getByTestId('g7le-json-block'), { target: { value: '{ broken' } });
    expect(screen.getByTestId('g7le-json-block-error')).toBeInTheDocument();
    expect(onValid).toHaveBeenLastCalledWith(false);
    // 깨진 입력은 흘리지 않는다(저장 차단).
    expect(onChange).not.toHaveBeenCalled();
  });

  it("shape='object' — 배열/스칼라 입력 거부(validity false)", () => {
    const onChange = vi.fn();
    const onValid = vi.fn();
    render(<JsonBlockField value={{}} shape="object" onChange={onChange} onValidityChange={onValid} t={t} />);
    // 배열 — 거부.
    fireEvent.change(screen.getByTestId('g7le-json-block'), { target: { value: '[1,2]' } });
    expect(onValid).toHaveBeenLastCalledWith(false);
    expect(screen.getByTestId('g7le-json-block-error')).toBeInTheDocument();
    // 스칼라 — 거부.
    fireEvent.change(screen.getByTestId('g7le-json-block'), { target: { value: '42' } });
    expect(onValid).toHaveBeenLastCalledWith(false);
    expect(onChange).not.toHaveBeenCalled();
  });

  it('빈 문자열 → emptyValue', () => {
    const onChange = vi.fn();
    render(<JsonBlockField value={{ a: 1 }} emptyValue={{}} onChange={onChange} t={t} />);
    fireEvent.change(screen.getByTestId('g7le-json-block'), { target: { value: '' } });
    expect(onChange).toHaveBeenLastCalledWith({});
  });

  it('외부 value 재시드(편집 중이 아닐 때)', () => {
    const { rerender } = render(<JsonBlockField value={{ a: 1 }} onChange={vi.fn()} t={t} />);
    rerender(<JsonBlockField value={{ b: 2 }} onChange={vi.fn()} t={t} />);
    const ta = screen.getByTestId('g7le-json-block') as HTMLTextAreaElement;
    expect(ta.value).toBe('{\n  "b": 2\n}');
  });
});

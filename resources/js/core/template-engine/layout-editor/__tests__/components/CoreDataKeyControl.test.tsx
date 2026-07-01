/**
 * CoreDataKeyControl.test.tsx — 코어 "폼 데이터 연결점(dataKey)" 위젯
 *
 * 가드: (1) 식별자 안전 문자 sanitize(점 경로 허용), (2) 바인딩(`{{...}}`) 디그레이드,
 *       (3) 빈 값 → onChange(undefined).
 */

import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { CoreDataKeyControl, sanitizeDataKey } from '../../components/property-controls/CoreDataKeyControl';
import type { EditorControlSpec } from '../../spec/specTypes';

const control: EditorControlSpec = { widget: 'core-datakey', label: 'dataKey' };
const t = (k: string) => k;

function renderControl(value: unknown, onChange = vi.fn()) {
  render(<CoreDataKeyControl control={control} value={value} onChange={onChange} t={t} />);
  return onChange;
}

describe('sanitizeDataKey (점 경로 허용)', () => {
  it('점/언더스코어/하이픈은 통과(점 경로 — _global.formData)', () => {
    expect(sanitizeDataKey('_global.formData')).toBe('_global.formData');
    expect(sanitizeDataKey('form')).toBe('form');
    expect(sanitizeDataKey('user-data_1')).toBe('user-data_1');
  });

  it('공백/한글/중괄호 등 식별자 비허용 문자 제거', () => {
    expect(sanitizeDataKey('폼 데이터')).toBe('');
    expect(sanitizeDataKey('form {data}')).toBe('formdata');
    expect(sanitizeDataKey('a b.c')).toBe('ab.c');
  });
});

describe('CoreDataKeyControl 위젯', () => {
  it('정적 값 입력 시 안전 문자만 onChange 로 전달', () => {
    const onChange = renderControl('');
    const input = screen.getByTestId('g7le-widget-core-datakey') as HTMLInputElement;
    fireEvent.change(input, { target: { value: '폼 form.x' } });
    expect(onChange).toHaveBeenCalledWith('form.x');
  });

  it('빈 입력은 onChange(undefined) — 노드 키 삭제', () => {
    const onChange = renderControl('form');
    const input = screen.getByTestId('g7le-widget-core-datakey') as HTMLInputElement;
    fireEvent.change(input, { target: { value: '' } });
    expect(onChange).toHaveBeenCalledWith(undefined);
  });

  it('정적 기존 값을 그대로 표시(읽기/편집 가능) + 힌트', () => {
    renderControl('form');
    const input = screen.getByTestId('g7le-widget-core-datakey') as HTMLInputElement;
    expect(input.value).toBe('form');
    expect(input.disabled).toBe(false);
  });

  it('바인딩(`{{...}}`) 값이면 디그레이드(읽기전용 + 안내)', () => {
    const onChange = renderControl('{{_global.formData}}');
    expect(screen.getByTestId('g7le-core-datakey-bound')).toBeTruthy();
    const input = screen.getByTestId('g7le-widget-core-datakey') as HTMLInputElement;
    expect(input.value).toBe('{{_global.formData}}');
    expect(input.disabled).toBe(true);
    expect(onChange).not.toHaveBeenCalled();
  });
});

/**
 * IconPickerWidget.test.tsx — 템플릿 소유 아이콘 피커 위젯
 *
 * 검증: 카탈로그 그리드 렌더 / 검색 필터 / 선택 → onChange / 카탈로그 없으면 자유입력 폴백.
 */

import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { IconPickerWidget } from '../IconPickerWidget';

const t = (k: string) => k;

const catalog = [
  { value: 'star', keywords: ['star', 'favorite'] },
  { value: 'heart', keywords: ['heart', 'love'] },
  { value: 'house', keywords: ['house', 'home'] },
];

function renderWidget(control: any, value: unknown = '', onChange = vi.fn()) {
  render(<IconPickerWidget control={control} value={value} onChange={onChange} t={t} />);
  return onChange;
}

describe('템플릿 IconPickerWidget', () => {
  it('카탈로그가 있으면 검색 + 그리드 렌더(자유입력 아님)', () => {
    renderWidget({ icons: catalog, iconColumns: 8 });
    expect(screen.getByTestId('g7le-widget-icon-picker-search')).toBeTruthy();
    expect(screen.getByTestId('g7le-widget-icon-picker-grid')).toBeTruthy();
    expect(screen.queryByTestId('g7le-widget-icon-picker-free')).toBeNull();
    // 3개 아이콘 + "없음" 셀
    expect(screen.getByTestId('g7le-icon-cell-star')).toBeTruthy();
    expect(screen.getByTestId('g7le-icon-cell-heart')).toBeTruthy();
    expect(screen.getByTestId('g7le-icon-cell-none')).toBeTruthy();
  });

  it('검색어로 카탈로그를 필터한다(value/keywords)', () => {
    renderWidget({ icons: catalog });
    const search = screen.getByTestId('g7le-widget-icon-picker-search');
    fireEvent.change(search, { target: { value: 'love' } }); // heart 의 keyword
    expect(screen.getByTestId('g7le-icon-cell-heart')).toBeTruthy();
    expect(screen.queryByTestId('g7le-icon-cell-star')).toBeNull();
    expect(screen.queryByTestId('g7le-icon-cell-house')).toBeNull();
  });

  it('아이콘 셀 클릭 → onChange(value)', () => {
    const onChange = renderWidget({ icons: catalog });
    fireEvent.click(screen.getByTestId('g7le-icon-cell-heart'));
    expect(onChange).toHaveBeenCalledWith('heart');
  });

  it('"없음" 클릭 → onChange(undefined) (prop 삭제)', () => {
    const onChange = renderWidget({ icons: catalog }, 'star');
    fireEvent.click(screen.getByTestId('g7le-icon-cell-none'));
    expect(onChange).toHaveBeenCalledWith(undefined);
  });

  it('카탈로그 미공급 → 자유입력 폴백', () => {
    renderWidget({ icons: [] });
    expect(screen.getByTestId('g7le-widget-icon-picker-free')).toBeTruthy();
    expect(screen.queryByTestId('g7le-widget-icon-picker-grid')).toBeNull();
  });

  it('FA 프리뷰: 셀에 fas fa-{value} 글리프 렌더', () => {
    renderWidget({ icons: [{ value: 'heart' }] });
    const cell = screen.getByTestId('g7le-icon-cell-heart');
    expect(cell.querySelector('i.fas.fa-heart')).toBeTruthy();
  });
});

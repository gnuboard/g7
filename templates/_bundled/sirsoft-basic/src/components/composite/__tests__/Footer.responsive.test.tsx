/**
 * Footer 반응형 회귀 테스트
 *
 * 정책: 레이아웃 편집 가능 템플릿은 G7 표준 responsive(useResponsive) 만 사용한다.
 * Footer 는 Tailwind md:/lg:/sm: 미디어쿼리 대신 useResponsive().width 기반으로
 * 그리드 컬럼/flex 방향을 분기해야 위지윅 프리뷰 디바이스 전환(overrideWidth)에
 * 정상 반응한다. 본 테스트는 width 별 분기를 가드한다.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render } from '@testing-library/react';
import Footer from '../Footer';

function mockResponsive(width: number) {
  return vi.fn(() => ({
    width,
    isMobile: width < 768,
    isTablet: width >= 768 && width < 1024,
    isDesktop: width >= 1024,
    matchedPreset: (width < 768 ? 'mobile' : width < 1024 ? 'tablet' : 'desktop') as
      | 'mobile'
      | 'tablet'
      | 'desktop',
  }));
}

describe('Footer — G7 표준 responsive 분기', () => {
  let originalG7Core: any;

  function setWidth(width: number) {
    originalG7Core = (window as any).G7Core;
    (window as any).G7Core = {
      ...originalG7Core,
      useResponsive: mockResponsive(width),
      t: (k: string) => k,
    };
  }

  afterEach(() => {
    (window as any).G7Core = originalG7Core;
    vi.clearAllMocks();
  });

  it('데스크톱(1280) — 링크 그룹 그리드 5열', () => {
    setWidth(1280);
    const { container } = render(<Footer />);
    const grid = container.querySelector('.grid') as HTMLElement;
    expect(grid).toBeTruthy();
    expect(grid.style.gridTemplateColumns).toBe('repeat(5, minmax(0, 1fr))');
  });

  it('태블릿(820) — 그리드 2열', () => {
    setWidth(820);
    const { container } = render(<Footer />);
    const grid = container.querySelector('.grid') as HTMLElement;
    expect(grid.style.gridTemplateColumns).toBe('repeat(2, minmax(0, 1fr))');
  });

  it('모바일(390) — 그리드 1열 + 저작권 영역 세로(column)', () => {
    setWidth(390);
    const { container } = render(<Footer />);
    const grid = container.querySelector('.grid') as HTMLElement;
    expect(grid.style.gridTemplateColumns).toBe('repeat(1, minmax(0, 1fr))');

    // 저작권 영역 flexDirection = column (모바일)
    const cols = Array.from(container.querySelectorAll('div')).filter(
      (d) => d.style.flexDirection === 'column'
    );
    expect(cols.length).toBeGreaterThan(0);
  });

  it('데스크톱 — 저작권 영역 flexDirection row', () => {
    setWidth(1280);
    const { container } = render(<Footer />);
    const rows = Array.from(container.querySelectorAll('div')).filter(
      (d) => d.style.flexDirection === 'row'
    );
    expect(rows.length).toBeGreaterThan(0);
  });

  it('Footer 마크업에 Tailwind 반응형 그리드 클래스(md:grid-cols / lg:grid-cols)를 사용하지 않음', () => {
    setWidth(1280);
    const { container } = render(<Footer />);
    const html = container.innerHTML;
    // 정책: 편집 가능 템플릿은 viewport 미디어쿼리 그리드/flex 분기 금지
    expect(html).not.toContain('md:grid-cols');
    expect(html).not.toContain('lg:grid-cols');
    expect(html).not.toContain('sm:flex-row');
  });
});

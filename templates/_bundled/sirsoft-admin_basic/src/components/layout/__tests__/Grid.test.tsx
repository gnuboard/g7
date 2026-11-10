import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { Grid } from '../Grid';

describe('Grid 컴포넌트', () => {
  describe('기본 렌더링', () => {
    // @scenario case=grid_c08
    // @effects grid_layout_props_to_classes
    it('자식 요소를 렌더링한다', () => {
      render(<Grid>Test Content</Grid>);
      expect(screen.getByText('Test Content')).toBeTruthy();
    });

    // @scenario case=grid_c09
    // @effects grid_layout_props_to_classes
    it('기본 grid 클래스가 적용된다', () => {
      const { container } = render(<Grid>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('grid');
    });

    // @scenario case=grid_c10
    // @effects grid_layout_props_to_classes
    it('기본 1 컬럼이 적용된다', () => {
      const { container } = render(<Grid>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('grid-cols-1');
    });
  });

  describe('Columns (컬럼 수)', () => {
    // @scenario case=grid_c11
    // @effects grid_layout_props_to_classes
    it('2 컬럼이 적용된다', () => {
      const { container } = render(<Grid cols={2}>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('grid-cols-2');
    });

    // @scenario case=grid_c12
    // @effects grid_layout_props_to_classes
    it('3 컬럼이 적용된다', () => {
      const { container } = render(<Grid cols={3}>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('grid-cols-3');
    });

    // @scenario case=grid_c13
    // @effects grid_layout_props_to_classes
    it('4 컬럼이 적용된다', () => {
      const { container } = render(<Grid cols={4}>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('grid-cols-4');
    });

    // @scenario case=grid_c14
    // @effects grid_layout_props_to_classes
    it('12 컬럼이 적용된다', () => {
      const { container } = render(<Grid cols={12}>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('grid-cols-12');
    });
  });

  describe('Gap (간격)', () => {
    // @scenario case=grid_c15
    // @effects grid_layout_props_to_classes
    it('gap 클래스가 적용된다', () => {
      const { container } = render(<Grid gap={4}>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('gap-4');
    });

    // @scenario case=grid_c16
    // @effects grid_layout_props_to_classes
    it('rowGap 클래스가 적용된다', () => {
      const { container } = render(<Grid rowGap={3}>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('gap-y-3');
    });

    // @scenario case=grid_c17
    // @effects grid_layout_props_to_classes
    it('colGap 클래스가 적용된다', () => {
      const { container } = render(<Grid colGap={5}>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('gap-x-5');
    });

    // @scenario case=grid_c18
    // @effects grid_layout_props_to_classes
    it('gap, rowGap, colGap이 함께 적용된다', () => {
      const { container } = render(<Grid gap={2} rowGap={3} colGap={4}>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('gap-2');
      expect(div.className).toContain('gap-y-3');
      expect(div.className).toContain('gap-x-4');
    });

    // @scenario case=grid_c19
    // @effects grid_layout_props_to_classes
    it('gap이 0이면 적용되지 않는다', () => {
      const { container } = render(<Grid gap={0}>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).not.toContain('gap-');
    });
  });

  describe('Auto Rows & Cols', () => {
    // @scenario case=grid_c20
    // @effects grid_layout_props_to_classes
    it('autoRows가 적용된다', () => {
      const { container } = render(<Grid autoRows="auto">Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('auto-rows-auto');
    });

    // @scenario case=grid_c21
    // @effects grid_layout_props_to_classes
    it('autoRows min 값이 적용된다', () => {
      const { container } = render(<Grid autoRows="min">Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('auto-rows-min');
    });

    // @scenario case=grid_c22
    // @effects grid_layout_props_to_classes
    it('autoRows max 값이 적용된다', () => {
      const { container } = render(<Grid autoRows="max">Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('auto-rows-max');
    });

    // @scenario case=grid_c23
    // @effects grid_layout_props_to_classes
    it('autoCols가 적용된다', () => {
      const { container } = render(<Grid autoCols="auto">Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('auto-cols-auto');
    });

    // @scenario case=grid_c24
    // @effects grid_layout_props_to_classes
    it('autoCols min 값이 적용된다', () => {
      const { container } = render(<Grid autoCols="min">Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('auto-cols-min');
    });

    // @scenario case=grid_c25
    // @effects grid_layout_props_to_classes
    it('autoCols max 값이 적용된다', () => {
      const { container } = render(<Grid autoCols="max">Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('auto-cols-max');
    });
  });

  describe('Grid Flow', () => {
    // @scenario case=grid_c26
    // @effects grid_layout_props_to_classes
    it('flow row가 적용된다', () => {
      const { container } = render(<Grid flow="row">Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('grid-flow-row');
    });

    // @scenario case=grid_c27
    // @effects grid_layout_props_to_classes
    it('flow col이 적용된다', () => {
      const { container } = render(<Grid flow="col">Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('grid-flow-col');
    });

    // @scenario case=grid_c28
    // @effects grid_layout_props_to_classes
    it('flow dense가 적용된다', () => {
      const { container } = render(<Grid flow="dense">Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('grid-flow-dense');
    });
  });

  describe('사용자 정의 Props', () => {
    // @scenario case=grid_c29
    // @effects grid_layout_props_to_classes
    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(<Grid className="custom-class">Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('custom-class');
    });

    // @scenario case=grid_c30
    // @effects grid_layout_props_to_classes
    it('인라인 스타일이 적용된다', () => {
      const { container } = render(
        <Grid style={{ backgroundColor: 'blue' }}>Content</Grid>
      );
      const div = container.firstChild as HTMLElement;
      expect(div.style.backgroundColor).toBe('blue');
    });

    // @scenario case=grid_c31
    // @effects grid_layout_props_to_classes
    it('onClick 핸들러가 호출된다', () => {
      let clicked = false;
      const { container } = render(<Grid onClick={() => (clicked = true)}>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      div.click();
      expect(clicked).toBe(true);
    });
  });

  describe('복합 속성 조합', () => {
    // @scenario case=grid_c32
    // @effects grid_layout_props_to_classes
    it('여러 속성이 함께 적용된다', () => {
      const { container } = render(
        <Grid
          cols={2}
          responsive={{ md: 3, lg: 4 }}
          gap={4}
          rowGap={6}
          autoRows="min"
          flow="row"
        >
          Content
        </Grid>
      );
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('grid');
      // jsdom 기본 폭 1024px → lg 구간 값 하나만
      expect(div.className.split(/\s+/).filter((t) => /^grid-cols-\d+$/.test(t))).toEqual(['grid-cols-4']);
      expect(div.className).not.toMatch(/(^|\s)(sm|md|lg|xl|2xl):/);
      expect(div.className).toContain('gap-4');
      expect(div.className).toContain('gap-y-6');
      expect(div.className).toContain('auto-rows-min');
      expect(div.className).toContain('grid-flow-row');
    });
  });
});

/** 폭 판정 접두(`md:grid-cols-3` 등)가 붙은 클래스 */
const PREFIXED = /(^|\s)(sm|md|lg|xl|2xl|min-\[[^\]]+\]|max-\[[^\]]+\]):/;

/** className 안의 `grid-cols-N` 토큰 목록 */
const gridColsTokens = (className: string): string[] =>
  className.split(/\s+/).filter((token) => /^grid-cols-\d+$/.test(token));

/** 다섯 구간을 모두 지정한 반응형 열 수 */
const ALL = { sm: 2, md: 3, lg: 4, xl: 5, '2xl': 6 };

/** 엔진 폭 컨텍스트 흉내 — 엔진 ResponsiveProvider 와 같은 규칙(overrideWidth ?? 창 폭, resize 구독) */
const WidthContext = React.createContext<number>(1024);
const useTestResponsive = () => ({ width: React.useContext(WidthContext) });
const TestResponsiveProvider: React.FC<{ overrideWidth?: number; children: React.ReactNode }> = ({ overrideWidth, children }) => {
  const [actual, setActual] = React.useState(() => window.innerWidth);
  React.useEffect(() => {
    const onResize = () => setActual(window.innerWidth);
    window.addEventListener('resize', onResize);
    return () => window.removeEventListener('resize', onResize);
  }, []);
  return <WidthContext.Provider value={overrideWidth ?? actual}>{children}</WidthContext.Provider>;
};

/**
 * Grid responsive — Tailwind 접두 대신 엔진 폭(G7Core.useResponsive)으로 열 수를 고른다.
 * 편집기 미리보기 폭과 검색 봇 렌더가 화면과 같은 규칙을 타게 하려는 것.
 */
describe('Grid — 엔진 폭으로 고르는 반응형 열 수', () => {
  let savedG7Core: any;

  beforeEach(() => {
    savedG7Core = (window as any).G7Core;
  });
  afterEach(() => {
    (window as any).G7Core = savedG7Core;
  });

  /** useResponsive 가 주는 폭으로 그린다 */
  const renderAt = (width: number, ui: React.ReactElement) => {
    (window as any).G7Core = { ...(savedG7Core ?? {}), useResponsive: () => ({ width }) };
    return render(ui);
  };

  // @scenario case=grid_c01
  // @effects grid_responsive_columns_by_engine_width
  it.each([
    [639, 'grid-cols-1'],
    [640, 'grid-cols-2'],
    [767, 'grid-cols-2'],
    [768, 'grid-cols-3'],
    [1023, 'grid-cols-3'],
    [1024, 'grid-cols-4'],
    [1279, 'grid-cols-4'],
    [1280, 'grid-cols-5'],
    [1535, 'grid-cols-5'],
    [1536, 'grid-cols-6'],
  ])('폭 %ipx 에서 %s 하나만 낸다', (width, expected) => {
    const { container } = renderAt(width, <Grid cols={1} responsive={ALL}>x</Grid>);
    const className = (container.firstChild as HTMLElement).className;
    expect(gridColsTokens(className)).toEqual([expected]);
  });

  // @scenario case=grid_c02
  // @effects grid_responsive_columns_by_engine_width
  it('지정하지 않은 구간은 아래 구간 값을 이어받고, 맞는 구간이 없으면 cols 를 쓴다', () => {
    const responsive = { md: 3 };
    const at = (width: number) =>
      gridColsTokens((renderAt(width, <Grid cols={2} responsive={responsive}>x</Grid>).container.firstChild as HTMLElement).className);

    expect(at(767)).toEqual(['grid-cols-2']);
    expect(at(768)).toEqual(['grid-cols-3']);
    expect(at(1600)).toEqual(['grid-cols-3']);
  });

  // @scenario case=grid_c03
  // @effects grid_responsive_columns_by_engine_width
  it('어느 폭에서도 반응형 접두 클래스를 내지 않는다', () => {
    for (const width of [320, 640, 768, 1024, 1280, 1536, 1920]) {
      const { container, unmount } = renderAt(
        width,
        <Grid cols={1} responsive={ALL} gap={4}>x</Grid>
      );
      expect((container.firstChild as HTMLElement).className).not.toMatch(PREFIXED);
      unmount();
    }
  });

  // @scenario case=grid_c04
  // @effects grid_responsive_columns_by_engine_width
  it('창 크기가 바뀌면 열 수가 따라 바뀐다', async () => {
    (window as any).G7Core = { ...(savedG7Core ?? {}), useResponsive: useTestResponsive };
    const setWidth = (width: number) => {
      Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: width });
      window.dispatchEvent(new Event('resize'));
    };

    setWidth(500);
    const { container } = render(
      <TestResponsiveProvider>
        <Grid cols={1} responsive={ALL}>x</Grid>
      </TestResponsiveProvider>
    );
    const grid = () => container.firstChild as HTMLElement;
    expect(gridColsTokens(grid().className)).toEqual(['grid-cols-1']);

    setWidth(1300);
    await waitFor(() => expect(gridColsTokens(grid().className)).toEqual(['grid-cols-5']));

    setWidth(700);
    await waitFor(() => expect(gridColsTokens(grid().className)).toEqual(['grid-cols-2']));

    setWidth(1024);
  });

  // @scenario case=grid_c05
  // @effects grid_responsive_columns_by_engine_width
  it('편집기 미리보기 폭(overrideWidth)을 창 폭보다 먼저 따른다', () => {
    (window as any).G7Core = { ...(savedG7Core ?? {}), useResponsive: useTestResponsive };
    Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: 1600 });

    const { container, rerender } = render(
      <TestResponsiveProvider overrideWidth={375}>
        <Grid cols={1} responsive={ALL}>x</Grid>
      </TestResponsiveProvider>
    );
    expect(gridColsTokens((container.firstChild as HTMLElement).className)).toEqual(['grid-cols-1']);

    rerender(
      <TestResponsiveProvider overrideWidth={1024}>
        <Grid cols={1} responsive={ALL}>x</Grid>
      </TestResponsiveProvider>
    );
    expect(gridColsTokens((container.firstChild as HTMLElement).className)).toEqual(['grid-cols-4']);

    Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: 1024 });
  });

  // @scenario case=grid_c06
  // @effects grid_responsive_columns_by_engine_width
  it('responsive 가 없으면 cols 하나만 내고 나머지 속성 클래스는 그대로다', () => {
    const { container } = renderAt(
      1600,
      <Grid cols={3} gap={4} rowGap={2} colGap={6} autoRows="min" flow="col" className="custom">x</Grid>
    );
    const className = (container.firstChild as HTMLElement).className;
    expect(gridColsTokens(className)).toEqual(['grid-cols-3']);
    expect(className.split(/\s+/)).toEqual(
      expect.arrayContaining(['grid', 'gap-4', 'gap-y-2', 'gap-x-6', 'auto-rows-min', 'grid-flow-col', 'custom'])
    );
  });

  // @scenario case=grid_c07
  // @effects grid_responsive_columns_by_engine_width
  it('G7Core 훅이 없는 환경에서는 창 폭으로 고르고 resize 로 갱신한다', async () => {
    const { useResponsive: _omit, ...rest } = (savedG7Core ?? {}) as Record<string, unknown>;
    (window as any).G7Core = rest;
    Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: 700 });

    const { container } = render(<Grid cols={1} responsive={ALL}>x</Grid>);
    const grid = () => container.firstChild as HTMLElement;
    expect(gridColsTokens(grid().className)).toEqual(['grid-cols-2']);

    Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: 1600 });
    window.dispatchEvent(new Event('resize'));
    await waitFor(() => expect(gridColsTokens(grid().className)).toEqual(['grid-cols-6']));

    Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: 1024 });
    window.dispatchEvent(new Event('resize'));
  });
});

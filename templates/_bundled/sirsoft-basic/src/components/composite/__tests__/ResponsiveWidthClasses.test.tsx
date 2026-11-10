// e2e:allow Vitest 컴포넌트 계약 — 화면은 바뀌지 않는 리팩터링(폭 판정을 CSS 미디어 쿼리에서 엔진 폭으로 옮김)이라 폭별 클래스만 잠근다. 실제 화면은 375·639·640·767·768·1023·1024·1280 폭으로 따로 잰다.
import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, waitFor, screen } from '@testing-library/react';
import Header from '../Header';
import Footer from '../Footer';
import { Pagination } from '../Pagination';
import { VisitorCounter } from '../VisitorCounter';
import { PageSkeleton } from '../PageSkeleton';
import { FileList } from '../FileUploader/FileList';
import { Grid } from '../../layout/Grid';

/**
 * 부품 안의 Tailwind 반응형 접두(`sm:px-6`, `hidden sm:inline-block`, `md:grid-cols-4` …)를 엔진 폭
 * (`G7Core.useResponsive().width`, 편집기 미리보기에서는 기기 폭)으로 고른 접두 없는 클래스로 바꾼다.
 * 경계는 Tailwind 와 같아 일반 화면은 그대로이고, 편집기 미리보기 폭에서도 같은 모양이 나온다.
 */

/** 폭 판정 접두(확장 앞글자 `ecommerce:` 뒤에 붙은 것 포함) */
const PREFIXED = /(^|\s)([a-z0-9_-]+:)?(sm|md|lg|xl|2xl|max-sm|max-md|max-lg|max-xl|max-2xl|min-\[[^\]]+\]|max-\[[^\]]+\]):/;

/** 재는 폭 — 경계 양쪽 */
const WIDTHS = [375, 639, 640, 767, 768, 1023, 1024, 1280];

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

const tokens = (el: Element | null | undefined): string[] => (el?.getAttribute('class') ?? '').split(/\s+/).filter(Boolean);

/** 부품 하나의 폭 계약 */
interface WidthCase {
  name: string;
  ui: () => React.ReactElement;
  /** 폭에 따라 클래스가 바뀌는 요소 */
  target: (container: HTMLElement) => Element | null | undefined;
  /** 그 폭에서 있어야 할 클래스와 없어야 할 클래스 */
  expectAt: (width: number) => { has: string[]; not: string[] };
}

/** 옛 `px-4 sm:px-6 lg:px-8` */
const padding = (width: number) => {
  const all = ['px-4', 'px-6', 'px-8'];
  const has = width >= 1024 ? 'px-8' : width >= 640 ? 'px-6' : 'px-4';
  return { has: ['max-w-7xl', 'mx-auto', has], not: all.filter((c) => c !== has) };
};

const USER_NAME = '홍길동';

const CASES: WidthCase[] = [
  {
    name: 'Header 상단 바 여백',
    ui: () => <Header siteName="사이트" availableLocales={['ko', 'en']} currentLocale="ko" />,
    target: (c) => c.querySelector('header .max-w-7xl'),
    expectAt: padding,
  },
  {
    name: 'Header 회원 이름 (옛 hidden sm:line-clamp-1)',
    ui: () => <Header siteName="사이트" user={{ uuid: 'u-1', name: USER_NAME } as any} availableLocales={['ko', 'en']} currentLocale="ko" />,
    target: () => screen.getAllByText(USER_NAME)[0],
    expectAt: (width) =>
      width >= 640
        ? { has: ['line-clamp-1', 'break-all', 'max-w-32', 'min-w-12'], not: ['hidden'] }
        : { has: ['hidden', 'break-all', 'max-w-32', 'min-w-12'], not: ['line-clamp-1'] },
  },
  {
    name: 'Footer 본문 칸 여백',
    ui: () => <Footer />,
    target: (c) => c.querySelector('footer .max-w-7xl'),
    expectAt: padding,
  },
  {
    name: 'Pagination 맨 앞 쪽 단추 (옛 hidden sm:inline-block)',
    ui: () => <Pagination currentPage={1} totalPages={7} showFirstLast onPageChange={vi.fn()} />,
    target: (c) => c.querySelector('[aria-label="첫 페이지"]'),
    expectAt: (width) => (width >= 640 ? { has: ['inline-block', 'px-3'], not: ['hidden'] } : { has: ['hidden', 'px-3'], not: ['inline-block'] }),
  },
  {
    name: 'Pagination 맨 끝 쪽 단추 (옛 hidden sm:inline-block)',
    ui: () => <Pagination currentPage={1} totalPages={7} showFirstLast onPageChange={vi.fn()} />,
    target: (c) => c.querySelector('[aria-label="마지막 페이지"]'),
    expectAt: (width) => (width >= 640 ? { has: ['inline-block', 'px-3'], not: ['hidden'] } : { has: ['hidden', 'px-3'], not: ['inline-block'] }),
  },
  {
    name: 'FileList 썸네일 격자 (옛 grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5)',
    ui: () => (
      <FileList
        allItems={[]}
        canAddMore
        totalCount={0}
        maxFiles={5}
        onRemove={vi.fn()}
        onRetry={vi.fn()}
        onImageClick={vi.fn()}
        onDownload={vi.fn()}
        onDragEnd={vi.fn()}
        onAddClick={vi.fn()}
      />
    ),
    target: (c) => c.querySelector('.grid'),
    expectAt: (width) => {
      const cols = width >= 1024 ? 5 : width >= 768 ? 4 : width >= 640 ? 3 : 2;
      return { has: ['grid', 'gap-3', `grid-cols-${cols}`], not: [2, 3, 4, 5].filter((n) => n !== cols).map((n) => `grid-cols-${n}`) };
    },
  },
  {
    name: 'VisitorCounter row 배치 (옛 grid-cols-2 md:grid-cols-4)',
    ui: () => <VisitorCounter layout="row" />,
    target: (c) => c.querySelector('dl'),
    expectAt: (width) =>
      width >= 768
        ? { has: ['grid', 'gap-3', 'grid-cols-4'], not: ['grid-cols-2'] }
        : { has: ['grid', 'gap-3', 'grid-cols-2'], not: ['grid-cols-4'] },
  },
  {
    name: 'PageSkeleton 헤더 뼈대 여백',
    ui: () => <PageSkeleton components={[{ name: 'Header' }]} options={{ animation: 'none', iteration_count: 1 }} />,
    target: (c) => c.querySelector('header .max-w-7xl'),
    expectAt: padding,
  },
  {
    name: 'PageSkeleton 헤더 뼈대 메뉴 자리 (옛 hidden lg:flex)',
    ui: () => <PageSkeleton components={[{ name: 'Header' }]} options={{ animation: 'none', iteration_count: 1 }} />,
    target: (c) => c.querySelector('header .gap-6'),
    expectAt: (width) =>
      width >= 1024 ? { has: ['flex', 'items-center', 'gap-6'], not: ['hidden'] } : { has: ['hidden', 'items-center', 'gap-6'], not: ['flex'] },
  },
  {
    name: 'PageSkeleton 푸터 뼈대 여백',
    ui: () => <PageSkeleton components={[{ name: 'Footer' }]} options={{ animation: 'none', iteration_count: 1 }} />,
    target: (c) => c.querySelector('footer .max-w-7xl'),
    expectAt: (width) => ({ ...padding(width), has: [...padding(width).has, 'py-8'] }),
  },
];

describe('부품의 폭 분기 — Tailwind 반응형 접두 대신 엔진 폭', () => {
  let savedG7Core: any;
  const savedInnerWidth = window.innerWidth;

  beforeEach(() => {
    savedG7Core = (window as any).G7Core;
    (window as any).__slotContextValue = {
      getSlotComponents: () => [],
      subscribeToSlot: () => () => {},
      isEnabled: true,
    };
  });
  afterEach(() => {
    (window as any).G7Core = savedG7Core;
    delete (window as any).__slotContextValue;
    Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: savedInnerWidth });
  });

  /** 테스트 공통 G7Core — 번역은 기존 모의를 쓰고 폭 훅만 바꾼다 */
  const setCore = (useResponsive: () => { width: number }) => {
    (window as any).G7Core = {
      ...(savedG7Core ?? {}),
      useResponsive,
      dispatch: vi.fn(),
      state: { getLocal: () => ({}), getGlobal: () => ({}) },
      getSlotContext: () => (window as any).__slotContextValue,
    };
  };

  const assertAt = (c: WidthCase, el: Element | null | undefined, width: number) => {
    expect(el, `${c.name} — 대상 요소`).toBeTruthy();
    const { has, not } = c.expectAt(width);
    const got = tokens(el);
    expect(got, `${c.name} @${width}px`).toEqual(expect.arrayContaining(has));
    for (const cls of not) {
      expect(got, `${c.name} @${width}px 에 ${cls} 가 없어야 한다`).not.toContain(cls);
    }
  };

  // @scenario case=width_c01
  // @effects component_width_classes_follow_engine_width
  it.each(CASES.map((c) => [c.name, c] as const))('%s — 폭별 클래스가 Tailwind 경계와 같다', (_name, c) => {
    for (const width of WIDTHS) {
      setCore(() => ({ width }));
      const { container, unmount } = render(c.ui());
      assertAt(c, c.target(container), width);
      unmount();
    }
  });

  // @scenario case=width_c02
  // @effects component_width_classes_have_no_breakpoint_prefix
  it.each(CASES.map((c) => [c.name, c] as const))('%s — 어느 폭에서도 반응형 접두 클래스를 내지 않는다', (_name, c) => {
    for (const width of WIDTHS) {
      setCore(() => ({ width }));
      const { container, unmount } = render(c.ui());
      const offenders = Array.from(container.querySelectorAll('[class]'))
        .flatMap((el) => tokens(el))
        .filter((token) => PREFIXED.test(token));
      expect(offenders, `${c.name} @${width}px`).toEqual([]);
      unmount();
    }
  });

  // @scenario case=width_c03
  // @effects component_width_classes_follow_engine_width
  it.each(CASES.map((c) => [c.name, c] as const))('%s — 창 크기가 바뀌면 클래스가 따라 바뀐다', async (_name, c) => {
    setCore(useTestResponsive);
    const setWidth = (width: number) => {
      Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: width });
      window.dispatchEvent(new Event('resize'));
    };

    setWidth(500);
    const { container } = render(<TestResponsiveProvider>{c.ui()}</TestResponsiveProvider>);
    assertAt(c, c.target(container), 500);

    for (const width of [1300, 700, 900, 500]) {
      setWidth(width);
      await waitFor(() => assertAt(c, c.target(container), width));
    }
  });

  // @scenario case=width_c04
  // @effects component_width_classes_follow_preview_width
  it.each(CASES.map((c) => [c.name, c] as const))('%s — 편집기 미리보기 폭(overrideWidth)을 창 폭보다 먼저 따른다', (_name, c) => {
    setCore(useTestResponsive);
    Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: 1600 });

    const { container, rerender } = render(<TestResponsiveProvider overrideWidth={375}>{c.ui()}</TestResponsiveProvider>);
    assertAt(c, c.target(container), 375);

    rerender(<TestResponsiveProvider overrideWidth={820}>{c.ui()}</TestResponsiveProvider>);
    assertAt(c, c.target(container), 820);

    rerender(<TestResponsiveProvider overrideWidth={1280}>{c.ui()}</TestResponsiveProvider>);
    assertAt(c, c.target(container), 1280);
  });

  /** 엔진 훅도 창 폭도 없는 환경 — 폭을 모른다 */
  const setUnknownWidth = () => {
    const { useResponsive: _omit, ...rest } = savedG7Core ?? {};
    (window as any).G7Core = {
      ...rest,
      dispatch: vi.fn(),
      state: { getLocal: () => ({}), getGlobal: () => ({}) },
      getSlotContext: () => (window as any).__slotContextValue,
    };
    Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: undefined });
  };

  // @scenario case=width_c05
  // @effects component_width_unknown_defaults_to_engine_default
  it.each(CASES.map((c) => [c.name, c] as const))('%s — 폭을 모르면 엔진 기본 폭(1024, 검색 봇 렌더 폭)의 클래스를 낸다', (_name, c) => {
    setUnknownWidth();
    const { container } = render(c.ui());
    assertAt(c, c.target(container), 1024);
  });

  // @scenario case=width_c06
  // @effects component_width_unknown_defaults_to_engine_default
  it('Grid 와 화면 뼈대의 Grid 도 폭을 모르면 같은 기본 폭(1024)으로 열 수를 고른다', () => {
    setUnknownWidth();
    const responsive = { md: 3, lg: 4, xl: 5 };

    const grid = render(<Grid cols={1} responsive={responsive}>x</Grid>);
    expect(tokens(grid.container.firstChild as Element)).toContain('grid-cols-4');
    grid.unmount();

    const skeleton = render(
      <PageSkeleton
        components={[{ name: 'Grid', type: 'layout', props: { cols: 1, responsive }, children: [{ name: 'P', type: 'basic' }] }]}
        options={{ animation: 'none', iteration_count: 1 }}
      />
    );
    expect(tokens(skeleton.container.querySelector('[role="status"] > .grid'))).toContain('grid-cols-4');
  });
});

/**
 * 부품 폭 분기 — Tailwind 반응형 접두와 폭 미디어 의미 클래스 대신 렌더 폭으로 클래스를 고른다
 *
 * 부품 안의 `md:inline`·`sm:grid-cols-3` 같은 접두와, 폭 미디어를 품은 의미 클래스(`page-title` 의 640 이상 글자 크기,
 * `page-actions`·`modal-footer-row` 의 640 이하 세로 배치, `admin-footer-row` 의 768 이상 가로 배치)는 브라우저 창
 * 폭으로 판정되어 엔진 `responsive` 와 경계가 갈라지고 편집기 미리보기 기기 폭을 따르지 못했다. 부품이 렌더 폭
 * (`useRenderWidth` — Provider 폭 우선)으로 경계를 판정해 접두 없는 클래스를 내는지 잠근다. 경계 값은 Tailwind 와
 * 같다(sm 640·md 768·lg 1024·xl 1280). 의미 클래스의 폭 미디어 `max-width: 640px` 은 640 을 포함한다.
 *
 * 부품은 폭 미디어를 품은 클래스를 아예 붙이지 않는다. 옛 클래스를 붙인 채 렌더 폭 클래스를 더하면, 편집기 미리보기
 * 기기 폭과 실제 창 폭이 다를 때 CSS 미디어가 다른 판정을 덧씌운다(모바일 미리보기인데 창이 넓으면 `admin-footer-row`
 * 의 768 이상 가로 배치가 살아난다). 부품은 폭과 무관한 바탕만 가진 새 클래스(`page-heading`·`page-action-group`·
 * `admin-footer-bar`·`modal-footer-bar`)를 쓰고, 옛 정의는 사이트 호환을 위해 폐지 예정으로 남는다.
 */
import fs from 'node:fs';
import path from 'node:path';

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, fireEvent, waitFor } from '@testing-library/react';
import { CardGrid } from '../CardGrid';
import { FileList } from '../FileUploader/FileList';
import { PermissionTree, type PermissionNode } from '../PermissionTree';
import { MultilingualInput } from '../MultilingualInput';
import { AdminHeader } from '../AdminHeader';
import { AdminFooter } from '../AdminFooter';
import { PageHeader } from '../PageHeader';
import { Modal } from '../Modal';
import { ColumnSelector } from '../ColumnSelector';
import { CodeWorkspace } from '../CodeWorkspace';
import { classAtWidth, isWidthAtLeast, isWidthAtMost, WIDTH_BREAKPOINTS } from '../../layout/widthBreakpoints';

// Monaco 대역 — 이 시험은 머리 띠와 단축키 도움말의 클래스만 본다
vi.mock('@monaco-editor/react', () => ({
  default: ({ defaultValue: value }: any) => <textarea data-testid="editor-textarea" value={value ?? ''} readOnly />,
  DiffEditor: () => <div data-testid="diff-editor" />,
  loader: { init: () => Promise.resolve({}), config: vi.fn() },
}));

/** 폭 판정 접두가 붙은 클래스 토큰 (상태 변형 뒤에 붙은 것 포함) */
const PREFIXED = /(^|\s)([a-z0-9_-]+:)*(sm|md|lg|xl|2xl|max-(sm|md|lg|xl|2xl)|min-\[[^\]]+\]|max-\[[^\]]+\]):/;

/** 클래스 토큰 목록 */
const tokens = (el: Element | null | undefined): string[] =>
  (el?.getAttribute('class') ?? '').split(/\s+/).filter(Boolean);

/** 토큰 중 `grid-cols-N` */
const gridCols = (el: Element | null | undefined): string =>
  tokens(el).filter((t) => /^grid-cols-\d+$/.test(t)).join(' ');

/** 토큰 하나라도 있으면 그 토큰들을 이은 문자열 */
const pick = (el: Element | null | undefined, names: string[]): string =>
  names.filter((n) => tokens(el).includes(n)).join(' ');

/** 텍스트가 같은 첫 요소 */
const byText = (root: HTMLElement, text: string): HTMLElement | null =>
  (Array.from(root.querySelectorAll('*')) as HTMLElement[]).find(
    (el) => el.children.length === 0 && el.textContent === text
  ) ?? null;

/** 반응형 @apply 토큰(`md:flex-row`, `dark:md:…` 포함) */
const APPLY_BREAKPOINT = /(^|\s)([a-z0-9_-]+:)*(sm|md|lg|xl|2xl|max-(sm|md|lg|xl|2xl)|min-\[[^\]]+\]|max-\[[^\]]+\]):/;

/** 폭 미디어 머리(`@media (max-width: 640px)`, `(width >= 40rem)`, `@variant md`) */
const isWidthAtRule = (head: string): boolean =>
  (/^@media\b/.test(head) && /\(\s*(?:min-|max-)?width\s*[:<>=]|[<>]=?\s*width\s*[)<>=]/.test(head)) ||
  /^@(?:variant|screen)\s+(?:sm|md|lg|xl|2xl)\b/.test(head);

/**
 * CSS 원문에서 폭으로 갈리는 클래스(폭 미디어 안의 규칙, 폭 미디어를 품은 규칙, 반응형 @apply 를 가진 규칙)를 모은다.
 * 선택자 조각마다 첫 클래스만 센다(`.modal-footer .btn` 은 `modal-footer`).
 */
function widthMediaClasses(css: string): Set<string> {
  const text = css.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '));
  const found = new Set<string>();
  const stack: Array<{ width: boolean; classes: string[] }> = [];
  const owner = () => [...stack].reverse().find((s) => s.classes.length > 0);
  let seg = 0;
  for (let i = 0; i < text.length; i++) {
    const ch = text[i];
    if (ch === '{') {
      const head = text.slice(seg, i).trim().replace(/\s+/g, ' ');
      if (head.startsWith('@')) {
        const width = isWidthAtRule(head);
        if (width) owner()?.classes.forEach((c) => found.add(c));
        stack.push({ width, classes: [] });
      } else {
        const classes = head
          .split(',')
          .map((part) => part.trim().match(/^[^.[]*\.((?:\\.|[A-Za-z0-9_-])+)/)?.[1])
          .filter((c): c is string => Boolean(c));
        if (stack.some((s) => s.width)) classes.forEach((c) => found.add(c));
        stack.push({ width: false, classes });
      }
      seg = i + 1;
    } else if (ch === '}') {
      stack.pop();
      seg = i + 1;
    } else if (ch === ';') {
      const stmt = text.slice(seg, i).trim();
      const apply = stmt.match(/^@apply\s+([\s\S]*)$/);
      if (apply && apply[1].split(/\s+/).some((t) => APPLY_BREAKPOINT.test(t))) owner()?.classes.forEach((c) => found.add(c));
      seg = i + 1;
    }
  }

  return found;
}

/** 템플릿 원본 CSS 전부(`src/styles/**`) */
const stylesDir = path.resolve(__dirname, '../../../styles');
const readStyles = (dir: string): string[] =>
  fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) return readStyles(full);
    return entry.name.endsWith('.css') ? [fs.readFileSync(full, 'utf8')] : [];
  });
const WIDTH_MEDIA_CLASSES = new Set(readStyles(stylesDir).flatMap((css) => [...widthMediaClasses(css)]));

const permissionData: PermissionNode[] = [
  {
    id: 1,
    identifier: 'core',
    name: '코어',
    is_assignable: false,
    leaf_count: 1,
    children: [
      {
        id: 2,
        identifier: 'core.users',
        name: '사용자',
        is_assignable: false,
        leaf_count: 1,
        children: [
          { id: 3, identifier: 'core.users.read', name: '조회', is_assignable: true, leaf_count: 1, children: [] },
        ],
      },
    ],
  },
];

/**
 * 부품 하나의 폭 판정 표.
 * - ui: 그릴 요소
 * - probe: 폭에 따라 바뀌는 클래스를 문자열로 뽑는다
 * - expect: [폭, 기대 문자열] — 경계 양쪽을 적는다
 * - open: 그린 뒤 펼쳐야 보이는 부분이 있으면 연다
 */
interface WidthCase {
  name: string;
  ui: () => React.ReactElement;
  probe: (root: HTMLElement) => string;
  expect: Array<[number, string]>;
  open?: (root: HTMLElement) => void;
}

const CASES: WidthCase[] = [
  {
    name: 'CardGrid 열 수 (responsiveColumns 지정)',
    ui: () => (
      <CardGrid
        data={[{ id: 1 }]}
        cardColumns={[{ id: 'c', cellChildren: [] } as any]}
        gridColumns={3}
        responsiveColumns={{ sm: 1, md: 2, lg: 3, xl: 4 }}
        pagination={false}
      />
    ),
    probe: (root) => gridCols(root.querySelector('.grid')),
    expect: [
      [639, 'grid-cols-1'],
      [640, 'grid-cols-1'],
      [767, 'grid-cols-1'],
      [768, 'grid-cols-2'],
      [1023, 'grid-cols-2'],
      [1024, 'grid-cols-3'],
      [1279, 'grid-cols-3'],
      [1280, 'grid-cols-4'],
    ],
  },
  {
    name: 'CardGrid 열 수 (기본값 — md 는 gridColumns 와 2 중 작은 값, lg 는 gridColumns)',
    ui: () => (
      <CardGrid data={[{ id: 1 }]} cardColumns={[{ id: 'c', cellChildren: [] } as any]} gridColumns={4} pagination={false} />
    ),
    probe: (root) => gridCols(root.querySelector('.grid')),
    expect: [
      [767, 'grid-cols-1'],
      [768, 'grid-cols-2'],
      [1023, 'grid-cols-2'],
      [1024, 'grid-cols-4'],
      [1600, 'grid-cols-4'],
    ],
  },
  {
    name: 'FileList 썸네일 격자',
    ui: () => (
      <FileList
        allItems={[]}
        canAddMore={false}
        totalCount={0}
        maxFiles={5}
        maxSize={1}
        onRemove={vi.fn()}
        onRetry={vi.fn()}
        onImageClick={vi.fn()}
        onDownload={vi.fn()}
        onDragEnd={vi.fn()}
        onAddClick={vi.fn()}
      />
    ),
    probe: (root) => gridCols(root.querySelector('.grid')),
    expect: [
      [639, 'grid-cols-2'],
      [640, 'grid-cols-3'],
      [767, 'grid-cols-3'],
      [768, 'grid-cols-4'],
      [1023, 'grid-cols-4'],
      [1024, 'grid-cols-5'],
      [1600, 'grid-cols-5'],
    ],
  },
  {
    name: 'PermissionTree 권한 격자 (desktopColumns=3)',
    ui: () => <PermissionTree data={permissionData} value={[]} desktopColumns={3} />,
    probe: (root) => gridCols(root.querySelector('.pl-8.grid')),
    expect: [
      [767, 'grid-cols-1'],
      [768, 'grid-cols-3'],
      [1280, 'grid-cols-3'],
    ],
  },
  {
    name: 'PermissionTree 권한 격자 (desktopColumns 기본 2)',
    ui: () => <PermissionTree data={permissionData} value={[]} />,
    probe: (root) => gridCols(root.querySelector('.pl-8.grid')),
    expect: [
      [767, 'grid-cols-1'],
      [768, 'grid-cols-2'],
    ],
  },
  {
    name: 'MultilingualInput 언어 탭 이름 (showCodeOnMobile)',
    ui: () => (
      <MultilingualInput
        value={{ ko: '이름', en: 'Name' }}
        onChange={vi.fn()}
        name="title"
        defaultLocale="ko"
        layout="tabs"
        showCodeOnMobile
      />
    ),
    probe: (root) => `name:${pick(byText(root, 'English'), ['inline', 'hidden'])} code:${pick(byText(root, 'EN'), ['inline', 'hidden'])}`,
    expect: [
      [767, 'name:hidden code:inline'],
      [768, 'name:inline code:hidden'],
    ],
  },
  {
    name: 'AdminHeader 사용자 이름 묶음',
    ui: () => <AdminHeader user={{ name: '홍길동', email: 'h@example.com', role: '관리자' }} />,
    probe: (root) => pick(byText(root, '홍길동')?.parentElement, ['block', 'hidden']),
    expect: [
      [767, 'hidden'],
      [768, 'block'],
    ],
  },
  {
    name: 'AdminFooter 줄 방향과 구분점',
    ui: () => <AdminFooter version="1.0.0" />,
    probe: (root) =>
      `row:${pick(root.querySelector('.admin-footer-bar'), ['flex-row'])} dot:${pick(byText(root, '•'), ['inline', 'hidden'])}`,
    expect: [
      [767, 'row: dot:hidden'],
      [768, 'row:flex-row dot:inline'],
    ],
  },
  {
    name: 'PageHeader 제목 글자 크기와 액션 묶음',
    ui: () => <PageHeader title="사용자 관리" actions={[{ id: 1, label: '추가', variant: 'primary' }]} />,
    probe: (root) =>
      `title:${pick(root.querySelector('.page-heading'), ['text-2xl'])} actions:${pick(root.querySelector('.page-action-group'), ['w-full', 'justify-start'])}`,
    expect: [
      [639, 'title: actions:w-full justify-start'],
      [640, 'title:text-2xl actions:w-full justify-start'],
      [641, 'title:text-2xl actions:'],
    ],
  },
  {
    name: 'PageHeader 자식 액션 묶음',
    ui: () => (
      <PageHeader title="사용자 관리">
        <span>단추</span>
      </PageHeader>
    ),
    probe: (root) => pick(root.querySelector('.page-action-group'), ['w-full', 'justify-start']),
    expect: [
      [640, 'w-full justify-start'],
      [641, ''],
    ],
  },
  {
    name: 'Modal 바닥 단추 줄',
    ui: () => (
      <Modal isOpen onClose={vi.fn()} title="창" footer={[{ type: 'basic', name: 'Button', text: '확인' }]}>
        <div>내용</div>
      </Modal>
    ),
    probe: (root) => pick(root.ownerDocument.querySelector('.modal-footer-bar'), ['flex-col', 'gap-2', '[&_.btn]:w-full']),
    expect: [
      [375, 'flex-col gap-2 [&_.btn]:w-full'],
      [640, 'flex-col gap-2 [&_.btn]:w-full'],
      [641, ''],
    ],
  },
  {
    name: 'ColumnSelector 단추 글',
    ui: () => (
      <ColumnSelector
        columns={[{ field: 'name', header: '이름' }] as any}
        visibleColumns={['name']}
        onColumnVisibilityChange={vi.fn()}
        triggerLabel="열 선택"
      />
    ),
    probe: (root) => pick(byText(root, '열 선택'), ['inline', 'hidden']),
    expect: [
      [639, 'hidden'],
      [640, 'inline'],
    ],
  },
  {
    name: 'CodeWorkspace 줄바꿈 단추 글',
    ui: () => <CodeWorkspace fileKey="layout:a" serverValue={'<layout name="a"/>'} serverRevision={1} />,
    probe: (root) => pick(root.querySelector('[data-testid="word-wrap-toggle"] span:last-child'), ['inline', 'hidden']),
    expect: [
      [639, 'hidden'],
      [640, 'inline'],
    ],
  },
  {
    name: 'CodeWorkspace 단축키 도움말 격자',
    ui: () => <CodeWorkspace fileKey="layout:a" serverValue={'<layout name="a"/>'} serverRevision={1} />,
    open: (root) => fireEvent.click(root.querySelector('[data-testid="shortcut-help-open"]') as HTMLElement),
    probe: (root) => gridCols(root.ownerDocument.querySelector('.gap-x-7.px-5.py-4')),
    expect: [
      [639, 'grid-cols-1'],
      [640, 'grid-cols-2'],
    ],
  },
];

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

/** 시험 사이에서 공유하는 G7Core 바탕 — Modal 바닥은 DynamicRenderer 로 그린다 */
const baseG7Core = () => ({
  t: (key: string) => (key === 'common.language_en' ? 'English' : key),
  locale: { supported: () => ['ko', 'en'], current: () => 'ko' },
  getDynamicRenderer: () => ({ componentDef }: any) => <button className="btn">{componentDef.text}</button>,
  getComponentRegistry: () => ({}),
  getDataBindingEngine: () => ({}),
  getTranslationEngine: () => ({}),
  getActionDispatcher: () => ({}),
  renderItemChildren: () => null,
  getComponentMap: () => ({}),
});

const setWindowWidth = (width: number, dispatch = true) => {
  Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: width });
  if (dispatch) {
    window.dispatchEvent(new Event('resize'));
  }
};

/** 폭 두 개를 고른다 — 기대 문자열이 다른 첫 짝 */
const contrastingWidths = (c: WidthCase): [[number, string], [number, string]] => {
  const [first] = c.expect;
  const other = c.expect.find(([, value]) => value !== first[1]);
  if (!other) {
    throw new Error(`${c.name}: 기대값이 모두 같아 폭 전환을 잴 수 없다`);
  }
  return [first, other];
};

describe('부품 폭 분기 — 렌더 폭으로 접두 없는 클래스를 고른다', () => {
  let savedG7Core: any;
  let savedWidth: number;

  beforeEach(() => {
    savedG7Core = (window as any).G7Core;
    savedWidth = window.innerWidth;
    window.localStorage.clear();
  });

  afterEach(() => {
    (window as any).G7Core = savedG7Core;
    setWindowWidth(savedWidth);
    delete (window as any).G7Config;
  });

  /** useResponsive 가 주는 폭으로 그린다 */
  const renderAt = (c: WidthCase, width: number) => {
    (window as any).G7Core = { ...baseG7Core(), useResponsive: () => ({ width }) };
    const result = render(c.ui());
    c.open?.(result.container);
    return result;
  };

  // @scenario case=wcls_c01
  // @effects component_width_helper_matches_tailwind_breakpoints
  it('경계 헬퍼는 Tailwind 경계와 같은 값으로 판정한다', () => {
    expect(WIDTH_BREAKPOINTS).toEqual({ sm: 640, md: 768, lg: 1024, xl: 1280, '2xl': 1536 });
    expect(isWidthAtLeast(767, 'md')).toBe(false);
    expect(isWidthAtLeast(768, 'md')).toBe(true);
    expect(isWidthAtLeast(899, 900)).toBe(false);
    expect(isWidthAtLeast(900, 900)).toBe(true);
    expect(isWidthAtMost(640, 640)).toBe(true);
    expect(isWidthAtMost(641, 640)).toBe(false);
    expect(classAtWidth(639, 'sm', 'inline', 'hidden')).toBe('hidden');
    expect(classAtWidth(640, 'sm', 'inline', 'hidden')).toBe('inline');
    expect(classAtWidth(500, 'md', 'flex-row')).toBe('');
  });

  describe.each(CASES.map((c) => [c.name, c] as const))('%s', (_name, c) => {
    // @scenario case=wcls_c02
    // @effects component_width_classes_by_render_width
    it.each(c.expect)('폭 %ipx → %s', (width, expected) => {
      const { container } = renderAt(c, width);
      expect(c.probe(container)).toBe(expected);
    });
  });

  // @scenario case=wcls_c03
  // @effects component_width_classes_have_no_breakpoint_prefix
  it.each(CASES.map((c) => [c.name, c] as const))('%s — 어느 폭에서도 반응형 접두 클래스를 내지 않는다', (_name, c) => {
    for (const width of [320, 639, 640, 641, 767, 768, 1023, 1024, 1280, 1536]) {
      const { container, unmount } = renderAt(c, width);
      const offenders = (Array.from(document.body.querySelectorAll('*')) as Element[])
        .flatMap((el) => tokens(el))
        .filter((token) => PREFIXED.test(token));
      expect({ width, offenders }).toEqual({ width, offenders: [] });
      expect(container).toBeTruthy();
      unmount();
    }
  });

  // @scenario case=wcls_c04
  // @effects component_width_classes_follow_resize
  it.each(CASES.map((c) => [c.name, c] as const))('%s — 창 크기가 바뀌면 클래스가 따라 바뀐다', async (_name, c) => {
    const [[narrow, narrowValue], [wide, wideValue]] = contrastingWidths(c);
    (window as any).G7Core = { ...baseG7Core(), useResponsive: useTestResponsive };

    setWindowWidth(narrow, false);
    const { container } = render(<TestResponsiveProvider>{c.ui()}</TestResponsiveProvider>);
    c.open?.(container);
    expect(c.probe(container)).toBe(narrowValue);

    setWindowWidth(wide);
    await waitFor(() => expect(c.probe(container)).toBe(wideValue));

    setWindowWidth(narrow);
    await waitFor(() => expect(c.probe(container)).toBe(narrowValue));
  });

  // @scenario case=wcls_c05
  // @effects component_width_classes_follow_preview_width
  it.each(CASES.map((c) => [c.name, c] as const))('%s — 편집기 미리보기 폭을 창 폭보다 먼저 따른다', (_name, c) => {
    const [[narrow, narrowValue], [wide, wideValue]] = contrastingWidths(c);
    (window as any).G7Core = { ...baseG7Core(), useResponsive: useTestResponsive };

    // 창은 넓은 쪽과 다른 쪽 끝에 두어, 창 폭으로 고르면 틀리게 한다
    setWindowWidth(wide, false);
    const first = render(<TestResponsiveProvider overrideWidth={narrow}>{c.ui()}</TestResponsiveProvider>);
    c.open?.(first.container);
    expect(c.probe(first.container)).toBe(narrowValue);
    first.unmount();

    setWindowWidth(narrow, false);
    const second = render(<TestResponsiveProvider overrideWidth={wide}>{c.ui()}</TestResponsiveProvider>);
    c.open?.(second.container);
    expect(c.probe(second.container)).toBe(wideValue);
  });

  // @scenario case=wcls_c06
  // @effects component_width_classes_fall_back_to_window_without_hook
  it.each(CASES.map((c) => [c.name, c] as const))('%s — G7Core 훅이 없으면 창 폭으로 고르고 resize 로 갱신한다', async (_name, c) => {
    const [[narrow, narrowValue], [wide, wideValue]] = contrastingWidths(c);
    (window as any).G7Core = baseG7Core();

    setWindowWidth(narrow, false);
    const { container } = render(c.ui());
    c.open?.(container);
    expect(c.probe(container)).toBe(narrowValue);

    setWindowWidth(wide);
    await waitFor(() => expect(c.probe(container)).toBe(wideValue));
  });

  // @scenario case=wcls_c07
  // @effects component_width_classes_by_render_width
  it('ColumnSelector 는 labelClassName 을 주면 폭과 무관하게 그 클래스를 쓴다', () => {
    for (const width of [375, 1280]) {
      (window as any).G7Core = { ...baseG7Core(), useResponsive: () => ({ width }) };
      const { container, unmount } = render(
        <ColumnSelector
          columns={[{ field: 'name', header: '이름' }] as any}
          visibleColumns={['name']}
          onColumnVisibilityChange={vi.fn()}
          triggerLabel="열 선택"
          labelClassName="inline"
        />
      );
      expect(tokens(byText(container, '열 선택'))).toEqual(['inline', 'text-sm']);
      unmount();
    }
  });

  // @scenario case=wcls_c08
  // @effects component_width_classes_by_render_width
  it('FileList 표 한 칸 크기(xs)는 폭과 무관하게 두 칸 격자다', () => {
    for (const width of [375, 1600]) {
      (window as any).G7Core = { ...baseG7Core(), useResponsive: () => ({ width }) };
      const { container, unmount } = render(
        <FileList
          allItems={[]}
          canAddMore={false}
          totalCount={0}
          maxFiles={5}
          maxSize={1}
          onRemove={vi.fn()}
          onRetry={vi.fn()}
          onImageClick={vi.fn()}
          onDownload={vi.fn()}
          onDragEnd={vi.fn()}
          onAddClick={vi.fn()}
          size="xs"
        />
      );
      expect(gridCols(container.querySelector('.grid'))).toBe('grid-cols-2');
      unmount();
    }
  });

  // @scenario case=wcls_c09
  // @effects component_width_classes_avoid_width_media_semantic_classes
  it('폭 클래스 수집기는 폭 미디어 안 규칙·폭 미디어를 품은 규칙·반응형 @apply 를 찾고 폭과 무관한 미디어는 거른다', () => {
    const sample = [
      '.a { @apply flex md:flex-row; }',
      '@media (max-width: 640px) { .b, .c .btn { width: 100%; } }',
      '.d { color: red; @media (width >= 48rem) { color: blue; } }',
      '@media (prefers-reduced-motion: reduce) { .e { animation: none; } }',
      '.f { @apply dark:bg-gray-800 hover:bg-gray-50; }',
      '/* @media (max-width: 640px) { .g { width: 1px; } } */',
    ].join('\n');
    expect([...widthMediaClasses(sample)].sort()).toEqual(['a', 'b', 'c', 'd']);
  });

  // @scenario case=wcls_c09
  // @effects component_width_classes_avoid_width_media_semantic_classes
  it.each(CASES.map((c) => [c.name, c] as const))('%s — 어느 폭에서도 폭 미디어를 품은 클래스를 붙이지 않는다', (_name, c) => {
    for (const width of [320, 375, 639, 640, 641, 767, 768, 1023, 1024, 1280, 1536]) {
      const { unmount } = renderAt(c, width);
      const offenders = (Array.from(document.body.querySelectorAll('*')) as Element[])
        .flatMap((el) => tokens(el))
        .filter((token) => WIDTH_MEDIA_CLASSES.has(token));
      expect({ width, offenders: [...new Set(offenders)] }).toEqual({ width, offenders: [] });
      unmount();
    }
  });

  // @scenario case=wcls_c09
  // @effects component_width_classes_avoid_width_media_semantic_classes
  it('부품이 쓰는 바탕 클래스는 정의가 있고 그 정의는 폭으로 갈리지 않는다', () => {
    // 의미 클래스 정의는 semantic.css 에 있다(#434 분리 — main.css 는 불러오기와 바탕 규칙만 둔다)
    const css = fs.readFileSync(path.join(stylesDir, 'semantic.css'), 'utf8');
    for (const name of ['page-heading', 'page-action-group', 'admin-footer-bar', 'modal-footer-bar']) {
      expect(css, `.${name} 정의가 없다`).toMatch(new RegExp(`^\\s*\\.${name}\\s*\\{`, 'm'));
      expect(WIDTH_MEDIA_CLASSES.has(name), `.${name} 정의가 폭으로 갈린다`).toBe(false);
    }
  });
});

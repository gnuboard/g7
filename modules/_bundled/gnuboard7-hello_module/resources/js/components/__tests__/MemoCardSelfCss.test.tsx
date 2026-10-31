/**
 * @file MemoCardSelfCss.test.tsx
 * @description Hello 모듈 화면이 자기 순수 CSS 만으로 그려지는지 — MemoCard 두 판본과 모듈 레이아웃 4종의 클래스
 *
 * 0.2.x 까지 MemoCard 와 모듈 레이아웃은 Tailwind 유틸리티(`border-gray-200`, `dark:bg-gray-800` 등)와 관리자 템플릿
 * 의미 클래스(`text-page-heading`, `section-heading`)를 썼다. 그 클래스는 템플릿 CSS 가 우연히 만들어 둔 것이라,
 * 스타일시트가 없는 Hello 사용자 템플릿에서는 카드 테두리·제목 서식이 오류 없이 빠졌다. 0.3.0 은 모듈이 자기 CSS
 * (resources/css/main.css)를 싣고, 화면은 그 파일에 정의된 `hellomodule-*` 클래스만 쓴다.
 *
 * 함께 잠그는 작성 규약(학습 샘플이 보여야 하는 것):
 *   - 색·테두리·배경(의미)은 `@layer g7-extension` 안, 여백·정렬(배치)은 층 밖 — 두 칸을 모두 쓴다
 *   - 다크는 템플릿 `.dark` 가 아니라 중립 표식 `data-g7-color-scheme` 으로, 「다크 안의 라이트」 구역은 뺀다
 *
 * @scenario-manifest tests/scenarios/memo-card-self-css.yaml
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { render } from '@testing-library/react';
import { MemoCard } from '../composite/MemoCard';
import { MemoCard as AdminMemoCard } from '../for/gnuboard7-hello_admin_template/MemoCard';

const MODULE_ROOT = path.resolve(__dirname, '../../../..');
const CSS = fs.readFileSync(path.join(MODULE_ROOT, 'resources/css/main.css'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');

/** 중립 다크 표식 선택자의 꼬리 — 다크 안, 단 다크 속 라이트 구역은 제외 */
const DARK_TAIL =
  ':is([data-g7-color-scheme="dark"] *, [data-g7-color-scheme="dark"])' +
  ':not(:where([data-g7-color-scheme="dark"] [data-g7-color-scheme="light"] *, [data-g7-color-scheme="dark"] [data-g7-color-scheme="light"]))';

/** `@layer g7-extension { … }` 본문과 그 밖을 가른다 */
const splitLayer = (css: string): { inLayer: string; outside: string } => {
  const start = css.indexOf('@layer g7-extension');
  const open = css.indexOf('{', start);
  let depth = 0;
  let end = open;
  for (let i = open; i < css.length; i += 1) {
    if (css[i] === '{') depth += 1;
    if (css[i] === '}') depth -= 1;
    if (depth === 0) {
      end = i;
      break;
    }
  }

  return { inLayer: css.slice(open + 1, end), outside: css.slice(0, start) + css.slice(end + 1) };
};

/** 규칙 목록 — [선택자, 선언] */
const rules = (css: string): Array<[string, string]> =>
  [...css.matchAll(/([^{}]+)\{([^{}]*)\}/g)].map((m) => [m[1].trim(), m[2]]);

/** 선택자에 쓰인 클래스 이름 전부 */
const definedClasses = new Set([...CSS.matchAll(/\.(hellomodule-[a-z0-9_-]+)/g)].map((m) => m[1]));

const { inLayer, outside } = splitLayer(CSS);

/** 클래스 하나의 (층 안 | 층 밖) 단독 규칙 선언을 모은다 */
const declsOf = (part: string, cls: string): string =>
  rules(part)
    .filter(([sel]) => sel.split(',').some((s) => s.trim() === `.${cls}`))
    .map(([, body]) => body)
    .join(';');

const classTokens = (root: Element): string[] =>
  [root, ...root.querySelectorAll('*')].flatMap((el) => [...el.classList]);

describe('[case:memo-card-self-css] MemoCard 와 모듈 레이아웃은 모듈 자기 CSS 로 그려진다', () => {
  /**
   * @scenario surface=component
   * @effects memo_card_uses_module_css_classes
   */
  it('기본 판본은 hellomodule-card 계열 클래스만 쓰고 강조는 수식 클래스로 붙는다', () => {
    const { container, rerender } = render(<MemoCard title="제목" body="본문" />);
    const root = container.firstElementChild!;

    expect(root).toHaveClass('hellomodule-card');
    expect(root).not.toHaveClass('hellomodule-card--highlighted');
    expect(container.querySelector('h3')).toHaveClass('hellomodule-card__title');
    expect(container.querySelector('p')).toHaveClass('hellomodule-card__body');

    rerender(<MemoCard title="제목" body="본문" highlighted />);
    expect(container.firstElementChild).toHaveClass('hellomodule-card', 'hellomodule-card--highlighted');

    for (const token of classTokens(container.firstElementChild!)) {
      expect(token).toMatch(/^hellomodule-/);
      expect(definedClasses.has(token)).toBe(true);
    }
  });

  /**
   * @scenario surface=component
   * @effects memo_card_uses_module_css_classes
   */
  it('Hello 관리자 템플릿 판본도 모듈 CSS 의 hellomodule-row 계열만 쓴다', () => {
    const { container } = render(<AdminMemoCard title="제목" body="본문" highlighted />);
    const root = container.firstElementChild!;

    expect(root).toHaveClass('hellomodule-row');
    expect(root.querySelector('.hellomodule-row__title')).toHaveClass('hellomodule-row__title--highlighted');
    for (const token of classTokens(root)) {
      expect(token).toMatch(/^hellomodule-/);
      expect(definedClasses.has(token)).toBe(true);
    }
  });

  /**
   * @scenario surface=component
   * @effects caller_class_name_is_appended
   */
  it('호출처 className 은 뒤에 덧붙고, 비면 빈 토큰을 남기지 않는다', () => {
    const { container } = render(<MemoCard title="제목" className="extra" />);

    expect(container.firstElementChild!.getAttribute('class')).toBe('hellomodule-card extra');
  });

  /**
   * @scenario surface=layout
   * @effects layouts_use_only_module_css_classes
   */
  it('모듈 레이아웃 4종의 클래스는 모두 hellomodule-* 이고 모듈 CSS 에 정의돼 있다', () => {
    const layouts = [
      'resources/layouts/user/user_memo_list.json',
      'resources/layouts/admin/admin_memo_list.json',
      'resources/layouts/admin/admin_memo_form.json',
      'resources/layouts/admin/admin_markup_demo.json',
    ];
    const tokens: string[] = [];
    const walk = (node: unknown): void => {
      if (Array.isArray(node)) {
        node.forEach(walk);
        return;
      }
      if (!node || typeof node !== 'object') return;
      const className = (node as { props?: { className?: unknown } }).props?.className;
      if (typeof className === 'string') tokens.push(...className.split(/\s+/).filter(Boolean));
      Object.values(node).forEach(walk);
    };
    for (const rel of layouts) walk(JSON.parse(fs.readFileSync(path.join(MODULE_ROOT, rel), 'utf8')));

    expect(tokens.length).toBeGreaterThan(10);
    for (const token of tokens) {
      expect(token).toMatch(/^hellomodule-/);
      expect(definedClasses.has(token)).toBe(true);
    }
  });

  /**
   * @scenario surface=stylesheet
   * @effects meaning_in_extension_layer_layout_outside
   */
  it('카드의 색·테두리는 g7-extension 층 안에, 여백은 층 밖에 있다', () => {
    expect(declsOf(inLayer, 'hellomodule-card')).toMatch(/background-color/);
    expect(declsOf(inLayer, 'hellomodule-card')).toMatch(/border:/);
    expect(declsOf(inLayer, 'hellomodule-card')).not.toMatch(/padding/);
    expect(declsOf(outside, 'hellomodule-card')).toMatch(/padding/);
    expect(declsOf(outside, 'hellomodule-card')).not.toMatch(/color/);
    expect(declsOf(inLayer, 'hellomodule-card__title')).toMatch(/color:/);
    expect(declsOf(inLayer, 'hellomodule-page__title')).toMatch(/color:/);
    expect(declsOf(outside, 'hellomodule-list > * + *')).toMatch(/margin-top/);
  });

  /**
   * @scenario surface=stylesheet
   * @effects dark_uses_neutral_color_scheme_marker
   */
  it('다크 규칙은 템플릿 .dark 가 아니라 중립 표식만 쓰고, 다크 속 라이트 구역은 뺀다', () => {
    expect(CSS).not.toMatch(/\.dark\b/);
    const darkRules = rules(inLayer).filter(([sel]) => sel.includes('data-g7-color-scheme'));

    expect(darkRules.length).toBeGreaterThan(0);
    for (const [sel] of darkRules) {
      expect(sel.endsWith(DARK_TAIL)).toBe(true);
    }
    // 층 밖(배치)에는 다크 규칙이 없다 — 색은 기본값 칸에서만 바뀐다
    expect(outside).not.toMatch(/data-g7-color-scheme/);
    // 강조 카드 다크 짝은 일반 카드 다크 짝 뒤 — 강도가 같아 순서가 이긴다
    const order = darkRules.map(([sel]) => sel.slice(0, sel.indexOf(':is(')));
    expect(order.indexOf('.hellomodule-card--highlighted')).toBeGreaterThan(order.indexOf('.hellomodule-card'));
  });
});

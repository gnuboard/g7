/**
 * @file IconPrefixedLayoutNames.test.ts
 * @description 사용자 레이아웃이 Icon 에 넘기는 「스타일 토큰이 붙은 이름」 전수 — 스타일 클래스가 한 번만 나온다
 *
 * 사용자 템플릿·번들 모듈·번들 플러그인의 사용자 레이아웃(.g7.html)에서 `<icon name="…">` 값을 모은다.
 *  - 리터럴 값 중 스타일 토큰(fas·far·fab·fal·fad·fa-solid·fa-regular·fa-brands·fa-light·fa-duotone)이 있는 것
 *  - 식(`{{ … }}`) 안의 따옴표 문자열 중 스타일 토큰이 있는 것 (`? 'fa-solid fa-chevron-up' : …`)
 *  - 식 안의 `?? 'fas fa-…'` 대체값
 * 각 값을 Icon 으로 그려 스타일 클래스가 정확히 하나이고, `fa-fas`·`fa-solid` 같은 겹친 클래스가 없으며,
 * 글리프와 추가 클래스(fa-spin 등)가 순서대로 남는지 잰다. 종전 Icon 은 `fas fa-x` 를 `fas fa-fas fa-x` 로 그렸다.
 *
 * 모집단 하한은 실측 수(2026-11-21: 파일 205 · 단축형 리터럴 8 · fa-solid 리터럴 4 · 대체값 0, 고유 이름 5)와 같게 둔다 —
 * 스캔 경로가 틀어져 0 건이 되면 시험이 공허하게 통과하지 않게 한다.
 *
 * @scenario-manifest tests/scenarios/icon-prefix-parsing.yaml
 * @vitest-environment jsdom
 */

import React from 'react';
import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect } from 'vitest';
import { render, cleanup } from '@testing-library/react';
import { Icon } from '../../src/components/basic/Icon';

const REPO_ROOT = path.resolve(__dirname, '../../../../..');
const SIDE = 'user';
const TEMPLATE_LAYOUTS = path.join(REPO_ROOT, 'templates/_bundled/sirsoft-basic/layouts');

/** 스타일 토큰 → FA 단축 클래스 */
const STYLE_CLASS: Record<string, string> = {
  fas: 'fas', 'fa-solid': 'fas',
  far: 'far', 'fa-regular': 'far',
  fal: 'fal', 'fa-light': 'fal',
  fad: 'fad', 'fa-duotone': 'fad',
  fab: 'fab', 'fa-brands': 'fab',
};

const SHORT_CLASSES = ['fas', 'far', 'fab', 'fal', 'fad'];
const DOUBLED_STYLE_CLASS = /^fa-(fas|far|fab|fal|fad|solid|regular|brands|light|duotone)$/;
const HAS_STYLE_TOKEN = /(^|\s)(fas|far|fab|fal|fad|fa-solid|fa-regular|fa-brands|fa-light|fa-duotone)(\s|$)/;
const HAS_SHORT_TOKEN = /(^|\s)(fas|far|fab|fal|fad)(\s|$)/;
const HAS_FA_SOLID = /(^|\s)fa-solid(\s|$)/;
/** 따옴표 값 안의 `>` 를 넘어 여는 태그 하나를 읽는다 */
const ICON_TAG = /<icon\s((?:[^>"]|"[^"]*")*)>/g;
const NAME_ATTR = /(?:^|\s)name="([^"]*)"/;
const NULLISH_FALLBACK = /\?\?\s*'([^']*)'/g;

interface IconNameCase {
  file: string;
  value: string;
  kind: 'literal' | 'expression' | 'fallback';
}

/**
 * 디렉터리 아래 .g7.html 을 모두 모은다.
 *
 * @param dir 디렉터리
 * @param acc 누적
 * @returns 파일 경로 목록
 */
function walk(dir: string, acc: string[] = []): string[] {
  if (!fs.existsSync(dir)) return acc;
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full, acc);
    else if (entry.name.endsWith('.g7.html')) acc.push(full);
  }

  return acc;
}

/**
 * 스캔할 레이아웃 디렉터리 — 템플릿 + 번들 모듈·플러그인의 같은 쪽(user) 레이아웃.
 *
 * @returns 디렉터리 목록
 */
function layoutDirs(): string[] {
  const dirs = [TEMPLATE_LAYOUTS];
  for (const kind of ['modules', 'plugins']) {
    const base = path.join(REPO_ROOT, kind, '_bundled');
    if (!fs.existsSync(base)) continue;
    for (const ext of fs.readdirSync(base)) {
      dirs.push(path.join(base, ext, 'resources/layouts', SIDE));
    }
  }

  return dirs;
}

/**
 * 레이아웃에서 스타일 토큰이 붙은 아이콘 이름을 모은다.
 *
 * @returns 사례 목록 (중복 포함 — 모집단 계수용)
 */
function collectCases(): { cases: IconNameCase[]; files: number } {
  const files = layoutDirs().flatMap((dir) => walk(dir));
  const cases: IconNameCase[] = [];

  for (const file of files) {
    const src = fs.readFileSync(file, 'utf8');
    const rel = path.relative(REPO_ROOT, file).split(path.sep).join('/');

    for (const tag of src.matchAll(ICON_TAG)) {
      const name = NAME_ATTR.exec(tag[1]);
      if (!name) continue;
      const value = name[1];

      if (!value.includes('{{')) {
        if (HAS_STYLE_TOKEN.test(value)) cases.push({ file: rel, value, kind: 'literal' });
        continue;
      }

      // `?? '…'` 의 여는 따옴표 위치 — 같은 위치의 문자열은 대체값으로 센다
      const fallbackQuotes = new Set(
        [...value.matchAll(NULLISH_FALLBACK)].map((m) => (m.index ?? 0) + m[0].indexOf("'")),
      );
      for (const quoted of value.matchAll(/'([^']*)'/g)) {
        if (!HAS_STYLE_TOKEN.test(quoted[1])) continue;
        cases.push({ file: rel, value: quoted[1], kind: fallbackQuotes.has(quoted.index ?? -1) ? 'fallback' : 'expression' });
      }
    }
  }

  return { cases, files: files.length };
}

/**
 * 이름에서 기대 클래스를 직접 계산한다 (해석기를 쓰지 않는 독립 계산).
 *
 * @param value 아이콘 이름
 * @returns 기대 className
 */
function expectedClass(value: string): string {
  const tokens = value.trim().split(/\s+/);
  const styleToken = tokens.find((token) => STYLE_CLASS[token] !== undefined)!;
  const rest = tokens.filter((token) => STYLE_CLASS[token] === undefined);
  const [glyph, ...extras] = rest;

  return [STYLE_CLASS[styleToken], `fa-${glyph.replace(/^fa-/, '')}`, ...extras].join(' ');
}

const { cases, files } = collectCases();
const uniqueValues = [...new Set(cases.map((c) => c.value.trim().replace(/\s+/g, ' ')))].sort();

describe(`${SIDE} 레이아웃의 스타일 토큰 붙은 아이콘 이름`, () => {
  /**
   * @scenario component=layout_scan
   * @effects style_prefix_tokens_resolved_once
   */
  it('스캔 모집단이 실측 하한 이상이다 (경로가 틀어져 공허하게 통과하지 않는다)', () => {
    const shortCount = cases.filter((c) => HAS_SHORT_TOKEN.test(c.value)).length;
    const solidLiteralCount = cases.filter((c) => c.kind === 'literal' && HAS_FA_SOLID.test(c.value)).length;
    const fallbackCount = cases.filter((c) => c.kind === 'fallback').length;

    // eslint-disable-next-line no-console
    console.info(`[IconPrefixedLayoutNames:${SIDE}] files=${files} cases=${cases.length} unique=${uniqueValues.length} short=${shortCount} faSolidLiteral=${solidLiteralCount} fallback=${fallbackCount}`);

    expect(files).toBeGreaterThan(100);
    expect(shortCount).toBeGreaterThanOrEqual(8);
    expect(solidLiteralCount).toBeGreaterThanOrEqual(4);
    // 사용자 레이아웃에는 아직 `?? 'fas fa-…'` 대체값이 없다(fallbackCount=0) — 생기면 아래 시험이 같은 규칙으로 잰다
  });

  /**
   * @scenario component=layout_scan
   * @effects style_prefix_tokens_resolved_once
   */
  it('스타일 토큰 붙은 이름마다 Icon 은 스타일 클래스를 정확히 하나 내고 겹친 클래스가 없으며 글리프·추가 클래스를 지킨다', () => {
    const failures: string[] = [];

    for (const value of uniqueValues) {
      const { container } = render(React.createElement(Icon, { name: value }));
      const className = container.querySelector('i')?.className ?? '';
      const tokens = className.split(/\s+/).filter(Boolean);
      cleanup();

      const styleCount = tokens.filter((token) => SHORT_CLASSES.includes(token)).length;
      const doubled = tokens.filter((token) => DOUBLED_STYLE_CLASS.test(token));
      const expected = expectedClass(value);

      if (styleCount !== 1 || doubled.length > 0 || className !== expected) {
        const where = cases.find((c) => c.value.trim().replace(/\s+/g, ' ') === value)?.file;
        failures.push(`${JSON.stringify(value)} → ${JSON.stringify(className)} (기대 ${JSON.stringify(expected)}, ${where})`);
      }
    }

    expect(failures).toEqual([]);
  });

  /**
   * @scenario component=layout_scan
   * @effects style_prefix_tokens_resolved_once
   */
  it('추가 클래스가 붙은 실제 이름(fas fa-spinner fa-spin)은 추가 클래스를 지키고, ?? 대체값도 같은 규칙으로 그린다', () => {
    const withExtras = uniqueValues.filter((value) => value.trim().split(/\s+/).length > 2);
    expect(withExtras).toContain('fas fa-spinner fa-spin');

    const fallbacks = [...new Set(cases.filter((c) => c.kind === 'fallback').map((c) => c.value))];

    for (const value of [...withExtras, ...fallbacks]) {
      const { container } = render(React.createElement(Icon, { name: value }));
      expect(container.querySelector('i')?.className, value).toBe(expectedClass(value));
      cleanup();
    }
  });
});

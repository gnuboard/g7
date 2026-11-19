/**
 * 산출 검증 — 변환한 합성 확장을 실제 Tailwind(4.1.16)로 빌드해 템플릿·다른 확장을 건드리는 규칙이 없는지 본다
 *
 * `@tailwindcss/vite` 가 하는 일(진입 CSS 컴파일 → `@source` 스캔 → 후보 빌드 → 최적화)을
 * `@tailwindcss/node` 의 `compile`·`optimize` 와 `@tailwindcss/oxide` 의 `Scanner` 로 그대로 밟는다.
 *
 * 없어야 하는 것: preflight(`*,::before` 의 일반 속성, `html{`), 접두 없는 `.flex`, 접두 없는 `--color-*`, `.dark`.
 */
import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import { cpSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import postcss from 'postcss';
import { compile, optimize } from '@tailwindcss/node';
import { Scanner } from '@tailwindcss/oxide';
import { convertExtension } from '../convert-layout-classes.mjs';
import { resolveTypography } from '../lib/tailwind-classifier.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const FIXTURES = path.join(here, 'fixtures');
const REPO = path.resolve(here, '..', '..', '..');

describe('변환 산출 Tailwind 빌드', () => {
  let dir;
  let css;
  let root;

  beforeAll(async () => {
    dir = mkdtempSync(path.join(tmpdir(), 'g7-convert-build-'));
    cpSync(path.join(FIXTURES, 'ext'), dir, { recursive: true });
    await convertExtension({
      extPath: dir,
      repoRoot: FIXTURES,
      adminTemplate: path.join(FIXTURES, 'templates/admin_tpl'),
      userTemplate: path.join(FIXTURES, 'templates/user_tpl'),
      phpGlobs: ['src/**/*.php'],
    });
    const cssDir = path.join(dir, 'resources/css');
    const typography = resolveTypography([REPO, path.join(REPO, 'templates/_bundled/sirsoft-basic')]);
    // 임시 폴더는 저장소 밖이라 `tailwindcss/*.css` 를 저장소 루트 의존성으로 해석한다
    // (실제 확장은 저장소 안에 있어 루트·확장 node_modules 에서 그대로 해석된다).
    const requireFromRepo = createRequire(path.join(REPO, 'package.json'));
    const compiler = await compile(readFileSync(path.join(cssDir, 'main.css'), 'utf8'), {
      base: cssDir,
      onDependency() {},
      customCssResolver: async (id) => (id.startsWith('tailwindcss') ? requireFromRepo.resolve(id) : undefined),
      customJsResolver: async (id) => (id === '@tailwindcss/typography' ? typography : undefined),
    });
    const candidates = new Scanner({ sources: compiler.sources }).scan();
    css = optimize(compiler.build(candidates)).code;
    root = postcss.parse(css);
  });

  afterAll(() => rmSync(dir, { recursive: true, force: true }));

  it('접두 유틸리티·생성 의미 클래스·손 CSS 가 실린다', () => {
    expect(css).toContain('.paydemo\\:flex {');
    expect(css).toContain('.paydemo\\:dark\\:hover\\:bg-gray-100:is([data-g7-color-scheme="dark"] *, [data-g7-color-scheme="dark"])');
    expect(css).toContain('.paydemo\\:prose {');
    expect(css).toContain('.paydemo\\:rounded-full {'); // @source "../js"
    // 시험 디렉터리 문자열은 훑지 않는다(#869 S7) — resources/js/__tests__/probe.test.ts 의 이름이 실리면 안 된다
    expect(css).not.toContain('tracking-widest');
    expect(css).not.toContain('md\\:flex-row');
    expect(css).toContain('.paydemo\\:inline-flex {'); // @source "../../src" (PHP)
    expect(css).toContain('@layer g7-extension');
    expect(css).toContain('.pay-demo-input');
    expect(css).toContain('.pay-demo-tone');
  });

  it('preflight 가 없다(`*`·`::before` 규칙은 --tw-* 초기값뿐, html 규칙 없음)', () => {
    root.walkRules((rule) => {
      const selectors = rule.selectors.map((s) => s.trim());
      if (selectors.some((s) => /^(\*|::?before|::?after|::backdrop|html|body|:host)\b/.test(s) || s === '*')) {
        if (/^:root/.test(rule.selector)) return;
        rule.walkDecls((d) => expect(d.prop.startsWith('--tw-')).toBe(true));
      }
      expect(selectors.some((s) => /^html\b/.test(s))).toBe(false);
    });
    expect(css).not.toMatch(/(^|[\s},])html\s*\{/);
  });

  it('접두 없는 유틸리티·테마 변수가 없다', () => {
    expect(css).not.toMatch(/(^|[\s},])\.flex\s*\{/);
    expect(css).not.toMatch(/--color-[a-z]/);
    expect(css).toMatch(/--paydemo-color-[a-z]/);
  });

  it('층 밖 의미 규칙·아이콘 줄 높이 규칙은 층 밖이고 확장 유틸리티보다 뒤에 온다(같은 특이도에서 이긴다)', () => {
    const order = [];
    root.walkRules((rule) => {
      let layer = null;
      for (let p = rule.parent; p && p.type !== 'root'; p = p.parent) if (p.type === 'atrule' && p.name === 'layer') layer = p.params;
      if (rule.selector === '.paydemo\\:px-4' || rule.selector === '.paydemo\\:text-sm') order.push(`utility:${layer}`);
      if (rule.selector === '.pay-demo-page-header') order.push(`semantic:${layer}:${rule.parent.type === 'atrule' && rule.parent.name === 'media' ? 'media' : 'plain'}`);
      if (rule.selector.startsWith('i:is(')) order.push(`icon:${layer}`);
    });
    expect(order).toEqual([
      'utility:utilities',
      'utility:utilities',
      'semantic:g7-extension:plain',
      'semantic:null:media',
      'icon:null',
    ]);
  });

  it('템플릿 다크 클래스(.dark)에 기대지 않는다', () => {
    expect(css).not.toMatch(/\.dark\b/);
  });
});

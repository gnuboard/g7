/**
 * 번들 레이아웃 전수 — 클릭을 받는 비단추 요소는 손 모양 커서를 갖는다
 *
 * Tailwind v4 는 커서를 주지 않는다. 단추(`<button>`)는 두 템플릿 Button 부품이 기본으로 커서를 주고, 주소가 있는 링크는
 * 브라우저가 준다. 그 밖의 요소(div, span, li, tr, td, img, label, input)에 `<on type="click">` 을 달면 레이아웃이 커서를
 * 줘야 한다 — 유틸리티(`cursor-*`)나 커서를 정의한 의미 클래스로. 화면 전체를 덮는 바깥 눌러 닫기 백드롭(`inset-0`)은
 * 손 모양이 맞지 않아 뺀다. 번들 템플릿·모듈·플러그인의 `.g7.html` 을 모두 훑는다.
 *
 * 조합과 효과 표식은 그것을 재는 시험마다 단다.
 */
import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const REPO = path.resolve(__dirname, '../../../../..');
const NON_BUTTON = new Set(['div', 'span', 'li', 'tr', 'td', 'img', 'label', 'input', 'p', 'section', 'article']);

function walk(dir: string, accept: (p: string) => boolean, out: string[] = []): string[] {
  if (!fs.existsSync(dir)) return out;
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) {
      if (/^(node_modules|dist|vendor|__tests__|tests)$/.test(e.name)) continue;
      walk(p, accept, out);
    } else if (accept(p)) out.push(p);
  }
  return out;
}

const roots = ['templates/_bundled', 'modules/_bundled', 'plugins/_bundled'].map((r) => path.join(REPO, r));

/** 커서를 정의한 의미 클래스와 화면 전체를 덮는 백드롭 의미 클래스 — 두 템플릿과 확장의 손 CSS, 생성 CSS 에서 모은다 */
const cursorClasses = new Set<string>();
const backdropClasses = new Set<string>();
for (const file of roots.flatMap((r) => walk(r, (p) => p.endsWith('.css') && /[\\/](src[\\/]styles|resources[\\/]css)[\\/]/.test(p)))) {
  const css = fs.readFileSync(file, 'utf8');
  const re = /([^{}]+)\{([^{}]*)\}/g;
  let m: RegExpExecArray | null;
  while ((m = re.exec(css))) {
    const names = [...m[1].matchAll(/\.([A-Za-z0-9_-]+)/g)].map((c) => c[1]);
    if (/\bcursor(:|-)/.test(m[2])) names.forEach((n) => cursorClasses.add(n));
    if (/\binset-0\b|inset:\s*0/.test(m[2])) names.forEach((n) => backdropClasses.add(n));
  }
}

type Hit = { file: string; line: number; tag: string; cls: string };

function clickableNonButtons(): Hit[] {
  const hits: Hit[] = [];
  for (const file of roots.flatMap((r) => walk(r, (p) => p.endsWith('.g7.html') && /[\\/](layouts|extensions)[\\/]/.test(p)))) {
    const lines = fs.readFileSync(file, 'utf8').split('\n');
    lines.forEach((l, i) => {
      const m = l.match(/^(\s*)<on\b[^>]*type="click"/);
      if (!m) return;
      const parentIndent = m[1].length - 2;
      let j = i - 1;
      while (j >= 0 && !new RegExp(`^ {${parentIndent}}<([a-z][\\w-]*)`).test(lines[j])) j--;
      if (j < 0) return;
      let open = lines[j];
      for (let k = j + 1; !/>\s*$/.test(open) && k < i; k++) open += ` ${lines[k].trim()}`;
      const tag = open.match(/<([a-z][\w-]*)/)![1];
      if (!NON_BUTTON.has(tag)) return;
      hits.push({ file: path.relative(REPO, file).split(path.sep).join('/'), line: j + 1, tag, cls: (open.match(/\bclass="([^"]*)"/) ?? [, ''])[1] });
    });
  }
  return hits;
}

describe('클릭을 받는 비단추 요소의 커서', () => {
  const hits = clickableNonButtons();

  // @scenario surface=bundled_layouts
  // @effects clickable_layout_element_has_cursor
  it('모집단을 실제로 모은다(하네스 자체 회귀 방지)', () => {
    expect(hits.length).toBeGreaterThan(20);
    expect(cursorClasses.size).toBeGreaterThan(5);
  });

  // @scenario surface=bundled_layouts
  // @effects clickable_layout_element_has_cursor
  it('클릭을 받는 div·span·li·tr·td·img·label·input 은 커서 유틸리티나 커서 의미 클래스를 갖는다(백드롭 제외)', () => {
    const missing = hits.filter(({ cls }) => {
      const tokens = cls.match(/[A-Za-z0-9_:\-[\]/.]+/g) ?? [];
      if (tokens.some((t) => /(^|:)inset-0$/.test(t) || backdropClasses.has(t))) return false;
      if (tokens.some((t) => /(^|:)cursor-[\w-]+$/.test(t))) return false;
      return !tokens.some((t) => cursorClasses.has(t));
    });
    expect(missing.map((h) => `${h.file}:${h.line} <${h.tag}> ${h.cls.slice(0, 80)}`)).toEqual([]);
  });
});

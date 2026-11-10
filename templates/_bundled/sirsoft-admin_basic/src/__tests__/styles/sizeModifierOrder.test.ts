/**
 * @file sizeModifierOrder.test.ts
 * @description 크기 변형 클래스(`.btn-sm` 등)는 빌드 CSS 에서 같은 층의 기본 클래스(`.btn`)보다 뒤에 온다
 *
 * `.btn-sm` 은 `.btn` 의 `px-4 py-2` 를 `px-3 py-1.5` 로 줄이는 변형이다. 둘은 같은 `@layer components` 에 있고
 * 명시도도 같아 원문 순서가 승패를 정한다. 변형이 기본보다 앞에 선언되면 `class="btn btn-sm"` 에서 기본이 이겨
 * 작은 단추가 늘 기본 패딩으로 그려진다(빌드 CSS 에서 `.btn-sm{` 가 `.btn{` 보다 앞에 있었다).
 *
 * 모집단은 빌드 CSS 에서 파생한다 — `.X-{xs|sm|md|lg|xl|2xl}` 와 `.X` 가 같은 층에 있고 같은 속성을 다투는 쌍.
 * 변형을 기본과 한 요소에 함께 쓰지 않는 독립 클래스(예: `stats-value-lg`)는 순서가 화면에 영향이 없어 뺀다 —
 * 그 판정은 레이아웃·컴포넌트 원본에서 둘을 함께 쓴 클래스 문자열이 있는지로 한다.
 *
 * @scenario-manifest tests/scenarios/admin-button-size-modifier.yaml
 */

import fs from 'fs';
import path from 'path';
import { describe, expect, it } from 'vitest';

const TEMPLATE_DIR = path.resolve(__dirname, '../../..');
const DIST_CSS = path.join(TEMPLATE_DIR, 'dist/css/components.css');
const SIZES = ['xs', 'sm', 'md', 'lg', 'xl', '2xl'];

interface Rule {
  name: string;
  pos: number;
  layer: string;
  props: Set<string>;
}

/**
 * 빌드 CSS 의 단일 클래스 규칙(`.name{…}`)을 층 이름과 함께 모은다.
 *
 * @param css 빌드 CSS
 * @returns 규칙 목록
 */
function singleClassRules(css: string): Rule[] {
  const layers: Array<{ name: string; start: number; end: number }> = [];
  const layerRe = /@layer\s+([\w-]+)\s*\{/g;
  let m: RegExpExecArray | null;
  while ((m = layerRe.exec(css))) {
    let depth = 1;
    let i = layerRe.lastIndex;
    for (; i < css.length && depth; i += 1) {
      if (css[i] === '{') depth += 1;
      else if (css[i] === '}') depth -= 1;
    }
    layers.push({ name: m[1], start: m.index, end: i });
  }
  const layerAt = (pos: number): string => {
    let best: { name: string; start: number } | null = null;
    for (const l of layers) if (pos > l.start && pos < l.end && (!best || l.start > best.start)) best = l;
    return best ? best.name : '(none)';
  };
  const rules: Rule[] = [];
  const ruleRe = /(^|[{}])\s*\.((?:[\w-]|\\.)+)\{([^{}]*)\}/g;
  while ((m = ruleRe.exec(css))) {
    const pos = m.index + m[1].length;
    const props = new Set(m[3].split(';').map((d) => d.split(':')[0].trim()).filter(Boolean));
    rules.push({ name: m[2].replace(/\\/g, ''), pos, layer: layerAt(pos), props });
  }
  return rules;
}

/**
 * 원본(레이아웃 `.g7.html`·`.json`, 컴포넌트 `.tsx`)의 클래스 문자열 전부.
 *
 * @returns 클래스 문자열 목록
 */
function sourceClassStrings(): string[] {
  const out: string[] = [];
  const walk = (dir: string): void => {
    for (const name of fs.readdirSync(dir)) {
      const full = path.join(dir, name);
      if (name === 'node_modules' || name === '__tests__') continue;
      if (fs.statSync(full).isDirectory()) walk(full);
      else if (/\.(g7\.html|json|tsx)$/.test(name)) {
        const text = fs.readFileSync(full, 'utf8');
        for (const hit of text.matchAll(/(?:class|className)\s*[=:]\s*["'`]([^"'`]*)/g)) out.push(hit[1]);
      }
    }
  };
  walk(path.join(TEMPLATE_DIR, 'layouts'));
  walk(path.join(TEMPLATE_DIR, 'src/components'));
  return out;
}

/**
 * 크기 변형이 기본보다 앞에 선언돼 기본에 지는 쌍 — 둘을 함께 쓴 원본이 있는 것만.
 *
 * @returns `변형 < 기본 (층, 다투는 속성)` 목록
 */
function losingSizeModifiers(): string[] {
  const rules = singleClassRules(fs.readFileSync(DIST_CSS, 'utf8'));
  const byName = new Map<string, Rule[]>();
  for (const r of rules) byName.set(r.name, [...(byName.get(r.name) ?? []), r]);
  const strings = sourceClassStrings();
  const usedTogether = (a: string, b: string): boolean =>
    strings.some((s) => {
      const words = s.split(/\s+/);
      return words.includes(a) && words.includes(b);
    });
  const sizeRe = new RegExp(`^(.+)-(${SIZES.join('|')})$`);
  const out: string[] = [];
  for (const [name, list] of byName) {
    const match = sizeRe.exec(name);
    const bases = match ? byName.get(match[1]) : undefined;
    if (!match || !bases) continue;
    for (const size of list) {
      for (const base of bases) {
        if (size.layer !== base.layer || size.pos > base.pos) continue;
        const shared = [...size.props].filter((p) => base.props.has(p));
        if (shared.length && usedTogether(name, match[1])) {
          out.push(`.${name} < .${match[1]} (${size.layer}: ${shared.join(',')})`);
        }
      }
    }
  }
  return out;
}

describe('크기 변형 클래스 선언 순서', () => {
  // @scenario bundle=admin_template
  // @effects size_modifier_overrides_base_padding
  it('빌드 CSS 에 btn 과 btn-sm 이 모두 있다(파서 확인)', () => {
    const names = singleClassRules(fs.readFileSync(DIST_CSS, 'utf8')).map((r) => r.name);
    expect(names).toEqual(expect.arrayContaining(['btn', 'btn-sm']));
  });

  // @scenario bundle=admin_template
  // @effects size_modifier_overrides_base_padding
  it('함께 쓰는 크기 변형은 같은 층의 기본 클래스보다 뒤에 선언된다', () => {
    expect(losingSizeModifiers()).toEqual([]);
  });
});

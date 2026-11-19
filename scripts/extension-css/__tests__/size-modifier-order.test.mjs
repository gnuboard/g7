/**
 * 번들 확장 빌드 CSS 의 크기 변형 순서 — `{ns}-btn-sm` 은 `{ns}-btn` 보다 뒤에 온다
 *
 * 확장의 의미 클래스는 관리자 템플릿 빌드 산출을 복사한 것이라(`g7-extension.generated.css`) 템플릿에서
 * 크기 변형이 기본보다 앞에 선언되면 확장 복사본도 같은 순서를 물려받는다. 둘은 같은 `g7-extension` 층이고
 * 명시도도 같아 원문 순서가 승패를 정한다 — 변형이 앞이면 `{ns}-btn {ns}-btn-sm` 단추가 기본 패딩으로 그려진다.
 *
 * 모집단은 번들 확장의 `dist/css/*.css` 에서 파생한다. 변형과 기본을 한 요소에 함께 쓴 레이아웃이 그 확장에
 * 있을 때만 결함으로 센다(함께 쓰지 않으면 순서가 화면에 영향이 없다).
 *
 * @scenario-manifest templates/_bundled/sirsoft-admin_basic/tests/scenarios/admin-button-size-modifier.yaml
 */
import { describe, it, expect } from 'vitest';
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(here, '..', '..', '..');
const SIZES = ['xs', 'sm', 'md', 'lg', 'xl', '2xl'];

/**
 * 빌드 CSS 의 단일 클래스 규칙과 그 층.
 *
 * @param {string} css 빌드 CSS
 * @returns {{name: string, pos: number, layer: string, props: Set<string>}[]} 규칙
 */
function singleClassRules(css) {
  const layers = [];
  const layerRe = /@layer\s+([\w-]+)\s*\{/g;
  let m;
  while ((m = layerRe.exec(css))) {
    let depth = 1;
    let i = layerRe.lastIndex;
    for (; i < css.length && depth; i += 1) {
      if (css[i] === '{') depth += 1;
      else if (css[i] === '}') depth -= 1;
    }
    layers.push({ name: m[1], start: m.index, end: i });
  }
  const layerAt = (pos) => {
    let best = null;
    for (const l of layers) if (pos > l.start && pos < l.end && (!best || l.start > best.start)) best = l;
    return best ? best.name : '(none)';
  };
  const rules = [];
  const ruleRe = /(^|[{}])\s*\.((?:[\w-]|\\.)+)\{([^{}]*)\}/g;
  while ((m = ruleRe.exec(css))) {
    const pos = m.index + m[1].length;
    const props = new Set(m[3].split(';').map((d) => d.split(':')[0].trim()).filter(Boolean));
    rules.push({ name: m[2].replace(/\\/g, ''), pos, layer: layerAt(pos), props });
  }
  return rules;
}

/**
 * 확장 레이아웃의 클래스 문자열.
 *
 * @param {string} dir 확장 경로
 * @returns {string[]} 클래스 문자열
 */
function classStrings(dir) {
  const out = [];
  const walk = (d) => {
    if (!existsSync(d)) return;
    for (const name of readdirSync(d)) {
      const full = path.join(d, name);
      if (name === 'node_modules' || name === '__tests__') continue;
      if (statSync(full).isDirectory()) walk(full);
      else if (/\.(g7\.html|json|tsx)$/.test(name)) {
        for (const hit of readFileSync(full, 'utf8').matchAll(/(?:class|className)\s*[=:]\s*["'`]([^"'`]*)/g)) out.push(hit[1]);
      } else if (name.endsWith('.php')) {
        // 리스너가 레이아웃에 끼워 넣는 노드(`'className' => '…'`)도 같은 화면에 그려진다(#869 결제 3종).
        for (const hit of readFileSync(full, 'utf8').matchAll(/["'](?:class|className|\w+ClassName)["']\s*=>\s*["']([^"']*)/g)) out.push(hit[1]);
      }
    }
  };
  walk(path.join(dir, 'resources'));
  walk(path.join(dir, 'src'));
  return out;
}

/** 번들 확장(모듈·플러그인)의 빌드 CSS */
function bundledExtensionCss() {
  const out = [];
  for (const kind of ['modules', 'plugins']) {
    const root = path.join(REPO, kind, '_bundled');
    for (const id of readdirSync(root)) {
      const cssDir = path.join(root, id, 'dist', 'css');
      if (!existsSync(cssDir)) continue;
      for (const f of readdirSync(cssDir)) if (f.endsWith('.css')) out.push({ id, dir: path.join(root, id), file: path.join(cssDir, f) });
    }
  }
  return out;
}

describe('번들 확장 빌드 CSS — 크기 변형 선언 순서', () => {
  // @scenario bundle=extension
  // @effects size_modifier_overrides_base_padding
  it('확장 빌드 CSS 를 하나 이상 찾는다(모집단 확인)', () => {
    expect(bundledExtensionCss().length).toBeGreaterThan(0);
  });

  // @scenario bundle=extension
  // @effects size_modifier_overrides_base_padding
  it('함께 쓰는 크기 변형은 같은 층의 기본 클래스보다 뒤에 선언된다', () => {
    const sizeRe = new RegExp(`^(.+)-(${SIZES.join('|')})$`);
    const losing = [];
    for (const { id, dir, file } of bundledExtensionCss()) {
      const rules = singleClassRules(readFileSync(file, 'utf8'));
      const byName = new Map();
      for (const r of rules) byName.set(r.name, [...(byName.get(r.name) ?? []), r]);
      const strings = classStrings(dir).map((s) => s.split(/\s+/));
      for (const [name, list] of byName) {
        const match = sizeRe.exec(name);
        const bases = match ? byName.get(match[1]) : undefined;
        if (!match || !bases) continue;
        const together = strings.some((w) => w.includes(name) && w.includes(match[1]));
        if (!together) continue;
        for (const size of list) {
          for (const base of bases) {
            if (size.layer !== base.layer || size.pos > base.pos) continue;
            const shared = [...size.props].filter((p) => base.props.has(p));
            if (shared.length) losing.push(`${id}: .${name} < .${match[1]} (${size.layer}: ${shared.join(',')})`);
          }
        }
      }
    }
    expect(losing).toEqual([]);
  });
});

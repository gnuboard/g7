/**
 * 폐지 예정 반응형 접두 클래스 — 공개 출시본 빌드 CSS 에 있던 집합이 지금 빌드 CSS 에 모두 남는다
 *
 * 번들 레이아웃·부품이 Tailwind 반응형 접두를 엔진 `responsive` 로 바꾸자, 레이아웃 스캔으로 들어오던 `md:w-96` 같은
 * 클래스가 빌드 CSS 에서 빠졌다. 이미 설치한 사이트가 편집기로 고쳐 저장한 레이아웃과 사이트 자체 확장은 그 클래스를 계속
 * 쓸 수 있어 이번 버전에는 빌드에 남기고 다음 출시 이후 뺀다. 기준 집합은 마지막 공개 출시본의 빌드 CSS 에 실제로 있던
 * 이름이다(`deprecated-responsive-classes.mjs baseline` 으로 뽑아 픽스처로 고정). 확장 출시본 CSS 의 접두 없는 반응형
 * 클래스(이커머스 `md:w-24` 등)는 지금 접두 빌드가 만들 수 없어 관리자 템플릿 블록에 더한다. 출시된 적 없는 확장과 접두
 * 이름은 쓸 수 있었던 사이트가 없어 대상이 아니다. 다음 출시에서 보존 블록을 지울 때 이 시험과 픽스처도 함께 지운다.
 *
 * @scenario-manifest templates/_bundled/sirsoft-admin_basic/tests/scenarios/deprecated-responsive-classes-kept.yaml
 */
import { describe, it, expect } from 'vitest';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  classNamesInCss,
  hasWidthVariant,
  responsiveClassesInCss,
  splitVariants,
  unescapeIdent,
} from '../lib/responsive-classes.mjs';
import { FIXTURE, MARKER_END, MARKER_START, inputCssFor, upsertBlock } from '../deprecated-responsive-classes.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(here, '..', '..', '..');
const { release, sets, skipped } = JSON.parse(readFileSync(FIXTURE, 'utf8'));
const entries = Object.entries(sets).map(([distRel, { ref, classes }]) => [distRel, ref, classes]);
/** 보존 블록에 실리는 이름 전체 — 반응형 접두 기준 집합 + 출시본에 있다가 빠진 일반 유틸리티 */
const blockEntries = Object.entries(sets).map(([distRel, { ref, classes, utilities = [] }]) => [distRel, ref, [...classes, ...utilities]]);
const utilityEntries = Object.entries(sets)
  .filter(([, { utilities }]) => (utilities ?? []).length > 0)
  .map(([distRel, { ref, utilities }]) => [distRel, ref, utilities]);

describe('반응형 접두 클래스 수집기', () => {
  // @scenario case=drc_c01
  // @effects responsive_class_collector_reads_width_variants
  it('CSS 이스케이프를 풀고 폭 변형만 센다', () => {
    expect(unescapeIdent('md\\:w-96')).toBe('md:w-96');
    expect(unescapeIdent('\\32 xl\\:flex')).toBe('2xl:flex');
    expect(unescapeIdent('sm\\:w-1\\/2')).toBe('sm:w-1/2');
    expect(splitVariants('sm:grid-cols-[120px_1fr]')).toEqual(['sm', 'grid-cols-[120px_1fr]']);
    expect(splitVariants('[&:hover]:md:flex')).toEqual(['[&:hover]', 'md', 'flex']);
    for (const name of ['sm:flex', 'md:w-96', 'lg:col-span-2', 'xl:p-8', '2xl:grid', 'max-md:hidden', 'min-[900px]:flex',
      'max-[480px]:p-2', 'ecommerce:md:w-20', 'dark:md:bg-gray-800']) {
      expect(hasWidthVariant(name), name).toBe(true);
    }
    for (const name of ['flex', 'dark:bg-gray-800', 'hover:underline', 'min-w-[200px]', 'max-w-md', '@md:flex', 'ecommerce:flex',
      'md']) {
      expect(hasWidthVariant(name), name).toBe(false);
    }
    const css = '.md\\:w-96{@media (width>=48rem){width:24rem}}.\\32 xl\\:flex{@media (width>=96rem){display:flex}}.flex{display:flex}';
    expect(responsiveClassesInCss(css)).toEqual(['2xl:flex', 'md:w-96']);
  });

  // @scenario case=drc_c02
  // @effects deprecated_responsive_block_is_idempotent
  it('보존 블록은 표식 사이만 바꾸고 다시 넣어도 같다', () => {
    const tpl = '@source "a";\n@source inline("x");\n\n.a { color: red; }\n';
    const once = upsertBlock(tpl, `${MARKER_START} */\n@source inline("md:flex");\n${MARKER_END}`, 'template');
    expect(once.indexOf(MARKER_START)).toBeGreaterThan(once.indexOf('@source inline("x");'));
    expect(once.indexOf(MARKER_START)).toBeLessThan(once.indexOf('.a {'));
    const twice = upsertBlock(once, `${MARKER_START} */\n@source inline("md:flex");\n${MARKER_END}`, 'template');
    expect(twice).toBe(once);
    const ext = '/* g7-extension-css:start */\n@import "x";\n/* g7-extension-css:end */\n\n.ns-a {}\n';
    const extOnce = upsertBlock(ext, `${MARKER_START} */\n${MARKER_END}`, 'extension');
    expect(extOnce.indexOf(MARKER_START)).toBeGreaterThan(extOnce.indexOf('g7-extension-css:end'));
  });
});

describe('전환 전 반응형 접두 클래스가 지금 빌드 CSS 에 남는다', () => {
  it('두 템플릿은 공개 출시본 빌드 CSS 를 기준으로 삼는다', () => {
    for (const id of ['sirsoft-admin_basic', 'sirsoft-basic']) {
      const set = sets[`templates/_bundled/${id}/dist/css/components.css`];
      expect(set?.ref, id).toBe(release);
      expect(set?.classes.length ?? 0, id).toBeGreaterThan(0);
    }
  });

  // @scenario case=drc_c05
  // @effects deprecated_responsive_block_only_for_released
  it('출시본 빌드 CSS 에 있던 이름만 남긴다 — 출시된 적 없는 확장·접두 이름에는 보존 블록이 없다', () => {
    for (const [distRel, { ref }] of Object.entries(sets)) expect(ref, distRel).toBe(release);
    const unreleased = Object.entries(skipped).filter(([, why]) => why.startsWith('공개 출시본에 없는')).map(([f]) => f);
    expect(unreleased.length).toBeGreaterThan(0);
    const targeted = new Set(Object.keys(sets).map(inputCssFor));
    for (const kind of ['modules', 'plugins']) {
      const dir = path.join(REPO, kind, '_bundled');
      for (const id of readdirSync(dir)) {
        const main = `${kind}/_bundled/${id}/resources/css/main.css`;
        if (targeted.has(main) || !existsSync(path.join(REPO, main))) continue;
        expect(readFileSync(path.join(REPO, main), 'utf8').includes(MARKER_START), main).toBe(false);
      }
    }
  });

  // @scenario case=drc_c06
  // @effects deprecated_responsive_admin_block_includes_extension_hand_css
  it('관리자 템플릿 기준 집합에 출시본 확장 손 CSS 의 접두 없는 반응형 클래스가 들어 있다', () => {
    const admin = sets['templates/_bundled/sirsoft-admin_basic/dist/css/components.css'];
    const fromEcommerce = admin?.merged?.['modules/_bundled/sirsoft-ecommerce/dist/css/module.css'] ?? [];
    expect(fromEcommerce).toEqual(['md:w-24', 'md:w-28', 'md:w-36']);
    for (const list of Object.values(admin?.merged ?? {})) {
      for (const c of list) expect(admin.classes, c).toContain(c);
    }
  });

  // @scenario case=drc_c03
  // @effects deprecated_responsive_classes_kept_in_build_css
  it.each(entries)('%s (기준 %s)', (distRel, _ref, baseline) => {
    const current = classNamesInCss(readFileSync(path.join(REPO, distRel), 'utf8'));
    const missing = baseline.filter((c) => !current.has(c));
    expect(missing, `빌드 CSS 에서 빠진 반응형 접두 클래스 ${missing.length}개`).toEqual([]);
  });

  // @scenario case=drc_c04
  // @effects deprecated_responsive_block_matches_baseline
  it.each(blockEntries)('입력 CSS 보존 블록이 기준 집합과 같다 — %s (기준 %s)', (distRel, _ref, baseline) => {
    const css = readFileSync(path.join(REPO, inputCssFor(distRel)), 'utf8');
    const s = css.indexOf(MARKER_START);
    const e = css.indexOf(MARKER_END, s);
    expect(s, '보존 블록 없음').toBeGreaterThanOrEqual(0);
    const m = /@source\s+inline\("([^"]*)"\)/.exec(css.slice(s, e));
    expect(m?.[1].split(/\s+/).filter(Boolean)).toEqual(baseline);
  });

  /**
   * 출시본 빌드 CSS 에 있던 일반 유틸리티(반응형 접두 없음) 중 번들 레이아웃이 더 쓰지 않아 빠진 이름도 남는다.
   * 사이트가 직접 쓴 레이아웃과 CSS 가 `dark:bg-sky-700`·`grid-cols-[120px_1fr]` 같은 이름을 쓸 수 있었다.
   * 기준 수는 2026-11-07 대조 값(관리자 42, 사용자 34)이다 — 줄면 누가 픽스처를 깎은 것이다.
   */
  // @scenario case=drc_c07
  // @effects deprecated_utility_classes_kept_in_build_css
  it.each(utilityEntries)('출시본 일반 유틸리티가 지금 빌드 CSS 에 남는다 — %s (기준 %s)', (distRel, _ref, utilities) => {
    const floor = { 'templates/_bundled/sirsoft-admin_basic/dist/css/components.css': 42, 'templates/_bundled/sirsoft-basic/dist/css/components.css': 34 };
    expect(utilities.length, distRel).toBeGreaterThanOrEqual(floor[distRel] ?? 1);
    for (const c of utilities) expect(hasWidthVariant(c), c).toBe(false);
    const current = classNamesInCss(readFileSync(path.join(REPO, distRel), 'utf8'));
    const missing = utilities.filter((c) => !current.has(c));
    expect(missing, `빌드 CSS 에서 빠진 출시본 일반 유틸리티 ${missing.length}개`).toEqual([]);
  });

  // @scenario case=drc_c07
  // @effects deprecated_utility_classes_kept_in_build_css
  it('두 템플릿 모두 일반 유틸리티 기준을 갖는다', () => {
    expect(utilityEntries.map(([f]) => f).sort()).toEqual([
      'templates/_bundled/sirsoft-admin_basic/dist/css/components.css',
      'templates/_bundled/sirsoft-basic/dist/css/components.css',
    ]);
  });
});

/**
 * 확장 빌드 CSS 에는 화면 폭 접두 클래스가 하나도 없다.
 *
 * 번들 레이아웃은 엔진 `responsive` 로만 폭을 가르고, 출시된 적 있는 확장 접두 이름도 없어 보존 블록 대상이 아니다.
 * 그런데 빌드 도구가 `resources/js/**\/__tests__` 의 시험 문자열까지 훑어 `ecommerce:md:flex-row` 같은 이름이
 * 빌드 CSS 에 실렸다. 진입 CSS 생성 블록이 시험 디렉터리를 빼므로(`@source not "../js/**\/__tests__"`) 0 이어야 한다.
 */
describe('확장 빌드 CSS 에 화면 폭 접두 클래스가 없다', () => {
  const extDists = [];
  for (const kind of ['modules', 'plugins']) {
    const dir = path.join(REPO, kind, '_bundled');
    for (const id of readdirSync(dir)) {
      const cssDir = path.join(dir, id, 'dist', 'css');
      if (!existsSync(cssDir)) continue;
      for (const f of readdirSync(cssDir)) if (f.endsWith('.css')) extDists.push(`${kind}/_bundled/${id}/dist/css/${f}`);
    }
  }

  // @scenario case=drc_c08
  // @effects extension_build_css_has_no_width_variant
  it.each(extDists.filter((f) => !sets[f]))('%s', (distRel) => {
    const found = responsiveClassesInCss(readFileSync(path.join(REPO, distRel), 'utf8'));
    expect(found, `빌드 CSS 에 화면 폭 접두 클래스 ${found.length}개`).toEqual([]);
  });
});

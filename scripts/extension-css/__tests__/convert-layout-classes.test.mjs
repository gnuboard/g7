/**
 * 변환기 통합 테스트 — 합성 확장·합성 템플릿 픽스처를 임시 폴더에 복사해 변환한다
 *
 * 검증: 분류별 변환, 관리자/사용자 이름 나누기, JSON 형식 보존, 멱등, 의미 클래스 복사
 * (선택자 개명·다크 규격·변수 풀어쓰기·keyframes·@property), 진입 블록 생성·갱신, PHP, namespace 오류.
 */
import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import { cpSync, mkdtempSync, readFileSync, rmSync, writeFileSync, existsSync, readdirSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { convertExtension, upsertEntryBlock, lettersOf } from '../convert-layout-classes.mjs';
import { DARK_SUFFIX } from '../lib/tailwind-classifier.mjs';
import { applyDarkSpec, loadTemplateIndex, UNLAYERED_MARK } from '../lib/template-css.mjs';
import postcss from 'postcss';

const here = path.dirname(fileURLToPath(import.meta.url));
const FIXTURES = path.join(here, 'fixtures');
const A7 = ':is([data-g7-color-scheme="dark"] *, [data-g7-color-scheme="dark"]):not(:where([data-g7-color-scheme="dark"] [data-g7-color-scheme="light"] *, [data-g7-color-scheme="dark"] [data-g7-color-scheme="light"]))';

/**
 * 픽스처 확장을 임시 폴더로 복사한다.
 *
 * @returns {string} 복사본 경로
 */
function copyFixture() {
  const dir = mkdtempSync(path.join(tmpdir(), 'g7-convert-'));
  cpSync(path.join(FIXTURES, 'ext'), dir, { recursive: true });
  return dir;
}

/**
 * 변환 옵션.
 *
 * @param {string} extPath 확장 경로
 * @param {Record<string, any>} [extra] 추가 옵션
 * @returns {Record<string, any>} 옵션
 */
const options = (extPath, extra = {}) => ({
  extPath,
  repoRoot: FIXTURES,
  adminTemplate: path.join(FIXTURES, 'templates/admin_tpl'),
  userTemplate: path.join(FIXTURES, 'templates/user_tpl'),
  phpGlobs: ['src/**/*.php'],
  ...extra,
});

/**
 * 폴더 아래 모든 파일 내용.
 *
 * @param {string} dir 폴더
 * @returns {Record<string, string>} 상대 경로 → 내용
 */
function snapshot(dir) {
  const out = {};
  const visit = (d) => readdirSync(d).forEach((name) => {
    const full = path.join(d, name);
    if (statSync(full).isDirectory()) visit(full);
    else out[path.relative(dir, full).replace(/\\/g, '/')] = readFileSync(full, 'utf8');
  });
  visit(dir);
  return out;
}

const read = (dir, rel) => readFileSync(path.join(dir, rel), 'utf8');

describe('convert-layout-classes', () => {
  let ext;
  let report;
  const temps = [];

  beforeAll(async () => {
    ext = copyFixture();
    temps.push(ext);
    ({ report } = await convertExtension(options(ext)));
  });

  afterAll(() => temps.forEach((d) => rmSync(d, { recursive: true, force: true })));

  it('namespace 를 manifest 에서 읽고 letters 는 a-z 만 남긴다', () => {
    expect(report.namespace).toBe('pay-demo');
    expect(report.letters).toBe('paydemo');
    expect(report.namespaceSource).toBe('manifest');
    expect(lettersOf('verification-kginicis')).toBe('verificationkginicis');
  });

  it('분류별로 바꾼다(.g7.html)', () => {
    const html = read(ext, 'resources/layouts/admin/page.g7.html');
    // Tailwind 유틸리티(변형·! 중요·음수·임의값 포함)와 표지
    expect(html).toContain('class="paydemo:flex paydemo:items-center paydemo:gap-2 paydemo:dark:hover:bg-gray-100 paydemo:!mt-2 paydemo:-mt-1 paydemo:group paydemo:group/item"');
    expect(html).toContain("'paydemo:w-[200px] paydemo:md:w-1/2'");
    // 의미 클래스(관리자 정의), FontAwesome·미분류는 그대로
    expect(html).toContain('class="pay-demo-hover-lift paydemo:group-hover:underline fa fa-check readonly paydemo:sr-only pay-demo-spinner"');
    // 자기 것은 유지, 조립형은 그대로
    expect(html).toContain('class="bg-{{row.color}}-500 {{\'text-\' + row.tone}} pay-demo-tone paydemo:flex"');
    // class-map 과 *ClassName
    expect(html).toContain('base="paydemo:px-2" default="paydemo:text-gray-500"');
    expect(html).toContain('<variant when="on">paydemo:bg-green-100 pay-demo-input</variant>');
    expect(html).toContain('cellClassName="paydemo:px-4 paydemo:text-sm"');
    // 주석은 건드리지 않는다
    expect(html).toContain('<!-- 합성 관리자 화면. 이 주석 안의 class="flex" 는 바꾸지 않는다 -->');
  });

  it('표현식의 비교 피연산자·객체 키·인자·대괄호 접근은 바꾸지 않는다', () => {
    const html = read(ext, 'resources/layouts/admin/page.g7.html');
    expect(html).toContain("{{row.status === 'active' ? 'paydemo:bg-blue-100 paydemo:text-blue-800' : 'pay-demo-input'}}");
    expect(html).toContain("{{ {'pending': 'paydemo:bg-yellow-100', 'done': 'pay-demo-section-heading-md'}[row.status] }}");
    expect(html).toContain("{{row.tags.includes('hidden') || row.map['block'] ? 'paydemo:hidden' : 'paydemo:block'}}");
    expect(html).toContain("{{(row.mode ?? 'standard') === 'standard' ? 'paydemo:prose' : ");
  });

  it('같은 토큰을 두 쪽이 다르게 정의하면 관리자 {ns}-x, 사용자 {ns}-user-x 로 나눈다', () => {
    expect(report.split).toEqual([{ token: 'input', admin: 'pay-demo-input', user: 'pay-demo-user-input' }]);
    expect(read(ext, 'resources/layouts/user/view.g7.html')).toContain('class="pay-demo-user-input pay-demo-badge paydemo:md:flex"');
    // 조각: target_layout(관리자) / extension_point(사용자), .g7.html 은 짝 .json 으로 판정
    expect(read(ext, 'resources/extensions/admin_frag.json')).toContain('"className": "pay-demo-input pay-demo-card"');
    expect(read(ext, 'resources/extensions/user_frag.g7.html')).toContain('class="pay-demo-user-input pay-demo-badge"');
    expect(report.files['resources/extensions/user_frag.g7.html'].contextBasis).toBe('extension_point:user_slot');
    expect(report.files['resources/extensions/admin_frag.json'].contextBasis).toBe('target_layout:admin_screen');
  });

  it('.g7.html 과 .json 에 같은 매핑을 적용한다', () => {
    const html = read(ext, 'resources/layouts/admin/page.g7.html');
    const json = JSON.parse(read(ext, 'resources/layouts/admin/page.json'));
    const htmlClasses = [...html.replace(/<!--[\s\S]*?-->/g, '').matchAll(/ class="([^"]*)"/g)].map((m) => m[1]);
    const jsonClasses = [];
    const visit = (n) => {
      if (Array.isArray(n)) n.forEach(visit);
      else if (n && typeof n === 'object') Object.entries(n).forEach(([k, v]) => { if (k === 'className') jsonClasses.push(v); else visit(v); });
    };
    visit(json);
    expect(jsonClasses).toEqual(htmlClasses);
  });

  it('JSON 은 형식(들여쓰기·키 순서·다른 값)을 보존한다', () => {
    const before = readFileSync(path.join(FIXTURES, 'ext/resources/layouts/admin/page.json'), 'utf8').split('\n');
    const after = read(ext, 'resources/layouts/admin/page.json').split('\n');
    expect(after.length).toBe(before.length);
    after.forEach((line, i) => {
      if (line === before[i]) return;
      // 바뀐 줄은 클래스 값만 다르다
      const strip = (l) => l.replace(/"(className|cellClassName|base|default|on)": "(?:[^"\\]|\\.)*"/g, '"$1": ""');
      expect(strip(line)).toBe(strip(before[i]));
    });
    expect(after.join('\n')).toContain('"label": "flex"');
  });

  it('PHP 문자열 값도 바꾸고, 보간 문자열은 수동 확인으로 남긴다', () => {
    const php = read(ext, 'src/Listeners/AdminBadgeListener.php');
    expect(php).toContain("'className' => 'paydemo:inline-flex paydemo:px-2 pay-demo-input'");
    expect(php).toContain("'iconClassName' => \"w-4 {$x}\"");
    expect(php).toContain("'label' => 'flex'");
    expect(report.manual.some((m) => m.kind === 'php-interpolation')).toBe(true);
  });

  it('겹침 토큰: 복사 가능한 템플릿 규칙이 있으면 두 이름을 주고 정의를 복사, 없으면 Tailwind 형만', () => {
    const html = read(ext, 'resources/layouts/admin/page.g7.html');
    // table: 관리자 템플릿에 .table·.table th 규칙(복사 가능) → 두 이름
    // hidden: 관리자 템플릿 규칙이 `.panel .dark .hidden` 뿐(복사 불가) → Tailwind 형만
    // sr-only: 사용자 템플릿에만 정의 — 관리자 화면에서는 Tailwind 형만
    expect(html).toContain('<table class="paydemo:table pay-demo-table paydemo:hidden"/>');
    expect(html).toContain('paydemo:sr-only pay-demo-spinner');
    expect(read(ext, 'resources/layouts/admin/page.json')).toContain('"className": "paydemo:table pay-demo-table paydemo:hidden"');
    const byToken = Object.fromEntries(report.overlap.map((x) => [x.token, x]));
    expect(byToken.table.decision).toBe('both');
    expect(byToken.table.names).toEqual(['paydemo:table pay-demo-table']);
    expect(byToken.table.evidence.admin_tpl).toMatchObject({ copyable: 4, sample: ['.table', '.table th', '.dark .table th', '.table'] });
    expect(byToken.hidden.decision).toBe('tailwind-only');
    expect(byToken.hidden.evidence.admin_tpl).toMatchObject({ copyable: 0, notCopyable: ['.panel .dark .hidden'] });
    expect(byToken['sr-only'].decision).toBe('tailwind-only');
    expect(report.summary.overlapBoth).toBe(1);
    const css = read(ext, 'resources/css/g7-extension.generated.css');
    expect(css).toContain('.pay-demo-table {\n    width: 100%;\n  }');
    expect(css).toContain('.pay-demo-table th {\n    padding-inline: calc(.25rem*2);\n  }');
    expect(css).toContain(`.pay-demo-table th${A7} {`);
    // 템플릿이 생성한 같은 이름 유틸리티 규칙도 복사 가능한 규칙이다(두 이름이 함께 붙으므로 무해)
    expect(css).toContain('.pay-demo-table {\n    display: table;\n  }');
    expect(css).not.toContain('hidden');
    expect(report.copiedClasses.admin).toContain('table');
  });

  it('템플릿에서 층 밖이던 의미 규칙은 생성 파일에서도 @layer g7-extension 밖에 둔다', () => {
    const css = read(ext, 'resources/css/g7-extension.generated.css');
    const placement = {};
    postcss.parse(css).walkRules((rule) => {
      if (!rule.selector.includes('.pay-demo-page-header')) return;
      let p = rule.parent;
      let layered = false;
      while (p && p.type !== 'root') { if (p.type === 'atrule' && p.name === 'layer') layered = true; p = p.parent; }
      const media = rule.parent.type === 'atrule' && rule.parent.name === 'media' ? `@media ${rule.parent.params} ` : '';
      placement[`${media}${rule.selector}`] = layered ? 'g7-extension' : 'outside';
    });
    // 원본 @layer components 안 → 층 안, 원본 층 밖 @media → 층 밖(확장 유틸리티 뒤에서 px-4 를 이긴다)
    expect(placement).toEqual({
      '.pay-demo-page-header': 'g7-extension',
      '@media (min-width:768px) .pay-demo-page-header': 'outside',
    });
    expect(css.indexOf(UNLAYERED_MARK)).toBeGreaterThan(css.indexOf('@layer g7-extension {'));
    expect(report.unlayeredRules).toBe(1);
    expect(read(ext, 'resources/layouts/admin/page.g7.html')).toContain('<header class="pay-demo-page-header paydemo:px-4"/>');
  });

  it('<icon> 에 단 글자 크기 유틸리티에는 층 밖 line-height:1 을 함께 낸다', () => {
    const html = read(ext, 'resources/layouts/admin/page.g7.html');
    expect(html).toContain('<icon name="fa-solid fa-check" class="paydemo:text-sm paydemo:md:text-xl paydemo:text-gray-500"/>');
    // 색 유틸리티(text-gray-500)·임의값 글자 크기(text-[2rem], 줄 높이를 정하지 않음)와
    // 아이콘이 아닌 요소의 text-sm(cellClassName)은 넣지 않고, leading-* 는 넣는다.
    // JSON 은 "name" 키가 "props" 뒤에 와도 Icon 으로 판별한다.
    expect(report.iconLineHeight).toEqual(['paydemo:leading-5', 'paydemo:md:text-xl', 'paydemo:text-sm', 'paydemo:text-xs']);
    const css = read(ext, 'resources/css/g7-extension.generated.css');
    expect(css).toContain('i:is(.paydemo\\:leading-5, .paydemo\\:md\\:text-xl, .paydemo\\:text-sm, .paydemo\\:text-xs) {\n  line-height: 1;\n}');
    const iconRule = [];
    postcss.parse(css).walkRules(/^i:is/, (r) => iconRule.push(r.parent.type));
    expect(iconRule).toEqual(['root']);
  });

  it('--php-context 로 PHP 사용처를 정한다', async () => {
    const dir = copyFixture();
    temps.push(dir);
    const { report: r } = await convertExtension(options(dir, { phpContext: 'user' }));
    expect(read(dir, 'src/Listeners/AdminBadgeListener.php')).toContain("'className' => 'paydemo:inline-flex paydemo:px-2 pay-demo-user-input'");
    expect(r.files['src/Listeners/AdminBadgeListener.php'].contextBasis).toBe('argument');
  });

  it('보고서에 FontAwesome·미분류·수동 확인·겹침·제외 리터럴을 올린다', () => {
    expect(report.fontawesome.map((x) => x.token).sort()).toEqual(['fa', 'fa-check']);
    expect(report.unclassified.map((x) => x.token)).toEqual(['readonly']);
    expect(report.manual.filter((m) => m.kind === 'assembled').map((m) => m.text)).toEqual(['bg-', '-500', 'text-', 'bg-', '-500', 'text-']);
    expect(report.overlap.map((x) => x.token)).toEqual(['hidden', 'sr-only', 'table']);
    expect(report.summary.excludedLiterals).toMatchObject({ compare: 6, 'object-key': 4, argument: 2, index: 2 });
    expect(report.summary.tokens.fontawesome).toBe(4);
    expect(report.typography).toBe(true);
  });

  it('의미 클래스 정의를 복사한다 — 선택자 개명·폐포·다크 규격·변수 풀어쓰기', () => {
    const css = read(ext, 'resources/css/g7-extension.generated.css');
    expect(css).toMatch(/^\/\*\n \* 생성 파일/);
    expect(css).toContain('@layer g7-extension {');
    expect(css).toContain('.pay-demo-input {\n    height: calc(.25rem*9);\n    background-color: oklch(96.7% .003 264.542);');
    expect(css).toContain(`.pay-demo-input${A7} {`);
    expect(css).toContain(`.pay-demo-input${A7}::placeholder {`);
    // 폐포: 선택자에 함께 걸린 의미 클래스도 개명·복사
    expect(css).toContain('.pay-demo-card .pay-demo-card-title {');
    // 표지는 확장 접두로, 템플릿 외부 클래스 선택자는 버리고 보고
    expect(css).toContain('.paydemo\\:group:hover .pay-demo-hover-lift {');
    expect(css).not.toContain('yarl__');
    expect(report.selectorIssues.map((x) => x.reason).sort()).toEqual(['foreign-class', 'tailwind-in-selector']);
    // @media 보존, 사용자 정의는 사용자 이름으로
    expect(css).toContain('@media (min-width:768px) {\n    .pay-demo-card {');
    expect(css).toContain('.pay-demo-user-input {\n    border-radius: .5rem;');
    expect(css).toContain(`.pay-demo-badge${A7} {`);
    // 테마 변수는 풀고 --tw-* 는 남긴다
    expect(css).not.toMatch(/var\(--(color|spacing|radius|text|font|animate)/);
    expect(css).toContain('--tw-shadow: 0 1px 3px 0 var(--tw-shadow-color,#0000001a);');
    expect(css).toContain('font-weight: 600;');
    // keyframes 는 namespace 로 개명, @property·초기값 폴백 동반
    expect(css).toContain('animation: pay-demo-spin 1s linear infinite;');
    expect(css).toContain('@keyframes pay-demo-spin {');
    expect(css).toContain('@property --tw-shadow {');
    expect(css).toMatch(/\*, ::before, ::after, ::backdrop \{\n\s+--tw-font-weight: initial;/);
    // 템플릿 다크 클래스는 남지 않는다
    expect(css).not.toMatch(/\.dark\b/);
  });

  it('진입 블록을 main.css 맨 앞에 만들고 손 CSS 는 아래에 둔다', () => {
    const main = read(ext, 'resources/css/main.css');
    const lines = main.split('\n');
    expect(lines[0]).toMatch(/^\/\* g7-extension-css:start/);
    expect(lines[1]).toBe('@import "tailwindcss/theme.css" layer(theme) prefix(paydemo);');
    expect(lines[2]).toBe('@import "tailwindcss/utilities.css" layer(utilities) prefix(paydemo) source(none);');
    expect(lines[3]).toBe('@import "./g7-extension.generated.css";');
    expect(main).toContain('@source "../layouts";\n@source "../extensions";\n@source "../js";\n@source "../../src";');
    expect(main).toContain('@theme {\n  --font-sans: \'Fixture Sans\', ui-sans-serif, sans-serif;\n  --color-primary-500: #3b82f6;\n}');
    expect(main).toContain('@plugin "@tailwindcss/typography";');
    expect(main).toContain(`@custom-variant dark (&${A7});`);
    // @import 는 다른 at-rule 보다 앞
    const firstOther = lines.findIndex((l) => /^@(source|theme|plugin|custom-variant)/.test(l));
    const lastImport = lines.map((l, i) => (l.startsWith('@import') ? i : -1)).reduce((a, b) => Math.max(a, b));
    expect(lastImport).toBeLessThan(firstOther);
    expect(main).toMatch(/\/\* g7-extension-css:end \*\/\n\n\/\* 확장 손 CSS — 변환 도구는 이 아래를 건드리지 않는다 \*\/\n\.pay-demo-tone \{/);
  });

  it('다시 돌려도 결과가 같다(멱등)', async () => {
    const before = snapshot(ext);
    const { report: second } = await convertExtension(options(ext));
    expect(snapshot(ext)).toEqual(before);
    expect(second.summary.tokens).toMatchObject({ tailwind: 0, semantic: 0, marker: 0 });
    expect(second.copiedClasses).toEqual(report.copiedClasses);
    // 손 CSS 가 같은 이름(.pay-demo-card)을 써도 생성 정의는 유지된다
    expect(read(ext, 'resources/css/g7-extension.generated.css')).toContain('.pay-demo-card {\n    border-radius: .5rem;');
    expect(second.split).toEqual(report.split);
  });

  it('--dry-run 은 아무 파일도 쓰지 않는다', async () => {
    const dir = copyFixture();
    temps.push(dir);
    const before = snapshot(dir);
    const { report: dry, writes } = await convertExtension(options(dir, { dryRun: true }));
    expect(snapshot(dir)).toEqual(before);
    expect(dry.summary.filesChanged).toBeGreaterThan(0);
    expect(writes.length).toBeGreaterThan(0);
  });

  it('main.css 가 없으면 만들고, 블록만 교체한다', () => {
    const block = '@import "x";\n';
    const created = upsertEntryBlock(null, block);
    expect(created).toMatch(/^\/\* g7-extension-css:start[^\n]*\n@import "x";\n\/\* g7-extension-css:end \*\/\n$/);
    const withHand = upsertEntryBlock('.a { color: red; }\n', block);
    const replaced = upsertEntryBlock(withHand, '@import "y";\n');
    expect(replaced).toContain('@import "y";');
    expect(replaced).not.toContain('@import "x";');
    expect(replaced.endsWith('\n\n.a { color: red; }\n')).toBe(true);
    expect(upsertEntryBlock(replaced, '@import "y";\n')).toBe(replaced);
  });

  it('main.css 가 없는 확장도 처리한다', async () => {
    const dir = copyFixture();
    temps.push(dir);
    rmSync(path.join(dir, 'resources/css/main.css'));
    const { report: r } = await convertExtension(options(dir));
    expect(r.outputs.mainCssExisted).toBe(false);
    expect(existsSync(path.join(dir, 'resources/css/main.css'))).toBe(true);
  });

  it('namespace 가 없거나 manifest 와 인자가 다르면 오류', async () => {
    const dir = copyFixture();
    temps.push(dir);
    await expect(convertExtension(options(dir, { namespace: 'other', dryRun: true }))).rejects.toThrow(/다르다/);
    const manifest = JSON.parse(read(dir, 'module.json'));
    delete manifest.assets.css.namespace;
    writeFileSync(path.join(dir, 'module.json'), JSON.stringify(manifest));
    await expect(convertExtension(options(dir, { dryRun: true }))).rejects.toThrow(/namespace 가 없다/);
    const { report: r } = await convertExtension(options(dir, { namespace: 'pay-demo', dryRun: true }));
    expect(r.namespaceSource).toBe('argument');
    await expect(convertExtension(options(dir, { namespace: 'Bad_NS', dryRun: true }))).rejects.toThrow(/형식/);
  });
});

describe('층 위치 판단 근거 — 원본 CSS 의 층 밖 규칙과 빌드 산출의 층 밖 규칙이 같다', () => {
  const REPO = path.resolve(here, '..', '..', '..');

  /**
   * 빌드 산출에서 층 밖 규칙에 나오는 원본 의미 클래스.
   *
   * @param {ReturnType<typeof loadTemplateIndex>} t 템플릿 색인
   * @returns {string[]} 클래스
   */
  const distUnlayered = (t) => [...new Set(t.entries.filter((e) => !e.layered)
    .flatMap((e) => e.selectors.flatMap((s) => s.classes)).filter((c) => t.srcClasses.has(c)))].sort();

  it.each([
    ['합성 관리자 템플릿', path.join(FIXTURES, 'templates/admin_tpl')],
    ['sirsoft-admin_basic', path.join(REPO, 'templates/_bundled/sirsoft-admin_basic')],
    ['sirsoft-basic', path.join(REPO, 'templates/_bundled/sirsoft-basic')],
  ])('%s', (_, dir) => {
    const t = loadTemplateIndex(dir);
    expect(t.hasDist).toBe(true);
    // 산출의 층 위치를 따르는 것이 원본 위치를 따르는 것과 같아야 한다(다르면 빌드가 층을 옮긴 것이므로 재검토)
    expect(distUnlayered(t)).toEqual([...t.srcUnlayered].sort());
  });
});

describe('다크 규격 변환', () => {
  it('규격 상수는 최종 규격(다크 안의 라이트만 제외)이다', () => {
    expect(DARK_SUFFIX).toBe(A7);
  });

  it('.dark 조상을 떼고 주체 복합 선택자(의사 요소 앞)에 붙인다', () => {
    expect(applyDarkSpec('.dark .a .b:hover').selector).toBe(`.a .b:hover${A7}`);
    expect(applyDarkSpec('.dark .a::placeholder').selector).toBe(`.a${A7}::placeholder`);
    expect(applyDarkSpec('.dark .a>.b:before').selector).toBe(`.a>.b${A7}:before`);
    expect(applyDarkSpec(':where(.dark .a>:not(:last-child))').selector).toBe(`:where(.a>:not(:last-child)${A7})`);
    expect(applyDarkSpec('.a .dark .b').ok).toBe(false);
    expect(applyDarkSpec('.a').dark).toBe(false);
  });
});

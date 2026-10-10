/**
 * JSON 레이아웃에서 반응형/임의값 클래스 추출
 *
 * 추출 대상:
 * - 반응형: lg:, md:, sm:, xl:, 2xl:
 * - 다크 모드: dark:
 * - 상태: hover:, focus:, active:, disabled:
 * - 임의값: w-[260px], text-[13px] 등
 *
 * 사용법:
 *   node scripts/extract-safelist.cjs
 *
 * 출력:
 *   src/styles/safelist.txt
 */
const fs = require('fs');
const path = require('path');

/**
 * 디렉토리 내 모든 JSON 파일 찾기 (재귀)
 */
function findJsonFiles(dir) {
  let results = [];
  try {
    const items = fs.readdirSync(dir);
    for (const item of items) {
      const fullPath = path.join(dir, item);
      const stat = fs.statSync(fullPath);
      if (stat.isDirectory()) {
        results = results.concat(findJsonFiles(fullPath));
      } else if (item.endsWith('.json')) {
        results.push(fullPath);
      }
      // `.g7.html` 은 여기서 수집하지 않는다 — 아래 dynamic 추출이 JSON.parse 를 전제하기 때문이다.
      // 대신 `main.css` 가 `@source "../../layouts/**/*.g7.html"` 로 직접 앵커해 정적 클래스는
      // Tailwind 스캐너가 잡는다. safelist 는 **동적 생성 클래스**(classMap variants·switch-value 등
      // 스캐너가 문자열로 못 보는 것)만 담당하므로 두 경로의 역할이 갈린다.
    }
  } catch (e) {
    // 디렉토리 접근 실패 시 무시
  }
  return results;
}

const layoutsDir = path.join(__dirname, '../layouts');
const files = findJsonFiles(layoutsDir);
const classes = new Set();

/**
 * editor-spec.json 의 classToken 컨트롤이 생성할 수 있는 고정 토큰의 `dark:` 변형을 safelist 에
 * 보장한다. 편집기는 다크 탭에서 이 토큰들에
 * `dark:` prefix 를 붙여 적용하는데, 그 클래스가 빌드 CSS 에 없으면(레이아웃 JSON 에서 미사용 시
 * Tailwind 가 생성 안 함) 화면에 반영되지 않는다. editor-spec 이 SSoT 이므로 거기서 토큰을 도출해
 * `dark:` 변형을 inline safelist 에 추가한다(원칙 4.8 — 토큰 형식은 editor-spec 이 정함).
 */
// editor-spec.json 합본 — 분할 manifest + `$include` → editor-spec/{block}.json
// 을 단일 spec 으로 읽는다. PHP EditorSpecAssembler 의 합본 규칙과 동치이며, 본
// 스크립트는 템플릿과 함께 배포되므로 외부 의존 없이 자기완결적으로 인라인한다.
function assembleEditorSpec(specPath) {
  if (!fs.existsSync(specPath)) return null;
  let manifest;
  try {
    manifest = JSON.parse(fs.readFileSync(specPath, 'utf-8'));
  } catch (e) {
    return null;
  }
  const includes = manifest.$include;
  delete manifest.$include;
  if (!includes || typeof includes !== 'object') return manifest;
  const baseDir = path.dirname(specPath);
  for (const [key, rel] of Object.entries(includes)) {
    if (typeof rel !== 'string' || rel === '') continue;
    const blockPath = path.join(baseDir, rel.replace(/\//g, path.sep));
    if (!fs.existsSync(blockPath)) continue;
    try {
      manifest[key] = JSON.parse(fs.readFileSync(blockPath, 'utf-8'));
    } catch (e) {
      /* 누락 — 무손실 디그레이드 */
    }
  }
  return manifest;
}

function collectEditorDarkTokens() {
  const specPath = path.join(__dirname, '../editor-spec.json');
  const spec = assembleEditorSpec(specPath);
  if (!spec) return;
  const tokens = new Set();
  const collectApply = (apply) => {
    if (!apply || apply.type !== 'classToken') return;
    for (const t of apply.tokens || []) {
      if (typeof t === 'string' && t.length > 0) tokens.add(t);
    }
    // tokenTemplate(임의값 합성)은 자유값이라 safelist 대상이 아님 — 고정 tokens 만.
  };
  for (const control of Object.values(spec.controls || {})) {
    collectApply(control.apply);
    for (const opt of control.options || []) collectApply(opt.apply);
    // slider `scale` 항목 중 완전한 클래스 토큰(예: opacity-50)을 수집한다. tokenTemplate:"{value}"
    // 슬라이더(opacity 등)는 scale 값 자체가 적용 토큰이므로 apply.tokens 에 잡히지 않는다.
    if (Array.isArray(control.scale)) {
      for (const s of control.scale) {
        if (typeof s === 'string' && /^[a-z]/.test(s) && /[a-z]-/.test(s)) tokens.add(s);
      }
    }
  }
  // 각 토큰의 라이트 + dark: 변형을 safelist 에 추가한다.
  //  - dark: 변형 — 편집기 다크 탭 적용.
  //  - 라이트 토큰 자체 — 레이아웃 JSON 미사용 신규 토큰(shadow/rounded/border/italic/underline/
  //    overflow 등)이 **저장 전 라이브 프리뷰**에서 즉시 반영되도록 보장(레이아웃 스캔은 저장된
  //  JSON 만 보므로, 사용자가 처음 토큰을 적용하는 순간엔 빌드 CSS 에 없을 수 있음).
  for (const t of tokens) {
    classes.add(t);
    classes.add('dark:' + t);
  }
}
collectEditorDarkTokens();

// 추출 대상 패턴
const patterns = [
  /^(lg|md|sm|xl|2xl):/,            // 반응형 breakpoint
  /^dark:/,                          // 다크 모드
  /^(hover|focus|active|disabled):/, // 상태
  /\[.+\]/,                          // 임의값 (arbitrary values)
];

for (const file of files) {
  const content = fs.readFileSync(file, 'utf-8');
  const matches = content.matchAll(/"className":\s*"([^"]+)"/g);
  for (const match of matches) {
    const classList = match[1].split(/\s+/);
    for (const cls of classList) {
      // 유효한 클래스만 (오염 데이터 제외)
      if (cls.includes("'") || cls.includes('}') || cls.includes('{')) continue;
      if (cls.includes('(') || cls.includes(')') || cls.includes('?')) continue;
      if (cls.length === 0) continue;
      // Tailwind 클래스 형식 검증 (알파벳/숫자/하이픈/콜론/슬래시/대괄호만 허용)
      if (!/^[a-zA-Z0-9\-:/\[\]\.%]+$/.test(cls)) continue;

      // 패턴 매칭
      if (patterns.some((p) => p.test(cls))) {
        classes.add(cls);
      }
    }
  }
}

/*
 * 모듈·플러그인 **확장 조각**의 클래스도 함께 담는다.
 *
 * 이 스크립트는 `../layouts` 만 훑고 `main.css` 의 `@source` 도 이 템플릿 안만 앵커하는데,
 * 관리자 화면에 실제로 그려지는 마크업은 그것만이 아니다 — 모듈·플러그인이
 * `resources/extensions/**` 에서 탭·카드·슬롯을 주입한다. 그 조각들이 쓰는 클래스는 어느
 * 스캔 대상에도 없어, 「이 템플릿이 마침 딴 데서도 쓰는 것」일 때만 우연히 살아났다.
 * 아니면 규칙이 통째로 없는데 **빌드는 성공하고 경고도 없다**(2026-09-21 실측 — 같은 원인으로
 * 사용자 템플릿 쪽 확장 카드들의 배치가 무너져 있었다).
 *
 * 대상 판별은 `target_layout` 으로 한다. 슬롯 확장(`extension_point`)은 주입 위치가 조각에
 * 적혀 있지 않아 어느 화면에도 들어갈 수 있으므로 함께 담는다 — safelist 는 과하게 담아도
 * CSS 가 조금 커질 뿐이지만, 빠지면 화면이 조용히 무너진다.
 */
function collectExtensionClasses(into) {
  const roots = [path.join(__dirname, '../../../../modules/_bundled'), path.join(__dirname, '../../../../plugins/_bundled')];
  const jsonFiles = [];
  for (const root of roots) {
    if (!fs.existsSync(root)) continue;
    for (const entry of fs.readdirSync(root)) {
      const extDir = path.join(root, entry, 'resources/extensions');
      if (!fs.existsSync(extDir)) continue;
      for (const f of fs.readdirSync(extDir)) if (f.endsWith('.json')) jsonFiles.push(path.join(extDir, f));
    }
  }

  let added = 0;
  for (const file of jsonFiles) {
    let doc;
    try {
      doc = JSON.parse(fs.readFileSync(file, 'utf-8'));
    } catch {
      continue;
    }
    const target = doc.target_layout || doc.targetLayout || '';
    // 사용자 템플릿 전용 조각은 담지 않는다 — 그쪽은 자기 safelist.txt 가 감당한다.
    if (target && !/^admin[_/]/.test(target) && !target.startsWith('_admin')) continue;

    (function walk(node) {
      if (!node || typeof node !== 'object') return;
      if (Array.isArray(node)) return node.forEach(walk);
      for (const [key, value] of Object.entries(node)) {
        if ((key === 'className' || key === 'class') && typeof value === 'string') {
          // 조건식(`{{a ? 'x' : 'y'}}`)이면 그 안의 문자열 리터럴이 실제로 렌더되는 클래스다.
          // 식 자체를 토큰으로 쪼개면 변수명까지 섞이므로 리터럴만 꺼낸다.
          const source = value.includes('{{')
            ? [...value.matchAll(/'([^']*)'/g)].map((m) => m[1]).join(' ')
            : value;
          for (const tok of source.split(/\s+/)) {
            // 임의값(`grid-cols-[100px_1fr]`)은 평문 `@source` 에서 추출되지 않아 여기서만 살아난다.
            if (tok && !into.has(tok)) {
              into.add(tok);
              added++;
            }
          }
        }
        walk(value);
      }
    })(doc);
  }
  return { files: jsonFiles.length, added };
}

const extStats = collectExtensionClasses(classes);
console.log(`확장 조각 ${extStats.files}개에서 클래스 ${extStats.added}개 추가`);

// main.css에 @source inline() 자동 삽입
const mainCssPath = path.join(__dirname, '../src/styles/main.css');
let mainCss = fs.readFileSync(mainCssPath, 'utf-8');

// 기존 safelist 마커 제거
//
// 삽입 블록은 앞뒤에 개행을 하나씩 두는데, 마커 사이만 잘라내면 그 개행 두 개가 남는다.
// 그대로 두면 빌드 1회마다 main.css 에 빈 줄 2개가 쌓여(실측: 373줄 누적) 매 빌드가 소스
// 파일을 변경된 것으로 만든다. 잘라낼 때 마커 주변 공백까지 함께 걷어낸다.
const markerStart = '/* SAFELIST-START */';
const markerEnd = '/* SAFELIST-END */';
const startIdx = mainCss.indexOf(markerStart);
const endIdx = mainCss.indexOf(markerEnd);
if (startIdx !== -1 && endIdx !== -1) {
  // 마커 앞뒤의 개행까지 함께 정리한다.
  //
  // safelistBlock 은 앞뒤에 개행을 하나씩 달고 들어오는데, 종전 제거 로직은 그 개행을 남겨
  // 두고 블록만 걷어냈다. 그래서 빌드할 때마다 `SAFELIST-END` 뒤에 빈 줄이 한 줄씩 쌓이며
  // 매 빌드가 main.css 를 dirty 로 만들었다(CSS 결과물은 동일 — 순수 노이즈).
  // 앞은 개행을 모두 지우고(블록이 다시 붙인다) 뒤는 한 줄로 접어 빌드를 멱등하게 만든다.
  mainCss =
    mainCss.slice(0, startIdx).replace(/\n+$/, '') +
    mainCss.slice(endIdx + markerEnd.length).replace(/^\n+/, '\n');
}

// 새 safelist 삽입 (@source "../../layouts/**/*.json"; 바로 뒤에)
const classesStr = [...classes].sort().join(' ');
const safelistBlock = `
${markerStart}
@source inline("${classesStr}");
${markerEnd}`;

// @source "../../layouts/**/*.json"; 뒤에 삽입
const layoutSourcePattern = /@source\s+"\.\.\/\.\.\/layouts\/\*\*\/\*\.json";/;
if (layoutSourcePattern.test(mainCss)) {
  mainCss = mainCss.replace(layoutSourcePattern, (match) => match + safelistBlock);
} else {
  // 패턴 없으면 @import "tailwindcss"; 뒤에 삽입
  mainCss = mainCss.replace(/@import\s+"tailwindcss";/, (match) => match + safelistBlock);
}

fs.writeFileSync(mainCssPath, mainCss);

console.log(`✓ Injected ${classes.size} classes into main.css`);

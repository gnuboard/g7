// e2e:allow 시험·시험 헬퍼 파일 — 런타임 동작을 바꾸지 않는다. 대상 화면(정보 탭·회원 목록·TagInput 두 화면·클레임 상세)은 headless 브라우저로 수정 전·후를 실측했고 정보 탭·추정 건수는 E2E settings-info-value-alignment.spec.ts 가 잠근다
/**
 * 흐림 래퍼가 배치 행의 자식 모양을 갈래마다 바꾸는 자리를 찾는 헬퍼 (engine-v1.99.2).
 *
 * ## 왜 필요한가
 *
 * `blur_until_loaded` 는 대상 노드를 `<div>` 래퍼로 감싸 그린다. 그 노드가 `if` 분기이고 부모가 flex·grid·space·gap·justify
 * 계열 컨테이너면 래퍼가 행의 자식이 된다. 흐림을 선언한 갈래는 「래퍼 div」로, 선언하지 않은 갈래는 「값 요소」로 행에 들어가
 * 받기 전·뒤에 행의 자식 모양이 바뀐다. engine-v1.99.2 전에는 거짓 갈래의 빈 래퍼까지 남아 「라벨·값」 양 끝 정렬 행의 값이
 * 가운데로 밀렸다(환경설정 정보 탭 메모리·디스크, 회원 목록 건수).
 *
 * 받는 중 노출 전수 시험(`loadingNegativeState` 계열)은 흐림 **선언 여부**만 본다. 이 헬퍼는 흐림이 **어디에** 선언됐는지를
 * 본다 — 흐림은 분기 노드가 아니라 분기들을 감싸는 하나의 노드(또는 분기 없는 값 노드 자체)에 둔다.
 *
 * ## 쓰는 법
 *
 * ```ts
 * import { findBlurBranchesInLayoutRows } from '@core/template-engine/__tests__/utils/blurBranchRowUtils';
 *
 * expect(findBlurBranchesInLayoutRows(PKG_ROOT, ['layouts'])).toEqual(ALLOWED.map(([label]) => label).sort());
 * ```
 *
 * 판정:
 *  - 노드에 조건(`if`·`condition`·`conditions`·`responsive.{key}.if`)과 꺼지지 않은 `blur_until_loaded` 가 함께 있다.
 *  - 그 노드를 `children`(또는 `responsive.{key}.children`)으로 가진 부모의 className(기본·구간) 토큰 중 하나가 배치
 *    컨테이너다. 반응형·확장 앞글자(`md:`·`board:`)는 떼고 본다. 의미 클래스는 번들 템플릿 스타일과 확장 CSS, 그리고 이
 *    패키지 자신의 CSS 에서 flex·grid 컨테이너 선택자를 모아 판정한다.
 *  - 부모를 알 수 없는 자리(문서·조각 루트, 확장 주입 최상위, `props` 안 셀 정의)는 보지 않는다.
 */

import fs from 'fs';
import path from 'path';

type Node = Record<string, any>;

/** 저장소 루트 — 이 파일은 `resources/js/core/template-engine/__tests__/utils` 에 있다 */
const REPO_ROOT = path.resolve(__dirname, '../../../../../..');

const CONTAINER_UTILITY = [
  /^(inline-)?(flex|grid)$/,
  /^flex-(row|col|wrap|nowrap|row-reverse|col-reverse|wrap-reverse)$/,
  /^grid-(cols|rows|flow)-/,
  /^-?space-[xy]-/,
  /^gap(-[xy])?-/,
  /^justify-(?!self)/,
];
const FLEX_ITEM = /^flex-(\d|1$|auto$|none$|initial$|grow|shrink|basis)/;
const CONTAINER_APPLY_TOKEN = /^(inline-)?(flex|grid)$|^-?space-[xy]-|^gap(-[xy])?-|^justify-(?!self)/;

const semanticCache = new Map<string, Set<string>>();

/**
 * 디렉터리 아래 CSS 파일을 모은다.
 *
 * @param dir 시작 디렉터리
 * @param out 결과 배열
 * @returns CSS 파일 경로 목록
 */
function collectCss(dir: string, out: string[] = []): string[] {
  let entries: fs.Dirent[];
  try {
    entries = fs.readdirSync(dir, { withFileTypes: true });
  } catch {
    return out;
  }
  for (const e of entries) {
    const full = path.join(dir, e.name);
    if (e.isDirectory()) {
      if (e.name !== 'node_modules' && e.name !== 'dist') collectCss(full, out);
    } else if (e.name.endsWith('.css')) out.push(full);
  }
  return out;
}

/**
 * flex·grid 컨테이너 뜻의 의미 클래스 집합.
 *
 * @param pkgRoot 패키지 루트(그 패키지 자신의 CSS 도 본다)
 * @returns 클래스 이름 집합
 */
export function semanticContainerClasses(pkgRoot?: string): Set<string> {
  const key = pkgRoot ?? '';
  const cached = semanticCache.get(key);
  if (cached) return cached;
  const dirs: string[] = [];
  const tplBase = path.join(REPO_ROOT, 'templates', '_bundled');
  if (fs.existsSync(tplBase)) for (const id of fs.readdirSync(tplBase)) dirs.push(path.join(tplBase, id, 'src', 'styles'));
  for (const kind of ['modules', 'plugins']) {
    const base = path.join(REPO_ROOT, kind, '_bundled');
    if (fs.existsSync(base)) for (const id of fs.readdirSync(base)) dirs.push(path.join(base, id, 'resources', 'css'));
  }
  if (pkgRoot) dirs.push(path.join(pkgRoot, 'src', 'styles'), path.join(pkgRoot, 'resources', 'css'));
  const set = new Set<string>();
  for (const file of [...new Set(dirs)].flatMap((d) => collectCss(d))) {
    const text = fs.readFileSync(file, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
    for (const m of text.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
      const body = m[2];
      const applies = [...body.matchAll(/@apply\s+([^;]+);/g)].flatMap((a) => a[1].split(/\s+/).filter(Boolean));
      const isContainer = applies.some((t) => CONTAINER_APPLY_TOKEN.test(t))
        || /(^|;|\s)display\s*:\s*(inline-)?(flex|grid)\b/.test(body);
      if (!isContainer) continue;
      for (const sel of m[1].split(',')) {
        const one = /^\.([A-Za-z][\w-]*)$/.exec(sel.trim());
        if (one) set.add(one[1]);
      }
    }
  }
  semanticCache.set(key, set);
  return set;
}

/**
 * className 값에서 배치 컨테이너 클래스를 찾는다(식 안 문자열 리터럴 포함).
 *
 * @param value className
 * @param semantic 의미 클래스 집합
 * @returns 처음 찾은 컨테이너 클래스, 없으면 null
 */
export function containerClassOf(value: unknown, semantic: Set<string>): string | null {
  if (typeof value !== 'string' || value === '') return null;
  const tokens = value.replace(/\{\{[\s\S]*?\}\}/g, ' ').split(/\s+/).filter(Boolean);
  for (const expr of value.match(/\{\{[\s\S]*?\}\}/g) ?? []) {
    for (const m of expr.matchAll(/'([^'\\]*)'|"([^"\\]*)"|`([^`\\$]*)`/g)) {
      const lit = m[1] ?? m[2] ?? m[3] ?? '';
      if (!lit.startsWith('$t:')) tokens.push(...lit.split(/\s+/).filter(Boolean));
    }
  }
  for (const t of tokens) {
    const bare = t.slice(t.lastIndexOf(':') + 1);
    if (FLEX_ITEM.test(bare)) continue;
    if (CONTAINER_UTILITY.some((re) => re.test(bare)) || semantic.has(bare)) return t;
  }
  return null;
}

const isComponent = (v: unknown): v is Node =>
  !!v && typeof v === 'object' && !Array.isArray(v) && typeof (v as Node).name === 'string'
  && (typeof (v as Node).type === 'string' || ((v as Node).props && typeof (v as Node).props === 'object'));

const baseClass = (n: Node): string => (typeof n.props?.className === 'string' ? n.props.className : typeof n.props?.class === 'string' ? n.props.class : '');

const segmentClass = (n: Node, seg: string): string => {
  const o = n.responsive?.[seg] ?? {};
  const cls = typeof o.props?.className === 'string' ? o.props.className : baseClass(n);
  return typeof o.classMerge === 'string' ? `${cls} ${o.classMerge}` : cls;
};

const isBranch = (n: Node): boolean =>
  (typeof n.if === 'string' && n.if.trim() !== '') || n.condition !== undefined || n.conditions !== undefined
  || Object.values(n.responsive ?? {}).some((o: any) => typeof o?.if === 'string' && o.if.trim() !== '');

const blurOn = (b: unknown): boolean => {
  if (!b) return false;
  if (typeof b === 'object' && !Array.isArray(b)) return (b as Node).enabled !== false;
  return true;
};

/**
 * 한 레이아웃 문서에서 배치 행 자식인 흐림 분기 노드를 찾는다.
 *
 * @param json 파싱한 레이아웃 문서
 * @param semantic 의미 클래스 집합
 * @returns 노드 표기(`id` 가 있으면 id, 없으면 이름) 목록
 */
export function blurBranchesInRows(json: unknown, semantic: Set<string>): string[] {
  const found: string[] = [];
  const visitChildren = (parent: Node, list: unknown[], seg: string | null) => {
    for (const child of list) {
      if (!isComponent(child)) continue;
      if (isBranch(child) && blurOn(child.blur_until_loaded)) {
        const classes = seg !== null
          ? [segmentClass(parent, seg)]
          : [baseClass(parent), ...Object.keys(parent.responsive ?? {})
            .filter((k) => !Array.isArray(parent.responsive[k]?.children))
            .map((k) => segmentClass(parent, k))];
        if (classes.some((c) => containerClassOf(c, semantic))) found.push(String(child.id ?? child.name));
      }
      walk(child);
    }
  };
  const walk = (v: unknown): void => {
    if (Array.isArray(v)) {
      v.forEach(walk);
      return;
    }
    if (!v || typeof v !== 'object') return;
    const node = v as Node;
    const component = isComponent(node);
    for (const [k, x] of Object.entries(node)) {
      if (!x || typeof x !== 'object') continue;
      if (component && k === 'children' && Array.isArray(x)) visitChildren(node, x, null);
      else if (component && k === 'responsive' && !Array.isArray(x)) {
        for (const [seg, o] of Object.entries(x as Node)) {
          if (!o || typeof o !== 'object') continue;
          for (const [sk, sv] of Object.entries(o as Node)) {
            if (sk === 'children' && Array.isArray(sv)) visitChildren(node, sv, seg);
            else walk(sv);
          }
        }
      } else walk(x);
    }
  };
  walk(json);
  return found;
}

/**
 * 패키지 레이아웃 전수에서 배치 행 자식인 흐림 분기 노드를 찾는다.
 *
 * @param pkgRoot 패키지 루트
 * @param roots 레이아웃 디렉터리(패키지 기준, 예: `['layouts']`·`['resources/layouts', 'resources/extensions']`)
 * @returns `패키지 기준 경로#노드` 표기의 정렬된 목록(같은 표기가 여럿이면 그 수만큼)
 */
export function findBlurBranchesInLayoutRows(pkgRoot: string, roots: string[]): string[] {
  const semantic = semanticContainerClasses(pkgRoot);
  const files: string[] = [];
  const collect = (dir: string) => {
    if (!fs.existsSync(dir)) return;
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, e.name);
      if (e.isDirectory()) {
        if (e.name !== '__tests__' && e.name !== 'node_modules') collect(full);
      } else if (e.name.endsWith('.json')) files.push(full);
    }
  };
  roots.forEach((r) => collect(path.join(pkgRoot, r)));
  const out: string[] = [];
  for (const file of files) {
    let json: unknown;
    try {
      json = JSON.parse(fs.readFileSync(file, 'utf8'));
    } catch {
      continue;
    }
    const rel = path.relative(pkgRoot, file).split(path.sep).join('/');
    for (const label of blurBranchesInRows(json, semantic)) out.push(`${rel}#${label}`);
  }
  return out.sort();
}

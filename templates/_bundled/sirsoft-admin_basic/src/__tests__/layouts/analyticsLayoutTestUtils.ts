/**
 * 방문자 통계 레이아웃 테스트 공용 도우미 — 구운 `.json`(런타임 계약)을 읽어 트리를 훑는다.
 *
 * 합성 입력이 아니라 **실제 산출 JSON** 을 읽는다. 합성으로만 검증하면 소스가 되돌아가도 테스트는 계속 통과한다.
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const here = path.dirname(fileURLToPath(import.meta.url));

/** 템플릿 루트 (`src/__tests__/layouts` 기준 세 단계 위) */
export const TEMPLATE_DIR = path.resolve(here, '../../..');

/**
 * 레이아웃·조각 JSON 을 읽는다.
 *
 * @param relative `layouts/` 아래 상대 경로 (예: `admin_analytics_overview.json`)
 * @returns 파싱된 JSON
 */
export function loadLayout(relative: string): any {
  const file = path.join(TEMPLATE_DIR, 'layouts', relative);
  return JSON.parse(fs.readFileSync(file, 'utf8'));
}

/**
 * 언어 파일(`lang/partial/{locale}/{ns}.json`)을 읽는다.
 *
 * @param locale 템플릿 안의 로케일 디렉터리 이름(ko·en …)
 * @param ns 네임스페이스 파일명
 * @returns 파싱된 JSON (그 로케일에 파일이 없으면 빈 객체 — 존재 검사가 그 부재를 누락으로 센다)
 */
export function loadLang(locale: string, ns: string): any {
  const file = path.join(TEMPLATE_DIR, 'lang', 'partial', locale, `${ns}.json`);
  return fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')) : {};
}

/**
 * 로케일 모집단 — 템플릿 `lang/partial/` 의 디렉터리 전부 + 번들 ja 언어팩.
 *
 * 로케일을 손으로 적으면 로케일이 늘 때 조용히 지나가고, ja 는 템플릿 밖(언어팩)에 있어 디렉터리만
 * 읽으면 빠진다. 둘을 합쳐 돌려준다.
 *
 * @returns 로케일 이름 목록 (ja 포함, 중복 없음)
 */
export function localeNames(): string[] {
  const dir = path.join(TEMPLATE_DIR, 'lang', 'partial');
  const inTemplate = fs.readdirSync(dir).filter((d) => fs.statSync(path.join(dir, d)).isDirectory());
  return Array.from(new Set([...inTemplate, 'ja']));
}

/**
 * 로케일 하나의 네임스페이스 트리를 읽는다 — ja 는 언어팩에서, 나머지는 템플릿에서.
 *
 * @param locale 로케일
 * @param ns 네임스페이스 파일명
 * @returns 파싱된 JSON (없으면 빈 객체)
 */
export function loadLocale(locale: string, ns: string): any {
  return locale === 'ja' ? loadJaPack(ns) : loadLang(locale, ns);
}

/**
 * 방문자 통계가 들인 레이아웃·조각 전부(구운 JSON) — `layouts/` 에서 파생한다.
 *
 * 화면 `admin_analytics_*.json` + 조각 `partials/admin_analytics/*.json` + 대시보드 카드 조각과
 * 그 카드를 품는 대시보드. 손으로 적은 목록은 화면·조각이 늘면 조용히 지나간다.
 *
 * @returns `layouts/` 기준 상대 경로 목록
 */
export function analyticsLayoutFiles(): string[] {
  const layoutsDir = path.join(TEMPLATE_DIR, 'layouts');
  const top = fs.readdirSync(layoutsDir).filter((f) => /^admin_analytics_.*\.json$/.test(f)).sort();
  const partials = fs
    .readdirSync(path.join(layoutsDir, 'partials', 'admin_analytics'))
    .filter((f) => f.endsWith('.json'))
    .sort()
    .map((f) => `partials/admin_analytics/${f}`);
  return [...top, ...partials, 'partials/admin_dashboard/_analytics_widget.json', 'admin_dashboard.json'];
}

/** 통계 화면(라우트가 여는 레이아웃)만 — `layouts/admin_analytics_*.json` */
export function analyticsScreens(): string[] {
  return fs
    .readdirSync(path.join(TEMPLATE_DIR, 'layouts'))
    .filter((name) => /^admin_analytics_.+\.json$/.test(name))
    .sort();
}

/**
 * 화면 트리를 읽되 `partial` 참조를 따라가 조각까지 펼친 모든 객체.
 *
 * @param relative `layouts/` 기준 상대 경로
 * @param seen 순환 방지
 * @returns 객체 목록
 */
export function expandedObjects(relative: string, seen = new Set<string>()): Record<string, any>[] {
  if (seen.has(relative)) return [];
  seen.add(relative);
  const file = path.join(TEMPLATE_DIR, 'layouts', relative);
  if (!fs.existsSync(file)) return [];
  const objects = collectObjects(JSON.parse(fs.readFileSync(file, 'utf8')));
  const nested = objects
    .map((node) => node.partial)
    .filter((ref): ref is string => typeof ref === 'string')
    .flatMap((ref) => expandedObjects(ref, seen));
  return objects.concat(nested);
}

/**
 * 저장소의 관리자 인증 응답(`/api/admin/auth/user` → `UserResource::toArray`)이 싣는 최상위 키.
 *
 * 레이아웃이 `current_user?.data?.X` 로 읽는 필드가 실제로 오는지 대조하는 모집단이다.
 *
 * @returns 키 목록
 */
export function adminAuthUserFields(): string[] {
  const repo = path.resolve(TEMPLATE_DIR, '../../..');
  const source = fs.readFileSync(path.join(repo, 'app/Http/Resources/UserResource.php'), 'utf8');
  const start = source.indexOf('public function toArray(');
  const next = source.indexOf('public function ', start + 1);
  const body = source.slice(start, next === -1 ? source.length : next);
  // 최상위 배열 키만 — `$data = [` 바로 안(12칸 들여쓰기)에 적힌 문자열 키. 관계 매핑 안쪽 키(더 깊은
  // 들여쓰기)와 `...$this->formatTimestamps()` 같은 펼침은 세지 않는다 — 통계 레이아웃은 그 키를 읽지 않는다.
  return Array.from(new Set([...body.matchAll(/^\s{12}'([a-z_]+)'\s*=>/gm)].map((m) => m[1])));
}

/**
 * ja 언어팩(`lang-packs/_bundled/g7-template-sirsoft-admin_basic-ja/frontend/partial/{ns}.json`)을 읽는다.
 *
 * ja 는 템플릿 안이 아니라 언어팩 패키지에 있다. 빌드 스크립트가 ko 원본에서 생성하므로 사람이 고치지
 * 않지만, 생성이 빠진 채로 배포되면 일본어 로케일만 영어로 떨어진다 — 그 상태를 여기서 잰다.
 *
 * @param ns 네임스페이스 파일명
 * @returns 파싱된 JSON (팩 파일이 없으면 빈 객체)
 */
export function loadJaPack(ns: string): any {
  const file = path.join(
    TEMPLATE_DIR,
    '../../../lang-packs/_bundled/g7-template-sirsoft-admin_basic-ja/frontend/partial',
    `${ns}.json`,
  );
  return fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')) : {};
}

/**
 * 점 경로로 언어 값을 찾는다.
 *
 * @param tree 언어 트리
 * @param dotted `admin.analytics.x.y` (첫 조각은 네임스페이스라 건너뛴다)
 * @returns 값 또는 undefined
 */
export function langValue(tree: any, dotted: string): unknown {
  return dotted.split('.').slice(1).reduce((cur: any, part) => (cur && typeof cur === 'object' ? cur[part] : undefined), tree);
}

/** 트리 안의 모든 객체를 깊이 우선으로 모은다 */
export function collectObjects(root: unknown): Record<string, any>[] {
  const out: Record<string, any>[] = [];
  const walk = (cur: unknown): void => {
    if (Array.isArray(cur)) { cur.forEach(walk); return; }
    if (cur && typeof cur === 'object') {
      out.push(cur as Record<string, any>);
      Object.values(cur as Record<string, unknown>).forEach(walk);
    }
  };
  walk(root);
  return out;
}

/** 컴포넌트 노드(`name` 보유)만 모은다 */
export function nodesNamed(root: unknown, name: string): Record<string, any>[] {
  return collectObjects(root).filter((o) => o.name === name);
}

/** `id`(data-ref) 로 노드를 찾는다 */
export function nodeById(root: unknown, id: string): Record<string, any> | undefined {
  return collectObjects(root).find((o) => o.id === id);
}

/** 핸들러명으로 액션을 모은다 */
export function actionsWithHandler(root: unknown, handler: string): Record<string, any>[] {
  return collectObjects(root).filter((o) => o.handler === handler);
}

/** 데이터소스를 id 로 찾는다 */
export function dataSource(layout: any, id: string): Record<string, any> | undefined {
  return (layout.data_sources ?? []).find((d: any) => d.id === id);
}

/** JSON 전체를 문자열로 (표현식 포함 여부 검사용) */
export function stringify(root: unknown): string {
  return JSON.stringify(root);
}

/**
 * 문서 안의 번역 키를 모두 뽑는다 — `$t:key`, `$t:defer:key`, `$t:key|param=…`, 식 안의 `$t('key')`.
 * (지연 접두사를 키로 읽거나 식 안의 호출을 놓치면 그 키는 ko/en 존재 검사에서 빠진다.)
 */
export function translationKeys(root: unknown): string[] {
  const keys = new Set<string>();
  const s = stringify(root);
  // 키는 `네임스페이스.경로` 라 점을 하나 이상 품는다 — 작성자 주석이 적은 `` `$t:defer:` `` 같은 문법
  // 설명을 키 「defer」로 읽지 않게 한다.
  for (const re of [/\$t:(?:defer:)?([A-Za-z0-9_]+\.[A-Za-z0-9_.]+)/g, /\$t\('([A-Za-z0-9_]+\.[A-Za-z0-9_.]+)'/g]) {
    let m: RegExpExecArray | null;
    // `$t('a.b.' + x)` 처럼 이어 붙이는 동적 키는 접두사만 잡히므로 뺀다(존재 검사 대상이 아니다).
    while ((m = re.exec(s)) !== null) if (!m[1].endsWith('.')) keys.add(m[1]);
  }
  return Array.from(keys);
}

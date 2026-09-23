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
 * @param locale ko | en
 * @param ns 네임스페이스 파일명
 * @returns 파싱된 JSON
 */
export function loadLang(locale: 'ko' | 'en', ns: string): any {
  return JSON.parse(fs.readFileSync(path.join(TEMPLATE_DIR, 'lang', 'partial', locale, `${ns}.json`), 'utf8'));
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
  for (const re of [/\$t:(?:defer:)?([A-Za-z0-9_.]+)/g, /\$t\('([A-Za-z0-9_.]+)'/g]) {
    let m: RegExpExecArray | null;
    // `$t('a.b.' + x)` 처럼 이어 붙이는 동적 키는 접두사만 잡히므로 뺀다(존재 검사 대상이 아니다).
    while ((m = re.exec(s)) !== null) if (!m[1].endsWith('.')) keys.add(m[1]);
  }
  return Array.from(keys);
}

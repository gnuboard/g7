/**
 * stateModalSeedCarriesRenderedFields.test.ts — 창 상태 표본의 시드가 창이 글자로 그리는 값을 담는지 정적 가드
 *
 * 창(modal) 본문은 목록 행 클릭이 전역 상태에 담아 둔 값을 읽는다. 상태 표본은 그 클릭을 대신하므로, 클릭이 싣는
 * 하위 키 중 창이 글자로 그리는 키를 빠뜨리면 그 줄은 캔버스에 그려지지 않는다(데이터 조건에 걸려 감춰지거나 빈 글자).
 * 검증 오류 시드는 서버 422 응답처럼 필드별 문구 목록이어야 한다 — 숫자를 넣으면 오류 목록에 그 숫자가 한 줄로 찍힌다.
 * 두 경우 모두 오류는 나지 않는다.
 *
 *  - 7.1.0 실측: 마이페이지 「신고 취소」 창 표본이 `reportCancelModal` 에 `id` 만 담아, 어느 신고를 거두는지
 *    보여 주는 대상 제목 줄이 편집기에서 그려지지 않았다(목록의 취소 버튼은 `id` 와 `title` 을 담는다).
 *  - 7.1.0 실측: 쇼핑몰 마일리지 수동 지급 창 표본의 `errors.errors.user_id` 가 숫자 1 이라 오류 목록에 「1」 이 찍혔다.
 *  - 7.1.0 실측: 언어별 맵(`{ko, en}`)으로 읽는 값을 문자열로 담은 표본 — 쇼핑몰 공통정보 보기의 안내 내용, 다국어 태그 창의
 *    언어별 값, 페이지 버전 미리보기의 제목·본문, 본인인증 메시지 정의·템플릿 창의 이름·제목·본문이 편집기에서 빈 칸으로 그려졌다.
 *
 * 판정 1: 창 묶음 항목의 initialState 객체 시드 K 에 대해, 레이아웃의 setState 가 K 에 싣는 하위 키 중 창 파일의
 *         글자 노드(`text`)가 `_global.K?.sub` 로 그리는 키는 시드에도 있어야 한다. 창 파일과 setState 는 표본을 둔
 *         확장에서 찾고, 그 확장에 창이 없을 때만 다른 번들 확장에서 찾는다(같은 창 id 를 여러 확장이 따로 쓴다).
 * 판정 2: 이름이 errors 로 끝나는 시드 맵은 필드별 문구 목록(문자열 배열 또는 문자열)이다. 422 모양({message, errors})이면
 *         안쪽 errors 를 본다. 상태 플래그(불리언)는 허용한다.
 * 판정 3: 같은 확장의 레이아웃이 `_global.K?.sub?.[식]` 처럼 숫자가 아닌 색인으로 읽는 경로에 문자열 시드를 두지 않는다
 *         (언어별 맵을 읽는 자리라 문자열이면 빈 값이 된다).
 * 판정 4: 같은 확장의 레이아웃이 `data-if="{{_global.K?.errors}}"` 처럼 참/거짓만으로 오류 띠를 띄우는 경로에 빈 오류 맵(`{}`)을
 *         두지 않는다 — 빈 객체도 참이라 「채움」 상태에 내용 없는 오류 띠가 그려진다(본인인증 메시지 창 2곳, 7.1.0 실측).
 * 판정 5: 창 묶음의 「채움」 항목(id 에 filled)은 창이 도는 상태 목록(`_global`/`_local` 반복) 중 하나 이상을 채운다 — 하나도
 *         채우지 않으면 목록 없이, 또는 「없습니다」 문구로 그려진다(쿠폰 받기 창이 빈 상태 문구, 일괄변경 확인 0개 등).
 * 판정 6: 그림을 싣는 키(아이콘·썸네일·이미지 주소·아바타, 아이콘 이름)에 생성기 자리표시 값(`/sample`, `샘플 값`)을 두지 않는다 —
 *         편집기에서 404 나 빈 아이콘으로 그려진다(확장 관리 창 23곳 아이콘).
 * 판정 7: 창이 도는 상태 목록의 항목을 객체로 읽으면(`항목.필드`) 시드 배열의 항목도 객체다 — 문자열 항목이면 그 칸이
 *         빈 글자나 `(undefined)` 로, 번역 인자가 빈 값이 되어 `{{version}}` 같은 자리표시 원문으로 그려진다(
 *         확장 정보 창의 의존성·역할·언어팩·변경 이력, 업데이트 창의 변경 이력).
 *
 * 모집단은 저장소에서 파생한다.
 *
 * @since engine-v1.76.0
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, existsSync, readdirSync, statSync } from 'node:fs';
import { resolve, join } from 'node:path';

const ROOT = process.cwd();
const BUNDLE_BASES = ['modules/_bundled', 'plugins/_bundled', 'templates/_bundled'];
const LAYOUT_DIRS = ['layouts', 'resources/layouts', 'resources/extensions'];

type Json = any;

const isPlainObject = (v: unknown): v is Record<string, unknown> => !!v && typeof v === 'object' && !Array.isArray(v);

/** 디렉터리 아래 JSON 파일을 모은다. */
function walkJson(absDir: string, out: string[] = []): string[] {
  if (!existsSync(absDir)) return out;
  for (const entry of readdirSync(absDir)) {
    const abs = join(absDir, entry);
    if (statSync(abs).isDirectory()) walkJson(abs, out);
    else if (entry.endsWith('.json')) out.push(abs);
  }
  return out;
}

/** 트리 안에 `id === modalId` 인 노드가 있는가 */
function declaresId(node: Json, modalId: string): boolean {
  if (Array.isArray(node)) return node.some((child) => declaresId(child, modalId));
  if (!isPlainObject(node)) return false;
  if (node.id === modalId) return true;
  return Object.values(node).some((child) => declaresId(child, modalId));
}

/** setState 가 상태 키 `key` 에 싣는 하위 키 — 객체 대입(`key: {a, b}`)과 경로 대입(`key.a`)을 모두 센다. */
function collectSetterSubkeys(docs: Json[], key: string): Set<string> {
  const subkeys = new Set<string>();
  const visit = (node: Json): void => {
    if (Array.isArray(node)) {
      node.forEach(visit);
      return;
    }
    if (!isPlainObject(node)) return;
    if (node.handler === 'setState' && isPlainObject(node.params)) {
      for (const [param, value] of Object.entries(node.params)) {
        if (param === key && isPlainObject(value)) Object.keys(value).forEach((s) => subkeys.add(s));
        else if (param.startsWith(`${key}.`)) subkeys.add(param.slice(key.length + 1).split('.')[0]);
      }
    }
    Object.values(node).forEach(visit);
  };
  docs.forEach(visit);
  return subkeys;
}

/** 창 파일의 글자 노드가 `_{scope}.{key}.sub` 로 그리는 하위 키. */
function collectRenderedSubkeys(docs: Json[], scope: string, key: string): Set<string> {
  const subkeys = new Set<string>();
  const pattern = new RegExp(`_${scope}\\.${key}\\??\\.([A-Za-z_]\\w*)`, 'g');
  const visit = (node: Json): void => {
    if (Array.isArray(node)) {
      node.forEach(visit);
      return;
    }
    if (!isPlainObject(node)) return;
    if (typeof node.text === 'string') for (const m of node.text.matchAll(pattern)) subkeys.add(m[1]);
    Object.values(node).forEach(visit);
  };
  docs.forEach(visit);
  return subkeys;
}

export interface ExtInput {
  label: string;
  groups: Json[];
  docs: Json[];
}

/** 판정 1 위반 — 창 표본 시드가 여는 쪽이 싣고 창이 글자로 그리는 하위 키를 빠뜨린 자리. */
export function findModalSeedGaps(ext: ExtInput, otherDocs: Json[] = []): string[] {
  const offenders: string[] = [];
  for (const group of ext.groups) {
    if (group?.scope?.kind !== 'modal' || typeof group.scope.match !== 'string') continue;
    const modalId: string = group.scope.match;
    let modalDocs = ext.docs.filter((doc) => declaresId(doc, modalId));
    let setterDocs = ext.docs;
    if (!modalDocs.length) {
      modalDocs = otherDocs.filter((doc) => declaresId(doc, modalId));
      setterDocs = otherDocs;
    }
    if (!modalDocs.length) continue;
    for (const item of group.items ?? []) {
      for (const scope of ['global', 'local']) {
        for (const [key, seed] of Object.entries((item?.initialState?.[scope] ?? {}) as Record<string, Json>)) {
          if (!isPlainObject(seed)) continue;
          const rendered = collectRenderedSubkeys(modalDocs, scope, key);
          if (!rendered.size) continue;
          const missing = [...collectSetterSubkeys(setterDocs, key)].filter((s) => rendered.has(s) && !(s in seed));
          if (missing.length) offenders.push(`${ext.label} ${modalId} :: ${item.id} ${scope}.${key} missing=${missing.join(',')}`);
        }
      }
    }
  }
  return offenders;
}

/** 판정 2 위반 — errors 로 끝나는 시드 맵의 값이 문구 목록이 아닌 자리. */
export function findErrorSeedShapeViolations(ext: Pick<ExtInput, 'label' | 'groups'>): string[] {
  const offenders: string[] = [];
  const checkMap = (map: Record<string, Json>, where: string): void => {
    for (const [field, value] of Object.entries(map)) {
      const ok = typeof value === 'string' || typeof value === 'boolean' || (Array.isArray(value) && value.every((m) => typeof m === 'string'));
      if (!ok) offenders.push(`${where}.${field}=${JSON.stringify(value)}`);
    }
  };
  const visit = (node: Json, trail: string): void => {
    if (Array.isArray(node)) {
      node.forEach((child, i) => visit(child, `${trail}[${i}]`));
      return;
    }
    if (!isPlainObject(node)) return;
    for (const [key, value] of Object.entries(node)) {
      const path = `${trail}.${key}`;
      if (/errors$/i.test(key) && isPlainObject(value)) {
        if (isPlainObject(value.errors)) checkMap(value.errors as Record<string, Json>, `${path}.errors`);
        else if (!('message' in value)) checkMap(value, path);
        continue;
      }
      visit(value, path);
    }
  };
  for (const group of ext.groups) {
    for (const item of group?.items ?? []) visit(item?.initialState ?? {}, `${ext.label} ${group?.scope?.match} :: ${item?.id}`);
  }
  return offenders;
}

/** 판정 3 위반 — 문자열 시드를 같은 확장 레이아웃이 숫자가 아닌 색인(언어별 맵 등)으로 읽는 자리. */
export function findStringSeedsReadAsMap(ext: ExtInput): string[] {
  const offenders: string[] = [];
  const text = ext.docs.map((doc) => JSON.stringify(doc)).join('\n');
  const escape = (seg: string): string => seg.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const visit = (node: Json, path: string[], scope: string, where: string): void => {
    if (!isPlainObject(node)) return;
    for (const [key, value] of Object.entries(node)) {
      const next = [...path, key];
      if (typeof value === 'string') {
        const segs = next.map(escape).join('\\??\\.');
        if (new RegExp(`_${scope}\\.${segs}\\??\\.?\\[(?!\\d)`).test(text)) offenders.push(`${where} ${scope}.${next.join('.')}`);
      } else if (isPlainObject(value)) {
        visit(value, next, scope, where);
      }
    }
  };
  for (const group of ext.groups) {
    for (const item of group?.items ?? []) {
      for (const scope of ['global', 'local']) visit(item?.initialState?.[scope], [], scope, `${ext.label} ${group?.scope?.match} :: ${item?.id}`);
    }
  }
  return offenders;
}

/** 판정 4 위반 — 빈 오류 맵(`{}`) 시드를 같은 확장 레이아웃이 참/거짓만으로 검사해 빈 오류 띠를 띄우는 자리. */
export function findEmptyErrorMapBanners(ext: ExtInput): string[] {
  const offenders: string[] = [];
  const text = ext.docs.map((doc) => JSON.stringify(doc)).join('\n');
  const escape = (seg: string): string => seg.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const visit = (node: Json, path: string[], scope: string, where: string): void => {
    if (!isPlainObject(node)) return;
    for (const [key, value] of Object.entries(node)) {
      const next = [...path, key];
      if (/errors$/i.test(key) && isPlainObject(value) && Object.keys(value).length === 0) {
        const segs = next.map(escape).join('\\??\\.');
        if (new RegExp(`"if":"\\{\\{\\s*!?!?_${scope}\\.${segs}\\s*\\}\\}"`).test(text)) offenders.push(`${where} ${scope}.${next.join('.')}`);
      } else if (isPlainObject(value)) {
        visit(value, next, scope, where);
      }
    }
  };
  for (const group of ext.groups) {
    for (const item of group?.items ?? []) {
      for (const scope of ['global', 'local']) visit(item?.initialState?.[scope], [], scope, `${ext.label} ${group?.scope?.match} :: ${item?.id}`);
    }
  }
  return offenders;
}

/** 트리에서 `id === modalId` 인 첫 노드. */
function findNodeById(docs: Json[], modalId: string): Json | null {
  const visit = (node: Json): Json | null => {
    if (Array.isArray(node)) {
      for (const child of node) {
        const hit = visit(child);
        if (hit) return hit;
      }
      return null;
    }
    if (!isPlainObject(node)) return null;
    if (node.id === modalId) return node;
    for (const child of Object.values(node)) {
      const hit = visit(child);
      if (hit) return hit;
    }
    return null;
  };
  for (const doc of docs) {
    const hit = visit(doc);
    if (hit) return hit;
  }
  return null;
}

/** 노드 아래 반복(`iteration.source`)이 도는 상태 경로 — `_global`/`_local` 을 읽지 않는 반복(상수·데이터소스)은 뺀다. */
function collectStateIterationPaths(node: Json): Array<{ scope: string; segs: string[] }> {
  const out: Array<{ scope: string; segs: string[] }> = [];
  const visit = (n: Json): void => {
    if (Array.isArray(n)) {
      n.forEach(visit);
      return;
    }
    if (!isPlainObject(n)) return;
    const source = (n.iteration as Json)?.source;
    if (typeof source === 'string') {
      const m = source.match(/_(global|local)\.([A-Za-z_]\w*(?:\??\.[A-Za-z_]\w*)*)/);
      // 오류 문구 목록은 내용 목록이 아니다 — 「채움」 상태에서는 비어 있는 것이 맞다
      if (m && !/errors$/i.test(m[2])) out.push({ scope: m[1], segs: m[2].split(/\??\./) });
    }
    Object.values(n).forEach(visit);
  };
  visit(node);
  return out;
}

/** 값이 차면 입력 본문을 감추는 「완료 분기」 키 — `if: "{{!_global.K…}}"`·`"{{!(_global.K?.length)}}"` 로 본문을 거는 키. */
function collectCompletionBranchKeys(node: Json): Set<string> {
  const keys = new Set<string>();
  const visit = (n: Json): void => {
    if (Array.isArray(n)) {
      n.forEach(visit);
      return;
    }
    if (!isPlainObject(n)) return;
    if (typeof n.if === 'string') {
      // `!(_global.K?.length)`(괄호로 감싼 목록 길이 부정)과 `!_global.K`(값 자체 부정)만 — `!_global.K?.length` 는
      // 「목록이 비면 빈 상태 문구」 분기라 완료 분기가 아니다.
      for (const m of n.if.matchAll(/!\(\s*_global\.([A-Za-z_]\w*)\?\.length\s*\)|!_global\.([A-Za-z_]\w*)(?![\w.?])/g)) keys.add(m[1] ?? m[2]);
    }
    Object.values(n).forEach(visit);
  };
  visit(node);
  return keys;
}

/** 판정 5 위반 — 「채움」 창 표본이 창이 도는 상태 목록을 하나도 채우지 않아 목록 없이(또는 빈 목록 문구로) 그려지는 자리. */
export function findFilledModalsWithoutListItems(ext: ExtInput, otherDocs: Json[] = []): string[] {
  const offenders: string[] = [];
  for (const group of ext.groups) {
    if (group?.scope?.kind !== 'modal' || typeof group.scope.match !== 'string') continue;
    const modal = findNodeById(ext.docs, group.scope.match) ?? findNodeById(otherDocs, group.scope.match);
    if (!modal) continue;
    // 「완료 뒤」 목록(그 값이 차면 입력 본문을 감추는 키 — `{{!_global.K}}`·`{{!(_global.K?.length)}}`)은 「채움」(입력 모습)에서
    // 비어 있는 것이 맞다. 7.1.0 실측(영역 I2): 템플릿 제거 창이 보존 사본 목록을 채워 늘 「제거 완료」 모습으로 열렸다.
    const completionKeys = collectCompletionBranchKeys(modal);
    const paths = collectStateIterationPaths(modal).filter(({ scope, segs }) => !(scope === 'global' && completionKeys.has(segs[0])));
    if (!paths.length) continue;
    for (const item of group.items ?? []) {
      if (!/filled/.test(String(item?.id ?? ''))) continue;
      const filled = paths.some(({ scope, segs }) => {
        const value = segs.reduce((acc: Json, seg) => (isPlainObject(acc) ? acc[seg] : undefined), item?.initialState?.[scope]);
        return (Array.isArray(value) && value.length > 0) || (isPlainObject(value) && Object.keys(value).length > 0);
      });
      if (!filled) offenders.push(`${ext.label} ${group.scope.match} :: ${item.id}`);
    }
  }
  return offenders;
}

/** 그림을 싣는 키(아이콘·썸네일·이미지 주소·아바타)와 아이콘 이름 키. 링크 주소(`*_changelog_url`, `click_url` 등)는 싣지 않으므로 뺀다. */
const IMAGE_LIKE_KEY = /(?:^|_)(?:icon_url|thumbnail_url|image_url|preview_url|avatar(?:_url)?)$|^(?:imageUrl|thumbnail|avatar|userAvatar|icon)$|[a-z]Avatar$/;

/** 생성기가 남긴 자리표시 값 — 주소나 아이콘 이름으로 쓰면 그림이 깨진다. */
const PLACEHOLDER_VALUES = new Set(['/sample', '샘플 값']);

/** 판정 6 위반 — 그림을 싣는 키에 자리표시 값을 둔 자리. */
export function findPlaceholderImageSeeds(ext: Pick<ExtInput, 'label' | 'groups'>): string[] {
  const offenders: string[] = [];
  const visit = (node: Json, trail: string): void => {
    if (Array.isArray(node)) {
      node.forEach((child, i) => visit(child, `${trail}[${i}]`));
      return;
    }
    if (!isPlainObject(node)) return;
    for (const [key, value] of Object.entries(node)) {
      if (typeof value === 'string' && IMAGE_LIKE_KEY.test(key) && PLACEHOLDER_VALUES.has(value)) offenders.push(`${trail}.${key}=${value}`);
      else visit(value, `${trail}.${key}`);
    }
  };
  for (const group of ext.groups) {
    for (const item of group?.items ?? []) visit(item?.initialState ?? {}, `${ext.label} ${group?.scope?.match} :: ${item?.id}`);
  }
  return offenders;
}

/**
 * 창 안의 반복 중 상태 목록을 돌며 항목 변수를 `항목.필드` 로 읽는 자리를 모은다.
 *
 * @param node 창 노드
 * @returns 상태 범위·경로·항목 변수 목록
 */
function collectObjectItemIterations(node: Json): Array<{ scope: string; segs: string[]; itemVar: string }> {
  const out: Array<{ scope: string; segs: string[]; itemVar: string }> = [];
  const visit = (n: Json): void => {
    if (Array.isArray(n)) {
      n.forEach(visit);
      return;
    }
    if (!isPlainObject(n)) return;
    const iteration = n.iteration as Json;
    if (typeof iteration?.source === 'string' && typeof iteration?.item_var === 'string') {
      // 원천이 상태 경로 그대로인 반복만 본다 — 계산식(즉시 실행 함수·filter·map)은 시드 항목을 다른 모양으로 바꿔 돈다
      const source = iteration.source.trim().replace(/^\{\{\s*|\s*\}\}$/g, '');
      const m = source.match(/^\(?\s*_(global|local)\.([A-Za-z_]\w*(?:\??\.[A-Za-z_]\w*)*)\s*(?:\?\?\s*\[\s*\])?\s*\)?$/);
      const body = JSON.stringify({ ...n, iteration: undefined });
      const readsField = new RegExp(`(?<![\\w.$])${iteration.item_var}\\??\\.[A-Za-z_]`).test(body);
      if (m && readsField) out.push({ scope: m[1], segs: m[2].split(/\??\./), itemVar: iteration.item_var });
    }
    Object.values(n).forEach(visit);
  };
  visit(node);
  return out;
}

/** 판정 7 위반 — 창이 항목을 객체로 읽는 상태 목록에 문자열(또는 숫자) 항목을 둔 표본. */
export function findScalarItemsReadAsObjects(ext: ExtInput, otherDocs: Json[] = []): string[] {
  const offenders = new Set<string>();
  for (const group of ext.groups) {
    if (group?.scope?.kind !== 'modal' || typeof group.scope.match !== 'string') continue;
    const modal = findNodeById(ext.docs, group.scope.match) ?? findNodeById(otherDocs, group.scope.match);
    if (!modal) continue;
    for (const { scope, segs, itemVar } of collectObjectItemIterations(modal)) {
      for (const item of group.items ?? []) {
        const value = segs.reduce((acc: Json, seg) => (isPlainObject(acc) ? acc[seg] : undefined), item?.initialState?.[scope]);
        if (Array.isArray(value) && value.some((v) => !isPlainObject(v))) {
          offenders.add(`${ext.label} ${group.scope.match} :: ${item.id} ${scope}.${segs.join('.')} (${itemVar}.*)`);
        }
      }
    }
  }
  return [...offenders];
}

/** 번들 확장 전부 — 상태 묶음(`$include` 조각 포함)과 레이아웃 JSON. */
function collectExtensions(): ExtInput[] {
  const exts: ExtInput[] = [];
  for (const base of BUNDLE_BASES) {
    const baseDir = resolve(ROOT, base);
    if (!existsSync(baseDir)) continue;
    for (const ext of readdirSync(baseDir)) {
      const extRoot = resolve(baseDir, ext);
      if (!statSync(extRoot).isDirectory()) continue;
      const docs: Json[] = [];
      for (const sub of LAYOUT_DIRS) {
        for (const file of walkJson(resolve(extRoot, sub))) {
          try {
            docs.push(JSON.parse(readFileSync(file, 'utf8')));
          } catch {
            // 레이아웃이 아닌 JSON 조각은 건너뛴다
          }
        }
      }
      const manifestAbs = resolve(extRoot, 'editor-spec.json');
      let groups: Json[] = [];
      if (existsSync(manifestAbs)) {
        const manifest = JSON.parse(readFileSync(manifestAbs, 'utf8'));
        const inc = manifest?.$include?.states;
        const states = manifest.states ?? (typeof inc === 'string' && existsSync(resolve(extRoot, inc)) ? JSON.parse(readFileSync(resolve(extRoot, inc), 'utf8')) : undefined);
        groups = states?.groups ?? [];
      }
      exts.push({ label: `${base}/${ext}`, groups, docs });
    }
  }
  return exts;
}

const EXTS = collectExtensions();
const ALL_DOCS = EXTS.flatMap((e) => e.docs);
const modalSeedCount = (): number =>
  EXTS.flatMap((e) => e.groups)
    .filter((g: Json) => g?.scope?.kind === 'modal')
    .flatMap((g: Json) => g.items ?? [])
    .filter((i: Json) => Object.values(i?.initialState?.global ?? {}).some(isPlainObject)).length;

describe('창 상태 표본 시드 — 모집단', () => {
  /**
   * @effects modal_seed_guard_population_is_derived_from_repository
   */
  it('번들 확장의 상태 묶음과 레이아웃을 저장소에서 파생하며, 객체 시드를 가진 창 표본이 비어 있지 않다', () => {
    expect(EXTS.filter((e) => e.groups.length).length).toBeGreaterThanOrEqual(15);
    expect(modalSeedCount()).toBeGreaterThanOrEqual(40);
    expect(ALL_DOCS.some((doc) => declaresId(doc, 'report_cancel_modal'))).toBe(true);
  });

  /**
   * @effects modal_seed_rendered_field_gap_is_detected
   */
  it('판정이 여는 쪽이 싣고 창이 그리는 제목을 시드가 빠뜨린 모양을 잡고, 조건에만 쓰는 키는 보지 않는다', () => {
    const fixture: ExtInput = {
      label: 'fixture',
      groups: [{ scope: { kind: 'modal', match: 'm1' }, items: [{ id: 'filled', initialState: { global: { box: { id: 1 } } } }] }],
      docs: [
        { id: 'm1', type: 'composite', name: 'Modal', children: [{ name: 'P', if: '{{!!_global.box?.title && !_global.box?.busy}}', text: '{{raw:_global.box?.title}}' }] },
        { actions: [{ handler: 'setState', params: { target: 'global', box: { id: '{{r.id}}', title: '{{r.title}}', busy: '{{false}}' } } }] },
      ],
    };
    expect(findModalSeedGaps(fixture)).toEqual(['fixture m1 :: filled global.box missing=title']);
  });

  /**
   * @effects error_seed_non_message_is_detected
   */
  it('판정이 422 오류 맵의 필드 값이 숫자인 모양을 잡고, 문구 목록과 상태 플래그는 통과시킨다', () => {
    const fixture = {
      label: 'fixture',
      groups: [
        {
          scope: { kind: 'modal', match: 'm2' },
          items: [
            { id: 'bad', initialState: { global: { form: { errors: { message: 'x', errors: { amount: ['a'], user_id: 1 } } } } } },
            { id: 'flag', initialState: { global: { loginErrors: { permanent: false, email: ['b'] } } } },
          ],
        },
      ],
    };
    expect(findErrorSeedShapeViolations(fixture)).toEqual(['fixture m2 :: bad.global.form.errors.errors.user_id=1']);
  });

  /**
   * @effects string_seed_read_as_locale_map_is_detected
   */
  it('판정이 언어 색인으로 읽는 자리에 문자열 시드를 둔 모양을 잡고, 맵 시드와 숫자 색인은 통과시킨다', () => {
    const fixture: ExtInput = {
      label: 'fixture',
      groups: [
        {
          scope: { kind: 'modal', match: 'm3' },
          items: [
            { id: 'bad', initialState: { global: { tag: { values: '샘플 값', first: 'a' } } } },
            { id: 'good', initialState: { global: { tag: { values: { ko: '빨강', en: 'Red' }, first: 'a' } } } },
          ],
        },
      ],
      docs: [{ id: 'm3', children: [{ name: 'Input', props: { value: '{{_global.tag?.values[localeItem.code] ?? \x27\x27}}' }, text: '{{_global.tag?.first?.[0]}}' }] }],
    };
    expect(findStringSeedsReadAsMap(fixture)).toEqual(['fixture m3 :: bad global.tag.values']);
  });

  /**
   * @effects empty_error_map_banner_is_detected
   */
  it('판정이 빈 오류 맵으로 오류 띠를 띄우는 모양을 잡고, 길이로 검사하는 조건과 null 시드는 통과시킨다', () => {
    const fixture: ExtInput = {
      label: 'fixture',
      groups: [
        {
          scope: { kind: 'modal', match: 'm4' },
          items: [
            { id: 'bad', initialState: { global: { form: { errors: {} } } } },
            { id: 'ok', initialState: { global: { form: { errors: null }, other: { errors: {} } } } },
          ],
        },
      ],
      docs: [{ id: 'm4', children: [{ name: 'Div', if: '{{_global.form?.errors}}' }, { name: 'Div', if: '{{Object.keys(_global.other?.errors ?? {}).length > 0}}' }] }],
    };
    expect(findEmptyErrorMapBanners(fixture)).toEqual(['fixture m4 :: bad global.form.errors']);
  });

  /**
   * @effects filled_modal_without_list_items_is_detected
   */
  it('판정이 채움 상태가 창의 상태 목록을 하나도 채우지 않은 모양을 잡고, 상수 반복과 다른 상태 항목은 보지 않는다', () => {
    const fixture: ExtInput = {
      label: 'fixture',
      groups: [
        {
          scope: { kind: 'modal', match: 'm5' },
          items: [
            { id: 'modal_filled', initialState: { local: { coupons: { data: [] } }, global: { stars: [] } } },
            { id: 'empty', initialState: { local: { coupons: [] } } },
          ],
        },
        {
          scope: { kind: 'modal', match: 'm6' },
          items: [{ id: 'modal_filled', initialState: { global: { picked: [{ id: 1 }] } } }],
        },
      ],
      docs: [
        { id: 'm5', children: [{ iteration: { source: '{{($parent._local.coupons)?.data ?? []}}' } }, { iteration: { source: '{{[1,2,3]}}' } }] },
        { id: 'm6', children: [{ iteration: { source: '{{_global.results ?? []}}' } }, { iteration: { source: '{{_global.picked ?? []}}' } }] },
      ],
    };
    expect(findFilledModalsWithoutListItems(fixture)).toEqual([]);
    fixture.groups[0].items[0].initialState = { local: {} };
    expect(findFilledModalsWithoutListItems(fixture)).toEqual(['fixture m5 :: modal_filled']);
  });

  /**
   * @effects placeholder_image_seed_is_detected
   */
  it('판정이 그림 키의 자리표시 값을 잡고, 링크 주소와 실제 주소는 통과시킨다', () => {
    const fixture = {
      label: 'fixture',
      groups: [
        {
          scope: { kind: 'modal', match: 'm7' },
          items: [
            { id: 'bad', initialState: { global: { selectedModule: { icon_url: '/sample', github_changelog_url: '/sample' }, menu: { icon: '샘플 값' } } } },
            { id: 'ok', initialState: { global: { selectedModule: { icon_url: '/api/extensions/module/sirsoft-board/icon' }, menu: { icon: 'fas fa-folder' } } } },
          ],
        },
      ],
    };
    expect(findPlaceholderImageSeeds(fixture)).toEqual([
      'fixture m7 :: bad.global.selectedModule.icon_url=/sample',
      'fixture m7 :: bad.global.menu.icon=샘플 값',
    ]);
  });

  /**
   * @effects scalar_item_read_as_object_is_detected
   */
  it('판정이 항목을 객체로 읽는 상태 목록에 문자열 항목을 둔 모양을 잡고, 글자로 그리는 목록과 객체 항목은 통과시킨다', () => {
    const fixture: ExtInput = {
      label: 'fixture',
      groups: [
        {
          scope: { kind: 'modal', match: 'm8' },
          items: [
            { id: 'bad', initialState: { global: { picked: { deps: ['샘플 값'], tags: ['a'] } } } },
            { id: 'ok', initialState: { global: { picked: { deps: [{ name: '게시판', version: '>=1.0.0' }], tags: ['a'] } } } },
          ],
        },
      ],
      docs: [
        {
          id: 'm8',
          children: [
            { iteration: { source: '_global.picked.deps', item_var: 'dep' }, text: '$t:x.required|version={{dep.version}}' },
            { iteration: { source: '_global.picked.tags', item_var: 'tag' }, text: '{{tag}}' },
            // 계산식 원천은 시드 항목을 바꿔 돈다 — 문자열 시드라도 보지 않는다
            { iteration: { source: '(function() { return (_global.picked?.tags ?? []).map(t => ({ t })); })()', item_var: 'row' }, text: '{{row.t}}' },
          ],
        },
      ],
    };
    expect(findScalarItemsReadAsObjects(fixture)).toEqual(['fixture m8 :: bad global.picked.deps (dep.*)']);
  });
});

describe('창 상태 표본 시드 — 모양', () => {
  /**
   * @effects modal_seed_carries_rendered_fields
   */
  it('창 표본 시드는 여는 쪽이 싣고 창이 글자로 그리는 하위 키를 모두 담는다', () => {
    expect(EXTS.flatMap((e) => findModalSeedGaps(e, ALL_DOCS))).toEqual([]);
  });

  /**
   * @effects error_seed_is_message_list
   */
  it('검증 오류 시드는 필드별 문구 목록이다', () => {
    expect(EXTS.flatMap((e) => findErrorSeedShapeViolations(e))).toEqual([]);
  });

  /**
   * @effects string_seed_not_read_as_locale_map
   */
  it('언어 색인으로 읽는 자리에는 문자열이 아니라 언어별 맵 시드를 둔다', () => {
    expect(EXTS.flatMap((e) => findStringSeedsReadAsMap(e))).toEqual([]);
  });

  /**
   * @effects empty_error_map_does_not_render_banner
   */
  it('참/거짓으로 오류 띠를 띄우는 자리에는 빈 오류 맵이 아니라 null 을 둔다', () => {
    expect(EXTS.flatMap((e) => findEmptyErrorMapBanners(e))).toEqual([]);
  });

  /**
   * @effects filled_modal_fills_a_list
   */
  it('창의 「채움」 표본은 창이 도는 상태 목록 하나 이상을 채운다', () => {
    expect(EXTS.flatMap((e) => findFilledModalsWithoutListItems(e, ALL_DOCS))).toEqual([]);
  });

  /**
   * @effects image_seed_has_no_placeholder
   */
  it('그림을 싣는 키에는 생성기 자리표시 값을 두지 않는다', () => {
    expect(EXTS.flatMap((e) => findPlaceholderImageSeeds(e))).toEqual([]);
  });

  /**
   * @effects iteration_items_read_as_objects_are_seeded_as_objects
   */
  it('창이 항목을 객체로 읽는 상태 목록에는 객체 항목을 시드한다', () => {
    expect(EXTS.flatMap((e) => findScalarItemsReadAsObjects(e, ALL_DOCS))).toEqual([]);
  });
});

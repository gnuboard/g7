/**
 * stateModalSeedsOpenerValues.test.ts — 창을 연 화면이 담는 값을 읽는 창에 상태 표본이 있는지 정적 가드
 *
 * 창(modal)의 제목·안내 문구가 번역 인자(`$t:키|count={{_global.K…}}`)나 제목 식(`title="{{_global.K?.title}}"`)으로
 * 「창을 연 화면이 담아 둔 값」을 읽으면, 편집기는 그 클릭을 하지 않으므로 상태 표본이 그 값을 대신 담아야 한다.
 * 담지 않으면 오류 없이 캔버스에 자리표시 원문(`{{target}}`)·0건·빈 제목이 그려진다.
 *
 *  - 7.1.0 실측(영역 I2): 개인정보 직권 실행 창 3곳(환경설정·게시판·GDPR) 제목 「{{target}} — 직권 실행」, 통계 행 추이 창 6곳
 *    「{{label}} 추이」 + 오류 카드, 메뉴 역할 일괄 적용 확인 「메뉴 0개에 역할 「{{roles}}」」, 회원 역할 일괄 변경 「0명」,
 *    본인인증 창 「남은 시도: 0회」, 소급 익명화 「방문 0건·액션 0건」, 개인정보 대상 목록 창 3곳 빈 제목.
 *  - 7.1.0 실측(영역 I2): 템플릿 「제거」 창 표본이 제거를 마친 뒤 값(보존 사본 목록)을 기본 상태에 담아, 창이 늘 「제거 완료」
 *    모습으로 열려 안내·삭제 선택·단추를 편집할 수 없었다(모듈·플러그인 창은 빈 목록이라 정상).
 *
 * 판정 1: 번들 레이아웃의 창 노드가 번역 인자 안에서 `_global.K`·`_local.K`(창을 연 화면의 로컬)·`$parent._local.K` 를 읽으면, 어느 번들 스펙이든 그 창 id 의
 *         상태 묶음 항목이 K 를 `initialState.global`/`initialState.local` 에 담는다.
 * 판정 2: 창 제목이 `{{_global.K?.sub …}}` 로 읽는 K·sub 를 그 창 묶음의 기본 항목이 담는다.
 * 판정 3: 창 본문이 `{{!(_global.K?.length)}}` 로 「완료 전」 모습을 감추는 키 K 를 기본 항목이 비어 있지 않은 목록으로 담지 않는다.
 *
 * 이커머스 화면의 창(7.1.0 실측 범위 밖)은 기존 결손 목록(KNOWN_GAPS)으로만 허용한다 — 새 결손은 실패, 고친 결손이
 * 목록에 남아 있어도 실패(목록은 줄기만 한다).
 *
 * @since engine-v1.78.3
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, existsSync, readdirSync, statSync } from 'node:fs';
import { join, resolve, sep } from 'node:path';

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

/** 번들 확장별 레이아웃 문서. */
function loadLayoutDocs(): Array<{ ext: string; file: string; doc: Json }> {
  const out: Array<{ ext: string; file: string; doc: Json }> = [];
  for (const base of BUNDLE_BASES) {
    const absBase = resolve(ROOT, base);
    if (!existsSync(absBase)) continue;
    for (const ext of readdirSync(absBase)) {
      for (const dir of LAYOUT_DIRS) {
        for (const file of walkJson(join(absBase, ext, dir))) {
          try {
            out.push({ ext, file: file.split(sep).join('/'), doc: JSON.parse(readFileSync(file, 'utf8')) });
          } catch {
            // 레이아웃이 아닌 JSON 은 건너뛴다.
          }
        }
      }
    }
  }
  return out;
}

/** 번들 스펙의 창 상태 묶음 — 창 id 별 항목 목록. */
function loadModalStateItems(): Map<string, Json[]> {
  const files: string[] = [];
  for (const tpl of ['sirsoft-admin_basic', 'sirsoft-basic']) files.push(`templates/_bundled/${tpl}/editor-spec/states.json`);
  for (const base of ['modules/_bundled', 'plugins/_bundled']) {
    const absBase = resolve(ROOT, base);
    if (!existsSync(absBase)) continue;
    for (const ext of readdirSync(absBase)) files.push(`${base}/${ext}/editor-spec.json`);
  }
  const map = new Map<string, Json[]>();
  for (const rel of files) {
    const abs = resolve(ROOT, rel);
    if (!existsSync(abs)) continue;
    const spec = JSON.parse(readFileSync(abs, 'utf8'));
    const groups: Json[] = spec?.states?.groups ?? spec?.groups ?? [];
    for (const group of groups) {
      if (group?.scope?.kind !== 'modal' || typeof group.scope.match !== 'string') continue;
      const list = map.get(group.scope.match) ?? [];
      list.push(...(group.items ?? []));
      map.set(group.scope.match, list);
    }
  }
  return map;
}

/** 트리에서 창 노드(name=Modal, id 문자열)를 모은다. */
function collectModals(doc: Json, out: Json[] = []): Json[] {
  if (Array.isArray(doc)) {
    doc.forEach((child) => collectModals(child, out));
    return out;
  }
  if (!isPlainObject(doc)) return out;
  if (doc.name === 'Modal' && typeof doc.id === 'string') out.push(doc);
  Object.values(doc).forEach((child) => collectModals(child, out));
  return out;
}

/** 문자열 값 전부. */
function strings(node: Json, out: string[] = []): string[] {
  if (typeof node === 'string') out.push(node);
  else if (Array.isArray(node)) node.forEach((child) => strings(child, out));
  else if (isPlainObject(node)) Object.values(node).forEach((child) => strings(child, out));
  return out;
}

const OPENER_KEY = /(?:_global|\$parent\._local|(?<![.\w$])_local)\??\.([A-Za-z_]\w*)/g;

/** 번역 인자(`$t:키|p={{식}}`) 안에서 읽는 opener 상태 키. */
export function translationParamKeys(text: string): string[] {
  const keys: string[] = [];
  if (!/\$t:/.test(text)) return keys;
  for (const m of text.matchAll(/\|[A-Za-z_]\w*=\{\{([\s\S]*?)\}\}/g)) {
    for (const k of m[1].matchAll(OPENER_KEY)) keys.push(k[1]);
  }
  return keys;
}

/** 항목 시드가 담은 최상위 키. */
function seededKeys(items: Json[]): Set<string> {
  const keys = new Set<string>();
  for (const item of items) {
    for (const scope of ['global', 'local']) Object.keys(item?.initialState?.[scope] ?? {}).forEach((k) => keys.add(k));
  }
  return keys;
}

/** 기본 항목(default:true 또는 첫 항목). */
function defaultItem(items: Json[]): Json | undefined {
  return items.find((item) => item?.default === true) ?? items[0];
}

/**
 * 7.1.0 실측 범위 밖(이커머스 화면) 창의 기존 결손 — `창id :: 키`. 줄기만 한다.
 */
const KNOWN_GAPS = new Set<string>([
  'download_history_modal :: selectedItem',
  'modal_bulk_price :: bulkPriceUnit',
  'modal_bulk_price :: bulkPriceValue',
  'modal_bulk_price :: modules',
  'modal_bulk_stock :: bulkStockValue',
  'modal_claim_bulk_confirm :: claimBulkConfirmData',
  'modal_claim_images_clear :: claimImagesClearContext',
  'modal_excel_download :: excelDownloadContext',
  'extra_fee_template :: selectedTemplateIds',
  'cart_delete_confirm_modal :: deleteModal',
  'cart_option_change_modal :: matchedOption',
]);

const layoutDocs = loadLayoutDocs();
const modalItems = loadModalStateItems();

/** 판정 1 결손 — `창id :: 키` 집합. */
export function findUnseededTranslationParams(): Set<string> {
  const gaps = new Set<string>();
  for (const { doc } of layoutDocs) {
    for (const modal of collectModals(doc)) {
      const seeded = seededKeys(modalItems.get(modal.id) ?? []);
      for (const text of strings(modal)) {
        for (const key of translationParamKeys(text)) {
          if (!seeded.has(key)) gaps.add(`${modal.id} :: ${key}`);
        }
      }
    }
  }
  return gaps;
}

describe('창을 연 화면의 값을 읽는 창 — 상태 표본', () => {
  // @scenario source=fixture
  // @effects translation_param_opener_keys_are_extracted
  it('번역 인자 안의 opener 상태 키만 뽑는다', () => {
    expect(translationParamKeys("$t:a.b|target={{_global.privacyRun?.label ?? ''}}")).toEqual(['privacyRun']);
    expect(translationParamKeys('$t:a.b|count={{($parent._local.selected ?? []).length}}|x={{rows.length}}')).toEqual(['selected']);
    expect(translationParamKeys('{{_global.privacyRun?.label}}')).toEqual([]);
  });

  // @scenario source=bundled
  // @effects modal_translation_params_are_seeded
  it('판정 1 — 번역 인자로 읽는 opener 값을 창 상태 묶음이 담는다(이커머스 기존 결손 제외)', () => {
    const gaps = findUnseededTranslationParams();
    const fresh = [...gaps].filter((g) => !KNOWN_GAPS.has(g)).sort();
    expect(fresh).toEqual([]);
  });

  // @scenario source=bundled
  // @effects known_gap_list_only_shrinks
  it('판정 1 — 기존 결손 목록에 이미 고친 항목이 남아 있지 않다', () => {
    const gaps = findUnseededTranslationParams();
    const stale = [...KNOWN_GAPS].filter((g) => !gaps.has(g)).sort();
    expect(stale).toEqual([]);
  });

  // @scenario source=bundled
  // @effects modal_title_expression_is_seeded
  it('판정 2 — 창 제목 식이 읽는 opener 값을 기본 항목이 담는다', () => {
    const offenders: string[] = [];
    for (const { ext, doc } of layoutDocs) {
      for (const modal of collectModals(doc)) {
        const title = modal?.props?.title;
        if (typeof title !== 'string' || !title.includes('{{')) continue;
        // 빈 값으로 떨어지는 자리만 본다 — 제목 전체가 식 하나인 경우(빈 제목)와 번역 인자(자리표시 원문).
        // `?? '기본값'`·`$t(… + (x ?? 'notice'))` 처럼 기본값이 있는 식은 빈 칸이 되지 않는다.
        const sources: string[] = [];
        const whole = title.match(/^\{\{([\s\S]*)\}\}$/);
        if (whole && !/\?\?\s*'[^']+'/.test(whole[1])) sources.push(whole[1]);
        for (const m of title.matchAll(/\|[A-Za-z_]\w*=\{\{([\s\S]*?)\}\}/g)) sources.push(m[1]);
        for (const src of sources) {
          for (const m of src.matchAll(/_global\??\.([A-Za-z_]\w*)\??\.([A-Za-z_]\w*)/g)) {
            if (m[2] === 'length') continue;
            const item = defaultItem(modalItems.get(modal.id) ?? []);
            const seed = item?.initialState?.global?.[m[1]];
            if (!isPlainObject(seed) || !(m[2] in seed)) offenders.push(`${ext} ${modal.id} :: ${m[1]}.${m[2]}`);
          }
        }
      }
    }
    expect([...new Set(offenders)].filter((o) => !/sirsoft-ecommerce/.test(o)).sort()).toEqual([]);
  });

  // @scenario source=bundled
  // @effects default_state_does_not_seed_completed_branch
  it('판정 3 — 기본 항목이 「완료 뒤」 목록을 채워 창 본문을 감추지 않는다', () => {
    const offenders: string[] = [];
    for (const { ext, doc } of layoutDocs) {
      for (const modal of collectModals(doc)) {
        const hiddenBy = new Set<string>();
        for (const text of strings(modal)) {
          for (const m of text.matchAll(/\{\{\s*!\(\s*_global\.([A-Za-z_]\w*)\?\.length\s*\)\s*\}\}/g)) hiddenBy.add(m[1]);
        }
        if (!hiddenBy.size) continue;
        const item = defaultItem(modalItems.get(modal.id) ?? []);
        for (const key of hiddenBy) {
          const seed = item?.initialState?.global?.[key];
          if (Array.isArray(seed) && seed.length > 0) offenders.push(`${ext} ${modal.id} :: ${key}`);
        }
      }
    }
    expect(offenders.sort()).toEqual([]);
  });
});

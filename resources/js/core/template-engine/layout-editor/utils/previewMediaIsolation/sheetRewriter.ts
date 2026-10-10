/**
 * 미리보기 매체 격리 — 규칙 트리 순회·치환·복원
 *
 * 폭 `@media` 블록을 같은 자리에서 둘로 나눈다.
 *   (가) 원본: 자식 스타일 규칙의 선택자 끝에 `:where(:not(.g7le-preview-frame *))` — 프레임 밖에서만 적용
 *   (나) 바로 뒤 복제본: `[@media <비폭 조건> {] @container g7le-preview <폭 조건> { 원본 자식 } [}]`
 *        — 이름이 붙은 컨테이너 쿼리는 그 컨테이너의 자손만 판정하므로 가드가 필요 없다
 * 원래 인덱스 자리에 넣어 다른 규칙과의 소스 순서·`@layer` 소속이 그대로다. `:where()` 는 우선순위 0 이다.
 *
 * `vw` 를 쓰는 스타일 규칙(폭 `@media` 밖)도 원본 가드 + 프레임 안 복제본(INSIDE 가드, 변수 치환)으로 나눈다.
 *
 * DOM 전역에 의존하지 않고 CSSOM 규칙 객체만 다룬다(덕 타이핑) — 가짜 규칙 트리로 테스트할 수 있다.
 * 처리한 규칙은 기록해 두고 언마운트 때 역순으로 원문을 되돌린다.
 *
 * @since engine-v1.75.2
 */

import { analyzeMediaQueryList } from './mediaQuery';
import type { MediaQueryEntry } from './mediaQuery';
import { guardSelectorList } from './selectorGuard';
import { hasViewportWidthUnits, rewriteViewportWidthUnits } from './vwRewrite';
import {
  INSIDE_GUARD,
  MEDIA_ISOLATION_OPT_OUT_ATTR,
  OUTSIDE_GUARD,
  PREVIEW_CONTAINER_NAME,
} from './constants';

/** 규칙 목록을 가진 객체(시트·그룹 규칙·중첩 가능한 스타일 규칙) */
interface RuleContainer {
  cssRules: ArrayLike<any>;
  insertRule(rule: string, index?: number): number;
  deleteRule(index: number): void;
}

/** 되돌리기 기록 */
type RewriteRecord =
  | { kind: 'selector'; rule: any; original: string }
  | { kind: 'inserted'; container: RuleContainer; rule: any }
  | { kind: 'replaced'; container: RuleContainer; rule: any; originalText: string };

/** 재작성 상태 — 훅 수명 동안 유지 */
export interface RewriteState {
  records: RewriteRecord[];
  /** 이미 처리했거나 우리가 넣은 규칙 */
  processed: WeakSet<object>;
  /** 시트별 마지막 처리 직후 규칙 수 — 늘었으면 다시 훑는다 */
  ruleCounts: WeakMap<object, number>;
  /** 건너뛰거나 변환 불가로 이미 센 시트 — 재실행마다 다시 세지 않는다 */
  countedSheets: WeakSet<object>;
}

/** 실행 통계 */
export interface RewriteStats {
  sheets: number;
  converted: number;
  unsupported: number;
  skipped: number;
}

/** 재작성 옵션 */
export interface RewriteOptions {
  /** rem/em 의 px 값(브라우저 기본 글꼴 크기) */
  remPx: number;
  /** 교차 출처 등으로 건너뛴 시트를 알릴 때(선택) */
  onSkip?: (reason: string) => void;
  /**
   * `vw` 규칙을 훑을지 — 시트마다 내부에서 정한다. `<style>` 원문에 `vw` 가 없으면 규칙마다 선언을
   * 직렬화하는 비용(실측 18,665규칙 57ms)을 건너뛴다.
   * @internal
   */
  scanVw?: boolean;
}

/**
 * 빈 재작성 상태를 만든다.
 *
 * @returns 상태
 */
export function createRewriteState(): RewriteState {
  return { records: [], processed: new WeakSet(), ruleCounts: new WeakMap(), countedSheets: new WeakSet() };
}

const isStyleRule = (rule: any): boolean => rule != null && typeof rule.selectorText === 'string';
const isMediaRule = (rule: any): boolean =>
  rule != null && rule.media != null && typeof rule.media.mediaText === 'string' && 'cssRules' in rule && !('styleSheet' in rule);
const isImportRule = (rule: any): boolean => rule != null && 'styleSheet' in rule && 'href' in rule;
const isKeyframesRule = (rule: any): boolean =>
  rule != null && (typeof rule.findRule === 'function' || typeof rule.appendRule === 'function');
const isGroupingRule = (rule: any): boolean =>
  rule != null && 'cssRules' in rule && typeof rule.insertRule === 'function' && !isKeyframesRule(rule);

/** 중첩 선언(CSSNestedDeclarations) — 선택자 없이 선언만 가진 규칙. 스타일 규칙 문맥 안에서만 생긴다 */
const isNestedDeclarations = (rule: any, insideStyle: boolean): boolean =>
  insideStyle && rule != null && !isStyleRule(rule) && rule.style != null && !('cssRules' in rule) && !isKeyframesRule(rule);

/** 이미 우리 흔적이 있는 텍스트 */
const hasOwnMarker = (text: string | undefined): boolean => typeof text === 'string' && text.includes(PREVIEW_CONTAINER_NAME);

/**
 * 컨테이너 안에서 규칙의 현재 인덱스를 찾는다.
 *
 * @param container 규칙 목록
 * @param rule 규칙
 * @returns 인덱스, 없으면 -1
 */
function indexOfRule(container: RuleContainer, rule: any): number {
  const rules = container.cssRules;
  for (let i = 0; i < rules.length; i++) {
    if (rules[i] === rule) return i;
  }
  return -1;
}

/**
 * 복제본에 넣을 자식 규칙 원문을 만든다 — `vw` 를 쓰는 평범한 스타일 규칙은 변수로 치환한다.
 *
 * @param rule 원본 자식 규칙
 * @returns 원문, 복제하지 않을 규칙이면 null
 */
function cloneChildText(rule: any): string | null {
  // 선택자 없는 at-rule(@keyframes·@font-face·@page)은 원본에만 둔다 — 복제하면 이름이 중복 정의된다
  if (isKeyframesRule(rule)) return null;
  const typeName: string = rule?.constructor?.name ?? '';
  if (typeName === 'CSSFontFaceRule' || typeName === 'CSSPageRule' || typeName === 'CSSFontFeatureValuesRule') return null;

  if (isStyleRule(rule) && !(rule.cssRules?.length > 0) && rule.style && hasViewportWidthUnits(rule.style.cssText)) {
    return `${rule.selectorText} { ${rewriteViewportWidthUnits(rule.style.cssText)} }`;
  }

  return rule.cssText;
}

/**
 * 블록 안의 스타일 규칙 선택자에 가드를 붙인다(중첩 스타일 규칙 포함).
 *
 * 중첩 선언(스타일 규칙 안 `@media` 의 선언)은 `&:where(...) { 선언 }` 규칙으로 바꾼다. 선언을 다시
 * 끼워 넣는 CSSOM 경로는 브라우저 지원이 고르지 않으므로, 그 경우의 복원은 호출자가 `@media` 규칙
 * 전체를 원문으로 교체한다(`onNested`).
 *
 * @param container 블록
 * @param insideStyle 스타일 규칙 문맥 안인지
 * @param pending 이 블록에서 만든 기록(실패 시 되돌림용)
 * @param onNested 중첩 선언을 처음 바꾸기 직전에 부르는 콜백
 * @returns 모두 받아들여졌으면 true
 */
function guardStyleRules(
  container: RuleContainer,
  insideStyle: boolean,
  pending: RewriteRecord[],
  onNested: () => void,
): boolean {
  const rules = container.cssRules;

  for (let i = rules.length - 1; i >= 0; i--) {
    const rule = rules[i];

    if (isStyleRule(rule)) {
      const original: string = rule.selectorText;
      const guarded = guardSelectorList(original, OUTSIDE_GUARD);
      if (guarded === null) return false;

      if (guarded !== original) {
        rule.selectorText = guarded;
        if (!String(rule.selectorText).includes(OUTSIDE_GUARD)) {
          // 브라우저가 거부 — 같은 블록의 앞선 변경을 되돌리게 실패로 알린다
          return false;
        }
        pending.push({ kind: 'selector', rule, original });
      }

      if (rule.cssRules && rule.cssRules.length > 0 && !guardStyleRules(rule, true, pending, onNested)) {
        return false;
      }
      continue;
    }

    if (isNestedDeclarations(rule, insideStyle)) {
      onNested();
      const declarations: string = rule.style.cssText;
      container.deleteRule(i);
      container.insertRule(`&${OUTSIDE_GUARD} { ${declarations} }`, i);
      continue;
    }

    if (isGroupingRule(rule)) {
      if (!guardStyleRules(rule, insideStyle, pending, onNested)) return false;
    }
  }

  return true;
}

/**
 * 기록을 역순으로 되돌린다.
 *
 * @param records 기록
 */
function undoRecords(records: RewriteRecord[]): void {
  for (let i = records.length - 1; i >= 0; i--) {
    const record = records[i];
    try {
      if (record.kind === 'selector') {
        record.rule.selectorText = record.original;
      } else if (record.kind === 'inserted') {
        const index = indexOfRule(record.container, record.rule);
        if (index >= 0) record.container.deleteRule(index);
      } else {
        const index = indexOfRule(record.container, record.rule);
        if (index >= 0) {
          record.container.deleteRule(index);
          record.container.insertRule(record.originalText, index);
        }
      }
    } catch {
      // 시트가 이미 문서에서 빠졌으면 되돌릴 대상이 없다
    }
  }
}

/**
 * 복제본 텍스트를 만든다.
 *
 * @param entry 조건 항목
 * @param body 자식 원문
 * @returns `@container` (+ 바깥 `@media`) 규칙 텍스트
 */
function buildClone(entry: MediaQueryEntry, body: string): string {
  const inner = `@container ${PREVIEW_CONTAINER_NAME} ${entry.containerCondition} { ${body} }`;
  return entry.outerMedia ? `@media ${entry.outerMedia} { ${inner} }` : inner;
}

/**
 * 폭 `@media` 블록 하나를 나눈다.
 *
 * @returns 'converted' | 'unsupported'
 */
function convertMediaBlock(
  container: RuleContainer,
  index: number,
  media: any,
  entries: MediaQueryEntry[],
  insideStyle: boolean,
  state: RewriteState,
): 'converted' | 'unsupported' {
  // 복제본 본문은 가드를 붙이기 전의 원문이다
  const body = Array.from(media.cssRules as ArrayLike<any>)
    .map((rule) => cloneChildText(rule))
    .filter((text): text is string => text !== null)
    .join(' ');

  const pending: RewriteRecord[] = [];
  const originalMediaText: string = media.cssText;
  let nestedRecorded = false;
  const onNested = (): void => {
    if (nestedRecorded) return;
    nestedRecorded = true;
    // 되돌릴 때 @media 규칙 전체를 원문으로 교체한다 — 역순 복원에서 가장 마지막에 실행되도록 맨 앞에 둔다
    pending.unshift({ kind: 'replaced', container, rule: media, originalText: originalMediaText });
  };

  try {
    if (!guardStyleRules(media, insideStyle, pending, onNested)) {
      undoRecords(pending);
      return 'unsupported';
    }

    if (body.trim() !== '') {
      entries.forEach((entry, offset) => {
        const at = index + 1 + offset;
        container.insertRule(buildClone(entry, body), at);
        const inserted = container.cssRules[at];
        state.processed.add(inserted);
        pending.push({ kind: 'inserted', container, rule: inserted });
      });
    }
  } catch {
    undoRecords(pending);
    return 'unsupported';
  }

  state.processed.add(media);
  state.records.push(...pending);

  return 'converted';
}

/**
 * `vw` 를 쓰는 스타일 규칙을 원본(가드) + 프레임 안 복제본으로 나눈다.
 *
 * @returns 'converted' | 'unsupported'
 */
function convertVwRule(container: RuleContainer, index: number, rule: any, state: RewriteState): 'converted' | 'unsupported' {
  if (rule.cssRules && rule.cssRules.length > 0) {
    // 중첩 규칙을 가진 스타일 규칙 — 원본을 가드하면 중첩 규칙까지 프레임 안에서 꺼지므로 손대지 않는다
    return 'unsupported';
  }

  const original: string = rule.selectorText;
  const outside = guardSelectorList(original, OUTSIDE_GUARD);
  const inside = guardSelectorList(original, INSIDE_GUARD);
  if (outside === null || inside === null) return 'unsupported';

  const pending: RewriteRecord[] = [];

  try {
    rule.selectorText = outside;
    if (!String(rule.selectorText).includes(OUTSIDE_GUARD)) {
      rule.selectorText = original;
      return 'unsupported';
    }
    pending.push({ kind: 'selector', rule, original });

    container.insertRule(`${inside} { ${rewriteViewportWidthUnits(rule.style.cssText)} }`, index + 1);
    const inserted = container.cssRules[index + 1];
    state.processed.add(inserted);
    pending.push({ kind: 'inserted', container, rule: inserted });
  } catch {
    undoRecords(pending);
    return 'unsupported';
  }

  state.processed.add(rule);
  state.records.push(...pending);

  return 'converted';
}

/**
 * 규칙 목록을 뒤에서 앞으로 훑는다(삽입으로 인덱스가 밀리지 않게).
 */
function processRuleList(
  container: RuleContainer,
  insideStyle: boolean,
  insideWidthMedia: boolean,
  state: RewriteState,
  options: RewriteOptions,
  stats: RewriteStats,
): void {
  const rules = container.cssRules;

  for (let i = rules.length - 1; i >= 0; i--) {
    const rule = rules[i];
    if (!rule || state.processed.has(rule)) continue;

    if (isImportRule(rule)) {
      const mediaText: string = rule.media?.mediaText ?? '';
      if (mediaText.trim() !== '' && mediaText.trim().toLowerCase() !== 'all') {
        stats.unsupported++;
        continue;
      }
      if (rule.styleSheet) processSheet(rule.styleSheet, state, options, stats);
      continue;
    }

    if (isMediaRule(rule)) {
      const mediaText: string = rule.media.mediaText;
      if (hasOwnMarker(mediaText)) continue;

      const analysis = analyzeMediaQueryList(mediaText, options.remPx);
      if (analysis.kind === 'convertible' && !insideWidthMedia) {
        const result = convertMediaBlock(container, i, rule, analysis.entries, insideStyle, state);
        stats[result === 'converted' ? 'converted' : 'unsupported']++;
        continue;
      }

      if (analysis.kind === 'unsupported') stats.unsupported++;
      processRuleList(rule, insideStyle, insideWidthMedia || analysis.kind === 'convertible', state, options, stats);
      continue;
    }

    if (isStyleRule(rule)) {
      if (hasOwnMarker(rule.selectorText)) continue;

      if (!insideWidthMedia && options.scanVw !== false && rule.style && hasViewportWidthUnits(rule.style.cssText)) {
        const result = convertVwRule(container, i, rule, state);
        stats[result === 'converted' ? 'converted' : 'unsupported']++;
      }

      if (rule.cssRules && rule.cssRules.length > 0) {
        processRuleList(rule, true, insideWidthMedia, state, options, stats);
      }
      continue;
    }

    if (isKeyframesRule(rule)) continue;

    if (isGroupingRule(rule)) {
      const prelude = typeof rule.conditionText === 'string' ? rule.conditionText : '';
      if (hasOwnMarker(prelude) || hasOwnMarker(rule.containerName)) continue;
      processRuleList(rule, insideStyle, insideWidthMedia, state, options, stats);
    }
  }
}

/**
 * 시트 하나를 처리한다.
 */
function processSheet(sheet: any, state: RewriteState, options: RewriteOptions, stats: RewriteStats): void {
  stats.sheets++;

  // 건너뛰는 시트는 한 번만 센다(재실행마다 누적되지 않게)
  const countOnce = (key: 'skipped' | 'unsupported'): void => {
    if (state.countedSheets.has(sheet)) return;
    state.countedSheets.add(sheet);
    stats[key]++;
  };

  if (sheet.ownerNode?.hasAttribute?.(MEDIA_ISOLATION_OPT_OUT_ATTR)) {
    countOnce('skipped');
    return;
  }

  let rules: ArrayLike<any>;
  try {
    rules = sheet.cssRules;
  } catch {
    // 교차 출처 시트 — CSSOM 을 읽을 수 없다
    countOnce('skipped');
    options.onSkip?.('cross-origin');
    return;
  }

  const sheetMedia: string = sheet.media?.mediaText ?? '';
  if (sheetMedia.trim() !== '' && sheetMedia.trim().toLowerCase() !== 'all') {
    // `<link media>`·`<style media>` 로 시트 전체에 조건이 걸림 — 변환하지 않는다
    countOnce('unsupported');
    return;
  }

  if (state.ruleCounts.get(sheet) === rules.length) {
    return;
  }

  // `<style>` 은 원문으로 vw 사용 여부를 먼저 본다(규칙별 선언 직렬화를 피한다). `<link>`·adopted 시트는 원문이 없어 규칙별로 본다.
  const ownerText: unknown = sheet.ownerNode?.tagName === 'STYLE' ? sheet.ownerNode.textContent : null;
  // 원문이 비어 있으면(CSS-in-JS 가 insertRule 로만 채운 시트) 규칙별로 본다.
  const scanVw = typeof ownerText === 'string' && ownerText.trim() !== '' ? /\d(?:s|l|d)?vw/i.test(ownerText) : true;

  processRuleList(sheet as RuleContainer, false, false, state, { ...options, scanVw }, stats);
  state.ruleCounts.set(sheet, sheet.cssRules.length);
}

/**
 * 스타일시트들을 재작성한다. 이미 처리한 규칙은 건너뛰므로 여러 번 불러도 결과가 같다(멱등).
 *
 * @param sheets 대상 시트(`document.styleSheets` + `adoptedStyleSheets`)
 * @param state 재작성 상태
 * @param options 옵션
 * @returns 이번 실행 통계
 */
export function rewriteSheets(sheets: Iterable<any>, state: RewriteState, options: RewriteOptions): RewriteStats {
  const stats: RewriteStats = { sheets: 0, converted: 0, unsupported: 0, skipped: 0 };

  for (const sheet of sheets) {
    if (!sheet) continue;
    processSheet(sheet, state, options, stats);
  }

  return stats;
}

/**
 * 모든 재작성을 역순으로 되돌린다. 되돌린 뒤 상태는 비어 다시 쓸 수 있다.
 *
 * @param state 재작성 상태
 */
export function restoreAll(state: RewriteState): void {
  undoRecords(state.records);
  state.records = [];
  state.processed = new WeakSet();
  state.ruleCounts = new WeakMap();
  state.countedSheets = new WeakSet();
}

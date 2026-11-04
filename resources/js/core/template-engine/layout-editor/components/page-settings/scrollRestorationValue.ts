/**
 * scrollRestorationValue.ts — meta.scroll_restoration 값 다루기 (페이지 설정 [스크롤] 탭)
 *
 * 셸(PageSettingsModal)과 폼(ScrollRestorationForm)이 함께 쓰는 순수 함수. 병합 규칙은 서버
 * `LayoutService::mergeScrollRestoration` 과 같다 — 최상위·snapshot 은 키 단위로 자식 우선, containers 는
 * 목록이라 통째로 바꾼다.
 *
 * @since engine-v1.85.0
 */

/** meta.scroll_restoration 부분 설정 (모든 키 선택) */
export interface ScrollRestorationValue {
  mode?: string;
  on_reload?: boolean;
  wait?: string;
  timeout_ms?: number;
  containers?: string[];
  snapshot?: {
    enabled?: boolean;
    ttl_seconds?: number;
    revalidate?: boolean;
    item_key?: string;
    /** 스냅샷과 함께 되돌릴 진행 상태 경로(`_global.*`·`_local.*`) — 목록이라 자식이 통째로 바꾼다 */
    state?: string[];
  };
  [key: string]: unknown;
}

function isPlainObject(v: unknown): v is Record<string, unknown> {
  return !!v && typeof v === 'object' && !Array.isArray(v);
}

/**
 * 객체면 그대로, 아니면 undefined
 *
 * @param value 임의 값
 * @return 스크롤 복원 설정 또는 undefined
 */
export function asScrollObject(value: unknown): ScrollRestorationValue | undefined {
  return isPlainObject(value) ? (value as ScrollRestorationValue) : undefined;
}

/**
 * own 정규화 — undefined 키·빈 snapshot 을 지우고, 남은 키가 없으면 undefined.
 *
 * @param value 편집 결과
 * @return 저장할 own 또는 undefined
 */
export function normalizeScrollOwn(value: ScrollRestorationValue | undefined): ScrollRestorationValue | undefined {
  if (!isPlainObject(value)) return undefined;
  const out: ScrollRestorationValue = {};
  for (const [k, v] of Object.entries(value)) {
    if (v === undefined) continue;
    if (k === 'snapshot') {
      if (!isPlainObject(v)) continue;
      const snap: Record<string, unknown> = {};
      for (const [sk, sv] of Object.entries(v)) {
        if (sv !== undefined) snap[sk] = sv;
      }
      if (Object.keys(snap).length > 0) out.snapshot = snap as ScrollRestorationValue['snapshot'];
      continue;
    }
    out[k] = v;
  }
  return Object.keys(out).length > 0 ? out : undefined;
}

/**
 * 부모 값 위에 자식 값을 덮는다 (최상위·snapshot 은 키 단위, containers 는 통째로).
 *
 * @param parent 부모 값
 * @param child 자식 값
 * @return 합친 값 (둘 다 없으면 undefined)
 */
export function mergeScrollLayers(
  parent: ScrollRestorationValue | undefined,
  child: ScrollRestorationValue | undefined,
): ScrollRestorationValue | undefined {
  const p = asScrollObject(parent);
  const c = asScrollObject(child);
  if (!p && !c) return undefined;
  if (!p) return { ...c };
  if (!c) return { ...p };
  const merged: ScrollRestorationValue = { ...p, ...c };
  if (isPlainObject(p.snapshot) && isPlainObject(c.snapshot)) {
    merged.snapshot = { ...p.snapshot, ...c.snapshot };
  }
  return merged;
}

/**
 * 병합본에서 own 이 적지 않은 키 = 상속분(베이스). snapshot 은 하위 키 단위.
 *
 * @param merged 병합본 값
 * @param own 이 레이아웃 값
 * @return 상속분 (없으면 undefined)
 */
export function deriveInheritedScroll(
  merged: ScrollRestorationValue | undefined,
  own: ScrollRestorationValue | undefined,
): ScrollRestorationValue | undefined {
  if (!isPlainObject(merged)) return undefined;
  const ownObj: Record<string, unknown> = isPlainObject(own) ? own : {};
  const base: ScrollRestorationValue = {};
  for (const [k, v] of Object.entries(merged)) {
    if (k === 'snapshot') {
      if (!isPlainObject(v)) continue;
      const ownSnap = isPlainObject(ownObj.snapshot) ? ownObj.snapshot : {};
      const snap: Record<string, unknown> = {};
      for (const [sk, sv] of Object.entries(v)) {
        if (!(sk in ownSnap)) snap[sk] = sv;
      }
      if (Object.keys(snap).length > 0) base.snapshot = snap as ScrollRestorationValue['snapshot'];
      continue;
    }
    if (!(k in ownObj)) base[k] = v;
  }
  return Object.keys(base).length > 0 ? base : undefined;
}

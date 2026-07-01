/**
 * pageStateSimulator.ts — 페이지 상태 시뮬레이터
 *
 * S6-1 범위: `applyInitialPatch` 의 **합성 로직만** 구현했다.
 *  페이지 상태의 `initialState.local`/`initialState.global` 을 의
 *  `sampleGlobal` baseline 위에 얹는 state-specific patch 로 합성한다.
 *
 * S6-3 범위: 캔버스 툴바 상태 토글(`PageStateSwitcher`)이 본 시뮬레이터를 결선한다.
 *   - `resolveSampleOverride(item, dataSourceId)` — sampleData 오버라이드 어댑터.
 *     활성 상태의 `sampleDataOverrides`(EditorSampleDataSpec) 를 sampleDataProvider
 *  우선순위 1·2 단계로 끼워 넣는다. 통째 교체.
 *  - `getFormErrors(item)` — 폼 검증 실패 시뮬레이션. `formErrors` 맵의
 *  **키를 상태 경로로 해석**한다. 실제 번들 템플릿이 검증
 *     오류를 `_local.errors.email`(배열) / `_global.loginErrors.email` 등 레이아웃마다
 *     다른 일반 상태 경로에서 읽어 표현하기 때문이다. FormContext.errors 라는 별도
 *     주입 메커니즘은 코드베이스에 존재하지 않아(전수 실측), 사용자 페이지와 동일한
 *     화면을 보장하려면 작성자가 정확한 경로를 지정해야 한다(사용자 페이지 parity).
 *   - `applyInitialPatch` 가 local/global 패치 + formErrors 경로 주입을 한 번에 합성.
 *
 * 상태 전환 시 "이전 패치를 되돌리고 새 패치 적용"은 본 함수가
 * **불변 baseline 으로부터 매번 재계산**하는 방식으로 자연 달성된다 — 이전 패치를
 * 별도 캐싱/역적용할 필요가 없다. 호출자는 항상 원본 baseline 을 전달한다.
 *
 * @since engine-v1.50.0
 * @since engine-v1.50.0
 */

import type { EditorSampleDataSpec, EditorStateItemSpec } from '../spec/specTypes';

/** plain object 판정 — 배열/null 제외 */
function isPlainObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** 깊은 복제 (JSON-safe 시드 전제) */
function deepClone<T>(value: T): T {
  if (Array.isArray(value)) return value.map((v) => deepClone(v)) as unknown as T;
  if (isPlainObject(value)) {
    const out: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(value)) out[k] = deepClone(v);
    return out as unknown as T;
  }
  return value;
}

/**
 * patch 를 dst 에 deep merge (부분 머지). 배열/원시값은 통째 교체.
 */
function deepMergePatch(dst: Record<string, unknown>, patch: Record<string, unknown>): void {
  for (const [key, patchVal] of Object.entries(patch)) {
    const dstVal = dst[key];
    if (isPlainObject(patchVal) && isPlainObject(dstVal)) {
      deepMergePatch(dstVal, patchVal);
    } else {
      dst[key] = deepClone(patchVal);
    }
  }
}

/**
 * formErrors 경로를 세그먼트 배열로 분해한다.
 *
 * 점(`.`)을 칸막이(구분자)로 보되, **대괄호 표기**(`['...']`/`["..."]`)로 감싼
 * 부분은 점이 들어 있어도 **단일 리터럴 키**로 보존한다. `/shop/checkout` 의
 * 주문자 입력칸처럼 키 자체에 점이 박힌 필드(`_local.errors?.['orderer.name']`)를
 * 시뮬레이션하기 위함이다.
 *
 * 예) `errors['orderer.name']` → `['errors', 'orderer.name']`
 *     `errors.email`           → `['errors', 'email']`
 *     `a.b["x.y"].c`           → `['a', 'b', 'x.y', 'c']`
 *
 * @param path 분해할 경로 문자열
 * @return 세그먼트 배열(빈 세그먼트 제외)
 */
function tokenizePath(path: string): string[] {
  const segments: string[] = [];
  let buf = '';
  for (let i = 0; i < path.length; i += 1) {
    const ch = path[i];
    if (ch === '[') {
      // 대괄호 진입 — 앞서 모은 점 구분 세그먼트를 먼저 비운다.
      if (buf.length > 0) {
        segments.push(buf);
        buf = '';
      }
      const quote = path[i + 1];
      if (quote === "'" || quote === '"') {
        // 인용된 리터럴 키 — 닫는 같은 따옴표 + `]` 까지 통째로 한 세그먼트.
        const close = path.indexOf(`${quote}]`, i + 2);
        if (close !== -1) {
          segments.push(path.slice(i + 2, close));
          i = close + 1; // `]` 까지 소비
          continue;
        }
      }
      // 인용 없는 대괄호(`[0]` 등)는 닫는 `]` 까지 키로 취급(배열 인덱스 미지원 — 키로 둠).
      const close = path.indexOf(']', i + 1);
      if (close !== -1) {
        segments.push(path.slice(i + 1, close));
        i = close;
        continue;
      }
      // 닫는 괄호 없음 — 글자 그대로 누적(방어적).
      buf += ch;
    } else if (ch === '.') {
      if (buf.length > 0) {
        segments.push(buf);
        buf = '';
      }
    } else {
      buf += ch;
    }
  }
  if (buf.length > 0) segments.push(buf);
  return segments.filter((s) => s.length > 0);
}

/**
 * 경로를 따라 dst 에 값을 deep set (없으면 객체 생성). 배열 인덱스 미지원.
 *
 * 경로는 점(`.`) 구분자에 더해 대괄호 표기(`['점.포함.키']`)를 지원한다 — `tokenizePath` 참조.
 */
function setByPath(dst: Record<string, unknown>, path: string, value: unknown): void {
  const segments = tokenizePath(path);
  if (segments.length === 0) return;
  let cursor: Record<string, unknown> = dst;
  for (let i = 0; i < segments.length - 1; i += 1) {
    const seg = segments[i];
    const next = cursor[seg];
    if (!isPlainObject(next)) {
      // 기존 값이 객체가 아니면(원시/배열/부재) 새 객체로 교체 후 진입
      const created: Record<string, unknown> = {};
      cursor[seg] = created;
      cursor = created;
    } else {
      // baseline 공유 방지 — 진입 경로는 항상 복제본으로 교체
      const cloned = deepClone(next);
      cursor[seg] = cloned;
      cursor = cloned;
    }
  }
  cursor[segments[segments.length - 1]] = deepClone(value);
}

/**  페이지 상태 1건의 초기 상태 패치 */
export interface PageStateInitialPatch {
  local?: Record<string, unknown>;
  global?: Record<string, unknown>;
  /** URL 쿼리 컨텍스트 오버라이드 (진입 맥락 변종 — `{{query.error}}`/`{{query.tab}}` 등) */
  query?: Record<string, unknown>;
  /** path param 컨텍스트 오버라이드 (`{{route.id}}` 있음↔없음 = 수정↔신규). null 값=토큰 제거 */
  route?: Record<string, unknown>;
}

/** applyInitialPatch 합성 결과 */
export interface AppliedInitialState {
  /** baseline `_global` 위에 state.global 을 부분 머지한 결과 */
  global: Record<string, unknown>;
  /** baseline `_local` 위에 state.local 을 부분 머지한 결과 */
  local: Record<string, unknown>;
  /** baseline query 위에 state.query 를 부분 머지한 결과 */
  query: Record<string, unknown>;
  /** baseline route 위에 state.route 를 부분 머지한 결과 (null 값 키는 제거) */
  route: Record<string, unknown>;
}

export interface ApplyInitialPatchOptions {
  /**
   * `_global` baseline — 의 sampleGlobal deep merge 체인 결과.
   * 본 함수는 baseline 을 변경하지 않는다(깊은 복제 후 패치).
   */
  globalBaseline?: Record<string, unknown>;
  /** `_local` baseline — 일반적으로 빈 객체 또는 레이아웃 initLocal */
  localBaseline?: Record<string, unknown>;
  /** query baseline — 일반적으로 빈 객체(편집기 평소 query 없음) */
  queryBaseline?: Record<string, unknown>;
  /** route baseline — sampleRouteParams(path param 자동 샘플값) */
  routeBaseline?: Record<string, unknown>;
  /** 선택된 페이지 상태의 초기 패치 (items[].initialState) */
  patch?: PageStateInitialPatch | null;
  /**
   * 폼 검증 실패 시뮬레이션 — 키가 상태 경로(`_local.errors.email` /
   * `_global.loginErrors.email`), 값이 표시 메시지(또는 배열)인 맵 (formErrors).
   * `_local.` 으로 시작하는 경로는 local baseline 의 `_local.` 제거 후 주입, `_global.`
   * 으로 시작하는 경로는 global baseline 에 `_global.` 제거 후 주입, 접두사 없는 경로는
   * local 에 그대로 주입(레거시 단순 필드명 호환).
   */
  formErrors?: Record<string, unknown> | null;
  /**
   * 편집 모드 게이트 — false 면 패치를 적용하지 않고 baseline 을 그대로 반환
   * (일반 사이트 렌더 보호). 기본 true.
   */
  isEditMode?: boolean;
}

/**
 *  sampleGlobal baseline 위에 페이지 상태의 초기 패치를 부분 머지.
 *
 * - 불변 baseline 으로부터 매번 재계산하므로 상태 전환 시 이전 패치가 자동으로
 *  되돌려진다.
 * - `global` 패치는 globalBaseline 위에, `local` 패치는 localBaseline 위에 머지.
 * - `isEditMode === false` 면 baseline 을 그대로 반환 (no-op — 디그레이드).
 * - patch/formErrors 미정의(states 미선언/scope 미매칭)면 baseline 만 반환.
 *   patch 와 formErrors 는 독립 — 한쪽만 있어도 그쪽만 적용한다.
 *
 * @param options 합성 옵션
 * @return 합성된 `{ global, local }`
 */
export function applyInitialPatch(options: ApplyInitialPatchOptions): AppliedInitialState {
  const global = deepClone(options.globalBaseline ?? {});
  const local = deepClone(options.localBaseline ?? {});
  const query = deepClone(options.queryBaseline ?? {});
  const route = deepClone(options.routeBaseline ?? {});

  // 편집 모드 외 → baseline 그대로 (일반 사이트 렌더 보호 — 디그레이드)
  if (options.isEditMode === false) {
    return { global, local, query, route };
  }

  if (options.patch) {
    if (isPlainObject(options.patch.global)) {
      deepMergePatch(global, options.patch.global);
    }
    if (isPlainObject(options.patch.local)) {
      deepMergePatch(local, options.patch.local);
    }
    // query 패치 — baseline(빈 객체) 위 머지. 진입 맥락 변종(?error/?tab/?category 등).
    if (isPlainObject(options.patch.query)) {
      deepMergePatch(query, options.patch.query);
    }
    // route 패치 — path param 오버라이드. null 값 키는 토큰 제거(신규 작성 모드 — route.id
    // 자동 샘플값 무력화 → `{{!route.id}}` 분기 미리보기).
    if (isPlainObject(options.patch.route)) {
      for (const [key, val] of Object.entries(options.patch.route)) {
        if (val === null) {
          delete route[key];
        } else {
          route[key] = deepClone(val);
        }
      }
    }
  }

  // formErrors 경로 주입.
  // 키가 `_global.` 으로 시작 → global baseline 에 접두사 제거 후 주입.
  // 키가 `_local.` 으로 시작 → local baseline 에 접두사 제거 후 주입.
  // 접두사 없음 → local 에 그대로 주입(레거시 단순 필드명 호환 — 예: "email").
  if (isPlainObject(options.formErrors)) {
    for (const [path, message] of Object.entries(options.formErrors)) {
      if (path.startsWith('_global.')) {
        setByPath(global, path.slice('_global.'.length), message);
      } else if (path.startsWith('_local.')) {
        setByPath(local, path.slice('_local.'.length), message);
      } else {
        setByPath(local, path, message);
      }
    }
  }

  return { global, local, query, route };
}

/**
 *  페이지 상태의 sampleData 오버라이드 어댑터.
 *
 * 활성 상태 item 의 `sampleDataOverrides`(EditorSampleDataSpec — sampleData 와 동일
 * 구조) 를 반환한다. sampleDataProvider 는 이 오버라이드를 우선순위의 가장 앞
 * (1·2 단계)에 끼워 넣어 매칭 시 **통째 교체**한다(부분 머지 아님).
 *
 * @param item 활성 페이지 상태 item (null 이면 오버라이드 없음)
 * @return 오버라이드 스펙 또는 undefined(미정의/디그레이드)
 */
export function resolveSampleOverride(
  item: EditorStateItemSpec | null | undefined,
): EditorSampleDataSpec | undefined {
  if (!item || !isPlainObject(item.sampleDataOverrides)) return undefined;
  const ov = item.sampleDataOverrides as EditorSampleDataSpec;
  // 빈 객체({})는 "오버라이드 없음"과 동일 — undefined 로 디그레이드.
  const hasAny =
    isPlainObject(ov.byDataSourceId) ||
    isPlainObject(ov.byEndpointPattern) ||
    isPlainObject((ov as { bySource?: unknown }).bySource);
  return hasAny ? ov : undefined;
}

/**
 *  페이지 상태의 폼 검증 실패 맵.
 *
 * 키가 상태 경로(`_local.errors.email` / `_global.loginErrors.email`), 값이 표시
 * 메시지(또는 배열)인 맵을 반환한다. `applyInitialPatch` 의 `formErrors` 옵션으로
 * 전달돼 경로별로 baseline 에 주입된다. 미정의/빈 맵이면 undefined(no-op 디그레이드).
 *
 * @param item 활성 페이지 상태 item (null 이면 폼 오류 없음)
 * @return formErrors 맵 또는 undefined
 */
export function getFormErrors(
  item: EditorStateItemSpec | null | undefined,
): Record<string, unknown> | undefined {
  if (!item || !isPlainObject(item.formErrors)) return undefined;
  const map = item.formErrors as Record<string, unknown>;
  return Object.keys(map).length > 0 ? map : undefined;
}

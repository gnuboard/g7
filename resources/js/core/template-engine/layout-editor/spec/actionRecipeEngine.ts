/**
 * actionRecipeEngine.ts — 친화 액션 레시피 ↔ `actions` JSON 변환
 *
 * 속성 모달 "동작" 탭이 친화 명칭 + 친화 입력값으로 핸들러 `actions` JSON 을
 * 생성하고(`buildAction`), 기존 `actions` 항목에서 어떤 레시피로 만들어졌는지
 * 역해석한다(`matchAction`). 핸들러명/파라미터 키는 유저에게 노출하지 않는다
 * (12.2.1) — 본 엔진이 유일하게 둘 사이를 번역한다.
 *
 * `build` 틀의 값에 든 `{{paramKey}}`(중괄호 2개)는 그 파라미터 입력값으로
 * 치환된다. 단순 문자열 토큰(`"{{url}}"`)이면 값으로 통째 치환(타입 보존), 문자열
 * 일부면 문자열 보간한다. `onSuccess`/`onError` 가 `action-list` 파라미터를 가리키면
 * 치환 결과는 **중첩 액션 배열**이다(12.2.3 — 문자열 보간 아님).
 *
 * 생성 JSON 은 코어 핸들러 규칙(API/핸들러 호출)을 위반하지 않는다.
 * `assertHandlerRules` 가 잘못된 핸들러명/위치를 dev 경고로 잡는다(레시피 작성자
 * 가드). 코어는 핸들러를 가정하지 않으므로(원칙 4.8) 레시피의 `build.handler` 를
 * 그대로 쓰되, 알려진 금지 패턴만 경고한다.
 *
 * 모든 함수는 순수 함수 — 입력을 변경하지 않는다.
 *
 * @since engine-v1.50.0
 */

import type { ActionRecipeSpec, ActionBuildTemplate, ActionRecipeParamSpec } from './specTypes';
import { createLogger } from '../../../utils/Logger';

const logger = createLogger('ActionRecipeEngine');

/** 정규화된 액션 레시피 — 단축형/표준형을 공통 형태로 */
export interface NormalizedActionRecipe {
  id: string;
  label?: string;
  params: ActionRecipeParamSpec[];
  build: ActionBuildTemplate;
}

/** 코어 금지 → 올바른 핸들러 매핑 (레시피 작성 가드) */
const HANDLER_ALIASES: Record<string, string> = {
  api: 'apiCall',
  nav: 'navigate',
  showToast: 'toast',
  setLocalState: 'setState',
};

/** `comment` 등 레시피가 아닌 메타 키 */
const NON_RECIPE_KEYS = new Set(['comment']);

/**
 * actionRecipes 블록을 정규화 목록으로. 단축형(`{label,handler}`)은 빈 params +
 * `build:{handler}` 로 흡수한다. `comment` 등 메타 키는 제외.
 *
 * @param recipes editor-spec 의 actionRecipes 블록
 * @return 정규화된 레시피 목록 (선언 순서 보존)
 */
export function normalizeActionRecipes(
  recipes: Record<string, ActionRecipeSpec | string> | undefined | null,
): NormalizedActionRecipe[] {
  if (!recipes || typeof recipes !== 'object') return [];
  const out: NormalizedActionRecipe[] = [];
  for (const [id, raw] of Object.entries(recipes)) {
    if (NON_RECIPE_KEYS.has(id)) continue;
    if (!raw || typeof raw !== 'object') continue;
    const spec = raw as ActionRecipeSpec;
    const build: ActionBuildTemplate | undefined =
      spec.build && typeof spec.build === 'object'
        ? (spec.build as ActionBuildTemplate)
        : typeof spec.handler === 'string'
          ? { handler: spec.handler }
          : undefined;
    if (!build || typeof build.handler !== 'string' || build.handler.length === 0) {
      // 핸들러가 없으면 빌드 불가 — 무시(작성자 가드).
      continue;
    }
    out.push({
      id,
      label: typeof spec.label === 'string' ? spec.label : undefined,
      params: Array.isArray(spec.params) ? spec.params : [],
      build,
    });
  }
  return out;
}

/** `{{key}}` 한 토큰만으로 이뤄진 문자열인지 — 값 통째 치환(타입 보존) 대상 */
function soleBindingKey(str: string): string | null {
  const m = /^\{\{\s*([\w.]+)\s*\}\}$/.exec(str);
  return m ? m[1] : null;
}

/**
 * build 틀 객체의 스프레드 키 — `{ "...": "{{paramKey}}" }` 가 그 파라미터(객체)를 부모로 펼친다.
 * setState 의 상태 payload 처럼 키 집합이 가변인 동적 맵을 부모(params)에 흡수할 때 쓴다. 일반
 * 핸들러 build 는 고정 키만 쓰므로 영향 없다(이 키를 쓰는 빌드만 스프레드 분기 진입).
 */
const SPREAD_KEY = '...';

/** 문자열 내 모든 `{{key}}` 를 values 로 보간 (문자열 결과) */
function interpolate(str: string, values: Record<string, unknown>): string {
  return str.replace(/\{\{\s*([\w.]+)\s*\}\}/g, (_whole, key: string) => {
    const v = values[key];
    return v === undefined || v === null ? '' : String(v);
  });
}

/**
 * build 틀의 한 값에 파라미터를 치환.
 *
 * - `"{{key}}"` 단독 → values[key] 통째(배열/객체/숫자 등 타입 보존). action-list
 *   파라미터면 중첩 액션 배열이 그대로 들어간다(12.2.3).
 * - `"...{{key}}..."` 부분 → 문자열 보간.
 * - 객체/배열 → 재귀.
 * - 그 외 → 그대로.
 */
function substituteValue(value: unknown, values: Record<string, unknown>): unknown {
  if (typeof value === 'string') {
    const sole = soleBindingKey(value);
    if (sole !== null) {
      // 값이 없으면(미입력) 토큰을 제거 — undefined 반환해 호출자가 키를 떨군다.
      return sole in values ? values[sole] : undefined;
    }
    if (value.includes('{{')) return interpolate(value, values);
    return value;
  }
  if (Array.isArray(value)) {
    return value.map((v) => substituteValue(v, values)).filter((v) => v !== undefined);
  }
  if (value && typeof value === 'object') {
    const out: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(value as Record<string, unknown>)) {
      // 스프레드 키(`"..."` → `"{{paramKey}}"`) — 그 파라미터 입력값(객체)을 부모로 펼친다.
      // setState 의 상태 payload 처럼 키 집합이 가변인 동적 맵을 params 에 흡수할 때 쓴다.
      // 값이 객체가 아니면(미입력/타입 불일치) 무시.
      if (k === SPREAD_KEY && typeof v === 'string') {
        const sole = soleBindingKey(v);
        const spreadVal = sole !== null ? values[sole] : undefined;
        if (spreadVal && typeof spreadVal === 'object' && !Array.isArray(spreadVal)) {
          for (const [sk, svv] of Object.entries(spreadVal as Record<string, unknown>)) {
            if (svv === undefined || sk === '') continue;
            out[sk] = svv;
          }
        }
        continue;
      }
      const sv = substituteValue(v, values);
      // 빈 후속(빈 배열) / 미입력 키는 떨군다 — 깔끔한 JSON.
      if (sv === undefined) continue;
      if (Array.isArray(sv) && sv.length === 0 && (k === 'onSuccess' || k === 'onError')) continue;
      // 동적 키 — `{{paramKey}}` 키는 입력값으로 치환(setState 의 상태 키 등). 미입력이면 떨군다.
      const sole = soleBindingKey(k);
      const outKey = sole !== null ? (sole in values ? String(values[sole]) : null) : k;
      if (outKey === null || outKey === '') continue;
      // 치환 결과가 빈 객체(예: 모든 하위 키 미입력된 params)면 떨군다.
      if (sv && typeof sv === 'object' && !Array.isArray(sv) && Object.keys(sv).length === 0) continue;
      out[outKey] = sv;
    }
    // 빈 params/객체는 떨군다(불필요한 `params: {}` 잔존 방지).
    return out;
  }
  return value;
}

/**
 * 알려진 핸들러 금지 패턴을 dev 경고로 잡고 올바른 핸들러로 교정.
 * (코어 핸들러 규칙 — 레시피 작성자 가드. 코어는 핸들러를 강제하지 않되
 *  명백한 금지 별칭만 교정한다.)
 *
 * @param action 빌드된 액션 1건
 * @return 교정된 액션(별칭 교정 시 새 사본)
 */
export function assertHandlerRules(action: Record<string, unknown>): Record<string, unknown> {
  const handler = action.handler;
  if (typeof handler !== 'string') return action;
  const corrected = HANDLER_ALIASES[handler];
  if (corrected) {
    logger.warn(
      `action recipe used forbidden handler "${handler}" — corrected to "${corrected}" (core handler rules)`,
    );
    return { ...action, handler: corrected };
  }
  // apiCall 의 target/onSuccess/onError 가 params 안에 잘못 들어간 경우 경고만(교정은
  // build 작성자 책임 — 끌어올리면 의미가 바뀔 수 있어 경고에 그친다).
  if (handler === 'apiCall' || handler === 'navigate') {
    const params = action.params;
    if (params && typeof params === 'object') {
      for (const k of ['target', 'onSuccess', 'onError']) {
        if (k in (params as Record<string, unknown>)) {
          logger.warn(
            `action recipe build placed "${k}" inside params for handler "${handler}" — should be top-level (core handler rules)`,
          );
        }
      }
    }
  }
  return action;
}

/**
 * 친화 입력값으로 액션 JSON 1건을 생성.
 *
 * placeholder 핸들러 recipe(build.handler 가 `{{paramKey}}`)는 그 입력값이 비면 substituteValue
 * 가 handler 를 undefined 로 떨궈 키 자체가 사라진다. "+동작 추가" 는 buildAction(recipe, {}) 로
 * 빈 값 빌드하므로(ActionAddPicker), 그대로 두면 handler 없는 빈 액션이 되어 추가 직후 "알 수
 * 없는 동작"(advanced)으로 강등된다. 따라서 빈 빌드에서 handler 가 사라지면 build.handler 의
 * placeholder 토큰을 그대로 복원해 둔다 — matchAction 이 placeholder-aware 로 친화 카드 매칭하고,
 * 사용자가 데이터 칩으로 핸들러를 채우면 그 값으로 치환된다(라운드트립 무손실).
 *
 * @param recipe 정규화 레시피
 * @param values 파라미터 키 → 입력값 (action-list 파라미터는 액션 배열)
 * @return 핸들러 `actions` 항목 1건
 */
export function buildAction(
  recipe: NormalizedActionRecipe,
  values: Record<string, unknown>,
): Record<string, unknown> {
  const built = substituteValue(recipe.build, values) as Record<string, unknown>;
  // 빈 입력으로 placeholder 핸들러가 떨어져 사라진 경우 — build.handler 토큰을 보존.
  if (typeof built.handler !== 'string' && recipe.build.handler.includes('{{')) {
    built.handler = recipe.build.handler;
  }
  // required placeholder param 보존 — placeholder 핸들러 recipe(build.handler 가 `{{...}}`)에 한해,
  // required param 의 sole-binding 토큰이 미입력으로 떨어지면 build 틀의 토큰을 복원한다. 이런 recipe
  // (결제 진입)는 matchAction 의 구조 fingerprint(placeholderRecipeStructureMatches)가 params 의
  // 토큰 존재에 의존하므로, 핸들러 필드를 임의 데이터 칩으로 바꾸고 다른 required 값을 비워둬도 친화
  // 카드가 [고급]으로 강등되지 않게 토큰을 유지한다(제보 회귀). 일반 recipe(리터럴 핸들러,
  // apiCall 등)는 미입력 required 를 그대로 떨궈 깔끔한 JSON 정책을 지킨다 — 그쪽은 핸들러 일치만으로
  // 매칭되므로 토큰 보존이 필요 없다.
  if (recipe.build.handler.includes('{{')) {
    const requiredKeys = new Set(recipe.params.filter((p) => p.required).map((p) => p.key));
    if (requiredKeys.size > 0) {
      restoreRequiredPlaceholders(recipe.build, built, requiredKeys);
    }
  }
  return assertHandlerRules(built);
}

/**
 * build 틀을 따라가며 required param 의 sole-binding 토큰이 결과(built)에서 사라졌으면 복원한다.
 *
 * build 틀의 sole-binding 문자열(`{{key}}`)이 required param 을 가리키는데 결과 객체에 그 키가
 * 없으면(미입력으로 substituteValue 가 떨굼) build 토큰을 그대로 되살린다. 객체/배열은 재귀.
 * handler 는 buildAction 이 별도 처리하므로 여기선 그 외 위치만 본다.
 *
 * @param tmpl build 틀(부분)
 * @param out 치환 결과(부분, 제자리 수정)
 * @param requiredKeys required param 키 집합
 */
function restoreRequiredPlaceholders(
  tmpl: unknown,
  out: unknown,
  requiredKeys: Set<string>,
): void {
  if (!tmpl || typeof tmpl !== 'object' || Array.isArray(tmpl)) return;
  if (!out || typeof out !== 'object' || Array.isArray(out)) return;
  const t = tmpl as Record<string, unknown>;
  const o = out as Record<string, unknown>;
  for (const [k, v] of Object.entries(t)) {
    if (k === 'handler') continue; // buildAction 이 별도 보존
    if (typeof v === 'string') {
      const sole = soleBindingKey(v);
      if (sole !== null && requiredKeys.has(sole) && !(k in o)) {
        o[k] = v; // 미입력으로 떨어진 required 토큰 복원
      }
    } else if (v && typeof v === 'object') {
      // 중첩 객체 — 결과에도 같은 키가 객체로 있어야 재귀(없으면 빈 객체로 만들어 복원).
      if (!(k in o) || typeof o[k] !== 'object' || o[k] === null) {
        // 결과에서 통째로 떨어진 중첩 — required 토큰이 하나라도 있으면 빈 객체로 살린 뒤 재귀.
        const hasRequired = JSON.stringify(v).includes('{{');
        if (hasRequired) o[k] = {};
        else continue;
      }
      restoreRequiredPlaceholders(v, o[k], requiredKeys);
    }
  }
}

/**
 * 기존 액션 1건이 어떤 레시피로 만들어졌는지 역해석 (동작 탭 진입 시 현재값 복원).
 *
 * 핸들러명 일치 + (있다면) build 의 고정 키 일치로 후보를 좁힌다. 파라미터 값은
 * build 의 `{{key}}` 위치에서 역추출한다. 매칭 실패 시 null — 호출자가 "고급
 * 동작"(코드 편집기 작성)으로 분류한다(원칙 4.4).
 *
 * @param action 기존 액션 1건
 * @param recipes 정규화 레시피 목록
 * @return { recipeId, values } 또는 null
 */
export function matchAction(
  action: Record<string, unknown>,
  recipes: NormalizedActionRecipe[],
): { recipeId: string; values: Record<string, unknown> } | null {
  if (!action || typeof action !== 'object') return null;
  const handler = action.handler;
  if (typeof handler !== 'string') return null;

  // 핸들러 일치 레시피 중, build 의 고정 값이 모두 일치하고 가장 많은 파라미터를
  // 역추출하는 레시피를 고른다(가장 구체적인 매칭 우선).
  let best: { recipeId: string; values: Record<string, unknown> } | null = null;
  let bestScore = -1;
  for (const recipe of recipes) {
    // 핸들러 비교는 placeholder-aware. build.handler 가 리터럴(`{{` 미포함)이면 정확 일치를
    // 요구하지만, `{{paymentHandler}}` 같은 placeholder 면 실제 핸들러명(동적 바인딩 등)을
    // extractValues 가 그 파라미터로 역추출한다(결제 진입 recipe — handler 가 응답값 칩).
    const handlerIsPlaceholder = recipe.build.handler.includes('{{');
    if (!handlerIsPlaceholder && recipe.build.handler !== handler) continue;
    // placeholder 핸들러 recipe 는 핸들러명만으로는 어떤 액션에도 느슨히 걸린다(greedy).
    // 자기 고유 구조(handler 외 build params 의 sole-binding 키)가 실제 액션에 실재해야만
    // 후보로 인정한다 — 그렇지 않으면 setState/toast 등 무관한 리터럴 액션을 흡수한다.
    if (handlerIsPlaceholder && !placeholderRecipeStructureMatches(recipe.build, action)) {
      continue;
    }
    const extracted = extractValues(recipe.build, action);
    if (extracted === null) continue;
    const score = Object.keys(extracted).length;
    if (score > bestScore) {
      bestScore = score;
      best = { recipeId: recipe.id, values: extracted };
    }
  }
  return best;
}

/**
 * placeholder 핸들러 recipe 의 "고유 구조" 가 실제 액션에 실재하는지.
 *
 * build.params 의 sole-binding 값(`{{key}}`)을 가진 키들이 실제 액션 params 에 모두
 * 존재해야 한다(예: 결제 진입 recipe 의 `params.pgPaymentData`). 이 가드가 없으면
 * placeholder 핸들러가 params 만 있으면 무엇이든 매칭하는 greedy 문제가 생긴다.
 *
 * @param build recipe build 틀
 * @param action 실제 액션
 * @return 고유 구조가 실재하면 true
 */
function placeholderRecipeStructureMatches(
  build: ActionBuildTemplate,
  action: Record<string, unknown>,
): boolean {
  // 빈 카드 정확 일치 — handler 가 build.handler placeholder 토큰과 글자 그대로 같으면(아직
  // 데이터 칩으로 안 채운 "+동작 추가" 직후 상태) 이 recipe 의 빈 카드가 확실하다. 임의 액션은
  // 그 정확한 placeholder 토큰(`{{paymentHandler}}`)을 handler 로 가질 수 없으므로 greedy 위험이
  // 없다. params 가 아직 없어도(빈 빌드) 친화 카드로 매칭해 "알 수 없는 동작" 강등을 막는다.
  if (typeof action.handler === 'string' && action.handler === build.handler) {
    return true;
  }
  const buildParams = build.params;
  if (!buildParams || typeof buildParams !== 'object') {
    // params 없는 placeholder recipe(핸들러만) — 구조 fingerprint 없음. 보수적으로 불인정.
    return false;
  }
  const actionParams = action.params;
  if (!actionParams || typeof actionParams !== 'object') return false;
  const a = actionParams as Record<string, unknown>;
  // build params 의 sole-binding 키(고정 키 제외)가 실제 params 에 모두 존재해야 한다.
  const soleKeys = Object.entries(buildParams).filter(
    ([, v]) => typeof v === 'string' && soleBindingKey(v) !== null,
  );
  if (soleKeys.length === 0) return false;
  return soleKeys.every(([k]) => k in a);
}

/**
 * build 틀과 실제 액션을 대조해 파라미터 값을 역추출.
 * 고정 값(플레이스홀더 없는 리터럴)이 불일치하면 null(이 레시피 아님).
 */
function extractValues(
  template: unknown,
  actual: unknown,
): Record<string, unknown> | null {
  const values: Record<string, unknown> = {};
  const walk = (tmpl: unknown, act: unknown): boolean => {
    if (typeof tmpl === 'string') {
      const sole = soleBindingKey(tmpl);
      if (sole !== null) {
        values[sole] = act;
        return true;
      }
      if (tmpl.includes('{{')) {
        // 부분 보간 — 정확 역추출 불가, 존재만 확인(느슨한 매칭).
        return typeof act === 'string';
      }
      return tmpl === act; // 리터럴 — 정확 일치 요구
    }
    if (Array.isArray(tmpl)) {
      return Array.isArray(act);
    }
    if (tmpl && typeof tmpl === 'object') {
      if (!act || typeof act !== 'object' || Array.isArray(act)) return false;
      const a = act as Record<string, unknown>;
      // 스프레드 키(`"...": "{{paramKey}}"`) — 고정/명시 키가 아닌 나머지 실제 키 전부를
      // 그 파라미터(객체)로 역추출한다(setState 동적 payload 복원). 명시 키는 아래 루프가
      // 먼저 소비하므로, 여기선 템플릿에 열거되지 않은 키만 모은다.
      const spreadTmpl = (tmpl as Record<string, unknown>)[SPREAD_KEY];
      const spreadKey =
        typeof spreadTmpl === 'string' ? soleBindingKey(spreadTmpl) : null;
      const namedKeys = new Set(Object.keys(tmpl as Record<string, unknown>).filter((kk) => kk !== SPREAD_KEY));
      for (const [k, v] of Object.entries(tmpl as Record<string, unknown>)) {
        if (k === SPREAD_KEY) continue; // 스프레드는 루프 뒤 일괄 처리
        // onSuccess/onError 가 단독 바인딩이면 액션 배열을 그대로 담는다.
        if (typeof v === 'string' && soleBindingKey(v) !== null) {
          const sole = soleBindingKey(v)!;
          if (k in a) values[sole] = a[k];
          continue;
        }
        if (!(k in a)) {
          // 틀에 있으나 실제에 없는 키 — 미입력(빈 후속 등)으로 허용.
          // identity_target: 대부분의 apiCall 은 선언하지 않으므로(IDV 정책 대상 흐름만 선언),
          // 실제 노드에 없어도 매칭 실패로 떨어뜨리면 안 된다(→ advanced 분류 시 친화 폼 전체 소실).
          if (k === 'onSuccess' || k === 'onError' || k === 'params' || k === 'identity_target') continue;
          return false;
        }
        if (!walk(v, a[k])) return false;
      }
      if (spreadKey !== null) {
        const rest: Record<string, unknown> = {};
        for (const [ak, av] of Object.entries(a)) {
          if (!namedKeys.has(ak)) rest[ak] = av;
        }
        if (Object.keys(rest).length > 0) values[spreadKey] = rest;
      }
      return true;
    }
    return tmpl === act;
  };
  return walk(template, actual) ? values : null;
}

// ── 친화 요약 / 카드 해석 / 실행조건(if) — ──

/**
 * 액션 카드 — resolveActionCard 결과. 친화 스펙 매칭(preset)이면 어느 레시피·입력값
 * 으로 만들어졌는지, 미매칭이면 advanced(코드 편집기에서 작성됨)로 분류한다.
 */
export type ActionCard =
  | { kind: 'preset'; recipeId: string; values: Record<string, unknown>; handler: string }
  | { kind: 'advanced'; handler: string };

/**
 * 액션 1건을 카드 종류로 해석 — 친화 스펙 매칭 → preset / 미매칭 → advanced.
 *
 * `matchAction`(기존, 무변경) 을 그대로 호출한다. 매칭이 없으면 advanced 로 분류해
 * [화면 동작]·[동작] 탭이 "코드 편집기에서 작성됨"으로 보존·열람만 제공하게 한다.
 *
 * @param action 액션 JSON 1건
 * @param recipes 정규화된 레시피 목록(코어 시드 + 확장)
 * @return ActionCard
 */
export function resolveActionCard(
  action: Record<string, unknown>,
  recipes: NormalizedActionRecipe[],
): ActionCard {
  const handler = typeof action?.handler === 'string' ? action.handler : '';
  const matched = matchAction(action, recipes);
  if (matched) {
    return { kind: 'preset', recipeId: matched.recipeId, values: matched.values, handler };
  }
  return { kind: 'advanced', handler };
}

/**
 * 친화 한 줄 요약 합성 — 스펙 라벨 + 주요 입력값.
 *
 * 핸들러명·params 키 같은 기술 용어는 노출하지 않는다(12.2.1). 라벨(`$t:` 키)은
 * `t` 로 해석하고, 필수 입력값 일부를 괄호로 덧붙인다. `t` 미제공 시 라벨 원문(키)
 * 폴백. 값이 표현식/데이터칩(`{{ }}`)이면 그대로 보여 준다(친화 미해석 — 데이터 출처).
 *
 * @param recipe 정규화 레시피(라벨·params 보유)
 * @param values 친화 입력값(matchAction/buildAction values 와 동형)
 * @param t 다국어 해석 함수(없으면 라벨 키 폴백)
 * @return 한 줄 요약 문자열
 */
export function summarizeAction(
  recipe: Pick<NormalizedActionRecipe, 'label' | 'params'> | null | undefined,
  values: Record<string, unknown>,
  t?: (key: string) => string,
): string {
  const labelKey = recipe?.label;
  const label = labelKey ? (t ? t(labelKey) : labelKey) : '';
  // 필수(required) 입력 + 값이 있는 입력 중 앞 2개를 요약 꼬리표로.
  const parts: string[] = [];
  const params = recipe?.params ?? [];
  for (const p of params) {
    // 고급 항목 + 중첩 액션 트리(action-list: onSuccess/onError/actions 등)는 요약에서 제외.
    // action-list 는 친화 편집 가능(advanced 아님)이라도 값이 배열이라 요약 꼬리에 `[N]` 토큰이
    // 새어들면 카드 요약이 의미 없이 길어진다 — 트리형 입력은 카드 라벨만 보여준다.
    if (p.advanced || p.widget === 'action-list') continue;
    const v = values[p.key];
    if (v === undefined || v === null || v === '') continue;
    const text = stringifyValueForSummary(v);
    if (text === '') continue;
    parts.push(text);
    if (parts.length >= 2) break;
  }
  if (parts.length === 0) return label;
  return `${label} (${parts.join(', ')})`;
}

/** 요약용 값 직렬화 — 배열/객체는 축약, 문자열·숫자·불리언은 그대로 */
function stringifyValueForSummary(value: unknown): string {
  if (typeof value === 'string') return value;
  if (typeof value === 'number' || typeof value === 'boolean') return String(value);
  if (Array.isArray(value)) return `[${value.length}]`;
  if (value && typeof value === 'object') return '{…}';
  return '';
}

/**
 * 액션에 실행조건(top-level `if`)을 부착한 사본 반환.
 *
 * `if` 식 생성/역해석은 conditionRecipeEngine 이 SSoT — 본 함수는 그 결과(`{{ }}`
 * 한 쌍)를 액션 최상위 `if` 키에 얹기만 한다(노드 구조키는 props 가 아닌 최상위,
 * [[feedback_node_structural_keys_if_actions_are_top_level_not_props]]). 빈 식이면
 * `if` 키를 제거한 사본.
 *
 * @param action 액션 JSON 1건
 * @param ifExpr `{{ }}` 형태의 조건 식(빈 문자열이면 조건 제거)
 * @return `if` 가 반영된 새 액션 사본
 */
export function withActionIf(
  action: Record<string, unknown>,
  ifExpr: string | null | undefined,
): Record<string, unknown> {
  const next = { ...action };
  if (typeof ifExpr === 'string' && ifExpr.trim().length > 0) {
    next.if = ifExpr.trim();
  } else {
    delete next.if;
  }
  return next;
}

/**
 * 액션의 실행조건(top-level `if`) 식 추출.
 *
 * 역해석(조건 빌더 환원)은 conditionRecipeEngine 이 담당하므로, 본 함수는 `if` 식
 * 원문(`{{ }}` 포함)만 꺼낸다. 없으면 null.
 *
 * @param action 액션 JSON 1건
 * @return `if` 식 문자열 또는 null
 */
export function extractActionIf(action: Record<string, unknown>): string | null {
  const v = action?.if;
  return typeof v === 'string' && v.trim().length > 0 ? v : null;
}

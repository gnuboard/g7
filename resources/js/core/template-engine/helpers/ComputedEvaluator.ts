/**
 * computed 공용 평가기
 *
 * 레이아웃 `computed` 를 계산하는 모든 지점(렌더 DynamicRenderer · 액션 sequence 재계산 ·
 * 라우트 진입 TemplateApp · 편집기 미리보기)이 이 한 구현을 쓴다. 지점마다 규칙이 달라
 * 같은 computed 가 렌더 직후와 액션 직후에 다른 값이 되던 문제를 없앤다.
 *
 * 규칙
 * - 정의 순서대로 평가한다. 앞에 정의된 computed 는 같은 계산에서 최신 값(`_computed`/`$computed`),
 *   뒤에 정의된 키(와 자기 자신)를 읽으면 `previous`(직전 값)를 본다.
 * - 문자열은 앞뒤 공백을 떼고 `{{ … }}` 이면 식으로 평가한다. 단일 바인딩 판정은 BindingShape 정본
 *   (`extractSingleBinding`)을 쓴다 — `{{…}}` 모양인데 단일 바인딩이 아니면(`"{{a}} {{b}}"`) 평가 실패.
 *   파이프가 있으면 `evaluatePipeExpression`, 아니면 `evaluateExpression`.
 * - `{{ }}` 가 아닌 문자열은 원문 그대로 값이다. `$switch` 객체는 `resolveSwitch`. 그 밖의 타입은
 *   키를 만들지 않는다.
 * - 실패 정책은 호출 지점이 정한다(렌더·액션 = 직전 값 유지, 라우트 진입 = undefined).
 *
 * 이 파일은 안전 스위치(EngineFlags)를 읽지 않는다 — 평가 규칙 통일은 스위치 대상이 아니다.
 *
 * @package G7
 * @subpackage Template Engine
 * @since engine-v1.74.0
 */

import { hasPipes } from '../PipeRegistry';
import { extractSingleBinding } from '../BindingShape';
import type { DataBindingEngine } from '../DataBindingEngine';
import { createLogger } from '../../utils/Logger';

const logger = createLogger('ComputedEvaluator');

/** 평가 옵션 */
export interface EvaluateComputedOptions {
  /** 직전 `_computed` (없으면 {}) — 뒤쪽 키 참조와 keep-previous 실패 정책이 읽는다 */
  previous?: Record<string, any>;
  /** 실패 정책: 렌더·액션 = keep-previous, 라우트 진입 = undefined */
  onError: 'keep-previous' | 'undefined';
  /** DevTools 추적 콜백 — 없으면 시간 측정도 하지 않는다 */
  track?: (key: string, expr: unknown, value: unknown, error?: string, ms?: number) => void;
  /** 실패 로그 — 지점별 기존 문구를 유지하기 위해 호출부가 넘긴다 */
  failureLog?: (key: string, error: unknown) => void;
}

/** 키를 만들지 않는 정의(문자열·`$switch` 객체가 아닌 것) */
const NO_VALUE = Symbol('computed-no-value');

/**
 * 정의 1개를 평가한다. 실패하면 throw 한다.
 *
 * @param def computed 정의(문자열 또는 `$switch` 객체)
 * @param ctx 평가 컨텍스트
 * @param engine 바인딩 엔진
 * @return 평가 결과 또는 NO_VALUE
 */
function evaluateDefinition(def: unknown, ctx: Record<string, any>, engine: DataBindingEngine): unknown {
  if (typeof def === 'string') {
    const trimmed = def.trim();
    if (!(trimmed.startsWith('{{') && trimmed.endsWith('}}'))) {
      return def;
    }
    const inner = extractSingleBinding(trimmed);
    if (inner === null) {
      throw new Error(`Computed definition is not a single {{ }} binding: ${def}`);
    }
    // 파이프 표현식은 evaluatePipeExpression 으로 평가한다 — evaluateExpression 은 `|` 를 JS 비트 OR 로
    // 본다. 값 캐시를 거치지 않는다(skipCache) — computed 는 호출마다 최신 컨텍스트로 계산한다.
    return hasPipes(inner)
      ? engine.evaluatePipeExpression(inner, ctx, { skipCache: true })
      : engine.evaluateExpression(inner, ctx, { skipCache: true });
  }
  if (def && typeof def === 'object' && '$switch' in (def as object)) {
    return engine.resolveSwitch(def as any, ctx, { skipCache: true });
  }
  return NO_VALUE;
}

/**
 * 정의 1개를 평가한다(편집기 미리보기 등 단건 평가용). 실패하면 throw 한다.
 *
 * @param def computed 정의
 * @param ctx 평가 컨텍스트
 * @param engine 바인딩 엔진
 * @return 평가 결과(문자열·`$switch` 가 아닌 정의는 undefined)
 */
export function evaluateComputedEntry(def: unknown, ctx: Record<string, any>, engine: DataBindingEngine): unknown {
  const value = evaluateDefinition(def, ctx, engine);
  return value === NO_VALUE ? undefined : value;
}

/**
 * computed 정의 전체를 정의 순서대로 평가한다.
 *
 * 평가 컨텍스트는 호출당 1회 `{ ...baseContext, _computed: acc, $computed: acc }` 로 만들고,
 * 누적 객체 `acc` 는 `{ ...previous }` 로 시작해 키를 덮어쓴다. `previous` 는 변형하지 않는다.
 *
 * @param definitions computed 정의(키 → 정의)
 * @param baseContext 평가 기반 컨텍스트
 * @param engine 바인딩 엔진
 * @param options 평가 옵션
 * @return 새 `_computed` 객체
 */
export function evaluateComputedDefinitions(
  definitions: Record<string, unknown>,
  baseContext: Record<string, any>,
  engine: DataBindingEngine,
  options: EvaluateComputedOptions,
): Record<string, any> {
  const previous = options.previous ?? {};
  const acc: Record<string, any> = { ...previous };
  const ctx = { ...baseContext, _computed: acc, $computed: acc };
  const track = options.track;

  for (const [key, def] of Object.entries(definitions)) {
    const start = track ? Date.now() : 0;
    let error: string | undefined;
    try {
      const value = evaluateDefinition(def, ctx, engine);
      if (value === NO_VALUE) continue;
      acc[key] = value;
    } catch (e) {
      options.failureLog?.(key, e);
      acc[key] = options.onError === 'keep-previous' ? previous[key] : undefined;
      error = e instanceof Error ? e.message : String(e);
    }
    if (track) track(key, def, acc[key], error, Date.now() - start);
  }

  return acc;
}

/* ------------------------------------------------------------------ *
 * 물려받기 가능 여부 (D12)
 * ------------------------------------------------------------------ */

const inheritableCache = new WeakMap<object, boolean>();

/** `_computed.K` · `$computed?.K` · `_computed['K']` · `$computed?.["K"]` */
const REF_PATTERN = /(^|[^\w$])(?:\$computed|_computed)\s*(?:(?:\?\.|\.)\s*([A-Za-z_$][\w$]*)|(?:\?\.)?\[\s*(['"])([^'"]+)\3\s*\])/g;
/** 대상을 특정할 수 없는 `_computed[식]` 접근 */
const DYNAMIC_REF_PATTERN = /(^|[^\w$])(?:\$computed|_computed)\s*(?:\?\.)?\[\s*(?!['"])/;

function collectStrings(value: unknown, out: string[], depth = 0): void {
  if (depth > 20) return;
  if (typeof value === 'string') {
    out.push(value);
  } else if (value && typeof value === 'object') {
    for (const [k, v] of Object.entries(value as Record<string, unknown>)) {
      out.push(k);
      collectStrings(v, out, depth + 1);
    }
  }
}

/**
 * 정의 객체가 computed 스코프 물려받기를 허용하는지 판정한다.
 *
 * 어떤 정의가 ① 자기 자신 또는 자기보다 **뒤에** 정의된 키를 참조하거나 ② 키를 특정할 수 없는
 * `_computed[…]`·`$computed[…]` 접근을 포함하면 false 다. 그런 레이아웃은 지금 "부모가 방금 계산한 값을
 * 자식이 다시 읽는" 덕에 맞게 표시되므로, 물려받으면 루트 진입 시 값에 고정된다 — 그래서 물려받기를
 * 끄고 종전처럼 모든 렌더러가 계산한다. 정의 객체당 1회 문자열 스캔이며 의존성 추적이 아니다.
 *
 * @param definitions computed 정의 객체
 * @return 물려받기 허용 여부
 */
export function isInheritableDefinitions(definitions: Record<string, unknown>): boolean {
  const cached = inheritableCache.get(definitions);
  if (cached !== undefined) return cached;

  const keys = Object.keys(definitions);
  const order = new Map(keys.map((k, i) => [k, i]));
  let result = true;
  let offender: { from: string; to: string } | null = null;

  outer: for (let i = 0; i < keys.length; i++) {
    const texts: string[] = [];
    collectStrings(definitions[keys[i]], texts);
    for (const text of texts) {
      if (DYNAMIC_REF_PATTERN.test(text)) {
        result = false;
        offender = { from: keys[i], to: '[?]' };
        break outer;
      }
      REF_PATTERN.lastIndex = 0;
      let m: RegExpExecArray | null;
      while ((m = REF_PATTERN.exec(text)) !== null) {
        const target = m[2] ?? m[4];
        const idx = order.get(target);
        if (idx !== undefined && idx >= i) {
          result = false;
          offender = { from: keys[i], to: target };
          break outer;
        }
      }
    }
  }

  inheritableCache.set(definitions, result);
  if (!result && offender) {
    logger.warn(
      `[computed] "${offender.from}" 가 뒤에 정의된 "${offender.to}" 를 참조합니다 — 직전에 계산된 값(루트에서는 화면 진입 시 값)이 쓰이며, 이 레이아웃은 computed 스코프 공유가 꺼집니다. 정의 순서를 바꾸면 해소됩니다`,
    );
  }
  return result;
}

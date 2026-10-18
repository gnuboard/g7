/**
 * 바인딩 식의 **형태 판정** 단일 출처
 *
 * "이 문자열이 단일 바인딩인가", "이 식이 경로인가 표현식인가 파이프인가" 를 판정하는
 * 로직은 엔진 곳곳에 복제되어 있었고, 복사본마다 인식 문자 집합이 달라 **같은 식이
 * 어느 렌더 경로를 타느냐에 따라 다른 결과**를 냈다. 판정은 이 모듈 하나로 모은다.
 *
 * 통일하는 것은 **판정뿐**이다. 캐시 정책·예외 처리·DevTools 추적·후처리(번역, raw 마커)는
 * 지점마다 정당한 이유로 다르므로 각 호출 지점이 그대로 유지한다.
 *
 * 순수 함수만 두고 런타임 의존은 `PipeRegistry`(파이프 스캐너) 하나로 제한한다 —
 * `DataBindingEngine` 은 `import type` 으로만 참조해 순환 import 를 만들지 않는다.
 *
 * @since engine-v1.55.0
 */

import { hasPipes } from './PipeRegistry';
import type { BindingContext, BindingOptions, DataBindingEngine } from './DataBindingEngine';

/**
 * JavaScript 리터럴 값 매핑
 *
 * 경로 탐색(`resolve`)이 `true`/`null` 같은 리터럴을 컨텍스트 키로 오인하는 것을 막는다.
 * `undefined` 는 `Map.get()` 의 기본 반환값과 구분되지 않으므로 `has()` 로 판정한다.
 */
export const LITERALS = new Map<string, unknown>([
  ['true', true],
  ['false', false],
  ['null', null],
  ['undefined', undefined],
]);

/**
 * 바인딩 식의 형태
 *
 * - `empty`: `{{}}` — 식이 없음 (평가 대상 아님)
 * - `literal`: `true` / `false` / `null` / `undefined`
 * - `pipe`: 파이프(`|`)가 포함된 식
 * - `expression`: 연산자·괄호·리터럴 등이 포함된 JS 표현식
 * - `path`: 점/대괄호 경로 (`user.profile.name`, `items[0]`)
 */
export type ExpressionShape = 'empty' | 'literal' | 'pipe' | 'expression' | 'path';

/**
 * 문자열 전체가 단일 `{{ }}` 바인딩인지 판정하고 내부 식을 반환
 *
 * 판정 기준(정본):
 * - 따옴표(`'`, `"`, `` ` ``) 안의 중괄호는 세지 않는다 — `{{a['}']}}` 같은 식이 잘리지 않는다.
 * - 따옴표 안의 백슬래시 이스케이프(`'\''`)는 다음 한 글자를 소비한다.
 * - 중괄호 균형을 추적해, 외곽 바인딩이 중간에 닫히면(`"{{a}} {{b}}"`) 단일 바인딩이 아니다.
 * - 균형이 맞지 않으면(`{{a} }}`) 단일 바인딩으로 보지 않는다.
 * - 결과는 trim 한다.
 *
 * `{{}}` 는 빈 문자열을 반환한다 — "바인딩이긴 하나 식이 없음"이며, 호출 지점은
 * `classifyExpression` 이 돌려주는 `'empty'` 로 이 상태를 구분해 처리한다.
 *
 * @param value 검사할 문자열
 * @returns 단일 바인딩이면 내부 식(trim), 아니면 null
 */
export function extractSingleBinding(value: string): string | null {
  if (typeof value !== 'string') return null;
  if (!value.startsWith('{{') || !value.endsWith('}}') || value.length < 4) return null;

  const inner = value.slice(2, -2);
  let quote: string | null = null;
  let depth = 0;

  for (let i = 0; i < inner.length; i++) {
    const char = inner[i];

    if (quote !== null) {
      if (char === '\\') {
        i++; // 이스케이프된 문자 1개 소비
        continue;
      }
      if (char === quote) quote = null;
      continue;
    }

    if (char === '"' || char === "'" || char === '`') {
      quote = char;
      continue;
    }
    if (char === '{') {
      depth++;
      continue;
    }
    if (char === '}') {
      if (depth > 0) {
        depth--;
        continue;
      }
      // 깊이 0 에서의 `}` → 외곽 바인딩이 중간에 닫혔거나 균형이 맞지 않는다.
      return null;
    }
  }

  return depth === 0 ? inner.trim() : null;
}

/**
 * 문자열에서 찾은 `{{ }}` 바인딩 한 건
 */
export interface ScannedBinding {
  /** 원본 문자열에서 `{{` 가 시작하는 인덱스 */
  start: number;
  /** 원본 문자열에서 `}}` 다음 인덱스 (slice 종료 지점) */
  end: number;
  /** `{{ }}` 를 벗긴 내부 식 (trim 하지 않은 원본) */
  expr: string;
}

/**
 * 문자열 안의 모든 `{{ }}` 바인딩 위치를 스캔
 *
 * 정규식 `\{\{([^}]+)\}\}` 은 식 안에 `}` 가 들어가면(`{{(row.meta ?? {}) | json}}`)
 * 매칭에 실패한다. `String.replace` 는 매칭이 없으면 입력을 그대로 돌려주므로,
 * 그 결과는 예외도 경고도 없이 **원본 `{{...}}` 문자열이 화면에 노출**되는 것이었다.
 *
 * 이 스캐너는 따옴표와 중괄호 깊이를 추적해 바인딩의 끝을 정확히 찾는다.
 * 닫히지 않은 `{{` 는 바인딩으로 보지 않고 리터럴로 남긴다(종전 정규식과 같은 결과).
 *
 * @param template 검사할 문자열
 * @returns 발견된 바인딩 목록 (등장 순서)
 *
 * @since engine-v1.55.1
 */
export function scanBindings(template: string): ScannedBinding[] {
  const found: ScannedBinding[] = [];
  if (typeof template !== 'string') return found;

  for (let i = 0; i < template.length - 1; i++) {
    if (template[i] !== '{' || template[i + 1] !== '{') continue;

    let quote: string | null = null;
    let depth = 0;
    let closed = -1;

    for (let j = i + 2; j < template.length; j++) {
      const char = template[j];

      if (quote !== null) {
        if (char === '\\') {
          j++;
          continue;
        }
        if (char === quote) quote = null;
        continue;
      }

      if (char === '"' || char === "'" || char === '`') {
        quote = char;
        continue;
      }
      if (char === '{') {
        depth++;
        continue;
      }
      if (char === '}') {
        if (depth > 0) {
          depth--;
          continue;
        }
        if (template[j + 1] === '}') {
          closed = j;
          break;
        }
        // 깊이 0 에서 짝이 맞지 않는 `}` — 바인딩이 아니다.
        break;
      }
    }

    if (closed === -1) continue; // 닫히지 않음 → 리터럴로 취급

    found.push({ start: i, end: closed + 2, expr: template.slice(i + 2, closed) });
    i = closed + 1; // 루프의 i++ 와 합쳐 바인딩 다음으로 이동
  }

  return found;
}

/**
 * 복잡한 표현식 여부 판정
 *
 * true 면 `evaluateExpression`(JS 평가)으로, false 면 `resolve`(경로 탐색)로 보낸다.
 *
 * 인식 문자 집합은 종전 6개 방언 중 **최대집합**을 정본으로 삼는다. 좁은 방언은
 * 배열/객체 리터럴(`['a','b']`)이나 따옴표 키 인덱싱(`query['sales_status[]']`)을
 * "단순 경로" 로 오판해 경로 탐색으로 보냈고, 그 결과는 조용한 `undefined` 였다.
 *
 * `|`(파이프)도 집합에 포함되지만, 분류 순서상 파이프가 먼저 걸러지므로
 * 파이프 식이 이 판정에 도달하지 않는다(`classifyExpression` 참조).
 *
 * @param expr 판정할 식 (`{{ }}` 제거된 상태)
 * @returns 복잡한 표현식 여부
 */
export function isComplexExpression(expr: string): boolean {
  return /[?:|&!+\-*/<>=()[\]{}]/.test(expr);
}

/**
 * 바인딩 식의 형태 분류
 *
 * 분류 순서가 곧 라우팅 우선순위다: 빈 식 → 리터럴 → 파이프 → 표현식 → 경로.
 * 리터럴을 파이프보다 먼저 보는 이유는 `true`/`null` 이 경로로 해석되면 안 되기 때문이고,
 * 리터럴 문자열 자체에는 파이프가 들어갈 수 없어 순서 충돌이 없다.
 *
 * @param expr 분류할 식 (`{{ }}` 제거된 상태)
 * @returns 식의 형태
 */
export function classifyExpression(expr: string): ExpressionShape {
  const trimmed = typeof expr === 'string' ? expr.trim() : '';
  if (trimmed === '') return 'empty';
  if (LITERALS.has(trimmed)) return 'literal';
  if (hasPipes(trimmed)) return 'pipe';
  if (isComplexExpression(trimmed)) return 'expression';
  return 'path';
}

/**
 * 단일 바인딩 값 해석 옵션
 */
export interface ResolveSingleBindingOptions {
  /** 캐시 사용 안 함 (반복 렌더 등) */
  skipCache?: boolean;

  /** DevTools 표현식 추적 정보 */
  trackingInfo?: { componentId?: string; componentName?: string; propName?: string };

  /**
   * 평가 실패 시 호출된다. 반환값이 해석 결과가 된다.
   * 미지정이면 예외를 그대로 전파한다 — 실패 정책은 호출 지점의 몫이다.
   */
  onError?: (error: unknown, expr: string) => unknown;

  /**
   * 빈 바인딩(`{{}}`)을 만났을 때 호출된다. 미지정이면 조용히 `undefined` 를 반환한다.
   */
  onEmpty?: (expr: string) => void;

  // `format` 옵션은 두지 않는다.
  //
  // 이 함수는 **판정과 라우팅**만 담당하고 결과를 원본 타입으로 돌려준다. 서식은
  // 호출 지점마다 규칙이 다르다 — `text` 노드는 문자열이 필요하고, prop 은 배열/객체/
  // boolean 을 그대로 넘겨야 한다(원본 타입 소실이 engine-v1.54.9 에서 고친 결함이다).
  // 여기에 서식을 얹으면 그 결함을 옵션 형태로 되살리는 셈이라, 서식은 호출 지점에 남긴다.
}

/**
 * 단일 바인딩 식을 형태에 따라 알맞은 평가기로 보내 값을 해석
 *
 * 형태 판정(`classifyExpression`)과 라우팅만 담당하고, 실행은 전달받은 엔진에 위임한다.
 * 결과는 **원본 타입**이다 — 문자열 서식이 필요한 호출 지점이 스스로 적용한다.
 *
 * @param expr 단일 바인딩 내부 식 (`{{ }}` 와 `raw:` 접두사가 제거된 상태)
 * @param context 데이터 컨텍스트
 * @param engine DataBindingEngine 인스턴스
 * @param options 해석 옵션
 * @returns 해석된 값 (빈 바인딩이면 undefined)
 */
export function resolveSingleBindingValue(
  expr: string,
  context: BindingContext,
  engine: DataBindingEngine,
  options?: ResolveSingleBindingOptions,
): unknown {
  const trimmed = typeof expr === 'string' ? expr.trim() : '';
  const shape = classifyExpression(trimmed);
  const bindingOptions: BindingOptions | undefined = options?.skipCache
    ? { skipCache: true }
    : undefined;

  if (shape === 'empty') {
    options?.onEmpty?.(expr);
    return undefined;
  }
  if (shape === 'literal') {
    return LITERALS.get(trimmed);
  }

  try {
    if (shape === 'pipe') {
      return engine.evaluatePipeExpression(trimmed, context, bindingOptions, options?.trackingInfo);
    }
    if (shape === 'expression') {
      return engine.evaluateExpression(trimmed, context, options?.trackingInfo);
    }
    return engine.resolve(trimmed, context, bindingOptions, options?.trackingInfo);
  } catch (error) {
    if (options?.onError) return options.onError(error, trimmed);
    throw error;
  }
}

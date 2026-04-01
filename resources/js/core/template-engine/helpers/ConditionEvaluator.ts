/**
 * 조건 평가 모듈
 *
 * 컴포넌트, 액션, 데이터 소스, 스크립트에서 사용되는
 * 통합 조건 시스템(conditions)을 평가하는 함수들을 제공합니다.
 *
 * 기존 `if` 속성과 완벽히 호환되며, AND/OR 그룹 및 if-else 체인을 지원합니다.
 *
 * @packageDocumentation
 */

import { DataBindingEngine, BindingContext } from '../DataBindingEngine';
import { createLogger } from '../../utils/Logger';

const logger = createLogger('ConditionEvaluator');

// ============================================================================
// 타입 정의
// ============================================================================

/**
 * 조건 표현식 타입
 *
 * - 문자열: 기존 `if`와 동일한 표현식 (예: "{{user.isAdmin}}")
 * - AND 그룹: 모든 조건이 true일 때 true
 * - OR 그룹: 하나라도 true이면 true
 *
 * 중첩 사용 가능: OR 안에 AND, AND 안에 OR 등
 *
 * @example
 * ```typescript
 * // 문자열 표현식
 * const expr1: ConditionExpression = "{{user.isAdmin}}";
 *
 * // AND 그룹
 * const expr2: ConditionExpression = {
 *   and: ["{{user.isLoggedIn}}", "{{user.hasPermission}}"]
 * };
 *
 * // OR 그룹 + 중첩 AND
 * const expr3: ConditionExpression = {
 *   or: [
 *     "{{user.isSuperAdmin}}",
 *     { and: ["{{user.isAdmin}}", "{{user.department === 'sales'}}"] }
 *   ]
 * };
 * ```
 */
export type ConditionExpression =
  | string
  | { and: ConditionExpression[] }
  | { or: ConditionExpression[] };

/**
 * 액션 정의 인터페이스 (ActionDispatcher와 호환)
 */
export interface ActionDefinition {
  handler: string;
  params?: Record<string, any>;
  onSuccess?: ActionDefinition | ActionDefinition[];
  onError?: ActionDefinition | ActionDefinition[];
  [key: string]: any;
}

/**
 * 조건 브랜치 (if/else if/else 체인의 각 항목)
 *
 * - `if`가 있으면 조건부 브랜치
 * - `if`가 없으면 else 브랜치 (항상 매칭)
 * - `then`은 액션에서만 사용 (컴포넌트에서는 무시)
 *
 * @example
 * ```typescript
 * // if 브랜치
 * const branch1: ConditionBranch = {
 *   if: "{{$args[0] === 'edit'}}",
 *   then: { handler: "navigate", params: { path: "/edit/{{row.id}}" } }
 * };
 *
 * // else 브랜치
 * const branch2: ConditionBranch = {
 *   then: { handler: "toast", params: { message: "기본 액션" } }
 * };
 * ```
 */
export interface ConditionBranch {
  /** 조건 표현식 (없으면 else 브랜치) */
  if?: ConditionExpression;
  /** 액션용: 조건 매칭 시 실행할 핸들러 */
  then?: ActionDefinition | ActionDefinition[];
}

/**
 * conditions 속성 타입
 *
 * - 단일 ConditionExpression: AND/OR 그룹 (컴포넌트 렌더링 조건)
 * - ConditionBranch[]: if/else if/else 체인 (컴포넌트 또는 액션)
 *
 * @example
 * ```typescript
 * // 컴포넌트: AND 그룹
 * const cond1: ConditionsProperty = {
 *   and: ["{{route.id}}", "{{form_data?.data?.author}}"]
 * };
 *
 * // 액션: if/else 체인
 * const cond2: ConditionsProperty = [
 *   { if: "{{mode === 'edit'}}", then: { handler: "save" } },
 *   { then: { handler: "create" } }
 * ];
 * ```
 */
export type ConditionsProperty = ConditionExpression | ConditionBranch[];

/**
 * 조건 브랜치 평가 결과
 */
export interface ConditionBranchResult {
  /** 매칭된 브랜치가 있는지 여부 */
  matched: boolean;
  /** 매칭된 브랜치 인덱스 (-1이면 매칭 없음) */
  branchIndex: number;
}

// ============================================================================
// 내부 헬퍼 함수
// ============================================================================

/**
 * JavaScript 리터럴 값 해석
 *
 * `true`, `false`, `null`, `undefined` 등의 리터럴을 직접 반환합니다.
 * resolve()가 이들을 컨텍스트 경로로 해석하는 것을 방지합니다.
 *
 * @since engine-v1.17.6
 * @returns `{ value: any }` 또는 리터럴이 아닌 경우 `undefined`
 */
function resolveLiteral(expr: string): { value: any } | undefined {
  switch (expr) {
    case 'true':
      return { value: true };
    case 'false':
      return { value: false };
    case 'null':
      return { value: null };
    case 'undefined':
      return { value: undefined };
    default:
      return undefined;
  }
}

/**
 * 복잡한 표현식 여부 감지
 *
 * 삼항 연산자(?:), 논리 연산자(&&, ||), 비교 연산자, 함수 호출 등이 포함된 경우 true 반환
 */
function isComplexExpression(expr: string): boolean {
  return /[?:|&!+\-*/<>=()]/.test(expr);
}

/**
 * 단일 바인딩 표현식 추출
 *
 * `{{expression}}` 형태에서 expression 부분만 추출합니다.
 * 문자열 리터럴 내부의 `}` 문자를 올바르게 처리합니다.
 */
function extractSingleBinding(value: string): string | null {
  if (!value.startsWith('{{') || !value.endsWith('}}')) {
    return null;
  }

  const inner = value.slice(2, -2);
  let inString: string | null = null;
  let braceCount = 0;

  for (let i = 0; i < inner.length; i++) {
    const char = inner[i];
    const prevChar = i > 0 ? inner[i - 1] : '';

    // 이스케이프 문자 처리
    if (prevChar === '\\') continue;

    // 문자열 시작/종료 감지
    if ((char === '"' || char === "'") && !inString) {
      inString = char;
    } else if (char === inString) {
      inString = null;
    }

    // 문자열 외부에서만 중괄호 카운트
    if (!inString) {
      if (char === '{') braceCount++;
      if (char === '}') braceCount--;
      if (braceCount < 0) return null;
    }
  }

  return braceCount === 0 ? inner.trim() : null;
}

// ============================================================================
// 공개 함수
// ============================================================================

/**
 * 문자열 조건을 평가하여 boolean 반환
 *
 * 기존 `if` 속성 평가와 동일한 로직을 사용합니다.
 * `{{expression}}` 형태의 바인딩 표현식, Optional Chaining, 비교/논리 연산 등 모든 표현식을 지원합니다.
 *
 * @param condition - 조건 문자열 (예: "{{user.isAdmin}}", "{{status === 'active'}}")
 * @param context - 데이터 컨텍스트
 * @param bindingEngine - DataBindingEngine 인스턴스
 * @returns 평가 결과 (true/false)
 *
 * @example
 * ```typescript
 * evaluateStringCondition("{{user.isAdmin}}", { user: { isAdmin: true } }, bindingEngine);
 * // 결과: true
 *
 * evaluateStringCondition("{{status === 'active'}}", { status: 'active' }, bindingEngine);
 * // 결과: true
 * ```
 */
export function evaluateStringCondition(
  condition: string,
  context: BindingContext,
  bindingEngine: DataBindingEngine
): boolean {
  if (!condition) {
    return true;
  }

  try {
    const singleBindingPath = extractSingleBinding(condition);

    let resolved: any;
    if (singleBindingPath !== null) {
      // JavaScript 리터럴 값 직접 처리 (resolve 경로 탐색 방지)
      const literalValue = resolveLiteral(singleBindingPath);
      if (literalValue !== undefined) {
        resolved = literalValue.value;
      } else if (isComplexExpression(singleBindingPath)) {
        // Optional chaining(?.)이나 복잡한 표현식은 evaluateExpression 사용
        resolved = bindingEngine.evaluateExpression(singleBindingPath, context);
      } else {
        // 단순 경로는 resolve 메서드 사용 (원본 타입 유지)
        resolved = bindingEngine.resolve(singleBindingPath, context, { skipCache: true });
      }
    } else {
      // 문자열 보간인 경우 resolveBindings 사용
      resolved = bindingEngine.resolveBindings(condition, context, { skipCache: true });
    }

    // 문자열 "false", "0", "", "null", "undefined"를 false로 처리
    if (typeof resolved === 'string') {
      const lowerResolved = resolved.toLowerCase().trim();
      if (
        lowerResolved === 'false' ||
        lowerResolved === '0' ||
        lowerResolved === '' ||
        lowerResolved === 'null' ||
        lowerResolved === 'undefined'
      ) {
        return false;
      }
    }

    return Boolean(resolved);
  } catch (error) {
    logger.warn(`evaluateStringCondition: 조건 평가 실패: ${condition}`, error);
    return false;
  }
}

/**
 * ConditionExpression을 평가하여 boolean 반환
 *
 * 문자열, AND 그룹, OR 그룹을 모두 처리합니다.
 * 단락 평가(short-circuit evaluation)를 사용하여 불필요한 평가를 건너뜁니다.
 *
 * @param condition - 조건 표현식
 * @param context - 데이터 컨텍스트
 * @param bindingEngine - DataBindingEngine 인스턴스
 * @returns 평가 결과 (true/false)
 *
 * @example
 * ```typescript
 * // 문자열
 * evaluateConditionExpression("{{user.isAdmin}}", context, bindingEngine);
 *
 * // AND 그룹 (모든 조건 true → true)
 * evaluateConditionExpression(
 *   { and: ["{{user.isLoggedIn}}", "{{user.hasPermission}}"] },
 *   context,
 *   bindingEngine
 * );
 *
 * // OR 그룹 (하나라도 true → true)
 * evaluateConditionExpression(
 *   { or: ["{{user.isAdmin}}", "{{user.isModerator}}"] },
 *   context,
 *   bindingEngine
 * );
 * ```
 */
export function evaluateConditionExpression(
  condition: ConditionExpression,
  context: BindingContext,
  bindingEngine: DataBindingEngine
): boolean {
  // 문자열: 기존 표현식 평가
  if (typeof condition === 'string') {
    return evaluateStringCondition(condition, context, bindingEngine);
  }

  // AND: 모든 조건 true (단락 평가 - 첫 false에서 중단)
  if ('and' in condition) {
    if (!Array.isArray(condition.and) || condition.and.length === 0) {
      logger.warn('evaluateConditionExpression: AND 그룹이 비어있습니다');
      return true; // 빈 AND는 true (논리적으로 모든 조건 충족)
    }

    for (const sub of condition.and) {
      if (!evaluateConditionExpression(sub, context, bindingEngine)) {
        return false;
      }
    }
    return true;
  }

  // OR: 하나라도 true (단락 평가 - 첫 true에서 중단)
  if ('or' in condition) {
    if (!Array.isArray(condition.or) || condition.or.length === 0) {
      logger.warn('evaluateConditionExpression: OR 그룹이 비어있습니다');
      return false; // 빈 OR는 false (논리적으로 어떤 조건도 충족 안 함)
    }

    for (const sub of condition.or) {
      if (evaluateConditionExpression(sub, context, bindingEngine)) {
        return true;
      }
    }
    return false;
  }

  logger.warn('evaluateConditionExpression: 알 수 없는 조건 형식', condition);
  return false;
}

/**
 * conditions 배열 (if/else 체인)을 평가
 *
 * 첫 번째로 조건이 true인 브랜치를 찾아 반환합니다.
 * `if`가 없는 브랜치는 else 브랜치로 간주되어 항상 매칭됩니다.
 *
 * @param branches - ConditionBranch 배열
 * @param context - 데이터 컨텍스트
 * @param bindingEngine - DataBindingEngine 인스턴스
 * @returns 매칭 결과 (matched: 매칭 여부, branchIndex: 매칭된 브랜치 인덱스)
 *
 * @example
 * ```typescript
 * const branches = [
 *   { if: "{{mode === 'edit'}}", then: { handler: "save" } },
 *   { if: "{{mode === 'view'}}", then: { handler: "view" } },
 *   { then: { handler: "default" } } // else 브랜치
 * ];
 *
 * const result = evaluateConditionBranches(branches, { mode: 'edit' }, bindingEngine);
 * // 결과: { matched: true, branchIndex: 0 }
 *
 * const result2 = evaluateConditionBranches(branches, { mode: 'other' }, bindingEngine);
 * // 결과: { matched: true, branchIndex: 2 } (else 브랜치)
 * ```
 */
export function evaluateConditionBranches(
  branches: ConditionBranch[],
  context: BindingContext,
  bindingEngine: DataBindingEngine
): ConditionBranchResult {
  if (!Array.isArray(branches) || branches.length === 0) {
    return { matched: false, branchIndex: -1 };
  }

  for (let i = 0; i < branches.length; i++) {
    const branch = branches[i];

    // else 브랜치 (if 없음) - 항상 매칭
    if (branch.if === undefined) {
      return { matched: true, branchIndex: i };
    }

    // 조건 평가
    if (evaluateConditionExpression(branch.if, context, bindingEngine)) {
      return { matched: true, branchIndex: i };
    }
  }

  return { matched: false, branchIndex: -1 };
}

/**
 * conditions 속성 전체를 평가하여 boolean 반환 (컴포넌트/데이터소스/스크립트용)
 *
 * - 단일 ConditionExpression: AND/OR 그룹 평가
 * - ConditionBranch[]: if/else 체인에서 하나라도 매칭되면 true
 *
 * @param conditions - conditions 속성 값
 * @param context - 데이터 컨텍스트
 * @param bindingEngine - DataBindingEngine 인스턴스
 * @returns 평가 결과 (true: 렌더링/실행, false: 숨김/스킵)
 *
 * @example
 * ```typescript
 * // AND 그룹
 * evaluateConditions(
 *   { and: ["{{route.id}}", "{{user.hasPermission}}"] },
 *   context,
 *   bindingEngine
 * );
 *
 * // if/else 체인 (하나라도 매칭되면 true)
 * evaluateConditions(
 *   [
 *     { if: "{{user.role === 'admin'}}" },
 *     { if: "{{user.role === 'manager'}}" }
 *   ],
 *   context,
 *   bindingEngine
 * );
 * ```
 */
export function evaluateConditions(
  conditions: ConditionsProperty,
  context: BindingContext,
  bindingEngine: DataBindingEngine
): boolean {
  // 단일 ConditionExpression (문자열 또는 AND/OR 객체)
  if (!Array.isArray(conditions)) {
    return evaluateConditionExpression(conditions, context, bindingEngine);
  }

  // ConditionBranch[] (if/else 체인)
  const result = evaluateConditionBranches(conditions, context, bindingEngine);
  return result.matched;
}

/**
 * 타입 가드: ConditionExpression인지 확인
 */
export function isConditionExpression(value: unknown): value is ConditionExpression {
  if (typeof value === 'string') {
    return true;
  }

  if (value && typeof value === 'object') {
    return 'and' in value || 'or' in value;
  }

  return false;
}

/**
 * 타입 가드: ConditionBranch[]인지 확인
 */
export function isConditionBranches(value: unknown): value is ConditionBranch[] {
  if (!Array.isArray(value)) {
    return false;
  }

  // 빈 배열은 브랜치 배열로 간주하지 않음
  if (value.length === 0) {
    return false;
  }

  // 첫 번째 요소가 ConditionBranch 형태인지 확인
  const first = value[0];
  if (!first || typeof first !== 'object') {
    return false;
  }

  // if 또는 then 속성이 있으면 ConditionBranch
  return 'if' in first || 'then' in first;
}

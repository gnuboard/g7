/**
 * 그누보드7 템플릿 엔진 - 표현식 컴파일 캐시
 *
 * `DataBindingEngine.evaluateExpression` 의 전처리(optional chaining·`$t:` 토큰)와 변수 추출은
 * 입력 식 문자열만으로 결과가 정해지는 순수 함수다. 그 결과를 식 문자열 키로 기억해 같은 식을
 * 다시 평가할 때 정규식 7회·변수 추출을 건너뛴다.
 *
 * **값은 담지 않는다.** 과거 회귀는 전부 컨텍스트에 따라 달라지는 값을 문자열 키로 캐시한 데서
 * 났다. 미정의 변수 주입·`$computed` alias·`$t`·`$localized`·로케일 회수는 호출마다 수행한다.
 *
 * 모듈 스코프인 이유: computed 경로 등이 호출마다 새 엔진 인스턴스를 만들어 왔으므로 인스턴스
 * 필드 캐시는 적중하지 않는다.
 *
 * @package G7
 * @subpackage Template Engine
 * @since engine-v1.74.0
 */

/** 컴파일된 식 — 전처리된 식과 최상위 변수 목록 */
export interface CompiledExpression {
  readonly processed: string;
  readonly variables: readonly string[];
}

/** 보관 상한. 초과 시 가장 먼저 들어온 항목부터 제거한다(삽입 순서 Map). */
export const EXPRESSION_COMPILE_CACHE_LIMIT = 5000;

const cache = new Map<string, CompiledExpression>();
let hits = 0;
let misses = 0;

/**
 * 식의 컴파일 결과를 반환한다. 미스일 때만 `compile` 을 부른다.
 *
 * `compile` 은 호출부가 호출 시점에 묶어 넘긴다 — 모듈 초기화 때 메서드 참조를 잡아 두면
 * 프로토타입 spy 로 호출 수를 셀 수 없다.
 *
 * @param expr 원본 식(`{{ }}` 가 벗겨진 내부 식)
 * @param compile 전처리·변수 추출 함수
 * @return 동결된 컴파일 결과(변수 배열도 동결)
 */
export function getCompiledExpression(
  expr: string,
  compile: (expr: string) => { processed: string; variables: string[] },
): CompiledExpression {
  const cached = cache.get(expr);
  if (cached !== undefined) {
    hits++;
    return cached;
  }
  misses++;
  const raw = compile(expr);
  const compiled: CompiledExpression = Object.freeze({
    processed: raw.processed,
    variables: Object.freeze(raw.variables.slice()),
  });
  if (cache.size >= EXPRESSION_COMPILE_CACHE_LIMIT) {
    const oldest = cache.keys().next().value;
    if (oldest !== undefined) cache.delete(oldest);
  }
  cache.set(expr, compiled);
  return compiled;
}

/**
 * 캐시 통계(진단용 — DevTools 패널에는 노출하지 않는다).
 *
 * @return 적중·미스 횟수와 현재 크기
 */
export function getExpressionCompileCacheStats(): { hits: number; misses: number; size: number } {
  return { hits, misses, size: cache.size };
}

/**
 * 테스트 간 상태 누수를 막기 위해 캐시와 통계를 비운다.
 *
 * @return void
 */
export function __resetExpressionCompileCacheForTests(): void {
  cache.clear();
  hits = 0;
  misses = 0;
}

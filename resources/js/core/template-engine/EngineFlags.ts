/**
 * 그누보드7 템플릿 엔진 - 안전 스위치
 *
 * 표현식 컴파일 캐시·AST 캐시·computed 스코프 물려받기를 기능별로 끄는 개발자용 플래그다.
 * 기본값은 셋 다 켜짐, 그림자 검증(물려받은 값과 직접 계산한 값을 대조)은 꺼짐이다.
 * 끄는 방법은 브라우저 쪽뿐이다 — 개발자 콘솔·확장 스크립트에서 `G7Core.engineFlags.set({...})`.
 * 서버 설정·관리자 화면은 없다.
 *
 * 플래그가 꺼진 경로는 수정 전과 같은 계산 경로다(캐시 조회 없이 매번 전처리·파싱, 모든 렌더러가
 * computed 를 직접 계산). 캐시는 값을 담지 않으므로 켜고 끌 때 무효화할 필요가 없다.
 *
 * @package G7
 * @subpackage Template Engine
 * @since engine-v1.74.0
 */

import { setAstCacheEnabled } from './SafeExpressionEvaluator';

export interface EngineFlags {
  /** 표현식 전처리·변수 추출 결과 캐시 */
  expressionCompileCache: boolean;
  /** 안전 평가기 AST 캐시 */
  astCache: boolean;
  /** computed 를 상태 스코프 단위로 1회 계산하고 자식이 물려받는다 */
  computedScopeInheritance: boolean;
  /** 물려받는 렌더러도 직접 계산해 대조한다(개발·실측 전용) */
  verifyComputedInheritance: boolean;
}

const DEFAULT_FLAGS: Readonly<EngineFlags> = Object.freeze({
  expressionCompileCache: true,
  astCache: true,
  computedScopeInheritance: true,
  verifyComputedInheritance: false,
});

const FLAG_KEYS = Object.keys(DEFAULT_FLAGS) as (keyof EngineFlags)[];

let current: Readonly<EngineFlags> = DEFAULT_FLAGS;
let configMerged = false;

/**
 * 현재 값을 확정하고, 자체 스위치를 가진 모듈에 밀어 넣는다.
 *
 * SafeExpressionEvaluator 는 다른 프로젝트 파일을 import 하지 않는 계약이라 이 모듈을 읽지 못한다.
 *
 * @param next 새 플래그
 * @return void
 */
function commit(next: Readonly<EngineFlags>): void {
  current = next;
  setAstCacheEnabled(next.astCache);
}

/**
 * 알려진 boolean 키만 골라 현재 플래그에 덮어쓴 새 동결 객체를 만든다.
 *
 * @param base 기준 플래그
 * @param patch 덮어쓸 값(알 수 없는 키·boolean 이 아닌 값은 무시)
 * @return 동결된 새 플래그 객체
 */
function applyPatch(base: Readonly<EngineFlags>, patch: unknown): Readonly<EngineFlags> {
  if (!patch || typeof patch !== 'object') return base;
  const next: EngineFlags = { ...base };
  for (const key of FLAG_KEYS) {
    const value = (patch as Record<string, unknown>)[key];
    if (typeof value === 'boolean') next[key] = value;
  }
  return Object.freeze(next);
}

/**
 * 현재 플래그를 반환한다.
 *
 * 첫 호출 때 `window.G7Config.engineFlags` 가 있으면 한 번 병합한다. 모듈 스코프 캐시는 엔진
 * init 전에도 쓰이므로 init 시점 병합으로는 늦다.
 *
 * @return 읽기 전용 플래그
 */
export function getEngineFlags(): Readonly<EngineFlags> {
  if (!configMerged) {
    configMerged = true;
    if (typeof window !== 'undefined') {
      commit(applyPatch(current, (window as any).G7Config?.engineFlags));
    }
  }
  return current;
}

/**
 * 플래그 일부를 바꾼다.
 *
 * @param patch 바꿀 플래그(알려진 boolean 키만 반영)
 * @return void
 */
export function setEngineFlags(patch: Partial<EngineFlags>): void {
  commit(applyPatch(getEngineFlags(), patch));
}

/**
 * 테스트 간 상태 누수를 막기 위해 기본값·병합 전 상태로 되돌린다.
 *
 * @return void
 */
export function __resetEngineFlagsForTests(): void {
  commit(DEFAULT_FLAGS);
  configMerged = false;
}

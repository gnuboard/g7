/**
 * sampleGlobalChain.ts — `_global.*` baseline deep merge 체인
 *
 * 편집 모드 격리 store 의 `_global.*` baseline 을 다음 순서로 deep merge 한다
 *
 *
 *   1. 코어 기본 시드 (currentUser / settings.general / route 등 도메인-중립 keyspace)
 *   2. 활성 모듈의 `editor-spec.json.sampleGlobal` (등록 순서)
 *   3. 활성 플러그인의 `editor-spec.json.sampleGlobal` (등록 순서)
 *   4. 편집 대상 템플릿의 `editor-spec.json.sampleGlobal`
 *
 * 충돌 정책 (관대 정책 + dev 경고):
 *  - 코어 시드가 직접 보유한 **leaf** 를 확장이 덮어쓰면 코어 값이 이긴다.
 *    dev 콘솔에 경고를 출력한다(개발자가 자기 시드 미반영 이유를 인지하도록).
 *  - 코어가 안 가진 sub-key 보강(예: `currentUser.cart_count`)은 통과.
 *  - 배열은 통째 교체 (의 sampleData 정책과 동일).
 *  - audit error 로 차단하지 않는다 — 편집 미리보기용 시드이므로 관대 정책.
 *
 * guest_only (임시 분기 `coreSampleGuestGlobalSeed` 흡수):
 *  - `meta.guest_only: true` 레이아웃은 비로그인 전제 — `currentUser` 를 시드
 *  결과에서 제외한다. **S6-2 정정**: 번들 템플릿이 자기 `sampleGlobal` 에
 *  `currentUser` 를 작성하면서, 코어 시드뿐 아니라 **템플릿/확장이 시드한
 *    currentUser 도** guest 페이지에서 제외해야 한다(그렇지 않으면 로그인 분기 가드
 *  partial 이 토스트/리다이렉트를 발화 — 결함 재발). 따라서 guest_only
 *    제외를 코어 시드(`base`)뿐 아니라 **최종 deep merge 결과에도** 적용한다.
 *
 * @since engine-v1.50.0
 * @since engine-v1.50.0
 */

import type { SampleGlobalSource } from '../spec/editorSpecLoader';
import { createLogger } from '../../../utils/Logger';

const logger = createLogger('LayoutEditor');

/** plain object 판정 — 배열/null 제외 */
function isPlainObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** 깊은 복제 (JSON-safe 시드 전제 — 함수/Date 등 비포함) */
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
 * 코어 시드가 직접 보유한 leaf 경로 집합을 수집.
 *
 * leaf = 값이 plain object 가 아닌 경로(원시값/배열/null). object 는 계속 내려간다.
 * 예: `{ currentUser: { uuid: 'x', is_admin: false }, settings: { general: { site_name } } }`
 *     → `currentUser.uuid`, `currentUser.is_admin`, `settings.general.site_name`
 */
function collectLeafPaths(obj: Record<string, unknown>, prefix = '', out = new Set<string>()): Set<string> {
  for (const [key, value] of Object.entries(obj)) {
    const path = prefix ? `${prefix}.${key}` : key;
    if (isPlainObject(value)) {
      collectLeafPaths(value, path, out);
    } else {
      out.add(path);
    }
  }
  return out;
}

export interface BuildSampleGlobalSeedOptions {
  /**
   * 코어 기본 시드 (체인 1단계). 깊은 복제되어 변경되지 않는다.
   *
   * 도메인-중립 baseline 으로, 코어는 더 이상 도메인 keyspace 를 시드하지 않는다
   * (currentUser/settings 는 번들 템플릿 sampleGlobal 이 제공 —
   * coreSamplePresets/coreSampleGlobalSeed 폐기-v1.50.0). 미전달 시
   * 빈 객체로 동작하며, 시드가 필요한 호출자만 명시 전달한다.
   */
  coreSeed?: Record<string, unknown>;
  /** 모듈 → 플러그인 → 템플릿 순서의 sampleGlobal 소스 (loader 결과). */
  sources: SampleGlobalSource[];
  /** guest_only 레이아웃 여부 — true 면 코어 keyspace `currentUser` 시드 제외 */
  isGuestOnly?: boolean;
  /** dev 경고 출력 함수 주입 (테스트). 기본 logger.warn */
  warn?: (message: string) => void;
}

/**
 * 한 소스를 누적 대상(dst)에 deep merge. 코어 leaf 충돌 시 코어 우선 + 경고.
 *
 * @param dst 누적 대상 (직접 변경됨)
 * @param src 병합할 소스 객체
 * @param protectedLeaves 코어 시드가 보유한 leaf 경로 집합
 * @param sourceId 충돌 경고 메시지용 확장 식별자
 * @param warn 경고 출력 함수
 * @param prefix 현재 경로 prefix (재귀)
 */
function mergeSource(
  dst: Record<string, unknown>,
  src: Record<string, unknown>,
  protectedLeaves: Set<string>,
  sourceId: string,
  warn: (message: string) => void,
  prefix = '',
): void {
  for (const [key, srcVal] of Object.entries(src)) {
    const path = prefix ? `${prefix}.${key}` : key;

    // 코어 leaf 충돌 — 코어 값 유지 + dev 경고. (sub-key 보강은 leaf 가 아니므로 통과)
    if (protectedLeaves.has(path)) {
      warn(
        `[LayoutEditor] sampleGlobal key conflict: extension '${sourceId}' override of core key '${path}' ignored`,
      );
      continue;
    }

    const dstVal = dst[key];
    if (isPlainObject(srcVal) && isPlainObject(dstVal)) {
      // 양쪽 object → deep merge 계속
      mergeSource(dstVal, srcVal, protectedLeaves, sourceId, warn, path);
    } else {
      // 원시값/배열/한쪽만 object → 통째 교체 (배열 통째 교체 정책 포함)
      dst[key] = deepClone(srcVal);
    }
  }
}

/**
 * 코어 시드 + 활성 확장 sampleGlobal 을 deep merge 한 격리 store baseline 을 생성.
 *
 * @param options 빌드 옵션
 * @return 편집 모드 격리 store 의 초기 `_global` baseline
 */
export function buildSampleGlobalSeed(
  options: BuildSampleGlobalSeedOptions,
): Record<string, unknown> {
  const warn = options.warn ?? ((message: string) => logger.warn(message));

  // 1단계 — 코어 시드 깊은 복제 (미전달 시 빈 객체). guest_only 면 currentUser 제외.
  const base = deepClone(options.coreSeed ?? {});
  if (options.isGuestOnly && 'currentUser' in base) {
    delete base.currentUser;
  }

  // 코어 시드가 보유한 leaf 경로 — 확장 충돌 판정 기준.
  // (currentUser 가 guest_only 로 제외됐다면 그 하위 leaf 는 보호 대상이 아니다 →
  //  guest 레이아웃에서 확장이 currentUser 를 시드할 일은 없지만, 충돌 판정도 base 기준.)
  const protectedLeaves = collectLeafPaths(base);

  // 2~4단계 — 모듈 → 플러그인 → 템플릿 순서로 deep merge.
  for (const source of options.sources) {
    if (!isPlainObject(source.sampleGlobal)) continue;
    mergeSource(base, source.sampleGlobal, protectedLeaves, source.id, warn);
  }

  // guest_only 최종 권위 적용 — 코어 시드뿐 아니라 템플릿/확장이
  // 시드한 currentUser 도 비로그인 페이지에서 제외한다. 번들 템플릿이 자기
  // sampleGlobal 에 currentUser 를 작성하므로, base 단계 제외만으로는 템플릿
  // currentUser 가 다시 병합돼 로그인 분기 가드가 발화하는 회귀를 차단한다.
  if (options.isGuestOnly && 'currentUser' in base) {
    delete base.currentUser;
  }

  return base;
}

/**
 * sampleDataProvider.ts — 편집 모드 캔버스용 샘플 데이터 프로바이더
 *
 * `DataSourceManager` 의 샘플 모드 옵션(`sampleProvider`) 으로 주입되어 편집기
 * 캔버스 렌더 시 네트워크 fetch 대신 샘플 데이터를 반환한다.
 *
 * 우선순위:
 * 0. `editorSpec.sampleData.bySource[sourceKey][id]` — 출처별 명시 매핑 (방안 B)
 * 1. `editorSpec.sampleData.byDataSourceId[id]` — 명시 매핑 (출처 미상 폴백)
 * 2. `editorSpec.sampleData.byEndpointPattern[pattern]` — 패턴 매칭
 * 3. `dataSource.fallback?.data` — 데이터소스 자체의 fallback
 * 4. 마지막 — 도메인-중립 빈 응답(`{}`)
 *
 * 코어 키워드 프리셋(`coreSamplePresets`)은 단계적 폐기에 따라 제거됨
 * (번들 sampleData 전수 커버 완료 후). 도메인 응답 shape 는 각 템플릿/모듈/
 * 플러그인 `editor-spec.json.sampleData` 가 SSoT (원칙 4.4 — 코어 도메인 비결정).
 *
 * @since engine-v1.50.0
 * @since engine-v1.50.0
 */

import type { DataSource, SampleDataProvider } from '../../DataSourceManager';
import type { EditorSampleDataSpec } from '../spec/specTypes';

/**
 * 매칭 결과 메타 — devtools `editor-sample-data` 트래커에 노출되는 구조 
 */
export type SampleMatchSource =
  | 'spec_byId'
  | 'spec_byEndpoint'
  | 'fallback'
  | 'inferred';

export interface SampleMatchInfo {
  dataSourceId: string;
  resolvedBy: SampleMatchSource;
  /**
   * 출처 분기 키 (방안 B) — '{kind}:{id}' 또는 'template'.
   * 출처별 byDataSourceId(bySource)로 해소된 경우에만 채워진다(devtools 가시성).
   */
  sourceKey?: string;
}

/**
 * 매칭 결과 (값 + 메타). 트래커에서 `presetKey` 등 메타를 활용.
 */
export interface SampleResolution {
  value: unknown;
  match: SampleMatchInfo;
}

/**
 * Sample 프로바이더 생성 옵션.
 *
 * `onMatch` 콜백은 매칭 결과를 받아 devtools 트래커 호출로 연결할
 * 책임을 호출자(`useSampleData`)에게 위임한다.
 */
export interface CreateSampleDataProviderOptions {
  spec?: EditorSampleDataSpec;
  /**
   * 활성 페이지 상태의 sampleData 오버라이드. 매칭 시
   * base `spec` 보다 우선해 **통째 교체**. 우선순위 0/1/2 단계(bySource/byDataSourceId/
   * byEndpointPattern)로 시도하고 미매칭이면 base spec 폴백(fallback/inferred 까지).
   */
  overrideSpec?: EditorSampleDataSpec;
  onMatch?: (info: SampleMatchInfo) => void;
}

/**
 * 데이터소스의 `__source` 출처 메타를 `bySource` 조회 키로 변환한다 (방안 B).
 *
 * - module/plugin + identifier → `"{kind}:{identifier}"`
 * - template/base/route/core/메타부재 → `"template"` (편집 대상 템플릿 소유로 귀속)
 *
 * @param source 데이터소스
 * @return bySource 조회 키
 */
function resolveSourceKey(source: DataSource): string {
  const meta = (source as { __source?: { kind?: string; identifier?: string | null } }).__source;
  if (meta && (meta.kind === 'module' || meta.kind === 'plugin') && meta.identifier) {
    return `${meta.kind}:${meta.identifier}`;
  }
  return 'template';
}

/**
 * 데이터소스에 대해 단일 매칭을 수행해 결과(값 + 메타) 를 반환한다.
 *
 * 본 함수는 순수 함수(side effect 없음) — 캐싱·devtools 호출 등은 호출자가 처리.
 */
/**
 * spec 의 명시 매핑 단계(0/1/2 — bySource/byDataSourceId/byEndpointPattern)만 시도한다.
 *
 * 페이지 상태 오버라이드가 base spec 보다 먼저 이 단계로 매칭을
 * 시도하기 위해 분리. fallback/inferred(3·4 단계)는 base 해석에서만 적용한다(오버라이드는
 * "명시한 데이터소스만" 통째 교체하고, 나머지는 base 흐름으로 폴백되어야 하므로).
 *
 * @param source 데이터소스
 * @param spec sampleData 스펙(또는 오버라이드 스펙)
 * @return 매칭 결과 또는 null(미매칭 — 다음 단계로)
 */
function resolveSpecExplicit(
  source: DataSource,
  spec?: EditorSampleDataSpec,
): SampleResolution | null {
  if (!spec) return null;

  // 0. 출처별 byDataSourceId (방안 B) — 같은 id 를 여러 확장이
  //    서로 다른 shape 로 정의할 때, 이 데이터소스의 출처(__source)에 해당하는
  //  스펙 샘플을 우선 선택해 전역 id 충돌을 해소한다(계획서).
  const sourceKey = resolveSourceKey(source);
  if (sourceKey && spec.bySource) {
    const scoped = spec.bySource[sourceKey];
    const scopedVal = scoped?.[source.id];
    if (scopedVal !== undefined) {
      return {
        value: scopedVal,
        match: { dataSourceId: source.id, resolvedBy: 'spec_byId', sourceKey },
      };
    }
  }

  // 1. byDataSourceId — 명시 매핑 (출처 미상 폴백)
  const byId = spec.byDataSourceId?.[source.id];
  if (byId !== undefined) {
    return {
      value: byId,
      match: { dataSourceId: source.id, resolvedBy: 'spec_byId' },
    };
  }

  // 2. byEndpointPattern — 패턴 매칭
  const endpoint = (source as any).endpoint ?? '';
  if (spec.byEndpointPattern && typeof endpoint === 'string' && endpoint.length > 0) {
    for (const [pattern, value] of Object.entries(spec.byEndpointPattern)) {
      if (matchEndpointPattern(endpoint, pattern)) {
        return {
          value,
          match: { dataSourceId: source.id, resolvedBy: 'spec_byEndpoint' },
        };
      }
    }
  }

  return null;
}

export function resolveSampleData(
  source: DataSource,
  spec?: EditorSampleDataSpec,
): SampleResolution {
  // 0/1/2. spec 명시 매핑 단계
  const explicit = resolveSpecExplicit(source, spec);
  if (explicit) return explicit;

  // 3. dataSource.fallback.data — 데이터소스 자체의 fallback
  const fallbackData = (source as any).fallback?.data;
  if (fallbackData !== undefined) {
    return {
      value: { data: Array.isArray(fallbackData) ? fallbackData : [fallbackData] },
      match: { dataSourceId: source.id, resolvedBy: 'fallback' },
    };
  }

  // 4. 마지막 — 도메인-중립 빈 응답.
  //  코어 키워드 프리셋(coreSamplePresets)은 단계적 폐기에 따라 제거됨
  //  (번들 sampleData 전수 커버 완료 — #2).
  //    미매칭 데이터소스는 도메인 결정을 코어가 갖지 않도록 빈 응답으로 디그레이드한다
  //    (원칙 4.4). 제3자 템플릿은 자기 editor-spec.json.sampleData 작성으로 채운다.
  return {
    value: {},
    match: { dataSourceId: source.id, resolvedBy: 'inferred' },
  };
}

/**
 * Endpoint 패턴 매칭 — `*` 와일드카드 만 지원하는 가벼운 글로브.
 *
 * 예: `/api/posts` 패턴은 정확히 `/api/posts` 만 매칭.
 * `/api/posts/*` 는 `/api/posts/1`, `/api/posts/foo/bar` 모두 매칭.
 */
function matchEndpointPattern(endpoint: string, pattern: string): boolean {
  // 정규식 특수문자 이스케이프 후 `\*` → `.*` 로 치환
  const escaped = pattern.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*');
  return new RegExp(`^${escaped}$`).test(endpoint);
}

/**
 * `DataSourceManager` 옵션으로 주입할 `SampleDataProvider` 를 생성한다.
 *
 * `DataSourceManager` 인터페이스 계약대로 `has()` 와 `resolve()` 를 노출.
 * 본 프로바이더는 모든 데이터소스에 대해 `has()` 가 `true` 를 반환 — 우선순위
 * 단계 안에 항상 매칭되도록 설계(미매칭 시 도메인-중립 빈 응답이 마지막 폴백).
 */
export function createSampleDataProvider(
  options: CreateSampleDataProviderOptions = {},
): SampleDataProvider {
  const { spec, overrideSpec, onMatch } = options;

  return {
    has(_dataSourceId: string): boolean {
      // 항상 true — 미매칭 케이스도 generic 폴백으로 처리
      return true;
    },
    resolve(dataSource: DataSource): unknown {
      // 활성 페이지 상태 오버라이드 우선 — 명시 매핑(0/1/2)만 시도.
      // 매칭되면 통째 교체, 미매칭이면 base spec 의 전체 우선순위(0~4)로 폴백.
      const overridden = overrideSpec ? resolveSpecExplicit(dataSource, overrideSpec) : null;
      const { value, match } = overridden ?? resolveSampleData(dataSource, spec);
      if (onMatch) {
        try {
          onMatch(match);
        } catch {
          // devtools 호출 실패는 편집기 본체에 영향 주지 않음 
        }
      }
      return value;
    },
  };
}

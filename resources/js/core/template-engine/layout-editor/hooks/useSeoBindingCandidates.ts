/**
 * useSeoBindingCandidates.ts — SEO 컨텍스트 데이터 칩 후보
 *
 * [검색엔진] 탭(og.title/description·vars·structured)의 데이터 칩 입력이 쓰는 후보 풀.
 * 운영 SeoRenderer 컨텍스트와 **동일 루트**를 노출한다 — data_sources(.data.*) / route(+path) /
 * query / _global(.settings/.site_name/.modules/.plugins/...) / _local / _computed / _seo.{page_type}.
 * `filter_context` 훅이 임의 루트를 추가할 수 있어 정적으로 폐쇄되지 않으므로,
 * 알려진 8종 루트 목록 + **자유 표현식 입력 허용**(호출자 UI 가 자유 입력칸 병행).
 *
 * useBindingCandidates 와 같은 `buildBindingCandidates` 인프라를 쓰되, SEO 전용 루트
 * (`_seo`)를 추가하고 캔버스 전용 상태(local 토글 등)는 그대로 둔다. 신규 후보 빌더 0.
 *
 * @since engine-v1.50.0
 */

import { useMemo } from 'react';
import {
  buildBindingCandidates,
  type BindingCandidate,
  type BindingDataSourceInput,
  type BindingStateInput,
} from '../spec/bindingCandidates';
import { resolveSampleData } from '../sample-data/sampleDataProvider';
import type { DataSource } from '../../DataSourceManager';
import type { EditorSpec } from '../spec/specTypes';

/** SEO 컨텍스트가 노출하는 알려진 루트(Q1) — 자유 표현식과 병행 */
export const SEO_KNOWN_ROOTS = [
  'data_source',
  'route',
  'query',
  '_global',
  '_local',
  '_computed',
  '_seo',
] as const;

/** useSeoBindingCandidates 입력 */
export interface UseSeoBindingCandidatesInput {
  /** 편집 중 레이아웃 raw (data_sources/computed 보유) */
  raw: Record<string, unknown> | undefined | null;
  /** 병합 editor-spec (sampleData/sampleGlobal/states) */
  spec: EditorSpec | null | undefined;
  /** 현재 meta.seo.page_type — `_seo.{page_type}` 루트 노출용 */
  pageType?: string | null;
}

/**
 * SEO 컨텍스트 데이터 칩 후보 풀을 빌드한다.
 *
 * @param  input  raw·spec·pageType
 * @return BindingCandidate[] (SEO 루트 후보 목록)
 */
export function useSeoBindingCandidates(input: UseSeoBindingCandidatesInput): BindingCandidate[] {
  const { raw, spec, pageType } = input;
  return useMemo<BindingCandidate[]>(() => {
    // data_sources — 편집기 샘플 응답 shape 해소(런타임 fetch 금지).
    const dsList = Array.isArray(raw?.data_sources)
      ? (raw!.data_sources as Array<Record<string, unknown>>)
      : [];
    const dataSources: BindingDataSourceInput[] = [];
    for (const ds of dsList) {
      const id = ds?.id;
      if (typeof id !== 'string' || id.length === 0) continue;
      const { value } = resolveSampleData(ds as unknown as DataSource, spec?.sampleData);
      const labelKey = typeof ds.label_key === 'string' ? ds.label_key : undefined;
      dataSources.push({ id, sample: value, labelKey });
    }

    const states: BindingStateInput[] = [];
    // _global baseline — sampleGlobal(settings/site_name/modules/plugins 등 SeoRenderer 노출 루트).
    if (spec?.sampleGlobal && typeof spec.sampleGlobal === 'object') {
      states.push({ scope: '_global', tree: spec.sampleGlobal });
    }
    // _computed — 레이아웃 raw.computed 키.
    if (raw?.computed && typeof raw.computed === 'object' && !Array.isArray(raw.computed)) {
      states.push({ scope: '_computed', tree: raw.computed as Record<string, unknown> });
    }

    const base = buildBindingCandidates({ dataSources, states, spec: spec ?? undefined });

    // _seo.{page_type} — SeoRenderer 가 resolveSeoContext 로 주입하는 SEO 전용 루트.
    // bindingCandidates 의 scope 유니언(route/query/_global/_local/_computed)에는 없는 SEO
    // 고유 루트라, 후보 빌더를 거치지 않고 알려진 키를 직접 후보로 덧붙인다(자유 표현식 병행).
    if (typeof pageType === 'string' && pageType !== '') {
      for (const leaf of ['title', 'description']) {
        base.push({
          expression: `{{_seo.${pageType}.${leaf}}}`,
          // _seo 는 BindingSourceKind 유니언 밖의 SEO 전용 루트 — 캐스팅으로 후보에 부착.
          source: '_seo' as unknown as BindingCandidate['source'],
          sourceId: '_seo',
          path: `${pageType}.${leaf}`,
          shape: 'scalar',
          preview: '',
        });
      }
    }

    return base;
  }, [raw, spec, pageType]);
}

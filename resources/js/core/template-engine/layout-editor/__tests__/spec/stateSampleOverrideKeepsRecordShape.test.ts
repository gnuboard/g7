/**
 * stateSampleOverrideKeepsRecordShape.test.ts — 상태 표본 덮어쓰기가 단건 기록을 조각으로 바꾸지 않는지 정적 가드
 *
 * 상태 항목의 `sampleDataOverrides` 는 그 데이터소스 표본을 **통째로 교체**한다(부분 병합 없음). 그래서 단건 기록
 * (주문, 회원처럼 `id` 를 가진 상세 응답)을 덮을 때 분기용 필드 하나만 적으면 나머지가 모두 사라져, 캔버스가
 * 빈 기록을 그린다. 오류는 나지 않는다.
 *
 *  - 7.1.0 실측: 쇼핑몰 관리자 주문 상세의 「부분 취소」 상태가 주문을 `{is_partially_cancelled: true}` 하나로
 *    덮어, 캔버스가 「주문상품이 없습니다」 와 빈 주문자, 결제 정보로 그려졌다.
 *
 * 판정: 기본 표본의 `data` 가 `id` 를 가진 단건 객체인데, 같은 데이터소스를 덮는 상태 표본의 `data` 가 목록 모양도
 * 아니면서 `id` 가 없으면 위반이다. 끝점 패턴으로 덮는 표본은 레이아웃의 데이터소스 끝점으로 기본 표본을 찾는다.
 * 모집단은 저장소에서 파생한다.
 *
 * @since engine-v1.76.0
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, existsSync, readdirSync, statSync } from 'node:fs';
import { resolve, join } from 'node:path';

const ROOT = process.cwd();
const BUNDLE_BASES = ['modules/_bundled', 'plugins/_bundled', 'templates/_bundled'];

type Json = any;

const isPlainObject = (v: unknown): v is Record<string, unknown> => !!v && typeof v === 'object' && !Array.isArray(v);

/** 목록 응답 모양(`data.data[]`·`data.items[]`)인가 */
const isListShape = (data: Record<string, unknown>): boolean => Array.isArray(data.data) || Array.isArray(data.items);

/** 디렉터리 아래 JSON 파일을 모은다. */
function walkJson(absDir: string, out: string[] = []): string[] {
  if (!existsSync(absDir)) return out;
  for (const entry of readdirSync(absDir)) {
    const abs = join(absDir, entry);
    if (statSync(abs).isDirectory()) walkJson(abs, out);
    else if (entry.endsWith('.json')) out.push(abs);
  }
  return out;
}

/** 확장 레이아웃이 선언한 데이터소스 id → 끝점. */
function collectDataSourceEndpoints(extRoot: string): Array<{ id: string; endpoint: string }> {
  const out: Array<{ id: string; endpoint: string }> = [];
  for (const sub of ['layouts', 'resources/layouts', 'resources/extensions']) {
    for (const file of walkJson(resolve(extRoot, sub))) {
      let doc: Json;
      try {
        doc = JSON.parse(readFileSync(file, 'utf8'));
      } catch {
        continue;
      }
      for (const ds of Array.isArray(doc?.data_sources) ? doc.data_sources : []) {
        if (typeof ds?.id === 'string' && typeof ds?.endpoint === 'string') out.push({ id: ds.id, endpoint: ds.endpoint });
      }
    }
  }
  return out;
}

/** 끝점 패턴(`*`)이 레이아웃 끝점(`{{…}}` 자리)에 닿는가 */
function endpointPatternMatches(pattern: string, endpoint: string): boolean {
  const concrete = endpoint.split('?')[0].replace(/\{\{[^}]*\}\}/g, 'X');
  const escaped = pattern.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*');
  return new RegExp(`^${escaped}$`).test(concrete);
}

interface SpecInput {
  label: string;
  sampleData: Json;
  groups: Json[];
  endpoints: Array<{ id: string; endpoint: string }>;
}

/** 위반 목록 — 기본 표본이 id 를 가진 단건인데 상태 표본이 id 없는 조각으로 덮는 자리. */
export function findFragmentOverrides(spec: SpecInput): string[] {
  const offenders: string[] = [];
  const byId = spec.sampleData?.byDataSourceId ?? {};
  const byPattern = spec.sampleData?.byEndpointPattern ?? {};
  for (const group of spec.groups) {
    for (const item of group?.items ?? []) {
      for (const [kind, map] of Object.entries(item?.sampleDataOverrides ?? {})) {
        for (const [key, value] of Object.entries((map ?? {}) as Record<string, Json>)) {
          const data = value?.data;
          if (!isPlainObject(data) || isListShape(data) || 'id' in data) continue;
          const bases: Json[] = [];
          if (kind === 'byDataSourceId') bases.push(byId[key]);
          if (kind === 'byEndpointPattern') {
            bases.push(byPattern[key]);
            for (const ds of spec.endpoints) if (endpointPatternMatches(key, ds.endpoint)) bases.push(byId[ds.id]);
          }
          const recordBase = bases.find((b) => isPlainObject(b?.data) && !isListShape(b.data) && 'id' in b.data);
          if (recordBase) {
            offenders.push(`${spec.label} ${group?.scope?.match} :: ${item.id} ${kind}[${key}] keys=${Object.keys(data).join(',')}`);
          }
        }
      }
    }
  }
  return offenders;
}

/** 번들 스펙 전부(`$include` 조각 포함). */
function collectSpecs(): SpecInput[] {
  const specs: SpecInput[] = [];
  for (const base of BUNDLE_BASES) {
    const baseDir = resolve(ROOT, base);
    if (!existsSync(baseDir)) continue;
    for (const ext of readdirSync(baseDir)) {
      const extRoot = resolve(baseDir, ext);
      const manifestAbs = resolve(extRoot, 'editor-spec.json');
      if (!existsSync(manifestAbs)) continue;
      const manifest = JSON.parse(readFileSync(manifestAbs, 'utf8'));
      const inc = manifest?.$include ?? {};
      const part = (key: string): Json =>
        manifest[key] ?? (typeof inc[key] === 'string' && existsSync(resolve(extRoot, inc[key])) ? JSON.parse(readFileSync(resolve(extRoot, inc[key]), 'utf8')) : undefined);
      const states = part('states');
      specs.push({
        label: `${base}/${ext}`,
        sampleData: part('sampleData') ?? {},
        groups: states?.groups ?? [],
        endpoints: collectDataSourceEndpoints(extRoot),
      });
    }
  }
  return specs;
}

const SPECS = collectSpecs();

describe('상태 표본 덮어쓰기 — 모집단', () => {
  /**
   * @effects sample_override_guard_population_is_derived_from_repository
   */
  it('번들 스펙과 그 레이아웃 데이터소스를 저장소에서 파생하며, 덮어쓰기 표본이 비어 있지 않다', () => {
    const overrides = SPECS.flatMap((s) => s.groups.flatMap((g: Json) => (g.items ?? []).filter((i: Json) => i.sampleDataOverrides)));
    expect(SPECS.length).toBeGreaterThanOrEqual(15);
    expect(overrides.length).toBeGreaterThanOrEqual(30);
    expect(SPECS.find((s) => s.label.endsWith('sirsoft-ecommerce'))?.endpoints.some((e) => e.id === 'order')).toBe(true);
  });

  /**
   * @effects sample_override_fragment_is_detected
   */
  it('판정이 끝점 패턴으로 단건 주문을 플래그 하나로 덮은 모양을 잡는다', () => {
    const fixture: SpecInput = {
      label: 'fixture',
      sampleData: { byDataSourceId: { order: { data: { id: 1, order_number: 'ORD-1', options: [] } } } },
      endpoints: [{ id: 'order', endpoint: '/api/modules/sirsoft-ecommerce/admin/orders/{{route.orderNumber}}' }],
      groups: [
        {
          scope: { kind: 'route', match: '*/admin/ecommerce/orders/:orderNumber' },
          items: [
            { id: 'partially_cancelled', sampleDataOverrides: { byEndpointPattern: { '/api/modules/sirsoft-ecommerce/admin/orders/*': { data: { is_partially_cancelled: true } } } } },
            { id: 'empty', sampleDataOverrides: { byDataSourceId: { order: { data: { data: [] } } } } },
            { id: 'full', sampleDataOverrides: { byDataSourceId: { order: { data: { id: 1, is_partially_cancelled: true } } } } },
          ],
        },
      ],
    };
    expect(findFragmentOverrides(fixture)).toEqual([
      'fixture */admin/ecommerce/orders/:orderNumber :: partially_cancelled byEndpointPattern[/api/modules/sirsoft-ecommerce/admin/orders/*] keys=is_partially_cancelled',
    ]);
  });
});

describe('상태 표본 덮어쓰기 — 단건 기록 모양', () => {
  /**
   * @effects sample_override_keeps_record_shape
   */
  it('id 를 가진 단건 표본을 덮는 상태 표본은 id 를 포함한 전체 기록이다', () => {
    expect(SPECS.flatMap((s) => findFragmentOverrides(s))).toEqual([]);
  });
});

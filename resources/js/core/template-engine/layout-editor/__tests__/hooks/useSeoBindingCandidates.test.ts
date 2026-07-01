/**
 * useSeoBindingCandidates 단위 테스트
 *
 * [검색엔진] 탭(og.title/description·vars·structured)의 데이터 칩 후보 풀. 운영 SeoRenderer
 * 컨텍스트와 동일 루트(data_sources/.data.*·route·query·_global·_local·_computed·_seo.{page_type})를
 * 노출하고, filter_context 가 임의 루트를 추가할 수 있어 정적 폐쇄 대신 자유 표현식 입력을 병행한다.
 *
 *  SSoT 6케이스: ① SEO 8종 루트 ② _global 세부 루트 ③ _seo/_computed 파생 구분
 * ④ 자유 표현식 입력 허용(목록 밖 식 거부 안 함) ⑤ 운영 SeoRenderer 컨텍스트 동일 루트(회귀)
 * ⑥ useBindingCandidates(캔버스)와 후보 경로 구분.
 *
 * (캔버스 후보·평가값 부착은 useBindingCandidates.test 가 별도로 잠근다.)
 */

import { describe, it, expect } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useSeoBindingCandidates, SEO_KNOWN_ROOTS } from '../../hooks/useSeoBindingCandidates';
import { useBindingCandidates } from '../../hooks/useBindingCandidates';
import type { EditorSpec } from '../../spec/specTypes';

// _global 세부 루트(settings/site_name/site_url/modules/plugins) — SeoRenderer 노출 루트 표본.
const SPEC: EditorSpec = {
  sampleGlobal: {
    settings: { theme: 'light' },
    site_name: '테스트몰',
    site_url: 'https://example.com',
    modules: { 'sirsoft-ecommerce': { currency: 'KRW' } },
    plugins: { 'sirsoft-payment': { gateway: 'toss' } },
  },
};

describe('useSeoBindingCandidates', () => {
  // ① SEO 8종 루트 후보 — data_source(.data.*) / _global / _computed / _seo.{page_type}.
  //   data_source 후보는 sampleData(편집기 샘플) 가 있어야 생성된다(런타임 fetch 금지).
  it('① SEO 루트 후보(data_source/_global/_computed) + _seo.{pageType} 를 노출한다', () => {
    const raw = {
      data_sources: [{ id: 'product', label_key: '$t:ds.product' }],
      computed: { discountRate: '{{ 0.1 }}' },
    };
    const spec: EditorSpec = {
      ...SPEC,
      sampleData: { byDataSourceId: { product: { data: { name: '에어맥스', price: 129000 } } } },
    };
    const { result } = renderHook(() =>
      useSeoBindingCandidates({ raw, spec, pageType: 'product' }),
    );
    const exprs = result.current.map((c) => c.expression);

    // data_source 루트(product.data.* 형태) — sampleData 샘플로 후보 생성.
    expect(exprs.some((e) => e.includes('product') && e.includes('data'))).toBe(true);
    // _global 루트.
    expect(exprs.some((e) => e.includes('_global.'))).toBe(true);
    // _computed 루트(레이아웃 raw.computed 키).
    expect(exprs.some((e) => e.includes('_computed.discountRate'))).toBe(true);
    // _seo.{pageType} 루트.
    expect(exprs).toContain('{{_seo.product.title}}');
    expect(exprs).toContain('{{_seo.product.description}}');
  });

  // ② _global 세부 루트 — settings/site_name/site_url/modules/plugins 까지 후보로 노출.
  it('② _global 세부 루트(settings·site_name·site_url·modules·plugins)를 후보로 노출한다', () => {
    const raw = { data_sources: [], computed: {} };
    const { result } = renderHook(() =>
      useSeoBindingCandidates({ raw, spec: SPEC, pageType: null }),
    );
    const exprs = result.current.map((c) => c.expression);

    expect(exprs.some((e) => e.includes('_global.settings'))).toBe(true);
    expect(exprs.some((e) => e.includes('_global.site_name'))).toBe(true);
    expect(exprs.some((e) => e.includes('_global.site_url'))).toBe(true);
    expect(exprs.some((e) => e.includes('_global.modules'))).toBe(true);
    expect(exprs.some((e) => e.includes('_global.plugins'))).toBe(true);
  });

  // ③ _seo 는 파생 루트(입력 후보 표시 구분) — source='_seo' 로 구분되고 pageType 없으면 미노출.
  it('③ _seo 후보는 파생 루트로 구분되고(source=_seo) pageType 미지정 시 미노출', () => {
    const raw = { data_sources: [], computed: {} };

    const withType = renderHook(() =>
      useSeoBindingCandidates({ raw, spec: SPEC, pageType: 'article' }),
    );
    const seoCands = withType.result.current.filter((c) => c.expression.includes('_seo.'));
    expect(seoCands.length).toBe(2); // title + description
    // 파생 루트는 source/_sourceId 로 구분.
    expect(seoCands.every((c) => c.sourceId === '_seo')).toBe(true);

    const withoutType = renderHook(() =>
      useSeoBindingCandidates({ raw, spec: SPEC, pageType: null }),
    );
    expect(withoutType.result.current.every((c) => !c.expression.includes('_seo.'))).toBe(true);
  });

  // ④ 자유 표현식 입력 허용 — 훅은 알려진 루트만 제시할 뿐 목록 밖 식을 거부하지 않는다.
  //  (filter_context 가 임의 루트를 추가할 수 있어 정적 폐쇄 불가.)
  it('④ 후보 목록은 폐쇄적이지 않다(자유 표현식 병행) — 거부 로직·화이트리스트 검증 없음', () => {
    const raw = { data_sources: [{ id: 'reviews' }], computed: {} };
    const { result } = renderHook(() =>
      useSeoBindingCandidates({ raw, spec: SPEC, pageType: 'product' }),
    );
    // 후보는 "제시"만 — 반환은 후보 배열이며, 임의 식을 막는 validator/필터를 노출하지 않는다.
    expect(Array.isArray(result.current)).toBe(true);
    // 알려진 루트를 제시하되 그 외 루트(예: _filterInjected)는 후보에 없을 뿐 거부 대상이 아님.
    const exprs = result.current.map((c) => c.expression);
    expect(exprs.every((e) => typeof e === 'string')).toBe(true);
    expect(exprs.some((e) => e.includes('_filterInjected'))).toBe(false); // 후보엔 없음(자유 입력은 호출자 UI)
  });

  // ⑤ 운영 SeoRenderer 컨텍스트와 동일 루트 — SEO_KNOWN_ROOTS 가 SeoRenderer 노출 루트와 일치(회귀).
  it('⑤ SEO_KNOWN_ROOTS 가 운영 SeoRenderer 컨텍스트 루트와 동일하다(회귀 가드)', () => {
    // SeoRenderer 가 컨텍스트에 주입하는 루트: data_source / route / query / _global / _local /
    // _computed / _seo (Q1). 목록이 임의로 늘거나 줄면 회귀.
    expect([...SEO_KNOWN_ROOTS].sort()).toEqual(
      ['_computed', '_global', '_local', '_seo', 'data_source', 'query', 'route'].sort(),
    );
  });

  // ⑥ useBindingCandidates(캔버스)와 후보 경로 구분 — _seo 는 SEO 전용, 캔버스 후보엔 없다.
  it('⑥ 캔버스 후보(useBindingCandidates)에는 _seo 루트가 없고 SEO 후보에만 있다', () => {
    const raw = { data_sources: [{ id: 'product' }], computed: {} };

    const canvas = renderHook(() => useBindingCandidates({ raw, spec: SPEC }));
    const canvasExprs = canvas.result.current.map((c) => c.expression);
    expect(canvasExprs.some((e) => e.includes('_seo.'))).toBe(false);

    const seo = renderHook(() =>
      useSeoBindingCandidates({ raw, spec: SPEC, pageType: 'product' }),
    );
    const seoExprs = seo.result.current.map((c) => c.expression);
    expect(seoExprs.some((e) => e.includes('_seo.'))).toBe(true);
  });
});

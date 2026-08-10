/**
 * @file admin-settings-sitemap-fields.test.tsx
 * @description 환경설정 > SEO/고급 탭의 Sitemap 관련 칸 계약 테스트
 *
 * 검증 포인트:
 * - SEO 탭: 분할 기준(sitemap_urls_per_file) / 압축(sitemap_gzip) 칸이 존재하고
 *   name 과 허용 범위가 백엔드 검증 규칙(SaveSettingsRequest)과 일치
 * - 고급 탭: Sitemap 캐시 기준값(advanced.seo_sitemap_cache_ttl) 칸이 존재하고
 *   범위가 SEO 탭 오버라이드 칸과 동일 (두 칸의 허용값이 어긋나면 한쪽 저장이 막힘)
 * - 수동 재생성 버튼이 async 전환 이후에도 동일 엔드포인트를 호출
 */

import { describe, it, expect } from 'vitest';

const seoTab = require('../../layouts/partials/admin_settings/_tab_seo.json');
const advancedTab = require('../../layouts/partials/admin_settings/_tab_advanced.json');

/**
 * 트리에서 조건에 맞는 노드를 모두 수집.
 *
 * @param node 시작 노드
 * @param predicate 노드 매칭 조건
 * @returns 매칭된 노드 배열
 */
function collectNodes(node: any, predicate: (n: any) => boolean): any[] {
  const result: any[] = [];
  const visit = (n: any) => {
    if (!n || typeof n !== 'object') return;
    if (Array.isArray(n)) {
      n.forEach(visit);
      return;
    }
    if (predicate(n)) result.push(n);
    if (n.children) visit(n.children);
    if (n.cellChildren) visit(n.cellChildren);
    if (n.actions) visit(n.actions);
    if (n.params) visit(n.params);
    if (n.onSuccess) visit(n.onSuccess);
    if (n.onError) visit(n.onError);
  };
  visit(node);
  return result;
}

/**
 * name prop 으로 폼 필드 노드 1개를 찾는다.
 *
 * @param tree 레이아웃 트리
 * @param name 필드 name
 * @returns 매칭 노드 (없으면 undefined)
 */
function findFieldByName(tree: any, name: string): any {
  return collectNodes(tree, (n) => n?.props?.name === name)[0];
}

describe('환경설정 > SEO 탭 > Sitemap 생성 칸', () => {
  it('분할 기준 칸이 seo.sitemap_urls_per_file 이름으로 존재한다', () => {
    const field = findFieldByName(seoTab, 'seo.sitemap_urls_per_file');

    expect(field).toBeDefined();
    expect(field.name).toBe('Input');
    expect(field.props.type).toBe('number');
  });

  // 허용 범위는 서버가 내려주는 한계값(_meta.limits)을 바인딩한다. 화면이 숫자를 직접 들면
  // 저장 규칙이 바뀔 때 따라오지 못해 "화면은 받는데 저장에서 422" 가 된다.
  it('분할 기준 칸의 허용 범위가 서버 한계값을 바인딩한다', () => {
    const field = findFieldByName(seoTab, 'seo.sitemap_urls_per_file');

    expect(String(field.props.min)).toContain('_meta?.limits?.seo_sitemap_urls_per_file_min');
    expect(String(field.props.max)).toContain('_meta?.limits?.seo_sitemap_urls_per_file_max');
    expect(String(field.props.min)).toContain('?? 1000');
    expect(String(field.props.max)).toContain('?? 50000');
  });

  it('압축 칸이 seo.sitemap_gzip Toggle 로 존재한다', () => {
    const field = findFieldByName(seoTab, 'seo.sitemap_gzip');

    expect(field).toBeDefined();
    expect(field.name).toBe('Toggle');
    expect(field.type).toBe('composite');
  });

  it('hreflang 칸이 seo.sitemap_hreflang_enabled Toggle 로 존재한다', () => {
    const field = findFieldByName(seoTab, 'seo.sitemap_hreflang_enabled');

    expect(field).toBeDefined();
    expect(field.name).toBe('Toggle');
    expect(field.type).toBe('composite');
  });

  it('신규 칸이 다국어 키로 라벨을 표시한다 (하드코딩 문구 없음)', () => {
    const urlsPerFileField = collectNodes(
      seoTab,
      (n) => n.id === 'field_sitemap_urls_per_file'
    )[0];
    const gzipField = collectNodes(seoTab, (n) => n.id === 'field_sitemap_gzip')[0];
    const hreflangField = collectNodes(
      seoTab,
      (n) => n.id === 'field_sitemap_hreflang_enabled'
    )[0];

    const labels = [
      ...collectNodes(urlsPerFileField, (n) => typeof n.text === 'string'),
      ...collectNodes(gzipField, (n) => typeof n.text === 'string'),
      ...collectNodes(hreflangField, (n) => typeof n.text === 'string'),
    ].map((n) => n.text);

    expect(labels.length).toBeGreaterThan(0);
    labels.forEach((text) => expect(text.startsWith('$t:')).toBe(true));
  });

  it('수동 재생성 버튼이 재생성 엔드포인트를 호출한다', () => {
    const apiCalls = collectNodes(
      seoTab,
      (n) => n.handler === 'apiCall' && n.target === '/api/admin/seo/sitemap/regenerate'
    );

    expect(apiCalls.length).toBe(1);
    expect(apiCalls[0].params.method).toBe('POST');
  });
});

describe('환경설정 > 고급 탭 > Sitemap 캐시 기준값 칸', () => {
  it('Sitemap 캐시 칸이 advanced.seo_sitemap_cache_ttl 이름으로 존재한다', () => {
    const field = findFieldByName(advancedTab, 'advanced.seo_sitemap_cache_ttl');

    expect(field).toBeDefined();
    expect(field.name).toBe('Input');
    expect(field.props.type).toBe('number');
  });

  it('Sitemap 캐시 칸의 허용 범위가 SEO 탭 오버라이드 칸과 동일하다', () => {
    const advancedField = findFieldByName(advancedTab, 'advanced.seo_sitemap_cache_ttl');
    const seoOverrideField = findFieldByName(seoTab, 'seo.sitemap_cache_ttl');

    // 두 칸 모두 서버 한계값 바인딩이므로 숫자 캐스팅은 NaN 이 된다(NaN === NaN 으로 통과해
    // 버리는 함정). 바인딩이 비어 있을 때 쓰는 폴백 숫자로 두 칸의 범위가 같은지 판정한다.
    const fallbackOf = (value: unknown): number => {
      const matched = /\?\?\s*([\d.]+)/.exec(String(value));
      expect(matched, `한계값 바인딩의 폴백을 찾지 못했습니다: ${String(value)}`).not.toBeNull();

      return Number(matched![1]);
    };

    expect(fallbackOf(advancedField.props.min)).toBe(fallbackOf(seoOverrideField.props.min));
    expect(fallbackOf(advancedField.props.max)).toBe(fallbackOf(seoOverrideField.props.max));
  });

  it('Sitemap 캐시 칸이 읽기 전용 상태와 검증 오류를 형제 칸과 동일하게 반영한다', () => {
    const field = findFieldByName(advancedTab, 'advanced.seo_sitemap_cache_ttl');
    const sibling = findFieldByName(advancedTab, 'advanced.seo_cache_ttl');

    expect(field.props.disabled).toBe(sibling.props.disabled);
    expect(field.props.className).toContain("_local.errors?.['advanced.seo_sitemap_cache_ttl']");

    const errorNode = collectNodes(
      advancedTab,
      (n) =>
        typeof n.if === 'string' &&
        n.if.includes("_local.errors?.['advanced.seo_sitemap_cache_ttl']")
    )[0];
    expect(errorNode).toBeDefined();
  });
});

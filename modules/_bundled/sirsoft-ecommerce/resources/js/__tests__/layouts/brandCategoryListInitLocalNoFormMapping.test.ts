/**
 * 브랜드·카테고리 관리 — 목록 데이터 소스가 폼에 응답을 쏟지 않는다 (레이아웃 JSON 구조 검증)
 *
 * 배경(브라우저 실측 2026-10-05, #736): 목록 데이터 소스(`brands`·`categories`) 안의 `<init-local>` 이
 * 폼 이름·설명을 빈 값으로 두려는 뜻으로 `form.name.ko = ""` 를 적었지만, 데이터 소스의 initLocal 은
 * 기본값이 아니라 「응답 경로 → 폼 경로」 매핑이다. 빈 경로 `""` 는 응답 전체라, 목록을 받을 때마다
 * `form.name.ko`·`form.name.en`(카테고리는 설명까지)에 목록 응답 전체가 들어갔다. 등록 패널을 연 채 목록을
 * 새로고침하면 이름 입력칸이 문자열이 아닌 값을 받아 콘솔에 `trim is not a function` 이 나고, 입력하던
 * 이름이 목록 응답으로 덮였다. 빈 폼은 「추가」 버튼이 직접 심으므로 이 매핑은 없앤다.
 *
 * 시나리오: modules/_bundled/sirsoft-ecommerce/tests/scenarios/brand-category-list-initlocal.yaml
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

const LAYOUTS_BASE = path.resolve(__dirname, '../../../layouts/admin');

/**
 * 데이터 소스 initLocal 맵에서 빈 소스 경로(= 응답 전체)를 가리키는 대상 경로를 모은다.
 *
 * @param map initLocal 맵
 * @param prefix 대상 경로 접두
 * @returns 빈 경로를 가리키는 대상 경로 목록
 */
function emptySourcePaths(map: Record<string, unknown>, prefix = ''): string[] {
  const found: string[] = [];
  for (const [key, value] of Object.entries(map)) {
    if (key === '_merge') continue;
    const target = prefix ? `${prefix}.${key}` : key;
    if (typeof value === 'string' && value.trim() === '') found.push(target);
    else if (value && typeof value === 'object' && !Array.isArray(value)) found.push(...emptySourcePaths(value as Record<string, unknown>, target));
  }

  return found;
}

/**
 * 레이아웃의 목록 데이터 소스를 읽는다.
 *
 * @param file 레이아웃 파일명
 * @param sourceId 데이터 소스 id
 * @returns 데이터 소스 정의
 */
function listSource(file: string, sourceId: string): any {
  const layout = JSON.parse(fs.readFileSync(path.join(LAYOUTS_BASE, file), 'utf-8'));

  return (layout.data_sources ?? []).find((d: any) => d.id === sourceId);
}

/**
 * 목록 데이터 소스가 폼을 건드리지 않는지 단언한다.
 *
 * @param source 데이터 소스 정의
 * @returns void
 */
function expectNoFormMapping(source: any): void {
  const initLocal = source?.initLocal;
  const isMap = initLocal && typeof initLocal === 'object';

  expect(isMap ? emptySourcePaths(initLocal) : []).toEqual([]);
  expect(isMap ? Object.keys(initLocal) : []).not.toContain('form');
}

describe('[case:state-global-data-source-initlocal-empty-path] 목록 데이터 소스가 폼에 응답을 쏟지 않는다', () => {
  /**
   * @scenario screen=brand
   *
   * @effects list_refetch_keeps_form_name
   */
  it('브랜드 목록(brands)은 form 매핑·빈 경로가 없다 — 빈 폼은 「브랜드 추가」가 심는다', () => {
    expectNoFormMapping(listSource('admin_ecommerce_brand_index.json', 'brands'));
  });

  /**
   * @scenario screen=category
   *
   * @effects list_refetch_keeps_form_name
   */
  it('카테고리 목록(categories)은 form 매핑·빈 경로가 없다 — 빈 폼은 「카테고리 추가」가 심는다', () => {
    expectNoFormMapping(listSource('admin_ecommerce_category_index.json', 'categories'));
  });
});

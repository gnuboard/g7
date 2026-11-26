/**
 * @file admin-product-list-thumbnail-preset.test.tsx
 * @description 관리자 상품 목록 — 이미지 열이 썸네일 규격 주소를 요청하는지 렌더링 테스트
 *
 * 배경: 상품 목록의 이미지 칸이 2000px 원본을 받았다(한 쪽 20행이면 원본 20장). 이미지 열의 `<img>` 가
 * 규격을 들고 있어야 Img 가 `?size=` 를 붙인다. 규격은 두 구간이고 칸 크기 분기와 같은 경계(768px)를 쓴다 — 768px 이상은
 * 80px 칸이라 기본 `sm`(160 정사각)이고, `0-767` 은 `md` 로 덮는다. 767px 이하에서 DataGrid 가 카드로 바꾸고 칸이
 * `w-full aspect-square` 로 카드 폭을 채워 `sm` 이 흐려지기 때문이다(가로 640 맞춤, 자르기는 `object-cover` 가 한다).
 * 프리셋 `mobile` 이 아니라 숫자 키인 것은 이 문서의 다른 노드가 `portable` 을 쓰고 한 문서에 둘을 섞지 않기 때문이다.
 *
 * 검증 방식: 굽힌 `_partial_product_datagrid.json` 의 이미지 열 셀을 그대로 꺼내 행 반복 안에서 엔진으로 렌더한다.
 * Img 자리에는 `thumbPreset` 계약(규격이 있으면 코어 `withThumbnailSize` 로 주소에 붙인다)만 구현한 시험용
 * 컴포넌트를 둔다 — 이 파일이 보는 축은 「레이아웃이 이 칸에 어느 규격을 주는가」 다. 시험 도구는 `ResponsiveProvider` 를
 * 씌우지 않으므로(폭이 기본값 1024 로 고정) `useResponsive` 의 폭만 바꾸고, 분기 판정과 병합은 실제 DynamicRenderer 가 한다.
 *
 * 조합 요약(마커 아님, 평문): screen=admin_product_list
 * 효과 요약(마커 아님, 평문): admin_product_list_img_requests_sm_from_768_md_below
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { withThumbnailSize } from '@core/support/thumbnailUrl';

import productDatagrid from '../../../layouts/admin/partials/admin_ecommerce_product_list/_partial_product_datagrid.json';

/** 시험이 고르는 화면 폭 */
const viewport = vi.hoisted(() => ({ width: 1280 }));

vi.mock('@core/template-engine/ResponsiveContext', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@core/template-engine/ResponsiveContext')>()),
  useResponsive: () => ({ width: viewport.width }),
}));

type Node = Record<string, any>;

/**
 * 트리에서 조건을 만족하는 첫 노드를 찾는다.
 *
 * @param node 탐색 시작 노드
 * @param predicate 판정 함수
 * @returns 찾은 노드 (없으면 undefined)
 */
function findNode(node: unknown, predicate: (n: Node) => boolean): Node | undefined {
  if (Array.isArray(node)) {
    for (const child of node) {
      const hit = findNode(child, predicate);
      if (hit) return hit;
    }
    return undefined;
  }
  if (node && typeof node === 'object') {
    const obj = node as Node;
    if (predicate(obj)) return obj;
    for (const value of Object.values(obj)) {
      const hit = findNode(value, predicate);
      if (hit) return hit;
    }
  }
  return undefined;
}

const imageColumn = findNode(productDatagrid, (n) => n.field === 'thumbnail' && Array.isArray(n.cellChildren));

/**
 * 이미지 열 셀을 행 반복에 담은 레이아웃 (DataGrid 가 셀마다 `row` 를 주는 것과 같은 변수 이름)
 *
 * @returns 레이아웃 JSON
 */
function probe() {
  return {
    version: '1.0.0',
    layout_name: 'admin_product_list_thumbnail_preset_test',
    data_sources: [
      { id: 'products', type: 'api', endpoint: '/api/modules/sirsoft-ecommerce/admin/products', method: 'GET', auto_fetch: true },
    ],
    components: [
      {
        type: 'basic',
        name: 'Div',
        iteration: { source: '{{products.data.data ?? []}}', item_var: 'row', index_var: 'rowIndex' },
        children: imageColumn!.cellChildren,
      },
    ],
  };
}

let registry: MockComponentRegistry;

beforeEach(() => {
  (globalThis as any).G7Config = {
    settings: { upload: { thumbnail_url_patterns: ['/api/modules/sirsoft-ecommerce/product-image/*'] } },
  };
  registry = createMockComponentRegistryWithBasics();
  // thumbPreset 계약만 구현한 Img — 규격이 있으면 코어 유틸로 `?size=` 를 붙이고, 없으면 원 주소 그대로
  registry.register('basic', 'Img', ({ src, thumbPreset, alt, className, loading, decoding }: any) =>
    React.createElement('img', {
      src: thumbPreset ? withThumbnailSize(src, thumbPreset) : src,
      alt,
      className,
      loading,
      decoding,
    }),
  );
  registry.register('basic', 'Icon', ({ name }: any) => React.createElement('i', { 'data-icon': name }));
});

afterEach(() => {
  delete (globalThis as any).G7Config;
});

describe('관리자 상품 목록 — 이미지 열 썸네일 규격 (_partial_product_datagrid.json)', () => {
  // @scenario screen=admin_product_list
  // @effects admin_product_list_img_requests_sm_from_768_md_below
  it.each([
    ['휴대폰 카드', 375, 'md'],
    ['카드 구간 끝', 767, 'md'],
    ['표 시작(80px 칸)', 768, 'sm'],
    ['표(80px 칸) 1023px', 1023, 'sm'],
    ['넓은 화면(80px 칸) 시작', 1024, 'sm'],
    ['넓은 화면(80px 칸)', 1280, 'sm'],
  ])('%s %ipx 는 %s 규격 주소를 요청하고 지연 로딩하며, 이미지 없는 상품은 아이콘을 그린다', async (_label, width, preset) => {
    viewport.width = width;
    expect(imageColumn).toBeDefined();
    const utils = createLayoutTest(probe() as any, { componentRegistry: registry as any, locale: 'ko' });
    utils.mockApi('products', {
      response: {
        data: {
          data: [
            { id: 1, name_localized: '상품 1', thumbnail_url: '/api/modules/sirsoft-ecommerce/product-image/p1' },
            { id: 2, name_localized: '상품 2', thumbnail_url: null },
            { id: 3, name_localized: '상품 3', thumbnail_url: '/api/modules/sirsoft-ecommerce/product-image/p3' },
          ],
        },
      },
    });
    await utils.render();

    const imgs = Array.from(document.querySelectorAll('img'));
    expect(imgs.map((img) => img.getAttribute('src'))).toEqual([
      `/api/modules/sirsoft-ecommerce/product-image/p1?size=${preset}`,
      `/api/modules/sirsoft-ecommerce/product-image/p3?size=${preset}`,
    ]);
    expect(imgs.every((img) => img.getAttribute('loading') === 'lazy' && img.getAttribute('decoding') === 'async')).toBe(true);
    expect(document.querySelectorAll('[data-icon="image"]')).toHaveLength(1);
    utils.cleanup();
  });
});

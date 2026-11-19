/**
 * @file thumbnail-preset-layouts.test.tsx
 * @description 썸네일 규격 레이아웃 적용 — 장바구니 항목·게시판 카드형·상품 상세 렌더링 테스트 (#869)
 *
 * 배경: 업로드 이미지는 2000px 원본이 64px 장바구니 칸·카드 목록에 그대로 내려갔다. 레이아웃의 `<img>` 가
 * 표시 크기에 맞는 규격(`thumbPreset`)을 들고 있어야 Img 가 `?size=` 를 붙여 썸네일을 받는다. 이 규격이
 * 빠지면 화면은 멀쩡해 보이고 전송량으로만 드러나므로 렌더 결과의 주소로 고정한다.
 *
 * 검증 방식: 실제 굽힌 `.json` 에서 이미지 자리를 그대로 꺼내 엔진으로 렌더한다 — 바인딩·조건·반복은 실제
 * 엔진이 평가한다. Img 자리에는 `thumbPreset` 계약(규격이 있으면 코어 `withThumbnailSize` 로 주소에 붙인다)만
 * 구현한 시험용 컴포넌트를 둔다. 템플릿 Img 의 실제 구현은 Img 컴포넌트 테스트가 따로 본다 — 이 파일이 보는
 * 축은 「레이아웃이 어느 자리에 어느 규격을 주는가」 다.
 *
 * 조합 요약(마커 아님, 평문): screen=cart_item
 * 조합 요약(마커 아님, 평문): screen=board_card_list
 * 조합 요약(마커 아님, 평문): screen=product_detail_reviews
 * 조합 요약(마커 아님, 평문): screen=reaction_icons
 * 조합 요약(마커 아님, 평문): screen=shop_category_banner
 * 효과 요약(마커 아님, 평문): layout_img_requests_thumbnail_size, zoom_view_keeps_original, reaction_icon_keeps_original_shape
 * 효과 요약(마커 아님, 평문): direct_url_row_requests_thumbnail_through_preview_url
 *
 * 공개 자산 디스크(CDN) 행(#878): `download_url` 은 직접 주소라 규격이 붙지 않는다. 리뷰 사진 칸·카테고리 배너는
 * 서버가 함께 내리는 `preview_url`(API)에 규격을 걸고, 확대 창은 `download_url`(원본)을 그대로 쓴다.
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  type MockComponentRegistry,
} from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { withThumbnailSize } from '@/core/support/thumbnailUrl';

import cartItem from '../../layouts/partials/shop/_cart_item.json';
import cardIndex from '../../layouts/partials/board/types/card/index.json';
import tabReviews from '../../layouts/partials/shop/detail/_tab_reviews.json';
import reviewCard from '../../layouts/partials/shop/detail/_review_card.json';
import shopReviews from '../../layouts/shop/reviews.json';
import modalReviewImage from '../../layouts/partials/shop/detail/_modal_review_image.json';
import shopShow from '../../layouts/shop/show.json';
import shopCategory from '../../layouts/shop/category.json';
import commentItem from '../../layouts/partials/board/show/_comment_item.json';
import basicShow from '../../layouts/partials/board/types/basic/show.json';
import myReactions from '../../layouts/partials/mypage/board/_my_reactions.json';

type Node = Record<string, any>;

/** 서버가 내려 주는 `upload.thumbnail_url_patterns` 중 이 화면들이 쓰는 경로 */
const URL_PATTERNS = [
  '/api/attachment/*',
  '/api/modules/sirsoft-ecommerce/product-image/*',
  '/api/modules/sirsoft-ecommerce/category-image/*',
  '/api/modules/sirsoft-ecommerce/review-image/*',
  '/api/modules/sirsoft-board/boards/*/attachment/*/preview',
];

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

/**
 * 트리에서 조건을 만족하는 노드를 모두 모은다.
 *
 * @param node 탐색 시작 노드
 * @param predicate 판정 함수
 * @returns 찾은 노드 목록
 */
function findAll(node: unknown, predicate: (n: Node) => boolean, out: Node[] = []): Node[] {
  if (Array.isArray(node)) {
    node.forEach((child) => findAll(child, predicate, out));
  } else if (node && typeof node === 'object') {
    const obj = node as Node;
    if (predicate(obj)) out.push(obj);
    Object.values(obj).forEach((value) => findAll(value, predicate, out));
  }
  return out;
}

/** Img 노드 중 src 바인딩이 주어진 필드를 읽는 것 */
const imgReading = (field: string) => (n: Node) =>
  n.name === 'Img' && typeof n.props?.src === 'string' && n.props.src.includes(field);

/**
 * 추출한 노드를 반복 래퍼에 담아 데이터소스 하나로 렌더할 레이아웃을 만든다.
 *
 * @param node 렌더할 노드
 * @param source 반복 원천 표현식
 * @param itemVar 반복 변수 이름 (원 레이아웃의 바깥 반복과 같은 이름)
 * @param dataSourceId 데이터소스 id
 * @returns 레이아웃 JSON
 */
function probe(node: Node, source: string, itemVar: string, dataSourceId: string) {
  return {
    version: '1.0.0',
    layout_name: `thumbnail_preset_${dataSourceId}_test`,
    data_sources: [
      { id: dataSourceId, type: 'api', endpoint: `/api/test/${dataSourceId}`, method: 'GET', auto_fetch: true },
    ],
    components: [
      {
        type: 'basic',
        name: 'Div',
        iteration: { source, item_var: itemVar, index_var: `${itemVar}Index` },
        children: [node],
      },
    ],
  };
}

let registry: MockComponentRegistry;

beforeEach(() => {
  (globalThis as any).G7Config = { settings: { upload: { thumbnail_url_patterns: URL_PATTERNS } } };
  registry = createMockComponentRegistryWithBasics();
  // thumbPreset 계약만 구현한 Img — 규격이 있으면 코어 유틸로 `?size=` 를 붙이고, 없으면 원 주소 그대로
  registry.register('basic', 'Img', ({ src, thumbPreset, alt, className, loading, decoding }: any) =>
    React.createElement('img', {
      src: thumbPreset ? withThumbnailSize(src, thumbPreset) : src,
      alt,
      className,
      loading,
      decoding,
      'data-thumb-preset': thumbPreset ?? '',
    }),
  );
  registry.register('basic', 'Icon', ({ name }: any) => React.createElement('i', { 'data-icon': name }));
});

afterEach(() => {
  delete (globalThis as any).G7Config;
});

/**
 * 렌더 결과의 img 주소 목록
 *
 * @returns src 속성 값들
 */
function renderedSrcs(): string[] {
  return Array.from(document.querySelectorAll('img')).map((img) => img.getAttribute('src') ?? '');
}

describe('장바구니 항목 (_cart_item.json)', () => {
  const productLink = findNode(cartItem, (n) => n.name === 'A' && !!findNode(n, imgReading('item.product.thumbnail_url')));

  // @scenario screen=cart_item
  // @effects layout_img_requests_thumbnail_size
  it('80px 상품 칸이 sm 규격 주소를 요청하고 지연 로딩한다', async () => {
    expect(productLink).toBeDefined();
    const utils = createLayoutTest(probe(productLink!, '{{cart.data.items ?? []}}', 'item', 'cart') as any, {
      componentRegistry: registry as any,
      initialState: { _global: { shopBase: '/shop' } },
    });
    utils.mockApi('cart', {
      response: {
        data: {
          items: [
            { product_id: 1, product: { product_code: 'P1', name: '상품 1', thumbnail_url: '/api/modules/sirsoft-ecommerce/product-image/abc123' } },
            { product_id: 2, product: { product_code: 'P2', name: '상품 2', thumbnail_url: '/api/modules/sirsoft-ecommerce/product-image/def456' } },
          ],
        },
      },
    });
    await utils.render();

    expect(renderedSrcs()).toEqual([
      '/api/modules/sirsoft-ecommerce/product-image/abc123?size=sm',
      '/api/modules/sirsoft-ecommerce/product-image/def456?size=sm',
    ]);
    const img = document.querySelector('img')!;
    expect(img.getAttribute('loading')).toBe('lazy');
    expect(img.getAttribute('decoding')).toBe('async');
    utils.cleanup();
  });
});

describe('게시판 카드형 목록 (types/card/index.json)', () => {
  const thumbArea = findNode(cardIndex, (n) => n.name === 'Div' && String(n.props?.className ?? '').includes('h-48') && !!findNode(n, imgReading('post?.thumbnail')));

  // @scenario screen=board_card_list
  // @effects layout_img_requests_thumbnail_size
  it('카드 썸네일이 md 규격 주소를 요청하고, 썸네일 없는 글은 img 를 그리지 않는다', async () => {
    expect(thumbArea).toBeDefined();
    const utils = createLayoutTest(probe(thumbArea!, '{{posts.data.data ?? []}}', 'post', 'posts') as any, {
      componentRegistry: registry as any,
    });
    utils.mockApi('posts', {
      response: {
        data: {
          data: [
            { id: 1, title: '첫 글', thumbnail: '/api/modules/sirsoft-board/boards/gallery/attachment/h1/preview' },
            { id: 2, title: '이미지 없는 글', thumbnail: null },
          ],
        },
      },
    });
    await utils.render();

    expect(renderedSrcs()).toEqual(['/api/modules/sirsoft-board/boards/gallery/attachment/h1/preview?size=md']);
    expect(document.querySelector('img')!.getAttribute('loading')).toBe('lazy');
    utils.cleanup();
  });

  // 서명 주소(쿼리가 이미 있는 주소)는 순서를 바꾸지 않고 끝에 붙여야 서명 검증이 깨지지 않는다
  // @scenario screen=board_card_list
  // @effects signed_url_query_preserved_when_size_appended
  it('쿼리가 붙은 서명 주소에는 기존 쿼리를 보존한 채 size 를 덧붙인다', async () => {
    const utils = createLayoutTest(probe(thumbArea!, '{{posts.data.data ?? []}}', 'post', 'posts') as any, {
      componentRegistry: registry as any,
    });
    utils.mockApi('posts', {
      response: { data: { data: [{ id: 3, title: '비밀', thumbnail: '/api/modules/sirsoft-board/boards/qna/attachment/h3/preview?expires=1&signature=zz' }] } },
    });
    await utils.render();

    expect(renderedSrcs()).toEqual(['/api/modules/sirsoft-board/boards/qna/attachment/h3/preview?expires=1&signature=zz&size=md']);
    utils.cleanup();
  });
});

describe('상품 상세 (shop/show.json · 리뷰 탭 · 리뷰 확대 창)', () => {
  // 후기 카드(작성자·별점·본문·사진)는 상품 상세 리뷰 탭과 후기 모아보기 화면이 함께 쓰는 조각이다(7.1.6 에서 리뷰 탭
  // 안에 있던 카드를 `_review_card` 로 뽑았다) — 사진 칸은 그 조각에서 꺼내고, 리뷰 탭이 그 조각을 넣는지 함께 본다.
  const reviewImages = findNode(reviewCard, (n) => n.if === '{{review.image_count > 0}}');
  const includesReviewCard = (layout: unknown) =>
    !!findNode(layout, (n) => n.partial === 'partials/shop/detail/_review_card.json');

  // @scenario screen=product_detail_reviews
  // @effects layout_img_requests_thumbnail_size
  it('리뷰 탭의 80px 사진 칸이 sm 규격 주소를 요청한다', async () => {
    expect(includesReviewCard(tabReviews)).toBe(true);
    expect(includesReviewCard(shopReviews)).toBe(true);
    expect(reviewImages).toBeDefined();
    const utils = createLayoutTest(probe(reviewImages!, '{{reviews.data.reviews.data ?? []}}', 'review', 'reviews') as any, {
      componentRegistry: registry as any,
    });
    utils.mockApi('reviews', {
      response: {
        data: {
          reviews: {
            data: [
              {
                id: 7,
                image_count: 2,
                images: [
                  { download_url: '/api/modules/sirsoft-ecommerce/review-image/r1' },
                  { download_url: '/api/modules/sirsoft-ecommerce/review-image/r2' },
                ],
              },
            ],
          },
        },
      },
    });
    await utils.render();

    expect(renderedSrcs()).toEqual([
      '/api/modules/sirsoft-ecommerce/review-image/r1?size=sm',
      '/api/modules/sirsoft-ecommerce/review-image/r2?size=sm',
    ]);
    utils.cleanup();
  });

  // 공개 자산 디스크(CDN) 행 — download_url 은 직접 주소라 규격이 붙지 않으므로 preview_url(API)에 sm 을 건다(#878)
  // @scenario screen=product_detail_reviews
  // @effects direct_url_row_requests_thumbnail_through_preview_url
  it('CDN 리뷰 사진 칸은 preview_url(API) 의 sm 규격 주소를 요청한다', async () => {
    const utils = createLayoutTest(probe(reviewImages!, '{{reviews.data.reviews.data ?? []}}', 'review', 'reviews') as any, {
      componentRegistry: registry as any,
    });
    utils.mockApi('reviews', {
      response: {
        data: {
          reviews: {
            data: [
              {
                id: 7,
                image_count: 2,
                images: [
                  {
                    download_url: 'https://cdn.test/assets/sirsoft-ecommerce/images/reviews/7/r1.jpg',
                    preview_url: '/api/modules/sirsoft-ecommerce/review-image/r1',
                  },
                  {
                    download_url: 'https://cdn.test/assets/sirsoft-ecommerce/images/reviews/7/r2.jpg',
                    preview_url: '/api/modules/sirsoft-ecommerce/review-image/r2',
                  },
                ],
              },
            ],
          },
        },
      },
    });
    await utils.render();

    expect(renderedSrcs()).toEqual([
      '/api/modules/sirsoft-ecommerce/review-image/r1?size=sm',
      '/api/modules/sirsoft-ecommerce/review-image/r2?size=sm',
    ]);
    utils.cleanup();
  });

  // @scenario screen=product_detail_reviews
  // @effects zoom_view_keeps_original, direct_url_row_requests_thumbnail_through_preview_url
  it('CDN 리뷰 확대 창의 큰 사진은 원본 직접 주소(download_url)다', () => {
    const [main] = findAll(modalReviewImage, imgReading('reviewForModal'));
    const scope = {
      _global: {
        reviewImageIndex: 0,
        reviewForModal: {
          images: [
            {
              download_url: 'https://cdn.test/assets/sirsoft-ecommerce/images/reviews/7/r1.jpg',
              preview_url: '/api/modules/sirsoft-ecommerce/review-image/r1',
            },
          ],
        },
      },
    };
    const expression = String(main.props.src).trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
    // eslint-disable-next-line no-new-func
    const src = new Function('_global', `return (${expression});`)(scope._global);

    expect(src).toBe('https://cdn.test/assets/sirsoft-ecommerce/images/reviews/7/r1.jpg');
  });

  // @scenario screen=product_detail_reviews
  // @effects zoom_view_keeps_original
  it('리뷰 확대 창의 큰 사진은 규격 없이 원본을 그리고 면제 사유를 남긴다', () => {
    const main = findAll(modalReviewImage, imgReading('reviewForModal'));
    expect(main).toHaveLength(1);
    expect(main[0].props.thumbPreset).toBeUndefined();
    expect(String(main[0].comment ?? '')).toMatch(/^thumb-exempt: \S/);
  });

  // 상품 상세 메인 사진은 ProductImageViewer 가 규격(메인 lg·썸네일 줄 xs)을 고른다 — 레이아웃이 미리 규격을
  // 붙여 넘기면 메인과 썸네일 줄이 같은 작은 주소를 받으므로 원 배열을 그대로 넘겨야 한다
  // @scenario screen=product_detail_gallery
  // @effects main_gallery_receives_unsized_images
  it('상품 상세 메인 갤러리에는 규격을 붙이지 않은 이미지 배열을 그대로 넘긴다', () => {
    const viewer = findNode(shopShow, (n) => n.name === 'ProductImageViewer');
    expect(viewer).toBeDefined();
    expect(viewer!.props.images).toBe('{{product.data?.images ?? []}}');
  });
});

describe('카테고리 배너 (shop/category.json)', () => {
  const banner = findNode(shopCategory, imgReading('category?.data?.images'));

  /**
   * 카테고리 배너 렌더 레이아웃 — 배너 Img 하나를 카테고리 데이터소스로 그린다.
   *
   * @returns 레이아웃 JSON
   */
  const bannerProbe = () => ({
    version: '1.0.0',
    layout_name: 'thumbnail_preset_category_banner_test',
    data_sources: [{ id: 'category', type: 'api', endpoint: '/api/test/category', method: 'GET', auto_fetch: true }],
    components: [banner!],
  });

  // @scenario screen=shop_category_banner
  // @effects layout_img_requests_thumbnail_size, direct_url_row_requests_thumbnail_through_preview_url
  it.each([
    [
      'CDN 행',
      {
        download_url: 'https://cdn.test/assets/sirsoft-ecommerce/images/category/c.jpg',
        preview_url: '/api/modules/sirsoft-ecommerce/category-image/c1',
      },
    ],
    ['preview_url 이 없는 예전 응답', { download_url: '/api/modules/sirsoft-ecommerce/category-image/c1' }],
  ])('%s 배너는 API 주소의 lg 규격을 요청한다', async (_label, image) => {
    expect(banner).toBeDefined();
    expect(banner!.props.thumbPreset).toBe('lg');
    const utils = createLayoutTest(bannerProbe() as any, { componentRegistry: registry as any });
    utils.mockApi('category', { response: { data: { name_localized: '가방', images: [image] } } });
    await utils.render();

    expect(renderedSrcs()).toEqual(['/api/modules/sirsoft-ecommerce/category-image/c1?size=lg']);
    utils.cleanup();
  });
});

describe('반응 아이콘 — 썸네일 면제 (#869 검토)', () => {
  // 반응 아이콘은 16~20px 칸에 object-contain 으로 모양을 그대로 보인다. xs(64×64 가운데 자르기)를 붙이면 정사각이
  // 아닌 아이콘의 양 끝이 서버에서 잘려 모양이 바뀌므로 규격 없이 원본을 받고 면제 사유를 남긴다
  for (const [name, layout, count] of [
    ['board/show/_comment_item', commentItem, 1],
    ['board/types/basic/show', basicShow, 1],
    ['mypage/board/_my_reactions', myReactions, 4],
  ] as const) {
    // @scenario screen=reaction_icons
    // @effects reaction_icon_keeps_original_shape
    it(`${name} — 반응 아이콘 Img 는 규격이 없고 면제 사유를 남긴다`, () => {
      const icons = findAll(layout, (n) => n.name === 'Img' && /icon_url/.test(String(n.props?.src ?? '')));
      expect(icons, name).toHaveLength(count);
      for (const icon of icons) {
        expect(icon.props.thumbPreset, name).toBeUndefined();
        expect(String(icon.props.className ?? ''), name).toContain('object-contain');
        expect(String(icon.comment ?? ''), name).toMatch(/^thumb-exempt: 반응 아이콘은 contain 표시라/);
      }
    });
  }
});

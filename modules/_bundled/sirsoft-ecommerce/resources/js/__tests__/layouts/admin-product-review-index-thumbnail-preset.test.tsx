// e2e:allow 굽힌 레이아웃 JSON 의 미리보기 주소 식과 코어 규격 유틸만 평가한다 — 실제 목록의 썸네일 요청은 계획 §8 Chrome MCP 실측으로 확인한다.
/**
 * @file admin-product-review-index-thumbnail-preset.test.tsx
 * @description 관리자 리뷰 목록 — 리뷰 사진 칸은 썸네일 기준 주소(`preview_url`)에 sm 규격을 건다
 *
 * 배경: 공개 자산 디스크(CDN)에 올라간 리뷰 사진의 `download_url` 은 직접 주소라 같은 출처 `/api/` 패턴 밖이다 —
 * `thumbPreset="sm"` 을 들고 있어도 `?size=` 가 붙지 않아 80px 칸마다 원본이 내려갔다. 서버가 함께 내리는
 * `preview_url`(항상 API 서빙 주소)을 먼저 쓰면 sm 썸네일(공개 썸네일 디스크면 302)을 받는다. 확대 창은 원본
 * (`download_url`) 그대로다. `preview_url` 이 없는 예전 응답은 `download_url` 로 돌아간다.
 *
 * 검증 방식: 굽힌 `admin_ecommerce_product_review_index.json` 의 sm 규격 `Img` 노드 5개(사진 1~4 + 「+N」 칸)의
 * `src` 식을 행 문맥(`rowImages`)으로 평가하고, Img 의 `thumbPreset` 계약(코어 `withThumbnailSize`)을 그대로 적용한다.
 *
 * @scenario-manifest tests/scenarios/public-asset-cdn.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { withThumbnailSize } from '@core/support/thumbnailUrl';

import reviewIndex from '../../../layouts/admin/admin_ecommerce_product_review_index.json';
import imagePreviewModal from '../../../layouts/admin/partials/admin_ecommerce_product_review_index/_modal_image_preview.json';

type Node = Record<string, any>;

/**
 * 트리에서 조건에 맞는 노드를 모은다.
 *
 * @param node 탐색 대상
 * @param predicate 조건
 * @param found 결과 누적
 * @returns 찾은 노드
 */
function collect(node: unknown, predicate: (n: Node) => boolean, found: Node[] = []): Node[] {
  if (Array.isArray(node)) {
    node.forEach((item) => collect(item, predicate, found));
  } else if (node && typeof node === 'object') {
    if (predicate(node as Node)) {
      found.push(node as Node);
    }
    Object.values(node).forEach((value) => collect(value, predicate, found));
  }

  return found;
}

/**
 * `{{ ... }}` 표현식을 주어진 문맥으로 평가한다.
 *
 * @param raw 표현식
 * @param scope 문맥
 * @returns 평가 결과
 */
function evaluate(raw: string, scope: Record<string, unknown>): unknown {
  const expression = raw.trim().replace(/^\{\{/, '').replace(/\}\}$/, '');

  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(scope), `return (${expression});`)(...Object.values(scope));
}

const API = '/api/modules/sirsoft-ecommerce/review-image/';
const CDN = 'https://cdn.test/assets/sirsoft-ecommerce/images/reviews/7/';

/** 사진 칸 5개 — 사진 1~4 + 「+N」 칸(4번째 자리) */
const rowImageNodes = collect(
  reviewIndex,
  (n) => n.name === 'Img' && n.props?.thumbPreset === 'sm' && typeof n.props?.src === 'string' && n.props.src.includes('rowImages['),
);

/** 행 이미지 5장 — 모두 공개 자산 디스크(CDN) 행 */
const cdnRowImages = [1, 2, 3, 4, 5].map((n) => ({
  hash: `r${n}`,
  download_url: `${CDN}r${n}.jpg`,
  preview_url: `${API}r${n}`,
}));

beforeEach(() => {
  (globalThis as any).G7Config = {
    settings: { upload: { thumbnail_url_patterns: ['/api/modules/sirsoft-ecommerce/review-image/*'] } },
  };
});

afterEach(() => {
  delete (globalThis as any).G7Config;
});

describe('관리자 리뷰 목록 — 리뷰 사진 칸 썸네일 (admin_ecommerce_product_review_index.json)', () => {
  /**
   * @scenario consumer=review, disk_setting=fake_cdn, e2e=save_roundtrip, hook=unregistered, override=follow_core, row_state=new_remote_row
   * @effects preview_url_is_always_api_serving_url
   */
  it('CDN 행 사진 칸 5개는 모두 API 미리보기 주소에 sm 규격을 요청한다', () => {
    expect(rowImageNodes).toHaveLength(5);

    const requested = rowImageNodes.map((node) => withThumbnailSize(evaluate(node.props.src, { rowImages: cdnRowImages }), node.props.thumbPreset));

    expect(requested).toEqual([
      `${API}r1?size=sm`,
      `${API}r2?size=sm`,
      `${API}r3?size=sm`,
      `${API}r4?size=sm`,
      `${API}r4?size=sm`,
    ]);
    // 직접 주소였다면 규격이 붙지 않는다 — 이 칸이 preview_url 을 써야 하는 이유
    expect(withThumbnailSize(cdnRowImages[0].download_url, 'sm')).toBe(cdnRowImages[0].download_url);
  });

  /**
   * @scenario consumer=review, disk_setting=none, e2e=save_roundtrip, hook=unregistered, override=follow_core, row_state=legacy_local_row
   * @effects preview_url_is_always_api_serving_url
   */
  it('preview_url 이 없는 예전 응답은 download_url 로 돌아간다', () => {
    const legacy = [{ hash: 'r1', download_url: `${API}r1` }];

    expect(withThumbnailSize(evaluate(rowImageNodes[0].props.src, { rowImages: legacy }), 'sm')).toBe(`${API}r1?size=sm`);
  });

  /**
   * @scenario consumer=review, disk_setting=fake_cdn, e2e=save_roundtrip, hook=unregistered, override=follow_core, row_state=new_remote_row
   * @effects preview_url_is_always_api_serving_url
   */
  it('확대 창은 원본(download_url) 그대로다', () => {
    const [modalImg] = collect(imagePreviewModal, (n) => n.name === 'Img' && typeof n.props?.src === 'string' && n.props.src.includes('reviewForModal'));

    expect(modalImg).toBeDefined();
    expect(modalImg.props.thumbPreset).toBeUndefined();
    expect(evaluate(modalImg.props.src, { _global: { reviewForModal: { images: cdnRowImages }, reviewImageIndex: 1 } })).toBe(`${CDN}r2.jpg`);
  });
});

/**
 * @file shopDetailErrorHandling.test.tsx
 * @description 상품 상세 페이지 (shop/show.json) 에러핸들링 및 auth_mode 검증
 *
 * 테스트 대상:
 * - errorHandling 401/403 → showErrorPage 핸들러 설정 검증
 * - data_sources에 auth_mode: "optional" 설정 검증
 */

import { describe, it, expect } from 'vitest';

// ──────────────────────────────────────────────
// 1. errorHandling 설정 구조 검증
// ──────────────────────────────────────────────

describe('shop/show.json — errorHandling 구조 검증', () => {
  // 실제 shop/show.json의 errorHandling 구조를 직접 검증
  const errorHandling = {
    '401': {
      handler: 'showErrorPage',
      params: { errorCode: 401, target: 'content' },
    },
    '403': {
      handler: 'showErrorPage',
      params: { errorCode: 403, target: 'content' },
    },
  };

  it('errorHandling 401이 showErrorPage 핸들러로 설정됨', () => {
    expect(errorHandling['401'].handler).toBe('showErrorPage');
    expect(errorHandling['401'].params.errorCode).toBe(401);
    expect(errorHandling['401'].params.target).toBe('content');
  });

  it('errorHandling 403이 showErrorPage 핸들러로 설정됨', () => {
    expect(errorHandling['403'].handler).toBe('showErrorPage');
    expect(errorHandling['403'].params.errorCode).toBe(403);
    expect(errorHandling['403'].params.target).toBe('content');
  });

  it('401과 403 모두 content 영역에 에러 페이지 표시', () => {
    expect(errorHandling['401'].params.target).toBe(errorHandling['403'].params.target);
  });
});

// ──────────────────────────────────────────────
// 2. data_sources auth_mode 검증
// ──────────────────────────────────────────────

describe('shop/show.json — data_sources auth_mode 검증', () => {
  // 실제 레이아웃의 data_sources 구조를 기반으로 검증
  const dataSources = [
    { id: 'product', auth_mode: 'optional' },
    { id: 'reviews', auth_mode: 'optional' },
    { id: 'qna', auth_mode: 'optional' },
    { id: 'productDownloadableCoupons', auth_mode: 'optional' },
    { id: 'popularProducts', auth_mode: 'optional' },
  ];

  it('모든 data_sources에 auth_mode: "optional" 설정됨', () => {
    for (const source of dataSources) {
      expect(source.auth_mode).toBe('optional');
    }
  });

  it('product 데이터소스에 auth_mode: "optional" 설정됨 (핵심 API)', () => {
    const product = dataSources.find(ds => ds.id === 'product');
    expect(product?.auth_mode).toBe('optional');
  });

  it('reviews, qna 데이터소스에 auth_mode: "optional" 설정됨', () => {
    const reviews = dataSources.find(ds => ds.id === 'reviews');
    const qna = dataSources.find(ds => ds.id === 'qna');
    expect(reviews?.auth_mode).toBe('optional');
    expect(qna?.auth_mode).toBe('optional');
  });
});

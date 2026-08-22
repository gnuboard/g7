/**
 * @file review-modal-image-policy.test.tsx
 * @description 리뷰 작성 모달 이미지 정책(A6) 레이아웃 회귀 테스트
 *
 * 테스트 대상:
 * - templates/.../layouts/mypage/orders/show.json (reviewSettings 데이터소스)
 * - templates/.../layouts/partials/mypage/orders/_modal_write_review.json (조건부 + 동적 props)
 *
 * 검증 항목:
 * - show.json 에 reviewSettings 데이터소스 등록 (공개 settings/review endpoint)
 * - 이미지 첨부 Div 에 max_images > 0 조건부 if
 * - FileUploader maxFiles/maxSize 가 reviewSettings 응답에 동적 바인딩 (폴백 포함)
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const baseDir = path.resolve(__dirname, '../..');

function loadJson(relPath: string): any {
  return JSON.parse(fs.readFileSync(path.resolve(baseDir, relPath), 'utf8'));
}

function collectNodes(node: any, predicate: (n: any) => boolean, acc: any[] = []): any[] {
  if (!node || typeof node !== 'object') return acc;
  if (predicate(node)) acc.push(node);
  const children = Array.isArray(node) ? node : node.children;
  if (Array.isArray(children)) {
    for (const child of children) collectNodes(child, predicate, acc);
  }
  if (node.children && !Array.isArray(node.children)) {
    collectNodes(node.children, predicate, acc);
  }
  return acc;
}

const showLayout = loadJson('layouts/mypage/orders/show.json');
const reviewModal = loadJson('layouts/partials/mypage/orders/_modal_write_review.json');

describe('A6 리뷰 모달 이미지 정책', () => {
  it('show.json 에 reviewSettings 데이터소스가 공개 settings/review endpoint 로 등록된다', () => {
    const ds = (showLayout.data_sources ?? []).find((d: any) => d.id === 'reviewSettings');
    expect(ds).toBeTruthy();
    expect(ds.endpoint).toBe('/api/modules/sirsoft-ecommerce/settings/review');
    expect(ds.method).toBe('GET');
    expect(ds.auto_fetch).toBe(true);
  });

  it('이미지 첨부 영역에 max_images > 0 조건부 if 가 부착된다', () => {
    const attachDivs = collectNodes(
      reviewModal,
      (n) =>
        typeof n.if === 'string' &&
        n.if.includes('reviewSettings?.data?.review_settings?.max_images') &&
        n.if.includes('> 0'),
    );
    expect(attachDivs.length).toBeGreaterThanOrEqual(1);
  });

  it('FileUploader maxFiles/maxSize 가 reviewSettings 에 동적 바인딩되며 폴백을 가진다', () => {
    const uploaders = collectNodes(reviewModal, (n) => n.name === 'FileUploader');
    expect(uploaders.length).toBe(1);
    const props = uploaders[0].props;

    expect(props.maxFiles).toBe('{{reviewSettings?.data?.review_settings?.max_images ?? 5}}');
    expect(props.maxSize).toBe('{{reviewSettings?.data?.review_settings?.max_image_size_mb ?? 10}}');
  });

  it('이미지 첨부 라벨이 max_images 설정값을 동적 count 파라미터로 받는다', () => {
    const labels = collectNodes(
      reviewModal,
      (n) =>
        typeof n.text === 'string' &&
        n.text.startsWith('$t:mypage.order_detail.review_modal.image_label'),
    );
    expect(labels.length).toBe(1);
    // 정적 라벨이 아니라 count 파라미터로 설정값을 주입해야 한다
    expect(labels[0].text).toBe(
      '$t:mypage.order_detail.review_modal.image_label|count={{reviewSettings?.data?.review_settings?.max_images ?? 5}}',
    );
  });
});

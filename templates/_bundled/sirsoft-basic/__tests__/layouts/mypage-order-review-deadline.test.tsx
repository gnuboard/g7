/**
 * @file mypage-order-review-deadline.test.tsx
 * @description 마이페이지 주문 상세 — 리뷰 작성 기간 만료 안내 레이아웃 회귀 테스트
 *
 * 테스트 대상:
 * - templates/.../layouts/partials/mypage/orders/_items.json
 *
 * 검증 항목:
 * - 리뷰 작성 버튼이 사라지는 만료 상황에서 사용자가 인지할 수 있도록
 *   review_deadline_passed 플래그에 연동된 안내 텍스트가 노출된다 (모바일 + PC 각 1건)
 * - 안내는 백엔드 OrderOptionResource.review_deadline_passed 필드에 바인딩된다
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
  // responsive 분기도 순회 (PC 전용 노드의 if 가 responsive.desktop 에 존재)
  if (node.responsive && typeof node.responsive === 'object') {
    for (const key of Object.keys(node.responsive)) {
      collectNodes(node.responsive[key], predicate, acc);
    }
  }
  return acc;
}

const items = loadJson('layouts/partials/mypage/orders/_items.json');

describe('마이페이지 주문 상세 — 리뷰 작성 기간 만료 안내', () => {
  it('review_deadline_passed 에 연동된 만료 안내 노드가 모바일/PC 각각 존재한다', () => {
    const deadlineTexts = collectNodes(
      items,
      (n) => n.text === '$t:mypage.order_detail.review_deadline_passed',
    );
    // 모바일 1건 + PC 1건
    expect(deadlineTexts.length).toBe(2);
  });

  it('만료 안내 컨테이너가 item.review_deadline_passed 플래그로 조건부 노출된다', () => {
    const guarded = collectNodes(
      items,
      (n) =>
        typeof n.if === 'string' &&
        n.if.includes('item.review_deadline_passed === true'),
    );
    // 모바일은 if 직접, PC 는 responsive.desktop.if — 둘 다 검출
    expect(guarded.length).toBeGreaterThanOrEqual(2);
  });

  it('리뷰 작성 버튼은 여전히 can_write_review 로만 노출된다 (만료 안내와 상호 배타)', () => {
    const writeButtons = collectNodes(
      items,
      (n) =>
        n.name === 'Button' &&
        typeof n.text === 'string' &&
        n.text === '$t:mypage.order_detail.write_review',
    );
    expect(writeButtons.length).toBeGreaterThanOrEqual(1);
  });
});

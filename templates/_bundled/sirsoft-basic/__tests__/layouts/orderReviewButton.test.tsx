/**
 * @file orderReviewButton.test.tsx
 * @description 주문상세 리뷰작성 버튼 구조 회귀 테스트 (U22 / D4 — 인라인 모달 단일 진입점)
 *
 * 테스트 대상:
 * - templates/.../layouts/mypage/orders/show.json (데드라우트 버튼 제거 확인)
 * - templates/.../layouts/partials/mypage/orders/_items.json (항목별 리뷰 버튼 → openModal)
 *
 * 회귀 차단:
 * - show.json 에 /mypage/reviews/write 데드라우트 navigate 가 없어야 한다
 * - 리뷰 버튼은 항목(option) 단위로 can_write_review 게이트 + openModal(modal_write_review) 사용
 *
 * @scenario payment_method=card_pg, terminal_path=admin_status_update, option_mix=all_active, actor=member
 * @effects review_button_opens_inline_modal_not_dead_route, review_button_visible_only_when_can_write_review
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const showPath = path.resolve(__dirname, '../../layouts/mypage/orders/show.json');
const itemsPath = path.resolve(__dirname, '../../layouts/partials/mypage/orders/_items.json');

interface Action {
  type?: string;
  handler: string;
  target?: string;
  params?: Record<string, any>;
  actions?: Action[];
  onSuccess?: Action[];
  onError?: Action[];
}

interface Node {
  type?: string;
  name?: string;
  if?: string;
  props?: Record<string, any>;
  children?: Node[];
  actions?: Action[];
  responsive?: Record<string, { if?: string }>;
}

function load(p: string): Node {
  return JSON.parse(fs.readFileSync(p, 'utf8'));
}

function flatten(node: Node | undefined, acc: Node[] = []): Node[] {
  if (!node) return acc;
  acc.push(node);
  if (Array.isArray(node.children)) {
    for (const c of node.children) flatten(c, acc);
  }
  return acc;
}

function collectActions(actions: Action[] | undefined, acc: Action[] = []): Action[] {
  if (!Array.isArray(actions)) return acc;
  for (const a of actions) {
    acc.push(a);
    if (a.actions) collectActions(a.actions, acc);
    // sequence/parallel 핸들러는 중첩 액션을 params.actions 에 둔다
    if (Array.isArray(a.params?.actions)) collectActions(a.params!.actions, acc);
    if (a.onSuccess) collectActions(a.onSuccess, acc);
    if (a.onError) collectActions(a.onError, acc);
  }
  return acc;
}

const WRITE_REVIEW_LABEL = '$t:mypage.order_detail.write_review';

describe('show.json — 데드라우트 리뷰 버튼 제거 (D4)', () => {
  const show = load(showPath);

  it('레이아웃 전체에 /mypage/reviews/write 데드라우트 참조가 없다', () => {
    const json = JSON.stringify(show);
    expect(json.includes('/mypage/reviews/write')).toBe(false);
    expect(json.includes('reviews/write')).toBe(false);
  });

  it('주문레벨 리뷰작성 버튼(navigate)이 제거되어, 리뷰 작성은 항목별 모달이 단일 진입점이다', () => {
    // show.json 자체에 write_review 라벨 + navigate 조합이 남아있지 않아야 한다
    const navReviewBtns = flatten(show).filter(
      (n) =>
        n.name === 'Button' &&
        Array.isArray(n.actions) &&
        collectActions(n.actions).some((a) => a.handler === 'navigate') &&
        JSON.stringify(n).includes(WRITE_REVIEW_LABEL)
    );
    expect(navReviewBtns).toEqual([]);
  });

  it('리뷰 작성 모달(_modal_write_review) 이 modals 에 등록되어 있다', () => {
    const modals = (show as any).modals ?? [];
    const hasReviewModal = modals.some(
      (m: any) => typeof m.partial === 'string' && m.partial.includes('_modal_write_review')
    );
    expect(hasReviewModal).toBe(true);
  });
});

describe('_items.json — 항목별 리뷰 버튼은 인라인 모달 진입점 (D4)', () => {
  const items = load(itemsPath);

  /** write_review 라벨을 가진 리뷰 버튼들 */
  function reviewButtons(): Node[] {
    return flatten(items).filter(
      (n) => n.name === 'Button' && JSON.stringify(n).includes(WRITE_REVIEW_LABEL)
    );
  }

  it('항목별 리뷰 버튼이 존재한다 (모바일/PC)', () => {
    const btns = reviewButtons();
    expect(btns.length).toBeGreaterThanOrEqual(1);
  });

  it('모든 리뷰 버튼은 can_write_review 게이트로만 노출된다', () => {
    for (const btn of reviewButtons()) {
      const gate = String(btn.if ?? '') + JSON.stringify(btn.responsive ?? {});
      expect(gate.includes('can_write_review')).toBe(true);
    }
  });

  it('모든 리뷰 버튼은 openModal(modal_write_review)을 호출하고 navigate(데드라우트)를 쓰지 않는다', () => {
    for (const btn of reviewButtons()) {
      const all = collectActions(btn.actions);
      const opensModal = all.some(
        (a) => a.handler === 'openModal' && a.target === 'modal_write_review'
      );
      expect(opensModal, '리뷰 버튼이 modal_write_review 를 openModal 해야 함').toBe(true);
      expect(all.some((a) => a.handler === 'navigate')).toBe(false);
    }
  });

  it('리뷰 버튼은 openModal 전에 reviewTarget(optionId/orderId/product_id)을 _local 에 설정한다', () => {
    for (const btn of reviewButtons()) {
      const all = collectActions(btn.actions);
      const setTarget = all.find(
        (a) => a.handler === 'setState' && a.params?.reviewTarget !== undefined
      );
      expect(setTarget, 'reviewTarget setState 존재').toBeDefined();
      const target = setTarget!.params!.reviewTarget;
      expect(String(target.id)).toContain('item.id');
      expect(String(target.orderId)).toContain('order.data.id');
      expect(String(target.product_id)).toContain('item.product_id');
    }
  });
});

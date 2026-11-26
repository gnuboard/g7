/**
 * @file mypage-reviews.test.tsx
 * @description 마이페이지 내 리뷰 — 목록 API, 삭제 단추는 지울 수 있는 리뷰에만, 삭제 확인 창의 DELETE 와 재조회, 빈 목록.
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import { dataBindingEngine } from '@core/template-engine/DataBindingEngine';

const layoutsDir = path.resolve(__dirname, '../../layouts');
const load = (relative: string): any => JSON.parse(fs.readFileSync(path.join(layoutsDir, relative), 'utf-8'));

function collect(node: any, match: (n: any) => boolean, out: any[] = []): any[] {
  if (!node || typeof node !== 'object') return out;
  if (Array.isArray(node)) {
    node.forEach((n) => collect(n, match, out));
    return out;
  }
  if (match(node)) out.push(node);
  Object.values(node).forEach((v) => collect(v, match, out));
  return out;
}

const evaluate = (expression: string, context: Record<string, unknown>) =>
  dataBindingEngine.evaluateExpression(String(expression).replace(/^\{\{/, '').replace(/\}\}$/, ''), context as any);
const refOf = (n: any) => String(n?.props?.['data-ref'] ?? n?.id ?? '');

const page = load('mypage/reviews.json');
const list = load('partials/mypage/reviews/_list.json');
const modal = load('partials/mypage/reviews/_modal_review_delete.json');

describe('마이페이지 내 리뷰', () => {
  // @scenario case=mypage_reviews_data_source
  // @effects user_reviews_own_only
  it('내 리뷰 API 를 쪽 번호와 함께 부른다', () => {
    const source = collect(page, (n) => n.type === 'api' && n.id === 'myReviews')[0];
    expect(source.endpoint).toBe('/api/modules/sirsoft-ecommerce/user/reviews');
    expect(JSON.stringify(source)).toContain('query.page');
  });

  // @scenario case=mypage_reviews_delete_button
  // @effects mypage_review_delete_only_deletable
  it('삭제 단추는 지울 수 있는 리뷰에만 보이고, 누르면 확인 창을 연다', () => {
    const button = collect(list, (n) => refOf(n).startsWith('review_delete_button_'))[0];
    expect(evaluate(button.if, { review: { is_deletable: true } })).toBe(true);
    expect(evaluate(button.if, { review: { is_deletable: false } })).toBe(false);
    expect(JSON.stringify(button)).toContain('reviewDeleteModal');
  });

  // @scenario case=mypage_reviews_delete_modal
  // @effects mypage_review_delete_refetches
  it('확인 창은 그 리뷰를 DELETE 로 지우고 닫은 뒤 목록을 다시 받는다', () => {
    const call = collect(modal, (n) => n.handler === 'apiCall')[0];
    expect(call.target).toBe('/api/modules/sirsoft-ecommerce/user/reviews/{{_global.reviewDeleteModal?.reviewId}}');
    expect(call.params.method).toBe('DELETE');
    const success = JSON.stringify(call.onSuccess);
    expect(success).toContain('closeModal');
    expect(success).toContain('refetchDataSource');
    expect(success).toContain('myReviews');
  });

  // @scenario case=mypage_reviews_empty_and_reply
  // @effects mypage_review_empty_and_reply
  it('빈 목록 안내와 답글 칸은 데이터에 따라 보인다 — 별점은 다섯 칸을 그린다', () => {
    const empty = collect(list, (n) => refOf(n) === 'review_empty')[0];
    expect(evaluate(empty.if, { myReviews: { data: { data: [] } } })).toBe(true);
    const reply = collect(list, (n) => refOf(n).startsWith('review_reply_'))[0];
    expect(evaluate(reply.if, { review: { has_reply: true } })).toBe(true);
    expect(evaluate(reply.if, { review: { has_reply: false } })).toBe(false);
    const stars = collect(list, (n) => n.iteration?.item_var === 'star')[0];
    expect(evaluate(stars.iteration.source, {})).toEqual([1, 2, 3, 4, 5]);
  });
});

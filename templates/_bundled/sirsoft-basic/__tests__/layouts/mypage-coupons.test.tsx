/**
 * @file mypage-coupons.test.tsx
 * @description 마이페이지 쿠폰함 (#952 C) — 상태 탭, 카드(발급 경로·남은 날), 빈 목록의 쿠폰존 링크, 쪽 나누기, 탭 숨김.
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

const page = load('mypage/coupons.json');
const list = load('partials/mypage/coupons/_list.json');
const tabs = load('partials/mypage/_tab_navigation.json');

describe('마이페이지 쿠폰함 (#952)', () => {
  // @scenario case=mypage_coupons_data_source
  // @effects coupon_box_lists_my_coupons
  it('내 쿠폰 목록 API 를 부르고 카드를 발급 건마다 그린다', () => {
    const source = collect(page, (n) => n.type === 'api' && n.id === 'user_coupons')[0];
    expect(source.endpoint).toBe('/api/modules/sirsoft-ecommerce/user/coupons');
    const card = collect(list, (n) => refOf(n).startsWith('coupon_card_') && n.iteration)[0];
    expect(card.iteration.source).toContain('user_coupons?.data?.coupons?.data');
  });

  // @scenario case=mypage_coupons_dday
  // @effects coupon_box_card_block
  it('사용 가능 쿠폰은 남은 날을 보이고, 기한 없는 쿠폰은 「기한 없음」 칸을 보인다', () => {
    const dday = collect(list, (n) => refOf(n).startsWith('coupon_dday_'))[0];
    const ctx = (issue: any, status = 'available') => ({ issue, _computed: { couponStatus: status } });
    expect(evaluate(dday.if, ctx({ expires_in_days: 3 }))).toBe(true);
    expect(evaluate(dday.if, ctx({ expires_in_days: 0 }))).toBe(true);
    expect(evaluate(dday.if, ctx({ expires_in_days: null }))).toBe(false);
    expect(evaluate(dday.if, ctx({ expires_in_days: 3 }, 'used'))).toBe(false);
  });

  // @scenario case=mypage_coupons_empty
  // @effects coupon_box_empty_links_zone
  it('빈 목록은 안내와 함께 사용 가능 탭에서 쿠폰존 링크를 준다', () => {
    const empty = collect(list, (n) => refOf(n) === 'coupon_empty')[0];
    expect(evaluate(empty.if, { user_coupons: { data: { coupons: { data: [] } } } })).toBe(true);
    const link = collect(list, (n) => refOf(n) === 'coupon_zone_link')[0];
    const nav = collect(link, (n) => n.handler === 'navigate')[0];
    expect(evaluate(nav.params.path, { _global: { modules: { 'sirsoft-ecommerce': { basic_info: { route_path: 'shop' } } } } })).toBe('/shop/coupons');
    expect(evaluate(nav.params.path, { _global: { modules: { 'sirsoft-ecommerce': { basic_info: { no_route: true } } } } })).toBe('/coupons');
  });

  // @scenario case=mypage_coupons_tab_hidden
  // @effects mypage_tabs_hidden_without_shop
  it('쇼핑몰 모듈이 꺼지면 쿠폰함·내 리뷰 탭을 숨기고, 활동 기록 탭은 늘 둔다', () => {
    const nav = collect(tabs, (n) => n?.props?.hiddenTabIds)[0];
    const hidden = (activeModules: string[]) => evaluate(nav.props.hiddenTabIds, { _global: { activeModules, modules: {} } }) as string[];
    expect(hidden(['sirsoft-board'])).toEqual(expect.arrayContaining(['coupons', 'reviews']));
    expect(hidden(['sirsoft-board'])).not.toContain('activity');
    expect(hidden(['sirsoft-board', 'sirsoft-ecommerce'])).not.toContain('coupons');
    const ids = collect(nav, (n) => typeof n.id === 'string' && ['coupons', 'reviews', 'activity'].includes(n.id)).map((n) => n.id);
    expect(ids).toEqual(expect.arrayContaining(['coupons', 'reviews', 'activity']));
  });
});

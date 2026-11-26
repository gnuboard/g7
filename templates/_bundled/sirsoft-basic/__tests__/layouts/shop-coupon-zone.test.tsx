/**
 * @file shop-coupon-zone.test.tsx
 * @description 쿠폰존 화면 — 비로그인 공개 목록, 로그인 회원 목록, 받기 단추·모두 받기, 빈 목록, 쪽 나누기.
 *
 * 레이아웃 JSON 의 식을 실제 바인딩 엔진으로 평가한다.
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

const page = load('shop/coupons.json');
const grid = load('partials/shop/coupons/_grid.json');
const toolbar = load('partials/shop/coupons/_toolbar.json');
const byRef = (root: any, ref: string) => collect(root, (n) => n?.props?.['data-ref'] === ref || n?.id === ref)[0];

describe('쿠폰존 화면', () => {
  // @scenario case=coupon_zone_endpoint
  // @effects coupon_zone_endpoint_by_login
  it('공개 주소 하나를 토큰이 있으면 실어 부른다 — 로그인 확인 전후로 주소가 갈리지 않는다', () => {
    const source = collect(page, (n) => n.id === 'couponZone' && n.type === 'api')[0];
    expect(source).toBeTruthy();
    expect(source.method).toBe('GET');
    expect(source.endpoint).toBe('/api/modules/sirsoft-ecommerce/coupons/downloadable');
    expect(source.auth_mode).toBe('optional');
    expect(String(source.endpoint)).not.toContain('currentUser');
  });

  // @scenario case=coupon_zone_card_buttons
  // @effects coupon_zone_card_download_state
  it('카드 단추 — 받은 쿠폰은 「받음」, 받는 중이면 진행 표시, 그 밖에는 받기 단추가 보인다', () => {
    const downloaded = collect(grid, (n) => String(n?.props?.['data-ref'] ?? n?.id ?? '').startsWith('coupon_zone_downloaded_'))[0];
    const download = collect(grid, (n) => String(n?.props?.['data-ref'] ?? n?.id ?? '').startsWith('coupon_zone_download_'))[0];
    expect(downloaded && download).toBeTruthy();

    const scope = (coupon: any, local: any = {}) => ({ coupon, _local: local });
    expect(evaluate(downloaded.if, scope({ coupon_id: 1, is_downloaded: true }))).toBe(true);
    expect(evaluate(download.if, scope({ coupon_id: 1, is_downloaded: false }))).toBe(true);
    // 이번 화면에서 받은 쿠폰도 받음으로 바뀐다
    expect(evaluate(downloaded.if, scope({ coupon_id: 2, is_downloaded: false }, { downloadedIds: [2] }))).toBe(true);
    expect(evaluate(download.if, scope({ coupon_id: 3, is_downloaded: false }, { downloadingId: 3 }))).toBe(false);
  });

  // @scenario case=coupon_zone_guest_download
  // @effects coupon_zone_guest_login_prompt
  it('비로그인이 받기를 누르면 로그인 안내 창을 열고, 회원은 그 쿠폰 받기 API 를 부른다', () => {
    const download = collect(grid, (n) => String(n?.props?.['data-ref'] ?? n?.id ?? '').startsWith('coupon_zone_download_'))[0];
    const text = JSON.stringify(download);
    expect(text).toContain('login_required_modal');
    expect(text).toContain('/api/modules/sirsoft-ecommerce/user/coupons/{{coupon.coupon_id}}/download');
    expect(text).toContain('refetchDataSource');

    // 비로그인의 currentUser 는 null 이 아니라 데이터소스 fallback 인 빈 배열([])이다 — 참인 값이라 uuid 로 가른다
    expect(text).toContain('!_global.currentUser?.uuid');
    expect(text).not.toMatch(/"if":"\{\{!_global\.currentUser\}\}"/);
    const hint = byRef(toolbar, 'coupon_zone_login_hint');
    expect(evaluate(hint.if, { _global: { currentUser: [] } })).toBe(true);
    expect(evaluate(hint.if, { _global: { currentUser: undefined } })).toBe(false);
    expect(evaluate(hint.if, { _global: { currentUser: { uuid: 'u' } } })).toBe(false);

    const bulk = collect(toolbar, (n) => n.handler === 'apiCall' && String(n.target).endsWith('/user/coupons/bulk-download'))[0];
    expect(bulk).toBeTruthy();
    expect(JSON.stringify(toolbar)).toContain('login_required_modal');
  });

  // @scenario case=coupon_zone_empty_and_paging
  // @effects coupon_zone_empty_and_pagination
  it('빈 목록은 안내를 보이고, 쪽이 하나뿐이면 쪽 나누기를 숨긴다', () => {
    const empty = byRef(grid, 'coupon_zone_empty');
    expect(evaluate(empty.if, { couponZone: { data: { data: [] } } })).toBe(true);
    expect(evaluate(empty.if, { couponZone: undefined })).toBe(false);

    const pager = collect(grid, (n) => n.name === 'Pagination')[0];
    expect(evaluate(pager.if, { couponZone: { data: { last_page: 1, current_page: 1 } } })).toBe(false);
    expect(evaluate(pager.if, { couponZone: { data: { last_page: 3, current_page: 1 } } })).toBe(true);
  });
});

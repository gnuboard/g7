/**
 * @file popupVisibility.test.ts
 * @description 노출 페이지 경로 판정 — 브라우저 층(매니페스트 route-matching.yaml, layer=ts).
 * 서버 `RoutePathTest`(layer=php)와 같은 표본에서 같은 결과를 내야 한다(PO 결정 #812 D2).
 */
import { describe, it, expect } from 'vitest';
import { matchesPage, matchesRoute, normalizeRoute } from '../../lib/popupVisibility';
import type { PublicPopup } from '../../lib/types';

describe('matchesRoute — 경로 경계 하위 포함, / 는 홈만', () => {
  // @scenario case=exact_path, layer=ts
  // @effects php_and_ts_agree
  it('지정 경로와 같은 경로에 맞는다', () => {
    expect(matchesRoute('/shop', '/shop')).toBe(true);
  });

  // @scenario case=child_path, layer=ts
  // @effects php_and_ts_agree
  it('경로 경계 아래 하위 경로에 맞는다', () => {
    expect(matchesRoute('/shop/products', '/shop')).toBe(true);
  });

  // @scenario case=deep_child_path, layer=ts
  // @effects php_and_ts_agree
  it('여러 단계 아래 하위 경로에도 맞는다', () => {
    expect(matchesRoute('/shop/products/1/reviews', '/shop')).toBe(true);
  });

  // @scenario case=prefix_without_boundary_excluded, layer=ts
  // @effects php_and_ts_agree
  it('경로 경계가 아니면 맞지 않는다 — /shopping·/shop-event', () => {
    expect(matchesRoute('/shopping', '/shop')).toBe(false);
    expect(matchesRoute('/shop-event', '/shop')).toBe(false);
  });

  // @scenario case=root_matches_home_only, layer=ts
  // @effects php_and_ts_agree
  it('/ 는 홈에 맞는다(빈 경로도 홈)', () => {
    expect(matchesRoute('/', '/')).toBe(true);
    expect(matchesRoute('', '/')).toBe(true);
  });

  // @scenario case=root_excludes_child, layer=ts
  // @effects php_and_ts_agree
  it('/ 는 하위 경로에 맞지 않는다', () => {
    expect(matchesRoute('/shop', '/')).toBe(false);
    expect(matchesRoute('/board/notice', '/')).toBe(false);
  });

  // @scenario case=route_trailing_slash_normalized, layer=ts
  // @effects php_and_ts_agree
  it('지정 경로 끝 / 는 뗀다', () => {
    expect(normalizeRoute(' /shop/ ')).toBe('/shop');
    expect(normalizeRoute('/')).toBe('/');
    expect(normalizeRoute('///')).toBe('/');
    expect(matchesRoute('/shop/1', '/shop/')).toBe(true);
  });

  // @scenario case=path_trailing_slash_normalized, layer=ts
  // @effects php_and_ts_agree
  it('현재 경로 끝 / 도 뗀다', () => {
    expect(matchesRoute('/shop/', '/shop')).toBe(true);
    expect(matchesRoute('/shopping/', '/shop')).toBe(false);
  });

  // @scenario case=case_sensitive, layer=ts
  // @effects php_and_ts_agree
  it('대소문자를 구분한다', () => {
    expect(matchesRoute('/Shop', '/shop')).toBe(false);
  });

  // @scenario case=query_ignored, layer=ts
  // @effects php_and_ts_agree
  it('쿼리는 떼고 판정한다', () => {
    expect(matchesRoute('/shop?page=2', '/shop')).toBe(true);
    expect(matchesRoute('/?ref=mail', '/')).toBe(true);
    expect(matchesRoute('/shopping?x=/shop', '/shop')).toBe(false);
  });

  // @scenario case=hash_ignored, layer=ts
  // @effects php_and_ts_agree
  it('해시는 떼고 판정한다', () => {
    expect(matchesRoute('/shop#top', '/shop')).toBe(true);
    expect(matchesRoute('/#intro', '/')).toBe(true);
  });

  // @scenario case=scope_all_matches_everything, layer=ts
  // @effects php_and_ts_agree
  it('전체 페이지는 어디서나 맞고, 라우트 지정은 목록 중 하나라도 맞으면', () => {
    const all = { id: 1, content_type: 'html', page_scope: 'all', device: 'all' } as PublicPopup;
    const routed = { id: 2, content_type: 'html', page_scope: 'routes', routes: ['/shop', '/'], device: 'all' } as PublicPopup;

    expect(['/', '/shop/1', '/board', '/anything/deep'].every((path) => matchesPage(all, path))).toBe(true);
    expect(matchesPage(routed, '/')).toBe(true);
    expect(matchesPage(routed, '/shop/1')).toBe(true);
    expect(matchesPage(routed, '/board')).toBe(false);
  });
});

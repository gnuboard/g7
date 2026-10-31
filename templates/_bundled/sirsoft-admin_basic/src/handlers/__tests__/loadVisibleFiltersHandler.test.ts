/**
 * loadVisibleFiltersHandler 테스트 (#705)
 *
 * FilterVisibilitySelector 가 저장한 키(`g7_filters_{id}_{uuid}`)를 같은 규칙으로 읽는지,
 * 「배열이면서 비어 있지 않을 때만 채택」 판정과 기본값 폴백을 확인한다.
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { loadVisibleFiltersHandler, buildVisibleFiltersStorageKey } from '../loadVisibleFiltersHandler';

const DEFAULTS = ['searchField', 'searchKeyword', 'status', 'role', 'createdAt'];

describe('loadVisibleFiltersHandler', () => {
  let originalG7Core: any;

  beforeEach(() => {
    localStorage.clear();
    originalG7Core = (window as any).G7Core;
    (window as any).G7Core = {
      AuthManager: { getInstance: () => ({ getUser: () => ({ uuid: 'user-uuid-1' }) }) },
    };
  });

  afterEach(() => {
    (window as any).G7Core = originalG7Core;
    localStorage.clear();
  });

  // @scenario filter_axis=status, outcome=narrows
  // @effects visible_filters_restored_from_storage
  it('로그인 사용자 uuid 를 붙인 키를 만든다', () => {
    expect(buildVisibleFiltersStorageKey('admin_user_filters')).toBe('g7_filters_admin_user_filters_user-uuid-1');
  });

  // @scenario filter_axis=status, outcome=narrows
  // @effects visible_filters_restored_from_storage
  it('로그인 사용자가 없으면 공유 키를 쓴다', () => {
    (window as any).G7Core = { AuthManager: { getInstance: () => ({ getUser: () => null }) } };

    expect(buildVisibleFiltersStorageKey('admin_user_filters')).toBe('g7_filters_admin_user_filters');
  });

  // @scenario filter_axis=status, outcome=narrows
  // @effects visible_filters_restored_from_storage
  it('저장된 배열이 있으면 그것을 돌려준다', () => {
    localStorage.setItem('g7_filters_admin_user_filters_user-uuid-1', JSON.stringify(['status', 'language']));

    const result = loadVisibleFiltersHandler({ params: { id: 'admin_user_filters', defaultFilters: DEFAULTS } });

    expect(result).toEqual(['status', 'language']);
  });

  // @scenario filter_axis=status, outcome=narrows
  // @effects visible_filters_restored_from_storage
  it('다른 사용자의 저장값은 읽지 않는다', () => {
    localStorage.setItem('g7_filters_admin_user_filters_other-uuid', JSON.stringify(['language']));

    expect(loadVisibleFiltersHandler({ params: { id: 'admin_user_filters', defaultFilters: DEFAULTS } })).toEqual(DEFAULTS);
  });

  // @scenario filter_axis=status, outcome=narrows
  // @effects visible_filters_restored_from_storage
  it('빈 배열이 저장돼 있으면 기본값으로 폴백한다', () => {
    localStorage.setItem('g7_filters_admin_user_filters_user-uuid-1', '[]');

    expect(loadVisibleFiltersHandler({ params: { id: 'admin_user_filters', defaultFilters: DEFAULTS } })).toEqual(DEFAULTS);
  });

  // @scenario filter_axis=status, outcome=narrows
  // @effects visible_filters_restored_from_storage
  it('배열이 아니거나 깨진 JSON 이면 기본값으로 폴백한다', () => {
    localStorage.setItem('g7_filters_admin_user_filters_user-uuid-1', '{"a":1}');
    expect(loadVisibleFiltersHandler({ params: { id: 'admin_user_filters', defaultFilters: DEFAULTS } })).toEqual(DEFAULTS);

    localStorage.setItem('g7_filters_admin_user_filters_user-uuid-1', '{not json');
    expect(loadVisibleFiltersHandler({ params: { id: 'admin_user_filters', defaultFilters: DEFAULTS } })).toEqual(DEFAULTS);
  });

  // @scenario filter_axis=status, outcome=narrows
  // @effects visible_filters_restored_from_storage
  it('id 가 없으면 기본값을 돌려준다', () => {
    expect(loadVisibleFiltersHandler({ params: { defaultFilters: DEFAULTS } })).toEqual(DEFAULTS);
    expect(loadVisibleFiltersHandler({})).toEqual([]);
  });

  // @scenario filter_axis=status, outcome=narrows
  // @effects visible_filters_restored_from_storage
  it('이커머스 판과 같은 키 규칙이다 — 이커머스 화면의 저장값과 섞이지 않는다', () => {
    localStorage.setItem('g7_filters_admin_ecommerce_order_filters_user-uuid-1', JSON.stringify(['date']));

    expect(loadVisibleFiltersHandler({ params: { id: 'admin_user_filters', defaultFilters: DEFAULTS } })).toEqual(DEFAULTS);
    expect(loadVisibleFiltersHandler({ params: { id: 'admin_ecommerce_order_filters', defaultFilters: [] } })).toEqual(['date']);
  });
});

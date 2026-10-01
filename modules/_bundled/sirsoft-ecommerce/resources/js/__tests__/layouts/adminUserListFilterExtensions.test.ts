/**
 * @file adminUserListFilterExtensions.test.ts
 * @description 회원관리 목록 확장 필터 조각 2종 구조 테스트 (#705 예제 E1·E2)
 *
 * 관리자 템플릿의 회원 목록이 연 확장점(`admin_user_list_filters`)에 붙는 이커머스 필터 행이 규약을
 * 지키는지 잠근다 — 확장점·모드, 행 권한(서버 리스너와 같은 권한), 값을 직접 URL 에 싣지 않고 이 모듈의
 * 핸들러 `sirsoft-ecommerce.setUserFilterExt` 로 `_local.userFilterExtraQuery` 배열을 갱신하는지(관리자
 * 템플릿 핸들러에 기대지 않는지), 키 형식이 서버 정규식과 맞는지(`{확장id}.{키}`).
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';

import mileage from '../../../extensions/admin-user-list-filter-mileage.json';
import address from '../../../extensions/admin-user-list-filter-address.json';
import { handlerMap } from '../../handlers';

/** 트리에서 조건을 만족하는 노드를 모두 모은다. */
function collect(obj: any, predicate: (n: any) => boolean, out: any[] = []): any[] {
  if (!obj || typeof obj !== 'object') return out;
  if (predicate(obj)) out.push(obj);
  for (const value of Object.values(obj)) {
    if (Array.isArray(value)) value.forEach((v) => collect(v, predicate, out));
    else if (value && typeof value === 'object') collect(value, predicate, out);
  }
  return out;
}

const FRAGMENTS: Array<[string, any, string, string[]]> = [
  ['마일리지 잔액', mileage, 'sirsoft-ecommerce.mileage.read', ['sirsoft-ecommerce.mileage_min', 'sirsoft-ecommerce.mileage_max']],
  ['배송지', address, 'sirsoft-ecommerce.user-addresses.read', ['sirsoft-ecommerce.shipping_country', 'sirsoft-ecommerce.has_address']],
];

describe('회원관리 목록 확장 필터 조각', () => {
  // @scenario example=mileage, outcome=narrows
  // @effects ecommerce_mileage_filter_narrows_user_list
  it.each(FRAGMENTS)('%s — 코어 확장점에 append 로 붙는다', (_label, fragment) => {
    expect(fragment.extension_point).toBe('admin_user_list_filters');
    expect(fragment.mode).toBe('append');
  });

  // @scenario example=address, outcome=ignored_without_permission
  // @effects ecommerce_user_filters_require_permission
  // 슬롯 확장은 어느 템플릿에 주입될지 조각이 모르므로 한 템플릿의 컴포넌트 클래스(filter-row·filter-label·
  // flex-center·text-body 등)를 쓰지 않고 평범한 유틸로 편다 — 다른 템플릿에는 그 규칙이 없어 배치가 무너진다
  // (check-extension-css-coverage 「어휘 오용」).
  it.each(FRAGMENTS)('%s — 행은 유틸로 편 필터 행이고 서버 리스너와 같은 권한으로만 보인다', (_label, fragment, permission) => {
    const classes = (n: any) => String(n?.props?.className ?? '').split(' ');
    const rows = collect(fragment, (n) => !!n?.id && classes(n).includes('md:flex-row'));

    expect(rows).toHaveLength(1);
    expect(JSON.stringify(rows[0].permissions)).toContain(permission);
    expect(collect(rows[0], (n) => classes(n).includes('w-32') && classes(n).includes('shrink-0'))).toHaveLength(1);

    const templateOnly = ['filter-row', 'filter-label', 'flex-center', 'text-body', 'text-label-subtle'];
    expect(collect(fragment, (n) => classes(n).some((c) => templateOnly.includes(c)))).toHaveLength(0);
  });

  // @scenario example=address, outcome=narrows
  // @effects ecommerce_address_filter_narrows_user_list
  it.each(FRAGMENTS)('%s — 값은 이 모듈의 setUserFilterExt 로 조립해 _local.userFilterExtraQuery 에 담는다', (_label, fragment, _perm, keys) => {
    const calls = collect(fragment, (n) => n?.handler === 'sirsoft-ecommerce.setUserFilterExt');
    const usedKeys = new Set(calls.map((c) => c.params.key));

    expect(calls.length).toBeGreaterThan(0);
    expect([...usedKeys].sort()).toEqual([...keys].sort());
    for (const call of calls) {
      expect(call.params.key).toMatch(/^[a-z0-9_-]+\.[a-z0-9_.-]+$/);
      expect(call.params.current).toContain('_local.userFilterExtraQuery');
    }

    const stores = collect(fragment, (n) => n?.handler === 'setState' && n?.params?.userFilterExtraQuery !== undefined);
    expect(stores).toHaveLength(calls.length);
    for (const store of stores) {
      expect(store.params.target).toBe('_local');
      expect(store.params.userFilterExtraQuery).toBe('{{$prev}}');
    }
  });

  // @scenario example=address, outcome=narrows
  // @effects ecommerce_address_filter_narrows_user_list
  // 조각은 어느 관리자 템플릿으로 그려지든 같은 동작을 해야 하므로 템플릿이 등록한 네임스페이스 없는
  // 핸들러를 부르지 않는다 — 부르는 핸들러는 이 모듈이 등록한다.
  it.each(FRAGMENTS)('%s — 관리자 템플릿 핸들러에 기대지 않고 모듈이 등록한 핸들러를 부른다', (_label, fragment) => {
    expect(collect(fragment, (n) => n?.handler === 'setUserFilterExt')).toHaveLength(0);
    expect(typeof handlerMap.setUserFilterExt).toBe('function');
  });

  // @scenario example=mileage, outcome=narrows
  // @effects ecommerce_mileage_filter_narrows_user_list
  it('두 조각은 서로 다른 우선순위로 마일리지 → 배송지 순서에 선다', () => {
    expect(mileage.priority).toBeLessThan(address.priority);
  });
});

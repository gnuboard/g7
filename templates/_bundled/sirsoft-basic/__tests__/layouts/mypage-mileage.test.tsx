/**
 * @file mypage-mileage.test.tsx
 * @description 마이페이지 마일리지 내역 화면 레이아웃 회귀 테스트 (§15)
 *
 * 테스트 대상:
 * - templates/.../layouts/mypage/mileage.json
 * - templates/.../layouts/partials/mypage/mileage/_list.json
 * - templates/.../layouts/partials/mypage/_tab_navigation.json
 * - templates/.../routes.json
 *
 * 검증 항목:
 * - 레이아웃 구조 (extends, computed.currentTab, 데이터소스 2종)
 * - balance / history 데이터소스 endpoint·params (page/category/per_page)
 * - 탭 네비게이션에 mileage 탭 추가
 * - /mypage/mileage 라우트 등록
 * - 4분류 필터(전체/적립/사용/소멸/조정) 5버튼 + navigate query.category
 * - 4분류 배지 매핑 (earn/use/expire/adjust)
 * - 요약 카드 4종 (available/expiring_soon/total_earned/total_used)
 * - 페이지네이션 컴포넌트 + onPageChange
 * - 다크모드 클래스 쌍
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const baseDir = path.resolve(__dirname, '../..');

function loadJson(relPath: string): any {
  const raw = fs.readFileSync(path.resolve(baseDir, relPath), 'utf8');
  return JSON.parse(raw);
}

/** 노드 트리에서 조건을 만족하는 모든 노드를 수집한다. */
function collectNodes(node: any, predicate: (n: any) => boolean, acc: any[] = []): any[] {
  if (!node || typeof node !== 'object') return acc;
  if (predicate(node)) acc.push(node);
  const children = Array.isArray(node) ? node : node.children;
  if (Array.isArray(children)) {
    for (const child of children) collectNodes(child, predicate, acc);
  }
  // iteration / responsive 내부도 순회
  if (node.children && !Array.isArray(node.children)) {
    collectNodes(node.children, predicate, acc);
  }
  return acc;
}

/** 객체 전체를 직렬화해 특정 문자열 포함 여부를 검사한다. */
function serialize(node: any): string {
  return JSON.stringify(node);
}

const mileageLayout = loadJson('layouts/mypage/mileage.json');
const listPartial = loadJson('layouts/partials/mypage/mileage/_list.json');
const tabNav = loadJson('layouts/partials/mypage/_tab_navigation.json');
const routes = loadJson('routes.json');

describe('mypage/mileage 레이아웃 (§15)', () => {
  // ── 레이아웃 구조 ──

  it('_user_base 를 extends 한다', () => {
    expect(mileageLayout.extends).toBe('_user_base');
  });

  it('computed.currentTab 이 mileage 다', () => {
    expect(mileageLayout.computed?.currentTab).toBe('mileage');
  });

  it('balance / history 데이터소스 2종을 갖는다', () => {
    const ids = mileageLayout.data_sources.map((d: any) => d.id);
    expect(ids).toContain('mileage_balance');
    expect(ids).toContain('mileage_history');
  });

  it('balance 데이터소스 endpoint 가 잔액 API 를 가리킨다', () => {
    const ds = mileageLayout.data_sources.find((d: any) => d.id === 'mileage_balance');
    expect(ds.endpoint).toBe('/api/modules/sirsoft-ecommerce/user/mileage');
    expect(ds.auth_required).toBe(true);
  });

  it('history 데이터소스 endpoint 와 page/category/per_page 파라미터를 갖는다', () => {
    const ds = mileageLayout.data_sources.find((d: any) => d.id === 'mileage_history');
    expect(ds.endpoint).toBe('/api/modules/sirsoft-ecommerce/user/mileage/history');
    expect(ds.params.page).toContain('query.page');
    expect(ds.params.category).toContain('query.category');
    expect(ds.params.per_page).toContain('query.per_page');
    expect(ds.auth_required).toBe(true);
  });

  it('init_actions 에 mileageCategory/mileagePage 초기화가 있다', () => {
    const init = mileageLayout.init_actions[0];
    expect(init.handler).toBe('setState');
    expect(init.params.target).toBe('local');
    expect(init.params).toHaveProperty('mileageCategory');
    expect(init.params).toHaveProperty('mileagePage');
  });

  it('탭 컨텐츠 슬롯에 마일리지 _list partial 을 포함한다', () => {
    const s = serialize(mileageLayout);
    expect(s).toContain('partials/mypage/mileage/_list.json');
    expect(s).toContain('mypage_tab_content');
  });
});

describe('mypage/_tab_navigation 마일리지 탭', () => {
  it('tabs 배열에 mileage 탭이 추가되어 있다', () => {
    const tabs = tabNav.props.tabs;
    const mileageTab = tabs.find((t: any) => t.id === 'mileage');
    expect(mileageTab).toBeDefined();
    expect(mileageTab.label).toBe('$t:mypage.tabs.mileage');
    expect(mileageTab.iconName).toBe('coins');
  });

  it('mileage 탭이 orders 와 wishlist 사이에 위치한다', () => {
    const ids = tabNav.props.tabs.map((t: any) => t.id);
    expect(ids.indexOf('mileage')).toBeGreaterThan(ids.indexOf('orders'));
    expect(ids.indexOf('mileage')).toBeLessThan(ids.indexOf('wishlist'));
  });
});

describe('routes.json /mypage/mileage', () => {
  it('마일리지 라우트가 등록되어 있다', () => {
    const route = routes.routes.find((r: any) => r.path === '/mypage/mileage');
    expect(route).toBeDefined();
    expect(route.layout).toBe('mypage/mileage');
    expect(route.auth_required).toBe(true);
  });
});

describe('mypage/mileage _list partial', () => {
  // ── 요약 카드 4종 ──

  it('요약 카드 4종 (available/expiring_soon/total_earned/total_used) 을 바인딩한다', () => {
    const s = serialize(listPartial);
    expect(s).toContain('mileage_balance?.data?.mileage?.available');
    expect(s).toContain('mileage_balance?.data?.mileage?.expiring_soon');
    expect(s).toContain('mileage_balance?.data?.mileage?.total_earned');
    expect(s).toContain('mileage_balance?.data?.mileage?.total_used');
  });

  // ── 4분류 필터 ──

  it('4분류 필터 5버튼(전체/적립/사용/소멸/조정) 이 존재한다', () => {
    const labels = ['filter.all', 'filter.earn', 'filter.use', 'filter.expire', 'filter.adjust'];
    const s = serialize(listPartial);
    for (const label of labels) {
      expect(s).toContain(`$t:mypage.mileage.${label}`);
    }
  });

  it('"전체" 필터 버튼이 선택 시 active 되도록 non-empty key(all) variant 를 사용한다 (회귀 차단)', () => {
    // mileageCategory 가 빈 문자열('')일 때 classMap key 가 falsy 가 되어 variant 매칭이 안 되던 버그.
    // 다른 필터 버튼(earn/use/expire/adjust)처럼 non-empty key 로 맞춰야 active 스타일이 적용된다.
    const allButton = collectNodes(
      listPartial,
      (n) => n.name === 'Button' && n.text === '$t:mypage.mileage.filter.all'
    )[0];
    expect(allButton, '"전체" 버튼이 존재해야 함').toBeDefined();
    // key 는 카테고리 미선택 시 'all' 로 평가되어야 한다 (빈 문자열 falsy 회피).
    expect(allButton.classMap.key).toContain("'all'");
    expect(allButton.classMap.key).not.toBe("{{_local.mileageCategory ?? ''}}");
    // variants 에 'all' active 스타일이 정의되어야 한다 (빈 문자열 키 아님).
    expect(allButton.classMap.variants).toHaveProperty('all');
    // 빈 문자열 키는 classMap key 가 falsy 일 때 매칭되지 않으므로 사용 금지.
    expect(Object.keys(allButton.classMap.variants)).not.toContain('');
  });

  it('각 필터 버튼이 navigate 로 query.category 를 전달한다', () => {
    const buttons = collectNodes(
      listPartial,
      (n) => n.name === 'Button' && Array.isArray(n.actions)
    );
    const navTargets = buttons
      .flatMap((b: any) => b.actions)
      .filter((a: any) => a.handler === 'sequence')
      .flatMap((a: any) => a.actions)
      .filter((a: any) => a.handler === 'navigate');
    // 5개 필터 버튼 → 5개 navigate
    expect(navTargets.length).toBe(5);
    for (const nav of navTargets) {
      expect(nav.params.path).toBe('/mypage/mileage');
      expect(nav.params.query).toHaveProperty('category');
    }
  });

  // ── 4분류 배지 매핑 ──

  it('user_display_category 4분류 배지 variants(earn/use/expire/adjust) 를 매핑한다', () => {
    const badges = collectNodes(
      listPartial,
      (n) => n.classMap && n.classMap.key === '{{tx.user_display_category}}'
    );
    expect(badges.length).toBeGreaterThanOrEqual(2); // PC 테이블 + 모바일 카드
    for (const badge of badges) {
      const variants = badge.classMap.variants;
      expect(variants).toHaveProperty('earn');
      expect(variants).toHaveProperty('use');
      expect(variants).toHaveProperty('expire');
      expect(variants).toHaveProperty('adjust');
      // §15.2 배지 색상: 적립=green, 사용=blue, 소멸=gray, 조정=amber
      expect(variants.earn).toContain('green');
      expect(variants.use).toContain('blue');
      expect(variants.expire).toContain('gray');
      expect(variants.adjust).toContain('amber');
    }
  });

  it('금액에 부호(+) 및 amount_formatted 를 표시한다', () => {
    const s = serialize(listPartial);
    expect(s).toContain('tx.amount_formatted');
    expect(s).toContain("tx.amount >= 0 ? '+' : ''");
  });

  // ── 페이지네이션 ──

  it('Pagination 컴포넌트가 history pagination 경로를 바인딩한다', () => {
    const pagination = collectNodes(listPartial, (n) => n.name === 'Pagination')[0];
    expect(pagination).toBeDefined();
    expect(pagination.props.currentPage).toContain('mileage_history.data.transactions.pagination.current_page');
    expect(pagination.props.totalPages).toContain('mileage_history.data.transactions.pagination.last_page');
    const pageChange = pagination.actions.find((a: any) => a.event === 'onPageChange');
    expect(pageChange).toBeDefined();
  });

  // ── 빈 상태 ──

  it('내역 없음 빈 상태를 렌더한다', () => {
    const s = serialize(listPartial);
    expect(s).toContain('$t:mypage.mileage.empty');
  });

  // ── 다크모드 쌍 ──

  it('카드/배경 클래스에 다크모드 쌍이 포함된다', () => {
    const s = serialize(listPartial);
    expect(s).toContain('dark:bg-gray-800');
    expect(s).toContain('dark:text-gray-400');
  });

  // ── 마일리지 비활성화 안내 (mileage.enabled=false) ──

  it('비활성화 안내 블록이 enabled=false 조건으로 노출된다', () => {
    const notice = collectNodes(
      listPartial,
      (n) => n.if === '{{!mileage_balance?.data?.mileage?.enabled}}'
    );
    expect(notice.length).toBeGreaterThanOrEqual(1);
    expect(serialize(notice)).toContain('$t:mypage.mileage.disabled_notice');
  });

  it('요약 카드·필터는 enabled=true 일 때만 노출된다', () => {
    const enabledGated = collectNodes(
      listPartial,
      (n) => n.if === '{{mileage_balance?.data?.mileage?.enabled}}'
    );
    // 요약 카드 그리드 + 4분류 필터 (최소 2블록)
    expect(enabledGated.length).toBeGreaterThanOrEqual(2);
  });

  it('내역 없음 빈 상태가 enabled 조건과 결합되어 비활성 시 중복 노출되지 않는다', () => {
    const empty = collectNodes(
      listPartial,
      (n) => typeof n.if === 'string'
        && n.if.includes('mileage_balance?.data?.mileage?.enabled')
        && n.if.includes('length === 0')
    );
    expect(empty.length).toBe(1);
  });
});

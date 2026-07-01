/**
 * 회원 화면 마일리지 주입 layout_extension 구조 검증 테스트
 *
 * @description
 * - admin-user-mileage-tab.json: 관리자 회원 상세 마일리지 탭 주입
 * - mypage-profile-mileage-card.json: 마이페이지 프로필 "내 마일리지" 카드 주입
 * - ecommerce_mileage 부재(enabled=false) 시 if 로 미표시
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import adminTab from '../../../extensions/admin-user-mileage-tab.json';
import mypageCard from '../../../extensions/mypage-profile-mileage-card.json';

function findFirst(node: any, predicate: (n: any) => boolean): any | null {
  if (!node) return null;
  if (predicate(node)) return node;
  for (const child of node.children ?? []) {
    const found = findFirst(child, predicate);
    if (found) return found;
  }
  return null;
}

function jsonOf(o: any): string {
  return JSON.stringify(o);
}

describe('관리자 회원 상세 마일리지 탭 주입 (admin-user-mileage-tab.json)', () => {
  it('admin_user_detail 레이아웃을 타깃한다', () => {
    expect(adminTab.target_layout).toBe('admin_user_detail');
  });

  it('user_detail_tabs 에 마일리지 탭을 inject_props 로 추가하고 데이터 부재 시 숨긴다', () => {
    const tabInjection = adminTab.injections.find((i: any) => i.target_id === 'user_detail_tabs');
    expect(tabInjection.position).toBe('inject_props');
    const appended = tabInjection.props.tabs._append[0];
    expect(appended.id).toBe('ext_mileage');
    expect(appended.if).toContain('ecommerce_mileage');
  });

  it('extension_tab_content 에 잔액/적립예정/누적적립/소멸예정 요약을 append 한다 (pending 포함 §18.3(3))', () => {
    const content = adminTab.injections.find((i: any) => i.target_id === 'extension_tab_content');
    const json = jsonOf(content);
    expect(json).toContain('ecommerce_mileage?.available');
    expect(json).toContain('ecommerce_mileage?.pending');
    expect(json).toContain('ecommerce_mileage?.total_earned');
    expect(json).toContain('ecommerce_mileage?.expiring_soon');
  });

  it('주입 컨텐츠가 ecommerce_mileage 부재 시 if 로 숨겨진다', () => {
    const content = adminTab.injections.find((i: any) => i.target_id === 'extension_tab_content');
    const root = content.components[0];
    expect(root.if).toContain('ecommerce_mileage');
  });

  it('ExtensionBadge 가 표시된다', () => {
    const content = adminTab.injections.find((i: any) => i.target_id === 'extension_tab_content');
    const badge = findFirst(content.components[0], (n) => n.name === 'ExtensionBadge');
    expect(badge.props.identifier).toBe('sirsoft-ecommerce');
  });

  it('"이 회원 내역 보기" 가 마일리지 내역으로 이동한다', () => {
    const content = adminTab.injections.find((i: any) => i.target_id === 'extension_tab_content');
    const btn = findFirst(content.components[0], (n) => n.id === 'view_member_mileage_history');
    const json = jsonOf(btn);
    expect(json).toContain('/admin/ecommerce/mileage-transactions');
  });
});

describe('마이페이지 프로필 내 마일리지 카드 주입 (mypage-profile-mileage-card.json)', () => {
  it('mypage/profile 레이아웃을 타깃하고 mypage_tab_content 에 append 한다', () => {
    expect(mypageCard.target_layout).toBe('mypage/profile');
    const inj = mypageCard.injections.find((i: any) => i.target_id === 'mypage_tab_content');
    expect(inj.position).toBe('append_child');
  });

  it('카드가 ecommerce_mileage 부재 시 if 로 숨겨진다', () => {
    const inj = mypageCard.injections.find((i: any) => i.target_id === 'mypage_tab_content');
    const card = inj.components[0];
    expect(card.if).toContain('ecommerce_mileage');
  });

  it('사용 가능 + 소멸 예정 + 마일리지 내역 링크(/mypage/mileage)를 표시한다', () => {
    const inj = mypageCard.injections.find((i: any) => i.target_id === 'mypage_tab_content');
    const json = jsonOf(inj.components[0]);
    expect(json).toContain('ecommerce_mileage?.available');
    expect(json).toContain('ecommerce_mileage?.expiring_soon');
    expect(json).toContain('/mypage/mileage');
  });

  it('카드는 비활성(enabled=false) 시 잔액 대신 비활성 안내를 표시한다', () => {
    const inj = mypageCard.injections.find((i: any) => i.target_id === 'mypage_tab_content');
    const json = jsonOf(inj.components[0]);
    // 비활성 안내 + enabled 게이트가 enabled 키로 분기되어야 한다.
    expect(json).toContain('user_mileage.disabled_notice');
    expect(json).toContain('ecommerce_mileage?.enabled');
  });

  it('사용자 화면(마이페이지) 카드는 ExtensionBadge 를 사용하지 않는다 (회귀 차단)', () => {
    // ExtensionBadge 는 관리자 템플릿(sirsoft-admin_basic)에만 존재하는 컴포넌트로,
    // 사용자 템플릿(sirsoft-basic)에는 미등록이라 사용자 화면에 주입 시 "컴포넌트를 찾을 수 없습니다" 렌더 실패.
    // PO 정책: 사용자 화면에서 ExtensionBadge 사용 금지.
    const json = jsonOf(mypageCard);
    expect(json).not.toContain('ExtensionBadge');
  });
});

describe('admin-user-mileage-tab 비활성 분기', () => {
  it('관리자 탭 콘텐츠는 비활성(enabled=false) 시 비활성 안내를 표시한다', () => {
    const json = jsonOf(adminTab);
    expect(json).toContain('admin.user_mileage.disabled_notice');
    expect(json).toContain('ecommerce_mileage?.enabled');
  });
});

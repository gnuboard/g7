/**
 * useEditEntryFab — 편집 진입 단추 자리 계산, iframe 판정, 라우트 정의 path, 권한 판정 (#869)
 *
 * 단추는 왼쪽 아래 16px 이 기본이고, 그 자리에 걸친 옆 패널(관리자 사이드바)과 하단 고정 바(모바일 구매 바,
 * 하단 탭, 토스트)를 비킨다. 화면 전체 덮개(모달 배경, 드로어 덮개)는 비키지 않는다 — 단추가 그 아래 층이다.
 */

import { describe, it, expect } from 'vitest';
import {
  computeEditEntryFabOffset,
  isEmbeddedFrame,
  readLayoutEditAbility,
  resolveCurrentRouteDefinitionPath,
} from '../../hooks/useEditEntryFab';

const DESKTOP = { width: 1280, height: 800 };
const MOBILE = { width: 375, height: 667 };
const PILL = { width: 130, height: 36 };
const ROUND = { width: 44, height: 44 };

describe('computeEditEntryFabOffset — 고정 요소 회피', () => {
  // @scenario case=placement_desktop_none
  // @effects ops_screen_fab_default_bottom_left
  it('걸친 요소가 없으면 왼쪽 아래 16px', () => {
    expect(computeEditEntryFabOffset([], DESKTOP, PILL)).toEqual({ left: 16, bottom: 16 });
  });

  // @scenario case=placement_desktop_admin_sidebar
  // @effects ops_screen_fab_avoids_side_panel
  it('관리자 사이드바(왼쪽 0, 너비 256, 화면 높이)는 오른쪽으로 비킨다 — 고정 요소가 아니어도', () => {
    const sidebar = { left: 0, top: 0, right: 256, bottom: 800, pinned: false };
    expect(computeEditEntryFabOffset([sidebar], DESKTOP, PILL)).toEqual({ left: 272, bottom: 16 });
  });

  // @scenario case=placement_mobile_bottom_bar
  // @effects ops_screen_fab_avoids_bottom_bar
  it('모바일 하단 고정 바(높이 64) 위로 올라간다', () => {
    const bar = { left: 0, top: 603, right: 375, bottom: 667, pinned: true };
    expect(computeEditEntryFabOffset([bar], MOBILE, ROUND)).toEqual({ left: 16, bottom: 80 });
  });

  // @scenario case=placement_mobile_toast
  // @effects ops_screen_fab_avoids_bottom_toast
  it('단추 띠에 걸친 하단 토스트 위로 올라가고, 띠 밖(오른쪽 아래) 토스트는 무시한다', () => {
    const toastOver = { left: 8, top: 600, right: 367, bottom: 667, pinned: true };
    expect(computeEditEntryFabOffset([toastOver], MOBILE, ROUND).bottom).toBe(83);
    const toastRight = { left: 900, top: 720, right: 1264, bottom: 800, pinned: true };
    expect(computeEditEntryFabOffset([toastRight], DESKTOP, PILL)).toEqual({ left: 16, bottom: 16 });
  });

  // @scenario case=placement_mobile_floating_banner
  // @effects ops_screen_fab_avoids_floating_bottom_panel
  it('아래에서 떠 있는(bottom-4) 쿠키 동의 배너와 토스트 묶음 위로도 올라간다 — 실측 375x667 상품 상세', () => {
    const consent = { left: 16, top: 443, right: 359, bottom: 651, pinned: true };
    expect(computeEditEntryFabOffset([consent], MOBILE, ROUND)).toEqual({ left: 16, bottom: 240 });
    const toastStack = { left: 4, top: 577, right: 371, bottom: 651, pinned: true };
    expect(computeEditEntryFabOffset([toastStack], MOBILE, ROUND).bottom).toBe(106);
    // 아래에서 48px 넘게 떠 있으면(본문 중간 고정 요소) 하단 요소로 보지 않는다
    const midFloating = { left: 0, top: 400, right: 375, bottom: 600, pinned: true };
    expect(computeEditEntryFabOffset([midFloating], MOBILE, ROUND)).toEqual({ left: 16, bottom: 16 });
  });

  // @scenario case=placement_mobile_overlay
  // @effects ops_screen_fab_ignores_full_overlay
  it('화면 전체 덮개와 넓은 모바일 드로어(너비 68%)는 비키지 않는다', () => {
    const overlay = { left: 0, top: 0, right: 375, bottom: 667, pinned: true };
    const drawer = { left: 0, top: 64, right: 256, bottom: 667, pinned: true };
    expect(computeEditEntryFabOffset([overlay, drawer], MOBILE, ROUND)).toEqual({ left: 16, bottom: 16 });
  });

  // @scenario case=placement_desktop_in_flow_footer
  // @effects ops_screen_fab_ignores_in_flow_footer
  it('흐름 안의 꼬리말(pinned=false)은 하단 바로 보지 않는다 — 스크롤하면 비켜난다', () => {
    const footer = { left: 0, top: 700, right: 1280, bottom: 800, pinned: false };
    expect(computeEditEntryFabOffset([footer], DESKTOP, PILL)).toEqual({ left: 16, bottom: 16 });
  });

  // @scenario case=placement_desktop_sidebar_and_bar
  // @effects ops_screen_fab_stays_inside_viewport
  it('사이드바 옆으로 비킨 띠의 하단 바만 보고, 화면 밖으로 나가지 않는다', () => {
    const sidebar = { left: 0, top: 0, right: 256, bottom: 800, pinned: false };
    const barUnderSidebar = { left: 0, top: 740, right: 200, bottom: 800, pinned: true };
    expect(computeEditEntryFabOffset([sidebar, barUnderSidebar], DESKTOP, PILL)).toEqual({ left: 272, bottom: 16 });
    const tall = { left: 0, top: 410, right: 375, bottom: 667, pinned: true };
    expect(computeEditEntryFabOffset([tall], MOBILE, ROUND).bottom).toBe(273);
  });
});

describe('isEmbeddedFrame — 미리보기·임베드 iframe 안에서는 띄우지 않는다', () => {
  // @scenario case=visibility_top
  // @effects ops_screen_fab_hidden_in_iframe
  it('최상위 창이면 false, iframe 안이면 true, top 을 못 읽으면 true', () => {
    const top: any = {};
    top.self = top;
    top.top = top;
    expect(isEmbeddedFrame(top)).toBe(false);
    const framed: any = { top: {} };
    framed.self = framed;
    expect(isEmbeddedFrame(framed)).toBe(true);
    const cross: any = {};
    cross.self = cross;
    Object.defineProperty(cross, 'top', {
      get() {
        throw new Error('cross-origin');
      },
    });
    expect(isEmbeddedFrame(cross)).toBe(true);
  });
});

describe('resolveCurrentRouteDefinitionPath / readLayoutEditAbility', () => {
  // @scenario case=route_param_definition_path
  // @effects ops_screen_fab_passes_route_definition_path
  it('라우터가 없거나 매칭이 없거나 던지면 빈 문자열, 매칭이 있으면 정의 path', () => {
    expect(resolveCurrentRouteDefinitionPath(null, '/x')).toBe('');
    expect(resolveCurrentRouteDefinitionPath({ match: () => null }, '/x')).toBe('');
    expect(
      resolveCurrentRouteDefinitionPath(
        {
          match: () => {
            throw new Error('x');
          },
        },
        '/x',
      ),
    ).toBe('');
    expect(resolveCurrentRouteDefinitionPath({ match: () => ({ route: { path: '*/admin/users' } }) }, '/admin/users')).toBe(
      '*/admin/users',
    );
  });

  // @scenario case=visibility_authenticated_no_perm
  // @effects ops_screen_fab_hidden_without_ability
  it('서버 판정 can_edit_layouts === true 일 때만 참 — permissions 목록은 보지 않는다', () => {
    expect(readLayoutEditAbility({ can_edit_layouts: true })).toBe(true);
    expect(readLayoutEditAbility({ can_edit_layouts: 'true' })).toBe(false);
    expect(readLayoutEditAbility({ permissions: [{ identifier: 'core.templates.layouts.edit' }] })).toBe(false);
    expect(readLayoutEditAbility(null)).toBe(false);
  });
});

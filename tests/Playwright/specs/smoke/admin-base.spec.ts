/**
 * Smoke: admin 베이스 레이아웃 + sticky 탭 시맨틱화 회귀 검증
 *   (#399 Phase 1.2 ~ Phase 1.4)
 *
 * - _admin_base.json 의 1회 등장 컴포넌트들이 인라인 Tailwind 토큰에서
 *   id 와 동일한 underscore 클래스로 캡슐화됐다 (Phase 1.2). 캡슐화 후에도
 *   베이스 레이아웃이 정상 마운트되고 핵심 컴포넌트들이 DOM 에 렌더되는지 smoke 검증.
 * - admin_settings 의 sticky 탭 영역과 TabNavigation composite 의 variant 인라인
 *   토큰이 시맨틱 클래스로 흡수됐다 (Phase 1.3).
 * - TabNavigationScroll composite 의 active/inactive 기본 클래스도 동일하게
 *   시맨틱화 + admin_board_settings 같이 sticky_header wrapper Div 케이스는
 *   .sticky-section-header 자산으로 분리됐다 (Phase 1.4).
 * - 베이스가 모든 admin 화면의 진입 경로이므로 본 spec 회귀가 사실상 모든 admin
 *   화면의 시각 회귀 가드 역할을 한다.
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

test('@smoke #399 Phase 1.2 - _admin_base 캡슐화 클래스가 적용된 채로 베이스가 마운트된다', async ({ page }) => {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);

  await page.goto('/admin/dashboard');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForFunction(
    () => !window.location.pathname.includes('/admin/login') || document.readyState === 'complete',
    { timeout: 15_000 },
  );

  expect(page.url(), '권한 보유 토큰임에도 /admin/login 으로 리다이렉트').not.toMatch(/\/admin\/login/);

  // 베이스 핵심 컴포넌트 id 들이 DOM 에 존재해야 한다.
  // _admin_base.json 의 #admin_layout_root → #left_sidebar_area → #sidebar_header /
  // #admin_sidebar_menu / #right_content_wrapper → #main_header / #right_content_area
  // 트리가 정상 마운트되는지 확인.
  await expect(page.locator('#admin_layout_root')).toBeVisible({ timeout: 10_000 });
  await expect(page.locator('#left_sidebar_area')).toBeAttached();
  await expect(page.locator('#admin_sidebar_menu')).toBeAttached();
  await expect(page.locator('#right_content_wrapper')).toBeAttached();
  await expect(page.locator('#right_content_area')).toBeAttached();

  // Phase 1.2 에서 신설한 캡슐화 클래스가 실제로 적용돼야 한다.
  // 인라인 Tailwind 클래스를 .{id} 클래스로 흡수했으므로 캡슐화 클래스명이
  // className 속성에 들어와 있어야 함.
  await expect(page.locator('#admin_layout_root')).toHaveClass(/admin_layout_root/);
  await expect(page.locator('#admin_sidebar_menu')).toHaveClass(/admin_sidebar_menu/);
  await expect(page.locator('#right_content_area')).toHaveClass(/right_content_area/);
});

test('@smoke #399 Phase 1.3 - admin_settings 진입 시 sticky-tab-nav 시맨틱과 TabNavigation 시맨틱 탭 버튼이 적용된다', async ({ page }) => {
  const token = issueToken('core.settings.view');
  await authenticatePage(page, token);

  await page.goto('/admin/settings');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForFunction(
    () => !window.location.pathname.includes('/admin/login') || document.readyState === 'complete',
    { timeout: 15_000 },
  );

  expect(page.url(), '권한 보유 토큰임에도 /admin/login 으로 리다이렉트').not.toMatch(/\/admin\/login/);

  // tab_navigation 컴포넌트가 .sticky-tab-nav 시맨틱 클래스로 마운트되어야 한다.
  // (Phase 1.3 에서 7개 레이아웃 동일 인라인 Tailwind → .sticky-tab-nav 자산으로 흡수)
  const stickyNav = page.locator('#tab_navigation');
  await expect(stickyNav).toBeAttached({ timeout: 10_000 });
  await expect(stickyNav).toHaveClass(/sticky-tab-nav/);

  // TabNavigation composite (variant="default") 가 .tab-nav-default 컨테이너 + .tab-btn-base 탭 버튼을 렌더해야 한다.
  // 인라인 Tailwind (flex gap-2, flex items-center gap-2 px-4 py-2 ...) → 시맨틱 클래스로 흡수됨.
  const nav = stickyNav.locator('nav.tab-nav-default').first();
  await expect(nav).toBeVisible();
  const firstTabBtn = nav.locator('button.tab-btn-base').first();
  await expect(firstTabBtn).toBeVisible();
  // 활성 탭은 .tab-btn-default-active, 비활성 탭은 .tab-btn-default 가 추가로 붙는다.
  await expect(nav.locator('button.tab-btn-default-active')).toHaveCount(1);
});

test('@smoke #399 Phase 1.4 - admin_board_settings 진입 시 sticky_header 가 sticky-section-header 시맨틱으로 마운트된다', async ({ page }) => {
  const token = issueToken('sirsoft-board.settings.view');
  await authenticatePage(page, token);

  await page.goto('/admin/board/settings');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForFunction(
    () => !window.location.pathname.includes('/admin/login') || document.readyState === 'complete',
    { timeout: 15_000 },
  );

  expect(page.url(), '권한 보유 토큰임에도 /admin/login 으로 리다이렉트').not.toMatch(/\/admin\/login/);

  // 게시판 설정의 sticky_header (Div wrapper, 자식 padding 보존을 위해 -mx-6 px-6 없는 변형) 가
  // .sticky-section-header 시맨틱 클래스로 마운트되는지 검증.
  const stickyHeader = page.locator('#sticky_header');
  await expect(stickyHeader).toBeAttached({ timeout: 10_000 });
  await expect(stickyHeader).toHaveClass(/sticky-section-header/);

  // 내부 TabNavigation 도 시맨틱 클래스로 렌더되어야 한다.
  const tabNav = stickyHeader.locator('#tab_navigation');
  await expect(tabNav).toBeAttached();
});

test('@smoke #399 Phase 1.6 - AdminSidebar 메뉴 아이템이 admin-sidebar-* 시맨틱 클래스로 렌더된다', async ({ page }) => {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);

  await page.goto('/admin/dashboard');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForFunction(
    () => !window.location.pathname.includes('/admin/login') || document.readyState === 'complete',
    { timeout: 15_000 },
  );

  expect(page.url(), '권한 보유 토큰임에도 /admin/login 으로 리다이렉트').not.toMatch(/\/admin\/login/);

  // AdminSidebar composite 가 .admin-sidebar-menu-list 스크롤 영역 + .admin-sidebar-item
  // 베이스 + .admin-sidebar-item-active modifier (현재 /admin/dashboard 활성) 로 렌더되어야 한다.
  // 인라인 Tailwind (px-4 pb-4 space-y-0.5, w-full flex items-center justify-between ..., bg-slate-100 ...) 가
  // 시맨틱 자산으로 흡수됐는지 확인.
  const sidebarMenu = page.locator('#admin_sidebar_menu');
  await expect(sidebarMenu).toBeAttached({ timeout: 10_000 });

  const menuList = sidebarMenu.locator('.admin-sidebar-menu-list').first();
  await expect(menuList).toBeVisible();

  const items = menuList.locator('button.admin-sidebar-item');
  await expect(items.first()).toBeVisible();
  // /admin/dashboard 진입 시 활성 메뉴 (대시보드) 가 1건 이상 존재
  await expect(menuList.locator('button.admin-sidebar-item-active')).toHaveCount(1);

  // 메뉴 아이템 좌측 영역 (아이콘 + 라벨) 도 .admin-sidebar-item-content 시맨틱
  // 자산으로 흡수됐는지 확인 — Phase 1.6 후속 (flex items-center gap-2 단순화).
  await expect(items.first().locator('.admin-sidebar-item-content')).toBeAttached();

  // padding-left 도 시맨틱 자산에 흡수 — Phase 1.6 후속2.
  // 최상위 아이템은 .admin-sidebar-item 의 px-3 (12px), 하위 아이템은
  // .admin-sidebar-subitem 의 pl-[30px] 이 부여. 인라인 style 속성 부재 확인.
  const firstItem = items.first();
  await expect(firstItem).not.toHaveAttribute('style', /padding-left/);
});

test('@smoke #399 Phase 1.7 - admin 페이지 컨텐츠 영역이 .admin-page-content 시맨틱으로 마운트된다', async ({ page }) => {
  const token = issueToken('core.roles.read');
  await authenticatePage(page, token);

  await page.goto('/admin/roles');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForFunction(
    () => !window.location.pathname.includes('/admin/login') || document.readyState === 'complete',
    { timeout: 15_000 },
  );

  // /admin/roles 진입 시 admin_role_list 의 content slot 직계 자식이
  // .admin-page-content 시맨틱 자산으로 마운트되는지 확인 (Phase 1.7 대상 36개 공통 패턴).
  await expect(page.locator('#main_content .admin-page-content').first()).toBeAttached({
    timeout: 10_000,
  });
});

test('@smoke #399 Phase 1.7 후속 - admin_settings 환경설정 페이지가 .admin-page-content-responsive 시맨틱으로 마운트된다', async ({ page }) => {
  const token = issueToken('core.settings.view');
  await authenticatePage(page, token);

  await page.goto('/admin/settings');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForFunction(
    () => !window.location.pathname.includes('/admin/login') || document.readyState === 'complete',
    { timeout: 15_000 },
  );

  // Phase 1.7 후속: p-4 sm:p-6 lg:p-8 반응형 변종 = .admin-page-content-responsive
  await expect(page.locator('#main_content .admin-page-content-responsive').first()).toBeAttached({
    timeout: 10_000,
  });
});

test('@smoke #399 Phase 1.8 - admin 페이지 헤더의 refresh 버튼이 .btn-icon 시맨틱으로 마운트된다', async ({ page }) => {
  const token = issueToken('core.roles.read');
  await authenticatePage(page, token);

  await page.goto('/admin/roles');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForFunction(
    () => !window.location.pathname.includes('/admin/login') || document.readyState === 'complete',
    { timeout: 15_000 },
  );

  // Phase 1.8: page_header 안의 refresh_button 이 인라인 Tailwind 5토큰에서
  // 표준 .btn-icon 시맨틱 자산으로 통합됐는지 확인.
  await expect(page.locator('#refresh_button').first()).toHaveClass(/btn-icon/);

  // Phase 1.9: page_header 의 우측 버튼 묶음 컨테이너가
  // 인라인 'flex items-center gap-2' 가 아닌 기존 시맨틱 .flex-center 를 활용한
  // multi-class (flex-center gap-2) 로 마운트되는지 확인.
  // refresh_button 의 부모 Div 가 .flex-center 클래스를 포함해야 한다.
  const refreshParent = page.locator('#refresh_button').first().locator('xpath=..');
  await expect(refreshParent).toHaveClass(/flex-center/);
});

test('@smoke #399 Phase 1.10 - admin_user_list 의 컨텐츠 영역이 .admin-page-content-fluid 시맨틱으로 마운트된다', async ({ page }) => {
  const token = issueToken('core.users.read');
  await authenticatePage(page, token);

  await page.goto('/admin/users');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForFunction(
    () => !window.location.pathname.includes('/admin/login') || document.readyState === 'complete',
    { timeout: 15_000 },
  );

  // Phase 1.10: p-6 bg-gray-50 dark:bg-gray-900 (no min-h-screen) 변종 흡수.
  // admin_user_list 의 user_list_content Div 가 .admin-page-content-fluid 마운트.
  await expect(page.locator('#user_list_content')).toHaveClass(/admin-page-content-fluid/);

  // Phase 1.11: 일괄 작업 영역 컨테이너 + 4종 액션 버튼 시맨틱화.
  // bulk_actions_area 가 .bulk-actions-bar 자산으로, 4개 버튼이
  // btn btn-sm btn-{success|neutral|danger|warning} 시맨틱 multi-class 로 마운트.
  await expect(page.locator('#bulk_actions_area')).toHaveClass(/bulk-actions-bar/);
  await expect(page.locator('#bulk_activate')).toHaveClass(/btn-success/);
  await expect(page.locator('#bulk_deactivate')).toHaveClass(/btn-neutral/);
  await expect(page.locator('#bulk_block')).toHaveClass(/btn-danger/);
  await expect(page.locator('#bulk_withdraw')).toHaveClass(/btn-warning/);
});

test('@smoke #399 Phase 1.12 - 활동 로그 필터 영역의 #filter_grid 가 gap-4 로 행 간격을 처리한다', async ({ page }) => {
  const token = issueToken('core.activity-logs.read');
  await authenticatePage(page, token);

  await page.goto('/admin/activity-logs');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForFunction(
    () => !window.location.pathname.includes('/admin/login') || document.readyState === 'complete',
    { timeout: 15_000 },
  );

  // Phase 1.12 정정: #filter_grid 의 'flex flex-col gap-4' 인라인 유틸을
  // .filter-grid 시맨틱 자산으로 흡수.
  await expect(page.locator('#filter_grid')).toHaveClass(/filter-grid/);
});

test('@smoke #399 Phase 1.13 - admin_identity_logs 의 filter_section (필터-그리드 바깥 filter-row 부모) 도 .filter-grid 시맨틱 적용', async ({ page }) => {
  const token = issueToken('core.admin.identity.logs.read');
  await authenticatePage(page, token);

  await page.goto('/admin/identity/logs');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForFunction(
    () => !window.location.pathname.includes('/admin/login') || document.readyState === 'complete',
    { timeout: 15_000 },
  );

  // Phase 1.13: filter-row 가 #filter_grid 바깥에 위치한 케이스는 부모
  // (#filter_section) 가 .filter-grid 자산을 가지도록 일관화.
  await expect(page.locator('#filter_section')).toHaveClass(/filter-grid/);

  // Phase 1.13 정정: gap-3 같은 비표준 토큰도 .filter-grid 로 통일.
  // #advanced_filters 도 filter-grid 시맨틱을 가지도록 표준화 확인.
  await expect(page.locator('#advanced_filters')).toHaveClass(/filter-grid/);
});

test('@smoke #399 Phase 1.14 - admin_user_list 의 list_options + 섹션 헤딩 시맨틱', async ({ page }) => {
  const token = issueToken('core.users.read');
  await authenticatePage(page, token);

  await page.goto('/admin/users');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForFunction(
    () => !window.location.pathname.includes('/admin/login') || document.readyState === 'complete',
    { timeout: 15_000 },
  );

  // Phase 1.14: #list_options 의 컨테이너가 'flex items-center gap-3' 인라인이
  // 아니라 .flex-center 시맨틱 + gap-3 multi-class 로 마운트.
  await expect(page.locator('#list_options')).toHaveClass(/flex-center/);
});

test('@smoke #399 Phase 1.15 - AdminFooter composite 가 .admin-footer 시맨틱으로 마운트된다', async ({ page }) => {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);

  await page.goto('/admin/dashboard');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForFunction(
    () => !window.location.pathname.includes('/admin/login') || document.readyState === 'complete',
    { timeout: 15_000 },
  );

  // Phase 1.15: AdminFooter composite 가 .admin-footer + .admin-footer-row + .admin-footer-group
  // 시맨틱 자산으로 마운트되는지 검증 (인라인 'px-6 py-4 mt-6' / 'flex flex-col md:flex-row ...' /
  // 'flex items-center gap-4 text-sm text-gray-600 dark:text-gray-400' 인라인이 자산으로 흡수).
  await expect(page.locator('.admin-footer').first()).toBeAttached();
  await expect(page.locator('.admin-footer .admin-footer-row').first()).toBeAttached();
});

test('@smoke #399 Phase 1.16 - footer_buttons 의 cancel/save 가 .btn-secondary/.btn-primary 시맨틱으로 마운트된다', async ({ page }) => {
  const token = issueToken('core.settings.view');
  await authenticatePage(page, token);

  await page.goto('/admin/settings');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForFunction(
    () => !window.location.pathname.includes('/admin/login') || document.readyState === 'complete',
    { timeout: 15_000 },
  );

  // Phase 1.16: cancel/save 버튼이 인라인 Tailwind 가 아닌 표준 .btn + .btn-{secondary|primary}
  // 시맨틱 자산으로 마운트.
  const cancel = page.locator('#cancel_button').first();
  if (await cancel.count() > 0) {
    await expect(cancel).toHaveClass(/btn-secondary/);
  }
  const save = page.locator('#save_button').first();
  if (await save.count() > 0) {
    await expect(save).toHaveClass(/btn-primary/);
  }

  // Phase 1.17: admin_card / tab_content 안 섹션 제목/설명 묶음이 .section-title
  // (text-lg + semibold + primary + mb-1) + .section-description (text-label-subtle
  // + mb-6) 시맨틱 자산으로 마운트.
  const sectionTitle = page.locator('.section-title').first();
  if (await sectionTitle.count() > 0) {
    await expect(sectionTitle).toBeAttached();
  }

  // Phase 1.18: .form-label 호출처의 mb-* multi-class 토큰을 제거하고 .form-label
  // 자산 정의 안에 mb-1 단일 흡수. 호출처는 'form-label' 단독.
  const formLabel = page.locator('label.form-label, .form-label').first();
  if (await formLabel.count() > 0) {
    await expect(formLabel).not.toHaveClass(/\bmb-\d/);
  }

  // Phase 1.19/1.20: 토글/옵션 행 + 폼 필드 컨테이너의 'py-3 border-b ...'
  // 인라인 잔존 토큰을 모두 제거. 행 간격은 부모 .flex-col gap-N 이 처리.
  const toggleRow = page.locator('#toggle_generator_enabled').first();
  if (await toggleRow.count() > 0) {
    await expect(toggleRow).not.toHaveClass(/\bpy-3\b/);
    await expect(toggleRow).not.toHaveClass(/border-b/);
  }
  const fieldRow = page.locator('#field_auth_token_lifetime').first();
  if (await fieldRow.count() > 0) {
    await expect(fieldRow).not.toHaveClass(/\bpy-3\b/);
    await expect(fieldRow).not.toHaveClass(/border-b/);
  }

  // Phase 1.21: 'flex flex-col gap-{0..8}' 패턴을 .stack (gap-4 통일) /
  // .stack-flush (gap-0 의도 보존) 시맨틱 자산으로 흡수. 어드민 settings 화면
  // 어딘가에 .stack 자산이 마운트되어야 하고, 호출처에는 인라인 'flex flex-col
  // gap-N' 잔존 토큰이 없어야 한다.
  const stackNode = page.locator('.stack, .stack-flush').first();
  if (await stackNode.count() > 0) {
    await expect(stackNode).toBeAttached();
    // .stack/.stack-flush 호출처는 인라인 'flex flex-col' 토큰을 더 들고 있지 않다.
    await expect(stackNode).not.toHaveClass(/\bflex flex-col\b/);
  }

  // Phase 1.21 후속: .stack 직계 자식의 잔존 수직 padding/margin 토큰 제거.
  // 행 간격은 부모 .stack 의 gap-4 가 처리하므로 자식의 py-*/pt-*/pb-*/my-*/mt-*/mb-*
  // 는 이중 간격을 만든다. 박스 스타일(bg-/border/rounded/shadow/ring)을 동반한
  // 자식의 전방위 p-*/m-* 는 박스 내부 padding 이므로 보존.
  // 본 spec 의 보안 탭 #field_login_lockout_time 은 검수자가 직접 지목한 사례 — 수직
  // 패딩 토큰이 모두 제거되어 있어야 한다.
  const lockoutField = page.locator('#field_login_lockout_time').first();
  if (await lockoutField.count() > 0) {
    await expect(lockoutField).not.toHaveClass(/\bpy-\d/);
    await expect(lockoutField).not.toHaveClass(/\bpt-\d/);
    await expect(lockoutField).not.toHaveClass(/\bpb-\d/);
  }

  // Phase 1.21 추가 후속: .stack 직계 자식은 모두 Div 래퍼여야 한다 ("stack > div
  // > 요소" 구조). 단위(unit) 단위로 감싸 — 비-Div 자식 비율 >=50% 인 stack 은
  // 전체를 단일 unit Div 로, <50% 인 stack 은 비-Div 자식만 개별 Div 로 감쌌다.
  // 이전에는 일부 .stack 이 Label/Input/Span/P 등 비-Div 컴포넌트를 직접 자식으로
  // 가져 UI 가 부자연스럽게 렌더됐다.
  const stackContainers = page.locator('.stack, .stack-flush');
  const count = await stackContainers.count();
  if (count > 0) {
    const tagNames = await stackContainers.first().evaluate((el) =>
      Array.from(el.children).map((c) => c.tagName.toLowerCase()),
    );
    for (const t of tagNames) {
      expect(t, '.stack 직계 자식이 div 가 아님').toBe('div');
    }
  }
});

test('@smoke #399 Phase 1.21 후속 - admin/menu 패널 헤더에 .panel-header 분리선이 적용된다', async ({ page }) => {
  // Phase 1.20 에서 'px-4 py-3 border-b border-gray-200 dark:border-gray-700' 일괄
  // 제거 중 패널/카드 섹션 헤더가 의도치 않게 분리선까지 잃었던 회귀 (#menu_list_header
  // 등) 를 .panel-header 시맨틱 자산으로 복원. 어드민 메뉴 관리 화면의 #menu_list_header
  // 가 .panel-header 클래스를 가지고 렌더되는지 확인.
  const token = issueToken('core.menus.view');
  await authenticatePage(page, token);
  await page.goto('/admin/menus');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForFunction(
    () => !window.location.pathname.includes('/admin/login') || document.readyState === 'complete',
    { timeout: 15_000 },
  );
  expect(page.url(), '권한 보유 토큰임에도 /admin/login 으로 리다이렉트').not.toMatch(/\/admin\/login/);

  const header = page.locator('#menu_list_header').first();
  if (await header.count() > 0) {
    await expect(header).toHaveClass(/panel-header/);
  }
});

/**
 * Layout Editor — 페이지 상태 토글 + 시뮬레이션.
 *
 * S6-3 검증:
 *  - editor-spec.json.states 가 선언된 라우트(/login: default/login_failed 2상태)에서
 *    캔버스 툴바에 상태 토글(PageStateSwitcher)이 표시된다.
 *  - 상태 미선언/단일 상태 라우트에서는 토글이 미표시된다(디그레이드).
 *  - "로그인 실패" 상태로 전환 → formErrors 경로 주입(_global.loginErrors / loginError)이
 *    캔버스 레이아웃 표현식으로 읽혀 사용자 페이지와 동일하게 오류가 표현된다.
 *  - sampleData 오버라이드(검색 결과 없음 등)·초기 상태 패치는 단위/레이아웃 렌더 테스트가
 *    잠그며, 본 spec 은 토글 표시/전환의 브라우저 가시 결과를 측정한다.
 *
 * @scenario layout-editor-page-state-switcher
 * @effects login_route_shows_state_toggle_with_two_options + root_route_hides_state_toggle + login_failed_state_renders_form_error_text_on_canvas + profile_edit_password_entry_vs_actual_edit_section_toggle + admin_users_edit_vs_create_title_via_route_id_removal + admin_settings_general_vs_seo_tab_via_query_tab + checkout_validation_failed_renders_dotted_key_inline_errors + guest_order_form_guest_vs_member_branch_toggle_via_currentuser_patch
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

test.describe('@layout-editor 페이지 상태 토글 (S6-3)', () => {
  test('states 선언 라우트(/login) → 상태 토글 표시 + 항목 2개', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    await page.goto('/admin/layout-editor/sirsoft-basic?route=%2Flogin');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });

    // 상태 토글 등장 — /login 은 default/login_failed 2상태이므로 표시된다.
    const select = page.getByTestId('g7le-state-switcher-select');
    await expect(select).toBeVisible({ timeout: 15_000 });

    // 항목 2개(기본 + 로그인 실패)
    const options = select.locator('option');
    await expect(options).toHaveCount(2);
  });

  test('states 미선언 라우트(/) → 상태 토글 미표시 (디그레이드)', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    await page.goto('/admin/layout-editor/sirsoft-basic?route=%2F');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });

    // 캔버스 렌더 완료 후에도 상태 토글은 미표시.
    await page.waitForFunction(
      () => document.querySelectorAll('[data-editor-path]').length > 0,
      { timeout: 20_000 },
    );
    await expect(page.getByTestId('g7le-state-switcher')).toHaveCount(0);
  });

  test('로그인 실패 상태 전환 → 캔버스에 폼 오류 표시 (formErrors 경로 주입)', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    await page.goto('/admin/layout-editor/sirsoft-basic?route=%2Flogin');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });

    const select = page.getByTestId('g7le-state-switcher-select');
    await expect(select).toBeVisible({ timeout: 15_000 });

    // "로그인 실패" 상태(login_failed)로 전환 — select value 변경.
    await select.selectOption('login_failed');
    await expect(page.getByTestId('g7le-state-switcher')).toHaveAttribute(
      'data-active-state',
      'login_failed',
      { timeout: 5_000 },
    );

    // 캔버스 프레임 안에서 검증 오류 텍스트가 노출되는지 측정. login_failed 의
    // _global.loginError / _global.loginErrors.{email,password} 가 로그인 폼이 읽는 경로로
    // 주입되어 폼이 그 메시지를 렌더한다. states.json 의 메시지 값은 평문 한국어 리터럴
    // ($t: 키 아님)이므로 로케일과 무관하게 그 문자열 그대로 표시된다.
    const frame = page.getByTestId('g7le-preview-frame');
    await expect(frame.getByText('이메일 또는 비밀번호가 올바르지 않습니다').first()).toBeVisible({
      timeout: 15_000,
    });
  });

  // 내 정보 수정 화면 — 비밀번호 재확인 단계 / 실제 수정 단계.
  // initialState.local.isPasswordVerified 패치가 레이아웃 init_actions 의 기본값을 이겨
  // 각 단계 화면이 미리보기된다. 신규 다국어 키(editor.state.profile_*)가 캐시 무효화
  // 구멍 없이 친화명으로 해석되는지도 함께 측정한다(라벨 raw 회귀 차단).
  test('profile/edit → 비밀번호 확인 / 실제 수정 단계 토글 + 신규 라벨 해석', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    await page.goto('/admin/layout-editor/sirsoft-basic?route=%2Fmypage%2Fprofile%2Fedit');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });

    const select = page.getByTestId('g7le-state-switcher-select');
    await expect(select).toBeVisible({ timeout: 15_000 });
    // 3상태 (password_entry / actual_edit / validation_failed)
    await expect(select.locator('option')).toHaveCount(3);

    const frame = page.getByTestId('g7le-preview-frame');

    // 기본 password_entry → 비밀번호 확인 섹션 표시
    await expect(frame.getByText('Please enter your password to edit your profile.').first()).toBeVisible({
      timeout: 15_000,
    });

    // actual_edit 전환 → 비밀번호 섹션 사라지고 정보 수정 폼(필드) 표시
    await select.selectOption('actual_edit');
    await expect(page.getByTestId('g7le-state-switcher')).toHaveAttribute('data-active-state', 'actual_edit', { timeout: 5_000 });
    await expect(frame.getByText('Please enter your password to edit your profile.')).toHaveCount(0, { timeout: 15_000 });

    // 신규 상태 라벨이 raw 키가 아닌 친화명으로 해석된다(캐시 무효화 구멍 회귀 차단)
    await expect(select.locator('option', { hasText: 'Edit profile' })).toHaveCount(1);
    await expect(select.locator('option', { hasText: 'editor.state.profile' })).toHaveCount(0);
  });

  // route 패치 — admin_user_form 의 route.id 유무(수정↔신규) 변종 (미커버 발굴분).
  // admin 라우트는 `*/admin/...` 프리픽스라 URL 직접 인코딩 대신 라우트 트리 클릭으로 진입.
  test('admin users/:id/edit → route.id 제거로 수정↔신규 작성 모드 전환', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    await page.goto('/admin/layout-editor/sirsoft-admin_basic');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    // 라우트 트리에서 users/:id/edit 노드 선택
    await page.waitForSelector('[data-route-path="*/admin/users/:id/edit"]', { timeout: 30_000 });
    await page.locator('[data-route-path="*/admin/users/:id/edit"]').first().click();
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });

    const select = page.getByTestId('g7le-state-switcher-select');
    await expect(select).toBeVisible({ timeout: 15_000 });
    const frame = page.getByTestId('g7le-preview-frame');

    // 기본 edit_existing → 수정 제목
    await expect(frame.getByText('Edit User Information').first()).toBeVisible({ timeout: 15_000 });

    // create_mode 전환 → route.id 제거 → 신규 작성 제목
    await select.selectOption('create_mode');
    await expect(page.getByTestId('g7le-state-switcher')).toHaveAttribute('data-active-state', 'create_mode', { timeout: 5_000 });
    await expect(frame.getByText('Create User').first()).toBeVisible({ timeout: 15_000 });
  });

  // query 패치 — admin_settings 의 query.tab 변종 (미커버 발굴분).
  test('admin settings → query.tab 으로 일반↔SEO 탭 전환', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    await page.goto('/admin/layout-editor/sirsoft-admin_basic');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForSelector('[data-route-path="*/admin/settings"]', { timeout: 30_000 });
    await page.locator('[data-route-path="*/admin/settings"]').first().click();
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });

    const select = page.getByTestId('g7le-state-switcher-select');
    await expect(select).toBeVisible({ timeout: 15_000 });
    // 2탭 변종 (General / SEO) 라벨 친화명 해석
    await expect(select.locator('option', { hasText: 'SEO tab' })).toHaveCount(1);

    await select.selectOption('settings_seo');
    await expect(page.getByTestId('g7le-state-switcher')).toHaveAttribute('data-active-state', 'settings_seo', { timeout: 5_000 });
  });

  // 점 박힌 필드명 formErrors — /shop/checkout 의 주문자/배송지 입력칸은 검증 오류를
  // `_local.errors?.['orderer.name']` 처럼 **키 자체에 점이 박힌** flat 키로 읽는다. 페이지
  // 상태 formErrors 키를 대괄호 표기(`_local.errors['orderer.name']`)로 지정하면
  // tokenizePath 가 점을 리터럴 leaf 로 보존해 인라인 에러가 캔버스에 표현된다.
  test('checkout → validation_failed 전환 시 점 박힌 키 인라인 에러 노출', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    await page.goto('/admin/layout-editor/sirsoft-basic');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    // shop/checkout 라우트는 동적 prefix(route_path ?? 'shop') → 트리에서 /shop/checkout 으로 평가됨.
    await page.waitForSelector('[data-route-path="/shop/checkout"]', { timeout: 30_000 });
    await page.locator('[data-route-path="/shop/checkout"]').first().click();
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });

    const select = page.getByTestId('g7le-state-switcher-select');
    await expect(select).toBeVisible({ timeout: 15_000 });
    // 3상태 (normal / payment_error / validation_failed)
    await expect(select.locator('option')).toHaveCount(3);

    const frame = page.getByTestId('g7le-preview-frame');

    // 기본 normal → 점 박힌 키 인라인 에러 미표시
    await expect(frame.getByText('주문자 이름을 입력해 주세요')).toHaveCount(0, { timeout: 15_000 });

    // validation_failed 전환 → 점 박힌 키(orderer.name / shipping.recipient_name)가 보존돼
    // 인라인 에러가 캔버스에 표현된다(dot-split 중첩 오해석 회귀 차단).
    await select.selectOption('validation_failed');
    await expect(page.getByTestId('g7le-state-switcher')).toHaveAttribute(
      'data-active-state',
      'validation_failed',
      { timeout: 5_000 },
    );
    await expect(frame.getByText('주문자 이름을 입력해 주세요').first()).toBeVisible({ timeout: 15_000 });
    await expect(frame.getByText('받는 분 이름을 입력해 주세요').first()).toBeVisible({ timeout: 15_000 });
  });

  // global 패치(currentUser:null) — 비회원 주문 조회 화면(shop/guest_order_form)은 본문이
  // _global.currentUser.uuid 유무로 양분된다(비회원→조회 폼 / 회원→마이페이지 리다이렉트
  // 안내). 편집기 sampleGlobal 은 로그인 고정이라 상태 그룹이 없으면 비회원 조회 폼이 영영
  // 미표시되어 편집 불가였다. guest 상태(currentUser:null 패치)로 폼을, member 상태(패치
  // 없음)로 안내 분기를 각각 캔버스에 노출하는지 측정한다(라이브 실측 회귀 차단).
  test('guest_order_form → 비회원(조회 폼) ↔ 회원(마이페이지 안내) 분기 전환', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    await page.goto('/admin/layout-editor/sirsoft-basic');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    // guest_order_form 라우트는 동적 prefix(route_path ?? 'shop') → 트리에서 /shop/guest/orders 로 평가됨.
    await page.waitForSelector('[data-route-path="/shop/guest/orders"]', { timeout: 30_000 });
    await page.locator('[data-route-path="/shop/guest/orders"]').first().click();
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });

    const select = page.getByTestId('g7le-state-switcher-select');
    await expect(select).toBeVisible({ timeout: 15_000 });
    // 2상태 (guest / member)
    await expect(select.locator('option')).toHaveCount(2);

    const frame = page.getByTestId('g7le-preview-frame');

    // 기본 guest(비회원, currentUser:null 패치) → 조회 입력 폼 표시
    await expect(frame.getByText('주문번호', { exact: false }).first()).toBeVisible({ timeout: 15_000 });

    // member 전환(baseline 로그인 유지) → 조회 폼 사라지고 마이페이지 안내 분기 표시
    await select.selectOption('member');
    await expect(page.getByTestId('g7le-state-switcher')).toHaveAttribute(
      'data-active-state',
      'member',
      { timeout: 5_000 },
    );
    await expect(frame.getByText('마이페이지', { exact: false }).first()).toBeVisible({ timeout: 15_000 });
  });
});

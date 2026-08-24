/**
 * E2E: 비즈뿌리오 알림 템플릿 라이프사이클 화면 (#597)
 *
 * @scenario bizppurio_tab_integration_e2e, bizppurio_compose_modal_e2e, bizppurio_manage_round_trip_e2e
 * @effects bizppurio_tab_replaces_sms_and_alimtalk_tabs, row_footer_shows_status_badge_and_lifecycle_actions,
 *          compose_modal_switches_conditional_fields_by_type, manage_screen_lists_db_rows_with_merge_query_round_trip,
 *          manage_list_mobile_card_view_and_unified_toolbar
 *
 * 검증 대상(브라우저에서만 잡히는 축):
 *  1. 코어 알림 설정 서브탭 통합 — '비즈뿌리오' 단일 탭 노출, 문자·알림톡 개별 탭 부재
 *     (탭 필터 표현식 3면 수정의 소비처 실렌더)
 *  2. 행 하단 라이프사이클 UI — 상태 배지 + 작성 버튼 + SMS 체크박스 렌더, i18n 원문 노출 0
 *  3. 작성 모달 — 강조 유형 전환(없음→강조표기→이미지→아이템리스트) 시 조건부 필드 등장/소멸
 *  4. 플러그인 관리 화면 — 상태 필터가 URL(bz_status)에 보존되는 목록 왕복(mergeQuery)
 *
 * 카카오 API(kapi) 실호출이 필요한 검수 신청·동기화 경로는 브라우저 E2E 범위 밖이다
 * (자격증명·실검수 필요 — PHPUnit 이 Http::fake 로 전수 가드, 실연동은 별도 수행 단계).
 *
 * 계획서 §6.2 의 "작성→신청→(kapi mock 승인)→발송 배지" 왕복 축을 여기에 두지 않은 이유는
 * 구조적이다: kapi 호출은 서버(PHP)에서 서버로 나가고, Playwright 의 route 가로채기는
 * 브라우저→서버 구간만 덮는다. 따라서 브라우저 경로에서는 승인 전이를 mock 으로 만들 수
 * 없고, approved 상태를 만들려면 DB 를 직접 조작해야 하는데 그것은 화면 왕복 검증이 아니다.
 * 승인 전이와 발송 게이트는 PHPUnit(BizppurioTemplateServiceTest 의 sync 전이,
 * AlimtalkChannelDriverTest 의 게이트 5분기)이 소유하고, 브라우저는 그 결과를 그리는
 * 배지·액션 분기(위 2번 축)만 담당한다.
 */
import { test, expect, authenticatePage } from '../../fixtures/bizppurio-auth';

const SETTINGS_URL = '/admin/settings?tab=notification_definitions&channel=alimtalk';
const MANAGE_URL = '/admin/plugins/sirsoft-message_bizppurio/settings?tab=templates';

test.describe('비즈뿌리오 통합 탭 (코어 알림 설정)', () => {
  // @scenario bizppurio_tab_integration_e2e
  // @effects bizppurio_tab_replaces_sms_and_alimtalk_tabs, tab_visible_when_any_of_tab_channels_active
  test('채널 활성 저장 시 서브탭에 비즈뿌리오 단일 탭이 노출되고 문자·알림톡 개별 탭은 없다', async ({ page, messagingManageToken }) => {
    await authenticatePage(page, messagingManageToken);
    await page.goto(SETTINGS_URL);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    const tabBar = page.locator('#channel_sub_tabs');
    await expect(tabBar).toBeVisible({ timeout: 30_000 });

    // 확장 채널은 opt-in(미저장=OFF)이라 통합 탭은 tab_channels 중 하나라도 활성 저장돼야
    // 노출된다. 미노출 상태면 알림톡 토글 카드를 켜고 저장해 활성화한다(멱등 — 이미 켜져
    // 있으면 이 분기를 타지 않는다).
    if (!(await tabBar.innerText()).includes('비즈뿌리오')) {
      const card = page
        .locator('#channel_toggles')
        .locator('div.flex-between', { hasText: '비즈뿌리오 알림톡' })
        .first();
      await expect(card).toBeVisible({ timeout: 15_000 });
      await card.locator('[role="switch"]').click();

      await page.locator('#save_button').click();
      await page.waitForLoadState('networkidle', { timeout: 30_000 });
      await page.goto(SETTINGS_URL);
      await page.waitForLoadState('networkidle', { timeout: 30_000 });
    }

    const tabTexts = await tabBar.locator('button').allInnerTexts();
    const joined = tabTexts.join('|');

    // 통합 탭 라벨(tab_label_key) 노출 + 채널 개별 라벨 부재
    expect(joined).toContain('비즈뿌리오');
    expect(joined).not.toContain('비즈뿌리오 문자');
    expect(joined).not.toContain('비즈뿌리오 알림톡');
  });

  // @scenario bizppurio_tab_integration_e2e
  // @effects row_footer_shows_status_summary_only, approval_badge_two_tier
  test('알림 행 하단에 알림톡 승인 2단 배지와 SMS 설정 요약만 렌더된다(버튼 0)', async ({ page, messagingManageToken }) => {
    await authenticatePage(page, messagingManageToken);
    await page.goto(SETTINGS_URL);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    const rows = page.locator('[id^="bizppurio_row_lifecycle"]');
    await expect(rows.first()).toBeVisible({ timeout: 30_000 });

    // 알림톡 라벨 + 승인 2단 배지(승인됨 | 미승인 (세부)) + SMS 설정 요약 — 버튼은 없다(조작은 [편집] 모달)
    const firstRow = rows.first();
    await expect(firstRow.getByText('알림톡', { exact: false }).first()).toBeVisible();
    await expect(firstRow.getByText(/승인됨|미승인/).first()).toBeVisible();
    await expect(firstRow.getByText('대체 SMS', { exact: false }).first()).toBeVisible();
    await expect(firstRow.getByText('SMS 단독', { exact: false }).first()).toBeVisible();
    // SMS 사용 여부는 문구("대체 SMS: 미사용")가 아니라 배지(사용/미사용)로 렌더된다 (제품 결정 2026-08-23)
    expect(await firstRow.locator('span.rounded', { hasText: /^(사용|미사용)$/ }).count()).toBeGreaterThanOrEqual(2);
    expect(await firstRow.getByText(/대체 SMS:|SMS 단독:/).count()).toBe(0);
    expect(await rows.locator('button').count()).toBe(0);

    // i18n 원문 키 노출 0 (탭 콘텐츠 영역 전체)
    const content = await page.locator('#notif_channel_content').innerText();
    expect(content).not.toContain('$t:');
    expect(content).not.toContain('sirsoft-message_bizppurio.');
  });

  // @scenario bizppurio_edit_modal_e2e, bizppurio_compose_modal_e2e
  // @effects edit_modal_hosts_alimtalk_and_sms_sections, core_modal_hides_editor_for_hidden_template_editor_channel, compose_modal_switches_conditional_fields_by_type
  test('[편집] 모달의 알림톡 섹션에서 강조 유형 전환 시 조건부 필드가 등장·소멸한다', async ({ page, messagingManageToken }) => {
    await authenticatePage(page, messagingManageToken);
    await page.goto(SETTINGS_URL);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 행의 코어 [편집] → 같은 모달 안에 알림톡 템플릿·문자(SMS) 섹션이 주입된다(작성 폼은 draft/rejected/미작성 행에서 열린다)
    const editButton = page.getByRole('button', { name: '편집', exact: true }).first();
    await expect(editButton).toBeVisible({ timeout: 30_000 });
    await editButton.click();

    const dialog = page.getByRole('dialog');
    const modal = dialog.locator('#bizppurio_tpl_sections');
    await expect(modal).toBeVisible({ timeout: 15_000 });
    await expect(dialog.locator('#bizppurio_tpl_sms_section')).toBeVisible();
    // 코어 제목/본문 입력은 hidden_template_editor 게이트로 숨는다 — 본문 입력은 알림톡 섹션 하나뿐
    await expect(dialog.locator('#template_body_input')).toHaveCount(0);
    await expect(dialog.getByRole('button', { name: '저장', exact: true })).toHaveCount(1);

    // 본문 textarea 는 상시
    await expect(modal.locator('textarea[name="bz_template_content"]')).toBeVisible();

    // 검수자 전달 의견 입력란은 바로연결 블록 아래 한 줄을 통째로 차지한다
    // (회귀: 라벨+[바로연결 추가] flex 행 안에 끼어 라벨이 세로로 찌그러짐 — 화면 검수 지적 2026-08-23)
    const commentBox = modal.locator('textarea[name="bz_request_comment"]');
    await expect(commentBox).toBeVisible();
    const [quickAddBox, commentBounds, bodyBox] = await Promise.all([
      modal.getByRole('button', { name: '바로연결 추가' }).boundingBox(),
      commentBox.boundingBox(),
      modal.locator('textarea[name="bz_template_content"]').boundingBox(),
    ]);
    expect(commentBounds!.y, '의견란은 [바로연결 추가] 아래에 있어야 한다').toBeGreaterThanOrEqual(quickAddBox!.y + quickAddBox!.height);
    expect(Math.abs(commentBounds!.x - bodyBox!.x), '의견란은 본문 입력과 같은 왼쪽 선에 맞는다').toBeLessThan(4);

    // 없음(NONE): 강조표기·이미지·아이템 필드 부재
    await expect(modal.getByText('강조표기 타이틀', { exact: false })).toHaveCount(0);

    // 강조표기(TEXT): 타이틀·서브타이틀 등장
    await modal.getByRole('button', { name: '강조표기', exact: true }).click();
    await expect(modal.getByText('강조표기 타이틀', { exact: false }).first()).toBeVisible();

    // 이미지(IMAGE): 파일 입력 등장, TEXT 필드 소멸
    await modal.getByRole('button', { name: '이미지', exact: true }).click();
    await expect(modal.locator('input[type="file"]')).toBeVisible();
    await expect(modal.getByText('강조표기 타이틀', { exact: false })).toHaveCount(0);

    // 아이템리스트(ITEM_LIST): 아이템 추가 버튼 등장
    await modal.getByRole('button', { name: '아이템리스트', exact: true }).click();
    await expect(modal.getByRole('button', { name: '아이템 추가' })).toBeVisible();
    await expect(modal.locator('input[type="file"]')).toHaveCount(0);

    // 버튼 추가 — 1행 추가 후 linkType 셀렉트·이름 입력 등장
    await modal.getByRole('button', { name: '버튼 추가' }).click();
    await expect(modal.getByPlaceholder('버튼명', { exact: false }).first()).toBeVisible();
  });
});

test.describe('비즈뿌리오 알림 템플릿 관리 (플러그인 설정)', () => {
  // @scenario bizppurio_manage_round_trip_e2e
  // @effects manage_screen_lists_db_rows_with_merge_query_round_trip
  test('상태 필터가 URL 에 보존되고 목록이 렌더된다 (mergeQuery 왕복)', async ({ page, messagingManageToken }) => {
    await authenticatePage(page, messagingManageToken);
    await page.goto(MANAGE_URL);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    const manageView = page.locator('#templates_manage_view');
    const readiness = page.locator('#templates_readiness');

    // 자격증명 미설정 환경이면 readiness 안내가 정상 경로다 — 관리 뷰 검증은 ready 환경에서만.
    if (await readiness.isVisible().catch(() => false)) {
      await expect(readiness.getByText('환경설정', { exact: false }).first()).toBeVisible();
      return;
    }

    await expect(manageView).toBeVisible({ timeout: 30_000 });

    // 목록 SMS 열은 평문(단독/대체/-)이 아니라 상태 배지(단독·대체=초록, 미사용=회색) — 행 하단과 같은 규칙 (제품 결정 2026-08-23)
    await expect(manageView.locator('span.rounded', { hasText: /^(단독|대체|미사용)$/ }).first()).toBeVisible({ timeout: 15_000 });

    // 상태 필터 변경 → URL bz_status 반영 (mergeQuery — tab 파라미터 유지)
    const filterRoot = manageView.locator('[aria-haspopup]').first();
    await filterRoot.click();
    await page.getByRole('option', { name: '승인됨' }).click();

    await expect(page).toHaveURL(/bz_status=approved/);
    await expect(page).toHaveURL(/tab=templates/);

    // i18n 원문 노출 0
    const text = await manageView.innerText();
    expect(text).not.toContain('$t:');
    expect(text).not.toContain('sirsoft-message_bizppurio.');
  });
  // @scenario bizppurio_manage_round_trip_e2e
  // @effects manage_list_mobile_card_view_and_unified_toolbar
  test('portable(375px) 에서 목록이 카드로 전환되고 도구줄 버튼이 btn 계열로 통일된다', async ({ page, messagingManageToken }) => {
    await authenticatePage(page, messagingManageToken);
    await page.goto(MANAGE_URL);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    const manageView = page.locator('#templates_manage_view');
    const readiness = page.locator('#templates_readiness');
    if (await readiness.isVisible().catch(() => false)) {
      await expect(readiness.getByText('환경설정', { exact: false }).first()).toBeVisible();
      return;
    }
    await expect(manageView).toBeVisible({ timeout: 30_000 });

    // 데스크톱: grid 헤더 노출 + 도구줄 버튼 계열
    await expect(manageView.locator('#bz_manage_list_header')).toBeVisible();
    const searchBtn = manageView.getByRole('button', { name: '검색', exact: true });
    await expect(searchBtn).toHaveClass(/btn-primary/);
    // 행 액션에도 동명 [새로고침](행 동기화) 버튼이 있어 aria-label 로 도구줄 버튼만 집는다
    const refreshBtn = manageView.getByLabel('새로고침', { exact: true });
    await expect(refreshBtn).toHaveClass(/btn-icon/);
    await expect(refreshBtn.locator('i.fa-arrows-rotate')).toBeVisible();

    // portable 로 전환 → 헤더 숨김 + 카드 메타 줄(신청·동기화 합본) 노출
    await page.setViewportSize({ width: 375, height: 812 });
    await expect(manageView.locator('#bz_manage_list_header')).toBeHidden();
    const firstRowInner = manageView.locator('#bz_manage_list_rows > div > div').first();
    if (await firstRowInner.isVisible().catch(() => false)) {
      await expect(firstRowInner.getByText(/동기화/).first()).toBeVisible();
      // 카드에서 별도 열(소속/신청일/동기화일 셀)은 숨는다 — grid 클래스가 아닌 flex 컨테이너
      const rowClass = (await firstRowInner.getAttribute('class')) ?? '';
      expect(rowClass).toContain('flex-wrap');
      expect(rowClass).not.toContain('grid-cols-12');
    }
    await page.setViewportSize({ width: 1280, height: 800 });
  });

});

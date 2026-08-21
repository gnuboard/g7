/**
 * E2E: 비즈뿌리오 알림 템플릿 라이프사이클 화면 (#597)
 *
 * @scenario bizppurio_tab_integration_e2e, bizppurio_compose_modal_e2e, bizppurio_manage_round_trip_e2e
 * @effects bizppurio_tab_replaces_sms_and_alimtalk_tabs, row_footer_shows_status_badge_and_lifecycle_actions,
 *          compose_modal_switches_conditional_fields_by_type, manage_screen_lists_db_rows_with_merge_query_round_trip
 *
 * 검증 대상(브라우저에서만 잡히는 축):
 *  1. 코어 알림 설정 서브탭 통합 — '비즈뿌리오' 단일 탭 노출, 문자·알림톡 개별 탭 부재
 *     (탭 필터 표현식 3면 수정의 소비처 실렌더)
 *  2. 행 하단 라이프사이클 UI — 상태 배지 + 작성 버튼 + SMS 체크박스 렌더, i18n 원문 노출 0
 *  3. 작성 모달 — 강조 유형 전환(없음→강조표기→이미지→아이템리스트) 시 조건부 필드 등장/소멸
 *  4. 플러그인 관리 화면 — 상태 필터가 URL(bz_status)에 보존되는 목록 왕복(mergeQuery)
 *
 * 카카오 API(kapi) 실호출이 필요한 검수 신청·동기화 경로는 브라우저 E2E 범위 밖이다
 * (자격증명·실검수 필요 — PHPUnit 이 Http::fake 로 전수 가드, 실연동은 PO 수행 단계).
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
  // @effects row_footer_shows_status_badge_and_lifecycle_actions
  test('알림 행 하단에 알림톡 라이프사이클 줄과 SMS 설정 줄이 렌더된다', async ({ page, messagingManageToken }) => {
    await authenticatePage(page, messagingManageToken);
    await page.goto(SETTINGS_URL);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    const rows = page.locator('[id^="bizppurio_row_lifecycle"]');
    await expect(rows.first()).toBeVisible({ timeout: 30_000 });

    // 알림톡 라벨 + 상태 배지(미작성/작성중/…) + SMS 체크박스 라벨
    const firstRow = rows.first();
    await expect(firstRow.getByText('알림톡', { exact: false }).first()).toBeVisible();
    await expect(firstRow.getByText('대체 SMS', { exact: false }).first()).toBeVisible();
    await expect(firstRow.getByText('SMS 단독', { exact: false }).first()).toBeVisible();

    // i18n 원문 키 노출 0 (탭 콘텐츠 영역 전체)
    const content = await page.locator('#notif_channel_content').innerText();
    expect(content).not.toContain('$t:');
    expect(content).not.toContain('sirsoft-message_bizppurio.');
  });

  // @scenario bizppurio_compose_modal_e2e
  // @effects compose_modal_switches_conditional_fields_by_type
  test('작성 모달의 강조 유형 전환 시 조건부 필드가 등장·소멸한다', async ({ page, messagingManageToken }) => {
    await authenticatePage(page, messagingManageToken);
    await page.goto(SETTINGS_URL);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 미작성 행의 [알림톡 템플릿 작성] → 모달 오픈
    const composeButton = page.getByRole('button', { name: '알림톡 템플릿 작성' }).first();
    await expect(composeButton).toBeVisible({ timeout: 30_000 });
    await composeButton.click();

    const modal = page.locator('[id^="bizppurio_tpl_modal_body"]').first();
    await expect(modal).toBeVisible({ timeout: 15_000 });

    // 본문 textarea 는 상시
    await expect(modal.locator('textarea[name="bz_template_content"]')).toBeVisible();

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
});

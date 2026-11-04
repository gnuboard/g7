/**
 * 운영 안내 — 등록 화면의 미리보기 위치·저장 잠김 이유·버튼과 링크 안내, 목록의 제작 상태·사이트에서 보기·빈 상태 구분.
 * @scenario-manifest tests/scenarios/operator-guidance.yaml
 * @effects accessible_names_present
 */
import { test, expect, authenticatePage, createBanner, ADMIN_LIST, uniqueTitle, VIEWPORTS } from '../fixtures/hero-auth';

test.setTimeout(90_000);

// @scenario surface=form_preview_first_on_mobile
// @scenario surface=form_save_blocked_reason
// @scenario surface=form_button_needs_link_warning
// @scenario surface=form_link_behavior_summary
test('휴대폰 등록 화면은 미리보기가 입력 위에 있고, 저장 잠김 이유와 버튼·링크 안내를 보인다', async ({ page, managerToken }) => {
  await authenticatePage(page, managerToken);
  await page.setViewportSize(VIEWPORTS.mobile);
  await page.goto(`${ADMIN_LIST}/create?position=home_hero`);
  const save = page.locator('#footer_save_button');
  await expect(save).toBeDisabled({ timeout: 30_000 });

  // 미리보기가 기본 정보 카드보다 위, 이미지 카드가 문구 카드보다 위
  const top = async (selector: string) => (await page.locator(selector).boundingBox())!.y;
  expect(await top('#hero_form_preview')).toBeLessThan(await top('#basic_card'));
  expect(await top('#image_card')).toBeLessThan(await top('#text_card'));

  // 잠김 이유는 저장 단추의 설명이고, 누르면 PC 이미지 칸으로 간다
  const reason = page.locator('#hero_save_reason');
  await expect(reason).toContainText('PC 이미지를 올려야 저장할 수 있습니다.');
  await expect(save).toHaveAttribute('aria-describedby', 'hero_save_reason');
  await reason.getByRole('button', { name: 'PC 이미지 칸으로 가기' }).click();
  await expect(page.locator('#hero_field_pc_image')).toBeInViewport();

  // 버튼 문구만 넣으면 경고와 「이동하지 않음」, 주소를 넣으면 경고가 사라지고 「버튼을 누르면 이동」
  const warning = page.getByText('이동할 주소를 입력해야 버튼이 사이트에 보입니다.', { exact: false });
  await expect(page.getByText('이동할 주소가 없어 사이트에서 배너를 눌러도 이동하지 않습니다.')).toBeVisible();
  await page.locator('input[name="button_text[ko]"]').fill('보러 가기');
  await expect(warning).toBeVisible();
  await page.locator('#hero_link_url').fill('/shop');
  await expect(warning).toHaveCount(0);
  await expect(page.getByText('사이트에서는 버튼을 누르면 이 주소로 이동합니다.')).toBeVisible();
  await page.locator('input[name="button_text[ko]"]').fill('');
  await expect(page.getByText('버튼 문구가 없어 사이트에서는 배너 전체를 누르면 이 주소로 이동합니다.')).toBeVisible();
});

// @scenario surface=list_row_readiness_flags
// @scenario surface=list_site_link_and_missing_slot
// @scenario surface=list_empty_states_distinguished
test('목록은 행마다 제작 상태를 보이고, 사이트 주소로 가는 링크와 조건 결과 0건 안내를 구분한다', async ({ page, request, managerToken, created }) => {
  const title = uniqueTitle('운영안내');
  created.push(await createBanner(request, managerToken, { is_active: false, title: { ko: title }, button_text: { ko: '보기' }, link_url: null }));
  await authenticatePage(page, managerToken);
  await page.setViewportSize(VIEWPORTS.desktop);
  await page.goto(`${ADMIN_LIST}?position=home_hero&search=${encodeURIComponent(title)}`);

  const row = page.locator('tr', { hasText: title });
  await expect(row).toBeVisible({ timeout: 30_000 });
  await expect(row.getByText('모바일: PC 이미지 사용')).toBeVisible();
  await expect(row.getByText('버튼 안 보임: 주소 없음')).toBeVisible();
  // 한국어 제목만 넣었으니 다른 사이트 언어는 「제목 없음」 — 언어 탭과 같은 언어 이름으로 보인다(코드 대문자 「EN」 이 아니다)
  await expect(row.getByText('English 제목 없음', { exact: true })).toBeVisible();

  // 홈 자리는 기본 사용자 템플릿의 「/」 에 있다 — 새 창 링크
  const site = page.getByRole('link', { name: /사이트에서 보기/ });
  await expect(site).toHaveAttribute('href', '/');
  await expect(site).toHaveAttribute('target', '_blank');
  await expect(page.locator('#slot_missing_notice')).toHaveCount(0);

  // 이 위치에 배너가 있는데 검색 결과만 0건이면 첫 등록 카드가 아니라 조건 안내
  await page.goto(`${ADMIN_LIST}?position=home_hero&search=${encodeURIComponent(`${title}-없음`)}`);
  await expect(page.getByText('조건에 맞는 배너가 없습니다.', { exact: false })).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#empty_position_card')).toHaveCount(0);
});

// @scenario surface=list_empty_states_distinguished
test('위치에 배너가 하나도 없으면 첫 등록 카드에 권장 크기와 등록 링크를 보인다', async ({ page, managerToken }) => {
  await authenticatePage(page, managerToken);
  await page.setViewportSize(VIEWPORTS.desktop);
  // 운영 데이터에 기대지 않게 쇼핑몰 위치 목록만 빈 응답으로 바꾼다
  await page.route((url) => url.pathname.endsWith('/admin/banners') && url.searchParams.get('position') === 'shop_hero', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: {
          data: [],
          pagination: { total: 0, current_page: 1, last_page: 1, per_page: 20, has_more_pages: false },
          statistics: { positions: { home_hero: 1, shop_hero: 0 }, statuses: { active: 0, scheduled: 0, ended: 0, inactive: 0 } },
          abilities: { can_create: true, can_update: true, can_delete: true },
        },
      }),
    });
  });
  await page.goto(`${ADMIN_LIST}?position=shop_hero`);
  const card = page.locator('#empty_position_card');
  await expect(card).toBeVisible({ timeout: 30_000 });
  await expect(card).toContainText('PC 1920×640');
  await expect(card.getByRole('link', { name: '첫 배너 등록' })).toHaveAttribute('href', /\/banners\/create\?position=shop_hero/);
  await expect(page.getByText('조건에 맞는 배너가 없습니다.', { exact: false })).toHaveCount(0);
});

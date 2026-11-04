/**
 * E2E: 템플릿 환경설정 공용 자동 화면 → 사용자 화면 반영 → 기본값으로 (#434 §12.7)
 *
 * 배경: 번들 템플릿은 설정 화면을 직접 쓰지 않고 선언만 둔다 — 켜진 관리자 템플릿의 공용 화면이 응답 sections 로 칸을 그린다.
 * 저장한 값은 사용자 화면 레이아웃 바인딩(`_global.templateSettings`)과 서버 `<html>` 표준 속성으로 나가므로,
 * 단위 시험(레이아웃 JSON·합성 컴포넌트)은 「저장 → 다음 문서에서 보인다 → 되돌리면 사라진다」를 잇지 못한다.
 *
 * 검증 (sirsoft-basic, 켜진 사용자 템플릿):
 *  1. 공용 화면 푸터 탭에서 「Powered by」 스위치를 끄고 저장하면 지금 탭 섹션(footer)만 PUT 된다
 *  2. 포인트 색·헤더 바로가기·푸터 소셜을 저장하면 사용자 화면 `/` 에 `<html data-g7-accent>`·헤더 링크·푸터 소셜이 나가고 Powered by 가 사라진다
 *  3. 공용 화면 「기본값으로」 → 확인 창 → POST reset 뒤 `/` 가 기본값(파랑·링크 없음·Powered by 보임)으로 돌아온다
 *
 * 시험이 바꾼 값은 3 의 되돌리기로 전부 지운다(첨부 칸은 건드리지 않는다).
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

const TEMPLATE = 'sirsoft-basic';
const SETTINGS_API = `/api/admin/templates/${TEMPLATE}/settings`;

// @scenario case=settings_layout
// @effects settings_layout_saves_template_settings, template_settings_exposed_to_global, standard_attributes_rendered, settings_reset_to_defaults
test('#434 - 공용 화면에서 저장한 값이 사용자 화면에 나가고 「기본값으로」 로 원상이 된다', async ({ page, request }) => {
  test.setTimeout(240_000);
  const token = issueToken('core.templates.read', 'core.templates.settings');
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };
  await authenticatePage(page, token);

  // 1. 공용 화면 — 푸터 탭에서 스위치를 끄고 저장(지금 탭 섹션만 보낸다)
  await page.goto(`/admin/templates/${TEMPLATE}/settings?tab=footer`);
  const poweredBy = page.locator('#template_settings_field_footer_show_powered_by');
  await expect(poweredBy).toBeVisible({ timeout: 30_000 });
  await poweredBy.locator('[role="switch"], input[type="checkbox"], button').first().click();

  const putRequest = page.waitForRequest((r) => r.url().endsWith(SETTINGS_API) && r.method() === 'PUT');
  const putResponse = page.waitForResponse((r) => r.url().endsWith(SETTINGS_API) && r.request().method() === 'PUT');
  await page.locator('#save_button').click();
  expect(Object.keys((await putRequest).postDataJSON())).toEqual(['footer']);
  expect((await putResponse).status()).toBe(200);

  // 2. 다른 섹션 값은 같은 저장 API 로 — 포인트 색·헤더 바로가기·푸터 소셜
  const saved = await request.put(SETTINGS_API, {
    headers,
    data: {
      branding: { accent: 'green' },
      header: { links: [{ label: { ko: '공지434', en: 'Notice434' }, url: '/board/notice', new_tab: false }] },
      footer: { social_youtube: 'https://www.youtube.com/@g7-434' },
    },
  });
  expect(saved.status()).toBe(200);

  await page.goto('/');
  await expect(page.locator('html')).toHaveAttribute('data-g7-accent', 'green');
  await expect(page.getByTestId('nav-extra-link').filter({ hasText: '공지434' }).first()).toHaveAttribute('href', /\/board\/notice$/);
  const youtube = page.getByTestId('footer-social-links').locator('a[href="https://www.youtube.com/@g7-434"]');
  await expect(youtube).toHaveCount(1);
  await expect(youtube).toHaveAttribute('rel', 'noopener noreferrer');
  await expect(page.getByTestId('footer-powered-by')).toHaveCount(0);

  // 3. 「기본값으로」 → 확인 창 → POST reset
  await page.goto(`/admin/templates/${TEMPLATE}/settings`);
  await expect(page.locator('#reset_button')).toBeVisible({ timeout: 30_000 });
  await page.locator('#reset_button').click();
  const resetResponse = page.waitForResponse((r) => r.url().endsWith(`${SETTINGS_API}/reset`) && r.request().method() === 'POST');
  await page.locator('#template_settings_reset_confirm_button').click();
  expect((await resetResponse).status()).toBe(200);

  await page.goto('/');
  await expect(page.locator('html')).toHaveAttribute('data-g7-accent', 'blue');
  await expect(page.getByTestId('nav-extra-link').filter({ hasText: '공지434' })).toHaveCount(0);
  await expect(page.getByTestId('footer-social-links').locator('a[href="https://www.youtube.com/@g7-434"]')).toHaveCount(0);
  await expect(page.getByTestId('footer-powered-by')).toBeVisible();

  // 저장 파일이 남지 않았는지 — 되돌린 뒤 조회 응답은 선언 기본값이다
  const after = await (await request.get(SETTINGS_API, { headers })).json();
  expect(after.data.settings.branding.accent).toBe('blue');
  expect(after.data.settings.header.links).toEqual([]);
  expect(after.data.settings.footer.show_powered_by).toBe(true);
});

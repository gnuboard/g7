/**
 * 쇼핑몰 설정 '리뷰 설정' 탭 — 작성자 표시 이름의 「지금 적용」 안내
 *
 * 상품 후기 영역(`sirsoft-ecommerce:review`)은 처음에 상위 영역을 따르고, 코어 사이트 기본의 처음 방식은 「회원이 고른 값」이다.
 * 그때는 적용 종류가 없어(`kind_label` null) 예전 화면은 「지금 적용」 안내를 아예 그리지 않았다. 안내가 방식 이름으로 서는지와,
 * 다른 탭으로 SPA 이동했다가 돌아와도 같은 글자인지를 실제 브라우저로 본다. 설정은 읽기만 한다(쓰기 없음).
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const SETTINGS_URL = '/admin/ecommerce/settings?tab=review_settings';
const SETTINGS_API = '/api/modules/sirsoft-ecommerce/admin/settings';

test.describe.configure({ mode: 'serial' });

test.describe('리뷰 설정 탭 작성자 표시 이름 안내', () => {
  /**
   * @scenario case=mode_inherit_member_e2e
   * @effects effective_line_names_member_choice_in_browser, effective_line_survives_tab_switch
   */
  test('상위 영역을 따르는 후기 칸이 지금 적용되는 방식(또는 종류)과 그 출처를 알리고, 탭을 오가도 그대로다', async ({ page, request, settingsToken }) => {
    test.setTimeout(120_000);

    const res = await request.get(SETTINGS_API, { headers: { Authorization: `Bearer ${settingsToken}`, Accept: 'application/json' } });
    expect(res.status()).toBe(200);
    const data = (await res.json())?.data ?? {};
    const stored = data.display_name?.review?.mode ?? 'inherit';
    const effective = data.display_name_options?.effective?.review ?? {};
    test.skip(stored !== 'inherit', '이 사이트는 후기 표시 이름을 직접 정해 두어 「지금 적용」 안내 대상이 아니다');

    // 서버가 준 지금 값으로 기대 글자를 만든다 — 「회원이 고른 값」이면 종류 대신 방식 이름
    const kind = effective.mode === 'member' ? '회원이 고른 값' : String(effective.kind_label ?? '');
    const expected = effective.source_label ? `지금 적용: ${kind} (${effective.source_label} 설정 기준)` : `지금 적용: ${kind}`;
    expect(kind, '지금 적용되는 방식이나 종류가 있다').not.toBe('');

    await authenticatePage(page, settingsToken);
    await page.goto(SETTINGS_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const line = page.locator('#review_display_name_effective');
    await expect(line).toHaveText(expected, { timeout: 30_000 });

    // 다른 탭으로 SPA 이동했다가 돌아와도 같은 글자다
    await page.locator('a[href*="tab=basic_info"]').first().click();
    await expect(page).toHaveURL(/tab=basic_info/, { timeout: 20_000 });
    await expect(line).toHaveCount(0);
    await page.locator('a[href*="tab=review_settings"]').first().click();
    await expect(page).toHaveURL(/tab=review_settings/, { timeout: 20_000 });
    await expect(line).toHaveText(expected, { timeout: 30_000 });
  });
});

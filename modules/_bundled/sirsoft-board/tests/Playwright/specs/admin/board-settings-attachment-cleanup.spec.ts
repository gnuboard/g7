/**
 * E2E: 게시판 환경설정 > 기본 설정 — "삭제 첨부 영구 정리" (공개 #115)
 *
 * @scenario board-settings-attachment-cleanup
 * @effects board_settings_purge_toggle_default_off, board_settings_purge_keys_declared
 *
 * 배경: 삭제한 첨부의 파일을 보존기간 경과 후 완전히 파기하는 운영자 스위치다. 저장 payload
 * 빌더가 이 카테고리를 싣지 않아 "저장 200 인데 값이 사라지던" 결함이 있었고, 그 증상은
 * 응답이 정상이라 화면만 봐서는 드러나지 않았다. 단위 테스트는 레이아웃 JSON 의 바인딩만
 * 보므로, 실제 요청 본문에 카테고리가 실리는지는 브라우저로만 확인된다.
 *
 * 검증:
 *  1. 기본 설정 탭에 토글·보존기간 필드가 번역문과 함께 마운트된다
 *  2. 토글은 기본 꺼짐으로 렌더된다 (사용자 파일을 지우는 기능의 인수 기준)
 *  3. 값을 바꿔 저장하면 요청 본문에 `attachment_settings` 가 실제로 실린다
 *
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

// 탭 판정은 `_global.activeBoardSettingsTab || query.tab || 'general'` 이다.
// 다른 값을 실으면 탭 콘텐츠 자체가 렌더되지 않아 섹션을 찾을 수 없다.
const SETTINGS_URL = '/admin/boards/settings?tab=general';

const SECTION = '#attachment_cleanup_section';
const TOGGLE_ROW = '#attachment_purge_enabled_field';
const RETENTION_ROW = '#attachment_purge_retention_days_field';

/** 설정 저장 PUT 인지 판정한다. */
const isSettingsPut = (url: string, method: string) => url.includes('/settings') && method === 'PUT';

/**
 * 보존기간 값을 바꿔 저장하고, 요청 본문을 돌려준다.
 *
 * 응답 상태까지 확인한다 — 요청만 관찰하면 서버가 4xx 로 거부해도 단언이 통과해
 * "저장됐다" 는 잘못된 결론이 남는다.
 *
 * @param page 대상 페이지
 * @param input 보존기간 입력
 * @param saveButton 저장 버튼
 * @param value 채워 넣을 값
 * @returns 저장 요청 본문
 */
async function save(
  page: import('@playwright/test').Page,
  input: import('@playwright/test').Locator,
  saveButton: import('@playwright/test').Locator,
  value: string,
): Promise<Record<string, unknown>> {
  await input.fill(value);
  await expect(saveButton).toBeEnabled({ timeout: 20_000 });

  const requestPromise = page.waitForRequest(
    (req) => isSettingsPut(req.url(), req.method()),
    { timeout: 20_000 },
  );
  const responsePromise = page.waitForResponse(
    (res) => isSettingsPut(res.url(), res.request().method()),
    { timeout: 20_000 },
  );

  await saveButton.click();

  const payload = (await requestPromise).postDataJSON() as Record<string, unknown>;
  expect((await responsePromise).status()).toBe(200);

  return payload;
}

test.describe('게시판 환경설정 — 삭제 첨부 영구 정리 (#115)', () => {
  test('토글·보존기간 필드가 번역문과 함께 마운트되고 기본 꺼짐이다', async ({
    page,
    settingsToken,
  }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(SETTINGS_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await expect(page.locator(SECTION)).toBeAttached({ timeout: 20_000 });
    await expect(page.locator(TOGGLE_ROW)).toBeAttached();
    await expect(page.locator(RETENTION_ROW)).toBeAttached();

    const text = (await page.locator(SECTION).innerText()).trim();
    expect(text).not.toContain('$t:');
    expect(text).not.toContain('purge_enabled');

    const toggle = page.locator(TOGGLE_ROW).locator('input[type="checkbox"]').first();
    expect(await toggle.isChecked()).toBe(false);
  });

  test('저장 요청 본문에 attachment_settings 카테고리가 실린다', async ({
    page,
    settingsToken,
  }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(SETTINGS_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await expect(page.locator(RETENTION_ROW)).toBeAttached({ timeout: 20_000 });

    const input = page.locator(RETENTION_ROW).locator('input').first();
    const saveButton = page.locator('#footer_save_button');
    const original = (await input.inputValue()) || '30';

    // 현재값을 그대로 채우면 hasChanges 가 서지 않아 저장 버튼이 비활성인 채로 남고,
    // PUT 이 영원히 오지 않아 테스트가 타임아웃한다. 반드시 다른 값으로 바꾼다.
    const target = original === '15' ? '20' : '15';

    const payload = await save(page, input, saveButton, target);

    // 카테고리 자체가 빠지면 서버는 200 을 돌려주고 값만 조용히 사라진다.
    expect(payload).toHaveProperty('attachment_settings');

    // number 입력의 값은 와이어에 문자열로 실리고(서버가 정수로 캐스팅한다) —
    // 여기서 검증할 것은 표현 타입이 아니라 "그 값이 실제로 실렸는가" 다.
    expect(
      Number((payload.attachment_settings as Record<string, unknown>).purge_retention_days),
    ).toBe(Number(target));

    // 요청 본문만 보면 서버가 그 값을 버려도 통과한다. 새로고침 후 값이 살아 있어야
    // "실제로 저장됐다" 가 증명된다 (F4 가 정확히 이 지점에서 200 인 채 값을 잃었다).
    await page.reload();
    await expect(input).toHaveValue(target, { timeout: 20_000 });

    // 검증 때문에 운영 설정이 바뀐 채로 남지 않도록 원래 값으로 되돌린다.
    await save(page, input, saveButton, original);
    await page.reload();
    await expect(input).toHaveValue(original, { timeout: 20_000 });
  });
});

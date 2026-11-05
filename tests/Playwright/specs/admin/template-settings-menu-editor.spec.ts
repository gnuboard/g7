/**
 * E2E: 템플릿 설정 메뉴 편집기 — 끌어서 놓아 짠 2단 메뉴가 사용자 화면 헤더 펼침 메뉴로 나간다 (#434 §12.14)
 *
 * 배경: 편집기의 끌어서 놓기(dnd-kit 포인터 끌기 → 가로 거리로 깊이 결정)와 저장 → 사용자 화면 헤더는 단위 시험이 잇지 못한다.
 *
 * 검증 (sirsoft-basic, 켜진 사용자 템플릿):
 *  1. 헤더 탭 메뉴 칸은 기본 메뉴 4줄(홈·인기글·쇼핑·게시판 전체)로 시작한다
 *  2. 「링크 추가」로 상위 메뉴, 「하위 메뉴 추가」로 그 하위 메뉴를 만들고, 또 하나의 링크를 손잡이로 오른쪽으로 끌면 하위 메뉴가 된다
 *  3. 저장하면 헤더 섹션만 PUT 되고, 사용자 화면 `/` 의 그 상위 메뉴를 누르면 펼침 메뉴에 하위 링크 둘이 나온다
 *
 * 시험이 바꾼 값은 끝에서(실패해도) 기본값 되돌리기로 지운다.
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

const TEMPLATE = 'sirsoft-basic';
const SETTINGS_API = `/api/admin/templates/${TEMPLATE}/settings`;

// @scenario case=menu_editor
// @effects menu_editor_add_edit, menu_saved_normalized, header_menu_renders_tree
test('#434 - 메뉴 편집기에서 끌어서 놓아 짠 2단 메뉴가 사용자 화면 헤더 펼침 메뉴로 나간다', async ({ page, request }) => {
  test.setTimeout(240_000);
  const token = issueToken('core.templates.read', 'core.templates.settings');
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };
  await authenticatePage(page, token);

  try {
    // 1. 헤더 탭 — 기본 메뉴 4줄
    await page.goto(`/admin/templates/${TEMPLATE}/settings?tab=header`);
    const field = page.locator('#template_settings_field_header_menu');
    await expect(field).toBeVisible({ timeout: 30_000 });
    const rows = field.getByTestId('template-menu-row');
    await expect(rows).toHaveCount(4);

    const editor = field.getByTestId('template-menu-editor');
    const nameInput = () => editor.locator('input[type="text"]:not([data-testid="template-menu-url"])').first();

    // 2-1. 상위 메뉴(주소 없음)
    await field.getByTestId('template-menu-add-link').click();
    await nameInput().fill('커뮤니티434');

    // 2-2. 그 하위 메뉴
    await editor.getByTestId('template-menu-add-child').click();
    await nameInput().fill('공지434');
    await editor.getByTestId('template-menu-url').fill('/board/notice');

    // 2-3. 상위 링크 하나 더 → 손잡이를 오른쪽으로 끌어 하위로
    await field.getByTestId('template-menu-add-link').click();
    await nameInput().fill('자유434');
    await editor.getByTestId('template-menu-url').fill('/board/free');
    await expect(rows).toHaveCount(7);

    const freeRow = rows.filter({ hasText: '자유434' });
    await expect(freeRow).toHaveAttribute('data-depth', '0');
    const handle = freeRow.getByLabel(/자유434/);
    // 화면 아래쪽 줄은 하단 고정 저장 바에 가려 끌 수 없다 — 사람처럼 가운데로 스크롤한 뒤 끈다
    await handle.evaluate((el) => el.scrollIntoView({ block: 'center' }));
    const box = (await handle.boundingBox())!;
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.down();
    for (let step = 1; step <= 8; step++) {
      await page.mouse.move(box.x + box.width / 2 + step * 6, box.y + box.height / 2 + 1);
    }
    await page.mouse.up();
    await expect(freeRow).toHaveAttribute('data-depth', '1');

    // 3. 저장 — 헤더 섹션만, 상위 메뉴 아래 하위 둘
    const putRequest = page.waitForRequest((r) => r.url().endsWith(SETTINGS_API) && r.method() === 'PUT');
    const putResponse = page.waitForResponse((r) => r.url().endsWith(SETTINGS_API) && r.request().method() === 'PUT');
    await page.locator('#save_button').click();
    const body = (await putRequest).postDataJSON();
    expect(Object.keys(body)).toEqual(['header']);
    const community = body.header.menu.find((item: any) => item.label?.ko === '커뮤니티434');
    expect(community.children.map((child: any) => child.label.ko)).toEqual(['공지434', '자유434']);
    expect((await putResponse).status()).toBe(200);

    await page.goto('/');
    const parent = page.getByTestId('nav-menu-parent').filter({ hasText: '커뮤니티434' });
    await expect(parent).toBeVisible({ timeout: 30_000 });
    await expect(parent).toHaveAttribute('aria-expanded', 'false');
    await parent.click();
    await expect(parent).toHaveAttribute('aria-expanded', 'true');
    const dropdown = page.getByTestId('nav-menu-dropdown');
    await expect(dropdown.getByRole('link', { name: '공지434' })).toHaveAttribute('href', /\/board\/notice$/);
    await expect(dropdown.getByRole('link', { name: '자유434' })).toHaveAttribute('href', /\/board\/free$/);
    await page.keyboard.press('Escape');
    await expect(dropdown).toHaveCount(0);
  } finally {
    const reset = await request.post(`${SETTINGS_API}/reset`, { headers, data: { section: 'header' } });
    expect(reset.status()).toBe(200);
  }
});

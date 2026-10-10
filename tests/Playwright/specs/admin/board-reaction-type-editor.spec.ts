/**
 * 반응 유형 인라인 편집기 (#525 WP-A).
 *
 * 실브라우저로 세 갈래를 잰다:
 *   ① 유형을 추가하면 **저장 전에도** 위 체크박스 목록에 선다 — 같은 폼 배열을 보기 때문
 *   ② 코드가 비었거나 겹치면 인라인 오류가 뜨고 배열은 그대로다
 *   ③ 반응이 쌓인 유형은 삭제가 잠기고, 그 사유를 래퍼 툴팁이 말한다
 *
 * ① 이 이 화면의 핵심이다. 데이터소스를 보면 방금 추가한 유형을 저장 전에 고를 수 없고,
 * 지운 유형이 선택지에 남는다 — 레이아웃 계약 테스트는 «폼 배열을 순회한다» 까지만 말할 수
 * 있고, 추가가 실제로 체크박스에 닿는지는 여기서만 보인다.
 *
 * @scenario case=settings_editor_renders_form_rows
 * @scenario case=settings_editor_add_form_validates
 * @effects settings_editor_iterates_form_reaction_types_not_datasource,
 *          settings_editor_add_form_checks_required_and_duplicate,
 *          settings_editor_delete_is_confirmed_and_blocked_by_count
 */
import type { Page } from '@playwright/test';
import { test, expect, authenticatePage } from '../../fixtures/reaction-seed';

const SETTINGS_URL = '/admin/boards/settings?tab=basic_defaults';

/**
 * 게시판 환경설정 기본값 탭을 열고 반응 편집기가 설 때까지 기다린다.
 *
 * @param page 대상 페이지
 * @param token 관리자 토큰
 * @returns void
 */
async function openEditor(page: Page, token: string): Promise<void> {
  await authenticatePage(page, token);
  await page.goto(SETTINGS_URL);

  // 편집기는 「반응 사용」이 켜져 있을 때만 열린다 — 기본값이 켜짐이다.
  await expect(page.locator('[data-testid="reaction-type-add-btn"]')).toBeVisible({ timeout: 25_000 });
}

test.describe('반응 유형 편집기 (#525 WP-A)', () => {
  // @scenario case=settings_editor_renders_form_rows
  // @effects settings_editor_iterates_form_reaction_types_not_datasource
  test('등록된 유형이 행으로 서고 비활성 유형은 체크할 수 없다', async ({ page, reactionSeed, settingsToken }) => {
    await openEditor(page, settingsToken);

    // 시드가 만든 비활성 유형이 편집기 행으로 선다.
    const row = page.locator(`[data-testid="reaction-type-active-${reactionSeed.reaction_type_inactive_code}"]`);
    await expect(row).toBeVisible({ timeout: 20_000 });

    // 비활성 유형은 위 체크박스 목록에서 고를 수 없다 — 회원 화면에 어차피 나오지 않는다.
    const check = page.locator(`[data-testid="reaction-type-check-${reactionSeed.reaction_type_inactive_code}"]`);
    await expect(check).toBeDisabled();

    await expect(page.locator('body')).not.toContainText('$t:');
  });

  // @scenario case=settings_editor_add_form_validates
  // @effects settings_editor_add_form_checks_required_and_duplicate
  test('코드가 비면 인라인 오류가 뜨고 행이 늘지 않는다', async ({ page, settingsToken }) => {
    await openEditor(page, settingsToken);

    const before = await page.locator('[data-testid^="reaction-type-delete-wrap-"]').count();

    await page.locator('[data-testid="reaction-type-add-btn"]').click();
    await page.locator('[data-testid="reaction-type-form-confirm"]').click();

    // 배열은 건드리지 않고 어느 칸이 문제인지만 세운다.
    await expect(page.locator('body')).toContainText('코드를 입력하세요');
    await expect(page.locator('[data-testid^="reaction-type-delete-wrap-"]')).toHaveCount(before);
  });

  // @scenario case=settings_editor_add_form_validates
  // @effects settings_editor_add_form_checks_required_and_duplicate
  test('이미 있는 코드는 거절한다', async ({ page, settingsToken }) => {
    await openEditor(page, settingsToken);

    await page.locator('[data-testid="reaction-type-add-btn"]').click();
    await page.locator('[data-testid="reaction-type-form-code"]').fill('like');

    // 기본 언어 이름도 채워 「코드 중복」만 남긴다.
    const nameInput = page.locator('input').filter({ hasNot: page.locator('[data-testid]') }).last();
    await nameInput.fill('중복 시험').catch(() => undefined);

    await page.locator('[data-testid="reaction-type-form-confirm"]').click();

    // 코드로 반응 행과 게시판 설정이 이어지므로 겹치면 안 된다.
    await expect(page.locator('body')).toContainText('이미 있는 코드입니다');
  });

  // @scenario case=settings_editor_renders_form_rows
  // @effects settings_editor_add_form_checks_required_and_duplicate
  test('새 유형을 추가하면 저장 전에도 위 체크박스 목록에 선다', async ({ page, settingsToken }) => {
    await openEditor(page, settingsToken);

    const code = `e2e_new_${Date.now().toString().slice(-6)}`;

    await page.locator('[data-testid="reaction-type-add-btn"]').click();
    await page.locator('[data-testid="reaction-type-form-code"]').fill(code);

    // 기본 언어 이름 — 다국어 입력의 첫 칸이다.
    const nameBox = page.locator('[data-testid="reaction-type-form-code"]')
      .locator('xpath=../../..')
      .locator('input')
      .nth(1);
    await nameBox.fill('새 반응(E2E)').catch(() => undefined);

    await page.locator('[data-testid="reaction-type-form-confirm"]').click();

    // 데이터소스를 보면 저장 전에는 뜨지 않는다 — 같은 폼 배열을 보기 때문에 곧바로 선다.
    await expect(page.locator(`[data-testid="reaction-type-check-${code}"]`)).toBeVisible({ timeout: 10_000 });
    await expect(page.locator(`[data-testid="reaction-type-delete-${code}"]`)).toBeVisible();
  });

  // @scenario case=settings_editor_renders_form_rows
  // @effects settings_editor_delete_is_confirmed_and_blocked_by_count
  test('반응이 쌓인 유형은 삭제가 잠기고 사유를 래퍼가 말한다', async ({ page, reactionSeed, settingsToken }) => {
    await openEditor(page, settingsToken);

    // 시드가 like 로 여러 건(글 5 + 댓글 1 + 대조군)을 심었으므로 그 유형은 지울 수 없다.
    // `reactionSeed` 를 받지 않으면 시드가 서지 않아 반응 수가 0 이고 버튼이 열린다.
    expect(reactionSeed.reaction_ids.length).toBeGreaterThan(0);

    const deleteBtn = page.locator('[data-testid="reaction-type-delete-like"]');
    await expect(deleteBtn).toBeVisible({ timeout: 20_000 });
    await expect(deleteBtn).toBeDisabled({ timeout: 20_000 });

    // disabled 버튼은 hover 이벤트가 죽어 툴팁이 뜨지 않으므로 래퍼가 사유를 든다.
    const wrap = page.locator('[data-testid="reaction-type-delete-wrap-like"]');
    await expect(wrap).toHaveAttribute('title', /비활성화만 가능/);
  });
});

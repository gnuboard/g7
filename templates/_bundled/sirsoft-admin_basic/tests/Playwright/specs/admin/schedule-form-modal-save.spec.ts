/**
 * sirsoft-admin_basic — 스케줄 등록/수정 모달 저장 회귀.
 *
 * 결함: footer 저장 버튼이 `form="schedule_form_modal"` 로 폼과 연결되는데 Form 에 id 가
 *   없어 클릭이 무반응이었고(예외·콘솔 오류 없음), 커스텀 Select 는 defaultValue 를
 *   렌더하지 않아 작업 유형·실행 주기가 빈 값으로 표시되며 저장 요청에 실리지 않았다.
 *   Toggle 자동바인딩은 "on" 문자열을 보내 boolean 검증에서 422 가 됐다 — 세 결함이
 *   겹쳐 이 모달로는 스케줄을 한 건도 등록할 수 없었다. 전부 브라우저에서만 드러나는
 *   결함이라 단위 테스트(JSON 구조 단언)와 별개로 실제 저장 왕복을 고정한다.
 *
 * 7.2.0: 직접 등록은 표시 명칭(다국어)이 필수다 — 명칭 칸을 채우고, 기계 이름은 따로 준다.
 *   목록은 명칭을 주 표기로 그리므로 행 확인도 명칭으로 한다.
 *
 * 시나리오 매니페스트: schedule-form-modal-save (tests/scenarios — test_files 귀속)
 * @axes leg=create
 * @effects schedule_modal_save_creates_row
 */
import { test, expect } from '@playwright/test';
import { issueToken, authenticatePage } from '../../fixtures/admin-template-auth';

test.describe('@sirsoft-admin_basic 스케줄 폼 모달 저장', () => {
  // @scenario case=form_modal_save
  // @effects schedule_modal_save_creates_row
  test('등록 모달에서 저장하면 예약이 생성되어 목록에 나타난다 (원복 포함)', async ({ page }) => {
    const token = issueToken(
      'core.schedules.read',
      'core.schedules.create',
      'core.schedules.update',
      'core.schedules.delete'
    );
    await authenticatePage(page, token);
    await page.goto('/admin/schedules');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    const stamp = Date.now();
    const label = `E2E 스케줄 저장 회귀 ${stamp}`;
    const name = `e2e-schedule-${stamp}`;

    await page.getByRole('button', { name: /스케줄 등록/ }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    // 커스텀 Select 가 value 로 제어되어 기본값이 표시되어야 한다 (빈 표시 회귀).
    await expect(dialog.getByRole('button', { name: 'Artisan' })).toBeVisible();
    await expect(dialog.getByRole('button', { name: '매일' })).toBeVisible();

    // 명칭(다국어 입력) — placeholder 끝에 언어 이름이 붙는다(「… (한국어)」). 보이는 첫 칸이 기본 언어다.
    await dialog.getByPlaceholder(/작업명을 입력하세요/).first().fill(label);
    await dialog.locator('input[name="name"]').fill(name);
    await dialog.locator('textarea[name="command"]').fill('cache:clear');

    const createResponse = page.waitForResponse(
      (res) => res.url().includes('/api/admin/schedules') && res.request().method() === 'POST',
      { timeout: 30_000 }
    );
    await dialog.getByRole('button', { name: '저장' }).click();

    // 저장 버튼이 폼과 연결되지 않으면 POST 자체가 발생하지 않는다 (무반응 회귀).
    const response = await createResponse;
    expect(response.status(), '스케줄 생성 요청이 거절되었다').toBe(201);

    const created = (await response.json()) as { data: { id: number; label_localized?: string } };
    // 명칭이 다국어 객체로 저장되어 목록 주 표기(label_localized)로 돌아온다
    expect(created.data.label_localized).toBe(label);
    const sent = response.request().postDataJSON() as { label?: Record<string, string> };
    expect(Object.values(sent.label ?? {})).toContain(label);

    await expect(dialog).toBeHidden({ timeout: 10_000 });
    await expect(page.getByText(label, { exact: true })).toBeVisible({ timeout: 10_000 });

    // 원복 — 검증용 예약 삭제 (페이지 인증 컨텍스트의 실제 API 경로)
    const deleteStatus = await page.evaluate(async (id) => {
      const res = await fetch(`/api/admin/schedules/${id}`, {
        method: 'DELETE',
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
        },
        credentials: 'include',
      });
      return res.status;
    }, created.data.id);
    expect(deleteStatus, '검증용 예약 원복(삭제)이 실패했다').toBe(200);
  });

  // D5(M1 실측): 다른 언어 탭을 연 채 기본 언어 명칭을 비우고 저장하면 브라우저 검증이 숨은 필수 칸을
  //   잡아(「not focusable」) 요청 0·안내 0 으로 멈췄다. 폼은 noValidate — 서버 422 가 칸 아래 오류로 보여야 한다.
  // @scenario case=form_modal_save
  // @effects schedule_modal_save_creates_row
  test('다른 언어 탭에서 명칭을 비우고 저장해도 요청이 나가고 서버 오류가 칸 아래 보인다', async ({ page }) => {
    const token = issueToken('core.schedules.read', 'core.schedules.create');
    await authenticatePage(page, token);
    await page.goto('/admin/schedules');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    await page.getByRole('button', { name: /스케줄 등록/ }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    // 명칭은 비워 두고 영어 탭으로 옮긴다 — 기본 언어(한국어) 칸이 숨는다
    await dialog.getByRole('button', { name: /English|영어/ }).first().click();
    await dialog.locator('input[name="name"]').fill(`e2e-schedule-novalidate-${Date.now()}`);
    await dialog.locator('textarea[name="command"]').fill('cache:clear');

    const createResponse = page.waitForResponse(
      (res) => res.url().includes('/api/admin/schedules') && res.request().method() === 'POST',
      { timeout: 15_000 }
    );
    await dialog.getByRole('button', { name: '저장' }).click();

    const response = await createResponse;
    expect(response.status(), '명칭 없는 직접 등록은 서버가 거절해야 한다').toBe(422);
    const body = (await response.json()) as { errors?: Record<string, string[]> };
    const labelError = body.errors?.label?.[0];
    expect(labelError, '명칭 오류가 응답에 있어야 한다').toBeTruthy();

    // 창은 열린 채로, 명칭 칸 아래 서버 오류 문구가 보인다(브라우저 말풍선이 아니라).
    // 같은 문구가 창 위 오류 요약에도 나오므로 명칭 칸 안에서 찾는다.
    await expect(dialog).toBeVisible();
    await expect(
      dialog.locator('#schedule_label_input').getByText(labelError as string, { exact: true })
    ).toBeVisible({ timeout: 10_000 });
    await expect(dialog.getByRole('listitem').filter({ hasText: labelError as string })).toBeVisible();
  });
});

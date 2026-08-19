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
 * @scenario schedule-form-modal-save
 * @axes leg=create
 * @effects schedule_modal_save_creates_row
 */
import { test, expect } from '@playwright/test';
import { issueToken, authenticatePage } from '../../fixtures/admin-template-auth';

test.describe('@sirsoft-admin_basic 스케줄 폼 모달 저장', () => {
  // @scenario leg=create
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

    const name = `E2E 스케줄 저장 회귀 ${Date.now()}`;

    await page.getByRole('button', { name: /스케줄 등록/ }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    // 커스텀 Select 가 value 로 제어되어 기본값이 표시되어야 한다 (빈 표시 회귀).
    await expect(dialog.getByRole('button', { name: 'Artisan' })).toBeVisible();
    await expect(dialog.getByRole('button', { name: '매일' })).toBeVisible();

    await dialog.locator('input[name="name"]').fill(name);
    await dialog.locator('input[name="command"]').fill('cache:clear');

    const createResponse = page.waitForResponse(
      (res) => res.url().includes('/api/admin/schedules') && res.request().method() === 'POST',
      { timeout: 30_000 }
    );
    await dialog.getByRole('button', { name: '저장' }).click();

    // 저장 버튼이 폼과 연결되지 않으면 POST 자체가 발생하지 않는다 (무반응 회귀).
    const response = await createResponse;
    expect(response.status(), '스케줄 생성 요청이 거절되었다').toBe(201);

    const created = (await response.json()) as { data: { id: number } };

    await expect(dialog).toBeHidden({ timeout: 10_000 });
    await expect(page.getByText(name)).toBeVisible({ timeout: 10_000 });

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
});

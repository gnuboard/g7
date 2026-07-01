/**
 * Smoke: 본인인증 정책 편집 모달의 출처별 편집 제한.
 *
 * 규칙:
 *   선언형 정책(source_type = core/module/plugin)은 정책 키·강제 시점·강제 위치(정책이 걸리는
 *   지점 식별자) 3개 필드만 잠기고, 인증 목적·적용 대상을 포함한 그 외 모든 필드는 운영자가
 *   자유로이 편집할 수 있다. 운영자(admin) 정책은 키·시점·위치까지 전부 편집 가능하다.
 *
 * 배경:
 *   인증 목적(purpose)·적용 대상(applies_to)까지 잠겼던 것은 "선언형 readonly" 원칙의 과확장이었다.
 *   인증 목적은 어떤 정책이든 운영자가 자유 부여 가능해야 한다. 본 spec 은 선언형 편집 시
 *   인증 목적·적용 대상이 활성(편집 가능)이고 키·시점·위치만 비활성임을 브라우저에서 확인한다.
 *
 * @scenario source=identity-policy-edit axis=source_type:declared,source_type:admin
 * @effects key-scope-target-locked, purpose-editable-for-declared, applies-to-editable-for-declared, admin-fields-all-enabled
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

function policyToken(): string {
  return issueToken(
    'core.settings.read',
    'core.admin.identity.policies.read',
    'core.admin.identity.policies.update',
  );
}

/** 모달이 마운트되어 제목이 보일 때까지 대기 */
async function waitForPolicyModal(page: import('@playwright/test').Page) {
  await expect(page.getByRole('dialog')).toBeVisible({ timeout: 15_000 });
}

/** 특정 라벨 그룹의 컨트롤(input/select 버튼)이 disabled 인지 측정 */
async function fieldDisabled(page: import('@playwright/test').Page, labelText: string): Promise<boolean> {
  return page.evaluate((label) => {
    const dialog = document.querySelector('[role="dialog"]');
    if (!dialog) return null;
    const labels = Array.from(dialog.querySelectorAll('label, span'));
    const lbl = labels.find((l) => (l.textContent || '').trim() === label);
    if (!lbl) return null;
    // 그룹 Div 안에서 input 또는 커스텀 Select 트리거 버튼 탐색
    let group: Element | null = lbl.parentElement;
    for (let i = 0; i < 4 && group; i++) {
      const control = group.querySelector(
        'input, button[aria-haspopup="listbox"]',
      ) as HTMLInputElement | HTMLButtonElement | null;
      if (control) {
        return (control as any).disabled === true || control.getAttribute('aria-disabled') === 'true';
      }
      group = group.parentElement;
    }
    return null;
  }, labelText);
}

/** 목록에서 주어진 출처 필터로 첫 행의 [편집] 버튼 클릭 */
async function openFirstEditModal(page: import('@playwright/test').Page, sourceType: string) {
  const listResp = page.waitForResponse(
    (r) =>
      r.url().includes('/api/admin/identity/policies') &&
      new RegExp(`[?&]source_type=${sourceType}\\b`).test(r.url()),
    { timeout: 25_000 },
  );
  await page.goto(`/admin/settings?tab=identity&sub_tab=policies&source_type=${sourceType}`);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  expect(page.url()).not.toMatch(/\/admin\/login/);
  await listResp;
  await page.waitForTimeout(600);

  const editBtn = page.getByRole('button', { name: '편집', exact: true }).first();
  await expect(editBtn).toBeVisible({ timeout: 15_000 });
  await editBtn.click();
  await waitForPolicyModal(page);
}

test('@smoke 코어 정책 편집 모달 — 키/강제시점/강제위치만 잠금 + 인증목적·적용대상 포함 그 외 편집가능', async ({ page }) => {
  await authenticatePage(page, policyToken());
  await openFirstEditModal(page, 'core');

  // 잠금 필드 (정책이 걸리는 지점 식별자 3종만)
  expect(await fieldDisabled(page, '정책 키')).toBe(true);
  expect(await fieldDisabled(page, '강제 시점')).toBe(true);
  expect(await fieldDisabled(page, '강제 위치')).toBe(true);

  // 편집 가능 필드 — 인증 목적·적용 대상 포함
  expect(await fieldDisabled(page, '인증 목적')).toBe(false);
  expect(await fieldDisabled(page, '적용 대상')).toBe(false);
  expect(await fieldDisabled(page, '인증 수단')).toBe(false);
  expect(await fieldDisabled(page, '재요구 주기 (분) — 0: 매번 요구')).toBe(false);
  expect(await fieldDisabled(page, '실패 시 처리')).toBe(false);

  // 안내 문구 노출 (키·시점·위치만 변경 불가)
  await expect(
    page.getByText('정책 키 · 강제 시점 · 강제 위치는 변경할 수 없고', { exact: false }),
  ).toBeVisible();
});

test('@smoke 운영자 정책 편집 모달 — 모든 필드 편집가능 + 안내 문구 미노출', async ({ page }) => {
  await authenticatePage(page, policyToken());

  // 운영자 정책이 없으면 생성(테스트 자족) — 생성 후 admin 필터로 편집 진입
  const createResp = page.waitForResponse(
    (r) => r.url().includes('/api/admin/identity/policies') && r.request().method() === 'POST',
    { timeout: 25_000 },
  ).catch(() => null);

  await page.goto('/admin/settings?tab=identity&sub_tab=policies&source_type=admin');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  expect(page.url()).not.toMatch(/\/admin\/login/);
  await page.waitForTimeout(800);

  const hasAdminRow = await page.getByRole('button', { name: '편집', exact: true }).count();
  if (hasAdminRow === 0) {
    // + 정책 추가 → 키/위치 입력 → 저장
    await page.getByRole('button', { name: '+ 정책 추가' }).click();
    await waitForPolicyModal(page);
    const dialog = page.getByRole('dialog');
    const inputs = dialog.locator('input[type="text"]');
    await inputs.nth(0).fill('admin.e2e_edit_restriction.before_action');
    await inputs.nth(1).fill('/admin/e2e-edit-restriction/sensitive');
    await dialog.getByRole('button', { name: '저장', exact: true }).click();
    await createResp;
    await page.waitForTimeout(800);
  }

  // admin 정책 편집 모달 진입
  await openFirstEditModal(page, 'admin');

  // 모든 필드 편집 가능
  expect(await fieldDisabled(page, '정책 키')).toBe(false);
  expect(await fieldDisabled(page, '강제 시점')).toBe(false);
  expect(await fieldDisabled(page, '강제 위치')).toBe(false);
  expect(await fieldDisabled(page, '인증 목적')).toBe(false);
  expect(await fieldDisabled(page, '적용 대상')).toBe(false);
  expect(await fieldDisabled(page, '인증 수단')).toBe(false);
  expect(await fieldDisabled(page, '실패 시 처리')).toBe(false);

  // 선언형 안내 문구는 운영자 정책에는 미노출
  await expect(page.getByText('정책 키 · 강제 시점 · 강제 위치는 변경할 수 없고', { exact: false })).toHaveCount(0);
});

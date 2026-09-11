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
 * @scenario source=identity-policy-edit, axis=source_type:declared, source_type=admin
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
/**
 * 앱 로케일을 ko 로 고정한다.
 *
 * 본 spec 은 한국어 라벨('편집', '정책 키', '+ 정책 추가' 등)로 요소를 특정한다.
 * 앱 로케일이 en 이면 전부 매칭 실패하므로 첫 페이지 로드 전에 고정해야 한다
 * (엔진은 localStorage.g7_locale 을 사용).
 */
async function forceKoreanLocale(page: import('@playwright/test').Page) {
  await page.addInitScript(() => {
    try { window.localStorage.setItem('g7_locale', 'ko'); } catch { /* noop */ }
  });
}

/**
 * 정책 표로 스코프를 좁힌 locator.
 *
 * 같은 화면(`?tab=identity&sub_tab=policies`)에는 정책 표 **아래에 메시지 템플릿 표**가 함께
 * 렌더되고 그쪽에도 `편집` 버튼이 6개 있다. 종전 구현은 `getByRole('button',{name:'편집'}).first()`
 * 로 **컨테이너 스코프 없이** 잡아, 정책 표가 늦게 그려지면 메시지 템플릿 쪽 버튼을 눌러
 * `dialog "메시지 템플릿 편집"` 이 열렸다(S17 20차 게이트 ⑦ 실패의 실제 원인).
 * 표의 고유 헤더 `정책 키` 로 컨테이너를 특정한다.
 */
function policyTable(page: import('@playwright/test').Page) {
  return page
    .locator('table')
    .filter({ has: page.getByRole('columnheader', { name: '정책 키', exact: true }) });
}

/** 정책 표 안의 [편집] 버튼 (메시지 템플릿 표의 동명 버튼과 섞이지 않는다) */
function policyEditButtons(page: import('@playwright/test').Page) {
  return policyTable(page).getByRole('button', { name: '편집', exact: true });
}

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

  // 정책 표 자체가 마운트될 때까지 기다린 뒤 그 안에서 버튼을 찾는다 (고정 대기 대신 조건 대기)
  await expect(policyTable(page)).toBeVisible({ timeout: 15_000 });

  const editBtn = policyEditButtons(page).first();
  await expect(editBtn).toBeVisible({ timeout: 15_000 });
  await editBtn.click();
  await waitForPolicyModal(page);
}

test('@smoke 코어 정책 편집 모달 — 키/강제시점/강제위치만 잠금 + 인증목적·적용대상 포함 그 외 편집가능', async ({ page }) => {
  await authenticatePage(page, policyToken());
  await forceKoreanLocale(page);
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
  await forceKoreanLocale(page);

  // 운영자 정책이 없으면 생성(테스트 자족) — 생성 후 admin 필터로 편집 진입
  const createResp = page.waitForResponse(
    (r) => r.url().includes('/api/admin/identity/policies') && r.request().method() === 'POST',
    { timeout: 25_000 },
  ).catch(() => null);

  await page.goto('/admin/settings?tab=identity&sub_tab=policies&source_type=admin');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  expect(page.url()).not.toMatch(/\/admin\/login/);
  await expect(policyTable(page)).toBeVisible({ timeout: 15_000 });

  // 정책 표 안에서만 센다 — 무스코프로 세면 메시지 템플릿 표의 편집 버튼 6개 때문에
  // 운영자 정책이 0건이어도 항상 non-zero 가 되어 아래 자족 생성 분기가 도달 불가였다.
  const hasAdminRow = await policyEditButtons(page).count();
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

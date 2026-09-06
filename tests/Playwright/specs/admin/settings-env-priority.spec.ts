/**
 * E2E: 환경설정 — `.env` 키 단위 우선 (G7_ENV_PRIORITY) (#641)
 *
 * 시나리오 매니페스트: tests/scenarios/settings-env-priority.yaml — 마킹은 각 테스트의
 * scenario(k=v 조합)·effects 주석이 담당하며, 헤더 요약 마킹은 파서 형식이 아니다
 *
 * 잠금 축(스위치 ON)은 서버의 `.env` 를 바꾸고 설정 캐시를 다시 구워야 재현되므로 CI 의
 * 일반 실행에서는 다루지 않는다. 그 축은 레이아웃 테스트
 * (admin-settings-env-locked.test.tsx)와 Chrome MCP 실측 매트릭스가 커버한다.
 * 잠금 축을 켜 둔 서버에서 돌리려면 `G7_E2E_ENV_PRIORITY=1` 을 주면 된다.
 *
 * 여기서 상시 지키는 것은 **회귀 가드**다 — 스위치를 켜지 않은 설치에서 화면이 종전과
 * 똑같이 동작하는지(배지 0 · 배너 0 · 저장 왕복 유지).
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

const ENV_PRIORITY_ON = process.env.G7_E2E_ENV_PRIORITY === '1';

/** 관리자 환경설정 일반 탭 진입 (설정 시드 완료까지 대기) */
async function gotoGeneralTab(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('/admin/settings?tab=general');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator('input[name="general.site_name"]').first()).toBeAttached({ timeout: 20_000 });

  await expect
    .poll(
      () =>
        page.evaluate(
          () => Object.keys((window as any).G7Core?.state?.getLocal?.()?.form?.general ?? {}).length
        ),
      { timeout: 20_000 }
    )
    .toBeGreaterThan(0);
}

/** 설정 응답의 `_meta` 를 읽는다 */
async function readSettingsMeta(page: import('@playwright/test').Page): Promise<any> {
  return page.evaluate(() => (window as any).G7Core?.state?.getLocal?.()?.form?._meta ?? null);
}

// @scenario switch=off, key_state=unlocked, surface=get_meta
// @effects switch_off_behaves_identically
test('@smoke #641 - 스위치가 꺼진 설치는 응답 메타에 잠금이 비어 있다', async ({ page }) => {
  test.skip(ENV_PRIORITY_ON, '이 블록은 스위치가 꺼진 서버를 전제로 한다');

  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await gotoGeneralTab(page);

  const meta = await readSettingsMeta(page);

  expect(meta).not.toBeNull();
  expect(meta.env_priority_enabled).toBe(false);
  expect(Object.keys(meta.env_locked ?? {})).toHaveLength(0);
});

// @scenario switch=off, key_state=unlocked, surface=ui_lock
// @effects switch_off_behaves_identically
test('#641 - 스위치가 꺼진 설치는 배너도 잠금 배지도 렌더하지 않는다', async ({ page }) => {
  test.skip(ENV_PRIORITY_ON, '이 블록은 스위치가 꺼진 서버를 전제로 한다');

  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await gotoGeneralTab(page);

  await expect(page.locator('#env_priority_banner')).toHaveCount(0);
  await expect(page.locator('input[name="general.site_name"]').first()).toBeEnabled();
  // site_description 은 Textarea 로 렌더된다 — input 셀렉터로는 잡히지 않는다.
  await expect(page.locator('textarea[name="general.site_description"]').first()).toBeEnabled();
});

// @scenario switch=off, key_state=unlocked, surface=post_filter
// @effects switch_off_behaves_identically
test('#641 - 스위치가 꺼진 설치의 저장 왕복이 종전과 같다', async ({ page }) => {
  test.skip(ENV_PRIORITY_ON, '이 블록은 스위치가 꺼진 서버를 전제로 한다');

  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await gotoGeneralTab(page);

  const description = page.locator('textarea[name="general.site_description"]').first();
  await expect(description).toBeAttached();

  const original = (await description.inputValue()) ?? '';
  const probe = `env-priority-e2e-${Date.now()}`;

  const save = async (value: string) => {
    await description.fill(value);
    const saved = page.waitForResponse(
      (response) =>
        response.request().method() === 'POST' &&
        /\/api\/admin\/settings\/?$/.test(new URL(response.url()).pathname),
      { timeout: 20_000 }
    );
    await page.getByRole('button', { name: /저장|Save/ }).first().click();
    return saved;
  };

  const response = await save(probe);
  expect(response.status()).toBe(200);

  const body = await response.json();
  expect(body?.data?.settings?._meta?.env_priority_enabled).toBe(false);
  expect(body?.data?.settings?.general?.site_description).toBe(probe);

  // 원복 — 검수 후 상태를 남기지 않는다
  const restore = await save(original);
  expect(restore.status()).toBe(200);
});

// @scenario switch=on, key_state=locked_plain, surface=ui_lock
// @effects badge_and_disabled_rendered, meta_env_locked_uses_frontend_keys
test('#641 - 스위치가 켜진 서버는 잠긴 필드를 배지와 함께 잠근다', async ({ page }) => {
  test.skip(!ENV_PRIORITY_ON, '서버 `.env` 조작이 필요한 축 — G7_E2E_ENV_PRIORITY=1 일 때만 실행');

  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await gotoGeneralTab(page);

  const meta = await readSettingsMeta(page);
  expect(meta.env_priority_enabled).toBe(true);

  const lockedKeys = Object.keys(meta.env_locked ?? {});
  expect(lockedKeys.length).toBeGreaterThan(0);

  await expect(page.locator('#env_priority_banner')).toBeVisible();

  for (const key of lockedKeys.filter((k) => k.startsWith('general.'))) {
    const field = page.locator(`[name="${key}"]`).first();

    if ((await field.count()) === 0) {
      continue;
    }

    await expect(field).toBeDisabled();
  }
});

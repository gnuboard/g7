/**
 * 플러그인 수동 설치 창 — 서명 없는 ZIP: 409 경고 패널 → 「미서명으로 계속」 → 같은 요청을 allow_unsigned=1 로 다시 보내 201 (#916 ④).
 *
 * 시험 패키지는 저장소 픽스처(`templates/_bundled/sirsoft-admin_basic/tests/Playwright/fixtures/unsigned-plugin-package.ts`)가 시험 때 만든다(서명 파일 없음). 설치한 시험 플러그인은
 * 끝에 지우고(`plugin:uninstall --force --delete-data`), 남은 설치 디렉터리와 `_pending` 사본도 지운다.
 * 「미서명 패키지 차단」 스위치가 켜져 있으면 409 의 `can_continue` 가 false 라 이 흐름이 성립하지 않는다 — 그때는 그 사실로 실패한다.
 */
import { test, expect, type Page } from '@playwright/test';
import { execSync } from 'node:child_process';
import { existsSync, rmSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { issueToken, authenticatePage } from '../../fixtures/admin-template-auth';
import { buildUnsignedPluginZip, UNSIGNED_PLUGIN_ID } from '../../fixtures/unsigned-plugin-package';

const __dirname = dirname(fileURLToPath(import.meta.url));
const CORE_ROOT = process.env.G7_ROOT || resolve(__dirname, '../../../../../../../');
const INSTALL_URL_SUFFIX = '/api/admin/plugins/install-from-file';

/** 시험 플러그인을 지우고 설치 디렉터리·`_pending` 사본을 걷는다(없으면 아무 일도 하지 않는다) */
function removeTestPlugin(): void {
  try {
    execSync(`php artisan plugin:uninstall ${UNSIGNED_PLUGIN_ID} --force --delete-data --no-interaction`, {
      cwd: CORE_ROOT,
      encoding: 'utf-8',
      stdio: 'pipe',
    });
  } catch {
    // 설치되지 않은 상태(앞선 실패로 설치 전에 끝남)면 명령이 실패한다 — 디렉터리 정리는 계속한다
  }
  for (const dir of [resolve(CORE_ROOT, 'plugins', UNSIGNED_PLUGIN_ID), resolve(CORE_ROOT, 'plugins', '_pending', UNSIGNED_PLUGIN_ID)]) {
    if (existsSync(dir)) rmSync(dir, { recursive: true, force: true });
  }
}

/**
 * 설치 요청 본문 기록기 — Chromium 은 파일을 담은 multipart 본문을 Playwright 에 넘기지 않아(postData 가 비어 있다),
 * 페이지의 fetch 에 들어가는 FormData 를 칸 이름과 값(파일은 파일 이름)으로 적어 둔다.
 *
 * @param page Playwright 페이지
 */
async function recordInstallBodies(page: Page): Promise<void> {
  await page.addInitScript((suffix: string) => {
    const w = window as any;
    w.__installBodies = [];
    const original = w.fetch.bind(w);
    w.fetch = (input: any, init?: any) => {
      const url = typeof input === 'string' ? input : (input?.url ?? '');
      if (String(url).endsWith(suffix) && init?.body instanceof FormData) {
        const fields: Record<string, string> = {};
        (init.body as FormData).forEach((value, key) => {
          fields[key] = value instanceof File ? `file:${value.name}` : String(value);
        });
        w.__installBodies.push(fields);
      }
      return original(input, init);
    };
  }, INSTALL_URL_SUFFIX);
}

/**
 * 기록한 설치 요청 본문들
 *
 * @param page Playwright 페이지
 * @returns 요청마다 칸 이름 → 값
 */
async function installBodies(page: Page): Promise<Array<Record<string, string>>> {
  return page.evaluate(() => (window as any).__installBodies ?? []);
}

test.describe('@sirsoft-admin_basic 플러그인 수동 설치 — 미서명 계속', () => {
  test.beforeEach(async ({ page }) => {
    // 설치 요청 두 번(409 → 201)과 목록 갱신이 이어져 기본 30초를 넘는다
    test.setTimeout(120_000);
    // 동작 대기 상한 — 저장소 설정에 actionTimeout 이 없어 틀린 선택자 하나가 시험 제한을 통째로 쓰지 않게 한다
    page.setDefaultTimeout(20_000);
    page.setDefaultNavigationTimeout(60_000);
    removeTestPlugin();
  });

  test.afterEach(() => {
    removeTestPlugin();
  });

  // @scenario case=manual_install_unsigned_continue
  // @effects unsigned_panel_shown, install_button_becomes_continue_unsigned, retry_sends_same_body_with_allow_unsigned
  test('서명 없는 ZIP 은 409 경고 패널을 띄우고 「미서명으로 계속」이 같은 요청을 allow_unsigned=1 로 다시 보내 설치한다', async ({ page }) => {
    const token = issueToken('core.plugins.read', 'core.plugins.install', 'core.plugins.activate', 'core.plugins.uninstall');
    await authenticatePage(page, token);
    await recordInstallBodies(page);
    await page.goto('/admin/plugins', { waitUntil: 'domcontentloaded' });

    // 단추는 목록 응답의 설치 권한(abilities.can_install)이 와야 풀리고, 그 전에는 로딩 덮개가 클릭을 가린다
    const manualInstall = page.getByRole('button', { name: '수동 설치' }).first();
    await expect(manualInstall).toBeEnabled({ timeout: 45_000 });
    await expect(page.locator('#g7-skeleton-overlay')).toHaveCount(0, { timeout: 45_000 });
    await manualInstall.click();
    const dialog = page.getByRole('dialog').filter({ hasText: '플러그인 수동 설치' });
    await expect(dialog).toBeVisible();

    const zip = buildUnsignedPluginZip();
    await dialog.locator('input[type="file"][accept=".zip"]').setInputFiles({ name: zip.name, mimeType: 'application/zip', buffer: zip.buffer });

    // 1차 — 서명 파일 없이 보낸다
    const firstResponse = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith(INSTALL_URL_SUFFIX), { timeout: 60_000 });
    await dialog.getByRole('button', { name: '설치', exact: true }).click();
    const first = await firstResponse;
    expect(first.status()).toBe(409);
    const firstBody = await first.json();
    expect(firstBody.errors?.package_unsigned).toBe(true);
    expect(firstBody.errors?.integrity?.status).toBe('unsigned');
    expect(firstBody.errors?.integrity?.can_continue, '「미서명 패키지 차단」이 켜져 있으면 계속할 수 없다').toBe(true);

    // 경고 패널과 「미서명으로 계속」
    await expect(dialog.getByText('서명이 없는 패키지입니다')).toBeVisible();
    const continueButton = dialog.getByRole('button', { name: '미서명으로 계속' });
    await expect(continueButton).toBeVisible();

    // 2차 — 같은 주소, 같은 파일에 allow_unsigned=1 만 더한다
    const secondResponse = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith(INSTALL_URL_SUFFIX), { timeout: 90_000 });
    await continueButton.click();
    const second = await secondResponse;
    expect(second.url()).toBe(first.url());
    const bodies = await installBodies(page);
    expect(bodies).toHaveLength(2);
    expect(bodies[0].file).toBe(`file:${zip.name}`);
    expect(bodies[0].allow_unsigned).toBeUndefined();
    // 같은 파일에 allow_unsigned=1 만 더해졌다
    expect(bodies[1].file).toBe(bodies[0].file);
    expect(bodies[1].allow_unsigned).toBe('1');
    expect(Object.keys(bodies[1]).filter((k) => k !== 'allow_unsigned').sort()).toEqual(Object.keys(bodies[0]).sort());
    expect(second.status()).toBe(201);
    const secondBody = await second.json();
    expect(secondBody.data?.identifier).toBe(UNSIGNED_PLUGIN_ID);
    expect(secondBody.data?.integrity_status).toBe('unsigned');

    // 설치 결과 안내가 곧바로 뜬다(성공 시퀀스는 토스트 뒤에 목록 갱신·확장 다시 읽기를 거쳐 창을 닫으므로 먼저 본다)
    await expect(page.getByText('서명을 확인하지 않은 패키지로 진행했습니다.')).toBeVisible({ timeout: 10_000 });
    await expect(dialog).toBeHidden({ timeout: 45_000 });
  });
});

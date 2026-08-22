/**
 * E2E: 공개 자산 스토리지 설정 — 코어 드라이버 탭 카드 (공개#100)
 *
 * 시나리오 매니페스트: `tests/scenarios/public-asset-cdn.yaml`
 * (케이스 마킹은 각 test 의 라인 주석에 있다)
 *
 * @effects settings_catalog_includes_plugin_registered_disks,
 *          invalid_disk_rejected_with_422
 *
 * 드라이버 탭의 "공개 자산 스토리지" 카드가 카탈로그(none/public/s3)를 그리고,
 * 선택-저장-재진입 왕복에서 값이 유지되는지 검증한다. 함께 있는 CKEditor5 축은
 * 코어 PluginSettingsController 의 카탈로그 부착을 제한 권한에서 검증하는 것이라
 * 코어/템플릿 표면에 둔다 — 이커머스 모듈이 소유한 오버라이드 필드는 모듈 spec
 * (`modules/_bundled/sirsoft-ecommerce/tests/Playwright/specs/admin/`) 이 담당한다.
 *
 * composite Select 는 네이티브 select 가 아니라 커스텀 드롭다운(root div 에 name 속성,
 * Button 토글, portal listbox role=option)이다 — 상호작용은 그 구조를 따른다.
 * 저장 후에는 반드시 원값으로 되돌려 사이트 설정을 바꾸지 않는다.
 */
import { test, expect, authenticatePage, issueToken } from '../fixtures/admin-template-auth';
import type { Page } from '@playwright/test';

/** 코어 설정 읽기/쓰기 권한 토큰 (테스트 파일 단위 1회 발급) */
let settingsToken: string;

test.beforeAll(() => {
  settingsToken = issueToken('core.settings.read', 'core.settings.update');
});

const DRIVERS_TAB = '/admin/settings?tab=drivers';
const CKEDITOR5_SETTINGS = '/admin/plugins/sirsoft-ckeditor5/settings';

const CORE_SELECT_ROOT = '[name="drivers.public_asset_disk"]';
const PLUGIN_SELECT_ROOT = '[name="public_asset_disk"]';

/**
 * 드라이버 탭 진입 — 폼 데이터 적재를 엔진 상태로 확인한다.
 *
 * DOM attach 만 기다리면 바인딩 전 빈 Select 를 카탈로그 누락으로 오인한다.
 */
async function gotoDriversTab(page: Page): Promise<void> {
  await page.goto(DRIVERS_TAB);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect
    .poll(
      async () =>
        await page.evaluate(() => {
          const local = (window as any).G7Core?.state?.getLocal?.();
          return Array.isArray(local?.form?.available_drivers?.public_asset);
        }),
      { timeout: 20_000 },
    )
    .toBe(true);
}

/**
 * 커스텀 드롭다운을 열고 옵션 라벨 목록을 읽는다 (읽은 뒤 드롭다운은 닫지 않음).
 *
 * @param page Playwright 페이지
 * @param rootSelector Select 루트(name 속성) 선택자
 * @returns 옵션 라벨 배열
 */
async function openAndReadOptions(page: Page, rootSelector: string): Promise<string[]> {
  await page.locator(`${rootSelector} button`).first().click();
  const listbox = page.locator('[role="listbox"]');
  await expect(listbox).toBeVisible({ timeout: 10_000 });

  return listbox.locator('[role="option"]').allInnerTexts();
}

/**
 * 엔진 로컬 상태에서 폼 값을 읽는다.
 *
 * @param page Playwright 페이지
 * @param path form 하위 도트 경로 (예: 'drivers.public_asset_disk')
 * @returns 현재 폼 값
 */
async function readFormValue(page: Page, path: string): Promise<unknown> {
  return page.evaluate((p) => {
    const form = (window as any).G7Core?.state?.getLocal?.()?.form;
    return p.split('.').reduce((acc: any, key: string) => acc?.[key], form);
  }, path);
}

test.describe('공개 자산 스토리지 설정', () => {
  // @scenario consumer=product, disk_setting=none, e2e=drivers_tab_card, hook=unregistered, override=follow_core, row_state=legacy_local_row
  // @effects settings_catalog_includes_plugin_registered_disks
  test('드라이버 탭 카드가 코어 카탈로그(none/public/s3)를 그린다', async ({ page }) => {
    await authenticatePage(page, settingsToken);
    await gotoDriversTab(page);

    await expect(page.locator(CORE_SELECT_ROOT)).toBeVisible();

    const labels = await openAndReadOptions(page, CORE_SELECT_ROOT);
    expect(labels.join('|')).toContain('사용 안 함');
    expect(labels.join('|')).toContain('Public');
    expect(labels.join('|')).toContain('Amazon S3');

    await page.keyboard.press('Escape');
  });

  // @scenario consumer=product, disk_setting=public, e2e=save_roundtrip, hook=unregistered, override=module_override, row_state=new_remote_row
  // @effects invalid_disk_rejected_with_422
  test('선택-저장-재진입 왕복에서 값이 유지되고 원복된다', async ({ page }) => {
    test.setTimeout(120_000);
    await authenticatePage(page, settingsToken);
    await gotoDriversTab(page);

    const original = (await readFormValue(page, 'drivers.public_asset_disk')) as string;

    // Public 디스크 선택 → 저장 → 성공 토스트
    await openAndReadOptions(page, CORE_SELECT_ROOT);
    await page.locator('[role="listbox"] [role="option"]', { hasText: 'Public' }).first().click();
    await expect.poll(() => readFormValue(page, 'drivers.public_asset_disk')).toBe('public');

    await page.locator('#save_button button, button#save_button').first().click();
    await expect(page.getByText('설정이 저장되었습니다')).toBeVisible({ timeout: 20_000 });

    // 재진입 — 서버 저장값이 폼으로 복원되는지 확인 (refetchOnMount)
    await gotoDriversTab(page);
    await expect.poll(() => readFormValue(page, 'drivers.public_asset_disk'), { timeout: 20_000 }).toBe('public');

    // 원복 (사이트 설정 보존) — 원값 라벨('none' → '사용 안 함')로 되돌린다
    const restoreLabel = original === 'public' ? 'Public' : original === 's3' ? 'Amazon S3' : '사용 안 함';
    await openAndReadOptions(page, CORE_SELECT_ROOT);
    await page.locator('[role="listbox"] [role="option"]', { hasText: restoreLabel }).first().click();
    await page.locator('#save_button button, button#save_button').first().click();
    await expect(page.getByText('설정이 저장되었습니다')).toBeVisible({ timeout: 20_000 });

    await gotoDriversTab(page);
    await expect
      .poll(() => readFormValue(page, 'drivers.public_asset_disk'), { timeout: 20_000 })
      .toBe(original || 'none');
  });

  // @scenario consumer=editor, disk_setting=none, e2e=ecommerce_settings_field, hook=unregistered, override=module_override, row_state=legacy_local_row
  // @effects settings_catalog_includes_plugin_registered_disks
  test('CKEditor5 설정 필드가 코어 설정 권한 없이도 카탈로그를 그린다', async ({ page }) => {
    // core.settings.read 를 의도적으로 제외 — 카탈로그는 플러그인 설정 응답에 서버
    // 부착되므로, 코어 환경설정 API 권한이 없는 커스텀 역할에서도 선택지가 채워져야
    // 한다 (화면 권한과 카탈로그 권한의 표면 단일화 검증)
    const pluginOnlyToken = issueToken('core.plugins.read', 'core.plugins.update');
    await authenticatePage(page, pluginOnlyToken);
    await page.goto(CKEDITOR5_SETTINGS);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await expect
      .poll(
        async () =>
          await page.evaluate(() => {
            const local = (window as any).G7Core?.state?.getLocal?.();
            return Array.isArray(local?.form?.available_public_asset_disks);
          }),
        { timeout: 20_000 },
      )
      .toBe(true);

    await expect(page.locator(PLUGIN_SELECT_ROOT)).toBeVisible({ timeout: 20_000 });

    const labels = await openAndReadOptions(page, PLUGIN_SELECT_ROOT);
    expect(labels[0]).toContain('코어 설정 따름');
    expect(labels.join('|')).toContain('사용 안 함');
    expect(labels.join('|')).toContain('Amazon S3');

    await page.keyboard.press('Escape');
  });

  // @scenario consumer=product, disk_setting=fake_cdn, e2e=drivers_tab_card, hook=unregistered, override=follow_core, row_state=new_remote_row
  // @effects cross_origin_asset_request_omits_session_token
  test('교차 출처 공개 자산 요청에는 세션 토큰을 보내지 않는다', async ({ page }) => {
    await authenticatePage(page, settingsToken);
    await gotoDriversTab(page);

    // 외부 CDN origin 을 가로채 요청 헤더를 실측한다 (CORS 헤더는 주지 않는다 —
    // 기본 설정 S3 버킷과 동일한 조건)
    const crossOriginUrl = 'https://cdn.example.invalid/bucket/products/probe.png';
    const capturedHeaders: Record<string, string>[] = [];
    await page.route(crossOriginUrl, async (route) => {
      capturedHeaders.push(route.request().headers());
      await route.fulfill({ status: 200, contentType: 'image/png', body: Buffer.from([]) });
    });

    const sameOriginHeaders: Record<string, string>[] = [];
    await page.route('**/api/admin/settings?probe=1', async (route) => {
      sameOriginHeaders.push(route.request().headers());
      await route.fulfill({ status: 200, contentType: 'application/json', body: '{"success":true}' });
    });

    await page.evaluate(async (url) => {
      const api = (window as any).G7Core?.api;
      await api.get(url, { responseType: 'blob' }).catch(() => undefined);
      await api.get('/api/admin/settings?probe=1').catch(() => undefined);
    }, crossOriginUrl);

    await expect.poll(() => capturedHeaders.length, { timeout: 20_000 }).toBeGreaterThan(0);
    await expect.poll(() => sameOriginHeaders.length, { timeout: 20_000 }).toBeGreaterThan(0);

    // 동일 출처는 종전대로 토큰을 실어야 한다 (대조군 — 판정식이 살아 있음을 보장)
    expect(sameOriginHeaders[0]['authorization']).toContain('Bearer');
    // 교차 출처는 토큰이 없어야 한다 (제3자 origin 노출 + CORS preflight 유발 차단)
    expect(capturedHeaders[0]['authorization']).toBeUndefined();
  });

  // @scenario consumer=product, disk_setting=s3_without_url, e2e=drivers_tab_card, hook=unregistered, override=follow_core, row_state=new_remote_row
  // @effects s3_credentials_visible_when_public_asset_disk_is_s3
  test('파일 스토리지가 로컬이어도 공개 자산이 S3 면 접속 필드가 노출된다', async ({ page }) => {
    await authenticatePage(page, settingsToken);
    await gotoDriversTab(page);

    const storageSelect = '[name="drivers.storage_driver"]';

    // 파일 스토리지를 로컬로 두고(저장하지 않는다 — 폼 상태만 변경)
    await openAndReadOptions(page, storageSelect);
    await page.locator('[role="listbox"] [role="option"]', { hasText: '로컬' }).first().click();

    // 이 상태에서 s3 접속 필드는 없어야 한다 (존재 확정 전 부재 단언 회피 —
    // 아래에서 같은 선택자로 존재를 확정한다)
    const bucketInput = page.locator('input[name="drivers.s3_bucket"]');
    await expect(bucketInput).toHaveCount(0);

    // 공개 자산 디스크만 S3 로 바꾸면 접속 필드가 나타나야 한다
    await openAndReadOptions(page, CORE_SELECT_ROOT);
    await page.locator('[role="listbox"] [role="option"]', { hasText: 'Amazon S3' }).first().click();

    await expect(bucketInput).toBeVisible({ timeout: 20_000 });
    await expect(page.locator('input[name="drivers.s3_url"]')).toBeVisible();
    await expect(page.locator('input[name="drivers.s3_endpoint"]')).toBeVisible();

    // 저장하지 않고 이탈 — 사이트 설정을 바꾸지 않는다
    await gotoDriversTab(page);
  });
});

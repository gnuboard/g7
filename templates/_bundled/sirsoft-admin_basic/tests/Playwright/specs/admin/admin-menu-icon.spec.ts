/**
 * sirsoft-admin_basic — 관리자 메뉴 구조형 아이콘(icon_spec) 브라우저 계약 (#381).
 *
 * 결함(수정 전): 메뉴 아이콘은 FA 클래스 문자열만 그려 이미지·SVG 는 저장돼도 사이드바·메뉴 관리 화면에 빈 칸이었고,
 *   메뉴 관리 화면의 Icon 은 `fas fa-x` 를 `fas fa-fas fa-x` 로 조합했다. 구현 중 실측에서는 직접 입력에 외부 주소를
 *   한 글자씩 치면 막히기 전 중간 글자가 폼 값으로 남는 결함도 나왔다.
 *
 * 이 spec 은 실제 브라우저에서 ① SVG 아이콘 메뉴가 사이드바·보기 판에 인라인 svg 로 그려지고 ② 메뉴 관리 화면에 이중 접두
 *   클래스가 없으며 ③ 편집 필드 직접 입력에 외부 주소를 키보드로 쳐도 폼 값이 입력 전 값으로 남는지 잰다. 컴포넌트 시험은
 *   jsdom 합성 이벤트라 키 입력 순서·엔진 폼 바인딩·정적 번들 반영은 여기서만 확인된다.
 *
 * 시나리오 매니페스트: `tests/scenarios/menu-icon-rendering.yaml`
 */
import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect, issueToken, authenticatePage } from '../../fixtures/admin-template-auth';
import type { Page } from '@playwright/test';

/** 코어 루트 — 이 spec 에서 7단계 위 */
const G7_ROOT = process.env.G7_ROOT || resolve(dirname(fileURLToPath(import.meta.url)), '../../../../../../../');
const MENU_API = '/api/admin/menus';
const SVG = '<svg viewBox="0 0 24 24"><path fill="currentColor" d="M12 2 2 22h20z"/></svg>';

/**
 * 페이지 문맥에서 인증 API 를 부릅니다.
 *
 * @param page 페이지
 * @param method HTTP 메서드
 * @param path API 경로
 * @param body 본문
 * @returns 상태 코드와 본문
 */
async function api(page: Page, method: string, path: string, body?: unknown): Promise<{ status: number; json: any }> {
  return page.evaluate(
    async ({ method, path, body }) => {
      const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
      const res = await fetch(path, {
        method,
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
          'X-XSRF-TOKEN': xsrf,
        },
        body: body === undefined ? undefined : JSON.stringify(body),
      });
      let json: any = null;
      try {
        json = await res.json();
      } catch {
        json = null;
      }
      return { status: res.status, json };
    },
    { method, path, body },
  );
}

test.describe('관리자 메뉴 구조형 아이콘 (#381)', () => {
  test.describe.configure({ timeout: 90_000 });

  /**
   * SVG 아이콘 메뉴의 사이드바·보기 판 렌더, 이중 접두 0, 키보드로 친 외부 주소의 입력 전 값 복원.
   *
   * @scenario surface=sidebar, icon_type=svg
   * @effects structured_icon_renders_per_type, svg_sanitized_inline, layout_view_panel_renders_menu_icon, external_url_blocked_in_direct_input
   */
  test('SVG 아이콘 메뉴가 사이드바·보기 판에 그려지고, 외부 주소 직접 입력은 입력 전 값을 남긴다', async ({ page }) => {
    const token = issueToken('core.menus.read', 'core.menus.create', 'core.menus.update', 'core.menus.delete');
    await authenticatePage(page, token);
    await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
    await page.goto('/admin/menus');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const slug = `e2e-381-svg-${Date.now()}`;
    const created = await api(page, 'POST', MENU_API, {
      name: { ko: 'E2E 381 SVG' },
      slug,
      url: `/admin/${slug}`,
      icon_spec: { type: 'svg', value: SVG },
    });
    expect(created.status).toBe(201);
    expect(created.json?.data?.icon).toBeNull();
    const menuId = created.json?.data?.id as number;

    try {
      await page.goto('/admin/menus');

      // ① 사이드바 — span.menu-icon-media 안 svg 1개, 크기가 있다
      const sidebarSvg = page.locator(`a[href$="/admin/${slug}"] .admin-sidebar-icon-box span.menu-icon-media > svg`);
      await expect(sidebarSvg).toHaveCount(1, { timeout: 20_000 });
      // 개수가 맞은 직후엔 아직 배치 전일 수 있다 — 폭이 생길 때까지 기다려 잰다
      await expect.poll(async () => (await sidebarSvg.boundingBox())?.width ?? 0, { timeout: 10_000 }).toBeGreaterThan(0);

      // ① 보기 판 — 목록 행을 눌러(실사용 경로) 열고 인라인 svg + 유형 라벨을 본다
      await page.locator(`a[href*="menu=${slug}&mode=view"]`).first().click({ timeout: 20_000 });
      await expect(page.locator('#view_menu_icon_label')).toHaveText('SVG 코드', { timeout: 20_000 });
      await expect(page.locator('#view_menu_icon > svg')).toHaveCount(1);

      // ② 이중 접두 클래스 없음
      const doubled = await page.evaluate(
        () => [...document.querySelectorAll('i')].filter((i) => /\bfa-(fas|far|fab|fal|fad)\b/.test(i.className)).length,
      );
      expect(doubled).toBe(0);

      // ③ 편집 → 직접 입력에 외부 주소를 키보드로 친다
      await page.getByRole('button', { name: '편집', exact: true }).click({ timeout: 20_000 });
      await page.getByRole('tab', { name: '직접 입력' }).click({ timeout: 20_000 });
      const input = page.getByRole('textbox', { name: '아이콘 직접 입력' });
      await input.click();
      await input.press('Control+A');
      await input.press('Delete');
      await page.keyboard.type('https://example.com/x.png');

      await expect(page.getByText('외부 주소(http·https·//)는 쓸 수 없습니다', { exact: false })).toBeVisible();
      const spec = await page.evaluate(() => {
        const st = (window as any).G7Core?.state?.get?.() ?? {};
        return (st._global?.formData ?? st.formData ?? {}).icon_spec ?? null;
      });
      // 지운 직후 빈 입력은 null(지우기)로 내보내지만, 막히는 순간 입력 전 값(SVG)으로 되돌린다
      expect(spec?.type).toBe('svg');
    } finally {
      await api(page, 'DELETE', `${MENU_API}/${menuId}`);
    }
  });

  /**
   * 편집 폼 미리보기는 32px 로 그려지고, 편집하던 메뉴에서 다른 메뉴로 바꿔 편집하면 「직접 입력」 칸이 새 메뉴 값으로
   * 다시 맞춰진다(이전 메뉴 클래스 글자 잔존 0 — #381 validate-plan O3).
   *
   * @scenario surface=form_field, icon_type=class, icon_type=svg
   * @effects form_inputs_follow_external_value
   */
  test('편집 미리보기는 32px 이고, 다른 메뉴로 바꿔 편집하면 직접 입력 칸에 이전 메뉴 글자가 남지 않는다', async ({ page }) => {
    const token = issueToken('core.menus.read', 'core.menus.create', 'core.menus.update', 'core.menus.delete');
    await authenticatePage(page, token);
    await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
    await page.goto('/admin/menus');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const stamp = Date.now();
    const classSlug = `e2e-381-cls-${stamp}`;
    const svgSlug = `e2e-381-svg2-${stamp}`;
    const cls = await api(page, 'POST', MENU_API, {
      name: { ko: 'E2E 381 CLASS' },
      slug: classSlug,
      url: `/admin/${classSlug}`,
      icon_spec: { type: 'class', value: 'fas fa-rocket' },
    });
    const svg = await api(page, 'POST', MENU_API, {
      name: { ko: 'E2E 381 SVG2' },
      slug: svgSlug,
      url: `/admin/${svgSlug}`,
      icon_spec: { type: 'svg', value: SVG },
    });
    expect(cls.status).toBe(201);
    expect(svg.status).toBe(201);

    try {
      await page.goto('/admin/menus');

      // 클래스 메뉴 편집 — 미리보기 32px, 직접 입력 칸에 자기 클래스
      await page.locator(`a[href*="menu=${classSlug}&mode=view"]`).first().click({ timeout: 20_000 });
      await page.getByRole('button', { name: '편집', exact: true }).click({ timeout: 20_000 });
      const previewIcon = page.locator('.menu-icon-field [role="img"] i');
      await expect(previewIcon).toHaveCount(1, { timeout: 20_000 });
      const box = await previewIcon.boundingBox();
      expect(Math.round(box?.height ?? 0)).toBe(32);
      await page.getByRole('tab', { name: '직접 입력' }).click();
      await expect(page.getByRole('textbox', { name: '아이콘 직접 입력' })).toHaveValue('fas fa-rocket');

      // 다른(SVG) 메뉴로 바꿔 편집 — 직접 입력 칸은 비어야 한다
      // 편집 중 다른 메뉴를 고르면 이탈 확인 창이 뜬다(편집 중 이탈 가드) — 실사용 경로대로 「변경사항 버리기」
      await page.locator(`a[href*="menu=${svgSlug}&mode=view"]`).first().click({ timeout: 20_000 });
      await page.getByRole('button', { name: '변경사항 버리기' }).click({ timeout: 20_000 });
      await expect(page.locator('#view_menu_icon_label')).toHaveText('SVG 코드', { timeout: 20_000 });
      await page.getByRole('button', { name: '편집', exact: true }).click({ timeout: 20_000 });
      await expect(page.locator('.menu-icon-field [role="img"] svg')).toHaveCount(1, { timeout: 20_000 });
      const svgBox = await page.locator('.menu-icon-field [role="img"] svg').boundingBox();
      expect(Math.round(svgBox?.height ?? 0)).toBe(32);
      await page.getByRole('tab', { name: '직접 입력' }).click();
      await expect(page.getByRole('textbox', { name: '아이콘 직접 입력' })).toHaveValue('');
    } finally {
      await api(page, 'DELETE', `${MENU_API}/${cls.json?.data?.id}`);
      await api(page, 'DELETE', `${MENU_API}/${svg.json?.data?.id}`);
    }
  });

  /**
   * 업로드가 끝나면 파일 칸을 비워 같은 파일을 다시 올릴 수 있고, 업로드로 받은 SVG 는 「SVG 코드」
   * 칸에 보이며 바꾸기 전 클래스 글자는 「직접 입력」 칸에서 사라진다. 예전 API 로 저장된 비클래스 아이콘값이 남은
   * 메뉴도 아이콘을 건드리지 않은 수정은 저장된다(수정 전에는 아이콘 칸 422).
   *
   * @scenario surface=form_field, icon_type=svg, icon_type=legacy_string
   * @effects upload_input_reset_after_attempt, form_inputs_follow_external_value
   */
  test('같은 SVG 파일을 다시 올릴 수 있고 탭 칸이 새 값을 따르며, 옛 비클래스 아이콘 메뉴도 아이콘 외 수정이 저장된다', async ({ page }) => {
    const token = issueToken('core.menus.read', 'core.menus.create', 'core.menus.update', 'core.menus.delete');
    await authenticatePage(page, token);
    await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
    await page.goto('/admin/menus');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const stamp = Date.now();
    const slug = `e2e-381-up-${stamp}`;
    const created = await api(page, 'POST', MENU_API, {
      name: { ko: 'E2E 381 UPLOAD' },
      slug,
      url: `/admin/${slug}`,
      icon_spec: { type: 'class', value: 'fas fa-rocket' },
    });
    expect(created.status).toBe(201);
    const id = created.json?.data?.id;

    try {
      await page.goto('/admin/menus');
      await page.locator(`a[href*="menu=${slug}&mode=view"]`).first().click({ timeout: 20_000 });
      await page.getByRole('button', { name: '편집', exact: true }).click({ timeout: 20_000 });

      const uploads: string[] = [];
      page.on('request', (req) => {
        if (req.method() === 'POST' && req.url().includes('/api/admin/menus/icon-upload')) uploads.push(req.url());
      });
      const svgFile = { name: 'icon.svg', mimeType: 'image/svg+xml', buffer: Buffer.from(SVG) };
      const fileInput = page.locator('.menu-icon-field input[type="file"]');

      await page.getByRole('tab', { name: '업로드' }).click();
      await fileInput.setInputFiles(svgFile);
      await expect(page.locator('.menu-icon-field [role="img"] svg')).toHaveCount(1, { timeout: 20_000 });
      await expect.poll(() => fileInput.evaluate((el: HTMLInputElement) => el.files?.length ?? -1)).toBe(0);
      await fileInput.setInputFiles(svgFile);
      await expect.poll(() => uploads.length, { timeout: 20_000 }).toBe(2);

      await page.getByRole('tab', { name: 'SVG 코드' }).click();
      await expect(page.locator('.menu-icon-field textarea')).toHaveValue(SVG);
      await page.getByRole('tab', { name: '직접 입력' }).click();
      await expect(page.getByRole('textbox', { name: '아이콘 직접 입력' })).toHaveValue('');

      // 예전 API 가 200 으로 받던 비클래스 문자열을 저장값에 직접 넣는다(현행 API 는 422 라 DB 로만 재현된다)
      execFileSync('php', ['artisan', 'tinker', `--execute=App\\Models\\Menu::where('slug','${slug}')->toBase()->update(['icon'=>'https://cdn.example.com/legacy.png']);`], {
        cwd: G7_ROOT,
        encoding: 'utf-8',
      });
      await page.goto('/admin/menus');
      await page.locator(`a[href*="menu=${slug}&mode=view"]`).first().click({ timeout: 20_000 });
      await page.getByRole('button', { name: '편집', exact: true }).click({ timeout: 20_000 });
      await page.locator('input[name="url"]').fill(`/admin/${slug}-renamed`);
      const put = page.waitForResponse((res) => res.request().method() === 'PUT' && res.url().includes(`${MENU_API}/${id}`), { timeout: 20_000 });
      await page.getByRole('button', { name: '저장', exact: true }).click();
      expect((await put).status()).toBe(200);
      const after = await api(page, 'GET', `${MENU_API}/${id}`);
      expect(after.json?.data?.url).toBe(`/admin/${slug}-renamed`);
      expect(after.json?.data?.icon_spec).toEqual({ type: 'class', value: 'https://cdn.example.com/legacy.png' });
    } finally {
      await api(page, 'DELETE', `${MENU_API}/${id}`);
    }
  });

  /**
   * validate-plan 5차: ⓐ 직접 입력에 외부 주소를 붙여넣어 막힌 뒤 선택 탭에서 지금과 같은 아이콘을 고르면 직접 입력 칸이
   * 그 아이콘으로 맞춰지고 빨간 문구가 사라진다 ⓑ 업로드 오류 문구는 값을 바꾸면 사라진다 ⓒ(C-5a) 예전 API 로 저장된 JSON
   * 모양 아이콘 문자열의 외부 주소는 화면에 내보내지 않는다 — 사이드바가 외부 서버로 이미지를 요청하지 않고 대체 아이콘을 그린다.
   *
   * @scenario surface=form_field, surface=sidebar, icon_type=class, icon_type=legacy_string
   * @effects form_inputs_follow_external_value, upload_failure_shows_message, structured_icon_renders_per_type
   */
  test('막힌 직접 입력·업로드 오류가 값 변경을 따라 정리되고, 옛 JSON 모양 외부 주소 아이콘은 외부 요청 없이 대체 아이콘으로 그려진다', async ({ page }) => {
    const token = issueToken('core.menus.read', 'core.menus.create', 'core.menus.update', 'core.menus.delete');
    await authenticatePage(page, token);
    await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
    await page.goto('/admin/menus');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const stamp = Date.now();
    const slug = `e2e-381-r5-${stamp}`;
    const legacySlug = `e2e-381-r5x-${stamp}`;
    const created = await api(page, 'POST', MENU_API, {
      name: { ko: 'E2E 381 R5' },
      slug,
      url: `/admin/${slug}`,
      icon_spec: { type: 'class', value: 'fas fa-users' },
    });
    const legacy = await api(page, 'POST', MENU_API, {
      name: { ko: 'E2E 381 R5 LEGACY' },
      slug: legacySlug,
      url: `/admin/${legacySlug}`,
      icon_spec: { type: 'class', value: 'fas fa-users' },
    });
    expect(created.status).toBe(201);
    expect(legacy.status).toBe(201);

    try {
      await page.goto('/admin/menus');
      await page.locator(`a[href*="menu=${slug}&mode=view"]`).first().click({ timeout: 20_000 });
      await page.getByRole('button', { name: '편집', exact: true }).click({ timeout: 20_000 });

      // ⓐ 붙여넣기로 막힌 외부 주소 → 선택 탭에서 같은 Users → 직접 입력 칸 = fas fa-users, 빨간 문구 없음
      await page.getByRole('tab', { name: '직접 입력' }).click();
      const input = page.getByRole('textbox', { name: '아이콘 직접 입력' });
      await input.fill('https://x.example/a.png');
      await expect(page.getByText('외부 주소(http·https·//)는 쓸 수 없습니다', { exact: false })).toBeVisible();
      await page.getByRole('tab', { name: '선택' }).click();
      await page.locator('.menu-icon-field button[aria-haspopup="listbox"]').click();
      await page.locator('.menu-icon-field').getByText('Users', { exact: true }).last().click();
      await page.getByRole('tab', { name: '직접 입력' }).click();
      await expect(input).toHaveValue('fas fa-users');
      await expect(page.getByText('외부 주소(http·https·//)는 쓸 수 없습니다', { exact: false })).toHaveCount(0);

      // ⓑ 1MB 넘는 파일 → 업로드 오류 → 아이콘 지우기 → 업로드 탭에 오류 문구 없음
      await page.getByRole('tab', { name: '업로드' }).click();
      const big = { name: 'big.png', mimeType: 'image/png', buffer: Buffer.alloc(1_200_000, 1) };
      const upload = page.waitForResponse((res) => res.url().includes('/api/admin/menus/icon-upload'), { timeout: 20_000 });
      await page.locator('.menu-icon-field input[type="file"]').setInputFiles(big);
      expect((await upload).status()).toBe(422);
      const uploadAlert = page.locator('.menu-icon-field [role="alert"]');
      await expect(uploadAlert).toHaveCount(1, { timeout: 10_000 });
      await page.getByRole('button', { name: '아이콘 지우기' }).click();
      await page.getByRole('tab', { name: '업로드' }).click();
      await expect(uploadAlert).toHaveCount(0);

      // ⓒ 옛 API 가 200 으로 받던 JSON 모양 외부 주소 아이콘(현행 API 는 422 라 DB 로만 재현)
      const json = JSON.stringify({ path: '//evil.example/p.png', source: 'site', type: 'image' });
      execFileSync('php', ['artisan', 'tinker', `--execute=App\\Models\\Menu::where('slug','${legacySlug}')->toBase()->update(['icon'=>'${json}']);`], {
        cwd: G7_ROOT,
        encoding: 'utf-8',
      });
      const external: string[] = [];
      page.on('request', (req) => {
        if (req.url().includes('evil.example')) external.push(req.url());
      });
      await page.goto('/admin/menus');
      const legacyItem = page.locator(`a[href$="/admin/${legacySlug}"] .admin-sidebar-icon-box`);
      await expect(legacyItem.locator('i.fa-circle')).toHaveCount(1, { timeout: 20_000 });
      await expect(legacyItem.locator('img')).toHaveCount(0);
      const shown = await api(page, 'GET', `${MENU_API}/${legacy.json?.data?.id}`);
      expect(shown.json?.data?.icon_spec?.url ?? null).toBeNull();
      expect(external).toEqual([]);
    } finally {
      await api(page, 'DELETE', `${MENU_API}/${created.json?.data?.id}`);
      await api(page, 'DELETE', `${MENU_API}/${legacy.json?.data?.id}`);
    }
  });

  /**
   * 6차: 두 입력 칸 서로 맞춤(O-6a) · 업로드 중 저장 잠금(O-6b) · 경로 입력 중 미리보기 요청 억제(O-6c) ·
   * 옛 위험 SVG 저장값은 대체 아이콘으로 그리고 이름만 고친 저장이 통과(D-6a).
   *
   * @scenario surface=form_field, icon_type=svg
   * @effects form_inputs_follow_external_value, upload_in_progress_blocks_save, form_preview_waits_for_typing_pause, invalid_icon_falls_back
   */
  test('두 입력 칸이 서로 맞춰지고, 업로드 중에는 저장이 잠기며, 경로를 치는 동안 이미지 요청이 없고, 옛 위험 SVG 는 대체 아이콘으로 그려진다', async ({ page }) => {
    const token = issueToken('core.menus.read', 'core.menus.create', 'core.menus.update', 'core.menus.delete');
    await authenticatePage(page, token);
    await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
    await page.goto('/admin/menus');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const stamp = Date.now();
    const slug = `e2e-381-r6-${stamp}`;
    const legacySlug = `e2e-381-r6x-${stamp}`;
    const created = await api(page, 'POST', MENU_API, { name: { ko: 'E2E 381 R6' }, slug, url: `/admin/${slug}`, icon_spec: { type: 'class', value: 'fas fa-users' } });
    const legacy = await api(page, 'POST', MENU_API, { name: { ko: 'E2E 381 R6 LEGACY' }, slug: legacySlug, url: `/admin/${legacySlug}`, icon_spec: { type: 'class', value: 'fas fa-users' } });
    expect(created.status).toBe(201);
    expect(legacy.status).toBe(201);

    try {
      await page.goto('/admin/menus');
      await page.locator(`a[href*="menu=${slug}&mode=view"]`).first().click({ timeout: 20_000 });
      await page.getByRole('button', { name: '편집', exact: true }).click({ timeout: 20_000 });

      // O-6a 직접 입력에 막힌 외부 주소 → SVG 칸에 코드 → 직접 입력 칸은 비고 빨간 문구 없음 / 반대로 클래스를 치면 SVG 칸이 빈다
      const input = page.getByRole('textbox', { name: '아이콘 직접 입력' });
      await page.getByRole('tab', { name: '직접 입력' }).click();
      await input.fill('https://a');
      await expect(page.getByText('외부 주소(http·https·//)는 쓸 수 없습니다', { exact: false })).toBeVisible();
      await page.getByRole('tab', { name: 'SVG 코드' }).click();
      const svgBox = page.locator('.menu-icon-field textarea');
      await svgBox.fill(SVG);
      await page.getByRole('tab', { name: '직접 입력' }).click();
      await expect(input).toHaveValue('');
      await expect(page.getByText('외부 주소(http·https·//)는 쓸 수 없습니다', { exact: false })).toHaveCount(0);
      await input.fill('fas fa-star');
      await page.getByRole('tab', { name: 'SVG 코드' }).click();
      await expect(svgBox).toHaveValue('');

      // O-6c 경로를 한 글자씩 치는 동안 미리보기 이미지 요청 없음 — 멈춘 뒤 한 번만
      const typed: string[] = [];
      page.on('request', (req) => {
        if (req.url().includes('e2e381typing')) typed.push(req.url());
      });
      await page.getByRole('tab', { name: '직접 입력' }).click();
      await input.fill('');
      await input.pressSequentially('/e2e381typing.png', { delay: 40 });
      await page.waitForTimeout(1_200);
      expect(typed.length).toBeLessThanOrEqual(1);

      // O-6b 업로드 응답이 늦는 동안 저장 단추가 잠기고, 끝나면 풀린다 — 끝난 업로드는 이 메뉴에 저장(종료 시 메뉴 삭제로 첨부 정리)
      const save = page.locator('button[type="submit"][form="menu_form"]');
      await page.route('**/api/admin/menus/icon-upload', async (route) => {
        await new Promise((r) => setTimeout(r, 1_500));
        await route.continue();
      });
      await page.getByRole('tab', { name: '업로드' }).click();
      const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==', 'base64');
      const uploadDone = page.waitForResponse((res) => res.url().includes('/api/admin/menus/icon-upload'), { timeout: 20_000 });
      await page.locator('.menu-icon-field input[type="file"]').setInputFiles({ name: 'r6.png', mimeType: 'image/png', buffer: png });
      await expect(save).toBeDisabled({ timeout: 1_000 });
      expect((await uploadDone).status()).toBe(200);
      await expect(save).toBeEnabled({ timeout: 10_000 });
      await page.unroute('**/api/admin/menus/icon-upload');
      const saved = page.waitForResponse((res) => res.url().includes(`${MENU_API}/${created.json?.data?.id}`) && res.request().method() === 'PUT', { timeout: 20_000 });
      await save.click();
      expect((await saved).status()).toBe(200);
      // 저장 성공 처리(목록 두 개를 다시 불러온 뒤 보기 판으로)가 끝날 때까지 — 그 전에 떠나면 편집 중 이탈 확인 창이 뜬다
      await expect(page.getByRole('button', { name: '편집', exact: true })).toBeVisible({ timeout: 20_000 });

      // D-6a 옛 API 가 받던 JSON 모양 위험 SVG(현행 API 는 422 라 DB 로만 재현) → 사이드바 대체 아이콘, svg 없음
      const json = JSON.stringify({ type: 'svg', value: '<svg onload="alert(1)"></svg>' });
      execFileSync('php', ['artisan', 'tinker', `--execute=App\\Models\\Menu::where('slug','${legacySlug}')->toBase()->update(['icon'=>'${json.replace(/'/g, "\\'")}']);`], { cwd: G7_ROOT, encoding: 'utf-8' });
      const dialogs: string[] = [];
      page.on('dialog', async (d) => {
        dialogs.push(`${d.type()}:${d.message()}`);
        await d.accept();
      });
      await page.goto('/admin/menus');
      const legacyItem = page.locator(`a[href$="/admin/${legacySlug}"] .admin-sidebar-icon-box`);
      await expect(legacyItem.locator('i.fa-circle')).toHaveCount(1, { timeout: 20_000 });
      await expect(legacyItem.locator('svg')).toHaveCount(0);

      // 이름만 고친 저장은 통과하고 저장값은 그대로(응답은 계속 value null)
      await page.locator(`a[href*="menu=${legacySlug}&mode=view"]`).first().click({ timeout: 20_000 });
      await page.getByRole('button', { name: '편집', exact: true }).click({ timeout: 20_000 });
      await page.locator('input[name="name.ko"], input[name="name[ko]"]').first().fill('E2E 381 R6 LEGACY 이름만');
      const renamed = page.waitForResponse((res) => res.url().includes(`${MENU_API}/${legacy.json?.data?.id}`) && res.request().method() === 'PUT', { timeout: 20_000 });
      await save.click();
      expect((await renamed).status()).toBe(200);
      const shown = await api(page, 'GET', `${MENU_API}/${legacy.json?.data?.id}`);
      expect(shown.json?.data?.icon_spec).toEqual({ type: 'svg', value: null });
      expect(dialogs).toEqual([]);
    } finally {
      await page.unroute('**/api/admin/menus/icon-upload').catch(() => undefined);
      await api(page, 'DELETE', `${MENU_API}/${created.json?.data?.id}`);
      await api(page, 'DELETE', `${MENU_API}/${legacy.json?.data?.id}`);
    }
  });
});

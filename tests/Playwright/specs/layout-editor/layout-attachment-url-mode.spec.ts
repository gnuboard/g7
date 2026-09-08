/**
 * Layout Editor — 레이아웃 첨부 URL 발급 모드 왕복 (공개 #134).
 *
 * 결함: 업로드 응답의 `url` 이 스토리지 설정과 무관하게 항상 공개 서빙 라우트(프록시)로
 * 발급되어, 공개 자산 디스크(S3+CDN)를 설정해도 방문자 요청이 매번 오리진 PHP 를 거쳤다.
 *
 * 이 spec 은 첨부를 **실제로 업로드해 왕복**한다 — 기존 background-image-render.spec 은
 * URL 을 손으로 입력할 뿐 업로드 경로를 타지 않아 이 결함을 원리상 포착할 수 없었다.
 * 기본 설치(공개 자산 디스크 미설정) 상태에서 업로드 응답 URL 이 프록시 형태이고 그
 * 주소가 인증 없이 200 으로 열리는지, 그리고 그 URL 이 위젯 값·캔버스에 그대로
 * 반영되는지를 본다. 직접 URL(CDN) 축은 서버 설정 변경이 필요하므로 브라우저 실측
 * 매트릭스가 담당하고, 여기서는 서버 응답 형태를 가공 없이 보존하는지를 고정한다.
 *
 * 축 요약(마커 아님 — 평문): public_asset_disk=unset, row_disk=attachments,
 * filter_url_hook=absent.
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import { bodyRootPath } from '../../fixtures/layout-editor';
import { SANDBOX_ROOT_ID, SANDBOX_ROUTE, sandboxRouteParam } from '../../fixtures/seed-layout';
import type { Page } from '@playwright/test';

/** 1x1 투명 PNG (base64) — 업로드 픽스처 */
const PNG_BASE64 =
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

/**
 * 캔버스의 배경이 보일 만한 박스형 Div 영역을 골라 path 를 돌려준다.
 * 좌측 라우트 트리 패널과 겹치지 않도록 캔버스 안쪽(left > 360)으로 한정한다.
 */
async function pickBoxAreaPath(page: Page): Promise<string | null> {
  return page.evaluate(() => {
    const cands = Array.from(
      document.querySelectorAll('[data-editor-path][data-editor-name="Div"]'),
    ).filter((el) => {
      const r = el.getBoundingClientRect();
      return r.width > 200 && r.width < 800 && r.height > 80 && r.height < 320 && r.left > 360;
    });
    return cands[0]?.getAttribute('data-editor-path') ?? null;
  });
}

/** 캔버스 위임 click 핸들러로 노드를 선택한다(오버레이가 좌표를 가로채는 것 회피). */
async function selectNode(page: Page, path: string): Promise<void> {
  await page.evaluate((p) => {
    const el = document.querySelector(`[data-editor-path="${p}"]`);
    if (!el) return;
    el.scrollIntoView({ block: 'center' });
    const r = el.getBoundingClientRect();
    const cx = r.left + r.width / 2;
    const cy = r.top + r.height / 2;
    const types = [
      'pointerover',
      'pointermove',
      'pointerdown',
      'mousedown',
      'pointerup',
      'mouseup',
      'click',
    ];
    for (const type of types) {
      el.dispatchEvent(new MouseEvent(type, { bubbles: true, clientX: cx, clientY: cy }));
    }
  }, path);
}

async function openStyleTab(page: Page): Promise<void> {
  await page.waitForSelector('[data-testid="g7le-overlay-info-button"]', { timeout: 10_000 });
  await page.getByTestId('g7le-overlay-info-button').click();
  await page.waitForSelector('[data-testid="g7le-context-menu-edit-props"]', { timeout: 5_000 });
  await page.getByTestId('g7le-context-menu-edit-props').click();
  await page.waitForSelector('[data-testid="g7le-property-modal"]', { timeout: 10_000 });
  await page.getByTestId('g7le-property-tab-style').click();
}

async function enterEditor(page: Page): Promise<void> {
  await page.goto('/admin/layout-editor/sirsoft-basic?route=%2F');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });
  await page.waitForFunction(() => document.querySelectorAll('[data-editor-path]').length > 0, {
    timeout: 20_000,
  });
}

/** 첨부 1건을 업로드하고 `{ id, url }` 을 돌려준다. */
async function uploadAttachment(
  page: Page,
  token: string,
  filename: string,
): Promise<{ id: number | null; url: string | null; status: number }> {
  return page.evaluate(
    async ({ b64, bearer, name }) => {
      const bin = atob(b64);
      const bytes = new Uint8Array(bin.length);
      for (let i = 0; i < bin.length; i += 1) bytes[i] = bin.charCodeAt(i);
      const form = new FormData();
      form.append('file', new File([bytes], name, { type: 'image/png' }), name);
      form.append('layout_name', 'home');
      const res = await fetch('/api/admin/templates/sirsoft-basic/layout-attachments', {
        method: 'POST',
        headers: { Authorization: `Bearer ${bearer}`, Accept: 'application/json' },
        body: form,
        credentials: 'same-origin',
      });
      const body = await res.json().catch(() => null);
      return { status: res.status, id: body?.data?.id ?? null, url: body?.data?.url ?? null };
    },
    { b64: PNG_BASE64, bearer: token, name: filename },
  );
}

/** 이 spec 이 만든 첨부를 지운다 (공유 사이트에 잔여물을 남기지 않는다). */
async function deleteAttachment(page: Page, token: string, id: number | null): Promise<void> {
  if (id === null) return;
  await page.evaluate(
    async ({ attachmentId, bearer }) => {
      await fetch(`/api/admin/templates/layout-attachments/${attachmentId}`, {
        method: 'DELETE',
        headers: { Authorization: `Bearer ${bearer}`, Accept: 'application/json' },
        credentials: 'same-origin',
      });
    },
    { attachmentId: id, bearer: token },
  );
}

test.describe('@layout-editor 레이아웃 첨부 URL 발급 모드', () => {
  /**
   * @scenario public_asset_disk=unset,row_disk=attachments,filter_url_hook=absent
   *
   * @effects proxy_url_otherwise
   */
  test('업로드 → 응답 URL 이 프록시 형태이고 인증 없이 열린다', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    // 이 축은 서버 응답 형태만 보므로 편집기 진입이 필요 없다 — same-origin 페이지면 충분하다.
    await page.goto('/');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const uploaded = await uploadAttachment(page, token, 'e2e-url-mode.png');

    expect(uploaded.status).toBe(200);
    expect(uploaded.url).toBeTruthy();

    // 공개 자산 디스크 미설정 = 기본 설치 → 공개 서빙 라우트(프록시) 형태
    expect(uploaded.url as string).toContain(`/layout-attachments/${uploaded.id}/file`);

    // 그 주소는 인증 없이 이미지로 열려야 한다 (발행 배경은 방문자에게 로드된다)
    const served = await page.evaluate(async (url: string) => {
      const res = await fetch(url, { credentials: 'omit' });
      return { status: res.status, type: res.headers.get('content-type') };
    }, uploaded.url as string);
    expect(served.status).toBe(200);
    expect(served.type ?? '').toContain('image/');

    await deleteAttachment(page, token, uploaded.id);
  });

  /**
   * 서버가 준 URL 문자열이 위젯 값과 캔버스 배경에 가공 없이 반영돼야 한다.
   * 상대 경로를 가정해 접두사를 붙이면 직접 URL(CDN) 모드에서 주소가 깨진다.
   *
   * @scenario public_asset_disk=unset,row_disk=attachments,filter_url_hook=absent
   *
   * @effects editor_thumbnail_renders_cross_origin
   */
  test('업로드한 첨부 URL 이 위젯 값·캔버스 배경에 그대로 반영된다', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await enterEditor(page);

    const uploaded = await uploadAttachment(page, token, 'e2e-bind.png');
    expect(uploaded.url).toBeTruthy();

    const targetPath = await pickBoxAreaPath(page);
    expect(targetPath).not.toBeNull();
    await selectNode(page, targetPath as string);
    await openStyleTab(page);

    const urlInput = page.getByTestId('g7le-image-url');
    await urlInput.fill(uploaded.url as string);
    await urlInput.blur();

    // 입력칸이 서버 URL 을 그대로 보존
    await expect(urlInput).toHaveValue(uploaded.url as string);

    // 캔버스 inline background-image 에도 같은 주소가 실린다
    await expect
      .poll(
        async () =>
          page.evaluate((p) => {
            const el = document.querySelector(`[data-editor-path="${p}"]`);
            return el ? getComputedStyle(el).backgroundImage : '';
          }, targetPath),
        { timeout: 8_000 },
      )
      .toContain(`/layout-attachments/${uploaded.id}/file`);

    // 저장하지 않았으므로 레이아웃은 그대로. 첨부만 삭제한다.
    await deleteAttachment(page, token, uploaded.id);
  });

  /**
   * 저장까지 왕복해 **방문자 화면이 실제로 요청하는 주소**를 확인한다.
   *
   * 앞의 두 테스트는 업로드 응답과 편집기 화면까지만 본다. 이 결함의 최종 증상은 방문자
   * 요청이 어디로 나가느냐이므로, 저장된 레이아웃이 그리는 화면에서 그 주소가 실제로
   * 요청되고 200 으로 응답하는지까지 봐야 축이 닫힌다.
   *
   * 저장(PUT)은 편집 결과가 그대로 영속되므로 제품 화면(`home`)이 아니라 E2E 전용 시드
   * 화면(`e2e_sandbox`)을 대상으로 한다 — globalSetup 이 매 실행 fixture 원본으로 덮어쓰므로
   * 원복 절차가 필요 없다(원복 자체가 또 한 번의 저장이라 실패 시 잔여물이 남는다).
   *
   * @scenario public_asset_disk=unset,row_disk=attachments,filter_url_hook=absent
   *
   * @effects proxy_url_otherwise, saved_layout_url_is_what_visitor_requests
   */
  test('배경 적용 → 저장 → 방문자 화면이 그 주소를 요청한다', async ({ page }) => {
    test.setTimeout(90_000); // 업로드 + 저장 + 방문자 렌더 합산

    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    await page.goto(`/admin/layout-editor/sirsoft-basic?route=${sandboxRouteParam()}`);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });
    await page.waitForFunction(() => document.querySelectorAll('[data-editor-path]').length > 0, {
      timeout: 20_000,
    });

    const uploaded = await uploadAttachment(page, token, 'e2e-save-roundtrip.png');
    expect(uploaded.url).toBeTruthy();

    // 시드 화면은 본문 컨테이너 id 가 고정이라 후보 순회 없이 곧바로 지목할 수 있다.
    const rootPath = await bodyRootPath(page, SANDBOX_ROOT_ID);
    await selectNode(page, rootPath);
    await openStyleTab(page);

    const urlInput = page.getByTestId('g7le-image-url');
    await urlInput.fill(uploaded.url as string);
    await urlInput.blur();
    await expect(urlInput).toHaveValue(uploaded.url as string);

    // 모달을 확실히 닫은 뒤 툴바 저장 — 모달이 열린 채면 안쪽 버튼이 잡혀 PUT 이 안 난다.
    await page
      .getByTestId('g7le-property-modal-done')
      .click({ timeout: 2_000 })
      .catch(() => undefined);
    await page
      .getByRole('button', { name: /^(닫기|Close)$/ })
      .first()
      .click({ timeout: 3_000 })
      .catch(() => undefined);
    await page.waitForTimeout(300);

    const savePromise = page.waitForResponse(
      (r) =>
        /\/api\/admin\/templates\/sirsoft-basic\/layouts\//.test(r.url()) &&
        r.request().method() === 'PUT',
      { timeout: 20_000 },
    );
    await page.getByTestId('g7le-toolbar-save').click();
    const saveRes = await savePromise;
    expect(saveRes.status()).toBe(200);

    // 방문자 화면 — 저장된 문자열이 실제 요청으로 나가는지 본다.
    const visitor = await page.context().newPage();
    const attachmentRequests: number[] = [];
    visitor.on('response', (res) => {
      if (res.url() === uploaded.url) attachmentRequests.push(res.status());
    });
    await visitor.goto(SANDBOX_ROUTE['sirsoft-basic']);
    await visitor.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await expect
      .poll(() => attachmentRequests.length, { timeout: 15_000 })
      .toBeGreaterThan(0);
    expect(attachmentRequests.every((s) => s === 200)).toBe(true);
    await visitor.close();

    // 시드 화면은 다음 실행에서 fixture 로 덮이므로 첨부만 정리한다.
    await deleteAttachment(page, token, uploaded.id);
  });
});

/**
 * E2E: 엔진이 액션 정의를 옮겨 담을 때 키를 버리지 않는다 (배포 번들 기준, #753 / engine-v1.80.0)
 *
 * `<on listen>`·단일 `<fail>`·`<on-code>`·데이터소스 오류 처리·`<init>` 안의 sequence·conditions·switch·if·
 * action-ref·target 이 경고 없이 버려졌다(키 4개만 옮겨 담음). 단위 시험은 React 커밋·실제 네트워크 실패를
 * 모사하지 못하므로, 배포 번들로 프로브 레이아웃(미리보기 API — DB 행 없음, 자동 만료)을 띄워 버튼마다 결과를 잰다.
 *
 * 실패 경로는 없는 주소(`/api/__probe753_missing`)의 404 와 `page.route` 로 만든 409·500 을 쓴다.
 * 네트워크 실패의 브라우저 리소스 오류 줄(「Failed to load resource」)은 예정된 것이라 콘솔 error 집계에서 뺀다.
 * 미리보기 모드는 navigate 를 억제하므로 navigate 대체 동작은 단위 시험(ActionDispatcher.actionShapePreservation)이 맡는다.
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/engine-action-shape-preservation.yaml 참조.
 */
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { Page } from '@playwright/test';
import { test, expect, issueToken, authenticatePage } from './../fixtures/auth';

const here = dirname(fileURLToPath(import.meta.url));
const MARKUP = readFileSync(resolve(here, '../fixtures/probe753/probe753_markup.g7.html'), 'utf8');
const JSON_LAYOUT = JSON.parse(readFileSync(resolve(here, '../fixtures/probe753/probe753_json.json'), 'utf8'));

/** `#p_state` 의 프로브 상태 — 값은 글자로 맞춰 비교한다(식 결과는 숫자일 수 있다) */
async function probeState(page: Page): Promise<Record<string, string>> {
  const raw = JSON.parse((await page.locator('#p_state').textContent()) || '{}') as Record<string, unknown>;
  return Object.fromEntries(Object.entries(raw).map(([k, v]) => [k, String(v)]));
}

/** 버튼을 누르고 프로브 상태에 기대 키가 생길 때까지 기다린다 */
async function clickAndWait(page: Page, id: string, key: string, value: string): Promise<void> {
  await page.locator(`#${id}`).click();
  await expect.poll(async () => (await probeState(page))[key], { timeout: 10_000 }).toBe(value);
}

/** 예정된 네트워크 실패 줄을 뺀 콘솔 error 수집기 */
function collectErrors(page: Page): string[] {
  const errors: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error' && !m.text().includes('Failed to load resource')) errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push(String(e)));
  return errors;
}

test.describe('액션 모양 보존 (배포 번들)', () => {
  let token: string;
  let markupUrl: string;
  let jsonUrl: string;

  test.beforeAll(async ({ request }) => {
    token = issueToken('core.templates.read');
    const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };

    const a = await request.post('/api/admin/templates/sirsoft-admin_basic/layouts/__probe_753_markup/preview', { headers, data: { format: 'markup', content: MARKUP } });
    expect(a.status()).toBe(200);
    const aBody = await a.json();
    markupUrl = aBody.data.preview_url;
    // 진입점 전용 키 경고(⑭)와 안쪽 debounce 경고(⑫)가 `probe753:lint` 줄에서 난다
    const codes = (aBody.data.markup_warnings ?? []).map((w: { code: string }) => w.code);
    expect(codes).toContain('entry_only_key_ignored');
    expect(codes).toContain('nested_action_debounce_ignored');

    const b = await request.post('/api/admin/templates/sirsoft-admin_basic/layouts/__probe_753_json/preview', { headers, data: { content: JSON_LAYOUT } });
    expect(b.status()).toBe(200);
    jsonUrl = (await b.json()).data.preview_url;
  });

  // @scenario slot=init, shape=sequence
  // @effects init_runs_full_action
  test('<init> 의 sequence·switch·action-ref 가 실행된다', async ({ page }) => {
    const errors = collectErrors(page);
    await authenticatePage(page, token);
    await page.goto(`${markupUrl}?mode=quiet`);
    await expect.poll(async () => probeState(page), { timeout: 30_000 }).toMatchObject({ i1a: '1', i1b: '2', i2: 'quiet', i3: 'named' });
    expect(errors).toEqual([]);
  });

  // @scenario slot=listen, shape=implicit_sequence
  // @effects listen_runs_full_action
  test('<on listen> 의 암묵 sequence·sequence·conditions·switch·if·action-ref·target 이 실행된다', async ({ page }) => {
    const errors = collectErrors(page);
    await authenticatePage(page, token);
    await page.goto(markupUrl);
    await expect(page.locator('#p_state')).toBeVisible({ timeout: 30_000 });

    for (let i = 1; i <= 3; i += 1) {
      await clickAndWait(page, 'p_l1', 'l1count', String(i));
    }
    expect(await probeState(page)).toMatchObject({ l1a: '1', l1b: '2' });
    await clickAndWait(page, 'p_l2', 'l2', 'done');
    await clickAndWait(page, 'p_l3', 'l3', 'b-branch');
    await clickAndWait(page, 'p_l4', 'l4', 'a-case');

    await page.locator('#p_l5f').click();
    await page.waitForTimeout(500);
    expect((await probeState(page)).l5).toBeUndefined();
    await clickAndWait(page, 'p_l5t', 'l5', 'allowed');

    await clickAndWait(page, 'p_l6', 'l6', 'named');

    await page.locator('#p_l7').click();
    await expect.poll(async () => page.evaluate(() => (window as any).G7Core.state.get().modalStack ?? [])).toContain('p_modal');
    expect(errors).toEqual([]);
  });

  // @scenario slot=listen, shape=single
  // @effects event_result_reports_listener_failure
  test('이벤트 결과가 받는 쪽의 실패를 보낸 쪽에 알리고, 보낸 쪽은 멈추지 않는다', async ({ page }) => {
    const errors = collectErrors(page);
    await authenticatePage(page, token);
    await page.goto(markupUrl);
    await expect(page.locator('#p_state')).toBeVisible({ timeout: 30_000 });

    await clickAndWait(page, 'p_l8', 'l8after', '1');
    expect(await probeState(page)).toMatchObject({ l8result: 'false', l8fail: '1' });
    const toasts = await page.evaluate(() => ((window as any).G7Core.state.get().toasts ?? []).length);
    expect(toasts).toBe(0);

    await clickAndWait(page, 'p_l9', 'l9result', 'true');
    expect(errors).toEqual([]);
  });

  // 분기 안·여러 동작 중간의 실패도 보낸 쪽 success:false 다(그 동작의 <fail> 이 처리했어도). 분기 안 set-state 의
  // 병합 상태 스냅숏이 _eventResult.data 로 새면 emit 마다 _local 이 불어난다 — 반복 emit 뒤 크기가 고정이어야 한다.
  // @scenario slot=listen, shape=conditions
  // @effects event_result_reports_listener_failure
  test('분기·중간 동작의 실패를 보낸 쪽에 알리고, 분기 리스너를 되풀이해도 _local 이 불어나지 않는다', async ({ page }) => {
    const errors = collectErrors(page);
    await authenticatePage(page, token);
    await page.goto(markupUrl);
    await expect(page.locator('#p_state')).toBeVisible({ timeout: 30_000 });

    await clickAndWait(page, 'p_k2c', 'k2cafter', '1');
    expect(await probeState(page)).toMatchObject({ k2cresult: 'false', k2cfail: '1' });
    await clickAndWait(page, 'p_k2s', 'k2safter', '1');
    expect(await probeState(page)).toMatchObject({ k2sresult: 'false', k2sfail: '1', k2s1: '1', k2s2: '1' });
    const toasts = await page.evaluate(() => ((window as any).G7Core.state.get().toasts ?? []).length);
    expect(toasts).toBe(0);

    const localSize = () => page.evaluate(() => JSON.stringify((window as any).G7Core.state.get()._local ?? {}).length);
    for (const id of ['p_l3', 'p_l4']) {
      await page.locator(`#${id}`).click();
      await page.waitForTimeout(300);
      const sizes: number[] = [];
      for (let i = 0; i < 5; i += 1) {
        await page.locator(`#${id}`).click();
        await page.waitForTimeout(300);
        sizes.push(await localSize());
      }
      expect(new Set(sizes).size, `${id} sizes ${sizes.join(',')}`).toBe(1);
      const eventResult = await page.evaluate(() => (window as any).G7Core.state.get()._local?._eventResult);
      expect(eventResult).toMatchObject({ success: true, listeners: 1 });
      expect(eventResult.data).toBeUndefined();
    }
    expect(errors).toEqual([]);
  });

  // @scenario slot=fail, shape=conditions
  // @effects fail_runs_full_action
  test('단일 <fail>·<on-code> 의 conditions·switch·if·action-ref 가 상태 코드대로 실행된다 (404·409·500)', async ({ page }) => {
    const errors = collectErrors(page);
    await authenticatePage(page, token);
    await page.goto(markupUrl);
    await expect(page.locator('#p_state')).toBeVisible({ timeout: 30_000 });

    await clickAndWait(page, 'p_f1', 'f1', '404');
    await clickAndWait(page, 'p_f2', 'f2', '404-case');
    await clickAndWait(page, 'p_f4', 'f4', 'named-fail');
    await clickAndWait(page, 'p_f5', 'f5', '404');

    // if 가 false(404 ≠ 500)면 토스트를 띄우지 않는다
    await page.locator('#p_f3').click();
    await page.waitForTimeout(800);
    expect(await page.evaluate(() => ((window as any).G7Core.state.get().toasts ?? []).length)).toBe(0);

    // 409 → conditions else 갈래, 500 → switch default 갈래
    await page.route('**/api/__probe753_missing', (route) => route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ success: false, message: 'conflict' }) }));
    await clickAndWait(page, 'p_f1', 'f1', 'other');
    await page.unroute('**/api/__probe753_missing');
    await page.route('**/api/__probe753_missing', (route) => route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ success: false, message: 'server' }) }));
    await clickAndWait(page, 'p_f2', 'f2', 'default');
    await page.unroute('**/api/__probe753_missing');

    // 다른 코드(인증 없는 관리자 API)는 conditions else 갈래
    await clickAndWait(page, 'p_f6', 'f6', 'other');
    expect(errors).toEqual([]);
  });

  // @scenario slot=datasource_error, shape=conditions
  // @effects datasource_error_reads_route_query
  test('데이터소스 오류 처리가 query 로 가르고, 1개짜리 onError 배열·switch case 배열·action-ref 만 있는 init 이 실행된다', async ({ page }) => {
    const errors = collectErrors(page);
    await authenticatePage(page, token);

    await page.goto(`${jsonUrl}?mode=quiet`);
    await expect.poll(async () => page.evaluate(() => (window as any).G7Core?.state?.get?.()?.probe753Ds), { timeout: 30_000 }).toBe('quiet');
    expect(await probeState(page)).toMatchObject({ ia: 'named', ia_after: '1' });

    await page.goto(`${jsonUrl}?mode=loud`);
    await expect.poll(async () => page.evaluate(() => (window as any).G7Core?.state?.get?.()?.probe753Ds), { timeout: 30_000 }).toBe('loud');

    await clickAndWait(page, 'p_fa1', 'fa1', '404');
    await clickAndWait(page, 'p_sw', 'sw_arr2', '1');
    expect((await probeState(page)).sw_arr).toBe('1');
    expect(errors).toEqual([]);
  });
});

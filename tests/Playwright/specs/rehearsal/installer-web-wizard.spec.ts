/**
 * E2E: 웹 설치 마법사 — 요구사항 [다시 검증]은 GET 으로 다시 열고, 단계별 설치는 지금 도는 단계를 댄다 (7.2.0 W6-004·005·006)
 *
 * 실제 설치되지 않은 사본의 설치 마법사를 처음부터 끝까지 브라우저로 걷는다. 단위 시험(`installer-requirements-failure.test.ts`,
 * `installation-monitor.test.ts`, `installer-ui-state.test.ts`)이 함수 단위를 잠그고, 여기서는 서버가 실제로 내는 단계 순서와
 * 응답 위에서 화면이 무엇을 그리는지 본다.
 *
 * 케이스:
 *   1. [다시 검증] — 2단계 서버 게이트가 거부한 POST 응답 화면(웹 노출 「노출됨」 자기 신고 — 화면 잠금을 우회한 제출)에서
 *      [다시 검증]을 실제로 누르면, 같은 주소를 **GET** 으로 다시 연다. 직전 POST 를 다시 보내지 않고(「양식 다시 제출」 창, 서버
 *      거부 기록 중복이 없다), 다시 열린 화면에는 서버 거부 문구가 없다. 거부 문구는 번역된 문장이다(키 원문 아님).
 *   2. 단계별 설치 — 「CLI 없이 실행」(inprocess → 단계별 강제) + 번들 vendor 로 설치를 끝까지 돌린다. 그동안
 *      - 경과 표시(`#task-elapsed` 「… 실행 중 (N초 경과)」)가 바뀔 때마다 그 이름이 **그 순간 진행 중인 작업 행**이다
 *        (종전: 직전에 끝난 단계 이름, 작업 행은 요청이 도는 내내 대기)
 *      - 진행 중인 행은 한 번에 하나다(되풀이 요청이 다음 대기 행을 차례로 칠하지 않는다)
 *      - 현재 작업 줄(`#current-task`)의 확장 단계 이름은 작업 목록 행 이름과 같다 — 확장 이름과 식별자가 두 번 붙지 않는다
 *        (종전: 「게시판 게시판 모듈 활성화 (sirsoft-board) (sirsoft-board)」)
 *      - 단계 요청을 보낸 뒤 300ms 에 경과 줄이 이미 그 요청의 진행 중 행 이름이다(직전 단계 이름이 남지 않는다)
 *      - 설치가 「설치가 완료되었습니다!」로 끝난다.
 *
 * 환경(이 spec 은 개발 사이트를 재지 않는다 — 설치가 끝난 사이트의 설치 화면은 410 이다):
 *   - `G7_E2E_INSTALLER_URL`        설치되지 않은 사본의 설치 화면 주소(예: `http://127.0.0.1:8731/g7/install/`)
 *   - `G7_E2E_INSTALLER_DB_FILE`    설치에 쓸 빈 시험 DB 자격 JSON 파일 경로 `{host, port, database, username, password}`
 *   - `G7_E2E_ADMIN_EMAIL` + `G7_E2E_ADMIN_PASSWORD_FILE`  만들 최고관리자 계정(비밀번호는 파일로만 받는다)
 *   - `G7_E2E_INSTALLER_EXTENSIONS` 4단계에서 더 고를 확장 식별자(쉼표, 기본 `sirsoft-basic` — 의존 모듈은 화면이 함께 고른다)
 *   하나라도 없거나 설치 화면이 200 이 아니면 사유와 함께 건너뛴다. 2번 케이스는 그 DB 에 실제로 설치한다 — 다시 돌리려면
 *   사본·DB 를 처음 상태로 되돌린다. 비밀값은 화면에 넣기만 하고 출력·첨부하지 않는다.
 *   2번은 1번이 연 같은 브라우저 세션으로 이어 간다(3단계에서 설치 소유권이 그 세션에 묶인다).
 *
 * 수정 전 대조(기본 꺼짐): `G7_E2E_INSTALLER_JS_DIR=<폴더>` 에 수정 전 `installer.js`·`installation-monitor.js`
 *   (`git show HEAD:public/install/assets/js/<파일>`)를 두면 이 브라우저에서만 그 두 파일로 바꿔 끼운다(서버 파일 불변).
 *   바꿔 끼운 응답이 1 이상인지도 단언한다. 수정 전 파일에서는 1·2 가 red 다.
 *
 * @scenario-manifest tests/scenarios/no-cli-installer-stepwise.yaml
 *
 * 실행:
 *   $env:PLAYWRIGHT_BASE_URL='http://127.0.0.1:8731/g7/'
 *   $env:G7_E2E_INSTALLER_URL='http://127.0.0.1:8731/g7/install/'
 *   $env:G7_E2E_INSTALLER_DB_FILE='<DB 자격 JSON>'; $env:G7_E2E_ADMIN_EMAIL='<이메일>'; $env:G7_E2E_ADMIN_PASSWORD_FILE='<비밀번호 파일>'
 *   npx playwright test --config=tests/Playwright/rehearsal.config.ts tests/Playwright/specs/rehearsal/installer-web-wizard.spec.ts
 */
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { test, expect, type Browser, type BrowserContext, type Page, type Request } from '@playwright/test';

test.describe.configure({ mode: 'serial' });

const INSTALLER = process.env.G7_E2E_INSTALLER_URL || '';
const DB_FILE = process.env.G7_E2E_INSTALLER_DB_FILE || '';
const ADMIN_EMAIL = process.env.G7_E2E_ADMIN_EMAIL || '';
const ADMIN_PASSWORD_FILE = process.env.G7_E2E_ADMIN_PASSWORD_FILE || '';
const EXTENSIONS = (process.env.G7_E2E_INSTALLER_EXTENSIONS ?? 'sirsoft-basic').split(',').map((s) => s.trim()).filter(Boolean);
const HEAD_JS_DIR = process.env.G7_E2E_INSTALLER_JS_DIR || '';

/** 설치 화면 주소(끝 슬래시 포함) */
const INSTALL_URL = INSTALLER.endsWith('/') || INSTALLER === '' ? INSTALLER : `${INSTALLER}/`;

/** 경과 표시 문구(`stepwise_task_elapsed` ko) */
const ELAPSED_RE = /^(.*) 실행 중 \((\d+)초 경과\)$/;
/** 이름 없는 경과 표시(`stepwise_task_unnamed` ko) */
const UNNAMED = '현재 단계';

const ENV_SKIP = 'G7_E2E_INSTALLER_URL · G7_E2E_INSTALLER_DB_FILE · G7_E2E_ADMIN_EMAIL · G7_E2E_ADMIN_PASSWORD_FILE 가 필요하다(설치되지 않은 리허설 사본 전용)';

let context: BrowserContext;
let page: Page;
/** 수정 전 파일로 바꿔 끼운 응답 수 */
let swapped = 0;
/** 페이지에 뜬 대화상자(종류와 문구) */
const dialogs: string[] = [];

/**
 * 같은 브라우저 세션 하나를 연다 — 설치 소유권이 세션에 묶이므로 두 케이스가 같은 세션을 쓴다.
 *
 * @param browser 브라우저
 * @param locale 로케일(설정값)
 * @param userAgent UA(설정값)
 */
async function openSession(browser: Browser, locale: string | undefined, userAgent: string | undefined): Promise<void> {
  context = await browser.newContext({ locale, userAgent });
  page = await context.newPage();
  page.setDefaultTimeout(20_000);
  page.setDefaultNavigationTimeout(60_000);
  page.on('dialog', (d) => {
    dialogs.push(`${d.type()}: ${d.message().slice(0, 160)}`);
    void d.accept();
  });

  if (HEAD_JS_DIR) {
    // 폴더에 있는 파일만 바꿔 끼운다 — 한 파일의 특정 수정만 되돌린 사본으로 대조할 때는 그 파일 하나만 둔다
    for (const file of ['installer.js', 'installation-monitor.js'].filter((f) => existsSync(join(HEAD_JS_DIR, f)))) {
      const body = readFileSync(join(HEAD_JS_DIR, file));
      await page.route(`**/install/assets/js/${file}*`, (route) => {
        swapped += 1;

        return route.fulfill({ status: 200, contentType: 'text/javascript; charset=utf-8', body });
      });
    }
  }
}

/**
 * 지금 그려진 설치 단계(0~5, 모르면 -1).
 *
 * @returns 단계 번호
 */
async function currentStep(): Promise<number> {
  return page.evaluate(() => {
    if (document.querySelector('form#config-form')) return 3;
    if (document.querySelector('#requirements-result')) return 2;
    if (document.querySelector('#license-agree')) return 1;
    if (document.querySelector('#install-btn')) return 0;
    if (document.querySelector('.extension-card')) return 4;
    if (document.querySelector('#start-installation-btn, #task-list')) return 5;

    return -1;
  });
}

/**
 * 2단계 검사(요구사항·웹 노출 점검)가 끝날 때까지 기다린다.
 */
async function settleRequirements(): Promise<void> {
  await page.waitForFunction(
    () =>
      document.querySelector('#requirements-result')?.getAttribute('aria-busy') === 'false' &&
      (document.querySelector('#web-exposure-verdict') as HTMLInputElement | null)?.value !== 'pending',
    null,
    { timeout: 60_000 },
  );
  await page.waitForTimeout(500);
}

/**
 * 0·1단계를 지나 2단계 화면까지 간다.
 */
async function walkToRequirements(): Promise<void> {
  await page.goto(INSTALL_URL, { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 3; i++) {
    const step = await currentStep();
    if (step === 2) return;
    if (step === 0) {
      await Promise.all([page.waitForNavigation(), page.locator('#install-btn').click()]);
    } else if (step === 1) {
      await page.locator('#license-agree').check({ force: true });
      await Promise.all([page.waitForNavigation(), page.locator('#next-btn').click()]);
    } else {
      throw new Error(`2단계로 가지 못했다 — 지금 단계 ${step}. 사본이 처음 상태가 아니다(설치 상태 파일·세션)`);
    }
  }
  expect(await currentStep(), '2단계 도착').toBe(2);
}

test.describe('웹 설치 마법사 (설치되지 않은 사본)', () => {
  test.skip(!INSTALLER || !DB_FILE || !ADMIN_EMAIL || !ADMIN_PASSWORD_FILE, ENV_SKIP);

  test.beforeAll(async ({ browser, playwright }, testInfo) => {
    const api = await playwright.request.newContext();
    const status = (await api.get(INSTALL_URL, { maxRedirects: 0 })).status();
    await api.dispose();
    test.skip(status !== 200, `설치 화면이 ${status} — 설치되지 않은 사본에서만 잰다`);
    await openSession(browser, testInfo.project.use.locale, testInfo.project.use.userAgent);
  });

  test.afterAll(async () => {
    await context?.close();
  });

  /**
   * @scenario installation_mode=stepwise, base_path=subpath, script_home=installer
   *
   * @effects requirements_recheck_does_not_resubmit_the_post, requirements_failure_reason_reaches_the_screen, browser_requirements_recheck_reopens_with_get
   */
  test('[다시 검증]은 서버 게이트가 거부한 POST 화면에서 같은 주소를 GET 으로 다시 연다', async () => {
    await walkToRequirements();
    await settleRequirements();
    if (HEAD_JS_DIR) expect(swapped, '수정 전 설치 화면 스크립트를 바꿔 끼운 응답').toBeGreaterThan(0);

    // 화면 잠금을 우회한 「노출됨」 제출 — 서버 게이트가 같은 단계의 POST 응답으로 거부 문구를 그린다(W6-004 재현 경로).
    const [postNav] = await Promise.all([
      page.waitForNavigation(),
      page.evaluate(() => {
        const verdict = document.querySelector('#web-exposure-verdict') as HTMLInputElement;
        const next = document.querySelector('#next-btn') as HTMLButtonElement;
        verdict.value = 'exposed';
        next.disabled = false;
        next.removeAttribute('aria-disabled');
        next.classList.remove('hidden');
        next.form!.requestSubmit(next);
      }),
    ]);
    expect(postNav?.request().method(), '거부 화면은 POST 응답이다').toBe('POST');
    expect(await currentStep(), '거부 뒤에도 2단계').toBe(2);
    const alert = page.locator('.alert.alert-error[role=alert]').first();
    await expect(alert, '서버 게이트 거부 문구').toContainText('「노출됨」');
    await expect(alert, '거부 문구가 번역 키 원문이 아니다').not.toContainText('error_web_exposure_blocked');

    await settleRequirements();
    const recheck = page.locator('#recheck-btn');
    test.skip(!(await recheck.isVisible()), '이 사본은 요구사항·웹 노출이 모두 통과해 [다시 검증] 이 나타나지 않는다 — 판정 불능이 나는 배치(문서 루트 배치 + 번들 vendor)에서 잰다');

    const navigations: Array<{ method: string; url: string }> = [];
    const onRequest = (req: Request) => {
      if (req.isNavigationRequest() && req.frame() === page.mainFrame()) navigations.push({ method: req.method(), url: req.url() });
    };
    page.on('request', onRequest);
    const dialogsBefore = dialogs.length;
    await recheck.click();
    await expect.poll(() => navigations.length, { message: '[다시 검증] 뒤 문서 요청', timeout: 15_000 }).toBeGreaterThan(0);
    await page.waitForLoadState('domcontentloaded');
    page.off('request', onRequest);

    expect(navigations[0], '[다시 검증] 의 첫 문서 요청').toEqual({ method: 'GET', url: INSTALL_URL });
    expect(navigations.filter((n) => n.method !== 'GET'), '[다시 검증] 이 다시 보낸 POST').toEqual([]);
    expect(dialogs.slice(dialogsBefore), '[다시 검증] 중 대화상자(양식 다시 제출 확인 등)').toEqual([]);
    expect(await currentStep(), '다시 연 화면은 2단계').toBe(2);
    await expect(page.locator('.alert.alert-error[role=alert]'), '다시 연 GET 화면에는 서버 거부 문구가 없다').toHaveCount(0);
  });

  /**
   * @scenario installation_mode=stepwise, execution_mode_choice=inprocess, vendor_mode_choice=bundled, task_duration=long_no_feedback, base_path=subpath, task=module
   *
   * @effects stepwise_progress_names_the_task_being_run, installer_current_task_names_the_extension_once, stepwise_reports_progress_while_a_task_takes_long, stepwise_progress_line_switches_when_request_starts, browser_stepwise_install_progress_follows_the_running_task, browser_stepwise_progress_line_switches_when_request_starts
   */
  test('단계별 설치는 지금 도는 단계 이름을 대고 확장 이름을 한 번만 붙인다', async () => {
    test.setTimeout(30 * 60_000);
    const db = JSON.parse(readFileSync(DB_FILE, 'utf-8')) as Record<string, string | number>;
    expect(existsSync(ADMIN_PASSWORD_FILE), '관리자 비밀번호 파일').toBe(true);
    const adminPassword = readFileSync(ADMIN_PASSWORD_FILE, 'utf-8').trim();

    // 2단계 — 1번 케이스가 남긴 화면(또는 새로 걸어 온 화면)에서 판정 불능이면 「직접 확인했음」 을 체크하고 넘어간다.
    if ((await currentStep()) !== 2) await walkToRequirements();
    await settleRequirements();
    const confirm = page.getByLabel(/위 주소를 모두 확인했고/);
    if ((await confirm.count()) > 0 && (await confirm.isVisible())) await confirm.check();
    await expect(page.locator('#next-btn'), '2단계 [다음]').toBeEnabled();
    await Promise.all([page.waitForNavigation(), page.locator('#next-btn').click()]);

    // 3단계 — 시험 DB, 관리자, 「CLI 없이 실행」 + 번들 vendor
    expect(await currentStep(), '3단계').toBe(3);
    const fill = async (selector: string, value: string) => {
      const field = page.locator(selector);
      await field.fill('');
      await field.fill(value);
    };
    await fill('#db_write_host', String(db.host));
    await fill('#db_write_port', String(db.port));
    await fill('#db_write_database', String(db.database));
    await fill('#db_write_username', String(db.username));
    await fill('#db_write_password', String(db.password));
    await fill('#app_name', 'E2E 설치 리허설');
    await fill('#admin_email', ADMIN_EMAIL);
    await fill('#admin_name', 'E2E 최고관리자');
    await fill('#admin_password', adminPassword);
    await fill('#admin_password_confirm', adminPassword);
    await page.locator('label:has(input[name=execution_mode][value=inprocess])').click();
    const bundled = page.locator('input[name=vendor_mode][value=bundled]');
    if (await bundled.isEnabled()) await page.locator('label:has(input[name=vendor_mode][value=bundled])').click();
    await page.getByRole('button', { name: /Write DB 연결 테스트/ }).click();
    await expect(page.locator('#db-write-test-result'), 'DB 연결 검사').toContainText('성공', { timeout: 30_000 });
    await Promise.all([page.waitForNavigation(), page.locator('#submit-btn').click()]);

    // 4단계 — 관리자 템플릿(기본 선택) + 지정 확장. 카드는 확장 목록 조회 뒤에 그려진다.
    await page.locator('.extension-card').first().waitFor({ timeout: 60_000 });
    expect(await currentStep(), '4단계').toBe(4);
    for (const id of EXTENSIONS) {
      const card = page.locator(`.extension-card[data-identifier="${id}"]`).first();
      await expect(card, `확장 카드 ${id}`).toHaveCount(1);
      if (!(await card.evaluate((e) => e.classList.contains('selected')))) {
        await card.scrollIntoViewIfNeeded();
        await card.click();
      }
      await expect(card, `확장 카드 ${id} 선택`).toHaveClass(/\bselected\b/);
    }
    await Promise.all([page.waitForNavigation(), page.locator('#submit-btn').click()]);

    // 5단계 — 단계별 실행(강제) 시작, 화면 변화를 그 순간의 작업 목록과 함께 기록
    await page.locator('#start-installation-btn').waitFor({ timeout: 60_000 });
    expect(await currentStep(), '5단계').toBe(5);
    await expect(page.locator('input[name=installation_mode][value=stepwise]'), '단계별 실행 선택').toBeChecked();
    await page.evaluate(() => {
      const w = window as any;
      w.__e2eInstallLog = [];
      const rows = () =>
        [...document.querySelectorAll('#task-list .task-item')].map((row) => ({
          name: row.querySelector('.task-name')?.textContent?.trim() ?? '',
          state: (row.className.match(/task-(pending|active|completed|skipped|failed)/) || [])[1] ?? '',
        }));
      const watch = (id: string) => {
        const el = document.getElementById(id);
        if (!el) return;
        let last: string | null = null;
        new MutationObserver(() => {
          const text = el.textContent?.trim() ?? '';
          if (text === last) return;
          last = text;
          w.__e2eInstallLog.push({ id, text, visible: el.offsetParent !== null, at: Date.now(), rows: rows() });
        }).observe(el, { childList: true, characterData: true, subtree: true });
      };
      watch('task-elapsed');
      watch('current-task');
      // 단계 요청마다 보낸 뒤 300ms 에 경과 줄과 작업 행을 찍는다. 그 사이 응답이 왔으면(빠른 단계) 그 표본은 버린다.
      w.__e2eRequestProbes = [];
      const nativeFetch = window.fetch.bind(window);
      window.fetch = (input: RequestInfo | URL, init?: RequestInit) => {
        const url = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url;
        if (!/\/api\/run-task\.php/.test(url)) return nativeFetch(input, init);
        const probe: Record<string, unknown> = { sentAt: Date.now(), answered: false };
        w.__e2eRequestProbes.push(probe);
        setTimeout(() => {
          const el = document.getElementById('task-elapsed');
          probe.answeredBefore = probe.answered;
          probe.text = el?.textContent?.trim() ?? '';
          probe.visible = !!el && el.offsetParent !== null;
          probe.rows = rows();
        }, 300);
        const pending = nativeFetch(input, init);
        pending.finally(() => {
          probe.answered = true;
        }).catch(() => {});

        return pending;
      };
    });
    await page.locator('#start-installation-btn').click();

    const started = Date.now();
    for (;;) {
      const done = await page.locator('#current-task.progress-status--success').count();
      if (done > 0) break;
      if (await page.locator('#retry-installation-btn').isVisible()) {
        throw new Error(`설치가 실패로 멈췄다: ${(await page.locator('#current-task').innerText()).slice(0, 200)}`);
      }
      if (Date.now() - started > 25 * 60_000) throw new Error('설치가 25분 안에 끝나지 않았다');
      await page.waitForTimeout(1_000);
    }
    await expect(page.locator('#current-task'), '완료 문구').toHaveText('설치가 완료되었습니다!');

    type Row = { name: string; state: string };
    type Entry = { id: string; text: string; visible: boolean; at: number; rows: Row[] };
    const log: Entry[] = await page.evaluate(() => (window as any).__e2eInstallLog);
    const rowNames = new Set(log.flatMap((e) => e.rows.map((r) => r.name)));
    // 측정 원본(화면 문구와 작업 행 상태만 — 비밀값 없음)과 완료 직후 진행 카드 상태를 첨부한다
    const finalView = await page.evaluate(() => {
      const el = document.getElementById('task-elapsed');
      const body = document.getElementById('installation-card-body');

      return {
        elapsedText: el?.textContent ?? null,
        elapsedVisible: !!el && el.offsetParent !== null,
        cardBodyVisible: !!body && body.offsetParent !== null,
      };
    });
    await test.info().attach('install-log.json', { body: JSON.stringify({ finalView, log }, null, 1), contentType: 'application/json' });
    test.info().annotations.push({ type: 'install', description: `${Math.round((Date.now() - started) / 1000)}초, 작업 ${rowNames.size}개, 기록 ${log.length}줄` });

    // 아래 세 축(경과 이름, 진행 중 행 수, 현재 작업 이름)은 서로 다른 결함이라 하나가 깨져도 나머지를 끝까지 잰다(soft).
    // 경과 표시 — 바뀔 때마다 그 이름이 그 순간의 진행 중 행이다
    const elapsed = log.filter((e) => e.id === 'task-elapsed' && ELAPSED_RE.test(e.text));
    expect(elapsed.length, '경과 표시 갱신 수(1초 이상 걸린 단계가 있어야 한다)').toBeGreaterThan(3);
    const wrongName = elapsed
      .map((e) => {
        const name = (e.text.match(ELAPSED_RE) as RegExpMatchArray)[1];
        const active = e.rows.filter((r) => r.state === 'active').map((r) => r.name);
        const pending = e.rows.some((r) => r.state === 'pending' || r.state === 'active');
        const ok = active.includes(name) || (name === UNNAMED && !pending);

        return ok ? null : `「${e.text}」 — 그때 진행 중 행 [${active.join(', ')}]`;
      })
      .filter(Boolean);
    expect.soft(wrongName, '경과 표시가 지금 도는 단계가 아닌 이름을 댄 순간').toEqual([]);
    expect(elapsed.some((e) => /\((sirsoft-[a-z0-9_]+)\)/.test(e.text)), '확장 단계의 경과 표시를 한 번 이상 봤다').toBe(true);

    // 요청을 보내는 순간 경과 줄이 이번 단계로 바뀐다 — 보낸 뒤 300ms 에 직전 단계 이름이 남아 있으면 안 된다
    type Probe = { sentAt: number; answeredBefore?: boolean; text?: string; visible?: boolean; rows?: Row[] };
    const probes: Probe[] = await page.evaluate(() => (window as any).__e2eRequestProbes);
    const measured = probes.filter((p) => p.answeredBefore === false && Array.isArray(p.rows));
    test.info().annotations.push({ type: 'request-probes', description: `단계 요청 ${probes.length}건, 300ms 안에 응답이 오지 않아 잰 것 ${measured.length}건` });
    expect(measured.length, '300ms 넘게 걸린 단계 요청 수').toBeGreaterThan(5);
    const staleAtStart = measured
      .map((p) => {
        const active = (p.rows as Row[]).filter((r) => r.state === 'active').map((r) => r.name);
        const name = (p.text ?? '').match(ELAPSED_RE)?.[1] ?? null;
        const ok = p.visible === true && name !== null && (active.includes(name) || (name === UNNAMED && active.length === 0));

        return ok ? null : `보낸 뒤 300ms 경과 줄 「${p.text}」(보임 ${p.visible}) — 그때 진행 중 행 [${active.join(', ')}]`;
      })
      .filter(Boolean);
    expect.soft(staleAtStart, '단계 요청을 보낸 직후 경과 줄이 이번 단계가 아닌 순간').toEqual([]);

    // 진행 중 행은 한 번에 하나
    const multiActive = log
      .map((e) => e.rows.filter((r) => r.state === 'active').map((r) => r.name))
      .filter((names) => names.length > 1)
      .map((names) => names.join(' | '));
    expect.soft([...new Set(multiActive)], '진행 중 행이 둘 이상인 순간').toEqual([]);

    // 현재 작업 줄 — 확장 단계 이름은 작업 목록 행 이름과 같고, 식별자가 두 번 나오지 않는다
    const current = log
      .filter((e) => e.id === 'current-task')
      .map((e) => e.text.replace(/\s\(\d+\/\d+\)$/, ''))
      .filter((text) => /\(sirsoft-[a-z0-9_]+\)/.test(text));
    expect(current.length, '확장 단계의 현재 작업 줄을 한 번 이상 봤다').toBeGreaterThan(0);
    expect.soft([...new Set(current.filter((text) => !rowNames.has(text)))], '작업 목록에 없는 현재 작업 이름(확장 이름·식별자 중복)').toEqual([]);
    expect.soft([...new Set(current.filter((text) => /\((sirsoft-[a-z0-9_]+)\).*\(\1\)/.test(text)))], '식별자가 두 번 붙은 현재 작업 이름').toEqual([]);
  });
});

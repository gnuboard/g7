/**
 * sirsoft-admin_basic — 환경설정 「업데이트」 탭의 진행·결과 표시 (7.2.1).
 *
 * 웹 코어 업데이트가 터미널과 같은 수준으로 「지금 무엇을 하는지·어떻게 끝났는지」를 보여 주는지
 * 브라우저에서 잰다. 실제 업데이트는 돌리지 않는다 — 상태 조회(`status`)를 가로채 상태별 화면을
 * 그리고, 단계 요청(`advance`)은 붙잡아 두었다가 테스트가 정한 다음 상태만 돌려준다.
 * 가로채지 않은 상태 변경 요청(POST·PUT·DELETE)이 서버로 나가면 테스트가 실패한다.
 *
 * 1. 진행 중 — 번들 단계 줄 아래 막대(폭 = 끝낸 수 / 전체)·「번들 n / m」·종류·이름·식별자·판,
 *    다른 단계의 단계 안 진행 막대(내려받기 압축 풀기), 전체 양을 모르는 단계(파일 비교)의
 *    「n개 확인」, 최근 기록 5줄, 전체 기록을 펼친 뒤 줄이 늘면 맨 아래를 따라간다.
 * 2. 완료 + 번들 실패 1건 — 노랑 안내·실패 표·다시 시도 명령.
 * 3. 실패 + 점검 모드가 남음 — 점검 모드 경고, 「조치 방법 보기」가 복구 안내를 연다.
 * 4. 되돌리는 중 — 진행 머리말·단계 목록·최근 기록이 보인다.
 * 5. 다른 관리자가 시작한 실행 — 「… 님이 진행 중」 표시.
 *
 * @axes actor=with_permission, run_state=running, failing_step=bundled, rollback_outcome=restored
 */
import { test, expect, type Page, type Route } from '@playwright/test';
import { issueToken, authenticatePage } from '../../fixtures/auth';

/**
 * 응답을 모두 가로채므로 케이스끼리 라이브 상태를 공유하지 않는다 — 한 케이스가 실패해도 나머지를 계속 잰다.
 */
test.describe.configure({ mode: 'default' });

/** 화면이 단계 목록을 그리는 근거 — 서버 `status()` 의 `steps` 와 같은 모양. */
const STEPS = [
  { key: 'preflight', label: '사전 점검', position: 1 },
  { key: 'maintenance', label: '점검 모드', position: 2 },
  { key: 'download', label: '내려받기', position: 3 },
  { key: 'backup', label: '백업', position: 4 },
  { key: 'vendor', label: '의존성 준비', position: 5 },
  { key: 'plan', label: '파일 비교', position: 6 },
  { key: 'apply', label: '파일 적용', position: 7 },
  { key: 'vendor_copy', label: '의존성 적용', position: 8 },
  { key: 'migrate', label: '마이그레이션', position: 9 },
  { key: 'upgrade_steps', label: '업그레이드 스텝', position: 10 },
  { key: 'bundled', label: '번들 확장 업데이트', position: 11 },
  { key: 'finalize', label: '마무리', position: 12 },
];

/**
 * 진행 기록 줄을 만든다.
 *
 * @param count 줄 수
 * @returns `[H:i:s] 메시지` 형식 줄 배열
 */
function logLines(count: number): string[] {
  return Array.from({ length: count }, (_, i) => `[10:00:${String(i % 60).padStart(2, '0')}] e2e 기록 ${i + 1}`);
}

/**
 * 상태 응답 본문을 만든다.
 *
 * @param run 실행 상태(run) — 기본값 위에 덮어쓴다
 * @returns 상태 응답의 data
 */
function statusData(run: Record<string, unknown> | null): Record<string, unknown> {
  const completedUntil = (key: string | undefined): string[] => {
    const index = STEPS.findIndex((s) => s.key === key);

    return index < 0 ? [] : STEPS.slice(0, index).map((s) => s.key);
  };
  const currentStep = (run?.current_step as string | undefined) ?? undefined;
  const completed = (run?.completed_steps as string[] | undefined) ?? completedUntil(currentStep);

  return {
    run:
      run === null
        ? null
        : {
            run_id: 'e2e-progress',
            from_version: '7.2.0',
            to_version: '7.2.1',
            options: {},
            next_step: currentStep ?? null,
            completed_steps: completed,
            completed_count: completed.length,
            current_step_label: STEPS.find((s) => s.key === currentStep)?.label ?? null,
            logs: [],
            log_seq: 0,
            warnings: [],
            notices: [],
            error: null,
            rollback: null,
            started_at: '2026-11-27T10:00:00+09:00',
            updated_at: '2026-11-27T10:03:25+09:00',
            bundled_result: null,
            maintenance_left_on: false,
            started_by: null,
            log_file: null,
            claim: { held: false },
            awaiting: null,
            current_item: null,
            step_progress: null,
            ...run,
          },
    steps: STEPS,
    total_steps: STEPS.length,
    manual_sources: [],
    current_version: '7.2.0',
    time_budget: { request_seconds: 60, client_wait_seconds: 90 },
  };
}

/**
 * 응답을 JSON 으로 채운다.
 *
 * @param route 가로챈 요청
 * @param data 응답 data
 */
async function fulfillJson(route: Route, data: unknown): Promise<void> {
  try {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
  } catch {
    // 테스트가 끝나 페이지가 닫힌 뒤 풀린 요청 — 무시한다
  }
}

/** 화면 한 벌에 대한 가로채기 핸들 */
interface Harness {
  /** 상태 조회가 돌려줄 data 를 바꾼다 */
  setStatus: (data: Record<string, unknown>) => void;
  /** 붙잡힌 advance 요청 하나를 주어진 상태로 풀어 준다(붙잡힌 요청이 생길 때까지 기다린다) */
  releaseAdvance: (data: Record<string, unknown>) => Promise<void>;
  /** 가로채지 않았는데 서버로 나가려던 상태 변경 요청 */
  unexpected: string[];
}

/**
 * 코어 업데이트 API 를 가로챈다.
 *
 * - `status` 는 `setStatus` 로 정한 data 를 돌려준다.
 * - `advance` 는 붙잡아 두고 `releaseAdvance` 가 줄 때만 답한다 — 진행 중 화면이 그 상태로 머문다.
 * - 그 밖의 코어 업데이트 상태 변경 요청은 막고 기록한다(테스트 끝에 0 건이어야 한다).
 *
 * @param page 페이지
 * @param initial 처음 상태 data
 * @returns 가로채기 핸들
 */
async function interceptCoreUpdate(page: Page, initial: Record<string, unknown>): Promise<Harness> {
  let current = initial;
  const waiters: Array<(data: Record<string, unknown>) => void> = [];
  const unexpected: string[] = [];

  // 먼저 등록한 라우트가 나중에 등록한 것보다 뒤에 검사된다 — 이 그물은 아래 세 가지가 처리하지 않은 것만 받는다.
  await page.route('**/api/admin/core-update/**', async (route) => {
    const method = route.request().method();

    if (method === 'GET' || method === 'HEAD' || method === 'OPTIONS') {
      await route.fallback();

      return;
    }

    unexpected.push(`${method} ${route.request().url()}`);
    await route.abort();
  });

  await page.route('**/api/admin/core-update/status**', async (route) => {
    await fulfillJson(route, current);
  });

  await page.route('**/api/admin/core-update/advance**', async (route) => {
    const next = await new Promise<Record<string, unknown>>((resolve) => waiters.push(resolve));
    current = next;
    await fulfillJson(route, next);
  });

  return {
    setStatus: (data) => {
      current = data;
    },
    releaseAdvance: async (data) => {
      await expect.poll(() => waiters.length, { message: '화면이 다음 단계 요청을 보내지 않았다', timeout: 15_000 }).toBeGreaterThan(0);
      waiters.shift()!(data);
    },
    unexpected,
  };
}

/**
 * 업데이트 탭을 연다.
 *
 * @param page 페이지
 */
async function openUpdateTab(page: Page): Promise<void> {
  const token = issueToken('core.settings.read', 'core.system.update');
  await authenticatePage(page, token);
  await page.goto('/admin/settings?tab=update');
  await expect(page.locator('#tab_content_update')).toBeVisible({ timeout: 20_000 });
}

/**
 * 단계 목록에서 한 단계 줄을 고른다.
 *
 * @param page 페이지
 * @param label 단계 이름
 * @returns 그 단계의 li
 */
function stepRow(page: Page, label: string) {
  return page.locator('#core_update_step_list > li').filter({ hasText: label });
}

/**
 * 단계 줄 아래 얇은 막대의 채워진 부분 폭(%)을 읽는다.
 *
 * @param row 단계 줄
 * @returns style 의 width 값
 */
async function barWidth(row: ReturnType<typeof stepRow>): Promise<string> {
  return row.locator('div.h-1\\.5 > div.h-1\\.5').first().evaluate((el) => (el as HTMLElement).style.width);
}

let harness: Harness | null = null;

test.afterEach(async ({ page }) => {
  await page.unrouteAll({ behavior: 'ignoreErrors' });
  expect(harness?.unexpected ?? [], '가로채지 않은 상태 변경 요청이 서버로 나가려 했다').toEqual([]);
  harness = null;
});

test.describe('@sirsoft-admin_basic 환경설정 업데이트 탭 — 진행·결과 표시', () => {
  // @scenario actor=with_permission, run_state=running, failing_step=none
  // @effects the_screen_names_the_item_being_processed, recent_log_lines_stay_visible, the_full_log_follows_new_lines
  test('진행 중 — 번들 단계 줄에 처리 중 항목·막대를, 최근 기록 5줄을 보이고 전체 기록은 맨 아래를 따라간다', async ({ page }) => {
    const bundledLogs = logLines(12);
    const BUNDLED = statusData({
      status: 'running',
      current_step: 'bundled',
      logs: bundledLogs,
      log_seq: bundledLogs.length,
      current_item: {
        step: 'bundled',
        type: 'module',
        identifier: 'sirsoft-ecommerce',
        name: 'E2E 쇼핑몰',
        from_version: '1.4.0',
        to_version: '1.5.0',
        position: 3,
        total: 5,
      },
      step_progress: { step: 'bundled', done: 2, total: 5, unit: 'items' },
    });

    harness = await interceptCoreUpdate(page, BUNDLED);
    await openUpdateTab(page);

    // 번들 단계 줄 — 「번들 3 / 5」, 막대 폭 2/5 = 40%, 종류·이름·식별자·판
    const bundledRow = stepRow(page, '번들 확장 업데이트');
    await expect(bundledRow).toContainText('번들 3 / 5', { timeout: 15_000 });
    expect(await barWidth(bundledRow)).toBe('40%');
    await expect(bundledRow).toContainText('지금 처리 중');
    await expect(bundledRow).toContainText('모듈');
    await expect(bundledRow).toContainText('E2E 쇼핑몰');
    await expect(bundledRow).toContainText('sirsoft-ecommerce');
    await expect(bundledRow).toContainText('1.4.0 → 1.5.0');

    // 다른 단계 줄에는 막대가 없다 — 지금 단계에만 붙는다
    await expect(stepRow(page, '마무리').locator('div.h-1\\.5')).toHaveCount(0);

    // 최근 기록 5줄 — 접혀 있어도 마지막 5줄이 그대로 보인다
    const tail = page.locator('#core_update_log_tail');
    await expect(tail).toBeVisible();
    expect((await tail.innerText()).trim().split('\n')).toEqual(bundledLogs.slice(-5));

    // 전체 기록 — 넘칠 만큼 줄을 싣고 펼친다
    await harness.releaseAdvance(
      statusData({ status: 'running', current_step: 'bundled', logs: logLines(80), log_seq: 80 }),
    );
    await expect(tail).toContainText('e2e 기록 80', { timeout: 15_000 });
    await page.locator('#core_update_log_toggle').click();
    const log = page.locator('#core_update_log');
    await expect(log).toBeVisible();
    await expect(log).toContainText('e2e 기록 80');

    /**
     * 전체 기록 상자에서 마지막 줄이 상자 바닥에 붙어 있는지 잰다.
     *
     * @returns 넘침 여부와 본문 바닥 ↔ 상자 바닥 거리(px)
     */
    const measure = () =>
      log.evaluate((box) => {
        const body = box.querySelector('pre') as HTMLElement;

        return {
          overflows: box.scrollHeight > box.clientHeight + 1,
          gap: Math.abs(body.getBoundingClientRect().bottom - box.getBoundingClientRect().bottom),
        };
      });

    const before = await measure();
    expect(before.overflows, '기록이 상자를 넘쳐야 따라가기를 잴 수 있다').toBe(true);
    expect(before.gap, '펼쳤을 때 맨 아래(최신 줄)가 보여야 한다').toBeLessThanOrEqual(2);

    // 줄이 늘어난 응답 — 상자는 여전히 맨 아래를 보여 준다
    await harness.releaseAdvance(
      statusData({ status: 'running', current_step: 'bundled', logs: logLines(110), log_seq: 110 }),
    );
    await expect(log).toContainText('e2e 기록 110', { timeout: 15_000 });
    const after = await measure();
    expect(after.overflows).toBe(true);
    expect(after.gap, '줄이 늘어난 뒤에도 맨 아래를 따라가야 한다').toBeLessThanOrEqual(2);
  });

  // @scenario actor=with_permission, run_state=running, failing_step=none
  // @effects the_step_line_shows_progress_within_the_step
  test('진행 중 — 지금 단계 줄 아래에 단계 안 진행(막대·n / m, 전체를 모르면 n개 확인)을 보인다', async ({ page }) => {
    harness = await interceptCoreUpdate(
      page,
      statusData({ status: 'running', current_step: 'migrate', logs: logLines(2), log_seq: 2 }),
    );
    await openUpdateTab(page);
    await expect(page.locator('#core_update_step_list')).toBeVisible({ timeout: 15_000 });

    // 내려받기 단계로 바뀐 응답 — step_progress(total 있음)로 막대와 「n / m 항목」
    await harness.releaseAdvance(
      statusData({
        status: 'running',
        current_step: 'download',
        logs: logLines(3),
        log_seq: 3,
        step_progress: { step: 'download', done: 120, total: 480, unit: 'entries' },
      }),
    );
    const downloadRow = stepRow(page, '내려받기');
    await expect(downloadRow).toContainText('120 / 480 항목', { timeout: 15_000 });
    expect(await barWidth(downloadRow)).toBe('25%');
    await expect(downloadRow).not.toContainText('번들');

    // 파일 비교 — 전체 양을 모르면(total=null) 막대 없이 「n개 확인」
    await harness.releaseAdvance(
      statusData({
        status: 'running',
        current_step: 'plan',
        logs: logLines(4),
        log_seq: 4,
        step_progress: { step: 'plan', done: 37, total: null, unit: 'files' },
      }),
    );
    const planRow = stepRow(page, '파일 비교');
    await expect(planRow).toContainText('37개 확인', { timeout: 15_000 });
    await expect(planRow.locator('div.h-1\\.5')).toHaveCount(0);

    // step_progress 가 다른 단계 것이면 그리지 않는다
    await harness.releaseAdvance(
      statusData({
        status: 'running',
        current_step: 'apply',
        logs: logLines(5),
        log_seq: 5,
        step_progress: { step: 'plan', done: 37, total: null, unit: 'files' },
      }),
    );
    await expect(page.locator('#core_update_step_list')).not.toContainText('37개 확인', { timeout: 15_000 });
    await expect(stepRow(page, '파일 적용').locator('div.h-1\\.5')).toHaveCount(0);
  });

  // @scenario actor=with_permission, run_state=finished, failing_step=bundled
  // @effects the_completed_screen_lists_failed_bundles_with_retry_commands
  test('완료 + 번들 실패 1건 — 노랑 안내와 실패 표·다시 시도 명령을 보인다', async ({ page }) => {
    const retry = 'php artisan module:update sirsoft-ecommerce';
    harness = await interceptCoreUpdate(
      page,
      statusData({
        status: 'completed',
        current_step: undefined,
        next_step: null,
        completed_steps: STEPS.map((s) => s.key),
        completed_count: STEPS.length,
        logs: logLines(6),
        log_seq: 6,
        log_file: 'storage/logs/core_update_20261127_100325.log',
        bundled_result: {
          success: 29,
          failed: 1,
          skipped: 1,
          failed_items: [{ type: 'module', identifier: 'sirsoft-ecommerce', reason: 'e2e 마이그레이션 실패', retry_command: retry }],
          search_index: { status: 'rebuilt', message: null },
        },
      }),
    );
    await openUpdateTab(page);

    const partial = page.locator('#core_update_completed_partial');
    await expect(partial).toBeVisible({ timeout: 15_000 });
    await expect(partial).toContainText('번들 1개가 실패');
    await expect(page.locator('#core_update_completed')).toHaveCount(0);

    const summary = page.locator('#core_update_result_summary');
    await expect(summary).toContainText('7.2.0 → 7.2.1');
    await expect(summary).toContainText('성공 29 · 실패 1 · 건너뜀 1');
    await expect(summary).toContainText('다시 만들었습니다');
    await expect(summary).toContainText('storage/logs/core_update_20261127_100325.log');

    const failures = page.locator('#core_update_bundled_failures');
    await expect(failures).toBeVisible();
    const rows = failures.locator('tbody tr');
    await expect(rows).toHaveCount(1);
    await expect(rows.first()).toContainText('모듈');
    await expect(rows.first()).toContainText('sirsoft-ecommerce');
    await expect(rows.first()).toContainText('e2e 마이그레이션 실패');
    const command = rows.first().locator('.font-mono', { hasText: retry });
    await expect(command).toBeVisible();
    // 복사하기 쉬운 고정폭 — 계산된 글꼴이 monospace 계열이어야 한다
    const fontFamily = await command.evaluate((el) => getComputedStyle(el).fontFamily.toLowerCase());
    expect(fontFamily).toMatch(/mono|courier|consolas|menlo/);
  });

  // @scenario actor=with_permission, run_state=finished, rollback_outcome=restored
  // @effects the_failure_screen_warns_when_maintenance_is_left_on
  test('실패 + 점검 모드가 남음 — 경고를 보이고 「조치 방법 보기」가 복구 안내를 연다', async ({ page }) => {
    harness = await interceptCoreUpdate(
      page,
      statusData({
        status: 'rolled_back',
        current_step: undefined,
        next_step: null,
        completed_steps: ['preflight', 'maintenance', 'download', 'backup'],
        logs: logLines(4),
        log_seq: 4,
        error: { step: 'apply', step_label: '파일 적용', message: 'e2e 적용 실패' },
        rollback: { restored: true, files_were_applied: true, failed_targets: [] },
        maintenance_left_on: true,
      }),
    );
    await openUpdateTab(page);

    await expect(page.locator('#core_update_failed')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('#core_update_maintenance_left_on')).toBeVisible();
    await expect(page.locator('#core_update_maintenance_left_on')).toContainText('점검 모드');

    const guideButton = page.locator('#core_update_guide_button');
    const guide = page.locator('#core_update_recovery_guide');
    await expect(guideButton).toBeVisible();

    // 점검 모드가 남았으면 복구 안내가 처음부터 펼쳐져 있다 — 닫았다가 다시 연다
    await expect(guide).toBeVisible();
    await expect(guideButton).toHaveAttribute('aria-expanded', 'true');
    await guideButton.click();
    await expect(guide).toHaveCount(0);
    await expect(guideButton).toHaveAttribute('aria-expanded', 'false');
    await expect(guideButton).toContainText('조치 방법 보기');
    await guideButton.click();
    await expect(guide).toBeVisible();
    await expect(guide).toContainText('복구 안내');
    // 수동 복구 순서 — 파일 되돌리기, Composer 재설치, 점검 해제 (터미널 안내와 같은 순서)
    const text = await guide.innerText();
    const restore = text.indexOf('백업에서 파일을');
    const composer = text.indexOf('Composer 패키지를');
    const up = text.indexOf('점검 모드를 해제');
    expect(restore, '파일 되돌리기 단계').toBeGreaterThanOrEqual(0);
    expect(composer, 'Composer 재설치 단계').toBeGreaterThan(restore);
    expect(up, '점검 해제 단계').toBeGreaterThan(composer);
    // 터미널 업데이트 안내 창이 아니라 복구 안내다
    await expect(page.getByRole('dialog').filter({ hasText: 'core:update' })).toHaveCount(0);
  });

  // @scenario actor=with_permission, run_state=rolling_back
  // @effects the_rollback_shows_progress_like_a_run
  test('되돌리는 중 — 진행 머리말·단계 목록·최근 기록을 보인다', async ({ page }) => {
    const logs = logLines(9);
    harness = await interceptCoreUpdate(
      page,
      statusData({
        status: 'rolling_back',
        current_step: 'apply',
        logs,
        log_seq: logs.length,
        error: { step: 'apply', step_label: '파일 적용', message: 'e2e 적용 실패' },
      }),
    );
    await openUpdateTab(page);

    await expect(page.locator('#core_update_rolling_back')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('#core_update_progress_header')).toContainText('변경을 되돌리는 중');
    await expect(page.locator('#core_update_step_list')).toBeVisible();
    const tail = page.locator('#core_update_log_tail');
    await expect(tail).toBeVisible();
    expect((await tail.innerText()).trim().split('\n')).toEqual(logs.slice(-5));
  });

  // @scenario actor=with_permission, run_state=running, concurrency=single
  // @effects the_screen_names_another_admin_running_the_update
  test('다른 관리자가 시작한 실행 — 그 이름으로 「… 님이 진행 중」을 보인다', async ({ page }) => {
    harness = await interceptCoreUpdate(
      page,
      statusData({
        status: 'running',
        current_step: 'migrate',
        logs: logLines(3),
        log_seq: 3,
        started_by: { id: 987654321, uuid: 'e2e-other-admin-uuid', name: 'E2E다른관리자' },
      }),
    );
    await openUpdateTab(page);

    const startedBy = page.locator('#core_update_started_by');
    await expect(startedBy).toBeVisible({ timeout: 15_000 });
    await expect(startedBy).toContainText('E2E다른관리자 님이 진행 중');
  });
});

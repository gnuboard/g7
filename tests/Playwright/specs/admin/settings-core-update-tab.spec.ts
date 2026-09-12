/**
 * sirsoft-admin_basic — 환경설정 「업데이트」 탭 (W6 · 이슈 #609 G6).
 *
 * 브라우저에서만 확정되는 것을 잰다:
 *
 * 1. **권한이 없으면 영역 자체가 없다.** 서버측 403 은 「막혔다」까지이고, 화면이 탭을
 *    비활성으로 남겼는지 아예 지웠는지는 여기서만 갈린다 — 누를 수 없는 탭이 남으면
 *    운영자는 그 권한이 있는 줄 알고 기다린다 (W6 · D4).
 * 2. **진행 중이 아닐 때 위험한 것이 화면에 없다.** `--force`·`--prune`·백업 생략은 웹
 *    동선에 존재하지 않아야 하는데(D27), 그 부재는 DOM 을 봐야 확인된다.
 * 3. **[업데이트 시작] 이 안전 기본값을 담은 요청 하나로 시작한다.** 요청 본문에
 *    위험 선택이 실리면 서버가 무시하더라도 그 화면은 이미 계약을 어긴 것이다.
 * 4. **수동 ZIP 은 고급 접기 안에 있다** (㉯ — D18): 4상태 배치를 밀지 않는다.
 *
 * 실제 코어 교체는 여기서 돌리지 않는다 — 그것은 P0(무CLI) 프로필의 실측 몫이고
 * (§9 S13), 이 spec 은 그 실측이 시작되는 지점까지의 화면 계약을 고정한다.
 *
 * @scenario core-update-web
 * @axes actor=with_permission, run_state=idle
 * @effects update_tab_is_hidden_without_permission, start_sends_only_safe_options, manual_zip_lives_behind_advanced, the_start_controls_appear_only_when_there_is_something_to_apply, update_tab_buttons_carry_the_button_base_class
 */
import { test, expect } from '@playwright/test';
import { issueToken, issueScopedToken, authenticatePage } from '../../fixtures/auth';

/**
 * 이 파일의 케이스들은 **같은 라이브 사이트의 전역 상태**(진행 상태·확인 결과·자동
 * 이어받기 예산)를 세우고 읽는다. 병렬로 돌리면 서로의 상태를 밟고, 개발 서버에는
 * 동시 접속이 몰려 `networkidle` 대기가 만료된다(실측: 9케이스 병렬 6실패 → 직렬 9통과).
 * CI 는 이미 1 worker 이므로 이 선언은 로컬 실행을 CI 와 같게 만든다.
 */
test.describe.configure({ mode: 'serial' });

test.describe('@sirsoft-admin_basic 환경설정 업데이트 탭', () => {
  // @scenario actor=without_permission, run_state=idle
  // @effects update_tab_is_hidden_without_permission
  test('신규 권한이 없으면 업데이트 탭이 화면에 없다', async ({ page }) => {
    // admin 역할은 전체 권한을 갖는다 — 권한 경계를 재려면 좁힌 토큰이어야 한다.
    const token = issueScopedToken('core.settings.read', 'core.settings.update');
    await authenticatePage(page, token);

    await page.goto('/admin/settings?tab=general');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 탭 버튼이 비활성으로 남는 것이 아니라 **없어야** 한다.
    await expect(page.getByRole('button', { name: '업데이트', exact: true })).toHaveCount(0);
    await expect(page.locator('#tab_content_update')).toHaveCount(0);
  });

  // @scenario actor=with_permission, run_state=idle
  // @effects update_tab_is_hidden_without_permission
  test('신규 권한이 있으면 업데이트 탭이 열린다', async ({ page }) => {
    const token = issueToken('core.settings.read', 'core.system.update');
    await authenticatePage(page, token);

    await page.goto('/admin/settings?tab=update');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    await expect(page.locator('#tab_content_update')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#core_update_current_version')).not.toHaveText('-');
  });

  // @scenario actor=with_permission, run_state=idle
  // @effects start_sends_only_safe_options
  test('[업데이트 시작] 이 안전 기본값만 담아 보낸다', async ({ page }) => {
    const token = issueToken('core.settings.read', 'core.system.update');
    await authenticatePage(page, token);

    // 확인 응답을 「새 버전 있음」으로 고정한다 — 실제 GitHub 상태에 기대면 최신인 날
    // 착수 컨트롤이 없어 이 검사가 환경 따라 갈린다.
    await page.route('**/api/admin/core-update/check', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          data: { update_available: true, current_version: '7.0.8', latest_version: '9.9.9' },
        }),
      });
    });

    await page.goto('/admin/settings?tab=update');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 올릴 것이 없으면 착수 컨트롤은 화면에 없다 (W6 ①) — 확인을 거쳐야 ② 가 열린다.
    await expect(page.locator('#core_update_start_button')).toHaveCount(0);

    await page.locator('#core_update_check_button').click();
    await expect(page.locator('#core_update_start_button')).toBeVisible({ timeout: 15_000 });

    // 실제 업데이트를 시작하지 않는다 — 요청 본문만 확인하고 가로챈다.
    let payload: Record<string, unknown> | null = null;

    await page.route('**/api/admin/core-update/start', async (route) => {
      payload = JSON.parse(route.request().postData() ?? '{}');
      await route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({ success: false, message: 'intercepted by e2e' }),
      });
    });

    await page.locator('#core_update_start_button').click();
    await expect.poll(() => payload, { timeout: 15_000 }).not.toBeNull();

    const body = payload as unknown as Record<string, unknown>;

    // 웹에 없는 선택이 요청에도 없어야 한다 (D27).
    for (const forbidden of ['force', 'prune', 'no_backup', 'no_maintenance', 'vendor_mode']) {
      expect(body, `착수 요청에 ${forbidden} 가 실렸다`).not.toHaveProperty(forbidden);
    }

    expect(body.update_bundled_extensions, '번들 확장 함께 업데이트는 기본 켬 (D28)').toBe(true);
    expect(body.rebuild_search_index, '검색 인덱스 재생성은 기본 끔').toBe(false);
    expect(body.layout_strategy, 'CLI 기본과 같아야 한다 (D29)').toBe('overwrite');
  });

  // @scenario actor=with_permission, run_state=idle
  // @effects manual_zip_lives_behind_advanced
  test('수동 ZIP 지정은 고급 접기 안에 있다 (4상태 배치 불변)', async ({ page }) => {
    const token = issueToken('core.settings.read', 'core.system.update');
    await authenticatePage(page, token);

    await page.goto('/admin/settings?tab=update');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 「접혀 있다」의 판정은 **접기 컴포넌트가 노출하는 상태**로 한다. 내용 블록의 가시성으로
    // 재면 안 된다 — 이 컴포넌트는 감싼 영역의 높이를 0 으로 접을 뿐이라 안쪽 요소의 박스는
    // 그대로 남고, 그 차이가 브라우저 실측에서 드러났다.
    const trigger = page.getByRole('button', { name: '고급' });
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');

    // 수동 ZIP 은 그 접기 영역 **안**에 있어야 한다 — 밖에 있으면 착수 화면 배치가 밀린다.
    const manualZip = page.locator('#core_update_advanced_content #core_update_manual_zip');
    await expect(manualZip).toHaveCount(1);

    await trigger.click();
    await expect(trigger).toHaveAttribute('aria-expanded', 'true', { timeout: 10_000 });
    await expect(manualZip).toBeVisible({ timeout: 10_000 });
  });

  // @scenario actor=with_permission, run_state=idle
  // @effects the_start_controls_appear_only_when_there_is_something_to_apply
  test('올릴 것이 없으면 착수 컨트롤이 화면에 없다 (W6 ①)', async ({ page }) => {
    const token = issueToken('core.settings.read', 'core.system.update');
    await authenticatePage(page, token);

    await page.goto('/admin/settings?tab=update');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // ① 은 「현재 버전 + 최신 여부 + [업데이트 확인]」 뿐이다. 선택 항목·점검 모드 경고·
    // [업데이트 시작] 이 남아 있으면 올릴 것이 없는데 사이트를 멈출 수 있는 것처럼 보인다.
    await expect(page.locator('#core_update_check_button')).toBeVisible({ timeout: 10_000 });

    for (const id of ['#core_update_options', '#core_update_maintenance_warning', '#core_update_start_row']) {
      await expect(page.locator(id), `${id} 가 ① 상태에 남아 있다`).toHaveCount(0);
    }
  });

  // @scenario actor=with_permission, run_state=idle
  // @effects start_sends_only_safe_options
  test('진행 중이 아닐 때는 중단 버튼이 화면에 없다', async ({ page }) => {
    const token = issueToken('core.settings.read', 'core.system.update');
    await authenticatePage(page, token);

    await page.goto('/admin/settings?tab=update');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 파일 교체 중에 사람이 멈추면 반쪽 상태가 남는다 — 실패 판정과 되돌리기는 시스템이 한다 (W6).
    //
    // 「중단·취소」라는 **글자**를 부재 단언하면 그 글자가 화면에 존재한 적이 없어 무엇을
    // 넣어도 통과한다(모집단 0). 실제로 막아야 하는 것은 낱말이 아니라 **진행을 사람이
    // 끊는 조작**이므로, 이 화면의 버튼 전량을 세어 허용 목록과 대조한다 — 목록에 없는
    // 버튼이 생기면 그 이름이 무엇이든 red 가 된다. tsx 쌍둥이가 핸들러 축에 같은 형태를
    // 쓰고 있고, 여기만 낱말 부재 단언으로 남아 있었다.
    const buttonNames = await page.locator('#tab_content_update button').evaluateAll((els) =>
      els.map((el) => (el.textContent ?? '').replace(/\s+/g, ' ').trim()).filter(Boolean));

    expect(buttonNames.length, '버튼이 하나도 없으면 이 대조는 아무것도 재지 않는다')
      .toBeGreaterThan(0);

    // 유휴 상태(W6 ①)에서 이 화면이 가질 수 있는 조작.
    const allowed = [
      'アップデート確認', '업데이트 확인', 'Check for updates',
      '詳細', '고급', 'Advanced',
      '対処方法を見る', '조치 방법 보기', 'View remedies',
      '変更内容を見る', '변경사항 보기', 'View changelog',
    ];
    const unexpected = buttonNames.filter((name) => !allowed.some((a) => name.includes(a)));

    expect(unexpected, '유휴 화면에 예상 밖의 조작이 생겼다 — 진행 중단 버튼이면 W6 위반이다')
      .toEqual([]);
    await expect(page.locator('#core_update_progress')).toHaveCount(0);
  });

  /**
   * 유휴 상태의 화면 진입이 **자동 이어받기 예산을 갉아먹지 않는지** 잰다.
   *
   * 이 화면은 진행 중인 실행이 남아 있으면 운영자 조작 없이 이어받는다(D10). 그 자동
   * 경로에는 무한 왕복을 막는 상한이 있는데, 진입할 때마다 상한을 쓰면 **아무 일도 없는
   * 방문 몇 번으로 예산이 사라져** 정작 업데이트가 중단됐을 때 화면이 이어받지 못한다 —
   * 사이트가 점검 모드에 멈춘 채 남는다. 이 경로가 막으려던 바로 그 상태다.
   *
   * 화면 블록의 `if`(진행 중일 때만)는 이 발사를 막지 못한다 — 조건이 거짓인 컴포넌트도
   * 마운트되어 `lifecycle.onMount` 가 실행된다(라이브 실측: 유휴 탭 1회 진입에 상태
   * 조회가 2회 발생했고, 핸들러 이름을 무력화하면 1회로 줄었다). 그래서 **핸들러 자신이**
   * 이어받을 것이 있는지 먼저 확인해야 한다.
   *
   * 진입 반복은 핸들러를 직접 발화해 재현한다 — 마운트 경로를 흉내내는 것이 목적이고,
   * 탭 전환 DOM 조작에 기대면 배치가 바뀔 때 이 검사가 결함이 아니라 선택자 때문에
   * 무너진다. 레이아웃에 핸들러 이름이 적혀 있는지를 보는 검사로는 이 회귀가 잡히지
   * 않는다(핸들러 본문을 통째로 비워도 통과한다).
   *
   * @scenario actor=with_permission, run_state=running
   * @effects idle_visits_do_not_consume_the_auto_resume_budget
   */
  test('[case:components-misc-onmount-ignores-false-if] 유휴 진입을 상한보다 많이 반복해도 진행 중인 실행은 자동으로 이어받는다', async ({ page }) => {
    const token = issueToken('core.settings.read', 'core.system.update');
    await authenticatePage(page, token);

    const IDLE = { run: null, current_version: '7.0.8' };
    const RUNNING = {
      run: {
        status: 'running',
        run_id: 'e2e-budget',
        current_step: 'preflight',
        completed_count: 0,
        logs: [],
        warnings: [],
        notices: [],
      },
      current_version: '7.0.8',
    };
    const DONE = {
      run: { status: 'completed', run_id: 'e2e-budget', logs: [], warnings: [], notices: [] },
      current_version: '7.0.8',
    };

    let running = false;
    const advanceCalls: string[] = [];

    await page.route('**/api/admin/core-update/status*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, data: running ? RUNNING : IDLE }),
      });
    });

    // 실제 코어는 건드리지 않는다 — 한 번 불리면 곧바로 종료 상태를 돌려 반복을 멈춘다.
    await page.route('**/api/admin/core-update/advance', async (route) => {
      advanceCalls.push(route.request().method());
      running = false;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, data: DONE }),
      });
    });

    await page.goto('/admin/settings?tab=update');
    await expect(page.locator('#tab_content_update')).toBeVisible({ timeout: 20_000 });

    // 진행 중인 실행이 없는 상태로 상한(3회)보다 많이 진입한다.
    for (let i = 0; i < 5; i++) {
      await page.evaluate(async () => {
        await (window as any).G7Core.dispatch({ handler: 'autoResumeCoreUpdate' });
      });
      await page.waitForTimeout(150);
    }

    expect(advanceCalls, '진행 중인 실행이 없으면 이어받을 것도 없다').toHaveLength(0);

    // 이제 진행 중인 실행이 생겼다. 앞의 유휴 진입이 예산을 쓰지 않았다면 이어받는다.
    running = true;
    await page.evaluate(async () => {
      await (window as any).G7Core.dispatch({ handler: 'autoResumeCoreUpdate' });
    });

    await expect
      .poll(() => advanceCalls.length, {
        timeout: 15_000,
        message: '유휴 진입이 자동 이어받기 예산을 소진해 진행이 이어받히지 않는다',
      })
      .toBeGreaterThan(0);
  });

  /**
   * 새 실행을 착수하면 자동 이어받기 예산이 **되돌아가는지** 잰다.
   *
   * 예산은 무한 왕복을 막기 위한 것이지 「이 브라우저 창에서 평생 3회」가 아니다.
   * 되돌리지 않으면 같은 화면에서 두 번째 업데이트를 하는 운영자가 앞 실행이 쓴 만큼을
   * 잃은 채 출발하고, 그 실행이 중단되면 자동 복구를 못 받는다.
   *
   * @scenario actor=with_permission, run_state=running
   * @effects starting_a_run_restores_the_auto_resume_budget
   */
  test('새 실행을 착수하면 자동 이어받기 예산이 되돌아간다', async ({ page }) => {
    const token = issueToken('core.settings.read', 'core.system.update');
    await authenticatePage(page, token);

    const RUNNING = {
      run: {
        status: 'running',
        run_id: 'e2e-reset',
        current_step: 'preflight',
        completed_count: 0,
        logs: [],
        warnings: [],
        notices: [],
      },
      current_version: '7.0.8',
    };
    const DONE = {
      run: { status: 'completed', run_id: 'e2e-reset', logs: [], warnings: [], notices: [] },
      current_version: '7.0.8',
    };

    let running = false;
    const advanceCalls: string[] = [];

    await page.route('**/api/admin/core-update/status*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, data: running ? RUNNING : DONE }),
      });
    });
    await page.route('**/api/admin/core-update/advance', async (route) => {
      advanceCalls.push(route.request().method());
      running = false;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, data: DONE }),
      });
    });
    // 착수는 실제 코어를 건드리지 않는다 — 곧바로 종료 상태를 돌려준다.
    await page.route('**/api/admin/core-update/start', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, data: DONE }),
      });
    });

    await page.goto('/admin/settings?tab=update');
    await expect(page.locator('#tab_content_update')).toBeVisible({ timeout: 20_000 });

    const resumeOnce = async () => {
      running = true;
      await page.evaluate(async () => {
        await (window as any).G7Core.dispatch({ handler: 'autoResumeCoreUpdate' });
      });
      await page.waitForTimeout(250);
    };

    // 상한(3회)까지 자동으로 이어받은 뒤 네 번째는 멈춘다.
    for (let i = 0; i < 4; i++) {
      await resumeOnce();
    }

    expect(advanceCalls, '자동 경로는 상한에서 멈춘다 — 멈추지 않으면 같은 왕복이 무한히 돈다')
      .toHaveLength(3);

    // 새 실행을 착수한다 — 예산이 되돌아가야 한다.
    await page.evaluate(async () => {
      await (window as any).G7Core.dispatch({
        handler: 'startCoreUpdate',
        params: { update_bundled_extensions: true, rebuild_search_index: false, layout_strategy: 'overwrite' },
      });
    });
    await page.waitForTimeout(400);

    await resumeOnce();

    expect(advanceCalls, '착수가 예산을 되돌리지 않으면 두 번째 실행은 자동 복구를 못 받는다')
      .toHaveLength(4);
  });

  /**
   * 값을 끼워 넣는 문구가 **화면에서 실제로 치환되는지** 잰다.
   *
   * 프론트 엔진은 치환 자리를 `{{name}}` / `{name}` 으로만 읽는다. 백엔드(Laravel) 규칙인
   * `:name` 을 쓰면 값이 정상 전달돼도 자리표시자가 문장 그대로 화면에 나간다 — 오류도
   * 경고도 콘솔 로그도 없다. 레이아웃 JSON 구조를 보는 검사는 전부 초록으로 통과하므로
   * **화면을 그려 봐야만** 드러난다.
   *
   * 그 문구들은 진행 중·완료·되돌리기 상태에서만 나타난다. 그래서 상태를 서버 응답으로
   * 세워 네 화면을 차례로 그려 보고, 남아 있는 자리표시자가 하나도 없음을 확인한다.
   * (실제 코어는 건드리지 않는다 — 상태 조회만 가로챈다.)
   *
   * @scenario actor=with_permission, run_state=running
   * @effects interpolated_messages_render_their_values_on_screen
   */
  test('값이 들어가는 문구가 화면에서 자리표시자로 남지 않는다', async ({ page }) => {
    const token = issueToken('core.settings.read', 'core.system.update');
    await authenticatePage(page, token);

    const base = {
      steps: [],
      total_steps: 12,
      manual_sources: [],
      modified_layouts: { has_modified: true, count: 7, extensions: ['sirsoft-board'] },
      preflight: { ok: true, blocked: [], checked: 43, disk: { measurable: true, ok: true }, errors: [] },
    };
    const failure = { step_label: '파일 적용', message: '치환에 실패했습니다.' };

    const states: Array<{ name: string; run: unknown }> = [
      { name: '진행 중', run: { status: 'running', run_id: 'e2e-ph', current_step: 'apply', completed_count: 5, logs: [], warnings: [], notices: [] } },
      { name: '완료', run: { status: 'completed', run_id: 'e2e-ph', to_version: '9.9.9', logs: [], warnings: [], notices: [] } },
      { name: '되돌림', run: { status: 'rolled_back', run_id: 'e2e-ph', error: failure, rollback: { restored: true, files_were_applied: true, failed_targets: [] }, logs: [], warnings: [], notices: [] } },
      { name: '되돌리기 실패', run: { status: 'rollback_failed', run_id: 'e2e-ph', error: failure, rollback: { restored: false, files_were_applied: true, failed_targets: ['app', 'vendor'], backup_path: '/b/1' }, logs: [], warnings: [], notices: [] } },
    ];

    let current: unknown = null;
    await page.route('**/api/admin/core-update/status*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, data: { ...base, run: current } }),
      });
    });

    await page.goto('/admin/settings?tab=update');
    await expect(page.locator('#tab_content_update')).toBeVisible({ timeout: 20_000 });

    // 자리표시자 후보: 글자·숫자가 앞서지 않는 `:name`.
    // 앞을 보는 이유는 안내 문구의 `core:update` 같은 명령어를 자리표시자로 오인하지
    // 않기 위해서다. 한국어·일본어 뒤의 문장부호 콜론(`예:`)도 같은 이유로 제외된다.
    const leftoverPlaceholders = async () =>
      page.locator('#tab_content_update').evaluate((el) =>
        (el.innerText.match(/(?<![\p{L}\p{N}]):[a-z][a-z0-9_]{2,}/gu) ?? []));

    for (const state of states) {
      current = state.run;
      await page.evaluate(async () => {
        await (window as any).G7Core.dispatch({ handler: 'refreshCoreUpdateStatus' });
      });
      await page.waitForTimeout(900);

      expect(await leftoverPlaceholders(), `${state.name} 화면에 치환되지 않은 자리표시자가 남았다`)
        .toEqual([]);
    }

    // 값이 실제로 들어갔는지도 함께 본다 — 자리표시자가 없다는 것만으로는
    // 문구에서 그 자리가 통째로 사라진 경우와 구분되지 않는다.
    current = states[0].run;
    await page.evaluate(async () => {
      await (window as any).G7Core.dispatch({ handler: 'refreshCoreUpdateStatus' });
    });
    await expect(page.locator('#tab_content_update')).toContainText('5', { timeout: 10_000 });
    await expect(page.locator('#tab_content_update')).toContainText('12');
  });

  /**
   * 화면이 등록해 둔 조작들이 **실제로 무엇을 하는지** 잰다.
   *
   * 종전에는 레이아웃 JSON 에 그 이름이 적혀 있는지만 봤다 — 함수 본문을 통째로 비워도
   * 모든 검사가 초록이었다(이 핸들러 파일을 불러오는 검사가 저장소에 하나도 없었다).
   * 배선과 동작은 다른 축이므로, 여기서는 조작을 실제로 발화시켜 **어떤 요청이 나가고
   * 화면 상태가 어떻게 바뀌는지**를 확인한다.
   *
   * 실제 코어는 건드리지 않는다 — 세 endpoint 를 모두 가로챈다.
   *
   * @scenario actor=with_permission, run_state=running
   * @effects registered_core_update_handlers_perform_their_work
   */
  test('[case:components-misc-handler-first-arg-is-action] 등록된 조작들이 실제로 상태를 읽고·이어가고·정리한다', async ({ page }) => {
    const token = issueToken('core.settings.read', 'core.system.update');
    await authenticatePage(page, token);

    const base = {
      steps: [], total_steps: 12, manual_sources: [],
      modified_layouts: { has_modified: false, count: 0, extensions: [] },
      preflight: { ok: true, blocked: [], checked: 43, disk: { measurable: true, ok: true }, errors: [] },
    };
    const RUNNING = { ...base, run: { status: 'running', run_id: 'e2e-h', current_step: 'apply', completed_count: 4, logs: [], warnings: [], notices: [] } };
    const DONE = { ...base, run: { status: 'completed', run_id: 'e2e-h', to_version: '9.9.9', logs: [], warnings: [], notices: [] } };

    // 진입 시점에는 **유휴**여야 한다 — 진행 중인 채로 들어가면 자동 이어받기(D10)가
    // 먼저 돌아 이 검사가 재려는 조작 전에 실행이 끝나 버린다.
    let phase: 'idle' | 'running' | 'done' = 'idle';
    const calls: string[] = [];

    await page.route('**/api/admin/core-update/status*', async (route) => {
      calls.push('status');
      const data = phase === 'running' ? RUNNING : (phase === 'done' ? DONE : { ...base, run: null });
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
    });
    await page.route('**/api/admin/core-update/advance', async (route) => {
      calls.push('advance');
      phase = 'done';
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: DONE }) });
    });
    await page.route('**/api/admin/core-update/dismiss', async (route) => {
      calls.push('dismiss');
      phase = 'idle';
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { ...base, run: null } }) });
    });

    await page.goto('/admin/settings?tab=update');
    await expect(page.locator('#tab_content_update')).toBeVisible({ timeout: 20_000 });

    const runState = () => page.evaluate(() =>
      (window as any).G7Core.state.get()?.coreUpdateStatus?.run?.status ?? null);
    const dispatch = (handler: string) => page.evaluate(
      async (h) => { await (window as any).G7Core.dispatch({ handler: h }); }, handler);

    // ① 상태 읽기 — 요청을 보내고 그 결과를 화면 상태에 싣는다.
    phase = 'running';
    calls.length = 0;
    await dispatch('refreshCoreUpdateStatus');
    await page.waitForTimeout(600);
    expect(calls, '상태 읽기가 요청을 보내지 않았다').toContain('status');
    expect(await runState(), '읽은 상태를 화면에 싣지 않았다').toBe('running');

    // ② 이어가기 — 진행이 끝날 때까지 단계를 민다.
    calls.length = 0;
    await dispatch('resumeCoreUpdate');
    await page.waitForTimeout(900);
    expect(calls.filter((c) => c === 'advance').length, '이어가기가 다음 단계를 부르지 않았다')
      .toBeGreaterThan(0);
    expect(await runState(), '끝난 실행이 화면에 반영되지 않았다').toBe('completed');

    // ③ 정리 — 기록을 지우고 그 결과를 화면에 싣는다.
    calls.length = 0;
    await dispatch('dismissCoreUpdate');
    await page.waitForTimeout(600);
    expect(calls, '정리가 요청을 보내지 않았다').toContain('dismiss');
    expect(await runState(), '정리한 뒤에도 실행 기록이 화면에 남았다').toBeNull();
  });

  // @scenario actor=with_permission, run_state=idle
  // @effects update_tab_buttons_carry_the_button_base_class
  test('탭의 버튼이 베이스 외형을 갖는다 (맨 <button> 으로 나가지 않는다)', async ({ page }) => {
    // 현장 제보 2026-09-12: [업데이트 확인]·[조치 방법 보기] 가 브라우저 기본 버튼으로
    // 보였다. 원인은 className 이 `btn-secondary` 뿐이어서 — 이 템플릿에서 `.btn` 이
    // padding·모서리·굵기를 주고 `.btn-*` 는 **색만** 준다.
    //
    // 이 결함은 기존 단언(존재·클릭·요청)을 하나도 깨지 않는다. 그래서 **계산된 값**을 잰다.
    // 클래스 이름만 보면 CSS 계약이 바뀌었을 때 초록인 채로 다시 깨진다.
    const token = issueToken('core.settings.read', 'core.system.update');
    await authenticatePage(page, token);

    await page.goto('/admin/settings?tab=update');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    const check = page.locator('#core_update_check_button');
    await expect(check).toBeVisible({ timeout: 20_000 });

    const box = await check.evaluate((el) => {
      const cs = getComputedStyle(el);

      return {
        paddingTop: parseFloat(cs.paddingTop),
        paddingLeft: parseFloat(cs.paddingLeft),
        borderRadius: parseFloat(cs.borderTopLeftRadius),
        fontWeight: Number(cs.fontWeight),
        hasBase: el.classList.contains('btn'),
      };
    });

    expect(box.hasBase, '베이스 클래스 btn 이 빠졌다 — 색 변형만으로는 외형이 없다').toBe(true);
    expect(box.paddingLeft, '좌우 padding 이 0 이다 (맨 button 모양)').toBeGreaterThan(0);
    expect(box.paddingTop, '상하 padding 이 0 이다 (맨 button 모양)').toBeGreaterThan(0);
    expect(box.borderRadius, '모서리가 각지다 (rounded-lg 미적용)').toBeGreaterThan(0);
    expect(box.fontWeight, '글자 굵기가 기본값이다 (font-medium 미적용)').toBeGreaterThanOrEqual(500);
  });
});

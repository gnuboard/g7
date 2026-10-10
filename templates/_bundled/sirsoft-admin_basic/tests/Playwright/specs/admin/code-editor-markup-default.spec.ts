/**
 * sirsoft-admin_basic — 코드 편집기 HTML(.g7.html) 기본화 (#476)
 *
 * 코드 편집기의 정본이 JSON 에서 HTML(`.g7.html`) 로 바뀌었다. DB 는 JSON 만 두고, 열 때 역변환
 * (`?with_markup=1`)·저장할 때 프로덕션 컴파일(`format=markup`)이며, JSON 탭은 읽기 전용 산출물이다.
 * 이 spec 은 전용 시드 화면(`e2e_sandbox`)만 저장한다 — 매 테스트가 `playwright:seed-layout` 으로 원본을 다시 설치하고 시작한다.
 *
 *  ① 진입 시 HTML 탭 활성 · Monaco 언어 html · 첫 줄 `<layout`
 *  ② JSON 탭은 읽기 전용(타이핑 불가)
 *  ③ HTML 텍스트 노드 수정 → 1초 유휴 뒤 `markup-compile` 1회 → JSON 탭에 반영
 *  ④ 잘못된 마크업 → 마커·오류 패널 · 저장 422 · lock_version 불변
 *  ⑤ 정상 수정 저장 → 200 · lock_version +1 → 새로고침 뒤 HTML 탭에 수정 유지
 *  ⑥ 파일 목록 라벨 `/\.g7\.html$/`
 *  ⑦ 확장 조각 갈래도 HTML 기본
 *  ⑧ 타이핑 직후(디바운스 창 안) 저장도 마지막 편집을 실는다
 *
 * @effects code_editor_markup_tab_is_default, code_editor_json_tab_is_read_only, code_editor_idle_compile_updates_json_view, code_editor_compile_error_blocks_save, code_editor_markup_save_persists
 *
 * **이 묶음은 `--workers=1` 로 돌린다.** `code-editor-*.spec.ts` 는 같은 시드 화면
 * (어드민 `e2e-sandbox` 시드)을 공유하면서 저장·생성·삭제까지 하므로 병렬에서 서로를 밟는다 —
 * 실측 2026-09-12: 8스펙을 기본 병렬로 돌리면 8건 실패, 같은 코드를 `--workers=1` 로 돌리면
 * 5건(그중 4건은 스펙 노후, 1건은 제품 결함)만 남았다. 병렬 결과는 실패를 만들어 내기도,
 * 가리기도 한다.
 */
import { test, expect, authenticatePage } from '../../fixtures/admin-template-auth';
import { runSeedLayoutCommand } from '../../../../../../../tests/Playwright/fixtures/seed-layout';
import type { Page } from '@playwright/test';

/** 편집기 진입 — 시드 화면의 라우트로 바로 연다(레이아웃 `init` 이 `query.route` 로 선택을 복원한다).
 *  목록에서 클릭해 고르는 방식은 100여 행이 그려지는 중에 스크롤·재렌더가 겹치면 **이웃 행**을 누른다(실측). */
const EDITOR_URL = '/admin/templates/sirsoft-admin_basic/edit?route=' + encodeURIComponent('/admin/e2e-sandbox');

// 템플릿 스위트의 설정에는 코어 스위트와 달리 globalSetup 이 없다 — 시드 화면을 여기서 직접 설치한다.
//
// **테스트마다** 설치하는 이유: 워커는 테스트 사이에 재시작될 수 있고, 그때 `beforeAll` 이 다시 돌아
// **저장과 새로고침 사이에** 시드가 행을 원본으로 되돌린다(실측 — ⑤ 가 저장 직후 원본을 봤다).
// 매 테스트가 같은 원본에서 출발하면 스케줄링과 무관해지고, 마지막 테스트 뒤에도 원본이 남는다.
test.beforeEach(() => runSeedLayoutCommand(false));

// 직렬 실행 — 이유가 둘이다. ① 여러 케이스가 **같은 시드 행**에 저장하고 `lock_version` 을 단언하므로
// 병렬이면 서로의 저장이 잠금 번호를 밀어 올린다. ② Monaco 는 동봉본(수 MB AMD 청크)을 매 워커가 새로
// 받는데, 7워커가 동시에 받으면 로컬 dev 서버에서 30초를 넘겨 "편집기를 불러오는 중" 에서 멈춘다(실측).
test.describe.configure({ mode: 'serial' });

// 기본 테스트 예산(30초)은 시드 설치 + Monaco 첫 로드 + 유휴 변환 1초 + 저장 왕복을 담기에 좁다.
test.beforeEach(({}, testInfo) => testInfo.setTimeout(120_000));

/** 유휴 변환 */
const COMPILE_URL_RE = /\/api\/admin\/templates\/sirsoft-admin_basic\/layouts\/e2e_sandbox\/markup-compile$/;

/** 저장 */
const SAVE_URL_RE = /\/api\/admin\/templates\/sirsoft-admin_basic\/layouts\/e2e_sandbox$/;

/**
 * 코드 편집기를 시드 화면으로 열고 HTML 편집기가 그려질 때까지 기다립니다.
 */
async function openSandboxEditor(page: Page, token: string): Promise<void> {
  await authenticatePage(page, token);
  await page.goto(EDITOR_URL);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  // 좌측 목록은 `role="tree"` 파일 트리다(#476) — 고정 폭 카드가 아니라 트리가 뜨는 것이 진입 신호다.
  await page.waitForSelector('[role="tree"] [role="treeitem"]', { timeout: 30_000 });
  await expect(page.locator('#editor_card').getByText('e2e_sandbox.g7.html')).toBeVisible({ timeout: 30_000 });
  // Monaco 동봉본 첫 로드가 느린 환경이 있다 — 편집기 마운트만 넉넉히 기다린다(그 뒤 단언은 기본 타임아웃).
  await expect(page.locator('#markup_editor_pane .monaco-editor').first()).toBeVisible({ timeout: 60_000 });
  await expect.poll(() => getMarkup(page), { timeout: 20_000 }).toContain('name="e2e_sandbox"');
}

/** Monaco 모델의 본문을 통째로 바꿉니다 (키 입력 대신 모델 API — 자동 닫기/들여쓰기 간섭 없음) */
async function setMarkup(page: Page, markup: string): Promise<void> {
  await page.evaluate((next) => {
    const monaco = (window as any).monaco;
    // 모델은 파일마다 따로다 — 지금 편집기에 붙은 것을 고른다(`getMarkup` 주석 참조).
    const editor = (monaco?.editor?.getEditors?.() ?? []).find(
      (e: any) => e.getModel?.()?.getLanguageId?.() === 'html',
    );
    const model = editor?.getModel?.();
    if (!model) throw new Error('html 모델을 찾지 못했습니다');
    model.setValue(next);
  }, markup);
}

/** 현재 HTML 모델 본문 */
/**
 * 지금 **편집기에 붙어 있는** HTML 모델의 본문을 읽습니다.
 *
 * `getModels()` 에서 첫 html 모델을 고르면 안 된다 — #476 이후 편집기는 **파일마다 모델을
 * 따로** 잡고(`<Editor path>`), 앞서 연 파일의 모델이 그대로 살아 있다. 그래서 확장 조각으로
 * 갈아탄 뒤에도 첫 모델(직전 레이아웃)이 잡혀 «바뀌지 않았다» 로 보인다
 * (실측 2026-09-12: 조각을 열었는데 `<layout name="e2e_sandbox"` 가 읽혔다).
 * 편집기에서 모델을 거슬러 읽으면 언제나 화면에 떠 있는 그 파일이다.
 *
 * @param page Playwright page
 * @returns 활성 HTML 모델의 본문 (없으면 빈 문자열)
 */
async function getMarkup(page: Page): Promise<string> {
  return page.evaluate(() => {
    const monaco = (window as any).monaco;
    const editor = (monaco?.editor?.getEditors?.() ?? []).find(
      (e: any) => e.getModel?.()?.getLanguageId?.() === 'html',
    );

    return editor?.getModel?.()?.getValue?.() ?? '';
  });
}

test.describe('@sirsoft-admin_basic 코드 편집기 HTML 기본화', () => {
  /**
   * @effects code_editor_markup_tab_is_default, code_editor_opens_with_decompiled_markup_buffer, code_editor_shows_g7_html_labels_and_fragment_key
   */
  test('① 진입 시 HTML 탭이 활성이고 Monaco 언어가 html 이며 첫 줄이 <layout 이다 · ⑥ 목록 라벨 .g7.html', async ({ page, layoutEditToken }) => {
    await openSandboxEditor(page, layoutEditToken);

    const state = await page.evaluate(() => {
      const monaco = (window as any).monaco;
      const models = monaco?.editor?.getModels?.() ?? [];
      const html = (monaco?.editor?.getEditors?.() ?? [])
        .find((e: any) => e.getModel?.()?.getLanguageId?.() === 'html')
        ?.getModel?.();
      return {
        languages: models.map((m: any) => m.getLanguageId?.()),
        firstLine: html?.getLineContent?.(1) ?? '',
        jsonPaneHidden: (document.querySelector('#json_editor_pane')?.className ?? '').includes('hidden'),
        markupPane: !!document.querySelector('#markup_editor_pane'),
        // 트리 행은 **마지막 세그먼트만** 보여 주고 전체 경로는 title 로 남긴다(#476).
        // `.json` 표기가 화면에 남았는지는 헤더 표기로 본다.
        headerLabels: [...document.querySelectorAll('#editor_card span')].map((e) => e.textContent ?? ''),
      };
    });

    expect(state.markupPane).toBe(true);
    // JSON 탭은 **언마운트하지 않고 덮는다**(#476) — 지우면 편집 버퍼와 Monaco 모델이 함께 날아간다.
    // 그래서 존재 여부가 아니라 `hidden` 여부로 본다.
    expect(state.jsonPaneHidden, 'JSON 탭은 활성 전에는 감춰져 있다').toBe(true);
    expect(state.languages).toContain('html');
    expect(state.firstLine.trimStart().startsWith('<layout')).toBe(true);

    const g7 = state.headerLabels.filter((t) => /\.g7\.html$/.test(t));
    const json = state.headerLabels.filter((t) => /\.json$/.test(t));
    expect(g7.length).toBeGreaterThan(0);
    expect(json.length).toBe(0);
  });

  /**
   * @effects code_editor_json_tab_is_read_only
   */
  test('② JSON 탭은 읽기 전용이다', async ({ page, layoutEditToken }) => {
    await openSandboxEditor(page, layoutEditToken);

    await page.locator('#editor_format_tabs').getByRole('tab', { name: /JSON/ }).click();
    await expect(page.locator('#json_editor_pane .monaco-editor').first()).toBeVisible({ timeout: 60_000 });

    const readOnly = await page.evaluate(() => {
      const monaco = (window as any).monaco;
      const editors = monaco?.editor?.getEditors?.() ?? [];
      const json = editors.find((e: any) => e.getModel?.()?.getLanguageId?.() === 'json');
      return json?.getOption?.(monaco.editor.EditorOption.readOnly) ?? null;
    });

    expect(readOnly).toBe(true);
  });

  /**
   * @effects code_editor_idle_compile_updates_json_view
   */
  test('③ HTML 을 고치면 1초 유휴 뒤 markup-compile 이 한 번 나가고 JSON 탭에 반영된다', async ({ page, layoutEditToken }) => {
    await openSandboxEditor(page, layoutEditToken);

    const compileCalls: number[] = [];
    page.on('request', (req) => {
      if (COMPILE_URL_RE.test(req.url()) && req.method() === 'POST') compileCalls.push(Date.now());
    });

    const original = await getMarkup(page);
    const probe = `E2E 마크업 프로브 ${Date.now()}`;
    expect(original).toContain('E2E 샌드박스');
    await setMarkup(page, original.replace('E2E 샌드박스', probe));

    await page.waitForResponse((res) => COMPILE_URL_RE.test(res.url()) && res.status() === 200, { timeout: 10_000 });
    await page.waitForTimeout(1_500);
    expect(compileCalls.length, '유휴 1초 뒤 한 번만 부른다').toBe(1);

    await page.locator('#editor_format_tabs').getByRole('tab', { name: /JSON/ }).click();
    await expect(page.locator('#json_editor_pane .monaco-editor').first()).toBeVisible({ timeout: 60_000 });

    const jsonText = await page.evaluate(() => {
      const monaco = (window as any).monaco;
      const model = monaco?.editor?.getModels?.().find((m: any) => m.getLanguageId?.() === 'json');
      return model?.getValue?.() ?? '';
    });
    expect(jsonText).toContain(probe);
    expect(await page.locator('#json_stale_badge').count()).toBe(0);
  });

  /**
   * @effects code_editor_compile_error_blocks_save, code_editor_marks_compile_errors_in_monaco_with_g7_markup_owner
   */
  test('④ 잘못된 마크업은 마커·오류 패널을 띄우고 저장이 422 로 막힌다', async ({ page, layoutEditToken }) => {
    await openSandboxEditor(page, layoutEditToken);

    const original = await getMarkup(page);
    // `<on>` 앞 주석은 산출에 실을 자리가 없어 프로덕션 컴파일이 거부한다 (comment_not_attachable)
    const broken = original.replace(
      /(<div [^>]*data-ref="e2e_sandbox_slot"[^>]*>)/,
      '$1\n      <!-- 프로브 -->\n      <on event="click"><set-state target="local" a="1"/></on>',
    );
    expect(broken).not.toBe(original);
    await setMarkup(page, broken);

    await page.waitForResponse((res) => COMPILE_URL_RE.test(res.url()) && res.status() === 422, { timeout: 10_000 });
    await expect(page.locator('#markup_error_panel')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#markup_error_panel')).toContainText('comment_not_attachable');
    // 마커는 **모델에** 붙는다. `.squiggly-error` 는 Monaco 가 화면에 들어온 줄만 그리는 장식이라,
    // 오류 줄이 스크롤 밖이면 DOM 에 없다 — 계약(`owner=g7-markup`)을 모델에서 직접 읽는다.
    const readMarkers = () =>
      page.evaluate(() => {
        const monaco = (window as any).monaco;
        const editor = (monaco?.editor?.getEditors?.() ?? []).find(
          (e: any) => e.getModel?.()?.getLanguageId?.() === 'html',
        );
        const model = editor?.getModel?.();

        if (!model) {
          return [] as { line: number; message: string }[];
        }

        return monaco.editor
          .getModelMarkers({ owner: 'g7-markup', resource: model.uri })
          .map((m: any) => ({ line: m.startLineNumber as number, message: String(m.message) }));
      });

    // 마커 메시지는 사람이 읽는 안내문이다 — 코드(`comment_not_attachable`) 는 오류 패널이 들고 있고,
    // 여기서 확인할 것은 «그 오류가 편집기 모델에 `g7-markup` 주인으로 찍혔는가» 다.
    await expect.poll(async () => (await readMarkers()).length, { timeout: 10_000 }).toBeGreaterThan(0);
    expect((await readMarkers())[0].message, '마커에 사람이 읽을 사유가 없다').not.toBe('');

    // 사람이 실제로 볼 수 있는가 — 그 줄로 스크롤한 뒤 물결선이 그려지는지 본다.
    const markerLine = (await readMarkers())[0].line;
    await page.evaluate((line: number) => {
      const monaco = (window as any).monaco;
      const editor = (monaco?.editor?.getEditors?.() ?? []).find((e: any) => e.getModel()?.getLanguageId?.() === 'html');
      editor?.revealLineInCenter?.(line);
    }, markerLine);

    await expect
      .poll(async () => page.evaluate(() => document.querySelectorAll('.squiggly-error').length), { timeout: 10_000 })
      .toBeGreaterThan(0);

    const savePromise = page.waitForResponse((res) => SAVE_URL_RE.test(res.url()) && res.request().method() === 'PUT', { timeout: 20_000 });
    await page.getByTestId('layout-save').click();
    const save = await savePromise;
    expect(save.status()).toBe(422);
    const body = await save.json();
    expect(body.compile_error?.code).toBe('comment_not_attachable');
  });

  /**
   * @effects code_editor_markup_save_persists, code_editor_saves_markup_format
   */
  test('⑤ 정상 수정을 저장하면 200 · lock_version +1 이고 새로고침 뒤 HTML 탭에 유지된다', async ({ page, layoutEditToken }) => {
    await openSandboxEditor(page, layoutEditToken);

    const lockVersion = await page.evaluate(async () => {
      const G7Core = (window as any).G7Core;
      const res = await G7Core.api.get('/api/admin/templates/sirsoft-admin_basic/layouts/e2e_sandbox');
      return res?.data?.lock_version ?? res?.lock_version;
    });

    const original = await getMarkup(page);
    const probe = `E2E 저장 프로브 ${Date.now()}`;
    await setMarkup(page, original.replace('sandbox slot', probe));

    // 타이핑이 멈춘 뒤 유휴 변환(1초)이 한 번 돌 때까지 기다린다 = 실제 사용 흐름.
    // 편집 버퍼 `editorMarkup` 은 300ms 디바운스로 갱신되므로, 고친 직후(수 ms) 저장을 누르면 저장 본문이
    // 직전 버퍼가 되어 **마지막 편집이 빠진 채 200 이 돌아온다**(실측). 이 창은 이번 변경 이전의 JSON 편집기에도
    // 같은 모양으로 있었다 — 자세한 관찰은 이력 문서 §7-1.
    await page.waitForResponse((res) => COMPILE_URL_RE.test(res.url()) && res.status() === 200, { timeout: 15_000 });

    const savePromise = page.waitForResponse((res) => SAVE_URL_RE.test(res.url()) && res.request().method() === 'PUT', { timeout: 20_000 });
    await page.getByTestId('layout-save').click();
    const save = await savePromise;
    expect(save.status()).toBe(200);
    const saved = await save.json();
    expect(saved.data.lock_version).toBe(Number(lockVersion) + 1);
    expect(saved.data.markup_warnings).toEqual([]);

    await page.reload();
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await expect(page.locator('#markup_editor_pane .monaco-editor').first()).toBeVisible({ timeout: 60_000 });
    await expect.poll(() => getMarkup(page), { timeout: 20_000 }).toContain(probe);

    // 저장 뒤 서빙 API 에도 실린다 (3단 영속)
    const served = await page.evaluate(async () => {
      const G7Core = (window as any).G7Core;
      const res = await G7Core.api.get('/api/admin/templates/sirsoft-admin_basic/layouts/e2e_sandbox');
      return JSON.stringify(res);
    });
    expect(served).toContain(probe);
  });

  /**
   * @effects code_editor_saves_the_last_edit_inside_the_debounce_window
   */
  test('⑧ 타이핑 직후(디바운스 창 안)에 저장해도 마지막 편집이 실린다', async ({ page, layoutEditToken }) => {
    await openSandboxEditor(page, layoutEditToken);

    const original = await getMarkup(page);
    const probe = `E2E 즉시저장 프로브 ${Date.now()}`;

    // 편집 버퍼는 `set-state :debounce="300"` 으로 갱신된다. 고친 **직후**(대기 없이) 저장을 누르면
    // 엔진이 대기분을 flush 하는데, 종전에는 저장 액션이 읽는 데이터 문맥이 flush 이전 스냅샷이라
    // 마지막 편집이 빠진 채 200 이 돌아왔다 — 화면에는 성공으로 보여 발견이 늦는 형태다.
    const savePromise = page.waitForResponse(
      (res) => SAVE_URL_RE.test(res.url()) && res.request().method() === 'PUT',
      { timeout: 20_000 },
    );

    await setMarkup(page, original.replace('sandbox slot', probe));
    await page.getByTestId('layout-save').click();

    const save = await savePromise;
    expect(save.status()).toBe(200);

    // 요청 본문이 **방금 친 글자**를 담고 있어야 한다 (응답 상태만으로는 누락이 보이지 않는다).
    expect(save.request().postData() ?? '').toContain(probe);

    await page.reload();
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await expect(page.locator('#markup_editor_pane .monaco-editor').first()).toBeVisible({ timeout: 60_000 });
    await expect.poll(() => getMarkup(page), { timeout: 20_000 }).toContain(probe);
  });

  /**
   * @effects code_editor_opens_extension_fragments_in_the_markup_tab
   */
  test('⑦ 확장 조각 갈래도 HTML 탭이 기본이다', async ({ page, layoutEditToken }) => {
    await openSandboxEditor(page, layoutEditToken);

    // 확장 조각은 트리의 `ext:` 접두 노드다 — 묶음 폴더를 먼저 펼쳐야 보인다.
    const extensionSection = page.locator('[role="treeitem"]').filter({ hasText: /확장|Extension/ }).first();

    if (await extensionSection.count() > 0) {
      await extensionSection.click();
    }

    const extensionRow = page
      .locator('[role="treeitem"]')
      .filter({ has: page.locator('i.fa-circle-plus') })
      .first();
    if ((await extensionRow.count()) === 0) {
      // 묶음(모듈·플러그인) 폴더가 접혀 있으면 한 단계 더 펼친다.
      const groups = page.locator('[role="treeitem"][aria-expanded="false"]');

      for (let index = 0; index < (await groups.count()); index += 1) {
        await groups.nth(index).click();
      }
    }

    test.skip((await extensionRow.count()) === 0, '이 템플릿에 등록된 확장 조각이 없다 — 갈래 자체가 없으면 검증 대상이 없다');

    await extensionRow.click();

    // 편집기는 이미 떠 있으므로 `.monaco-editor` 가시성은 **클릭 전에도 참**이다 — 바뀌는 값(본문)을 기다린다.
    // 조각 문서의 봉투는 `<extension>` 이지만 그 앞에 파일 헤더 주석이 오는 것이 보통이라 포함 여부로 본다.
    await expect
      .poll(() => getMarkup(page), { timeout: 30_000 })
      .toContain('<extension ');

    const markup = await getMarkup(page);
    expect(markup, 'HTML 탭이 기본이므로 JSON 원문이 아니라 마크업이 열린다').not.toContain('"target_layout":');
    expect(await page.locator('#markup_editor_pane').count(), '확장 갈래도 HTML 탭이 기본이다').toBe(1);
    await expect(page.locator('#json_editor_pane'), 'JSON 탭은 활성 전에는 감춰져 있다').toHaveClass(/hidden/);
  });
});

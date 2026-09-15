/**
 * Layout Editor — image 위젯 값 형태(단일 값 슬롯 축약 / 표시모드 억제 / 표현식 보호).
 *
 * 결함(공개 #135): 코어 `image` 위젯이 내보내는 `{url,size,repeat,position}` 객체를
 * `propValue` 경로가 그대로 `props[key]` 에 기록해, 소비 컴포넌트가 `<Img src={객체}>` 를
 * 받고 브라우저가 `[object Object]` 를 URL 로 해석했다. 이 결함은 **브라우저에서만**
 * 드러난다 — 깨진 요청은 SPA catch-all 때문에 404 조차 아니라 200(HTML)을 받고, 편집기
 * 위젯의 미리보기는 정상이며, 서버 로그에도 흔적이 없다. 화면의 엑박이 유일한 증상이다.
 *
 * 단위(Vitest)는 `applyRecipe` 반환 노드까지만 볼 수 있어 "실제 `<img>` 의 src 가 문자열이고
 * naturalWidth > 0" 을 포착하지 못한다. 그래서 캔버스 DOM 을 직접 재는 spec 을 둔다.
 *
 * 축 요약(마커 아님 — 평문): image_propvalue_narrowing, display_mode_suppression, binding_expression_guard, styleprop_bundle_regression.
 * 효과 요약(마커 아님 — 평문): image_object_narrows_to_url_string_in_propvalue_slot, single_value_slot_control_hides_display_mode_buttons_entirely, bound_value_shows_expression_badge_and_locks_destructive_controls, styleprop_bundle_four_property_decomposition_is_unchanged.
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import { SANDBOX_ROUTE, sandboxRouteParam } from '../../fixtures/seed-layout';
import type { Page } from '@playwright/test';

const EDITOR_URL = '/admin/layout-editor/sirsoft-basic?route=%2F';

/**
 * 공통 레이아웃 편집 모드 — `Header` 의 「로고 이미지」는 `_user_base` 소유다.
 *
 * 라우트 모드에서 그 노드는 **상속(base) 출처**라 ⓘ 대신 「🔒 공통 레이아웃 편집」이 뜬다.
 * 편집해도 저장 시 마스킹이 통째로 폐기하므로 편집 표면을 열지 않는 것이 계약이다
 * (계획서 D9~D11). 따라서 그 컨트롤을 여는 정상 경로는 이 모드다.
 */
const BASE_EDITOR_URL = '/admin/layout-editor/sirsoft-basic?edit=__base__%2F_user_base';

/**
 * 편집기에 진입해 캔버스 편집 노드가 렌더될 때까지 기다린다.
 */
async function openEditor(page: Page, url: string = EDITOR_URL): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto(url);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });
  await page.waitForFunction(
    () => document.querySelectorAll('[data-editor-path]').length > 0,
    { timeout: 20_000 },
  );
}

/**
 * 캔버스 위임 click 핸들러로 노드를 선택한다. 패널/오버레이가 좌표를 가로채는 것을 피해
 * 편집기가 실제로 받는 pointer/click 시퀀스를 노드 중앙에 직접 발사한다.
 */
async function selectNode(page: Page, path: string): Promise<void> {
  await page.evaluate((p) => {
    const el = document.querySelector(`[data-editor-path="${p}"]`);
    if (!el) return;
    el.scrollIntoView({ block: 'center' });
    const r = el.getBoundingClientRect();
    const cx = r.left + r.width / 2;
    const cy = r.top + r.height / 2;
    for (const type of ['pointerover', 'pointermove', 'pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click']) {
      el.dispatchEvent(new MouseEvent(type, { bubbles: true, clientX: cx, clientY: cy }));
    }
  }, path);
}

/** ⓘ → 「속성 설정」 → 속성 편집 모달. 지정한 탭을 연다. */
async function openPropertyTab(page: Page, tab: 'props' | 'style'): Promise<void> {
  await page.waitForSelector('[data-testid="g7le-overlay-info-button"]', { timeout: 10_000 });
  await page.getByTestId('g7le-overlay-info-button').click();
  await page.waitForSelector('[data-testid="g7le-context-menu-edit-props"]', { timeout: 5_000 });
  await page.getByTestId('g7le-context-menu-edit-props').click();
  await page.waitForSelector('[data-testid="g7le-property-modal"]', { timeout: 10_000 });
  await page.getByTestId(`g7le-property-tab-${tab}`).click();
}

/**
 * `image` 위젯이 열린 상태에서 **단일 값 슬롯 컨트롤**을 찾을 수 있는 노드를 고른다.
 *
 * 리터럴 `data-editor-path` 를 쓰지 않는다 — 베이스 루트 인덱스가 밀리면 통째로 깨진다.
 * 대신 캔버스에서 `Header` 컴포넌트를 이름으로 찾는다(로고 컨트롤의 소유 컴포넌트).
 */
async function findHeaderPath(page: Page): Promise<string | null> {
  return page.evaluate(() => {
    const el = document.querySelector('[data-editor-path][data-editor-name="Header"]');
    return el?.getAttribute('data-editor-path') ?? null;
  });
}

/**
 * 시드 화면이 소유한 `Header` 의 편집 경로를 id 로 지목한다.
 *
 * 시드 화면은 `_user_base` 를 extends 하므로 캔버스에 `Header` 가 **둘** 뜬다 — 상속받은
 * 베이스 헤더(잠금: ⓘ 대신 「공통 레이아웃 편집」)와 시드가 직접 둔 라우트 소유 헤더.
 * 이름으로만 찾으면 앞의 잠긴 쪽이 잡혀 속성 모달에 도달하지 못한다.
 */
async function findSandboxHeaderPath(page: Page): Promise<string | null> {
  return page.evaluate(() => {
    const el = document.querySelector('[data-editor-path][data-editor-id="e2e_sandbox_header"]');
    return el?.getAttribute('data-editor-path') ?? null;
  });
}

/** 배경이 보일 만한 박스형 Div — styleProp 묶음(회귀 가드)의 대상 */
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

/**
 * 저장 페이로드에서 `logo` prop 값을 찾아 돌려준다 (트리 어디에 있든).
 *
 * 노드 경로를 리터럴로 박으면 베이스 레이아웃 구조가 한 칸만 바뀌어도 통째로 깨지므로
 * 키 이름으로 전역 탐색한다.
 *
 * @param value 저장 요청 body (JSON)
 * @returns 찾은 `logo` 값, 없으면 undefined
 */
function findLogoProp(value: unknown): unknown {
  // 저장 페이로드의 `content` 는 **JSON 문자열**로 실린다 — 파싱하지 않으면 트리를 볼 수 없다.
  if (typeof value === 'string') {
    if (!value.trim().startsWith('{')) return undefined;
    try {
      return findLogoProp(JSON.parse(value));
    } catch {
      return undefined;
    }
  }
  if (Array.isArray(value)) {
    for (const item of value) {
      const hit = findLogoProp(item);
      if (hit !== undefined) return hit;
    }
    return undefined;
  }
  if (!value || typeof value !== 'object') return undefined;

  const record = value as Record<string, unknown>;
  const props = record.props;
  if (props && typeof props === 'object' && 'logo' in (props as Record<string, unknown>)) {
    return (props as Record<string, unknown>).logo;
  }
  for (const child of Object.values(record)) {
    const hit = findLogoProp(child);
    if (hit !== undefined) return hit;
  }
  return undefined;
}

/** 1x1 PNG — 첨부 업로드용 최소 실물 이미지(naturalWidth>0 을 재려면 실제 파일이어야 한다) */
const PNG_1PX = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64',
);

test.describe('@layout-editor image 위젯 값 형태 (공개 #135)', () => {
  /** @effects image_object_narrows_to_url_string_in_propvalue_slot, single_value_slot_control_hides_display_mode_buttons_entirely */
  test('로고 이미지 URL 입력 → 캔버스 img src 가 문자열, [object Object] 0건, 모드 버튼 미노출', async ({ page }) => {
    await openEditor(page, BASE_EDITOR_URL);

    const headerPath = await findHeaderPath(page);
    test.skip(headerPath === null, '캔버스에서 Header 노드를 찾지 못했습니다');
    await selectNode(page, headerPath as string);
    await openPropertyTab(page, 'props');

    const urlInput = page.getByTestId('g7le-image-url');
    test.skip(!(await urlInput.isVisible().catch(() => false)), '로고 이미지 컨트롤이 노출되지 않았습니다');

    // 저장값이 데이터 연결 표현식이면 컨트롤은 보호 상태로 뜬다(입력칸 readOnly).
    // 값을 바꾸려면 「직접 지정으로 바꾸기」로 명시적으로 열어야 한다 — 그 한 번의
    // 의사표시 없이는 환경설정과의 연결이 덮이지 않는다는 것이 계약이다.
    const replace = page.getByTestId('g7le-image-expression-replace');
    if (await replace.isVisible().catch(() => false)) {
      await replace.click();
      // 해제되면 되돌리기 경로가 함께 열린다(편도 금지).
      await expect(page.getByTestId('g7le-image-expression-restore')).toBeVisible();
    }

    // 단일 값 슬롯(propValue) 이므로 표시모드 버튼은 **컨테이너째** 미렌더되어야 한다.
    // `disabled` 로 남기면 "URL 을 넣으면 살아나겠지" 라는 거짓 정보를 준다.
    await expect(page.getByTestId('g7le-image-modes')).toHaveCount(0);
    await expect(page.getByTestId('g7le-image-mode-fill')).toHaveCount(0);

    const url = '/api/templates/sirsoft-basic/layout-attachments/5/file';
    await urlInput.fill(url);
    await urlInput.blur();

    // 핵심 검증 — 캔버스의 실제 <img> 가 문자열 src 를 받아야 한다.
    // 종전에는 여기에 "[object Object]" 가 들어가 엑박이 됐고, SPA catch-all 때문에
    // 그 요청은 404 조차 아니라 200(HTML) 이라 네트워크 탭으로도 드러나지 않았다.
    await expect
      .poll(
        async () =>
          page.evaluate((p) => {
            const host = document.querySelector(`[data-editor-path="${p}"]`);
            const img = host?.querySelector('img');
            return img?.getAttribute('src') ?? '';
          }, headerPath),
        { timeout: 8_000 },
      )
      .toContain('layout-attachments/5/file');

    const objectObjectCount = await page.evaluate(() =>
      Array.from(document.images).filter((i) => (i.getAttribute('src') ?? '').includes('[object Object]')).length,
    );
    expect(objectObjectCount, '문서 어디에도 [object Object] src 가 없어야 합니다').toBe(0);
  });

  /**
   * 저장 → 사용자 화면 3단 — 이 결함의 **최종 소비 지점**이다.
   *
   * 위 케이스는 편집기 캔버스까지만 본다. 그런데 #135 가 실제로 드러난 자리는 저장 이후의
   * 사용자 화면이고, 깨진 요청은 SPA catch-all 때문에 404 조차 아니라 200(HTML) 이라
   * 네트워크 탭·서버 로그 어디에도 흔적이 없다. 그래서 「저장 페이로드가 문자열인가」와
   * 「그 문자열이 실제로 그려지는가(naturalWidth>0)」를 여기서 함께 잰다.
   *
   * 대상은 **시드 화면(`e2e_sandbox`)** 이다. 이 spec 은 저장(PUT)까지 수행하므로 제품
   * 레이아웃(`_user_base`)을 쓰면 편집 결과가 그대로 영속돼 개발 사이트가 오염된다
   * (`fixtures/seed-layout.ts` 규약). 시드 화면에 라우트 소유 `Header` 를 두어 로고 컨트롤을
   * 라우트 모드에서 열 수 있게 했고, 시드는 globalTeardown 이 통째로 제거한다.
   *
   * @effects image_object_narrows_to_url_string_in_propvalue_slot, img_string_src_passes_through_unchanged
   */
  test('저장 → 사용자 화면에서 문자열 src 로 실제 렌더된다', async ({ page }) => {
    // 편집기 진입 + 저장 왕복 + 사용자 화면 재방문 3단이라 기본 30s 로는 부족하다.
    test.setTimeout(120_000);

    // 실제로 그려지는지(naturalWidth>0)를 재려면 실존하는 이미지가 필요하다. 고정 id 를
    // 박으면 그 첨부가 지워진 환경에서 조용히 0 이 되므로, 이 spec 이 직접 올리고 지운다.
    const token = issueToken('core.templates.layouts.edit');
    const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };
    const upload = await page.request.post('/api/admin/templates/sirsoft-basic/layout-attachments', {
      headers,
      multipart: {
        file: { name: 'e2e-logo.png', mimeType: 'image/png', buffer: PNG_1PX },
      },
    });
    test.skip(!upload.ok(), `첨부 업로드 실패(${upload.status()})`);
    const attachmentId = (await upload.json())?.data?.id;
    test.skip(!attachmentId, '첨부 id 를 받지 못했습니다');
    const url = `/api/templates/sirsoft-basic/layout-attachments/${attachmentId}/file`;

    try {
    await openEditor(page, `/admin/layout-editor/sirsoft-basic?route=${sandboxRouteParam()}`);

    const headerPath = await findSandboxHeaderPath(page);
    test.skip(headerPath === null, '시드 화면에서 Header 노드를 찾지 못했습니다');
    await selectNode(page, headerPath as string);
    await openPropertyTab(page, 'props');

    const urlInput = page.getByTestId('g7le-image-url');
    test.skip(!(await urlInput.isVisible().catch(() => false)), '로고 이미지 컨트롤이 노출되지 않았습니다');

    const replace = page.getByTestId('g7le-image-expression-replace');
    if (await replace.isVisible().catch(() => false)) await replace.click();

    await urlInput.fill(url);
    await urlInput.blur();

    // 속성 모달을 닫고 툴바에서 저장한다.
    await page.getByTestId('g7le-property-modal-done').click();

    // 저장 페이로드의 `logo` 가 **문자열**이어야 한다 — 객체면 수정 전 상태다.
    const [put] = await Promise.all([
      page.waitForResponse(
        (r) => r.url().includes('/layouts/e2e_sandbox') && r.request().method() === 'PUT',
        { timeout: 30_000 },
      ),
      page.getByTestId('g7le-toolbar-save').click(),
    ]);
    expect(put.status(), '저장이 200 이어야 합니다').toBe(200);

    const logo = findLogoProp(put.request().postDataJSON());
    expect(typeof logo, `저장 페이로드의 logo 가 문자열이어야 합니다 (실제: ${JSON.stringify(logo)})`).toBe('string');
    expect(logo).toContain('layout-attachments/5/file');

    // 3단 — 사용자 화면에서 실제로 그려지는가.
    await page.goto(SANDBOX_ROUTE['sirsoft-basic']);
    await page.waitForLoadState('domcontentloaded');

    const measured = await page.evaluate(async () => {
      await new Promise((r) => setTimeout(r, 1500));
      const imgs = Array.from(document.images);
      const hit = imgs.find((i) => (i.getAttribute('src') ?? '').includes('layout-attachments/5/file'));
      return {
        src: hit?.getAttribute('src') ?? null,
        natural: hit?.naturalWidth ?? 0,
        objectObject: imgs.filter((i) => (i.getAttribute('src') ?? '').includes('[object Object]')).length,
      };
    });

    expect(measured.objectObject, '사용자 화면에 [object Object] src 가 없어야 합니다').toBe(0);
    expect(measured.src, '사용자 화면의 로고 src 가 저장한 문자열이어야 합니다').toContain(
      'layout-attachments/5/file',
    );
    expect(measured.natural, '로고가 실제로 로드되어야 합니다(naturalWidth>0)').toBeGreaterThan(0);
    } finally {
      // 올린 첨부는 반드시 지운다 — 실패로 중단돼도 서버에 잔여물을 남기지 않는다.
      await page.request
        .delete(`/api/admin/templates/layout-attachments/${attachmentId}`, { headers })
        .catch(() => undefined);
    }
  });

  /** @effects bound_value_shows_expression_badge_and_locks_destructive_controls */
  test('저장값이 데이터 연결 표현식이면 원문 배지 + 파괴적 조작 잠금', async ({ page }) => {
    await openEditor(page, BASE_EDITOR_URL);

    const headerPath = await findHeaderPath(page);
    test.skip(headerPath === null, '캔버스에서 Header 노드를 찾지 못했습니다');
    await selectNode(page, headerPath as string);
    await openPropertyTab(page, 'props');

    const badge = page.getByTestId('g7le-image-expression');
    // 번들 레이아웃의 기본 저장값은 사이트 로고 설정 표현식이다. 표현식 상태가 아니면
    // (운영자가 이미 고정 URL 을 넣은 사이트) 이 축은 검증 대상이 아니다.
    test.skip(!(await badge.isVisible().catch(() => false)), '로고가 표현식 상태가 아닙니다');

    // 원문이 그대로 보여야 한다 — 빈 피커로 보이면 업로드 1클릭에 표현식이 소리 없이 사라진다.
    await expect(badge).toContainText('{{');
    // 미리보기는 깨지므로 렌더하지 않는다(거짓 미리보기 금지).
    await expect(page.getByTestId('g7le-image-preview')).toHaveCount(0);
    // 파괴적 조작은 잠긴다 — 단, 「직접 지정으로 바꾸기」로 즉시 해제되는 일시적 상태다.
    await expect(page.getByTestId('g7le-image-clear')).toBeDisabled();
    await expect(page.getByTestId('g7le-image-url')).toHaveAttribute('readonly', /.*/);

    await page.getByTestId('g7le-image-expression-replace').click();
    await expect(page.getByTestId('g7le-image-expression')).toHaveCount(0);
    await expect(page.getByTestId('g7le-image-clear')).toBeEnabled();
  });

  /** @effects styleprop_bundle_four_property_decomposition_is_unchanged */
  test('회귀 가드 — 배경 이미지(styleProp 묶음) 컨트롤은 모드 버튼과 4속성 분해를 유지', async ({ page }) => {
    await openEditor(page);

    const targetPath = await pickBoxAreaPath(page);
    test.skip(targetPath === null, '배경을 넣을 박스형 Div 를 찾지 못했습니다');
    await selectNode(page, targetPath as string);
    await openPropertyTab(page, 'style');

    const urlInput = page.getByTestId('g7le-image-url');
    test.skip(!(await urlInput.isVisible().catch(() => false)), '배경 이미지 컨트롤이 노출되지 않았습니다');

    // 다중 props 묶음이라 표시모드 버튼은 그대로 있어야 한다(단일 슬롯 억제가 여기까지
    // 번지면 배경 편집 기능이 통째로 죽는다).
    await expect(page.getByTestId('g7le-image-modes')).toBeVisible();

    const url = 'https://example.com/api/templates/sirsoft-basic/layout-attachments/5/file';
    await urlInput.fill(url);
    await urlInput.blur();

    await expect
      .poll(
        async () =>
          page.evaluate((p) => {
            const el = document.querySelector(`[data-editor-path="${p}"]`);
            return el ? getComputedStyle(el).backgroundImage : '';
          }, targetPath),
        { timeout: 8_000 },
      )
      .toContain('layout-attachments/5/file');

    const snap = await page.evaluate((p) => {
      const el = document.querySelector(`[data-editor-path="${p}"]`) as HTMLElement | null;
      const cs = el ? getComputedStyle(el) : null;
      return { size: cs?.backgroundSize, repeat: cs?.backgroundRepeat, image: cs?.backgroundImage };
    }, targetPath);
    expect(snap.size).toBe('cover');
    expect(snap.repeat).toBe('no-repeat');
    expect(snap.image, 'CSS 값 문맥이므로 url(...) 로 감싸져야 합니다').toContain('url(');
    expect(snap.image).not.toContain('[object Object]');
  });
});

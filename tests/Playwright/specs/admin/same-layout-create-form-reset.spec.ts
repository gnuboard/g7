/**
 * E2E: 같은 레이아웃을 쓰는 수정 → 등록 SPA 전환 시 등록 폼 잔존 없음 (트러블슈팅 state-global 사례 7)
 *
 * extends 기반 관리자 폼은 수정 라우트와 등록 라우트가 한 레이아웃을 공유한다. 엔진은 layout_name
 * 이 바뀔 때만 `_local` 을 비우므로, 수정 화면에서 등록 화면으로 SPA 이동하면 직전 레코드의 `form`
 * 이 등록 폼에 남았다(역할·상품·배송정책·게시글·게시판 — 2026-09-14 실측). 각 레이아웃이 생성 모드
 * init_actions 에서 `form` 을 비우고(데이터소스가 폼을 채우는 화면은 initLocal 을 replace 맵으로)
 * 등록 폼이 등록 화면 직접 진입과 같아지도록 대응했다.
 *
 * 레이아웃 JSON 구조 테스트(각 확장 `__tests__/layouts`)는 액션·데이터소스 선언을 잠그고, 이 spec 은
 * 실제 base 레이아웃·실제 데이터소스·실제 init_actions 경로를 거쳐 "SPA 도착 등록 폼 = 직접 진입
 * 등록 폼" 이 화면과 전역 상태에서 성립하는지를 본다. `page.goto` 로 두 화면을 따로 열면 전역 상태가
 * 통째로 비워져 결함이 나타나지 않으므로, 메뉴 클릭과 같은 경로인 라우터 navigate 로 이동한다.
 *
 * 대상 레코드는 관리자 목록 API 에서 첫 건을 읽어 쓴다 — 시드 id 를 박아 두면 다른 개발 DB 에서
 * 조용히 실패하거나 없는 화면을 열게 된다.
 *
 * // @scenario surface=admin_forms, transition=spa_navigate_edit_to_create, scope=page
 * // @effects spa_create_form_has_no_previous_record, spa_create_form_matches_direct_entry, copy_payload_survives_clear, no_page_errors
 */
import { test, expect, issueToken, authenticatePage, type Page } from '../../fixtures/auth';

const PERMISSIONS = [
  'core.permissions.read',
  'core.users.read',
  'sirsoft-ecommerce.products.read',
  'sirsoft-ecommerce.shipping-policies.read',
  'sirsoft-board.boards.read',
];

type FormSnapshot = {
  path: string;
  formKeys: string[];
  id: unknown;
  code: string | null;
  slug: string | null;
  name: string | null;
  filledInputs: number;
  hasChanges: unknown;
};

/**
 * 전역 `_local.form` 의 키 집합·식별자·이름과, 값이 들어 있는 텍스트 입력칸 수를 읽습니다.
 *
 * @param page Playwright 페이지
 * @return 폼 스냅샷
 */
async function readForm(page: Page): Promise<FormSnapshot> {
  return page.evaluate(() => {
    const local = (window as any).G7Core?.state?.get?.()?._local ?? {};
    const form = local.form && typeof local.form === 'object' ? local.form : {};
    const nameOf = (value: unknown): string | null =>
      typeof value === 'string' ? value : value && typeof value === 'object' ? JSON.stringify(value) : null;
    const filled = Array.from(document.querySelectorAll('input')).filter(
      (input) => !['hidden', 'checkbox', 'radio', 'file', 'submit', 'button'].includes(input.type) && input.value !== ''
    ).length;
    return {
      path: location.pathname + location.search,
      formKeys: Object.keys(form).sort(),
      id: form.id ?? null,
      code: typeof form.product_code === 'string' ? form.product_code : null,
      slug: typeof form.slug === 'string' ? form.slug : null,
      name: nameOf(form.name) ?? nameOf(form.title),
      filledInputs: filled,
      hasChanges: local.hasChanges ?? null,
    };
  });
}

/**
 * 전역 상태가 안정될 때까지(폼이 그려지고 연속 세 스냅샷이 같을 때까지) 기다립니다.
 *
 * 병렬 워커로 서버가 느려지면 URL 이 바뀐 직후에도 이전 화면의 `_local.form` 이 한동안 남아
 * 있다 — 두 스냅샷만 비교하면 그 잠복 구간을 "안정" 으로 오판해 옛 폼을 읽는다.
 *
 * @param page Playwright 페이지
 * @return void
 */
async function waitStable(page: Page): Promise<void> {
  await page
    .waitForFunction(
      () => Boolean((window as any).G7Core?.state?.get?.()?._local) && document.querySelectorAll('input').length > 0,
      null,
      { timeout: 30_000 }
    )
    .catch(() => undefined);
  let previous: string | null = null;
  let sameCount = 0;
  const started = Date.now();
  while (Date.now() - started < 30_000) {
    await page.waitForTimeout(700);
    const current = JSON.stringify(await readForm(page));
    sameCount = current === previous ? sameCount + 1 : 0;
    if (sameCount >= 2) return;
    previous = current;
  }
}

/**
 * 메뉴 클릭과 같은 경로(라우터 navigate)로 SPA 이동한 뒤, 도착 화면의 폼이 이전 화면의 폼과
 * 달라지고 상태가 안정될 때까지 기다립니다.
 *
 * URL 은 라우터가 즉시 바꾸지만 레이아웃·데이터 로드는 그 뒤에 일어나므로, 이전 폼 스냅샷이
 * 바뀌는 것을 먼저 확인해야 잠복 구간의 옛 폼을 읽지 않는다.
 *
 * @param page Playwright 페이지
 * @param path 이동할 경로
 * @return void
 */
async function spaNavigate(page: Page, path: string): Promise<void> {
  const before = JSON.stringify(await readForm(page));
  await page.evaluate((target) => {
    (window as any).__templateApp.router.navigate(target);
  }, path);
  await page.waitForURL(`**${path}**`, { timeout: 30_000 });
  await expect
    .poll(async () => JSON.stringify(await readForm(page)), { timeout: 30_000, message: '도착 화면의 폼이 이전 화면 폼에서 바뀌지 않았다' })
    .not.toBe(before);
  await waitStable(page);
}

/**
 * 관리자 목록 API 의 첫 레코드를 읽습니다.
 *
 * @param page Playwright 페이지
 * @param token 발급된 토큰
 * @param url 목록 API 경로
 * @return 첫 레코드
 */
async function listRecords(page: Page, token: string, url: string): Promise<any[]> {
  const response = await page.request.get(url, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  });
  if (!response.ok()) throw new Error(`${url} → HTTP ${response.status()}`);
  const json = await response.json();
  if (Array.isArray(json?.data?.data)) return json.data.data;
  if (Array.isArray(json?.data)) return json.data;
  return Array.isArray(json) ? json : [];
}

/**
 * 수정 화면(전체 로드) → 등록 화면(SPA) → 등록 화면(직접 진입) 순서로 폼을 읽습니다.
 *
 * @param page Playwright 페이지
 * @param editPath 수정 화면 경로
 * @param createPath 등록 화면 경로
 * @return 세 시점의 폼 스냅샷
 */
async function editThenCreate(
  page: Page,
  editPath: string,
  createPath: string
): Promise<{ edit: FormSnapshot; spa: FormSnapshot; direct: FormSnapshot; logs: string[] }> {
  const logs: string[] = [];
  const onConsole = (message: { text(): string }) => {
    const text = message.text();
    if (/initLocal|_localInit|\[setLocal\]|_local reset|initActions executed/.test(text)) logs.push(text.slice(0, 120));
  };
  page.on('console', onConsole);
  try {
    await page.goto(editPath);
    // 수정 화면의 데이터소스 응답이 모두 도착한 뒤에 이동한다 — 이전 화면의 늦은 응답이 도착
    // 화면에 닿는 것은 별개의 경로(늦은 응답)라 이 spec 의 판정 대상이 아니다.
    await page.waitForLoadState('networkidle', { timeout: 30_000 }).catch(() => undefined);
    await waitStable(page);
    const edit = await readForm(page);

    logs.length = 0;
    await spaNavigate(page, createPath);
    const spa = await readForm(page);
    const transitionLogs = logs.slice();

    await page.goto(createPath);
    await page.waitForLoadState('networkidle', { timeout: 30_000 }).catch(() => undefined);
    await waitStable(page);
    const direct = await readForm(page);
    return { edit, spa, direct, logs: transitionLogs };
  } finally {
    page.off('console', onConsole);
  }
}

/**
 * SPA 도착 등록 폼이 직접 진입 등록 폼과 같은지 단언합니다.
 *
 * @param result editThenCreate 결과
 * @param createPath 등록 화면 경로
 * @param pageErrors 수집된 페이지 에러
 * @return void
 */
function expectCleanCreateForm(
  result: { edit: FormSnapshot; spa: FormSnapshot; direct: FormSnapshot; logs: string[] },
  createPath: string,
  pageErrors: string[]
): void {
  expect(result.spa.path).toBe(createPath);
  expect(
    result.spa.id,
    `직전 레코드 id 가 등록 폼에 남았다 (도착 form ${result.spa.formKeys.length}키 / 직접 ${result.direct.formKeys.length}키; 전환 로그: ${result.logs.join(' | ')})`
  ).toBeNull();
  // `*_mode` 키는 CKEditor 플러그인이 에디터 생성 완료 뒤 setLocal 로 쓰는 값이다. 편집기가 있는 수정
  // 화면을 떠난 직후 그 늦은 쓰기가 도착 화면의 form 에 떨어지는 것은 별개의 기존 결함(이력
  // 20260914_0045 관찰 A, 보류)이라 이 spec 의 잔존 판정에서는 제외한다.
  const isEditorModeKey = (key: string) => /_mode$/.test(key);
  const spaKeys = result.spa.formKeys.filter((key) => !isEditorModeKey(key));
  const directKeys = result.direct.formKeys.filter((key) => !isEditorModeKey(key));
  const onlySpa = spaKeys.filter((key) => !directKeys.includes(key));
  const onlyDirect = directKeys.filter((key) => !spaKeys.includes(key));
  expect(onlySpa, `SPA 도착 등록 폼에만 있는 키(직전 레코드 잔존): ${onlySpa.join(', ')}`).toEqual([]);
  expect(onlyDirect, `직접 진입 등록 폼에만 있는 키(기본값 누락): ${onlyDirect.join(', ')}`).toEqual([]);
  expect(result.spa.filledInputs, '값이 들어 있는 입력칸 수가 직접 진입과 다르다').toBe(result.direct.filledInputs);
  expect(result.spa.hasChanges).not.toBe(true);
  expect(pageErrors).toEqual([]);
}

// 직렬 실행 — 워커 여러 개가 같은 개발 서버를 두드리면 수정 화면 데이터소스의 늦은 응답이 도착 화면의
// form 에 떨어지는 별개 경로(이전 화면 응답이 새 화면 상태에 닿음)가 섞여 판정이 흔들린다. 이 spec 의
// 판정 대상은 같은 레이아웃 재사용의 잔존이므로 부하 조건을 고정한다.
test.describe.configure({ mode: 'serial' });

test.describe('같은 레이아웃 수정 → 등록 SPA 전환 시 등록 폼 잔존 없음', () => {
  let token: string;
  let pageErrors: string[];

  test.beforeEach(async ({ page }) => {
    token = issueToken(...PERMISSIONS);
    await authenticatePage(page, token);
    pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));
  });

  /**
   * @scenario form=role
   * @effects spa_create_form_has_no_previous_record, spa_create_form_matches_direct_entry, no_page_errors
   */
  test('역할 수정 → 역할 등록: 직전 역할의 식별자·이름이 남지 않는다', async ({ page }) => {
    test.setTimeout(150_000);
    // 보호된 역할(코어·확장 소유)은 슈퍼 관리자가 아니면 수정 화면이 스스로 403 으로 접힌다 — 목록 첫 행이 확장 소유
    // 역할인 사이트에서 전제가 깨졌다(2026-10-04 실측). 서버가 행마다 싣는 수정 가능 판정으로 고른다.
    const roles = await listRecords(page, token, '/api/admin/roles?per_page=100');
    const role = roles.find((r) => r?.abilities?.can_update === true);
    expect(role, '수정 가능한 역할이 목록에 없다(전제 불성립)').toBeDefined();
    const result = await editThenCreate(page, `/admin/roles/${role.id}/edit`, '/admin/roles/create');
    expect(result.edit.id, '수정 화면이 역할을 불러오지 못했다(전제 불성립)').toBe(role.id);
    expectCleanCreateForm(result, '/admin/roles/create', pageErrors);
    expect(result.spa.name).toBeNull();
  });

  /**
   * @scenario form=user
   * @effects spa_create_form_has_no_previous_record, spa_create_form_matches_direct_entry, no_page_errors
   */
  test('회원 수정 → 회원 등록: 직전 회원의 이메일·이름이 남지 않는다', async ({ page }) => {
    test.setTimeout(150_000);
    const [user] = await listRecords(page, token, '/api/admin/users');
    const key = user.uuid ?? user.id;
    const result = await editThenCreate(page, `/admin/users/${key}/edit`, '/admin/users/create');
    expect(result.edit.formKeys.length, '수정 화면이 회원을 불러오지 못했다(전제 불성립)').toBeGreaterThan(0);
    expectCleanCreateForm(result, '/admin/users/create', pageErrors);
    expect(result.spa.name).toBeNull();
  });

  /**
   * @scenario form=product
   * @effects spa_create_form_has_no_previous_record, spa_create_form_matches_direct_entry, no_page_errors
   */
  test('상품 수정 → 상품 등록: 직전 상품의 코드·이름·가격이 남지 않는다', async ({ page }) => {
    test.setTimeout(150_000);
    const [product] = await listRecords(page, token, '/api/modules/sirsoft-ecommerce/admin/products');
    const result = await editThenCreate(
      page,
      `/admin/ecommerce/products/${product.product_code}/edit`,
      '/admin/ecommerce/products/create'
    );
    expect(result.edit.code, '수정 화면이 상품을 불러오지 못했다(전제 불성립)').toBe(product.product_code);
    expectCleanCreateForm(result, '/admin/ecommerce/products/create', pageErrors);
    expect(result.spa.code).toBeNull();
  });

  /**
   * @scenario form=product
   * @effects copy_payload_survives_clear, no_page_errors
   */
  test('상품 수정 → 상품 복사 등록(copy_id): 복사 페이로드는 살고 직전 상품의 id 는 남지 않는다', async ({ page }) => {
    test.setTimeout(150_000);
    const [product] = await listRecords(page, token, '/api/modules/sirsoft-ecommerce/admin/products');
    const copyPath = `/admin/ecommerce/products/create?copy_id=${product.id}`;
    const result = await editThenCreate(page, `/admin/ecommerce/products/${product.product_code}/edit`, copyPath);
    expect(result.edit.code).toBe(product.product_code);
    expect(result.spa.path).toBe(copyPath);
    expect(result.spa.id, '직전 상품 id 가 복사 폼에 남았다').toBeNull();
    expect(result.spa.code, '복사 폼의 상품코드가 원본 그대로다').not.toBe(product.product_code);
    expect(result.spa.name, '복사 페이로드(이름)가 사라졌다').toBe(result.direct.name);
    expect(result.spa.formKeys).toEqual(result.direct.formKeys);
    expect(pageErrors).toEqual([]);
  });

  /**
   * @scenario form=shipping_policy
   * @effects spa_create_form_has_no_previous_record, spa_create_form_matches_direct_entry, no_page_errors
   */
  test('배송정책 수정 → 배송정책 등록: 직전 정책의 이름·설정이 남지 않는다', async ({ page }) => {
    test.setTimeout(150_000);
    const [policy] = await listRecords(page, token, '/api/modules/sirsoft-ecommerce/admin/shipping-policies');
    const result = await editThenCreate(
      page,
      `/admin/ecommerce/shipping-policies/${policy.id}/edit`,
      '/admin/ecommerce/shipping-policies/create'
    );
    expect(result.edit.id, '수정 화면이 배송정책을 불러오지 못했다(전제 불성립)').toBe(policy.id);
    expectCleanCreateForm(result, '/admin/ecommerce/shipping-policies/create', pageErrors);
  });

  /**
   * @scenario form=board_post
   * @effects spa_create_form_has_no_previous_record, spa_create_form_matches_direct_entry, no_page_errors
   */
  test('게시글 수정 → 게시글 작성: 직전 글의 id 가 새 글 양식에 남지 않는다', async ({ page }) => {
    test.setTimeout(150_000);
    const boards = await listRecords(page, token, '/api/modules/sirsoft-board/admin/boards?per_page=20');
    let boardSlug: string | null = null;
    let postId: number | null = null;
    for (const board of boards) {
      const posts = await listRecords(page, token, `/api/modules/sirsoft-board/admin/board/${board.slug}/posts?per_page=1`);
      if (posts.length) {
        boardSlug = board.slug;
        postId = posts[0].id;
        break;
      }
    }
    if (!boardSlug || !postId) throw new Error('게시글이 있는 게시판이 없어 전제가 성립하지 않는다');
    const result = await editThenCreate(page, `/admin/board/${boardSlug}/${postId}/edit`, `/admin/board/${boardSlug}/create`);
    expect(result.edit.id, '수정 화면이 게시글을 불러오지 못했다(전제 불성립)').toBe(postId);
    expectCleanCreateForm(result, `/admin/board/${boardSlug}/create`, pageErrors);
  });

  /**
   * @scenario form=board
   * @effects spa_create_form_has_no_previous_record, spa_create_form_matches_direct_entry, no_page_errors
   */
  test('게시판 수정 → 게시판 등록: 직전 게시판의 다국어 이름이 남지 않는다', async ({ page }) => {
    test.setTimeout(150_000);
    const [board] = await listRecords(page, token, '/api/modules/sirsoft-board/admin/boards?per_page=1');
    const result = await editThenCreate(page, `/admin/boards/${board.slug}/edit`, '/admin/boards/create');
    expect(result.edit.slug, '수정 화면이 게시판을 불러오지 못했다(전제 불성립)').toBe(board.slug);
    expectCleanCreateForm(result, '/admin/boards/create', pageErrors);
    expect(result.spa.slug).toBeNull();
    expect(result.spa.name).toBe(result.direct.name);
  });
});

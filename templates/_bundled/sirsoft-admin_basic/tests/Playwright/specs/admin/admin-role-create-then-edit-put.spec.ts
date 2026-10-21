/**
 * 역할 등록 직후 넘어온 수정 화면의 「저장」은 PUT 이다 (7.1.1).
 * 템플릿 sirsoft-admin_basic (`/admin/roles/create` → `/admin/roles/{id}/edit`).
 *
 * 결함: 등록 성공 뒤 수정 화면으로 갈 때 navigate 에 `replace` 가 붙어 같은 화면의 쿼리만 바꾸는 이동이 됐고, `route.id` 가
 *   등록 화면 값(없음)에 머물러 수정 화면의 「저장」이 `POST /api/admin/roles` 로 나가 422 가 났다. `replace` 를 빼 라우트 전환을 탄다.
 *
 * 데이터: 서버에 역할을 만들지 않는다 — 등록(`POST /api/admin/roles`)·수정(`PUT`)을 가로채 가짜 200 으로 답하고, 새 역할 조회
 *   (`GET /api/admin/roles/{가짜 id}`)는 실제 역할 하나의 응답을 받아 id 만 바꿔 돌려준다. 계정은 실행 환경변수로만 받는다
 *   (관리자 토큰 `G7_E2E_TOKEN` 이 있으면 그것을 먼저 쓴다).
 *
 * @scenario-manifest tests/scenarios/e2e-admin-role-create-then-edit-put.yaml
 *
 * 실행:
 *   $env:PLAYWRIGHT_BASE_URL='https://example.com'; npm run test:e2e -- admin-role-create-then-edit-put --workers=1
 */
import { test, expect } from '@playwright/test';
import { adminToken } from '../../fixtures/admin-login';
import { authenticatePage } from '../../fixtures/admin-template-auth';

test.describe.configure({ timeout: 120_000 });

/** 계정 환경변수(관리자 토큰 G7_E2E_TOKEN, 또는 이메일+비밀번호 파일) — 없으면 발급 경로(issueToken)로 새 계정을 만들지 않도록 이 spec 은 건너뛴다 */
const HAS_ADMIN_ENV = !!(process.env.G7_E2E_TOKEN || (process.env.G7_E2E_ADMIN_EMAIL && process.env.G7_E2E_ADMIN_PASSWORD_FILE));

/** 가로챈 등록이 돌려줄 새 역할 id */
const FAKE_ROLE_ID = 990_404;

test.describe('역할 등록 → 수정 화면 저장 메서드', () => {
  test.skip(!HAS_ADMIN_ENV, '관리자 계정 환경변수 없음 — G7_E2E_TOKEN 또는 G7_E2E_ADMIN_EMAIL + G7_E2E_ADMIN_PASSWORD_FILE 을 주고 실행한다');

  /**
   * @scenario case=create_then_edit
   * @effects create_posts_once, edit_after_create_saves_with_put, no_role_written_to_server
   */
  test('등록 저장 → 수정 화면으로 넘어간 뒤 다시 저장하면 PUT /api/admin/roles/{id} 다', async ({ page, request }) => {
    const token = process.env.G7_E2E_TOKEN || (await adminToken(request));
    await authenticatePage(page, token);
    const auth = { Accept: 'application/json', Authorization: `Bearer ${token}` };

    // 실제 역할 하나 — 가짜 id 조회 응답의 모양으로 쓴다(관리자 역할이 아닌 첫 행)
    const list = await (await request.get('/api/admin/roles?per_page=20', { headers: auth })).json();
    const rows = (list?.data?.data ?? list?.data ?? []) as Array<{ id: number; identifier?: string }>;
    const source = rows.find((r) => r.identifier !== 'admin') ?? rows[0];
    expect(source, '모양을 빌릴 실제 역할').toBeTruthy();

    const writes: string[] = [];
    // 쓰기 요청 기본 차단 — 서버에 닿지 않는다
    await page.route(
      (url) => url.pathname.startsWith('/api/'),
      (route) => {
        const m = route.request().method();
        if (m === 'GET' || m === 'HEAD') return route.fallback();
        writes.push(`${m} ${new URL(route.request().url()).pathname}`);

        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { id: FAKE_ROLE_ID } }) });
      },
    );
    // 가짜 id 조회 — 실제 역할 응답을 받아 id 만 바꾼다
    await page.route(
      (url) => url.pathname === `/api/admin/roles/${FAKE_ROLE_ID}`,
      async (route) => {
        if (route.request().method() !== 'GET') return route.fallback();
        const res = await route.fetch({ url: route.request().url().replace(`/roles/${FAKE_ROLE_ID}`, `/roles/${source.id}`) });
        const body = await res.json();
        if (body?.data) body.data.id = FAKE_ROLE_ID;

        return route.fulfill({ response: res, json: body });
      },
    );

    await page.goto('/admin/roles/create');
    const identifier = page.locator('input[name="identifier"]');
    await expect(identifier).toBeVisible({ timeout: 30_000 });
    // 필수 칸 — 역할명(한국어)과 식별자. 비우면 브라우저 필수 검사가 제출을 막는다
    await page.getByPlaceholder(/콘텐츠 관리자 \(한국어\)/).first().fill('E2E F04 역할');
    await identifier.fill(`e2e_f04_${Date.now().toString(36)}`);
    const save = page.locator('button[type="submit"][form="role_form"]:visible').first();
    await save.click();

    await expect.poll(() => new URL(page.url()).pathname, { message: '등록 뒤 수정 화면으로 이동', timeout: 20_000 }).toBe(`/admin/roles/${FAKE_ROLE_ID}/edit`);
    await expect(identifier, '수정 화면 식별자 칸은 잠긴다(route.id 가 섰다)').toBeDisabled({ timeout: 20_000 });
    await page.waitForTimeout(1_000);
    await page.locator('button[type="submit"][form="role_form"]:visible').first().click();

    await expect.poll(() => writes.filter((w) => w.includes('/api/admin/roles')).length, { message: '두 번째 저장 요청', timeout: 15_000 }).toBeGreaterThanOrEqual(2);
    const roleWrites = writes.filter((w) => w.includes('/api/admin/roles'));
    expect(roleWrites[0], '첫 저장은 등록').toBe('POST /api/admin/roles');
    expect(roleWrites[1], '수정 화면 저장은 PUT').toBe(`PUT /api/admin/roles/${FAKE_ROLE_ID}`);
    expect(roleWrites.filter((w) => w === 'POST /api/admin/roles').length, '등록 POST 는 한 번뿐').toBe(1);

    // 저장 뒤 다시 읽는 가짜 id 조회가 시험 끝에 아직 날아가는 중일 수 있다 — 닫힌 페이지에서 route.fetch 가 실패로 남지 않게 정리한다
    await page.unrouteAll({ behavior: 'ignoreErrors' });
  });
});

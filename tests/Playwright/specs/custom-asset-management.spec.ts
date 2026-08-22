/**
 * E2E: 사용자 추가 에셋 화면 관리 + 탈출구 (공개 이슈 #123, D32~D34)
 *
 * 운영자가 자기 CSS·JS·폰트·이미지를 화면에서 넣고 고치는 경로를 실브라우저로 잰다.
 * 잠그는 것은 셋이다:
 *
 *  ① **권한 경계** — 레이아웃 편집 권한만으로는 관리 API 를 통과하지 못한다. 여기서 올린
 *     스크립트는 그 레이아웃 한 장이 아니라 사이트 전 화면에서 실행되므로, 레이아웃을
 *     고칠 수 있다는 것이 곧 그 권한이 될 수 없다. 이 결함은 오류를 남기지 않는다 —
 *     약한 경로가 정상 200 을 내보내는 것이 유일한 증상이다.
 *
 *  ② **왕복** — 저장한 파일이 목록·본문·삭제까지 그대로 왕복한다.
 *
 *  ③ **탈출구** — `?custom=off` 로 다시 열면 서버가 목록을 비운다. 운영자가 넣은 CSS 한 줄이
 *     화면을 조작 불능으로 만들면 그것을 고칠 편집기에도 같은 CSS 가 실려 스스로 갇히는데,
 *     서버가 목록을 비우면 자산이 페이지에 **도달하지 않으므로** 이미 깨진 화면에서
 *     자바스크립트가 돌기를 기대하지 않아도 된다.
 *
 * 시나리오 축·효과는 tests/scenarios/custom-asset-management.yaml 참조.
 */
import { test as base, expect } from '@playwright/test';

import { authenticatePage, issueScopedToken, issueToken } from '../fixtures/auth';
import { acquireCustomAssetLock, releaseCustomAssetLock } from '../fixtures/custom-asset-lock';

/** 관리 대상 템플릿 — 관리자 화면의 활성 템플릿. */
const TEMPLATE = 'sirsoft-admin_basic';

/** 테스트가 만드는 파일 (접두어로 운영자 파일과 구분해 정리한다). */
const FIXTURE_PATH = '__e2e123-manage.css';

/** 이 테스트만 쓰는 색 — 우연히 같아져 거짓 통과할 수 없다. */
const FIXTURE_COLOR = 'rgb(55, 55, 55)';

type Fixtures = {
  managerToken: string;
  layoutOnlyToken: string;
};

const test = base.extend<Fixtures>({
  managerToken: async ({}, use) => {
    await use(issueToken('core.extensions.custom_assets.manage', 'core.templates.layouts.edit'));
  },
  layoutOnlyToken: async ({}, use) => {
    // 대조군 — 편집 권한은 있으나 자산 관리 권한이 없는 계정.
    //
    // `issueScopedToken` 이어야 한다. `issueToken` 은 admin 역할을 함께 붙이는데,
    // 그 역할은 코어 동기화가 **모든 리프 권한**을 부여하므로(all_leaf) 대조군이
    // 대조군이 아니게 된다 — 그러면 게이트가 없어도 이 테스트가 통과한다.
    await use(issueScopedToken('core.templates.layouts.edit'));
  },
});

/**
 * 관리 API base URL — 세 확장 타입이 한 엔드포인트를 공유한다.
 *
 * @param identifier 확장 식별자
 * @param type 확장 타입
 */
const base_ = (identifier: string, type: 'template' | 'module' | 'plugin' = 'template'): string =>
  `/api/admin/extensions/${type}/${encodeURIComponent(identifier)}/custom-assets`;

test.describe('사용자 추가 에셋 화면 관리 @custom-assets', () => {
  // 케이스들이 실서버의 같은 `custom/` 디렉토리에 같은 파일명을 쓰고 `afterEach` 가 그것을
  // 지운다. 병렬로 돌리면 한 워커의 정리가 다른 워커가 방금 저장한 파일을 지워, 제품이
  // 정상인데도 목록 단언이 무작위로 깨진다. 형제 spec(`custom-assets.spec.ts`)이 같은
  // 이유로 이미 직렬이다.
  test.describe.configure({ mode: 'serial' });

  // 파일 안의 순서만으로는 부족하다 — `custom-assets.spec.ts` 가 같은 서버의 같은
  // `custom/` 디렉토리를 만지고, 여기서의 쓰기가 확장 캐시 버전을 올려 그쪽의 측정 창을
  // 흔든다. 파일끼리는 병렬이므로 둘을 서로 배제한다.
  test.beforeAll(async () => {
    // 훅 기본 상한(30초)은 상대 spec 이 잠금을 쥐고 있는 시간보다 짧다 — 올리지 않으면
    // 배제가 성립한 바로 그 순간에 훅이 시간 초과로 죽는다.
    test.setTimeout(5 * 60 * 1000);
    await acquireCustomAssetLock();
  });

  test.afterAll(() => {
    releaseCustomAssetLock();
  });

  test.afterEach(async ({ request, managerToken }) => {
    await request.delete(`${base_(TEMPLATE)}?path=${encodeURIComponent(FIXTURE_PATH)}`, {
      headers: { Authorization: `Bearer ${managerToken}`, Accept: 'application/json' },
    });
  });

  // @scenario manage_actor=layout_edit_only, manage_action=save
  // @effects custom_asset_manage_requires_dedicated_permission
  test('레이아웃 편집 권한만으로는 저장할 수 없다', async ({ request, layoutOnlyToken }) => {
    const response = await request.put(`${base_(TEMPLATE)}/content`, {
      headers: { Authorization: `Bearer ${layoutOnlyToken}`, Accept: 'application/json' },
      data: { path: FIXTURE_PATH, content: `body { color: ${FIXTURE_COLOR}; }` },
    });

    expect(response.status()).toBe(403);
  });

  // @scenario manage_actor=with_permission, manage_action=save
  // @effects custom_asset_editor_save_invalidates_published_copy
  // @scenario manage_actor=with_permission, manage_action=list
  // @effects custom_asset_manage_requires_dedicated_permission
  // @scenario manage_actor=with_permission, manage_action=read
  // @effects custom_asset_manage_requires_dedicated_permission
  test('저장한 파일이 목록·본문으로 왕복하고 화면에 적용된다', async ({
    page,
    request,
    managerToken,
  }) => {
    const headers = { Authorization: `Bearer ${managerToken}`, Accept: 'application/json' };

    const save = await request.put(`${base_(TEMPLATE)}/content`, {
      headers,
      data: { path: FIXTURE_PATH, content: `body { color: ${FIXTURE_COLOR} !important; }` },
    });
    expect(save.status()).toBe(200);

    const list = await request.get(base_(TEMPLATE), { headers });
    expect(list.status()).toBe(200);
    const files = (await list.json()).data.files as Array<{ path: string; loaded: boolean }>;
    const row = files.find((f) => f.path === FIXTURE_PATH);
    expect(row, '저장한 파일이 목록에 없습니다').toBeTruthy();
    expect(row?.loaded, '규약 스캔이 자동으로 싣지 않았습니다').toBe(true);

    const show = await request.get(
      `${base_(TEMPLATE)}/content?path=${encodeURIComponent(FIXTURE_PATH)}`,
      { headers },
    );
    expect(show.status()).toBe(200);
    expect((await show.json()).data.content).toContain(FIXTURE_COLOR);

    // 저장만으로는 증명이 아니다 — 실제 화면에 적용되는지를 최종 계산값으로 잰다.
    await authenticatePage(page, managerToken);
    await page.goto('/admin');

    // `networkidle` 은 쓰지 않는다 — 관리자 SPA 는 유지 연결이 있어 idle 이 오지 않는다.
    // custom CSS 는 JS 부팅 이후에 붙으므로 최종 적용값 자체를 조건으로 기다린다.
    await expect
      .poll(async () => page.evaluate(() => getComputedStyle(document.body).color), {
        timeout: 20000,
      })
      .toBe(FIXTURE_COLOR);
  });

  // @scenario manage_actor=with_permission, manage_action=delete
  // @effects custom_assets_disabled_by_request_parameter
  test('?custom=off 로 열면 그 자산이 페이지에 도달하지 않는다', async ({
    page,
    request,
    managerToken,
  }) => {
    const headers = { Authorization: `Bearer ${managerToken}`, Accept: 'application/json' };

    await request.put(`${base_(TEMPLATE)}/content`, {
      headers,
      data: { path: FIXTURE_PATH, content: `body { color: ${FIXTURE_COLOR} !important; }` },
    });

    await authenticatePage(page, managerToken);

    await page.goto('/admin?custom=off');

    // 목록이 비었는지를 서버가 심은 설정에서 직접 확인한다 — DOM 부재만 보면
    // "로더가 아직 안 돌았다" 와 구분되지 않는다.
    await page.waitForFunction(() => !!(window as any).G7Config, undefined, { timeout: 20000 });

    const declared = await page.evaluate(
      () => ((window as any).G7Config?.customAssets ?? []) as unknown[],
    );
    expect(declared).toHaveLength(0);

    const applied = await page.evaluate(() => getComputedStyle(document.body).color);
    expect(applied).not.toBe(FIXTURE_COLOR);

    // 대조군 — 파라미터 없이 열면 다시 적용된다 (위 단언이 우연이 아님을 잠근다)
    await page.goto('/admin');
    await expect
      .poll(async () => page.evaluate(() => getComputedStyle(document.body).color), {
        timeout: 20000,
      })
      .toBe(FIXTURE_COLOR);
  });

  // @scenario manage_actor=with_permission, manage_action=upload
  // @effects custom_asset_manage_upload_extension_whitelist
  test('허용되지 않는 확장자 업로드는 422 로 막힌다', async ({ request, managerToken }) => {
    const response = await request.post(`${base_(TEMPLATE)}/upload`, {
      headers: { Authorization: `Bearer ${managerToken}`, Accept: 'application/json' },
      multipart: {
        file: {
          name: '__e2e123-shell.php',
          mimeType: 'application/x-php',
          buffer: Buffer.from('<?php echo 1;'),
        },
      },
    });

    expect(response.status()).toBe(422);
  });

  // @scenario manage_actor=unauthenticated, manage_action=list
  // @effects custom_asset_manage_requires_dedicated_permission
  test('비인증 요청은 목록을 받을 수 없다', async ({ request }) => {
    const response = await request.get(base_(TEMPLATE), { headers: { Accept: 'application/json' } });

    expect(response.status()).toBe(401);
  });

  // @scenario manage_actor=with_permission, manage_action=list
  // @effects custom_asset_manage_all_extension_types_parity
  test('모듈·플러그인도 같은 엔드포인트로 관리된다', async ({ request, managerToken }) => {
    const headers = { Authorization: `Bearer ${managerToken}`, Accept: 'application/json' };

    for (const [type, identifier] of [
      ['module', 'sirsoft-page'],
      ['plugin', 'sirsoft-gdpr'],
    ] as const) {
      const path = `__e2e123-${type}.css`;

      const save = await request.put(`${base_(identifier, type)}/content`, {
        headers,
        data: { path, content: 'body { line-height: 1.7; }' },
      });
      expect(save.status(), `${type} 저장이 실패했습니다`).toBe(200);

      const list = await request.get(base_(identifier, type), { headers });
      expect(list.status()).toBe(200);
      const paths = ((await list.json()).data.files as Array<{ path: string }>).map((f) => f.path);
      expect(paths).toContain(path);

      const removed = await request.delete(
        `${base_(identifier, type)}?path=${encodeURIComponent(path)}`,
        { headers },
      );
      expect(removed.status()).toBe(200);
    }
  });

  // @scenario manage_actor=with_permission, manage_action=read
  // @effects custom_asset_manage_all_extension_types_parity
  test('알 수 없는 확장 타입은 라우트가 받지 않는다', async ({ request, managerToken }) => {
    // 통과시켜 빈 목록으로 응답하면 오타가 "그 확장에는 파일이 없다" 로 보인다.
    const response = await request.get(`/api/admin/extensions/theme/${TEMPLATE}/custom-assets`, {
      headers: { Authorization: `Bearer ${managerToken}`, Accept: 'application/json' },
    });

    expect(response.status()).toBe(404);
  });
});

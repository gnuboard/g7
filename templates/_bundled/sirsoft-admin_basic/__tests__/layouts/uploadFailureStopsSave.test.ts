/**
 * 업로드가 실패하면 저장하지 않는다 — 저장 흐름 6곳 (#869 브라우저 실측)
 *
 * 저장 단추는 `emit-event upload:*` 로 업로더를 돌린 뒤 저장 요청을 보낸다. 업로드가 모두 실패해도 저장이 이어져
 * (관리자 환경설정 `POST /api/admin/settings` 의 `site_logo: []`) 올리려던 파일 없이 저장됐다. 업로더가 신호 결과에
 * `failedCount` 를 싣고, 저장 흐름이 그 수를 `_local.uploadFailedCount` 에 모아 하나라도 실패면 저장 표시를 풀고 알린 뒤
 * 저장 요청을 건너뛴다. 각 레이아웃의 실제 저장 시퀀스를 꺼내 진짜 엔진으로 실행한다.
 *
 * 조합과 효과 표식은 그것을 재는 시험마다 단다.
 */
import { describe, it, expect, afterEach } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';

type Json = Record<string, any>;
const REPO = path.resolve(__dirname, '../../../../..');
const read = (rel: string): Json => JSON.parse(fs.readFileSync(path.join(REPO, rel), 'utf8'));

/** 업로드 신호를 품은 액션 배열(저장 시퀀스 몸통)을 모두 찾는다 */
function saveSequences(node: unknown, out: Json[][] = []): Json[][] {
  if (Array.isArray(node)) {
    if (node.some((a) => a && typeof a === 'object' && a.handler === 'emitEvent' && String(a.params?.event ?? '').startsWith('upload:'))) {
      out.push(node as Json[]);
    }
    node.forEach((n) => saveSequences(n, out));
  } else if (node && typeof node === 'object') {
    Object.values(node).forEach((v) => saveSequences(v, out));
  }
  return out;
}

const FLOWS: Array<{ name: string; file: string; urlPart: string; count: number }> = [
  { name: 'admin_settings', file: 'templates/_bundled/sirsoft-admin_basic/layouts/admin_settings.json', urlPart: '/api/admin/settings', count: 1 },
  { name: 'user_post_form', file: 'templates/_bundled/sirsoft-basic/layouts/partials/board/form/_post_form.json', urlPart: '/posts', count: 1 },
  { name: 'board_admin_post_form', file: 'modules/_bundled/sirsoft-board/resources/layouts/admin/admin_board_post_form.json', urlPart: '/posts', count: 2 },
  { name: 'ecommerce_product_form', file: 'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_product_form.json', urlPart: '/admin/products', count: 1 },
  { name: 'page_form', file: 'modules/_bundled/sirsoft-page/resources/layouts/admin/admin_page_form.json', urlPart: '/admin/pages', count: 1 },
];

/** 하네스 fetch spy 에 기록된 저장 요청(GET 이 아닌 것) 수 */
function writeRequests(urlPart: string): number {
  const spy = globalThis.fetch as unknown as { mock?: { calls: any[][] } };
  return (spy.mock?.calls ?? []).filter(
    ([url, init]) => String(url).includes(urlPart) && String(init?.method ?? 'GET').toUpperCase() !== 'GET'
  ).length;
}

describe('업로드가 실패하면 저장하지 않는다 (#869 브라우저 실측)', () => {
  let testUtils: ReturnType<typeof createLayoutTest> | null = null;

  afterEach(() => {
    testUtils?.cleanup();
    testUtils = null;
  });

  async function runSave(sequence: Json[], failedCount: number) {
    testUtils = createLayoutTest(
      {
        version: '1.0.0',
        layout_name: 'upload_failure_stops_save_test',
        data_sources: [],
        initLocal: { form: { general: { site_logo: [] } }, isSaving: false, saving: false },
        components: [{ id: 'main', type: 'basic', name: 'Div', children: [] }],
      } as any,
      { translations: {}, locale: 'ko' }
    );
    await testUtils.render();
    const g7 = (window as any).G7Core;
    const events = new Set(sequence.filter((a) => a.handler === 'emitEvent').map((a) => String(a.params.event)));
    const offs = [...events].map((event) =>
      g7.componentEvent.on(event, async () => ({ uploadedAttachments: [], existingFiles: [], allFiles: [], failedCount }))
    );
    await testUtils.triggerAction({ type: 'click', handler: 'sequence', actions: sequence } as any);
    offs.forEach((off: any) => typeof off === 'function' && off());
  }

  /** 흐름 하나의 저장 시퀀스(들)를 실패 또는 성공 결과로 돌려 본다 */
  async function check(name: string, failed: boolean) {
    const flow = FLOWS.find((f) => f.name === name)!;
    for (const sequence of saveSequences(read(flow.file))) {
      await runSave(sequence, failed ? 1 : 0);
      const toasts = testUtils!.getToasts().map((t) => t.message).join('|');
      if (failed) {
        expect(writeRequests(flow.urlPart), `${name}: 업로드가 실패했는데 저장 요청이 나갔습니다`).toBe(0);
        expect(toasts).toContain('attachment.upload_failed_save_stopped');
        const local = testUtils!.getState()._local;
        expect(local.isSaving === true || local.saving === true, `${name}: 저장 중 표시가 남았습니다`).toBe(false);
      } else {
        expect(writeRequests(flow.urlPart), name).toBe(1);
        expect(toasts).not.toContain('upload_failed_save_stopped');
      }
      testUtils!.cleanup();
      testUtils = null;
    }
  }

  // @scenario flow=admin_settings, upload_result=ok
  // @effects save_proceeds_when_upload_ok
  it('모집단 — 업로드 신호를 품은 저장 시퀀스를 흐름마다 찾는다', () => {
    for (const flow of FLOWS) {
      expect(saveSequences(read(flow.file)).length, flow.name).toBe(flow.count);
    }
  });

  // @scenario flow=admin_settings, upload_result=failed
  // @effects save_stops_when_upload_failed
  it('관리자 환경설정: 업로드가 실패하면 저장 요청이 0건이고 오류 알림과 저장 표시 해제가 남는다', async () => {
    await check('admin_settings', true);
  });

  // @scenario flow=admin_settings, upload_result=ok
  // @effects save_proceeds_when_upload_ok
  it('관리자 환경설정: 업로드가 성공하면 저장 요청이 그대로 나간다', async () => {
    await check('admin_settings', false);
  });

  // @scenario flow=user_post_form, upload_result=failed
  // @effects save_stops_when_upload_failed
  it('사용자 글쓰기: 업로드가 실패하면 저장 요청이 0건이고 오류 알림과 저장 표시 해제가 남는다', async () => {
    await check('user_post_form', true);
  });

  // @scenario flow=user_post_form, upload_result=ok
  // @effects save_proceeds_when_upload_ok
  it('사용자 글쓰기: 업로드가 성공하면 저장 요청이 그대로 나간다', async () => {
    await check('user_post_form', false);
  });

  // @scenario flow=board_admin_post_form, upload_result=failed
  // @effects save_stops_when_upload_failed
  it('게시판 관리자 글쓰기(위아래 저장 단추): 업로드가 실패하면 저장 요청이 0건이고 오류 알림과 저장 표시 해제가 남는다', async () => {
    await check('board_admin_post_form', true);
  });

  // @scenario flow=board_admin_post_form, upload_result=ok
  // @effects save_proceeds_when_upload_ok
  it('게시판 관리자 글쓰기(위아래 저장 단추): 업로드가 성공하면 저장 요청이 그대로 나간다', async () => {
    await check('board_admin_post_form', false);
  });

  // @scenario flow=ecommerce_product_form, upload_result=failed
  // @effects save_stops_when_upload_failed
  it('이커머스 상품 폼: 업로드가 실패하면 저장 요청이 0건이고 오류 알림과 저장 표시 해제가 남는다', async () => {
    await check('ecommerce_product_form', true);
  });

  // @scenario flow=ecommerce_product_form, upload_result=ok
  // @effects save_proceeds_when_upload_ok
  it('이커머스 상품 폼: 업로드가 성공하면 저장 요청이 그대로 나간다', async () => {
    await check('ecommerce_product_form', false);
  });

  // @scenario flow=page_form, upload_result=failed
  // @effects save_stops_when_upload_failed
  it('페이지 폼: 업로드가 실패하면 저장 요청이 0건이고 오류 알림과 저장 표시 해제가 남는다', async () => {
    await check('page_form', true);
  });

  // @scenario flow=page_form, upload_result=ok
  // @effects save_proceeds_when_upload_ok
  it('페이지 폼: 업로드가 성공하면 저장 요청이 그대로 나간다', async () => {
    await check('page_form', false);
  });
});

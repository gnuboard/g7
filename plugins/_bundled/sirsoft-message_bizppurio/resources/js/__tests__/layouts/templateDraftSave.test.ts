/**
 * @file templateDraftSave.test.ts
 * @description 알림톡 템플릿 작성 창 — 「저장」은 draft 표시를 싣고, 「저장 후 검수 신청」은 싣지 않는다
 *
 * 서버는 draft 표시가 있을 때만 필수 조건을 걷어 낸다. 「저장 후 검수 신청」까지 draft 를 실으면
 * 미완성 내용이 저장된 뒤 검수 신청 단계에서야 막히므로, 두 버튼의 본문이 갈려야 한다.
 */

import { describe, expect, it } from 'vitest';
import layout from '../../../layouts/admin/plugin_settings.json';
import { callbacksOf, findActions } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

/** 템플릿 저장 호출(생성 POST · 수정 PUT) 전량 */
const saveCalls = findActions(layout, (action) =>
  action.handler === 'apiCall'
  && /\/admin\/templates(\/\{\{_global\.bz_tpl_modal\?\.id\}\})?$/.test(String(action.target ?? ''))
  && ['POST', 'PUT'].includes(String(action.params?.method ?? '')),
);

describe('템플릿 작성 창 저장 본문', () => {
  /**
   * @scenario content_variant=ba_none, transition=draft_to_requested
   * @effects draft_save_accepts_partial_content_and_request_validates_fully
   */
  it('「저장」 두 곳(신규·기존)만 draft 를 싣고 「저장 후 검수 신청」 두 곳은 싣지 않는다', () => {
    expect(saveCalls).toHaveLength(4);
    const withDraft = saveCalls.filter((call) => call.params?.body?.draft === true);
    const chained = saveCalls.filter((call) => JSON.stringify(callbacksOf(call, 'onSuccess')).includes('/request'));

    expect(withDraft).toHaveLength(2);
    expect(chained).toHaveLength(2);
    // 검수 신청으로 이어지는 저장에는 draft 가 없다
    expect(chained.every((call) => call.params?.body?.draft === undefined)).toBe(true);
  });
});

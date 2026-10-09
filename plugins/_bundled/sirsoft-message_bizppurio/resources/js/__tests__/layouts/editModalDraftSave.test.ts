/**
 * @file editModalDraftSave.test.ts
 * @description 알림 템플릿 [편집] 창 하단 「저장」 — 알림톡 섹션을 초안으로 남기고, 「저장 후 검수 신청」은 전체 검증
 *
 * 비즈뿌리오 설정의 「알림 템플릿 관리」 탭 작성 창은 「저장」에 draft 표시를 실어 쓰다 만 내용도 초안으로 남긴다.
 * 그 탭 안내는 「작성·검수 신청은 알림 설정 화면과 동일하게 동작합니다」인데, 알림 설정 [편집] 창의 하단 「저장」은
 * draft 표시 없이 보내 본문만 쓰고 카테고리를 비우면 422 로 막혔다(2026-10-07 실측 O-M2-3). 두 화면이 같게
 * 동작하려면 이 창의 「저장」(생성·갱신 두 갈래)도 draft 를 싣고, 「저장 후 검수 신청」 두 갈래는 싣지 않아야 한다.
 */

import { describe, expect, it } from 'vitest';
import footer from '../../../extensions/notification_template_form_footer_actions.json';
import { callbacksOf, findActions } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

/** 알림톡 템플릿 저장 호출(생성 POST · 수정 PUT) 전량 */
const saveCalls = findActions(footer, (action) =>
  action.handler === 'apiCall'
  && /\/admin\/templates(\/\{\{_global\.bz_tpl_modal\?\.id\}\})?$/.test(String(action.target ?? ''))
  && ['POST', 'PUT'].includes(String(action.params?.method ?? '')),
);

/** 저장 성공 뒤 검수 신청으로 이어지는 호출 */
const chained = saveCalls.filter((call) => JSON.stringify(callbacksOf(call, 'onSuccess')).includes('/request'));

/** 검수 신청 없이 끝나는 호출 (하단 「저장」) */
const plain = saveCalls.filter((call) => !chained.includes(call));

describe('알림 템플릿 [편집] 창 하단 저장 본문', () => {
  /**
   * @scenario surface=core_edit_modal, transition=draft_save
   * @effects edit_modal_save_keeps_alimtalk_as_draft
   */
  it('「저장」 두 갈래(생성·갱신)는 draft 를 싣는다', () => {
    expect(saveCalls).toHaveLength(4);
    expect(plain).toHaveLength(2);
    expect(plain.map((call) => call.params?.body?.draft)).toEqual([true, true]);
  });

  /**
   * @scenario surface=core_edit_modal, transition=draft_to_requested
   * @effects edit_modal_request_validates_fully
   */
  it('「저장 후 검수 신청」 두 갈래는 draft 를 싣지 않는다(빠진 필수값은 이때 항목별로 돌아온다)', () => {
    expect(chained).toHaveLength(2);
    expect(chained.every((call) => call.params?.body?.draft === undefined)).toBe(true);
  });

  /**
   * @scenario surface=core_edit_modal, transition=draft_save
   * @effects edit_modal_save_keeps_alimtalk_as_draft
   */
  it('발송 설정만 저장하는 갈래(본문 없음·내용 잠김)는 템플릿 내용을 싣지 않아 draft 와 무관하다', () => {
    const delivery = findActions(footer, (action) =>
      action.handler === 'apiCall' && String(action.target ?? '').includes('/admin/templates/delivery/'),
    );
    expect(delivery).toHaveLength(1);
    expect(delivery[0].params?.body?.content).toBeUndefined();
  });
});

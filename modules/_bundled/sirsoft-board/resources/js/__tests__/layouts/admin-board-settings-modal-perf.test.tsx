/**
 * @file admin-board-settings-modal-perf.test.tsx
 * @description 게시판 환경설정 모달 회귀 (옵션 K — _global 단일 진실)
 *
 * namespace 키 분리 — 게시판: board_notification_template_form_modal
 * 정책 모달은 동일 키 (identity_policy_form_modal) 사용 — 코어와 ecommerce/board 가
 * 동시 마운트되지 않으므로 키 충돌 없음 (각 모듈 환경설정 페이지에서만 노출)
 */

import { describe, it, expect } from 'vitest';

const policyTab = require('../../../layouts/admin/partials/admin_board_settings/_tab_identity_policies.json');
const policyModal = require('../../../layouts/admin/partials/admin_board_settings/_modal_identity_policy_form.json');
const notifTab = require('../../../layouts/admin/partials/admin_board_settings/_tab_notification_definitions.json');
const notifModal = require('../../../layouts/admin/partials/admin_board_settings/_modal_notification_template_edit.json');
const notifPreview = require('../../../layouts/admin/partials/admin_board_settings/_modal_notification_template_preview.json');

interface AnyJson { [k: string]: any }

const collectChangeTargets = (node: AnyJson, acc: string[] = []): string[] => {
  if (!node || typeof node !== 'object') return acc;
  if (Array.isArray(node)) { node.forEach(item => collectChangeTargets(item, acc)); return acc; }
  if (Array.isArray(node.actions)) {
    for (const a of node.actions) {
      const isChange = a?.type === 'change' || a?.event === 'onChange' || a?.event === 'onSearch';
      if (isChange && a?.handler === 'setState' && a?.params?.target) {
        acc.push(a.params.target);
      }
    }
  }
  for (const k of Object.keys(node)) collectChangeTargets(node[k], acc);
  return acc;
};

describe('게시판 본인인증 정책 모달 회귀 (옵션 K)', () => {
  /**
   * @effects identity_policy_modal_has_no_onmount_lifecycle
   */
  it('lifecycle.onMount 미사용', () => {
    expect(policyModal.lifecycle).toBeUndefined();
  });

  /**
   * @effects identity_policy_modal_actions_target_global
   */
  it('change 액션은 모두 target:"global"', () => {
    const targets = collectChangeTargets(policyModal);
    expect(targets.length).toBeGreaterThan(0);
    expect(targets.every(t => t === 'global')).toBe(true);
  });

  /**
   * @effects identity_policy_list_sets_the_whole_namespace
   */
  it('list 측 setState 가 _global.identity_policy_form_modal 통째 set', () => {
    const tabStr = JSON.stringify(policyTab);
    expect(tabStr).toContain('"target":"global"');
    expect(tabStr).toContain('"identity_policy_form_modal":{');
  });

  /**
   * @effects identity_policy_modal_reads_and_writes_one_namespace
   */
  it('표시/입력 모두 _global.identity_policy_form_modal 경로', () => {
    const modalStr = JSON.stringify(policyModal);
    expect(modalStr).toContain('_global.identity_policy_form_modal?.form?.key');
    expect(modalStr).toContain('"identity_policy_form_modal.form.key":');
  });

  /**
   * @effects identity_policy_save_carries_source_identifier_and_datasource
   */
  it('apiCall body source_identifier=sirsoft-board, dataSourceId=boardIdentityPolicies', () => {
    const modalStr = JSON.stringify(policyModal);
    expect(modalStr).toContain("'sirsoft-board'");
    expect(modalStr).toContain('"dataSourceId":"boardIdentityPolicies"');
  });

  /**
   * @effects identity_policy_save_clears_the_namespace
   */
  it('저장 onSuccess 에 namespace null 정리', () => {
    const modalStr = JSON.stringify(policyModal);
    expect(modalStr).toContain('"identity_policy_form_modal":null');
  });
});

describe('게시판 알림 템플릿 모달 회귀 (옵션 K)', () => {
  /**
   * @effects notification_template_modal_has_no_onmount_lifecycle
   */
  it('lifecycle.onMount 미사용', () => {
    expect(notifModal.lifecycle).toBeUndefined();
  });

  /**
   * @effects notification_template_modal_actions_target_global
   */
  it('change/onChange/onSearch 액션은 모두 target:"global"', () => {
    const targets = collectChangeTargets(notifModal);
    expect(targets.length).toBeGreaterThan(0);
    expect(targets.every(t => t === 'global')).toBe(true);
  });

  /**
   * @effects notification_template_list_sets_the_whole_namespace
   */
  it('list 측 setState 가 _global.board_notification_template_form_modal 통째 set', () => {
    const tabStr = JSON.stringify(notifTab);
    expect(tabStr).toContain('"board_notification_template_form_modal":{');
  });

  /**
   * @effects notification_template_modal_reads_one_namespace
   */
  it('표시 표현식이 board_notification_template_form_modal 경로', () => {
    const modalStr = JSON.stringify(notifModal);
    expect(modalStr).toContain('_global.board_notification_template_form_modal?.template?.subject');
    expect(modalStr).toContain('_global.board_notification_template_form_modal?.recipients');
  });

  /**
   * @effects notification_template_subject_and_body_set_whole_objects
   */
  it('subject/body — Object.assign + dot path 통째 set', () => {
    const modalStr = JSON.stringify(notifModal);
    expect(modalStr).toContain('"board_notification_template_form_modal.template.subject":');
    expect(modalStr).toContain('Object.assign({}, _global.board_notification_template_form_modal?.template?.subject');
  });

  /**
   * @effects notification_template_recipients_replace_the_whole_array
   */
  it('recipients — .map/.filter 통째 교체', () => {
    const modalStr = JSON.stringify(notifModal);
    expect(modalStr).toContain('"board_notification_template_form_modal.recipients":');
  });

  /**
   * @effects notification_template_preview_reads_the_namespace
   */
  it('preview 모달이 _global.board_notification_template_form_modal?.preview 참조', () => {
    const previewStr = JSON.stringify(notifPreview);
    expect(previewStr).toContain('_global.board_notification_template_form_modal?.preview?.subject');
    expect(previewStr).toContain('_global.board_notification_template_form_modal?.preview?.body');
  });

  /**
   * 미리보기가 채널의 편집기 종류를 따른다.
   *
   * 사이트내 알림은 알림센터가 본문을 **평문으로** 그린다. 미리보기만 HTML 로 해석하면
   * 운영자는 서식이 적용된 화면을 보고 사용자는 태그가 글자로 보이는 화면을 받는다.
   *
   * @effects notification_template_preview_follows_the_channel_editor
   */
  it('미리보기 본문이 isHtml 을 채널 편집기 종류로 판정한다', () => {
    const previewStr = JSON.stringify(notifPreview);

    expect(previewStr).not.toContain('"isHtml":true');
    expect(previewStr).toContain('template_editor');
    expect(previewStr).toContain("=== 'rich'");
  });

  /**
   * @effects notification_template_save_refetches_and_clears_the_namespace
   */
  it('저장 onSuccess 에 dataSourceId=boardNotificationDefinitions + namespace null 정리', () => {
    const modalStr = JSON.stringify(notifModal);
    expect(modalStr).toContain('"dataSourceId":"boardNotificationDefinitions"');
    expect(modalStr).toContain('"board_notification_template_form_modal":null');
  });

  // 회귀: 역할/사용자 드롭다운이 저장된 선택값을 표시하지 못하던 버그.
  // 검색 전(roleSearchResults/userSearchResults 빈 배열) 에도 현재 선택값을 options 에
  // 시드해야 SearchableDropdown 이 라벨(rcpt.display_name / display_names) 을 표시한다.
  /**
   * @effects notification_template_role_dropdown_seeds_selected_values
   */
  it('role 드롭다운 options 가 현재 선택값을 시드', () => {
    const modalStr = JSON.stringify(notifModal);
    expect(modalStr).not.toContain('"options":"{{_global.board_notification_template_form_modal?.roleSearchResults ?? []}}"');
    // 역할은 여러 개를 고를 수 있다 (E3) — 사용자 칸과 같은 모양이다.
    expect(modalStr).toContain('(rcpt.display_names ?? [])[i] ?? rid');
    // 스칼라 전제가 남아 있으면 배열이 들어온 순간 값이 조용히 덮인다.
    expect(modalStr).not.toContain('rcpt.display_name ?? rcpt.value');
    expect(modalStr).not.toContain('String(o.value) !== String(rcpt.value)');
  });

  /**
   * @effects notification_template_users_dropdown_seeds_selected_values
   */
  it('specific_users 드롭다운 options 가 현재 선택값을 시드', () => {
    const modalStr = JSON.stringify(notifModal);
    expect(modalStr).not.toContain('"options":"{{_global.board_notification_template_form_modal?.userSearchResults ?? []}}"');
    expect(modalStr).toContain('(rcpt.display_names ?? [])[i] ?? uid');
  });
});

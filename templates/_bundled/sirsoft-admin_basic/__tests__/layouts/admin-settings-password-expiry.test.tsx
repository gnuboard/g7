/**
 * @file admin-settings-password-expiry.test.tsx
 * @description 환경설정 > 보안 「비밀번호 정책」·「비밀번호 만료」 카드와 직권 실행 창 구조 회귀 테스트 (#736)
 *
 * - 조건부 칸은 토글이 켜졌을 때만 보이고, 저장값이 boolean 이 아닐 수 있어 느슨하게 참 판정한다.
 * - 대상 행은 만료가 꺼져 있어도 보이고, 「대상 보기」는 명단 권한(`can_view_targets`)만 보고 그린다(0명이어도).
 * - 「지금 실행」은 저장 안 한 변경·처리 중·**저장된 만료 꺼짐**이면 잠긴다(2026-10-03 결정 — 서버도 409).
 * - 확인 창은 전체 대상 수와 이번 실행 수(상한)를 함께 보이고, 만료 처리 실행 버튼은 빨갛다.
 *
 * @since engine-v1.77.0
 */

import { describe, it, expect } from 'vitest';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import policy from '../../layouts/partials/admin_settings/_tab_security_password_policy.json';
import runModal from '../../layouts/partials/admin_settings/_modal_password_expiry_run.json';
import settings from '../../layouts/admin_settings.json';

type Node = { id?: string; name?: string; if?: string; props?: Record<string, any>; children?: Node[] | string; actions?: any; iteration?: any };

function flatten(node: any): Node[] {
  if (!node || typeof node !== 'object') return [];
  if (Array.isArray(node)) return node.flatMap(flatten);
  const children = Array.isArray(node.children) ? node.children.flatMap(flatten) : [];
  return [node, ...children];
}

function flattenActions(actions: any): any[] {
  if (!actions) return [];
  return asArray<any>(actions).flatMap((a) => [a, ...flattenActions(a.actions), ...flattenActions(a.onSuccess), ...flattenActions(a.onError)]);
}

const policyNodes = flatten((policy as any).components ?? policy);
const modalNodes = flatten((runModal as any).components ?? runModal);
const find = (nodes: Node[], id: string) => nodes.find((n) => n.id === id);

const EXPIRY_ON = "!!_local.form?.security?.password_expiry_enabled && _local.form?.security?.password_expiry_enabled !== '0'";

describe('보안 탭 — 비밀번호 정책 카드', () => {
  /**
   * @scenario surface=security_tab, expiry=off
   * @effects policy_checkboxes_are_explicitly_bound
   */
  it.each([
    'require_password_special_char',
    'password_require_char_types',
    'password_block_identifier',
    'password_block_repeated_chars',
    'password_block_sequential_chars',
  ])('%s 체크박스는 자동 바인딩을 끄고 change 에서 직접 담는다', (key) => {
    const checkbox = policyNodes.find((n) => n.name === 'Checkbox' && n.props?.name === `security.${key}`);
    expect(checkbox?.props?.autoBinding).toBe(false);
    expect(String(checkbox?.props?.checked)).toContain(`!!_local.form?.security?.${key}`);
    const set = flattenActions(checkbox?.actions).find((a) => a.handler === 'setState');
    expect(set?.params?.[`form.security.${key}`]).toBe('{{$event.target.checked}}');
  });

  /**
   * @scenario surface=security_tab, expiry=off
   * @effects conditional_fields_follow_their_toggle
   */
  it.each([
    ['field_password_repeated_chars_length', 'password_block_repeated_chars'],
    ['field_password_sequential_chars_length', 'password_block_sequential_chars'],
    ['field_password_reuse_count', 'password_reuse_prevention_enabled'],
  ])('%s 는 %s 가 켜졌을 때만 보인다', (id, toggle) => {
    expect(find(policyNodes, id)?.if).toBe(`{{!!_local.form?.security?.${toggle} && _local.form?.security?.${toggle} !== '0'}}`);
  });
});

describe('보안 탭 — 비밀번호 만료 카드', () => {
  /**
   * @scenario surface=security_tab, expiry=on
   * @effects conditional_fields_follow_their_toggle
   */
  it.each([
    'password_expiry_started_at',
    'field_password_expiry_days',
    'field_password_expiry_notice_days',
    'field_password_expiry_scope',
    'field_password_expiry_action',
    'field_password_expiry_batch_size',
    'password_expiry_schedule_notice',
  ])('%s 는 만료가 켜졌을 때만 보인다', (id) => {
    expect(find(policyNodes, id)?.if).toBe(`{{${EXPIRY_ON}}}`);
  });

  /**
   * @scenario surface=security_tab, expiry=on, action=remind
   * @effects conditional_fields_follow_their_toggle
   */
  it('연기 일수는 만료가 켜지고 동작이 「다음에 변경 허용」일 때만 보인다', () => {
    expect(find(policyNodes, 'field_password_expiry_remind_days')?.if).toBe(
      `{{${EXPIRY_ON} && _local.form?.security?.password_expiry_action === 'remind'}}`,
    );
  });

  /**
   * @scenario surface=security_tab, expiry=off
   * @effects target_rows_visible_when_off_with_permission_gated_view
   */
  it('대상 목록은 만료가 꺼져 있어도 보이고 「대상 보기」는 명단 권한만 본다', () => {
    const list = find(policyNodes, 'password_expiry_target_list');
    expect(list).toBeDefined();
    expect(list?.if).toBeUndefined();

    const row = policyNodes.find((n) => String(n.id).startsWith('password_expiry_target_row_'));
    const view = flatten(row).find((n) => String(n.id).startsWith('view_password_expiry_'));
    expect(view?.if).toBe('{{passwordExpiryPreview?.data?.can_view_targets}}');

    const openList = flattenActions(view?.actions);
    expect(openList.some((a) => a.handler === 'openModal' && a.target === 'privacy_targets_modal')).toBe(true);
    expect(String(openList.find((a) => a.handler === 'setState')?.params?.privacyTargets)).toContain("'password-expiry-targets/' + stage");
  });

  /**
   * @scenario surface=security_tab, expiry=off
   * @effects run_locked_when_unsaved_running_or_saved_off
   */
  it('「지금 실행」은 저장 안 한 변경·저장된 만료 꺼짐·처리 중이면 잠긴다', () => {
    const row = policyNodes.find((n) => String(n.id).startsWith('password_expiry_target_row_'));
    const run = flatten(row).find((n) => String(n.id).startsWith('run_password_expiry_'));
    const disabled = String(run?.props?.disabled);
    // 실행 권한이 없는 관리자(설정 보기만)에게도 보이되 잠긴다 — 2026-10-04 결정
    expect(run?.if).toBe("{{!!passwordExpiryManualRuns?.data?.targets?.['password_expiry:' + stage]}}");
    expect(disabled).toContain("!passwordExpiryManualRuns?.data?.targets?.['password_expiry:' + stage]?.can_run");
    expect(disabled).toContain('_local.hasChanges');
    expect(disabled).toContain('!passwordExpiryPreview?.data?.enabled');
    expect(disabled).toContain('run?.is_active');
    const opens = flattenActions(run?.actions);
    expect(opens.some((a) => a.handler === 'openModal' && a.target === 'password_expiry_run_modal')).toBe(true);
    expect(opens.some((a) => a.handler === 'apiCall' && String(a.target).includes('/api/admin/privacy/manual-runs/'))).toBe(true);
  });
});

describe('비밀번호 만료 직권 실행 확인 창', () => {
  /**
   * @scenario surface=run_modal, stage=notice
   * @effects run_modal_shows_total_and_this_run
   */
  it('전체 대상과 이번 실행 수를 함께 보이고, 남은 인원은 차이가 있을 때만 보인다', () => {
    const count = find(modalNodes, 'password_expiry_run_count');
    expect(JSON.stringify(count)).toContain('total={{_global.passwordExpiryRun?.total ?? 0}}');
    expect(JSON.stringify(count)).toContain('count={{_global.passwordExpiryRun?.preview?.count ?? 0}}');
    expect(find(modalNodes, 'password_expiry_run_remaining')?.if).toContain('(_global.passwordExpiryRun?.total ?? 0) > (_global.passwordExpiryRun?.preview?.count ?? 0)');
    // 상한 행은 상한이 이번 실행을 실제로 자를 때만(디자인 캔버스 RunModal)
    expect(find(modalNodes, 'password_expiry_run_batch')?.if).toContain('(_global.passwordExpiryRun?.total ?? 0) > (_global.passwordExpiryRun?.batchSize ?? 0)');
    // 큐 안내·마지막 실행 줄은 만료 전용 문구(「N명 처리」) — 개인정보 탭 공용 문구(「N건」)를 쓰지 않는다
    expect(JSON.stringify(runModal)).toContain('admin.settings.security.password_expiry.run.queue_hint');
    expect(JSON.stringify(runModal)).not.toContain('admin.settings.privacy.manual_run.queue_hint');
    expect(JSON.stringify(policy)).toContain('admin.settings.security.password_expiry.last_completed|');
    expect(JSON.stringify(policy)).not.toContain('admin.settings.privacy.manual_run.last_completed');
  });

  /**
   * @scenario surface=run_modal, stage=expire
   * @effects run_modal_blocks_when_off_and_reddens_expire
   */
  it('꺼져 있으면 실행을 막고, 만료 처리는 빨간 실행 버튼이다', () => {
    const execute = find(modalNodes, 'password_expiry_run_execute');
    expect(String(execute?.props?.disabled)).toContain('preview?.enabled === false');
    expect(String(execute?.props?.className)).toContain("stage === 'expire' ? 'btn btn-danger flex-center gap-2' : 'btn btn-primary flex-center gap-2'");
    const success = flattenActions(flattenActions(execute?.actions).find((a) => a.handler === 'apiCall')?.onSuccess);
    expect(success.filter((a) => a.handler === 'refetchDataSource').map((a) => a.params?.dataSourceId)).toEqual(
      expect.arrayContaining(['passwordExpiryManualRuns', 'passwordExpiryPreview']),
    );
  });

  /**
   * 더블클릭: `disabled` 의 submitting 은 다시 그려진 뒤에야 걸려 두 번째 클릭이 한 번 더 접수됐다(2026-10-04 결정 P10 —
   * 이 창의 실행 버튼만 잠근다). 버튼이 첫 클릭 즉시 스스로 잠그고, 처리 중 표시는 종전대로 submitting 으로 그린다.
   *
   * @scenario surface=run_modal, stage=notice
   * @effects run_execute_locks_repeat_click
   */
  it('실행 버튼은 연타 잠금을 켜고, 처리 중에는 disabled 로 넘긴다', () => {
    const execute = find(modalNodes, 'password_expiry_run_execute');
    expect(execute?.props?.lockRepeatClick).toBe(true);
    expect(String(execute?.props?.disabled)).toContain('_global.passwordExpiryRun?.submitting');
    const first = flattenActions(execute?.actions).find((a) => a.handler === 'sequence')?.actions?.[0];
    expect(first?.handler).toBe('setState');
  });
});

describe('환경설정 화면 배선', () => {
  /**
   * @scenario surface=security_tab, expiry=on
   * @effects settings_wires_expiry_sources_and_modal
   */
  it('보안 탭 전용 데이터 소스 둘·대기·저장 후 재조회·확인 창을 등록한다', () => {
    const json = settings as any;
    const sources = asArray<any>(json.data_sources);
    for (const id of ['passwordExpiryPreview', 'passwordExpiryManualRuns']) {
      const source = sources.find((s) => s.id === id);
      expect(source, `${id} 데이터 소스가 없다`).toBeDefined();
      expect(String(source?.if)).toContain("=== 'security'");
    }
    expect(JSON.stringify(json.transition_overlay)).toContain('passwordExpiryPreview');
    expect(JSON.stringify(json.transition_overlay)).toContain('passwordExpiryManualRuns');
    expect(JSON.stringify(json)).toContain('"dataSourceId":"passwordExpiryPreview"');
    expect(JSON.stringify(json.modals ?? json)).toContain('_modal_password_expiry_run');
  });
});

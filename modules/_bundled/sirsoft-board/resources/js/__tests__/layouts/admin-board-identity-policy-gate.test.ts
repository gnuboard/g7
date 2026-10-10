// e2e:allow 권한 게이트(탭 안내·쓰기 잠금) 레이아웃 계약 검증 — 서버 403·abilities 는 PHPUnit BoardIdentityPolicyPermissionGateTest 가 잠그고, 브라우저 실측은 통합 뒤 총괄 세션이 권한 회수 계정으로 한다
/**
 * @file admin-board-identity-policy-gate.test.ts
 * @description 게시판 환경설정 「본인인증 정책」 탭의 권한 게이트 (제품 결정 2026-10-10)
 *
 * 이 탭은 게시판 권한 `sirsoft-board.identity.policies.read`/`.update` 로 동작한다.
 * - 목록·추가·수정·삭제 API 는 게시판 모듈 경로(/api/modules/sirsoft-board/admin/identity-policies)를 쓴다
 *   — 코어 경로는 코어 권한을 요구해 게시판 권한이 아무 효과가 없었다
 * - 조회 권한이 없으면(목록 403) 탭 내용 대신 「권한 없음」 안내를 보인다
 * - 수정 권한이 없으면(abilities.can_update=false) 읽기 전용 안내를 보이고, 추가·토글·편집·삭제·저장이 잠긴다
 * - 인증 제공자 선택지는 권한이 필요 없는 공개 목록에서 받는다(코어 관리자 권한이 없어도 폼이 빈 선택지가 되지 않게)
 */

import { describe, it, expect } from 'vitest';
import settingsLayout from '../../../layouts/admin/admin_board_settings.json';
import policyTab from '../../../layouts/admin/partials/admin_board_settings/_tab_identity_policies.json';
import policyFormModal from '../../../layouts/admin/partials/admin_board_settings/_modal_identity_policy_form.json';
import policyDeleteModal from '../../../layouts/admin/partials/admin_board_settings/_modal_identity_policy_delete.json';
import koSettings from '../../../lang/partial/ko/admin/settings.json';
import enSettings from '../../../lang/partial/en/admin/settings.json';

type Node = Record<string, any>;

const MODULE_ROUTE = '/api/modules/sirsoft-board/admin/identity-policies';
const DATA_SOURCE_ID = 'boardIdentityPolicies';

/** 트리에서 조건에 맞는 노드를 모두 모은다 */
function collect(node: unknown, pred: (n: Node) => boolean, out: Node[] = []): Node[] {
  if (Array.isArray(node)) {
    node.forEach((v) => collect(v, pred, out));
    return out;
  }
  if (node && typeof node === 'object') {
    if (pred(node as Node)) out.push(node as Node);
    Object.values(node).forEach((v) => collect(v, pred, out));
  }
  return out;
}

/** id(data-ref) 로 노드 하나를 찾는다 */
function byId(node: unknown, id: string): Node {
  const hit = collect(node, (n) => n.id === id)[0];
  expect(hit, `노드 ${id} 가 없습니다`).toBeTruthy();
  return hit;
}

/** `{{ ... }}` 표현식을 주어진 이름들로 평가한다 */
function evaluate(raw: unknown, scope: Record<string, unknown>): unknown {
  const expr = String(raw).trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(scope), `return (${expr});`)(...Object.values(scope));
}

/** 목록 응답(abilities)과 로드 오류로 화면 computed 를 평가한다 */
function computedFor(abilities: Record<string, boolean> | undefined, loadError?: string) {
  const computed = (settingsLayout as Node).computed as Record<string, string>;
  const scope = {
    [DATA_SOURCE_ID]: { data: abilities ? { data: [], abilities } : { data: [], meta: {} } },
    _local: { identityPoliciesLoadError: loadError },
  };
  return {
    noRead: evaluate(computed.identityPoliciesNoReadPermission, scope),
    readOnly: evaluate(computed.isIdentityPolicyReadOnly, scope),
  };
}

/** 노드 아래 apiCall 대상 경로를 모두 모은다 */
function apiTargets(node: unknown): string[] {
  return collect(node, (n) => n.handler === 'apiCall').map((n) => String(n.target));
}

/** apiCall 을 품은 버튼(저장·삭제 실행 버튼)을 찾는다 */
function submitButton(node: unknown): Node {
  const hit = collect(node, (n) => n.name === 'Button' && apiTargets(n.actions ?? []).length > 0)[0];
  expect(hit, 'apiCall 을 실행하는 버튼이 없습니다').toBeTruthy();
  return hit;
}

describe('게시판 본인인증 정책 탭 — API 경로와 권한 축', () => {
  const ds = ((settingsLayout as Node).data_sources as Node[]).find((d) => d.id === DATA_SOURCE_ID)!;

  /**
   * @scenario case=identity_tab_module_route
   * @effects identity_tab_uses_module_permission_route
   */
  it('목록 데이터소스는 게시판 모듈 경로를 쓰고, 403 이면 「권한 없음」 표지를 남긴다', () => {
    expect(ds.endpoint).toBe(MODULE_ROUTE);
    expect(ds.errorHandling?.['403']).toMatchObject({
      handler: 'setState',
      params: { target: 'local', identityPoliciesLoadError: 'forbidden' },
    });
  });

  /**
   * @scenario case=identity_tab_module_route
   * @effects identity_tab_uses_module_permission_route
   */
  it('토글·저장·삭제 apiCall 이 모두 게시판 모듈 경로를 쓴다(코어 관리자 경로 잔존 없음)', () => {
    const targets = [...apiTargets(policyTab), ...apiTargets(policyFormModal), ...apiTargets(policyDeleteModal)];
    expect(targets.length).toBeGreaterThanOrEqual(3);
    for (const target of targets) {
      expect(target).toContain(MODULE_ROUTE);
    }
    const serialized = JSON.stringify({ settingsLayout, policyTab, policyFormModal, policyDeleteModal });
    expect(serialized).not.toContain('/api/admin/identity/policies');
  });

  /**
   * @scenario case=identity_tab_module_route
   * @effects identity_provider_options_need_no_core_permission
   */
  it('인증 제공자 선택지는 권한이 필요 없는 공개 목록에서 받는다', () => {
    const providers = ((settingsLayout as Node).data_sources as Node[]).find((d) => d.id === 'identityProviders')!;
    expect(providers.endpoint).toBe('/api/identity/providers');
  });
});

describe('게시판 본인인증 정책 탭 — 조회 권한 없음', () => {
  /**
   * @scenario case=identity_tab_without_read
   * @effects identity_tab_shows_no_permission_notice
   */
  it('목록이 403 이면 탭 내용 대신 「권한 없음」 안내를 보인다', () => {
    expect(computedFor(undefined, 'forbidden').noRead).toBe(true);
    expect(computedFor({ can_create: false, can_update: false, can_delete: false }, 'forbidden').noRead).toBe(false);
    expect(computedFor(undefined, undefined).noRead).toBe(false);

    const notice = byId(policyTab, 'identity_policies_no_permission');
    expect(notice.if).toBe('{{_computed.identityPoliciesNoReadPermission}}');
    expect(JSON.stringify(notice)).toContain('$t:sirsoft-board.admin.settings.identity.no_read_permission');

    const pageRoot = byId(policyTab, 'page_root');
    expect(pageRoot.if).toBe('{{!_computed.identityPoliciesNoReadPermission}}');
  });
});

describe('게시판 본인인증 정책 탭 — 수정 권한 없음(읽기 전용)', () => {
  /**
   * @scenario case=identity_tab_read_only
   * @effects identity_tab_read_only_locks_writes
   */
  it('수정 권한이 없으면 읽기 전용이고 안내를 보인다', () => {
    expect(computedFor({ can_create: false, can_update: false, can_delete: false }).readOnly).toBe(true);
    expect(computedFor(undefined).readOnly).toBe(true);
    expect(computedFor({ can_create: true, can_update: true, can_delete: true }).readOnly).toBe(false);

    const notice = byId(policyTab, 'identity_policies_read_only_notice');
    const scopeFor = (canUpdate: boolean | undefined) => ({
      [DATA_SOURCE_ID]: { data: canUpdate === undefined ? { data: [] } : { data: [], abilities: { can_update: canUpdate } } },
    });
    expect(evaluate(notice.if, scopeFor(false))).toBe(true);
    expect(evaluate(notice.if, scopeFor(true))).toBe(false);
    expect(evaluate(notice.if, scopeFor(undefined))).toBe(false);
    expect(JSON.stringify(notice)).toContain('$t:sirsoft-board.admin.settings.identity.read_only_notice');
  });

  /**
   * @scenario case=identity_tab_read_only
   * @effects identity_tab_read_only_locks_writes
   */
  it('정책 추가·행 토글·편집은 abilities 로 잠기고 삭제는 숨는다', () => {
    const addButtons = collect(policyTab, (n) => n.name === 'Button' && String(n.props?.disabled ?? '').includes(`${DATA_SOURCE_ID}?.data?.abilities?.can_create !== true`));
    expect(addButtons.length).toBe(1);

    const toggles = collect(policyTab, (n) => n.name === 'Toggle');
    expect(toggles.length).toBeGreaterThan(0);
    for (const toggle of toggles) {
      expect(evaluate(toggle.props.disabled, { policy: { abilities: { can_update: false } } })).toBe(true);
    }

    const deleteButtons = collect(policyTab, (n) => n.name === 'Button' && String(n.if ?? '').includes('can_delete'));
    expect(deleteButtons.length).toBeGreaterThan(0);
    for (const button of deleteButtons) {
      expect(evaluate(button.if, { policy: { source_type: 'admin', abilities: { can_delete: false } } })).toBe(false);
    }
  });

  /**
   * @scenario case=identity_tab_read_only
   * @effects identity_tab_read_only_locks_writes
   */
  it('정책 창의 [저장]과 삭제 확인 창의 [삭제]도 수정 권한이 없으면 잠긴다', () => {
    const scopeFor = (allowed: boolean) => ({
      _global: { identity_policy_form_modal: { isSaving: false }, boardIdentityPolicyDeleting: false },
      [DATA_SOURCE_ID]: { data: { data: [], abilities: { can_create: allowed, can_update: allowed, can_delete: allowed } } },
    });

    const save = submitButton(policyFormModal);
    expect(evaluate(save.props.disabled, scopeFor(false))).toBe(true);
    expect(evaluate(save.props.disabled, scopeFor(true))).toBe(false);

    const confirmDelete = submitButton(policyDeleteModal);
    expect(evaluate(confirmDelete.props.disabled, scopeFor(false))).toBe(true);
    expect(evaluate(confirmDelete.props.disabled, scopeFor(true))).toBe(false);
  });
});

describe('게시판 본인인증 정책 탭 — 안내 문구', () => {
  /**
   * @scenario case=identity_tab_without_read
   * @effects identity_tab_shows_no_permission_notice
   */
  it('안내 문구가 ko·en 에 모두 있다', () => {
    for (const lang of [koSettings, enSettings] as Node[]) {
      expect(typeof lang.identity?.no_read_permission).toBe('string');
      expect(typeof lang.identity?.read_only_notice).toBe('string');
    }
  });
});

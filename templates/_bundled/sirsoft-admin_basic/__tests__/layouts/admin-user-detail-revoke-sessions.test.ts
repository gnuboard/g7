/**
 * @file admin-user-detail-revoke-sessions.test.ts
 * @description 회원 상세 「모든 기기에서 로그아웃」 회귀 테스트 (sirsoft-admin_basic, 7.2.0 A4t·K16)
 *
 * - 상단 작업 버튼(수정·잠금 해제 옆)이 확인 창 `revoke_sessions_confirm_modal` 을 연다. 회원 수정 권한(`abilities.can_update`)이
 *   없으면 잠긴다 — 서버 라우트도 같은 권한(`core.users.update`)을 요구하는지 대조한다.
 * - 확인하면 `POST /api/admin/users/{uuid}/sessions/revoke` 를 보내고, 성공하면 토스트 + 창 닫기, 실패하면 서버 문구 토스트.
 *   진행 중 표시는 두 갈래 모두에서 끈다.
 *
 * @since 1.2.0
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, expect } from 'vitest';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import userDetailLayout from '../../layouts/admin_user_detail.json';
import adminKo from '../../lang/partial/ko/admin.json';
import adminEn from '../../lang/partial/en/admin.json';

type Json = Record<string, any>;

/**
 * 저장소 루트를 위로 훑어 찾는다 (`_bundled` 여부로 깊이가 달라지므로 상대경로 고정 금지).
 *
 * @returns 저장소 루트 절대경로
 */
function repositoryRoot(): string {
  let current = path.dirname(fileURLToPath(import.meta.url));
  for (let depth = 0; depth < 10; depth++) {
    if (fs.existsSync(path.join(current, 'config/core.php'))) return current;
    current = path.dirname(current);
  }
  throw new Error('config/core.php 를 가진 저장소 루트를 찾지 못했습니다.');
}

/**
 * 트리를 평탄화합니다 (자식·슬롯·모달 포함).
 *
 * @param node 노드
 * @returns 노드 목록
 */
function flatten(node: any): Json[] {
  if (!node || typeof node !== 'object') return [];
  if (Array.isArray(node)) return node.flatMap(flatten);
  const children = Array.isArray(node.children) ? node.children.flatMap(flatten) : [];
  // Modal 고정 하단(props.footer)의 단추도 걷는다(바닥 단추를 footer 로 옮김)
  const footer = Array.isArray(node.props?.footer) ? node.props.footer.flatMap(flatten) : [];
  const slots = node.slots ? Object.values(node.slots).flatMap(flatten) : [];
  const modals = Array.isArray(node.modals) ? node.modals.flatMap(flatten) : [];
  return [node, ...children, ...footer, ...slots, ...modals];
}

/**
 * 액션을 평탄화합니다 — 원소 하나짜리 배열은 객체로 접혀 굽히므로(D67) asArray 로 정규화한다.
 *
 * @param actions 액션(들)
 * @returns 액션 목록
 */
function flattenActions(actions: any): Json[] {
  if (!actions) return [];
  return asArray<Json>(actions).flatMap((a) => [
    a,
    ...flattenActions(a.actions),
    ...flattenActions(a.onSuccess),
    ...flattenActions(a.onError),
  ]);
}

/**
 * `{{식}}` 을 주어진 값으로 계산합니다.
 *
 * @param expr 식 문자열
 * @param scope 계산에 쓸 값
 * @returns 계산 결과
 */
function evaluate(expr: string, scope: Json): any {
  const body = String(expr).replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(scope), `return (${body});`)(...Object.values(scope));
}

/**
 * dot 경로로 lang 값을 읽습니다.
 *
 * @param lang lang JSON
 * @param path 경로
 * @returns 값
 */
function langAt(lang: Json, path: string): unknown {
  return path.split('.').reduce<any>((acc, key) => (acc == null ? acc : acc[key]), lang);
}

const root = repositoryRoot();
const nodes = flatten(userDetailLayout);
const byId = (id: string): Json | undefined => nodes.find((n) => n.id === id);
const button = byId('header_revoke_sessions_button');
const modal = byId('revoke_sessions_confirm_modal');
const confirm = byId('revoke_sessions_confirm_button');
const confirmActions = flattenActions(confirm?.actions);
const request = confirmActions.find((a) => a.handler === 'apiCall');

describe('sirsoft-admin_basic 회원 상세 「모든 기기에서 로그아웃」 (A4t)', () => {
  /**
   * @scenario surface=detail_header, action=click
   * @effects revoke_button_opens_confirm_modal
   */
  it('상단 작업 버튼이 바로 요청하지 않고 확인 창을 연다', () => {
    const header = nodes.find((n) => asArray<Json>(n.children).some((c) => c.id === 'header_edit_button'));
    expect(asArray<Json>(header?.children).map((c) => c.id)).toContain('header_revoke_sessions_button');

    const click = asArray<Json>(button?.actions);
    expect(click).toHaveLength(1);
    expect(click[0]).toMatchObject({ type: 'click', handler: 'openModal', target: 'revoke_sessions_confirm_modal' });
    expect(flatten(button).map((n) => n.text)).toContain('$t:admin.users.detail.revoke_sessions.button');

    expect((userDetailLayout as Json).modals.map((m: Json) => m.id)).toContain('revoke_sessions_confirm_modal');
    expect(modal?.props?.title).toBe('$t:admin.users.detail.revoke_sessions.confirm_title');
    expect(flatten(modal).map((n) => n.text)).toContain('$t:admin.users.detail.revoke_sessions.confirm_message');
  });

  /**
   * @scenario surface=detail_header, ability=can_update|cannot_update
   * @effects revoke_button_requires_update_ability
   */
  it.each([
    [true, false],
    [false, true],
    [undefined, true],
  ])('can_update=%s 이면 버튼 잠김=%s — 서버 라우트와 같은 권한이다', (canUpdate, disabled) => {
    expect(Boolean(evaluate(button?.props?.disabled, { user: { data: { abilities: { can_update: canUpdate } } } }))).toBe(disabled);

    const routes = fs.readFileSync(path.join(root, 'routes/api.php'), 'utf-8');
    expect(routes).toMatch(/\{user\}\/sessions\/revoke'.*permission:admin,core\.users\.update/);
    const resource = fs.readFileSync(path.join(root, 'app/Http/Resources/UserResource.php'), 'utf-8');
    expect(resource).toMatch(/'can_update'\s*=>\s*'core\.users\.update'/);
  });

  /**
   * @scenario surface=confirm_modal, action=confirm, response=ok|error
   * @effects confirm_posts_revoke_and_toasts_success
   */
  it('확인하면 POST /api/admin/users/{uuid}/sessions/revoke 를 보내고 성공 토스트 뒤 창을 닫는다', () => {
    const start = asArray<Json>(asArray<Json>(confirm?.actions)[0]?.actions)[0];
    expect(start?.params).toEqual({ target: 'global', isRevokingSessions: true });

    // 회원 상세 경로의 id 는 uuid 다(라우트 모델 바인딩 `{user}` 가 uuid 로 찾는다)
    expect(request?.target).toBe('/api/admin/users/{{route?.id}}/sessions/revoke');
    expect(request?.params?.method).toBe('POST');
    expect(request?.auth_required).toBe(true);

    const ok = asArray<Json>(request?.onSuccess);
    expect(ok.find((a) => a.handler === 'toast')?.params).toEqual({ type: 'success', message: '$t:admin.users.detail.revoke_sessions.success' });
    expect(ok.some((a) => a.handler === 'closeModal')).toBe(true);
    expect(ok.find((a) => a.handler === 'setState')?.params?.isRevokingSessions).toBe(false);

    const fail = asArray<Json>(request?.onError);
    expect(fail.find((a) => a.handler === 'toast')?.params).toEqual({ type: 'error', message: '{{error.message}}' });
    expect(fail.find((a) => a.handler === 'setState')?.params?.isRevokingSessions).toBe(false);
    // 실패하면 창을 닫지 않는다(다시 시도할 수 있게)
    expect(fail.some((a) => a.handler === 'closeModal')).toBe(false);

    // 진행 중에는 두 버튼이 잠긴다
    const cancel = flatten(modal).find((n) => asArray<Json>(n.actions).some((a) => a.handler === 'closeModal'));
    for (const target of [confirm, cancel]) {
      expect(Boolean(evaluate(target?.props?.disabled, { _global: { isRevokingSessions: true } }))).toBe(true);
      expect(Boolean(evaluate(target?.props?.disabled, { _global: {} }))).toBe(false);
    }
  });

  /**
   * @scenario locale=ko|en
   * @effects revoke_sessions_lang_keys_present
   */
  it.each([
    ['ko', adminKo],
    ['en', adminEn],
  ])('%s lang 에 admin.users.detail.revoke_sessions.* 문구가 있다', (_locale, lang) => {
    for (const key of ['button', 'confirm_title', 'confirm_message', 'confirm', 'success']) {
      expect(typeof langAt(lang as Json, `users.detail.revoke_sessions.${key}`), key).toBe('string');
    }
  });
});

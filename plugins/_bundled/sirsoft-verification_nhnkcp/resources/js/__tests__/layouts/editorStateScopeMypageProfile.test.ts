/**
 * @file editorStateScopeMypageProfile.test.ts
 * @description 마이페이지 본인확인 카드 편집기 상태가 실제 화면 경로에 붙는지
 *
 * 레이아웃 편집기 상태 표본을 전수 순회했더니 마이페이지 「내 정보」에서 이 플러그인의 상태 고르개가 나타나지 않았다.
 * 상태 범위가 `*\/mypage/profile` 로 적혀 있었는데, 편집기의 `*` 는 정확히 한 경로 조각을 뜻해 사용자 화면 경로
 * `/mypage/profile` 과 맞지 않는다(`*\/admin/...` 은 관리자 라우트 자체가 `*\/admin` 으로 정의돼 있어 맞는다).
 * 편집기 자체의 범위 판정 함수로, 사용자 템플릿에 실제로 있는 경로에서 세 상태가 잡히는지 본다.
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { deriveCurrentScope, matchStateItems } from '@core/template-engine/layout-editor/utils/matchStateScope';

const PLUGIN_ROOT = resolve(__dirname, '../../../..');
const spec = JSON.parse(readFileSync(resolve(PLUGIN_ROOT, 'editor-spec.json'), 'utf8'));
const basicRoutes = JSON.parse(readFileSync(resolve(PLUGIN_ROOT, '../../../templates/_bundled/sirsoft-basic/routes.json'), 'utf8'));

describe('본인확인 카드 편집기 상태 — 마이페이지 경로', () => {
  /**
   * @scenario route=mypage_profile
   * @effects nhnkcp_profile_states_match_user_route
   */
  it('사용자 템플릿의 /mypage/profile 경로에서 세 상태가 잡힌다', () => {
    const paths = (basicRoutes.routes as Array<{ path: string }>).map((r) => r.path);
    expect(paths).toContain('/mypage/profile');

    const ids = matchStateItems(spec.states.groups, deriveCurrentScope('route', '/mypage/profile')).map((i) => i.id);

    expect(ids).toEqual(expect.arrayContaining(['nhnkcp_record_verified', 'nhnkcp_record_birthday_missing', 'nhnkcp_record_none']));
  });

  /**
   * @scenario route=mypage_profile
   * @effects nhnkcp_profile_states_scope_has_no_segment_glob
   */
  it('사용자 화면 범위는 한 조각 glob(*)으로 시작하지 않는다', () => {
    const userScopes = (spec.states.groups as Array<{ scope: { kind: string; match: string } }>)
      .filter((g) => g.scope?.kind === 'route' && !g.scope.match.startsWith('*/admin'))
      .map((g) => g.scope.match);

    expect(userScopes.length).toBeGreaterThan(0);
    for (const match of userScopes) expect(match.startsWith('*/'), match).toBe(false);
  });
});

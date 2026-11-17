/**
 * @file admin-base-avatar-logo-thumbnail.test.ts
 * @description 관리자 헤더 사용자 사진·로고 — 실제 응답 필드와 썸네일 규격 계약 (#869)
 *
 * - 헤더 사진은 관리자 사용자 응답(`/api/admin/auth/user`)의 `avatar` 를 읽는다. 종전 `avatar_url` 은 응답에 없는
 *   필드라 사진을 올린 관리자에게도 늘 대신 그림(아이콘)이 나왔다. 사진 칸이 작아 규격 `xs` 로 요청한다.
 * - 사이드바·모바일 헤더 로고는 원본 대신 규격 `md` 로 요청하고, 첫 화면 자리라 지연 로딩하지 않는다(`eager`).
 *
 * @scenario-manifest tests/scenarios/admin-base-avatar-logo-thumbnail.yaml
 */

import { describe, it, expect } from 'vitest';
import layout from '../../layouts/_admin_base.json';

type Node = Record<string, any>;

/** id 로 노드를 깊이 우선으로 찾는다. */
function byId(node: unknown, id: string): Node | undefined {
  if (Array.isArray(node)) {
    for (const c of node) {
      const r = byId(c, id);
      if (r) return r;
    }
  } else if (node && typeof node === 'object') {
    if ((node as Node).id === id) return node as Node;
    for (const v of Object.values(node as Node)) {
      const r = byId(v, id);
      if (r) return r;
    }
  }

  return undefined;
}

describe('관리자 헤더 사용자 사진·로고 썸네일', () => {
  /**
   * 사진은 응답의 avatar 필드를 쓰고, 사진이 없을 때만 대신 그림이 나온다. 대신 그림은 사용자 정보를 받은 뒤에만
   * 판정한다 — 받기 전(`current_user` 가 undefined)에는 사진이 없는 것으로 읽지 않는다(#915, 7.1.6 받는 중 노출 규칙).
   *
   * @scenario target=header_avatar
   * @effects avatar_reads_response_field
   */
  it('헤더 사진은 current_user.data.avatar 를 xs 규격으로 그린다', () => {
    const img = byId(layout, 'user_avatar_img');
    const fallback = byId(layout, 'user_avatar_fallback');
    expect(img?.props?.src).toBe('{{current_user.data?.avatar}}');
    expect(img?.if).toBe('{{current_user.data?.avatar}}');
    expect(img?.props?.thumbPreset).toBe('xs');
    expect(fallback?.if).toBe('{{current_user !== undefined && (!current_user.data?.avatar)}}');
    expect(JSON.stringify(layout)).not.toMatch(/\{\{[^}]*avatar_url/);
  });

  /**
   * 헤더 사진은 모든 관리자 화면 첫 화면에 걸리므로 지연 로딩하지 않고, 이름이 없을 때의 대체 문구는 다국어다.
   *
   * @scenario target=header_avatar
   * @effects avatar_eager_and_translated_alt
   */
  it('헤더 사진은 eager 로딩이고 alt 대체 문구가 다국어 키다', () => {
    const img = byId(layout, 'user_avatar_img');
    expect(img?.props?.loading).toBe('eager');
    // 대체 문구는 옆 이름 줄과 같이 표시 이름을 먼저 읽는다(#490 8단계)
    expect(img?.props?.alt).toBe("{{current_user.data?.display_name ?? current_user.data?.name ?? $t('admin.default_user_name')}}");
    expect(String(img?.props?.alt)).not.toContain("'User'");
  });

  /**
   * 로고 두 자리 모두 md 규격이고 지연 로딩하지 않는다.
   *
   * @scenario target=logo
   * @effects logo_thumbnail_eager
   */
  it('사이드바·모바일 로고는 md 규격, eager 로딩이다', () => {
    for (const id of ['logo_image', 'mobile_logo_image']) {
      const img = byId(layout, id);
      // 템플릿 설정 로고(branding.logo)가 있으면 그것, 없으면 환경설정 사이트 로고(#434). 규격은 그대로 md 다.
      expect(img?.props?.src, id).toBe('{{(_global.templateSettings?.branding?.logo_url || _global.settings?.general?.site_logo_url)}}');
      expect(img?.props?.thumbPreset, id).toBe('md');
      expect(img?.props?.loading, id).toBe('eager');
    }
  });
});

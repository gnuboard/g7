/**
 * @file admin-user-detail-password-status.test.tsx
 * @description 관리자 회원 상세 「비밀번호」 구획 구조 회귀 테스트 (#736)
 *
 * 활동 정보 바로 뒤·동의정보 앞에 서고, 값은 상세 응답에만 실리는 `password_status` 를 읽는다.
 * 만료 예정은 적용 안 됨 / 만료됨 / 날짜(D-n) 세 갈래로 그리고, 연기 종료는 연기 중일 때만 보인다.
 *
 * @since engine-v1.77.0
 */

import { describe, it, expect } from 'vitest';

import detail from '../../layouts/admin_user_detail.json';

function flatten(node: any): any[] {
  if (!node || typeof node !== 'object') return [];
  if (Array.isArray(node)) return node.flatMap(flatten);
  const children = Array.isArray(node.children) ? node.children.flatMap(flatten) : [];
  return [node, ...children];
}

const nodes = flatten((detail as any).slots?.content ?? (detail as any).components);
const ids = nodes.map((n) => n.id).filter(Boolean) as string[];

describe('관리자 회원 상세 — 비밀번호 구획 (#736)', () => {
  /**
   * @scenario applies=true, expired=false
   * @effects detail_password_section_after_activity
   */
  it('활동 정보 뒤·동의정보 앞에 다섯 칸이 순서대로 선다', () => {
    const at = (id: string) => ids.indexOf(id);
    expect(at('section_password_info')).toBeGreaterThan(at('section_activity_info'));
    expect(at('section_password_info')).toBeLessThan(at('section_consent_info'));
    const fields = ids.filter((id) => id.startsWith('field_password_'));
    expect(fields).toEqual([
      'field_password_changed_at',
      'field_password_expires_at',
      'field_password_change_required',
      'field_password_postponed_until',
      'field_password_expiry_policy',
    ]);
  });

  /**
   * @scenario applies=false, expired=false
   * @effects detail_expiry_branches
   */
  it('만료 예정은 적용 안 됨·만료됨·날짜 세 갈래이고 값은 password_status 를 읽는다', () => {
    const expires = nodes.find((n) => n.id === 'field_password_expires_at');
    const branches = flatten(expires).filter((n) => n.if).map((n) => n.if);
    expect(branches).toEqual([
      '{{!user?.data?.password_status?.applies}}',
      '{{user?.data?.password_status?.applies && user?.data?.password_status?.expired}}',
      '{{user?.data?.password_status?.applies && !user?.data?.password_status?.expired}}',
    ]);
    expect(nodes.find((n) => n.id === 'field_password_postponed_until')?.if).toBe('{{user?.data?.password_status?.postponed_until}}');
  });
});

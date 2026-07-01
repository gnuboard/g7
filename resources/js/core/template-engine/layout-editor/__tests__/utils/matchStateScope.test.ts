/**
 * matchStateScope.test.ts — 페이지 상태 scope 매칭
 *
 * 검증:
 *  - deriveCurrentScope: route/base/modal 인코딩 → scope 기준 / extension·iteration·미선택 디그레이드
 *  - matchStateItems: kind+match 정확 일치 / route glob 1단계(`*`) / 그룹 concat / 미매칭 빈 배열
 *  - resolveDefaultStateId: default:true 우선 / 없으면 첫 항목 / 빈 배열 null
 */

import { describe, it, expect } from 'vitest';
import {
  deriveCurrentScope,
  matchStateItems,
  resolveDefaultStateId,
} from '../../utils/matchStateScope';
import type { EditorStateGroupSpec } from '../../spec/specTypes';

describe('deriveCurrentScope — 편집 대상 scope 도출', () => {
  it('route 모드 → 라우트 path 그대로', () => {
    expect(deriveCurrentScope('route', '/admin/users')).toEqual({ kind: 'route', match: '/admin/users' });
  });

  it('base 모드 → `__base__/{name}` 에서 식별자 추출', () => {
    expect(deriveCurrentScope('base', '__base__/_user_base')).toEqual({ kind: 'base', match: '_user_base' });
  });

  it('modal 모드 → `__modal__/{id}` 에서 modal id 추출', () => {
    expect(deriveCurrentScope('modal', '__modal__/confirm_delete')).toEqual({
      kind: 'modal',
      match: 'confirm_delete',
    });
  });

  it('iteration_item 모드는 디그레이드(null)', () => {
    expect(deriveCurrentScope('iteration_item', '__iteration__/0.children.1')).toBeNull();
  });

  it('extension 모드 — hostRoutePath 없으면 디그레이드(null)', () => {
    // 호스트 path 미상(picker 미선택 등) → 폴백 안내로 디그레이드.
    expect(deriveCurrentScope('extension', '__extension__/35')).toBeNull();
    expect(deriveCurrentScope('extension', '__extension__/35', null)).toBeNull();
    expect(deriveCurrentScope('extension', '__extension__/35', '')).toBeNull();
  });

  it('extension 모드 — hostRoutePath(라우트) → route scope', () => {
    // 호스트가 라우트 레이아웃이면 그 라우트 path 로 route scope 매칭 (게이트 뒤 조각 노출).
    expect(deriveCurrentScope('extension', '__extension__/35', '/board/free/write')).toEqual({
      kind: 'route',
      match: '/board/free/write',
    });
  });

  it('extension 모드 — hostRoutePath(`__base__/`) → base scope', () => {
    // 호스트가 공통 base 레이아웃(라우트 노드 없음)이면 base scope 로 매칭.
    expect(deriveCurrentScope('extension', '__extension__/38', '__base__/_user_base')).toEqual({
      kind: 'base',
      match: '_user_base',
    });
  });

  it('path 미선택 시 null', () => {
    expect(deriveCurrentScope('route', null)).toBeNull();
    expect(deriveCurrentScope('route', undefined)).toBeNull();
  });

  it('base/modal 접두사 불일치 시 null', () => {
    expect(deriveCurrentScope('base', '/some/path')).toBeNull();
  });
});

describe('matchStateItems — scope 매칭 items 평탄화', () => {
  const groups: EditorStateGroupSpec[] = [
    {
      scope: { kind: 'route', match: '/login' },
      items: [
        { id: 'default', default: true },
        { id: 'login_failed' },
      ],
    },
    {
      scope: { kind: 'route', match: '/board/:slug/write' },
      items: [{ id: 'write_member' }],
    },
    {
      scope: { kind: 'base', match: '_user_base' },
      items: [{ id: 'logged_in' }],
    },
  ];

  it('kind+match 정확 일치 그룹의 items 만 반환', () => {
    const items = matchStateItems(groups, { kind: 'route', match: '/login' });
    expect(items.map((i) => i.id)).toEqual(['default', 'login_failed']);
  });

  it('path param 토큰(:slug)은 리터럴 정확 일치', () => {
    expect(matchStateItems(groups, { kind: 'route', match: '/board/:slug/write' }).map((i) => i.id)).toEqual([
      'write_member',
    ]);
    // 실제 채워진 path 는 매칭 안 됨(시뮬레이션 — 토큰 리터럴 키)
    expect(matchStateItems(groups, { kind: 'route', match: '/board/free/write' })).toEqual([]);
  });

  it('base kind 정확 일치', () => {
    expect(matchStateItems(groups, { kind: 'base', match: '_user_base' }).map((i) => i.id)).toEqual([
      'logged_in',
    ]);
  });

  it('route `*` glob 1단계 매칭 (한 세그먼트)', () => {
    const globGroups: EditorStateGroupSpec[] = [
      { scope: { kind: 'route', match: '/admin/*/edit' }, items: [{ id: 'edit' }] },
    ];
    expect(matchStateItems(globGroups, { kind: 'route', match: '/admin/users/edit' }).map((i) => i.id)).toEqual([
      'edit',
    ]);
    // `*` 는 한 세그먼트만 — 슬래시 넘는 매칭 거부
    expect(matchStateItems(globGroups, { kind: 'route', match: '/admin/a/b/edit' })).toEqual([]);
  });

  it('같은 scope 그룹이 둘이면 items concat (확장 네임스페이스 병합)', () => {
    const merged: EditorStateGroupSpec[] = [
      { scope: { kind: 'route', match: '/x' }, items: [{ id: 'a' }] },
      { scope: { kind: 'route', match: '/x' }, items: [{ id: 'b' }] },
    ];
    expect(matchStateItems(merged, { kind: 'route', match: '/x' }).map((i) => i.id)).toEqual(['a', 'b']);
  });

  it('미매칭/scope null/groups 부재 → 빈 배열 (디그레이드)', () => {
    expect(matchStateItems(groups, { kind: 'route', match: '/none' })).toEqual([]);
    expect(matchStateItems(groups, null)).toEqual([]);
    expect(matchStateItems(undefined, { kind: 'route', match: '/login' })).toEqual([]);
  });

  it('id 없는 항목은 제외', () => {
    const bad: EditorStateGroupSpec[] = [
      { scope: { kind: 'route', match: '/x' }, items: [{ id: '' }, { id: 'ok' }] },
    ];
    expect(matchStateItems(bad, { kind: 'route', match: '/x' }).map((i) => i.id)).toEqual(['ok']);
  });
});

describe('resolveDefaultStateId — 기본 상태 결정', () => {
  it('default:true 인 항목 우선', () => {
    expect(resolveDefaultStateId([{ id: 'a' }, { id: 'b', default: true }, { id: 'c' }])).toBe('b');
  });

  it('default 없으면 첫 항목', () => {
    expect(resolveDefaultStateId([{ id: 'a' }, { id: 'b' }])).toBe('a');
  });

  it('빈 배열 → null', () => {
    expect(resolveDefaultStateId([])).toBeNull();
  });
});

/**
 * @file admin-role-form-create-then-edit-route.test.ts
 * @description 역할 등록·복제 저장 뒤 넘어간 수정 화면이 「수정」으로 저장되는지
 *
 * 등록·복제 저장이 성공하면 방금 만든 역할의 수정 화면(`/admin/roles/{id}/edit`)으로 간다. 종전에는 이 이동에
 * `replace: true` 를 붙였는데, 엔진의 `replace: true` 는 **같은 화면의 쿼리만 바꾸는 이동**이라
 * `G7Core.updateQueryParams` 로 주소만 바꿔 끼우고 라우트 파라미터(`route.id`)는 등록 화면 값(없음) 그대로 둔다
 * (docs/frontend/actions-handlers-navigation.md 「replace: true 는 같은 페이지 내에서만 사용」).
 * 그래서 주소는 수정 화면인데 폼은 여전히 `route.id` 가 없어, 그 화면의 「저장」이 `PUT /api/admin/roles/{id}` 대신
 * `POST /api/admin/roles` 로 나가 같은 식별자 중복 422 가 났다. 주소창으로 연 수정 화면은 정상(라우트 전환을 탐).
 *
 * 레이아웃 JSON 의 실제 이동 액션을 엔진(`ActionDispatcher`)에 넣어, 라우트 전환(`navigate`)을 타는지와
 * 복제 진입 쿼리(`clone_from`)를 수정 화면 주소로 끌고 가지 않는지 본다.
 *
 * @scenario-manifest tests/scenarios/admin-role-save-stay-and-filter.yaml
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { ActionDispatcher } from '@core/template-engine/ActionDispatcher';

type Json = Record<string, any>;

const layout: Json = JSON.parse(
  readFileSync(resolve(__dirname, '../../layouts/admin_role_form.json'), 'utf-8'),
);

/** 트리에서 조건을 만족하는 노드를 모두 모은다. */
function collect(node: unknown, predicate: (n: Json) => boolean, out: Json[] = []): Json[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, predicate, out));
    return out;
  }
  if (node && typeof node === 'object') {
    if (predicate(node as Json)) out.push(node as Json);
    Object.values(node as Json).forEach((v) => collect(v, predicate, out));
  }
  return out;
}

/** 등록·복제 저장 성공 뒤의 수정 화면 이동 */
function createdNavigate(): Json {
  const [nav] = collect(
    layout,
    (n) => n.handler === 'navigate' && typeof n.params?.path === 'string' && n.params.path.includes("'/edit'"),
  );
  expect(nav, '등록 저장 뒤 수정 화면 이동을 찾지 못했습니다').toBeDefined();
  return nav;
}

describe('역할 폼 — 등록·복제 저장 뒤 수정 화면 이동', () => {
  let navigate: ReturnType<typeof vi.fn>;
  let updateQueryParams: ReturnType<typeof vi.fn>;
  let dispatcher: ActionDispatcher;

  beforeEach(() => {
    navigate = vi.fn();
    updateQueryParams = vi.fn().mockResolvedValue(undefined);
    (window as any).G7Core = { ...((window as any).G7Core ?? {}), updateQueryParams };
    dispatcher = new ActionDispatcher({ navigate });
  });

  afterEach(() => {
    delete (window as any).G7Core;
    window.history.replaceState(null, '', '/');
  });

  /**
   * @scenario surface=form_save
   * @effects create_goes_to_new_edit
   */
  it.each([
    { name: '등록', from: '/admin/roles/create?page=2', expected: '/admin/roles/749/edit?page=2' },
    { name: '복제', from: '/admin/roles/create?page=2&clone_from=4', expected: '/admin/roles/749/edit?page=2' },
  ])('$name 저장 뒤 라우트 전환으로 수정 화면에 간다(쿼리만 바꾸는 이동이 아님)', async ({ from, expected }) => {
    window.history.replaceState(null, '', from);

    await dispatcher.dispatchAction(createdNavigate() as any, {
      data: { route: {}, query: Object.fromEntries(new URL(from, 'http://x').searchParams), response: { data: { id: 749 } } },
      navigate,
    } as any);

    expect(updateQueryParams, '쿼리만 바꾸는 이동은 route.id 를 갱신하지 않아 다음 저장이 POST 로 나간다').not.toHaveBeenCalled();
    expect(navigate).toHaveBeenCalledTimes(1);
    expect(navigate.mock.calls[0][0]).toBe(expected);
  });

  /**
   * @scenario surface=form_save
   * @effects create_goes_to_new_edit
   */
  it('수정 화면 주소에 복제 진입 쿼리(clone_from)를 남기지 않는다', () => {
    const nav = createdNavigate();
    expect(nav.params?.replace).not.toBe(true);
    expect(nav.params?.mergeQuery).toBe(true);
    expect(nav.params?.query?.clone_from).toBe('');
  });
});

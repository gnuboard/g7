/**
 * pageStateSimulation.render.test.tsx — 페이지 상태 시뮬레이션 렌더 통합
 *
 * 페이지 상태 토글의 시뮬레이션 결과가 실제 레이아웃 렌더에 반영되는지 검증한다.
 * PreviewCanvas 의 결선(applyInitialPatch → globalSeed → DynamicRenderer dataContext)
 * 결과와 동형으로, 시뮬레이터가 만든 상태를 createLayoutTest 의 initialState 로 주입해
 * 레이아웃 표현식이 그 값을 읽어 화면에 표시함을 확인한다.
 *
 * 핵심: formErrors 키는 실제 상태 경로(`_local.errors.email`)
 * 이며, 그 경로가 레이아웃의 `{{_local.errors?.email?.[0]}}` / `{{_global.loginError}}`
 * 표현식으로 읽혀 사용자 페이지와 동일하게 오류가 표현된다.
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import { screen } from '@testing-library/react';
import { createLayoutTest } from '../../../__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '../../../ComponentRegistry';
import { applyInitialPatch, getFormErrors } from '../../state/pageStateSimulator';
import type { EditorStateItemSpec } from '../../spec/specTypes';

// 검증 오류 표시 패턴을 모사한 미니 레이아웃 — 실제 번들 폼과 동일하게
// `_local.errors.{field}?.[0]` 배열 접근 / `_global.loginError` + if 조건부 표시.
const FORM_LAYOUT = {
  version: '1.0.0',
  layout_name: 'test/form',
  components: [
    {
      id: 'email-error',
      type: 'basic',
      name: 'Text',
      if: '{{_local.errors?.email}}',
      text: '{{_local.errors?.email?.[0] ?? ""}}',
    },
    {
      id: 'global-error',
      type: 'basic',
      name: 'Text',
      if: '{{_global.loginError}}',
      text: '{{_global.loginError ?? ""}}',
    },
  ],
};

const Text = ({ text, children }: { text?: string; children?: React.ReactNode }) =>
  React.createElement('span', null, children ?? text);
const Fragment = ({ children }: { children?: React.ReactNode }) =>
  React.createElement(React.Fragment, null, children);

function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  // Fragment 포함 — DynamicRenderer 가 렌더 트리 wrapper 로 사용하므로 미등록 시 빈 렌더.
  (registry as unknown as { registry: Record<string, unknown> }).registry = {
    Text: { component: Text, metadata: { name: 'Text', type: 'basic' } },
    Fragment: { component: Fragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

let registry: ComponentRegistry;
beforeEach(() => {
  registry = setupRegistry();
});

describe('페이지 상태 시뮬레이션 렌더 — formErrors 경로 주입 ', () => {
  it('formErrors 미주입(기본 상태) → 오류 미표시', async () => {
    const utils = createLayoutTest(FORM_LAYOUT, { componentRegistry: registry });
    await utils.render();
    expect(screen.queryByText('이메일 오류입니다')).toBeNull();
    expect(screen.queryByText('로그인 실패 메시지')).toBeNull();
    utils.cleanup();
  });

  it('_local.errors.email 경로 주입(검증 실패 상태) → 시뮬레이터 합성 _local 이 표현식으로 오류 표시', async () => {
    const item: EditorStateItemSpec = {
      id: 'validation_failed',
      formErrors: { '_local.errors.email': ['이메일 오류입니다'] },
    };
    // 시뮬레이터가 만든 local 패치 — PreviewCanvas 결선과 동형(applyInitialPatch → globalSeed._local).
    const { local } = applyInitialPatch({ formErrors: getFormErrors(item) });
    expect(local).toEqual({ errors: { email: ['이메일 오류입니다'] } });

    const utils = createLayoutTest(FORM_LAYOUT, {
      componentRegistry: registry,
      initialState: { _local: local },
    });
    await utils.render();
    expect(await screen.findByText('이메일 오류입니다')).toBeTruthy();
    utils.cleanup();
  });

  it('_global.loginError 경로 주입(로그인 실패 상태) → 시뮬레이터 합성 _global 이 표현식으로 오류 표시', async () => {
    const item: EditorStateItemSpec = {
      id: 'login_failed',
      initialState: { global: { loginError: '로그인 실패 메시지' } },
    };
    const { global } = applyInitialPatch({
      globalBaseline: {},
      patch: item.initialState ?? null,
    });
    expect(global).toEqual({ loginError: '로그인 실패 메시지' });

    const utils = createLayoutTest(FORM_LAYOUT, {
      componentRegistry: registry,
      initialState: { _global: global },
    });
    await utils.render();
    expect(await screen.findByText('로그인 실패 메시지')).toBeTruthy();
    utils.cleanup();
  });
});

// profile-edit 의 isPasswordVerified 분기를 모사 — 페이지 상태 initialState.local 이
// `{{!_local?.isPasswordVerified}}` / `{{_local?.isPasswordVerified}}` if 분기를 전환한다.
const STEP_LAYOUT = {
  version: '1.0.0',
  layout_name: 'test/profile-edit',
  components: [
    { id: 'verify', type: 'basic', name: 'Text', if: '{{!_local?.isPasswordVerified}}', text: 'VERIFY_SECTION' },
    { id: 'edit', type: 'basic', name: 'Text', if: '{{_local?.isPasswordVerified}}', text: 'EDIT_FORM' },
  ],
};

describe('페이지 상태 시뮬레이션 렌더 — initialState.local 분기 (profile-edit)', () => {
  it('password_entry(isPasswordVerified=false) → 비밀번호 확인 섹션 표시', async () => {
    const { local } = applyInitialPatch({ patch: { local: { isPasswordVerified: false } } });
    const utils = createLayoutTest(STEP_LAYOUT, { componentRegistry: registry, initialState: { _local: local } });
    await utils.render();
    expect(await screen.findByText('VERIFY_SECTION')).toBeTruthy();
    expect(screen.queryByText('EDIT_FORM')).toBeNull();
    utils.cleanup();
  });

  it('actual_edit(isPasswordVerified=true) → 정보 수정 폼 표시 (비밀번호 섹션 숨김)', async () => {
    const { local } = applyInitialPatch({ patch: { local: { isPasswordVerified: true } } });
    const utils = createLayoutTest(STEP_LAYOUT, { componentRegistry: registry, initialState: { _local: local } });
    await utils.render();
    expect(await screen.findByText('EDIT_FORM')).toBeTruthy();
    expect(screen.queryByText('VERIFY_SECTION')).toBeNull();
    utils.cleanup();
  });
});

// query/route 분기 — 진입 맥락 변종(전수조사 미커버 발굴분).
// admin_user_form 의 route.id 유무 / admin_settings 의 query.tab 분기를 모사.
const CONTEXT_LAYOUT = {
  version: '1.0.0',
  layout_name: 'test/context',
  components: [
    { id: 'edit', type: 'basic', name: 'Text', if: '{{!!route?.id}}', text: 'EDIT_MODE' },
    { id: 'create', type: 'basic', name: 'Text', if: '{{!route?.id}}', text: 'CREATE_MODE' },
    { id: 'seo', type: 'basic', name: 'Text', if: "{{query.tab === 'seo'}}", text: 'SEO_TAB' },
    { id: 'general', type: 'basic', name: 'Text', if: "{{(query.tab ?? 'general') === 'general'}}", text: 'GENERAL_TAB' },
  ],
};

describe('페이지 상태 시뮬레이션 렌더 — query/route 분기 (미커버 발굴분)', () => {
  it('route.id 있음 → 수정 모드(EDIT_MODE) 표시', async () => {
    const { route } = applyInitialPatch({ routeBaseline: { id: '123' }, patch: null });
    const utils = createLayoutTest(CONTEXT_LAYOUT, { componentRegistry: registry, routeParams: route as Record<string, string> });
    await utils.render();
    expect(await screen.findByText('EDIT_MODE')).toBeTruthy();
    expect(screen.queryByText('CREATE_MODE')).toBeNull();
    utils.cleanup();
  });

  it('route.id 패치 null → 신규 작성 모드(CREATE_MODE) 표시 (토큰 제거)', async () => {
    const { route } = applyInitialPatch({ routeBaseline: { id: '123' }, patch: { route: { id: null } } });
    expect(route).toEqual({}); // id 제거됨
    const utils = createLayoutTest(CONTEXT_LAYOUT, { componentRegistry: registry, routeParams: route as Record<string, string> });
    await utils.render();
    expect(await screen.findByText('CREATE_MODE')).toBeTruthy();
    expect(screen.queryByText('EDIT_MODE')).toBeNull();
    utils.cleanup();
  });

  it('query.tab=seo 패치 → SEO 탭(SEO_TAB) 표시', async () => {
    const { query } = applyInitialPatch({ queryBaseline: {}, patch: { query: { tab: 'seo' } } });
    expect(query).toEqual({ tab: 'seo' });
    const utils = createLayoutTest(CONTEXT_LAYOUT, { componentRegistry: registry, queryParams: query as Record<string, string> });
    await utils.render();
    expect(await screen.findByText('SEO_TAB')).toBeTruthy();
    expect(screen.queryByText('GENERAL_TAB')).toBeNull();
    utils.cleanup();
  });
});

/**
 * @file board-notice-option-visibility.test.tsx
 * @description 게시글 작성 폼 — 공지 옵션 체크박스 노출 조건 렌더링 테스트
 *
 * 배경: 공지 지정은 서버가 게시판 관리 권한(can_manage) 또는 공지 등록 권한(can_write_notice)으로
 * 판정한다. 화면이 can_manage 만 보면 공지 등록 권한만 받은 역할(게시판 스텝 등)은 서버가
 * 허용하는데도 옵션을 볼 수 없다. 반대로 권한 없는 회원에게는 옵션이 보이면 안 된다.
 *
 * 검증 방식: 실제 `_post_form.json` 에서 게시글 옵션 영역을 그대로 추출해 렌더링한다 —
 * 조건식을 테스트에 복사하지 않으므로 레이아웃의 조건이 바뀌면 이 테스트가 그것을 본다.
 *
 * @scenario viewer=board_manager
 * @scenario viewer=notice_holder
 * @scenario viewer=no_permission
 * @scenario viewer=reply_writer
 * @effects notice_option_visible_for_manage_or_notice_permission, notice_option_hidden_without_permission, notice_option_hidden_on_reply
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import { createLayoutTest, screen } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

import postForm from '../../layouts/partials/board/form/_post_form.json';

type Node = Record<string, any>;

// ============================================================
// 테스트용 컴포넌트
// ============================================================

const Passthrough =
  (Tag: keyof JSX.IntrinsicElements): React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =>
  ({ className, children, text }) =>
    React.createElement(Tag, { className }, children ?? text);

const TestInput: React.FC<{ type?: string; name?: string; className?: string; value?: string }> = ({ type, name, className }) => (
  <input type={type} name={name} className={className} data-testid={`input-${name}`} readOnly />
);

const TestIcon: React.FC<{ name?: string }> = ({ name }) => <i data-icon={name} />;

/**
 * 옵션 영역이 쓰는 기본 컴포넌트만 등록한다.
 *
 * @returns 테스트 레지스트리
 */
function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const Fragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;
  (registry as any).registry = {
    Fragment: { component: Fragment, metadata: { name: 'Fragment', type: 'layout' } },
    Div: { component: Passthrough('div'), metadata: { name: 'Div', type: 'basic' } },
    Label: { component: Passthrough('label'), metadata: { name: 'Label', type: 'basic' } },
    Span: { component: Passthrough('span'), metadata: { name: 'Span', type: 'basic' } },
    P: { component: Passthrough('p'), metadata: { name: 'P', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
  };
  return registry;
}

// ============================================================
// 실제 레이아웃에서 게시글 옵션 영역 추출
// ============================================================

/**
 * 트리에서 조건을 만족하는 첫 노드를 찾는다.
 *
 * @param node 탐색 시작 노드
 * @param predicate 판정 함수
 * @returns 찾은 노드 (없으면 undefined)
 */
function findNode(node: unknown, predicate: (n: Node) => boolean): Node | undefined {
  if (Array.isArray(node)) {
    for (const child of node) {
      const hit = findNode(child, predicate);
      if (hit) return hit;
    }
    return undefined;
  }
  if (node && typeof node === 'object') {
    const obj = node as Node;
    if (predicate(obj)) return obj;
    for (const value of Object.values(obj)) {
      const hit = findNode(value, predicate);
      if (hit) return hit;
    }
  }
  return undefined;
}

/** 공지·비밀글 카드를 감싸는 게시글 옵션 영역 (공지 입력을 품은 Div 중 조건이 걸린 것) */
const optionsSection = findNode(
  postForm,
  (n) => n.name === 'Div' && typeof n.if === 'string' && n.if.includes('user_abilities') && JSON.stringify(n).includes('"is_notice"'),
);

/**
 * 추출한 옵션 영역만으로 테스트 레이아웃을 만든다.
 *
 * @returns 레이아웃 JSON
 */
function buildLayout() {
  return {
    version: '1.0.0',
    layout_name: 'board_notice_option_visibility_test',
    data_sources: [
      {
        id: 'form_meta',
        type: 'api',
        endpoint: '/api/modules/sirsoft-board/boards/free/posts/form-meta',
        method: 'GET',
        auto_fetch: true,
      },
    ],
    components: [optionsSection],
  };
}

/**
 * 권한 조합으로 옵션 영역을 렌더링한다. 비밀글 카드가 섞이지 않도록 secret_mode 는 disabled 로 둔다.
 *
 * @param abilities user_abilities 값
 * @param local 초기 _local 상태
 * @returns 렌더링을 마친 테스트 유틸
 */
async function renderWith(abilities: Record<string, boolean>, local: Record<string, any> = {}) {
  // 실제 레이아웃에서 옵션 영역을 못 찾으면 "안 보인다" 단언이 거짓 통과하므로 먼저 막는다
  expect(optionsSection).toBeDefined();
  const testUtils = createLayoutTest(buildLayout() as any, { initialState: { _local: local } });
  testUtils.mockApi('form_meta', {
    response: { data: { board: { secret_mode: 'disabled', user_abilities: abilities } } },
  });
  await testUtils.render();
  return testUtils;
}

describe('게시글 작성 폼 — 공지 옵션 노출 조건 (_post_form.json)', () => {
  beforeEach(() => {
    setupTestRegistry();
  });

  // @scenario viewer=board_manager
  // @effects notice_option_visible_for_manage_or_notice_permission
  it('게시판 관리 권한(can_manage) 보유자에게 공지 옵션이 보인다', async () => {
    await renderWith({ can_manage: true, can_write_notice: false });
    expect(screen.getByTestId('input-is_notice')).toBeInTheDocument();
  });

  // @scenario viewer=notice_holder
  // @effects notice_option_visible_for_manage_or_notice_permission
  it('공지 등록 권한(can_write_notice)만 가진 역할에게도 공지 옵션이 보인다', async () => {
    await renderWith({ can_manage: false, can_write_notice: true });
    expect(screen.getByTestId('input-is_notice')).toBeInTheDocument();
  });

  // @scenario viewer=no_permission
  // @effects notice_option_hidden_without_permission
  it('두 권한이 모두 없으면 공지 옵션이 보이지 않는다', async () => {
    await renderWith({ can_manage: false, can_write_notice: false });
    expect(screen.queryByTestId('input-is_notice')).not.toBeInTheDocument();
  });

  // @scenario viewer=reply_writer
  // @effects notice_option_hidden_on_reply
  it('답글 작성(parent_id 있음)에서는 권한이 있어도 공지 옵션이 보이지 않는다', async () => {
    await renderWith({ can_manage: true, can_write_notice: true }, { form: { parent_id: 10 } });
    expect(screen.queryByTestId('input-is_notice')).not.toBeInTheDocument();
  });
});

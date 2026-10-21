/**
 * @file board-secret-thread-password.test.tsx
 * @description 답변 화면 「원글 비밀번호로 열기」 폼 노출 조건과 답변 버튼 잠금 렌더 테스트
 *
 * 배경(제보 — 비회원이 쓴 비밀글에 관리자가 비밀 답변을 단 경우): 답변 주소로 바로 들어온 비회원
 * 질문자는 「로그인 후 확인」 안내만 보고 답변을 열 방법이 없었다. 원글 비밀번호를 알아도 그
 * 비밀번호를 넣을 곳이 원글 화면뿐이었다.
 *
 * 처치: 서버가 상세 응답에 `thread_password_available`(이 답변의 조상에 비밀번호가 있는 비회원 글이
 * 있고 본문이 가려짐) 1비트를 실어 주면, 화면은 기존 잠금 안내(로그인 버튼 포함) 아래에 원글
 * 비밀번호 폼을 함께 보인다. 또 `reply_locked_by_secret_ancestor` 가 참이면 답변 버튼을 감춘다
 * (서버가 답변 폼 404·제출 422 로 막는 경로).
 *
 * 검증 방식: 실제 `show.json` 에서 본문 잠금 블록과 답변 버튼을 그대로 꺼내 렌더한다 — 조건식을
 * 테스트에 복사하지 않으므로 레이아웃 조건이 바뀌면 이 테스트가 그것을 본다.
 */

import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import { createLayoutTest } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

import basicShow from '../../layouts/partials/board/types/basic/show.json';

type Node = Record<string, any>;

const Passthrough =
  (Tag: keyof JSX.IntrinsicElements): React.FC<{ className?: string; id?: string; children?: React.ReactNode; text?: string }> =>
  ({ className, id, children, text }) =>
    React.createElement(Tag, { className, id }, children ?? text);

const TestInput: React.FC<{ type?: string; name?: string; placeholder?: string }> = ({ type, name, placeholder }) => (
  <input type={type} name={name} placeholder={placeholder} readOnly />
);

const TestIcon: React.FC<{ name?: string }> = ({ name }) => <i data-icon={name} />;

/**
 * 잠금 블록·답변 버튼이 쓰는 기본 컴포넌트만 등록한다.
 */
function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const Fragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;
  (registry as any).registry = {
    Fragment: { component: Fragment, metadata: { name: 'Fragment', type: 'layout' } },
    Div: { component: Passthrough('div'), metadata: { name: 'Div', type: 'basic' } },
    P: { component: Passthrough('p'), metadata: { name: 'P', type: 'basic' } },
    Span: { component: Passthrough('span'), metadata: { name: 'Span', type: 'basic' } },
    Button: { component: Passthrough('button'), metadata: { name: 'Button', type: 'basic' } },
    A: { component: Passthrough('a'), metadata: { name: 'A', type: 'basic' } },
    Form: { component: Passthrough('form'), metadata: { name: 'Form', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
  };
  return registry;
}

/**
 * 트리에서 조건을 만족하는 노드를 모두 모은다.
 */
function collectNodes(node: unknown, predicate: (n: Node) => boolean, out: Node[] = []): Node[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collectNodes(child, predicate, out));
    return out;
  }
  if (node && typeof node === 'object') {
    const obj = node as Node;
    if (predicate(obj)) out.push(obj);
    Object.values(obj).forEach((value) => collectNodes(value, predicate, out));
  }
  return out;
}

/** 본문이 가려졌을 때(content === null) 뜨는 잠금 블록들 — 회원 비로그인·권한 없음·비회원 비밀번호·원글 비밀번호 */
const lockBlocks = collectNodes(
  basicShow,
  (n) => n.name === 'Div' && typeof n.if === 'string' && n.if.includes('post?.data?.content === null') && n.if.includes("status !== 'blinded'"),
);

/** 원글 비밀번호 폼 블록 */
const threadBlock = lockBlocks.find((n) => n.if.includes('thread_password_available'));

/** 답변 버튼 — 글쓰기 화면으로 parent_id 를 들고 이동하는 링크(`A`, 새 탭으로도 열린다) */
const replyButton = collectNodes(
  basicShow,
  (n) => n.name === 'A' && typeof n.if === 'string' && n.if.includes('use_reply') && JSON.stringify(n).includes('"parent_id"'),
)[0];

const TRANSLATIONS = {
  common: { login: 'LOGIN', confirm: 'CONFIRM', confirming: 'CONFIRMING' },
  board: {
    secret_post_login_required: 'LOGIN-REQUIRED',
    secret_post_no_permission: 'NO-PERMISSION',
    secret_post_locked: 'GUEST-LOCKED',
    secret_thread_password_hint: 'THREAD-HINT',
    secret_thread_password_placeholder: 'THREAD-PLACEHOLDER',
    form: { password_placeholder: 'PASSWORD' },
    post: { reply: 'REPLY' },
  },
};

const REPLY_POST = {
  id: 20,
  parent_id: 10,
  depth: 1,
  is_secret: true,
  is_guest_post: false,
  is_notice: false,
  status: 'published',
  content: null,
  thread_password_available: true,
  board: { use_reply: true, max_reply_depth: 5 },
  abilities: { can_write: true },
};

let current: ReturnType<typeof createLayoutTest> | null = null;

/**
 * 잠금 블록과 답변 버튼만으로 화면을 만들어 렌더한다.
 *
 * @param post 상세 응답의 data
 * @param currentUser 로그인 회원(없으면 비로그인)
 */
async function renderShow(post: Record<string, any>, currentUser: Record<string, any> | null = null) {
  // 실제 레이아웃에서 블록을 못 찾으면 「안 보인다」 단언이 거짓 통과하므로 먼저 막는다
  expect(lockBlocks.length).toBeGreaterThanOrEqual(3);
  expect(replyButton).toBeDefined();

  current = createLayoutTest(
    {
      version: '1.0.0',
      layout_name: 'board_secret_thread_password_test',
      data_sources: [
        { id: 'post', type: 'api', endpoint: '/api/modules/sirsoft-board/boards/qa/posts/20', method: 'GET', auto_fetch: true },
      ],
      components: [{ id: 'root', type: 'basic', name: 'Div', children: [...lockBlocks, replyButton] }],
    } as any,
    {
      translations: TRANSLATIONS,
      routeParams: { slug: 'qa', id: '20' },
      initialState: { _global: { currentUser } },
      componentRegistry: setupTestRegistry(),
    },
  );
  current.mockApi('post', { response: { data: post } });
  await current.render();
  return current;
}

/**
 * 렌더 결과에서 원글 비밀번호 폼 수를 센다(폼 id 기준).
 */
function threadFormCount(): number {
  return document.querySelectorAll('form#threadPasswordForm').length;
}

/**
 * 렌더 결과에서 주어진 문구를 가진 버튼·링크 수를 센다.
 */
function buttonCount(label: string): number {
  return Array.from(document.querySelectorAll('button, a')).filter((b) => (b.textContent ?? '').includes(label)).length;
}

describe('답변 화면 — 원글 비밀번호 폼 (show.json)', () => {
  afterEach(() => {
    current?.cleanup();
    current = null;
  });

  // @scenario surface=reply_detail
  // @effects thread_password_form_follows_server_flag
  it('레이아웃에 원글 비밀번호 블록이 있다', () => {
    expect(threadBlock, '원글 비밀번호 블록(thread_password_available 조건)이 show.json 에 없습니다.').toBeDefined();
  });

  // @scenario surface=reply_detail
  // @effects thread_password_form_follows_server_flag
  it('비로그인 + 플래그 참: 로그인 버튼과 원글 비밀번호 폼이 함께 보인다', async () => {
    await renderShow(REPLY_POST);

    expect(document.body.textContent).toContain('LOGIN-REQUIRED');
    expect(buttonCount('LOGIN')).toBe(1);
    expect(threadFormCount()).toBe(1);
    expect(document.body.textContent).toContain('THREAD-HINT');
    expect(document.querySelector('input[name="password"][placeholder="THREAD-PLACEHOLDER"]')).not.toBeNull();
  });

  // @scenario surface=reply_detail
  // @effects thread_password_form_follows_server_flag
  it('플래그 거짓: 원글 비밀번호 폼이 없고 로그인 안내만 보인다', async () => {
    await renderShow({ ...REPLY_POST, thread_password_available: false });

    expect(threadFormCount()).toBe(0);
    expect(buttonCount('LOGIN')).toBe(1);
  });

  // @scenario surface=reply_detail
  // @effects thread_password_form_follows_server_flag
  it('로그인했지만 권한 없음 + 플래그 참: 권한 없음 안내와 원글 비밀번호 폼이 함께 보인다', async () => {
    await renderShow(REPLY_POST, { uuid: 'member-1' });

    expect(document.body.textContent).toContain('NO-PERMISSION');
    expect(threadFormCount()).toBe(1);
  });

  // @scenario surface=reply_detail
  // @effects thread_password_form_follows_server_flag
  it('블라인드 글이면 플래그가 참이어도 폼이 없다', async () => {
    await renderShow({ ...REPLY_POST, status: 'blinded' });

    expect(threadFormCount()).toBe(0);
  });

  // @scenario surface=reply_detail
  // @effects thread_password_form_follows_server_flag
  it('본문이 열린 글이면 플래그가 남아 있어도 폼이 없다 (인증 성공 뒤 폼이 사라짐)', async () => {
    await renderShow({ ...REPLY_POST, content: '<p>본문</p>' });

    expect(threadFormCount()).toBe(0);
  });
});

describe('답변 버튼 — 비밀 조상 잠금 (reply_locked_by_secret_ancestor)', () => {
  afterEach(() => {
    current?.cleanup();
    current = null;
  });

  const OPEN_PUBLIC_REPLY = { ...REPLY_POST, is_secret: false, content: '<p>공개 답변</p>', thread_password_available: false };

  // @scenario surface=reply_detail
  // @effects reply_button_hidden_when_locked_by_secret_ancestor
  it('잠금 플래그가 없으면 답변 버튼이 보인다 (무회귀)', async () => {
    await renderShow({ ...OPEN_PUBLIC_REPLY, reply_locked_by_secret_ancestor: false }, { uuid: 'member-1' });

    expect(buttonCount('REPLY')).toBe(1);
  });

  // @scenario surface=reply_detail
  // @effects reply_button_hidden_when_locked_by_secret_ancestor
  it('잠금 플래그가 참이면 답변 버튼을 감춘다 (서버가 답변 폼 404·제출 422 로 막는 경로)', async () => {
    await renderShow({ ...OPEN_PUBLIC_REPLY, reply_locked_by_secret_ancestor: true }, { uuid: 'member-1' });

    expect(buttonCount('REPLY')).toBe(0);
  });
});

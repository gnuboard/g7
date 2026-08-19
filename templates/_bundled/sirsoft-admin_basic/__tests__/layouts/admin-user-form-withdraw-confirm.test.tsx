/**
 * @file admin-user-form-withdraw-confirm.test.tsx
 * @description 회원 수정 폼 — 상태 '탈퇴' 저장 시 확인 절차 (공개이슈 #112)
 *
 * 서버에서 관리자 상태 변경 경로가 정식 탈퇴(비가역 익명화)로 통일되었으므로,
 * 이 폼의 저장이 경고 없이 회원 정보를 파기하지 않아야 한다.
 *
 * 합성 레이아웃이 아니라 **실제 admin_user_form.json 의 노드**를 그대로 렌더한다 —
 * 사본으로 검증하면 배포되는 레이아웃이 바뀌어도 초록으로 남는다.
 *
 * @scenario entry_point=admin_form_ui, target_account=normal, target_account=already_withdrawn, confirm_action=cancel, confirm_action=submit
 * @effects admin_form_shows_confirm_dialog_before_destructive_save, admin_form_cancel_leaves_user_untouched, admin_form_non_withdraw_status_saves_without_dialog, create_mode_hides_withdrawn_option
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import userForm from '../../layouts/admin_user_form.json';

type Json = Record<string, any>;

/** 레이아웃 트리에서 id 로 노드를 찾는다. */
function findById(node: unknown, id: string): Json | null {
  if (Array.isArray(node)) {
    for (const child of node) {
      const found = findById(child, id);
      if (found) return found;
    }
    return null;
  }
  if (node && typeof node === 'object') {
    const obj = node as Json;
    if (obj.id === id) return obj;
    for (const value of Object.values(obj)) {
      const found = findById(value, id);
      if (found) return found;
    }
  }
  return null;
}

const TestDiv: React.FC<any> = ({ className, children }) => (
  <div className={className}>{children}</div>
);
const TestSpan: React.FC<any> = ({ children, text }) => <span>{children ?? text}</span>;
const TestP: React.FC<any> = ({ children, text }) => <p>{children ?? text}</p>;
const TestUl: React.FC<any> = ({ children }) => <ul>{children}</ul>;
const TestLi: React.FC<any> = ({ children, text }) => <li>{children ?? text}</li>;
const TestButton: React.FC<any> = ({ children, onClick, disabled, className }) => (
  <button type="button" onClick={onClick} disabled={disabled} className={className}>
    {children}
  </button>
);
const TestIcon: React.FC<any> = ({ name }) => <i data-icon={name} />;
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;
const TestModal: React.FC<any> = ({ isOpen, title, children }) =>
  isOpen ? (
    <div role="dialog" data-testid="withdraw-confirm-dialog">
      <div data-testid="withdraw-confirm-title">{title}</div>
      {children}
    </div>
  ) : null;

function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Ul: { component: TestUl, metadata: { name: 'Ul', type: 'basic' } },
    Li: { component: TestLi, metadata: { name: 'Li', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Modal: { component: TestModal, metadata: { name: 'Modal', type: 'composite' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

/** 실제 레이아웃의 저장 버튼 + 확인 다이얼로그만 떼어낸 테스트 레이아웃 */
function buildLayout(): Json {
  const saveButton = findById(userForm.slots.content, 'footer_save_button');
  const dialog = findById(userForm.slots.content, 'withdraw_confirm_dialog');

  if (!saveButton || !dialog) {
    throw new Error('저장 버튼 또는 확인 다이얼로그 노드를 찾지 못했습니다.');
  }

  return {
    version: '1.0.0',
    layout_name: 'test_admin_user_form_withdraw',
    named_actions: (userForm as Json).named_actions,
    components: [saveButton, dialog],
  };
}

describe('회원 수정 폼 — 탈퇴 저장 확인 절차', () => {
  let registry: ComponentRegistry;
  let testUtils: ReturnType<typeof createLayoutTest>;

  beforeEach(() => {
    registry = setupRegistry();
  });

  afterEach(() => {
    testUtils?.cleanup();
  });

  /** 테스트 하네스가 설치한 fetch spy 에 기록된 회원 저장 요청 URL 목록 */
  function saveRequests(): string[] {
    const spy = globalThis.fetch as unknown as { mock?: { calls: any[][] } };

    return (spy.mock?.calls ?? [])
      .map((call) => String(call[0]))
      .filter((url) => url.includes('/api/admin/users'));
  }

  /** 지정한 폼 상태로 렌더하고 저장 버튼 클릭까지 수행한다. */
  async function clickSave(formStatus: string, savedStatus: string) {
    testUtils = createLayoutTest(buildLayout() as any, {
      componentRegistry: registry,
      routeParams: { id: '7' },
      translations: {},
      // 저장 버튼은 폼이 시드된 뒤에만 활성이므로 email 까지 채운 상태로 렌더한다
      initialState: { _local: { form: { status: formStatus, email: 'seeded@example.com' } } },
      initialData: { user: { data: { id: 7, status: savedStatus } } },
    });

    await testUtils.render();

    const buttons = screen.getAllByRole('button');
    fireEvent.click(buttons[0]);
  }

  it('상태를 탈퇴로 바꿔 저장하면 확인 다이얼로그가 먼저 열리고 저장 요청은 나가지 않는다', async () => {
    await clickSave('withdrawn', 'active');

    // 존재를 먼저 확정한 뒤에 부재를 단언한다 (부재 단언은 렌더 전에도 통과한다)
    await waitFor(() => {
      expect(screen.getByTestId('withdraw-confirm-dialog')).toBeInTheDocument();
    });

    expect(saveRequests()).toHaveLength(0);
  });

  it('확인 다이얼로그의 처리 버튼을 누르면 그때 저장 요청이 나간다', async () => {
    await clickSave('withdrawn', 'active');

    await waitFor(() => {
      expect(screen.getByTestId('withdraw-confirm-dialog')).toBeInTheDocument();
    });

    const dialog = screen.getByTestId('withdraw-confirm-dialog');
    const dialogButtons = Array.from(dialog.querySelectorAll('button'));

    expect(dialogButtons).toHaveLength(2);

    fireEvent.click(dialogButtons[1]);

    await waitFor(() => {
      expect(saveRequests()).not.toHaveLength(0);
    });
  });

  it('탈퇴 외 상태 저장은 다이얼로그 없이 즉시 요청한다', async () => {
    await clickSave('inactive', 'active');

    await waitFor(() => {
      expect(saveRequests()).not.toHaveLength(0);
    });

    expect(screen.queryByTestId('withdraw-confirm-dialog')).not.toBeInTheDocument();
  });

  it('이미 탈퇴한 회원을 저장할 때는 다이얼로그를 다시 띄우지 않는다', async () => {
    await clickSave('withdrawn', 'withdrawn');

    await waitFor(() => {
      expect(saveRequests()).not.toHaveLength(0);
    });

    expect(screen.queryByTestId('withdraw-confirm-dialog')).not.toBeInTheDocument();
  });

  it('등록 모드에서는 상태 셀렉트에 탈퇴 옵션이 없다', () => {
    const statusSelect = findById(userForm.slots.content, 'field_status');
    const optionsExpr = JSON.stringify(statusSelect);

    // 편집 모드(route.id)에서만 withdrawn 이 포함되는 분기여야 한다
    expect(optionsExpr).toContain('route?.id');
    expect(optionsExpr).toContain('withdrawn');

    const select = JSON.parse(optionsExpr);
    const options = select.children.find((c: Json) => c.name === 'Select')?.props?.options;

    expect(typeof options).toBe('string');

    const [editBranch, createBranch] = String(options).split(' : ');

    expect(editBranch).toContain("value: 'withdrawn'");
    expect(createBranch).not.toContain("value: 'withdrawn'");
  });

  it('저장 버튼과 확인 다이얼로그가 완전히 같은 저장 요청을 보낸다', () => {
    // 저장 시퀀스는 두 곳에 복제되어 있다(다이얼로그를 거치는 경로 / 거치지 않는 경로).
    // 한쪽만 고치면 확인 절차를 거친 저장만 다른 본문을 보내게 되는데, 화면에는
    // 아무 차이도 드러나지 않는다 — 두 요청 정의가 동일함을 구조로 고정한다.
    const saveButton = findById(userForm.slots.content, 'footer_save_button');
    const dialog = findById(userForm.slots.content, 'withdraw_confirm_dialog');

    const collectSaveCalls = (node: unknown): Json[] => {
      const found: Json[] = [];
      const walk = (n: unknown): void => {
        if (Array.isArray(n)) {
          n.forEach(walk);

          return;
        }
        if (n && typeof n === 'object') {
          const obj = n as Json;
          if (obj.handler === 'apiCall' && String(obj.target ?? '').includes('users')) {
            found.push(obj);
          }
          Object.values(obj).forEach(walk);
        }
      };
      walk(node);

      return found;
    };

    const fromButton = collectSaveCalls(saveButton);
    const fromDialog = collectSaveCalls(dialog);

    expect(fromButton.length).toBeGreaterThan(0);
    expect(fromDialog.length).toBeGreaterThan(0);
    expect(fromDialog.length).toBe(fromButton.length);

    expect(JSON.stringify(fromDialog)).toBe(JSON.stringify(fromButton));
  });
});

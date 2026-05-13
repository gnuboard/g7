/**
 * @file admin-user-delete-modal.test.tsx
 * @description 사용자 삭제 모달 레이아웃 테스트
 *
 * 테스트 대상:
 * - templates/.../layouts/admin_user_list.json (삭제 모달 부분)
 *
 * 검증 항목:
 * - 삭제 모달 데이터 전달: setState(_global.modal_data) -> openModal 패턴
 * - apiCall target에 _global.modal_data.id가 포함되는지 확인
 * - 삭제 버튼 스피너/disabled 상태 (규정: modal-usage.md 위험 작업 버튼 로딩 상태)
 * - 취소 버튼도 삭제 중 비활성화
 * - onError 시 isDeleting 복원
 */

import { describe, it, expect } from 'vitest';

// ==============================
// 테스트
// ==============================

describe('사용자 삭제 모달 레이아웃 테스트', () => {
  describe('모달 데이터 전달 패턴', () => {
    it('삭제 모달은 setState(_global.modal_data) -> openModal 패턴을 사용해야 한다', () => {
      // admin_user_list.json의 switch > delete 케이스 구조 검증
      // 이 테스트는 레이아웃 JSON 구조를 직접 검증
      const layout = require('../../layouts/admin_user_list.json');

      // body > named_actions 또는 body 내 switch 구조에서 delete 케이스를 찾기
      const bodyStr = JSON.stringify(layout);

      // setState + target: global + modal_data 패턴이 존재해야 함
      expect(bodyStr).toContain('"handler":"setState"');
      expect(bodyStr).toContain('"target":"global"');
      expect(bodyStr).toContain('"modal_data"');

      // openModal이 onSuccess 내에 있어야 함 (setState 이후)
      expect(bodyStr).toContain('"handler":"openModal"');
      expect(bodyStr).toContain('"target":"delete_confirm_modal"');

      // 잘못된 패턴 (openModal의 params.data로 직접 전달)이 없어야 함
      expect(bodyStr).not.toContain('"handler":"openModal","target":"delete_confirm_modal","params"');
    });

    it('apiCall target에 _global.modal_data.id를 사용해야 한다', () => {
      const layout = require('../../layouts/admin_user_list.json');
      const bodyStr = JSON.stringify(layout);

      // 올바른 패턴: _global.modal_data.uuid
      expect(bodyStr).toContain('/api/admin/users/{{_global.modal_data.uuid}}');

      // 잘못된 패턴: modal_data.uuid (global 접두사 없음)
      expect(bodyStr).not.toMatch(/\/api\/admin\/users\/\{\{modal_data\.uuid\}\}/);
    });
  });

  describe('삭제 버튼 스피너/disabled 상태', () => {
    it('삭제 모달에 isDeleting 로딩 상태가 구현되어 있어야 한다', () => {
      const layout = require('../../layouts/admin_user_list.json');
      const bodyStr = JSON.stringify(layout);

      // _global.isDeleting 상태 사용
      expect(bodyStr).toContain('_global.isDeleting');

      // sequence 핸들러로 setState -> apiCall 순서
      expect(bodyStr).toContain('"handler":"sequence"');
    });

    it('삭제/취소 버튼 모두 isDeleting 시 disabled여야 한다', () => {
      const layout = require('../../layouts/admin_user_list.json');
      const modals = layout.modals;
      const deleteModal = modals.find((m: any) => m.id === 'delete_confirm_modal');
      expect(deleteModal).toBeDefined();

      const footerDiv = deleteModal.children[deleteModal.children.length - 1];
      const buttons = footerDiv.children;

      // 취소 버튼 (첫 번째)
      expect(buttons[0].props.disabled).toBe('{{_global.isDeleting}}');
      // 삭제 버튼 (두 번째)
      expect(buttons[1].props.disabled).toBe('{{_global.isDeleting}}');
    });

    it('삭제 버튼에 스피너 아이콘과 텍스트 삼항연산자가 있어야 한다', () => {
      const layout = require('../../layouts/admin_user_list.json');
      const modals = layout.modals;
      const deleteModal = modals.find((m: any) => m.id === 'delete_confirm_modal');
      const footerDiv = deleteModal.children[deleteModal.children.length - 1];
      const deleteButton = footerDiv.children[1]; // 삭제 버튼

      // 스피너 Icon 존재
      const spinnerIcon = deleteButton.children.find(
        (c: any) => c.name === 'Icon' && c.props?.name === 'spinner'
      );
      expect(spinnerIcon).toBeDefined();
      expect(spinnerIcon.if).toBe('{{_global.isDeleting}}');
      expect(spinnerIcon.props.className).toContain('animate-spin');

      // 텍스트 삼항 연산자
      const textSpan = deleteButton.children.find((c: any) => c.name === 'Span');
      expect(textSpan).toBeDefined();
      expect(textSpan.text).toContain('_global.isDeleting');
      expect(textSpan.text).toContain('$t:common.deleting');
      expect(textSpan.text).toContain('$t:common.delete');
    });

    it('삭제 버튼 className에 flex items-center gap-2가 있어야 한다', () => {
      const layout = require('../../layouts/admin_user_list.json');
      const modals = layout.modals;
      const deleteModal = modals.find((m: any) => m.id === 'delete_confirm_modal');
      const footerDiv = deleteModal.children[deleteModal.children.length - 1];
      const deleteButton = footerDiv.children[1];

      expect(deleteButton.props.className).toContain('flex items-center gap-2');
      expect(deleteButton.props.className).toContain('disabled:opacity-50');
      expect(deleteButton.props.className).toContain('disabled:cursor-not-allowed');
    });
  });

  describe('onSuccess/onError 핸들러 구조', () => {
    it('onSuccess에 closeModal, setState(false), refetchDataSource, toast가 있어야 한다', () => {
      const layout = require('../../layouts/admin_user_list.json');
      const modals = layout.modals;
      const deleteModal = modals.find((m: any) => m.id === 'delete_confirm_modal');
      const footerDiv = deleteModal.children[deleteModal.children.length - 1];
      const deleteButton = footerDiv.children[1];

      // sequence > apiCall 추출
      const sequenceAction = deleteButton.actions[0];
      expect(sequenceAction.handler).toBe('sequence');

      const apiCallAction = sequenceAction.actions.find((a: any) => a.handler === 'apiCall');
      expect(apiCallAction).toBeDefined();

      // onSuccess 핸들러 순서 검증 (setState → refetchDataSource → toast → closeModal)
      const onSuccess = apiCallAction.onSuccess;
      expect(onSuccess).toHaveLength(4);
      expect(onSuccess[0].handler).toBe('setState');
      expect(onSuccess[0].params.isDeleting).toBe(false);
      expect(onSuccess[1].handler).toBe('refetchDataSource');
      expect(onSuccess[1].params.dataSourceId).toBe('users');
      expect(onSuccess[2].handler).toBe('toast');
      expect(onSuccess[3].handler).toBe('closeModal');
    });

    it('onError에 setState(false)와 toast(error)가 있어야 한다', () => {
      const layout = require('../../layouts/admin_user_list.json');
      const modals = layout.modals;
      const deleteModal = modals.find((m: any) => m.id === 'delete_confirm_modal');
      const footerDiv = deleteModal.children[deleteModal.children.length - 1];
      const deleteButton = footerDiv.children[1];

      const sequenceAction = deleteButton.actions[0];
      const apiCallAction = sequenceAction.actions.find((a: any) => a.handler === 'apiCall');

      // onError 핸들러 검증
      const onError = apiCallAction.onError;
      expect(onError).toBeDefined();
      expect(onError).toHaveLength(2);
      expect(onError[0].handler).toBe('setState');
      expect(onError[0].params.isDeleting).toBe(false);
      expect(onError[1].handler).toBe('toast');
      expect(onError[1].params.type).toBe('error');
    });

    it('onError toast 메시지는 {{error.message}}를 사용해야 한다 ($error 아님)', () => {
      const layout = require('../../layouts/admin_user_list.json');
      const modals = layout.modals;
      const deleteModal = modals.find((m: any) => m.id === 'delete_confirm_modal');
      const footerDiv = deleteModal.children[deleteModal.children.length - 1];
      const deleteButton = footerDiv.children[1];

      const sequenceAction = deleteButton.actions[0];
      const apiCallAction = sequenceAction.actions.find((a: any) => a.handler === 'apiCall');

      const onError = apiCallAction.onError;
      const toastAction = onError.find((a: any) => a.handler === 'toast');

      // 올바른 패턴: {{error.message}} ($ 접두사 없음)
      expect(toastAction.params.message).toBe('{{error.message}}');
      // 금지 패턴: {{$error.message}} — ActionDispatcher는 error 키 사용
      expect(toastAction.params.message).not.toContain('$error');
    });
  });

  describe('삭제 버튼 스타일 규정 준수', () => {
    it('취소 버튼에 disabled:opacity-50 disabled:cursor-not-allowed가 있어야 한다', () => {
      const layout = require('../../layouts/admin_user_list.json');
      const modals = layout.modals;
      const deleteModal = modals.find((m: any) => m.id === 'delete_confirm_modal');
      const footerDiv = deleteModal.children[deleteModal.children.length - 1];
      const cancelButton = footerDiv.children[0];

      expect(cancelButton.props.className).toContain('disabled:opacity-50');
      expect(cancelButton.props.className).toContain('disabled:cursor-not-allowed');
    });

    it('삭제 버튼은 bg-red-600 스타일이어야 한다 (위험 작업)', () => {
      const layout = require('../../layouts/admin_user_list.json');
      const modals = layout.modals;
      const deleteModal = modals.find((m: any) => m.id === 'delete_confirm_modal');
      const footerDiv = deleteModal.children[deleteModal.children.length - 1];
      const deleteButton = footerDiv.children[1];

      expect(deleteButton.props.className).toContain('bg-red-600');
    });
  });
});

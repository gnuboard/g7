/**
 * 게시판 유형 관리 모달 레이아웃 JSON 구조 검증 테스트
 *
 * @description
 * - Partial 모달: 게시판 유형 CRUD (목록 테이블 + 추가/수정 폼)
 * - 상태 관리: _global.boardTypesList, btFormMode, btFormSlug, btFormName
 * - API: GET/POST/PUT/DELETE board-types 엔드포인트
 * - 핸들러: sequence, apiCall, setState, refetchDataSource, toast
 *
 * @scenario-manifest tests/scenarios/board-type-manage-modal-site-locale.yaml
 */

import { describe, it, expect } from 'vitest';

import modal from '../../../layouts/admin/partials/admin_board_form/_board_type_manage_modal.json';
import { findByIdPrefix } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

/**
 * JSON 트리에서 특정 ID를 가진 노드를 재귀적으로 찾습니다.
 */
function findById(node: any, id: string): any | null {
  if (!node) return null;
  // 반복(iteration) 안의 노드는 행마다 고유해야 하므로 id 에 index_var 접미가 붙는다
  // (`bt_row` → `bt_row_{{btIdx}}`). 정적 id 는 row 마다 같은 HTML id 를 N 번 출력한다.
  if (node.id === id || node.id === `${id}_{{btIdx}}`) return node;

  if (node.children && Array.isArray(node.children)) {
    for (const child of node.children) {
      const found = findById(child, id);
      if (found) return found;
    }
  }

  if (node.slots) {
    for (const slotChildren of Object.values(node.slots)) {
      if (Array.isArray(slotChildren)) {
        for (const child of slotChildren) {
          const found = findById(child as any, id);
          if (found) return found;
        }
      }
    }
  }

  return null;
}

/**
 * JSON 트리에서 특정 name을 가진 컴포넌트를 모두 찾습니다.
 */
function findByName(node: any, name: string): any[] {
  const results: any[] = [];
  if (!node) return results;

  if (node.name === name) {
    results.push(node);
  }

  if (node.children && Array.isArray(node.children)) {
    for (const child of node.children) {
      results.push(...findByName(child, name));
    }
  }

  if (node.slots) {
    for (const slotChildren of Object.values(node.slots)) {
      if (Array.isArray(slotChildren)) {
        for (const child of slotChildren) {
          results.push(...findByName(child as any, name));
        }
      }
    }
  }

  return results;
}

/**
 * JSON 트리에서 특정 handler를 가진 액션을 모두 재귀적으로 찾습니다.
 */
function findActions(node: any, handlerName: string): any[] {
  const results: any[] = [];
  if (!node) return results;

  if (node.actions && Array.isArray(node.actions)) {
    for (const action of node.actions) {
      if (action.handler === handlerName) {
        results.push(action);
      }
      if (action.actions && Array.isArray(action.actions)) {
        for (const subAction of action.actions) {
          if (subAction.handler === handlerName) {
            results.push(subAction);
          }
          if (subAction.onSuccess && Array.isArray(subAction.onSuccess)) {
            for (const cb of subAction.onSuccess) {
              if (cb.handler === handlerName) results.push(cb);
              if (cb.onSuccess && Array.isArray(cb.onSuccess)) {
                for (const nested of cb.onSuccess) {
                  if (nested.handler === handlerName) results.push(nested);
                }
              }
            }
          }
          if (subAction.onError && Array.isArray(subAction.onError)) {
            for (const cb of subAction.onError) {
              if (cb.handler === handlerName) results.push(cb);
            }
          }
        }
      }
    }
  }

  if (node.children && Array.isArray(node.children)) {
    for (const child of node.children) {
      results.push(...findActions(child, handlerName));
    }
  }

  return results;
}

describe('게시판 유형 관리 모달 레이아웃', () => {

  describe('메타 정보', () => {
    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('Partial 메타 정보가 올바르다', () => {
      expect(modal.meta).toBeDefined();
      expect(modal.meta.is_partial).toBe(true);
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('모달 기본 속성이 올바르다', () => {
      expect(modal.id).toBe('board_type_manage_modal');
      expect(modal.type).toBe('composite');
      expect(modal.name).toBe('Modal');
      expect(modal.props.width).toBe('640px');
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('모달 제목이 다국어 키로 설정되어 있다', () => {
      expect(modal.props.title).toBe('$t:sirsoft-board.admin.board_types.modal_title');
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('슬러그 사용법 안내 텍스트가 모달 상단에 있다', () => {
      const guide = findById(modal, 'bt_usage_guide');
      expect(guide).not.toBeNull();
      expect(guide.name).toBe('Div');
      // 아이콘 + 텍스트 children 구조
      const icon = findById(guide, 'bt_usage_guide_icon');
      expect(icon).not.toBeNull();
      expect(icon.name).toBe('Icon');
      expect(icon.props.name).toBe('fa-solid fa-circle-info');
      const text = findById(guide, 'bt_usage_guide_text');
      expect(text).not.toBeNull();
      expect(text.text).toBe('$t:sirsoft-board.admin.board_types.usage_guide');
    });
  });

  describe('테이블 구조', () => {
    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('테이블 헤더에 3개 컬럼이 있다 (slug, name, actions)', () => {
      const theadRow = findById(modal, 'bt_thead_row');
      expect(theadRow).not.toBeNull();
      expect(theadRow.children).toHaveLength(3);
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('각 헤더 컬럼이 올바른 다국어 키를 사용한다', () => {
      const expectedColumns = [
        { id: 'bt_th_slug', text: '$t:sirsoft-board.admin.board_types.slug' },
        { id: 'bt_th_name', text: '$t:sirsoft-board.admin.board_types.name' },
        { id: 'bt_th_actions', text: '$t:sirsoft-board.admin.board_types.actions' },
      ];

      for (const col of expectedColumns) {
        const th = findById(modal, col.id);
        expect(th).not.toBeNull();
        expect(th.text).toBe(col.text);
      }
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('빈 상태 행이 존재하고 조건부 렌더링된다', () => {
      const emptyRow = findById(modal, 'bt_empty_row');
      expect(emptyRow).not.toBeNull();
      expect(emptyRow.if).toBe('{{!_global.boardTypesList || _global.boardTypesList.length === 0}}');

      const emptyCell = findById(modal, 'bt_empty_cell');
      expect(emptyCell.props.colSpan).toBe(3);
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('데이터 행에 iteration이 올바르게 설정되어 있다', () => {
      const dataRow = findByIdPrefix(modal, 'bt_row');
      expect(dataRow).not.toBeNull();
      expect(dataRow.if).toBe('{{_global.boardTypesList && _global.boardTypesList.length > 0}}');
      expect(dataRow.iteration).toEqual({
        source: '{{_global.boardTypesList ?? []}}',
        item_var: 'btItem',
        index_var: 'btIdx',
      });
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('데이터 행에 3개 셀이 있다 (slug, name, actions)', () => {
      const dataRow = findByIdPrefix(modal, 'bt_row');
      expect(dataRow.children).toHaveLength(3);
    });
  });

  describe('테이블 행 데이터 바인딩', () => {
    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('slug 셀이 btItem.slug을 표시한다', () => {
      const slugCell = findByIdPrefix(modal, 'bt_td_slug');
      expect(slugCell.text).toBe('{{btItem.slug}}');
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('name 셀이 다국어 fallback 체인으로 표시한다', () => {
      const nameCell = findByIdPrefix(modal, 'bt_td_name');
      expect(nameCell.text).toContain('btItem.name');

      // 엔진은 현재 로케일을 전역 변수 `$locale` 로만 노출한다. `_global.locale` 은 심는 곳이
      // 없어 항상 undefined 이고, 그러면 뒤의 `?? 'ko'` 가 걸려 **모든 언어에서 한국어**가
      // 나간다. 같은 폴더의 `board-locale-fallback.test.ts` 가 이미 요구하던 형태다.
      expect(nameCell.text).toContain('$locale');
      expect(nameCell.text).not.toContain('_global.locale');
    });
  });

  describe('액션 버튼', () => {
    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('수정 버튼이 전역 상태를 폼에 로드한다', () => {
      const editBtn = findByIdPrefix(modal, 'bt_edit_btn');
      expect(editBtn).not.toBeNull();

      const editAction = editBtn.actions[0];
      expect(editAction.handler).toBe('setState');
      expect(editAction.params.target).toBe('global');
      expect(editAction.params.btFormMode).toBe('edit');
      expect(editAction.params.btFormSlug).toBe('{{btItem.slug}}');
      // btFormName은 객체로 설정
      expect(editAction.params.btFormName).toContain('btItem.name');
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('삭제 버튼이 sequence로 API 호출 후 목록 갱신한다', () => {
      const deleteBtn = findByIdPrefix(modal, 'bt_delete_btn');
      expect(deleteBtn).not.toBeNull();

      const clickAction = deleteBtn.actions[0];
      expect(clickAction.handler).toBe('sequence');
      expect(clickAction.actions).toHaveLength(2);

      expect(clickAction.actions[0].handler).toBe('setState');
      expect(clickAction.actions[0].params.btDeletingId).toBe('{{btItem.id}}');

      const apiCall = clickAction.actions[1];
      expect(apiCall.handler).toBe('apiCall');
      expect(apiCall.params.method).toBe('DELETE');
      expect(apiCall.target).toContain('/api/modules/sirsoft-board/admin/board-types/');
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('삭제는 진입점 액션에 confirm 이 걸려 실제로 확인창을 띄운다', () => {
      const deleteBtn = findByIdPrefix(modal, 'bt_delete_btn');
      const entry = deleteBtn.actions[0];
      const apiCall = entry.actions[1];

      // `confirm` 은 진입점 액션에서만 읽힌다 — `handleSequence` 는 `executeAction` 을 부르는데
      // 그 경로에 검사가 없어, 중첩된 apiCall 에 달면 **확인창 없이 즉시 삭제**된다(S16 F18).
      // 예전 단언이 중첩 위치를 보고 있어서 이 결함이 green 인 채로 출하됐다.
      expect(entry.confirm).toBe('$t:sirsoft-board.admin.board_types.delete_confirm');
      expect(apiCall.confirm).toBeUndefined();
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('삭제 아이콘과 스피너가 조건부 렌더링된다', () => {
      const deleteIcon = findByIdPrefix(modal, 'bt_delete_icon');
      const deleteSpinner = findByIdPrefix(modal, 'bt_delete_spinner');

      expect(deleteIcon.if).toBe('{{_global.btDeletingId !== btItem.id}}');
      expect(deleteSpinner.if).toBe('{{_global.btDeletingId === btItem.id}}');
    });
  });

  describe('폼 섹션', () => {
    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('폼에 2개 필드가 있다 (slug, name)', () => {
      const formFields = findById(modal, 'bt_form_fields');
      expect(formFields).not.toBeNull();
      expect(formFields.children).toHaveLength(2);
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('slug Input이 올바른 전역 상태 바인딩을 사용한다', () => {
      const slugInput = findById(modal, 'bt_input_slug');
      expect(slugInput).not.toBeNull();
      expect(slugInput.props.value).toBe("{{_global.btFormSlug ?? ''}}");
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('name 필드가 MultilingualInput 컴포넌트를 사용한다', () => {
      const nameInput = findById(modal, 'bt_input_name');
      expect(nameInput).not.toBeNull();
      expect(nameInput.name).toBe('MultilingualInput');
      expect(nameInput.type).toBe('composite');
      expect(nameInput.props.layout).toBe('tabs');
      expect(nameInput.props.required).toBe(true);
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('MultilingualInput이 btFormName 상태에 바인딩된다', () => {
      const nameInput = findById(modal, 'bt_input_name');
      expect(nameInput.props.value).toContain('_global.btFormName');

      const onChangeAction = nameInput.actions[0];
      expect(onChangeAction.event).toBe('onChange');
      expect(onChangeAction.handler).toBe('setState');
      expect(onChangeAction.params.target).toBe('global');
      expect(onChangeAction.params.btFormName).toContain('$args[0]?.target?.value');
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('slug Input의 setState 핸들러가 btFormSlug를 설정한다', () => {
      const slugInput = findById(modal, 'bt_input_slug');
      const setStateAction = slugInput.actions[0];
      expect(setStateAction.handler).toBe('setState');
      expect(setStateAction.params.target).toBe('global');
      expect(setStateAction.params.btFormSlug).toBe('{{$event.target.value}}');
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('slug 필드가 수정 모드에서 비활성화된다', () => {
      const slugInput = findById(modal, 'bt_input_slug');
      // 유형 쓰기 API 는 게시판 등록 권한이라 부모 화면의 canManageBoardTypes 로 잠근다(7.1.0 abilities 게이트 board-admin-a #4)
      expect(slugInput.props.disabled).toBe("{{$parent._computed.canManageBoardTypes !== true || _global.btFormMode === 'edit'}}");
    });
  });

  describe('폼 헤더 및 모드 전환', () => {
    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('폼 제목이 add/edit 모드에 따라 변경된다', () => {
      const title = findById(modal, 'bt_form_title');
      expect(title.text).toContain('btFormMode');
      expect(title.text).toContain('edit_title');
      expect(title.text).toContain('add_title');
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('취소 버튼이 수정 모드에서만 표시된다', () => {
      const cancelBtn = findById(modal, 'bt_form_cancel_btn');
      expect(cancelBtn.if).toBe("{{_global.btFormMode === 'edit'}}");
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('취소 버튼에 다크모드 포함 스타일이 적용된다', () => {
      const cancelBtn = findById(modal, 'bt_form_cancel_btn');
      expect(cancelBtn.props.className).toContain('text-gray-600');
      expect(cancelBtn.props.className).toContain('dark:text-gray-300');
      expect(cancelBtn.props.className).toContain('dark:bg-gray-700');
      expect(cancelBtn.props.className).toContain('text-xs');
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('취소 버튼 클릭 시 폼을 초기화한다', () => {
      const cancelBtn = findById(modal, 'bt_form_cancel_btn');
      const action = cancelBtn.actions[0];
      expect(action.handler).toBe('setState');
      expect(action.params.btFormMode).toBe('add');
      expect(action.params.btEditingItem).toBeNull();
      expect(action.params.btFormSlug).toBe('');
      expect(action.params.btFormName).toEqual({ ko: '', en: '' });
    });
  });

  describe('제출 버튼', () => {
    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('제출 버튼이 필수 필드 검증으로 비활성화 조건을 가진다', () => {
      const submitBtn = findById(modal, 'bt_submit_btn');
      expect(submitBtn.props.disabled).toContain('_global.btSaving');
      expect(submitBtn.props.disabled).toContain('_global.btFormSlug');
      expect(submitBtn.props.disabled).toContain('_global.btFormName');
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('제출 시 모드에 따라 POST 또는 PUT을 호출한다', () => {
      const submitBtn = findById(modal, 'bt_submit_btn');
      const clickAction = submitBtn.actions[0];
      expect(clickAction.handler).toBe('sequence');

      const apiCall = clickAction.actions[1];
      expect(apiCall.handler).toBe('apiCall');
      expect(apiCall.params.method).toContain('btFormMode');
      expect(apiCall.params.method).toContain('PUT');
      expect(apiCall.params.method).toContain('POST');
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('제출 요청 body에 slug과 name 필드가 포함된다', () => {
      const submitBtn = findById(modal, 'bt_submit_btn');
      const apiCall = submitBtn.actions[0].actions[1];
      const body = apiCall.params.body;

      expect(body.slug).toBe('{{_global.btFormSlug}}');
      expect(body.name).toContain('_global.btFormName');
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('제출 성공 시 폼을 초기화하고 목록을 갱신한다', () => {
      const submitBtn = findById(modal, 'bt_submit_btn');
      const apiCall = submitBtn.actions[0].actions[1];
      const onSuccess = apiCall.onSuccess;

      const resetAction = onSuccess[0];
      expect(resetAction.handler).toBe('setState');
      expect(resetAction.params.btSaving).toBe(false);
      expect(resetAction.params.btFormMode).toBe('add');
      expect(resetAction.params.btFormSlug).toBe('');
      expect(resetAction.params.btFormName).toEqual({ ko: '', en: '' });

      const refetchCall = onSuccess[1];
      expect(refetchCall.handler).toBe('apiCall');
      expect(refetchCall.target).toBe('/api/modules/sirsoft-board/admin/board-types');
      expect(refetchCall.params.method).toBe('GET');
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('제출 스피너가 저장 중에만 표시된다', () => {
      const spinner = findById(modal, 'bt_submit_spinner');
      expect(spinner.if).toBe('{{_global.btSaving}}');
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('제출 버튼 텍스트가 모드와 저장 상태에 따라 변경된다', () => {
      const text = findById(modal, 'bt_submit_text');
      expect(text.text).toContain('btFormMode');
      expect(text.text).toContain('btSaving');
    });
  });

  describe('API 엔드포인트', () => {
    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('모든 API 호출이 올바른 base URL을 사용한다', () => {
      const allApiCalls = findActions(modal, 'apiCall');
      const basePath = '/api/modules/sirsoft-board/admin/board-types';

      for (const apiCall of allApiCalls) {
        expect(apiCall.target).toContain(basePath);
      }
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('모든 API 호출에 auth_required가 설정되어 있다', () => {
      const allApiCalls = findActions(modal, 'apiCall');
      for (const apiCall of allApiCalls) {
        expect(apiCall.auth_required).toBe(true);
      }
    });
  });

  describe('데이터소스 갱신', () => {
    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('성공 콜백에서 boards와 board_types 데이터소스를 갱신한다', () => {
      const refetchActions = findActions(modal, 'refetchDataSource');
      const dataSourceIds = refetchActions.map((a: any) => a.params.dataSourceId);

      expect(dataSourceIds).toContain('boards');
      expect(dataSourceIds).toContain('board_types');
    });
  });

  describe('에러 처리', () => {
    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('제출 API 호출의 onError에서 btSaving을 false로 설정한다', () => {
      const submitBtn = findById(modal, 'bt_submit_btn');
      const seq = submitBtn.actions[0];
      const apiCall = seq.actions.find((a: any) => a.handler === 'apiCall');
      expect(apiCall).not.toBeNull();
      expect(apiCall.onError).toBeDefined();

      const resetState = apiCall.onError.find((a: any) => a.handler === 'setState');
      expect(resetState.params.btSaving).toBe(false);
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('삭제 API 호출의 onError에서 btDeletingId를 null로 설정한다', () => {
      const deleteBtn = findByIdPrefix(modal, 'bt_delete_btn');
      const seq = deleteBtn.actions[0];
      const apiCall = seq.actions.find((a: any) => a.handler === 'apiCall');
      expect(apiCall).not.toBeNull();
      expect(apiCall.onError).toBeDefined();

      const resetState = apiCall.onError.find((a: any) => a.handler === 'setState');
      expect(resetState.params.btDeletingId).toBeNull();
      // 삭제는 btSaving을 사용하지 않음 (저장 버튼 로딩과 분리)
      expect(resetState.params.btSaving).toBeUndefined();
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('에러 시 toast로 오류 메시지를 표시한다', () => {
      const submitBtn = findById(modal, 'bt_submit_btn');
      const apiCall = submitBtn.actions[0].actions[1];
      const errorToast = apiCall.onError.find((a: any) => a.handler === 'toast');

      expect(errorToast.params.type).toBe('error');
      expect(errorToast.params.message).toBe('{{error.errors ? Object.values(error.errors).flat()[0] : error.message}}');
    });
  });

  describe('모달 닫기', () => {
    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('닫기 버튼이 존재하고 sequence로 폼 초기화 후 모달을 닫는다', () => {
      const closeBtn = findById(modal, 'bt_modal_close_btn');
      expect(closeBtn).not.toBeNull();

      const seq = closeBtn.actions[0];
      expect(seq.handler).toBe('sequence');
      expect(seq.actions).toHaveLength(2);

      expect(seq.actions[0].handler).toBe('setState');
      expect(seq.actions[0].params.btFormMode).toBe('add');
      expect(seq.actions[0].params.btFormName).toEqual({ ko: '', en: '' });

      expect(seq.actions[1].handler).toBe('closeModal');
    });
  });

  describe('다크 모드', () => {
    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('테이블 본문에 다크 모드 클래스가 있다', () => {
      const tbody = findById(modal, 'bt_tbody');
      expect(tbody.props.className).toContain('dark:bg-gray-800');
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('헤더 셀에 다크 모드 텍스트 색상이 있다 (#408 — .text-table-header 시맨틱이 흡수)', () => {
      const thSlug = findById(modal, 'bt_th_slug');
      // text-xs + text-gray-500 + dark:text-gray-400 + font-medium + uppercase + tracking-wider 토큰을 .text-table-header 자산이 흡수
      expect(thSlug.props.className).toContain('text-table-header');
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('데이터 행에 다크 모드 호버 효과가 있다', () => {
      const row = findByIdPrefix(modal, 'bt_row');
      expect(row.props.className).toContain('dark:hover:bg-gray-700');
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('폼 구분선에 다크 모드 색상이 있다', () => {
      const formSection = findById(modal, 'bt_form_section');
      expect(formSection.props.className).toContain('dark:border-gray-700');
    });
  });

  describe('모달 UI/UX 개선', () => {
    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('수정 중인 행에 classMap으로 하이라이트 배경이 적용된다', () => {
      const row = findByIdPrefix(modal, 'bt_row');
      expect(row.classMap).toBeDefined();
      expect(row.classMap.key).toContain('btEditingItem');
      expect(row.classMap.variants.true).toContain('bg-blue-50');
      expect(row.classMap.variants.true).toContain('dark:bg-blue-900');
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('수정 모드 진입 시 폼 영역에 ring 효과가 적용된다', () => {
      const formSection = findById(modal, 'bt_form_section');
      expect(formSection.classMap).toBeDefined();
      expect(formSection.classMap.key).toBe('{{_global.btFormMode}}');
      expect(formSection.classMap.variants.edit).toContain('ring-2');
      expect(formSection.classMap.variants.edit).toContain('ring-blue-300');
      expect(formSection.classMap.variants.add).toBe('');
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('slug 필드에 허용 형식 힌트가 있다 (에러 없을 때만 표시)', () => {
      const hint = findById(modal, 'bt_slug_hint');
      expect(hint).not.toBeNull();
      expect(hint.name).toBe('P');
      expect(hint.text).toBe('$t:sirsoft-board.admin.board_types.slug_hint');
      expect(hint.if).toContain("btFormMode !== 'edit'");
      expect(hint.if).toContain('btFormErrors');
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('Modal에 width prop이 설정되어 있다', () => {
      expect(modal.props.width).toBe('640px');
    });
  });

  describe('폼 유효성 에러 표시', () => {
    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('slug Input에 에러 시 input-error 시맨틱이 분기 적용된다', () => {
      const slugInput = findById(modal, 'bt_input_slug');
      expect(slugInput.classMap).toBeDefined();
      expect(slugInput.classMap.key).toContain('btFormErrors?.slug');
      // Input 외형은 시맨틱 .input 이 디폴트로 제공, 에러는 input-error 토글로 통일 (#369)
      expect(slugInput.classMap.variants.error).toBe('input-error');
      expect(slugInput.classMap.variants.normal).toBe('');
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('slug 에러 메시지 요소가 존재하고 조건부 렌더링된다 (#408 — .form-error-xs 흡수)', () => {
      const slugError = findById(modal, 'bt_slug_error');
      expect(slugError).not.toBeNull();
      expect(slugError.name).toBe('P');
      expect(slugError.if).toContain('btFormErrors?.slug');
      expect(slugError.text).toContain('btFormErrors?.slug?.[0]');
      // text-red-500 + dark:text-red-400 + text-xs 토큰을 .form-error-xs 자산이 흡수
      expect(slugError.props.className).toContain('form-error-xs');
    });

    /**
     * @scenario case=server_error
     *
     * @effects server_name_error_shown_under_name_field_once
     */
    it('MultilingualInput에 error prop이 전달된다 (사이트 기본 언어 키 → 필드 전체 키 순)', () => {
      // 이름 오류 문구는 MultilingualInput 이 칸 아래에 직접 그린다(별도 문단 bt_name_error 는 두지 않는다 — O-E-2)
      const nameInput = findById(modal, 'bt_input_name');
      expect(nameInput.props.error).toBeDefined();
      expect(nameInput.props.error).toContain("btFormErrors?.['name.' + (_global.appConfig?.siteLocale ?? $locale ?? 'ko')]");
      expect(nameInput.props.error).toContain("btFormErrors?.['name']");
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('slug Input 변경 시 slug 에러를 클리어한다', () => {
      const slugInput = findById(modal, 'bt_input_slug');
      const inputAction = slugInput.actions[0];
      expect(inputAction.params.btFormErrors).toBeDefined();
      expect(inputAction.params.btFormErrors).toContain('delete e.slug');
    });

    /**
     * @scenario case=error_clear
     *
     * @effects name_input_clears_all_name_error_keys
     */
    it('name Input 변경 시 name 에러를 클리어한다', () => {
      const nameInput = findById(modal, 'bt_input_name');
      const onChangeAction = nameInput.actions[0];
      expect(onChangeAction.params.btFormErrors).toBeDefined();
      expect(onChangeAction.params.btFormErrors).toContain("k.startsWith('name.')");
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('제출 시 에러 상태를 초기화한다', () => {
      const submitBtn = findById(modal, 'bt_submit_btn');
      const seq = submitBtn.actions[0];
      const initState = seq.actions[0];
      expect(initState.params.btFormErrors).toBeNull();
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('제출 onError에서 btFormErrors에 에러를 저장한다', () => {
      const submitBtn = findById(modal, 'bt_submit_btn');
      const apiCall = submitBtn.actions[0].actions[1];
      const errorState = apiCall.onError.find((a: any) => a.handler === 'setState');
      expect(errorState.params.btFormErrors).toBe('{{error.errors ?? null}}');
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('취소 버튼 클릭 시 에러를 초기화한다', () => {
      const cancelBtn = findById(modal, 'bt_form_cancel_btn');
      const action = cancelBtn.actions[0];
      expect(action.params.btFormErrors).toBeNull();
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('모달 닫기 시 에러를 초기화한다', () => {
      const closeBtn = findById(modal, 'bt_modal_close_btn');
      const seq = closeBtn.actions[0];
      const resetAction = seq.actions[0];
      expect(resetAction.params.btFormErrors).toBeNull();
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('수정 버튼 클릭 시 에러를 초기화한다', () => {
      const editBtn = findByIdPrefix(modal, 'bt_edit_btn');
      const action = editBtn.actions[0];
      expect(action.params.btFormErrors).toBeNull();
    });

    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('제출 성공 시 에러를 초기화한다', () => {
      const submitBtn = findById(modal, 'bt_submit_btn');
      const apiCall = submitBtn.actions[0].actions[1];
      const successState = apiCall.onSuccess[0];
      expect(successState.params.btFormErrors).toBeNull();
    });
  });

  describe('Button type 속성', () => {
    /**
     * @scenario case=layout_contract
     *
     * @effects board_type_manage_modal_layout_contract
     */
    it('모든 Button에 type="button"이 명시되어 있다 (submit 방지)', () => {
      const buttons = findByName(modal, 'Button');
      for (const btn of buttons) {
        expect(btn.props.type).toBe('button');
      }
    });
  });

  // O-E-2 — 이름 필수 언어는 서버 규칙(LocaleRequiredTranslatable)과 같은 「사이트 기본 언어」다(2026-10-09 제품 결정).
  // 화면 언어와 무관하게 사이트 기본 언어 칸이 비면 잠그고, 서버가 `name`(필드 전체) 키나 사이트 기본 언어 키로
  // 돌려준 오류를 칸에 붙인다.
  describe('이름 필수 언어와 오류 키 (사이트 기본 언어 기준)', () => {
    /** 레이아웃 바인딩 `{{…}}` 한 개를 주어진 문맥으로 평가한다(엔진과 같은 식 의미). */
    const evalBinding = (binding: string, ctx: Record<string, unknown>): unknown => {
      const m = binding.match(/^\{\{([\s\S]*)\}\}$/);
      expect(m).not.toBeNull();
      const names = Object.keys(ctx);
      // eslint-disable-next-line no-new-func
      return new Function(...names, `return (${m![1]});`)(...names.map((n) => ctx[n]));
    };
    const ctxFor = ($locale: string, g: Record<string, unknown>, siteLocale = 'ko') => ({
      $locale,
      $parent: { _computed: { canManageBoardTypes: true } },
      _global: { btSaving: false, btFormSlug: 'notice', appConfig: { siteLocale }, ...g },
    });

    /**
     * @scenario case=submit_gate
     *
     * @effects submit_enabled_when_site_locale_name_filled
     */
    it.each(['ko', 'en', 'ja'])('%s 화면은 사이트 기본 언어(ko) 칸만 채워도 추가 단추가 열린다', (loc) => {
      const submitBtn = findById(modal, 'bt_submit_btn');
      const disabled = evalBinding(submitBtn.props.disabled, ctxFor(loc, { btFormName: { ko: '공지', en: '', ja: '' } }));
      expect(disabled).toBe(false);
    });

    /**
     * @scenario case=submit_gate
     *
     * @effects submit_locked_when_site_locale_name_empty
     */
    it.each(['ko', 'en', 'ja'])('%s 화면은 다른 언어 칸이 차 있어도 사이트 기본 언어(ko) 칸이 비면 추가 단추가 잠긴다', (loc) => {
      const submitBtn = findById(modal, 'bt_submit_btn');
      const disabled = evalBinding(submitBtn.props.disabled, ctxFor(loc, { btFormName: { ko: '', en: 'e', ja: 'j' } }));
      expect(disabled).toBe(true);
    });

    /**
     * @scenario case=submit_gate
     *
     * @effects submit_locked_when_site_locale_name_empty
     */
    it('사이트 기본 언어가 en 이면 한국어 화면에서도 영어 칸으로 잠금을 판단한다', () => {
      const submitBtn = findById(modal, 'bt_submit_btn');
      expect(evalBinding(submitBtn.props.disabled, ctxFor('ko', { btFormName: { ko: '공지', en: '' } }, 'en'))).toBe(true);
      expect(evalBinding(submitBtn.props.disabled, ctxFor('ko', { btFormName: { ko: '', en: 'Notice' } }, 'en'))).toBe(false);
    });

    /**
     * @scenario case=server_error
     *
     * @effects server_name_error_shown_under_name_field_once
     */
    it.each([
      ['en', 'ko', { name: ['The board type name (Korean) field is required.'] }, 'The board type name (Korean) field is required.'],
      ['en', 'ko', { 'name.ko': ['ko only'] }, 'ko only'],
      ['ja', 'ko', { 'name.ko': ['ko only'] }, 'ko only'],
      ['ko', 'en', { 'name.en': ['en only'] }, 'en only'],
    ])('%s 화면(사이트 기본 %s)의 서버 오류 %j 가 이름 칸 오류로 붙는다', (loc, site, errors, expected) => {
      const nameInput = findById(modal, 'bt_input_name');
      expect(evalBinding(nameInput.props.error, ctxFor(loc as string, { btFormErrors: errors }, site as string))).toBe(expected);
    });

    /**
     * @scenario case=server_error
     *
     * @effects server_name_error_shown_under_name_field_once
     */
    it('이름 오류를 칸(MultilingualInput error)과 별도 문단에 두 번 그리지 않는다', () => {
      // MultilingualInput 이 error 문구를 칸 아래에 직접 그린다 — 같은 문구를 담는 별도 문단이 있으면 두 줄로 겹친다
      expect(findById(modal, 'bt_name_error')).toBeNull();
    });

    /**
     * @scenario case=error_clear
     *
     * @effects name_input_clears_all_name_error_keys
     */
    it('이름 입력은 모든 언어의 이름 오류 키와 필드 전체 키를 지운다', () => {
      const nameInput = findById(modal, 'bt_input_name');
      const onChange = nameInput.actions[0];
      const left = evalBinding(onChange.params.btFormErrors, {
        $args: [{ target: { value: { ko: '', en: 'x' } } }],
        _global: { btFormErrors: { name: ['a'], 'name.en': ['b'], 'name.ja': ['c'], slug: ['d'] } },
      });
      expect(left).toEqual({ slug: ['d'] });
    });
  });
});

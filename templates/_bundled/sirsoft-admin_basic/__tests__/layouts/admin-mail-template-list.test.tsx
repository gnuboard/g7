/**
 * @file admin-mail-template-list.test.tsx
 * @description 환경설정 메일 템플릿 탭 레이아웃 테스트
 *
 * 테스트 대상:
 * - templates/.../partials/admin_settings/_tab_mail_templates.json
 * - templates/.../partials/admin_settings/_modal_mail_template_form.json
 *
 * 검증 항목:
 * - 검색 UI 렌더링 (검색 타입 Select, 검색 Input, 검색/초기화 버튼)
 * - 페이지네이션 표시 조건
 * - 편집 전용 모달 구조
 * - 빈 목록 메시지 표시
 * - 데이터 경로 (mailTemplates?.data?.data)
 */

import * as fs from 'fs';
import * as path from 'path';
import { describe, it, expect } from 'vitest';

// ==============================
// 실제 레이아웃 JSON 로드
// ==============================

const tabLayoutPath = path.resolve(
  __dirname,
  '../../layouts/partials/admin_settings/_tab_mail_templates.json'
);
const tabLayout = JSON.parse(fs.readFileSync(tabLayoutPath, 'utf-8'));

const formModalPath = path.resolve(
  __dirname,
  '../../layouts/partials/admin_settings/_modal_mail_template_form.json'
);
const formModalLayout = JSON.parse(fs.readFileSync(formModalPath, 'utf-8'));

// ==============================
// 헬퍼 함수: 컴포넌트 탐색
// ==============================

function findById(node: any, id: string): any {
  if (node.id === id) return node;
  const children = node.children ?? [];
  for (const child of children) {
    const found = findById(child, id);
    if (found) return found;
  }
  return null;
}

function findAllByName(node: any, name: string): any[] {
  const results: any[] = [];
  if (node.name === name) results.push(node);
  const children = node.children ?? [];
  for (const child of children) {
    results.push(...findAllByName(child, name));
  }
  return results;
}

// ==============================
// 테스트
// ==============================

describe('환경설정 메일 템플릿 탭 - 검색/페이지네이션/편집', () => {
  describe('탭 레이아웃 구조 검증', () => {
    it('탭 루트에 if 조건이 mail_templates 탭 확인이다', () => {
      expect(tabLayout.if).toBe("{{(_global.activeSettingsTab || query.tab || 'general') === 'mail_templates'}}");
    });

    it('헤더 영역이 존재한다', () => {
      expect(findById(tabLayout, 'mail_templates_header')).not.toBeNull();
    });

    it('검색 영역이 존재한다', () => {
      expect(findById(tabLayout, 'mail_templates_search')).not.toBeNull();
    });

    it('정보 영역이 존재한다 (총 건수, 페이지당)', () => {
      expect(findById(tabLayout, 'mail_templates_info')).not.toBeNull();
    });

    it('카드 목록이 존재한다', () => {
      expect(findById(tabLayout, 'mail_templates_list')).not.toBeNull();
    });

    it('빈 목록 메시지가 존재한다', () => {
      const empty = findById(tabLayout, 'mail_templates_empty');
      expect(empty).not.toBeNull();
      expect(empty.if).toBe('{{(mailTemplates?.data?.data ?? []).length === 0}}');
    });

    it('페이지네이션이 항상 표시된다', () => {
      const pagination = findById(tabLayout, 'mail_templates_pagination');
      expect(pagination).not.toBeNull();
      expect(pagination.if).toBeUndefined();
    });
  });

  describe('헤더 영역 검증', () => {
    it('헤더에 새 템플릿 버튼이 없다 (등록 기능 제거됨)', () => {
      const header = findById(tabLayout, 'mail_templates_header');
      const buttons = findAllByName(header, 'Button');
      const createButtons = buttons.filter((b: any) =>
        b.actions?.some((a: any) => a.handler === 'sequence' &&
          a.actions?.some((sa: any) => sa.handler === 'openModal'))
      );
      expect(createButtons.length).toBe(0);
    });
  });

  describe('검색 영역 검증', () => {
    it('검색 타입 Select가 3개 옵션을 가진다 (전체/제목/본문)', () => {
      const search = findById(tabLayout, 'mail_templates_search');
      const selects = findAllByName(search, 'Select');
      expect(selects.length).toBeGreaterThanOrEqual(1);

      const options = selects[0].props.options;
      expect(options).toHaveLength(3);
      expect(options.map((o: any) => o.value)).toEqual(['all', 'subject', 'body']);
    });

    it('검색 Input이 존재하고 로컬 상태에 바인딩된다', () => {
      const search = findById(tabLayout, 'mail_templates_search');
      const inputs = findAllByName(search, 'Input');
      expect(inputs.length).toBeGreaterThanOrEqual(1);
      expect(inputs[0].props.value).toBe("{{_local.mailTemplateFilter?.search || ''}}");
    });

    it('검색 Input에서 Enter 키 시 navigate(replace:true)로 URL 쿼리 업데이트', () => {
      const search = findById(tabLayout, 'mail_templates_search');
      const inputs = findAllByName(search, 'Input');
      const keydownAction = inputs[0].actions.find((a: any) => a.type === 'keydown');
      expect(keydownAction).toBeDefined();
      expect(keydownAction.key).toBe('Enter');
      expect(keydownAction.handler).toBe('navigate');
      expect(keydownAction.params.replace).toBe(true);
      expect(keydownAction.params.query.page).toBe(1);
      expect(keydownAction.params.query.tab).toBe('mail_templates');
    });

    it('검색 버튼 클릭 시 navigate(replace:true)로 URL 쿼리 업데이트', () => {
      const search = findById(tabLayout, 'mail_templates_search');
      const buttons = findAllByName(search, 'Button');
      const clickAction = buttons[0].actions[0];
      expect(clickAction.handler).toBe('navigate');
      expect(clickAction.params.replace).toBe(true);
      expect(clickAction.params.query.page).toBe(1);
      expect(clickAction.params.query.tab).toBe('mail_templates');
    });

    it('초기화 버튼이 필터를 기본값으로 리셋하고 navigate로 쿼리 정리', () => {
      const search = findById(tabLayout, 'mail_templates_search');
      const buttons = findAllByName(search, 'Button');
      const resetBtn = buttons[1];
      const clickAction = resetBtn.actions[0];
      expect(clickAction.handler).toBe('sequence');

      const setStateAction = clickAction.actions.find((a: any) => a.handler === 'setState');
      expect(setStateAction.params.mailTemplateFilter.search).toBe('');
      expect(setStateAction.params.mailTemplateFilter.searchType).toBe('all');

      const navigateAction = clickAction.actions.find((a: any) => a.handler === 'navigate');
      expect(navigateAction.params.replace).toBe(true);
      expect(navigateAction.params.query.tab).toBe('mail_templates');
    });
  });

  describe('카드 목록 검증', () => {
    it('iteration source가 mailTemplates?.data?.data 경로를 사용한다', () => {
      const card = findById(tabLayout, 'template_card');
      expect(card).not.toBeNull();
      expect(card.iteration.source).toBe('{{mailTemplates?.data?.data ?? []}}');
      expect(card.iteration.item_var).toBe('tpl');
      expect(card.iteration.index_var).toBe('tplIdx');
    });

    it('삭제 버튼이 없다 (삭제 기능 제거됨)', () => {
      const deleteBtn = findById(tabLayout, 'btn_delete_{{tplIdx}}');
      expect(deleteBtn).toBeNull();
    });

    it('기본값 복원 버튼에 if 조건이 있다 (is_default=false만)', () => {
      const resetBtn = findById(tabLayout, 'btn_reset_{{tplIdx}}');
      expect(resetBtn).not.toBeNull();
      expect(resetBtn.if).toBe('{{!tpl.is_default}}');
    });

    it('편집 버튼이 modal_mail_template_form을 연다', () => {
      const editBtn = findById(tabLayout, 'btn_edit_{{tplIdx}}');
      expect(editBtn).not.toBeNull();
      const clickAction = editBtn.actions[0];
      const openModal = clickAction.actions.find((a: any) => a.handler === 'openModal');
      expect(openModal.target).toBe('modal_mail_template_form');
    });

    it('편집 버튼이 editingTemplate에 tpl 데이터를 설정한다', () => {
      const editBtn = findById(tabLayout, 'btn_edit_{{tplIdx}}');
      const clickAction = editBtn.actions[0];
      const setState = clickAction.actions.find((a: any) => a.handler === 'setState');
      expect(setState.params.editingTemplate).toBe('{{tpl}}');
      expect(setState.params.templateErrors).toBeNull();
      expect(setState.params.isSaving).toBe(false);
    });
  });

  describe('페이지네이션 검증', () => {
    it('Pagination 컴포넌트가 올바른 props를 가진다', () => {
      const paginationSection = findById(tabLayout, 'mail_templates_pagination');
      const paginations = findAllByName(paginationSection, 'Pagination');
      expect(paginations.length).toBe(1);

      const pagination = paginations[0];
      expect(pagination.props.currentPage).toBe('{{mailTemplates?.data?.pagination?.current_page || 1}}');
      expect(pagination.props.totalPages).toBe('{{mailTemplates?.data?.pagination?.last_page || 1}}');
    });

    it('onPageChange 이벤트가 navigate(replace:true, mergeQuery:true)를 호출한다', () => {
      const paginationSection = findById(tabLayout, 'mail_templates_pagination');
      const paginations = findAllByName(paginationSection, 'Pagination');
      const pageChangeAction = paginations[0].actions.find((a: any) => a.event === 'onPageChange');
      expect(pageChangeAction.handler).toBe('navigate');
      expect(pageChangeAction.params.replace).toBe(true);
      expect(pageChangeAction.params.mergeQuery).toBe(true);
      expect(pageChangeAction.params.query.page).toBe('{{$args[0]}}');
    });
  });

  describe('편집 전용 모달 레이아웃 검증', () => {
    it('모달 ID가 modal_mail_template_form이다', () => {
      expect(formModalLayout.id).toBe('modal_mail_template_form');
    });

    it('모달이 xl 사이즈이다', () => {
      expect(formModalLayout.props.size).toBe('xl');
    });

    it('모달 타이틀이 편집 전용이다', () => {
      expect(formModalLayout.props.title).toContain('edit_modal.title');
    });

    it('type 입력 필드가 없다 (생성 모드 제거)', () => {
      const typeInput = findById(formModalLayout, 'type_input_section');
      expect(typeInput).toBeNull();
    });

    it('type 표시 섹션이 항상 표시된다 (조건 없음)', () => {
      const typeDisplay = findById(formModalLayout, 'type_display_section');
      expect(typeDisplay).not.toBeNull();
      expect(typeDisplay.if).toBeUndefined();
    });

    it('한국어/영어 탭이 있다', () => {
      const buttons = findAllByName(formModalLayout, 'Button');
      const langButtons = buttons.filter((b: any) =>
        b.text === '한국어' || b.text === 'English'
      );
      expect(langButtons.length).toBe(2);
    });

    it('한국어/영어 제목 입력 필드가 있다 (탭별 조건부)', () => {
      const subjectKo = findById(formModalLayout, 'subject_ko');
      const subjectEn = findById(formModalLayout, 'subject_en');
      expect(subjectKo).not.toBeNull();
      expect(subjectEn).not.toBeNull();
      expect(subjectKo.if).toContain("=== 'ko'");
      expect(subjectEn.if).toContain("=== 'en'");
    });

    it('한국어/영어 본문 HtmlEditor가 있다 (탭별 조건부)', () => {
      const bodyKo = findById(formModalLayout, 'body_ko');
      const bodyEn = findById(formModalLayout, 'body_en');
      expect(bodyKo).not.toBeNull();
      expect(bodyEn).not.toBeNull();

      const editorsKo = findAllByName(bodyKo, 'HtmlEditor');
      const editorsEn = findAllByName(bodyEn, 'HtmlEditor');
      expect(editorsKo.length).toBe(1);
      expect(editorsEn.length).toBe(1);
    });

    it('활성화 토글이 있다', () => {
      const toggle = findById(formModalLayout, 'is_active_toggle');
      expect(toggle).not.toBeNull();
      const toggles = findAllByName(toggle, 'Toggle');
      expect(toggles.length).toBe(1);
      expect(toggles[0].props.checked).toBe('{{$parent._local.editingTemplate?.is_active ?? true}}');
    });

    it('변수 정보 섹션이 조건부 표시된다', () => {
      const variablesInfo = findById(formModalLayout, 'variables_info');
      expect(variablesInfo).not.toBeNull();
      expect(variablesInfo.if).toBe('{{($parent._local.editingTemplate?.variables ?? []).length > 0}}');
    });

    it('검증 에러 배너가 조건부 표시된다', () => {
      const errorBanner = findById(formModalLayout, 'validation_error_banner');
      expect(errorBanner).not.toBeNull();
      expect(errorBanner.if).toBe('{{$parent._local.templateErrors}}');
    });

    it('저장 버튼이 PUT 메서드로 편집 API를 호출한다', () => {
      const saveBtn = findById(formModalLayout, 'btn_save');
      expect(saveBtn).not.toBeNull();

      const clickAction = saveBtn.actions[0];
      expect(clickAction.handler).toBe('sequence');

      const apiCallAction = clickAction.actions.find((a: any) => a.handler === 'apiCall');
      expect(apiCallAction).toBeDefined();
      expect(apiCallAction.target).toContain('editingTemplate');
      expect(apiCallAction.params.method).toBe('PUT');
    });

    it('저장 성공 시 모달 닫기 + navigate로 목록 새로고침', () => {
      const saveBtn = findById(formModalLayout, 'btn_save');
      const apiCallAction = saveBtn.actions[0].actions.find((a: any) => a.handler === 'apiCall');
      const onSuccess = apiCallAction.onSuccess;

      const closeModal = onSuccess.find((a: any) => a.handler === 'closeModal');
      expect(closeModal).toBeDefined();
      expect(closeModal.target).toBe('modal_mail_template_form');

      const navigateAction = onSuccess.find((a: any) => a.handler === 'navigate');
      expect(navigateAction).toBeDefined();
      expect(navigateAction.params.replace).toBe(true);
      expect(navigateAction.params.mergeQuery).toBe(true);
    });

    it('저장 실패 시 에러 상태가 설정된다', () => {
      const saveBtn = findById(formModalLayout, 'btn_save');
      const apiCallAction = saveBtn.actions[0].actions.find((a: any) => a.handler === 'apiCall');
      const onError = apiCallAction.onError;

      const setStateAction = onError.find((a: any) => a.handler === 'setState');
      expect(setStateAction).toBeDefined();
      expect(setStateAction.params.templateErrors).toBe('{{error.errors}}');
      expect(setStateAction.params.isSaving).toBe(false);
    });

    it('취소 버튼이 modal_mail_template_form을 닫는다', () => {
      const cancelBtn = findById(formModalLayout, 'btn_cancel');
      expect(cancelBtn).not.toBeNull();
      const clickAction = cancelBtn.actions[0];
      expect(clickAction.handler).toBe('closeModal');
      expect(clickAction.target).toBe('modal_mail_template_form');
    });
  });
});

/**
 * 게시판 설정 메일 템플릿 탭/모달 레이아웃 JSON 구조 검증 테스트
 *
 * @description
 * - 탭 Partial: _tab_mail_templates.json
 * - 모달 Partial: _modal_mail_template_edit.json, _modal_mail_template_preview.json
 *
 * 검증 항목:
 * - 탭 조건부 렌더링 (activeBoardSettingsTab === 'mail_templates')
 * - 검색/필터 바 (검색타입 Select + Input + 검색/초기화 버튼)
 * - 총 건수 + per_page 선택 + 페이지네이션
 * - iteration 기반 템플릿 카드 목록 렌더링 (페이지네이션 데이터 경로)
 * - 빈 상태 (검색결과 없음 / 데이터 없음 분기)
 * - 카드 내 토글/편집/리셋 버튼 구조 및 API 호출
 * - 변수 목록 조건부 표시 (variables.length > 0)
 * - 타입 표시 분기 (is_default 여부)
 * - navigate 기반 갱신 방식 (refetchDataSource 대신)
 * - 편집 모달: blur_until_loaded, sticky footer, IIFE body, is_active toggle
 * - 편집 버튼 상태 초기화 (templateErrors: null, isSaving: false)
 * - 미리보기 모달 구조
 */

import { describe, it, expect } from 'vitest';

// 레이아웃 JSON 임포트
import mainLayout from '../../../layouts/admin/admin_board_settings.json';
import tabMailTemplates from '../../../layouts/admin/partials/admin_board_settings/_tab_mail_templates.json';
import modalEdit from '../../../layouts/admin/partials/admin_board_settings/_modal_mail_template_edit.json';
import modalPreview from '../../../layouts/admin/partials/admin_board_settings/_modal_mail_template_preview.json';

/**
 * JSON 트리에서 특정 ID를 가진 노드를 재귀적으로 찾습니다.
 */
function findById(node: any, id: string): any | null {
  if (!node) return null;
  if (node.id === id) return node;

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

// ============================================================================
// 메인 레이아웃 (admin_board_settings.json) 내 메일 템플릿 연동 검증
// ============================================================================

describe('admin_board_settings.json 메일 템플릿 연동', () => {
  it('data_sources에 boardMailTemplates가 정의되어 있다', () => {
    const dataSources = mainLayout.data_sources;
    const mailDs = dataSources.find((ds: any) => ds.id === 'boardMailTemplates');
    expect(mailDs).toBeDefined();
    expect(mailDs!.endpoint).toBe('/api/modules/sirsoft-board/admin/mail-templates');
    expect(mailDs!.method).toBe('GET');
    expect(mailDs!.auto_fetch).toBe(true);
  });

  it('data_sources params에 페이지네이션/검색 파라미터가 있다', () => {
    const dataSources = mainLayout.data_sources;
    const mailDs = dataSources.find((ds: any) => ds.id === 'boardMailTemplates');
    expect(mailDs).toBeDefined();
    expect(mailDs!.params).toBeDefined();
    const paramsStr = JSON.stringify(mailDs!.params);
    expect(paramsStr).toContain('query.page');
    expect(paramsStr).toContain('query.per_page');
    expect(paramsStr).toContain('query.search');
    expect(paramsStr).toContain('query.search_type');
  });

  it('탭 네비게이션에 mail_templates 탭이 포함되어 있다', () => {
    const slotContent = (mainLayout as any).slots?.content;

    function findTabNav(node: any): any | null {
      if (!node) return null;
      if (node.name === 'TabNavigation') return node;
      if (node.children && Array.isArray(node.children)) {
        for (const child of node.children) {
          const found = findTabNav(child);
          if (found) return found;
        }
      }
      return null;
    }

    const tabNav = findTabNav({ children: slotContent });
    expect(tabNav).toBeDefined();

    const tabIds = tabNav.props.tabs.map((t: any) => t.id);
    expect(tabIds).toContain('mail_templates');
  });

  it('slots에 _tab_mail_templates.json partial이 참조되어 있다', () => {
    const slotContent = (mainLayout as any).slots;
    const partials = JSON.stringify(slotContent);
    expect(partials).toContain('_tab_mail_templates.json');
  });

  it('modals에 편집 모달 partial이 참조되어 있다', () => {
    const modals = mainLayout.modals;
    const modalsStr = JSON.stringify(modals);
    expect(modalsStr).toContain('_modal_mail_template_edit.json');
  });

  it('modals에 미리보기 모달 partial이 참조되어 있다', () => {
    const modals = mainLayout.modals;
    const modalsStr = JSON.stringify(modals);
    expect(modalsStr).toContain('_modal_mail_template_preview.json');
  });

  it('initLocal에 mailTemplateFilter가 있다', () => {
    const initLocal = (mainLayout as any).initLocal;
    expect(initLocal).toBeDefined();
    expect(initLocal.mailTemplateFilter).toBeDefined();
    expect(initLocal.mailTemplateFilter.search).toBe('');
    expect(initLocal.mailTemplateFilter.searchType).toBe('all');
  });

  it('initLocal에 expandedMailTemplateIds가 빈 배열로 있다', () => {
    const initLocal = (mainLayout as any).initLocal;
    expect(initLocal.expandedMailTemplateIds).toBeDefined();
    expect(initLocal.expandedMailTemplateIds).toEqual([]);
  });
});

// ============================================================================
// 탭 Partial (_tab_mail_templates.json) 구조 검증
// ============================================================================

describe('_tab_mail_templates.json 구조 검증', () => {
  it('partial 메타 정보가 올바르다', () => {
    expect(tabMailTemplates.meta.is_partial).toBe(true);
  });

  it('mail_templates 탭일 때만 표시된다 (조건부 렌더링)', () => {
    expect(tabMailTemplates.if).toContain('mail_templates');
    expect(tabMailTemplates.if).toContain('_global.activeBoardSettingsTab');
  });

  it('헤더에 제목과 설명이 있다', () => {
    const header = findById(tabMailTemplates, 'board_mail_templates_header');
    expect(header).toBeDefined();
    expect(header.type).toBe('basic');
  });

  // ========================================================================
  // 검색/필터 바
  // ========================================================================

  describe('검색/필터 바', () => {
    it('검색 바 영역이 존재한다', () => {
      const search = findById(tabMailTemplates, 'board_mail_templates_search');
      expect(search).toBeDefined();
    });

    it('검색 타입 Select (all/subject/body)가 있다', () => {
      const search = findById(tabMailTemplates, 'board_mail_templates_search');
      const searchStr = JSON.stringify(search);
      expect(searchStr).toContain('search_type_all');
      expect(searchStr).toContain('search_type_subject');
      expect(searchStr).toContain('search_type_body');
    });

    it('검색 Input이 있다', () => {
      const search = findById(tabMailTemplates, 'board_mail_templates_search');
      const searchStr = JSON.stringify(search);
      expect(searchStr).toContain('search_placeholder');
      expect(searchStr).toContain('mailTemplateFilter');
    });

    it('검색 버튼이 navigate 핸들러를 사용한다', () => {
      const search = findById(tabMailTemplates, 'board_mail_templates_search');
      const searchStr = JSON.stringify(search);
      expect(searchStr).toContain('btn_search');
      expect(searchStr).toContain('"handler":"navigate"');
    });

    it('초기화 버튼이 setState + navigate sequence를 실행한다', () => {
      const search = findById(tabMailTemplates, 'board_mail_templates_search');
      const searchStr = JSON.stringify(search);
      expect(searchStr).toContain('btn_reset_filter');
      expect(searchStr).toContain('"handler":"sequence"');
      expect(searchStr).toContain('"handler":"setState"');
      expect(searchStr).toContain('"handler":"navigate"');
    });

    it('Enter 키로 검색이 가능하다', () => {
      const search = findById(tabMailTemplates, 'board_mail_templates_search');
      const searchStr = JSON.stringify(search);
      expect(searchStr).toContain('"type":"keydown"');
      expect(searchStr).toContain('"key":"Enter"');
    });
  });

  // ========================================================================
  // 정보 표시 줄 (총 건수 + per_page)
  // ========================================================================

  describe('정보 표시 줄', () => {
    it('총 건수를 표시한다', () => {
      const info = findById(tabMailTemplates, 'board_mail_templates_info');
      expect(info).toBeDefined();
      const infoStr = JSON.stringify(info);
      expect(infoStr).toContain('total_count');
      expect(infoStr).toContain('boardMailTemplates?.data?.pagination?.total');
    });

    it('per_page Select (10/20/50/100)가 있다', () => {
      const info = findById(tabMailTemplates, 'board_mail_templates_info');
      const infoStr = JSON.stringify(info);
      expect(infoStr).toContain('per_page_label');
      expect(infoStr).toContain('"10"');
      expect(infoStr).toContain('"20"');
      expect(infoStr).toContain('"50"');
      expect(infoStr).toContain('"100"');
    });

    it('per_page 변경 시 navigate를 사용한다', () => {
      const info = findById(tabMailTemplates, 'board_mail_templates_info');
      const infoStr = JSON.stringify(info);
      expect(infoStr).toContain('"handler":"navigate"');
      expect(infoStr).toContain('per_page');
    });
  });

  // ========================================================================
  // 템플릿 카드 목록
  // ========================================================================

  describe('템플릿 카드 목록', () => {
    it('iteration 소스가 페이지네이션 데이터 경로를 사용한다', () => {
      const card = findById(tabMailTemplates, 'board_template_card');
      expect(card).toBeDefined();
      expect(card.iteration).toBeDefined();
      expect(card.iteration.source).toContain('boardMailTemplates?.data?.data');
      expect(card.iteration.item_var).toBe('tpl');
      expect(card.iteration.index_var).toBe('tplIdx');
    });

    it('is_default 여부로 타입 표시를 분기한다', () => {
      const card = findById(tabMailTemplates, 'board_template_card');
      const cardStr = JSON.stringify(card);
      expect(cardStr).toContain('tpl.is_default');
      // is_default인 경우 $t: 번역 키, 아닌 경우 raw type
      expect(cardStr).toContain('sirsoft-board.admin.settings.mail_templates.types');
      expect(cardStr).toContain('{{tpl.type}}');
    });

    it('카드에 활성/비활성 배지가 있다', () => {
      const card = findById(tabMailTemplates, 'board_template_card');
      const cardStr = JSON.stringify(card);
      expect(cardStr).toContain('tpl.is_active');
    });

    it('카드에 로케일 제목 미리보기가 있다', () => {
      const card = findById(tabMailTemplates, 'board_template_card');
      const cardStr = JSON.stringify(card);
      expect(cardStr).toContain('tpl.subject');
    });

    it('카드의 변수가 조건부로 표시된다 (variables.length > 0)', () => {
      const card = findById(tabMailTemplates, 'board_template_card');
      const cardStr = JSON.stringify(card);
      expect(cardStr).toContain('(tpl.variables ?? []).length > 0');
      expect(cardStr).toContain('variable.key');
    });
  });

  // ========================================================================
  // 토글 버튼
  // ========================================================================

  describe('토글 버튼', () => {
    it('PATCH /toggle-active API를 호출한다', () => {
      const card = findById(tabMailTemplates, 'board_template_card');
      const cardStr = JSON.stringify(card);
      expect(cardStr).toContain('/api/modules/sirsoft-board/admin/mail-templates/');
      expect(cardStr).toContain('toggle-active');
      expect(cardStr).toContain('"method":"PATCH"');
    });

    it('성공 시 navigate를 사용한다 (refetchDataSource 대신)', () => {
      const card = findById(tabMailTemplates, 'board_template_card');
      const cardStr = JSON.stringify(card);
      expect(cardStr).toContain('"handler":"navigate"');
      expect(cardStr).toContain('"mergeQuery":true');
    });
  });

  // ========================================================================
  // 편집 버튼
  // ========================================================================

  describe('편집 버튼', () => {
    it('sequence 핸들러로 setState + openModal을 실행한다', () => {
      const card = findById(tabMailTemplates, 'board_template_card');
      const cardStr = JSON.stringify(card);
      expect(cardStr).toContain('"handler":"setState"');
      expect(cardStr).toContain('"editingTemplate"');
      expect(cardStr).toContain('"handler":"openModal"');
      expect(cardStr).toContain('modal_board_mail_template_edit');
    });

    it('편집 시 상태 초기화 (templateErrors: null, isSaving: false)', () => {
      const card = findById(tabMailTemplates, 'board_template_card');
      const cardStr = JSON.stringify(card);
      expect(cardStr).toContain('"templateErrors":null');
      expect(cardStr).toContain('"isSaving":false');
    });
  });

  // ========================================================================
  // 리셋 버튼
  // ========================================================================

  describe('리셋 버튼', () => {
    it('POST /reset API를 호출한다', () => {
      const card = findById(tabMailTemplates, 'board_template_card');
      const cardStr = JSON.stringify(card);
      expect(cardStr).toContain('/reset');
      expect(cardStr).toContain('"method":"POST"');
    });

    it('is_default가 아닐 때만 표시된다', () => {
      const card = findById(tabMailTemplates, 'board_template_card');
      const cardStr = JSON.stringify(card);
      expect(cardStr).toContain('tpl.is_default');
    });

    it('성공 시 navigate를 사용한다', () => {
      const card = findById(tabMailTemplates, 'board_template_card');
      const cardStr = JSON.stringify(card);
      // reset onSuccess에도 navigate가 사용됨
      expect(cardStr).toContain('"handler":"navigate"');
    });
  });

  // ========================================================================
  // 본문 펼치기/접기 토글
  // ========================================================================

  describe('본문 펼치기/접기 토글', () => {
    it('펼치기 버튼이 카드에 존재한다', () => {
      const cardStr = JSON.stringify(tabMailTemplates);
      expect(cardStr).toContain('board_btn_expand_');
      expect(cardStr).toContain('fa-chevron-down');
      expect(cardStr).toContain('fa-chevron-up');
    });

    it('펼치기 버튼이 expandedMailTemplateIds 상태를 토글한다', () => {
      const cardStr = JSON.stringify(tabMailTemplates);
      expect(cardStr).toContain('expandedMailTemplateIds');
      expect(cardStr).toContain('.includes(tpl.id)');
      expect(cardStr).toContain('.filter(');
    });

    it('본문 미리보기가 펼침 상태일 때만 표시된다', () => {
      const cardStr = JSON.stringify(tabMailTemplates);
      // body preview section with if condition
      expect(cardStr).toContain('expandedMailTemplateIds');
      expect(cardStr).toContain('tpl.body');
      expect(cardStr).toContain('edit_modal.body');
    });
  });

  // ========================================================================
  // 빈 상태
  // ========================================================================

  describe('빈 상태', () => {
    it('빈 상태 영역이 존재한다', () => {
      const empty = findById(tabMailTemplates, 'board_mail_templates_empty');
      expect(empty).toBeDefined();
      expect(empty.if).toContain('boardMailTemplates?.data?.data');
      expect(empty.if).toContain('length === 0');
    });

    it('검색결과 없음과 데이터 없음을 분기 표시한다', () => {
      const empty = findById(tabMailTemplates, 'board_mail_templates_empty');
      const emptyStr = JSON.stringify(empty);
      expect(emptyStr).toContain('empty_no_results');
      expect(emptyStr).toContain('empty_message');
      expect(emptyStr).toContain('query.search');
    });
  });

  // ========================================================================
  // 페이지네이션
  // ========================================================================

  describe('페이지네이션', () => {
    it('Pagination 컴포넌트가 존재한다', () => {
      const pagination = findById(tabMailTemplates, 'board_mail_templates_pagination');
      expect(pagination).toBeDefined();
      const paginationStr = JSON.stringify(pagination);
      expect(paginationStr).toContain('"name":"Pagination"');
    });

    it('currentPage와 totalPages가 올바른 데이터 경로를 사용한다', () => {
      const pagination = findById(tabMailTemplates, 'board_mail_templates_pagination');
      const paginationStr = JSON.stringify(pagination);
      expect(paginationStr).toContain('boardMailTemplates?.data?.pagination?.current_page');
      expect(paginationStr).toContain('boardMailTemplates?.data?.pagination?.last_page');
    });

    it('페이지 변경 시 navigate를 사용한다', () => {
      const pagination = findById(tabMailTemplates, 'board_mail_templates_pagination');
      const paginationStr = JSON.stringify(pagination);
      expect(paginationStr).toContain('"handler":"navigate"');
      expect(paginationStr).toContain('mergeQuery');
    });
  });
});

// ============================================================================
// 편집 모달 (_modal_mail_template_edit.json) 구조 검증
// ============================================================================

describe('_modal_mail_template_edit.json 구조 검증', () => {
  it('모달 ID가 올바르다', () => {
    expect(modalEdit.id).toBe('modal_board_mail_template_edit');
  });

  it('Modal 컴포넌트를 사용한다', () => {
    expect(modalEdit.name).toBe('Modal');
    expect(modalEdit.type).toBe('composite');
  });

  it('모달 크기가 xl이다', () => {
    expect(modalEdit.props.size).toBe('xl');
  });

  it('closeOnOverlayClick이 false이다', () => {
    expect(modalEdit.props.closeOnOverlayClick).toBe(false);
  });

  // ========================================================================
  // blur_until_loaded
  // ========================================================================

  it('폼 컨테이너에 blur_until_loaded가 적용되어 있다', () => {
    const container = findById(modalEdit, 'board_edit_form_container');
    expect(container).toBeDefined();
    expect(container.blur_until_loaded).toBeDefined();
    expect(container.blur_until_loaded).toContain('isSaving');
  });

  // ========================================================================
  // sticky footer
  // ========================================================================

  it('footer가 sticky 스타일을 가진다', () => {
    const footer = findById(modalEdit, 'board_edit_modal_footer');
    expect(footer).toBeDefined();
    expect(footer.props.className).toContain('sticky');
    expect(footer.props.className).toContain('bottom-0');
    expect(footer.props.className).toContain('bg-white');
    expect(footer.props.className).toContain('dark:bg-gray-800');
  });

  it('알림 유형 라벨이 읽기 전용으로 표시된다', () => {
    const editStr = JSON.stringify(modalEdit);
    expect(editStr).toContain('_local.editingTemplate?.type');
  });

  it('한국어/영어 제목 입력 필드가 있다', () => {
    const subjectKo = findById(modalEdit, 'board_edit_subject_ko');
    const subjectEn = findById(modalEdit, 'board_edit_subject_en');
    expect(subjectKo).toBeDefined();
    expect(subjectEn).toBeDefined();
  });

  it('한국어/영어 본문 에디터가 있다', () => {
    const bodyKo = findById(modalEdit, 'board_edit_body_ko');
    const bodyEn = findById(modalEdit, 'board_edit_body_en');
    expect(bodyKo).toBeDefined();
    expect(bodyEn).toBeDefined();
  });

  // ========================================================================
  // is_active Toggle
  // ========================================================================

  it('is_active Toggle이 있다', () => {
    const toggle = findById(modalEdit, 'board_edit_is_active_toggle');
    expect(toggle).toBeDefined();
    const toggleStr = JSON.stringify(toggle);
    expect(toggleStr).toContain('Toggle');
    expect(toggleStr).toContain('editingTemplate?.is_active');
    expect(toggleStr).toContain('is_active_label');
  });

  // ========================================================================
  // 변수 정보 조건부 표시
  // ========================================================================

  it('변수 정보가 variables.length > 0일 때만 표시된다', () => {
    const varInfo = findById(modalEdit, 'board_edit_variables_info');
    expect(varInfo).toBeDefined();
    expect(varInfo.if).toContain('editingTemplate?.variables');
    expect(varInfo.if).toContain('length > 0');
  });

  // ========================================================================
  // 저장 버튼 (함수형 IIFE body)
  // ========================================================================

  it('저장 버튼이 PUT API를 호출한다', () => {
    const editStr = JSON.stringify(modalEdit);
    expect(editStr).toContain('/api/modules/sirsoft-board/admin/mail-templates/');
    expect(editStr).toContain('"method":"PUT"');
  });

  it('저장 body가 함수형 IIFE 패턴을 사용한다', () => {
    const editStr = JSON.stringify(modalEdit);
    expect(editStr).toContain('(function()');
    expect(editStr).toContain('tpl.subject');
    expect(editStr).toContain('tpl.body');
    expect(editStr).toContain('tpl.is_active');
  });

  it('저장 성공 시 closeModal + navigate를 실행한다', () => {
    const editStr = JSON.stringify(modalEdit);
    expect(editStr).toContain('"handler":"closeModal"');
    expect(editStr).toContain('"handler":"navigate"');
    expect(editStr).toContain('"mergeQuery":true');
  });

  it('저장 전 isSaving=true, templateErrors=null 설정', () => {
    const editStr = JSON.stringify(modalEdit);
    // sequence 첫 번째 액션에서 isSaving: true 설정
    expect(editStr).toContain('"isSaving":true');
    expect(editStr).toContain('"templateErrors":null');
  });

  it('저장 실패 시 에러를 templateErrors에 저장한다', () => {
    const editStr = JSON.stringify(modalEdit);
    expect(editStr).toContain('error.errors');
    expect(editStr).toContain('"isSaving":false');
  });

  it('취소 버튼이 closeModal을 실행한다', () => {
    const editStr = JSON.stringify(modalEdit);
    expect(editStr).toContain('"handler":"closeModal"');
  });

});

// ============================================================================
// 미리보기 모달 (_modal_mail_template_preview.json) 구조 검증
// ============================================================================

describe('_modal_mail_template_preview.json 구조 검증', () => {
  it('모달 ID가 올바르다', () => {
    expect(modalPreview.id).toBe('modal_board_mail_template_preview');
  });

  it('Modal 컴포넌트를 사용한다', () => {
    expect(modalPreview.name).toBe('Modal');
    expect(modalPreview.type).toBe('composite');
  });

  it('미리보기 제목이 표시된다', () => {
    const previewStr = JSON.stringify(modalPreview);
    expect(previewStr).toContain('preview');
  });
});

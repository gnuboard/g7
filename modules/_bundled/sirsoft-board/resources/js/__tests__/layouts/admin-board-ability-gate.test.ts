// e2e:allow 권한 게이트(입력·버튼 잠금) 레이아웃 계약 검증 — 브라우저 실측은 W1·W2·W3 통합 뒤 총괄 세션이 권한 회수 계정으로 함께 한다(이력 20261010_0208_710_abilities수정_W3)
/**
 * @file admin-board-ability-gate.test.ts
 * @description 게시판 관리자 화면 abilities 게이트 (7.1.0 abilities 게이트 최종 점검 board-admin-a)
 *
 * 화면이 쓰기 UI 를 잠그는 근거를 서버 라우트와 같은 권한 축으로 맞춘다.
 * - 생성·복제 폼: form-data 의 abilities.can_create 로 읽기 전용(#1)
 * - 환경설정 SEO 탭: 입력 9칸 잠금 + 「SEO 캐시 초기화」는 코어 환경설정 수정 권한(#2·#3, G06)
 * - 게시판 유형 관리: 게시판 등록 권한(#4)
 * - 알림 채널 토글: 저장 축(게시판 환경설정 수정)과 같게(#5)
 * - 날짜 형식 카드·일괄 적용 대상 카드·체크박스: 읽기 전용/일괄 적용 권한이면 바뀌지 않음(#6·#7, 제품 결정 일괄 적용)
 * - 반응 유형 드래그 손잡이: 읽기 전용이면 숨김(#8, G10)
 * - 이커머스 문의 게시판 「관리자 메뉴에 추가」: 그 게시판 행의 can_update(#9, G07)
 * - 게시판 폼 반응 유형 [관리]: 환경설정 수정 권한(#10)
 * - 대시보드 확장: 데이터 API 와 같은 권한으로 그리기(#12)
 */

import { describe, it, expect } from 'vitest';
import { actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import settingsLayout from '../../../layouts/admin/admin_board_settings.json';
import formLayout from '../../../layouts/admin/admin_board_form.json';
import seoTab from '../../../layouts/admin/partials/admin_board_settings/_tab_seo.json';
import generalTab from '../../../layouts/admin/partials/admin_board_settings/_tab_general.json';
import bulkApplyTab from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_bulk_apply.json';
import basicTab from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_basic.json';
import attachmentTab from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_attachment.json';
import commentTab from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_comment.json';
import listTab from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_list.json';
import notificationSubTab from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_notification.json';
import permissionsSubTab from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_permissions.json';
import postTab from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_post.json';
import replyTab from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_reply.json';
import notificationDefinitionsTab from '../../../layouts/admin/partials/admin_board_settings/_tab_notification_definitions.json';
import reactionEditor from '../../../layouts/admin/partials/admin_board_settings/_reaction_type_editor.json';
import boardTypeModal from '../../../layouts/admin/partials/admin_board_form/_board_type_manage_modal.json';
import formBasicTab from '../../../layouts/admin/partials/admin_board_form/_tab_basic.json';
import inquiryExtension from '../../../extensions/admin-ecommerce-inquiry-settings.json';
import communityExtension from '../../../extensions/admin_dashboard_community.json';
import quickMenuExtension from '../../../extensions/admin_dashboard_quick_menu.json';

type Node = Record<string, any>;

/** 트리에서 조건에 맞는 노드를 모두 모은다 */
function collect(node: unknown, pred: (n: Node) => boolean, out: Node[] = []): Node[] {
  if (Array.isArray(node)) {
    node.forEach((v) => collect(v, pred, out));
    return out;
  }
  if (node && typeof node === 'object') {
    if (pred(node as Node)) out.push(node as Node);
    Object.values(node).forEach((v) => collect(v, pred, out));
  }
  return out;
}

/** id(data-ref) 로 노드 하나를 찾는다 */
function byId(node: unknown, id: string): Node {
  const hit = collect(node, (n) => n.id === id)[0];
  expect(hit, `노드 ${id} 가 없습니다`).toBeTruthy();
  return hit;
}

/** `{{ ... }}` 표현식을 주어진 이름들로 평가한다 */
function evaluate(raw: unknown, scope: Record<string, unknown>): unknown {
  const expr = String(raw).trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(scope), `return (${expr});`)(...Object.values(scope));
}

/** 환경설정 화면 computed 를 응답 abilities 로 평가한다 */
function settingsComputed(abilities: Record<string, boolean> | undefined) {
  const scope = { settings: { data: abilities ? { abilities } : {} }, _local: {}, query: {}, _global: {}, boards_list: {}, boardNotificationDefinitions: {}, roles: {} };
  const computed = (settingsLayout as Node).computed as Record<string, string>;
  return {
    isReadOnly: evaluate(computed.isReadOnly, scope),
    canBulkApply: evaluate(computed.canBulkApply, scope),
    canManageBoardTypes: evaluate(computed.canManageBoardTypes, scope),
    canClearSeoCache: evaluate(computed.canClearSeoCache, scope),
  };
}

describe('게시판 환경설정 — 응답 abilities 로 계산하는 잠금 값', () => {
  /**
   * 환경설정 수정만 가진 관리자는 일괄 적용·유형 관리·SEO 캐시 초기화가 잠긴다.
   *
   * @scenario case=settings_abilities_settings_update_only
   *
   * @effects settings_reports_split_abilities, bulk_apply_locked_without_board_update
   */
  it('응답 키가 false 거나 없으면 세 값 모두 잠금(fail-closed)', () => {
    const c = settingsComputed({ can_update: true, can_bulk_apply: false, can_manage_board_types: false, can_clear_seo_cache: false });
    expect(c.isReadOnly).toBe(false);
    expect(c.canBulkApply).toBe(false);
    expect(c.canManageBoardTypes).toBe(false);
    expect(c.canClearSeoCache).toBe(false);
    const none = settingsComputed({ can_update: true });
    expect([none.canBulkApply, none.canManageBoardTypes, none.canClearSeoCache]).toEqual([false, false, false]);
  });

  /**
   * 권한을 모두 가지면 세 값이 열린다.
   *
   * @scenario case=settings_abilities_all_granted
   *
   * @effects settings_reports_split_abilities
   */
  it('응답 키가 true 면 열린다', () => {
    const c = settingsComputed({ can_update: true, can_bulk_apply: true, can_manage_board_types: true, can_clear_seo_cache: true });
    expect([c.canBulkApply, c.canManageBoardTypes, c.canClearSeoCache]).toEqual([true, true, true]);
  });
});

describe('게시판 환경설정 SEO 탭 (G06)', () => {
  /**
   * 읽기 전용이면 메타 입력 6칸·체크 3칸이 모두 잠긴다.
   *
   * @scenario case=seo_tab_read_only
   *
   * @effects seo_inputs_locked_when_read_only
   */
  it('읽기 전용이면 seo.* 입력 9칸이 모두 disabled', () => {
    const inputs = collect(seoTab, (n) => typeof n.props?.name === 'string' && n.props.name.startsWith('seo.'));
    expect(inputs.length).toBe(9);
    for (const input of inputs) {
      expect(evaluate(input.props.disabled, { _computed: { isReadOnly: true } }), input.props.name).toBe(true);
      expect(evaluate(input.props.disabled, { _computed: { isReadOnly: false } }), input.props.name).toBe(false);
    }
  });

  /**
   * 「SEO 캐시 초기화」는 코어 환경설정 수정 권한이 없으면 잠기고 이유를 보인다.
   *
   * @scenario case=seo_clear_cache_without_core_settings
   *
   * @effects seo_clear_cache_locked_with_reason
   */
  it('canClearSeoCache=false 면 캐시 초기화 버튼이 잠기고 안내가 보인다', () => {
    const button = byId(seoTab, 'seo_clear_cache_button');
    expect(evaluate(button.props.disabled, { _computed: { canClearSeoCache: false }, _local: {} })).toBe(true);
    expect(evaluate(button.props.disabled, { _computed: { canClearSeoCache: true }, _local: {} })).toBe(false);
    const hint = byId(seoTab, 'seo_clear_cache_no_permission');
    expect(evaluate(hint.if, { _computed: { canClearSeoCache: false } })).toBe(true);
    expect(JSON.stringify(hint)).toContain('clear_cache_no_permission');
  });
});

describe('게시판 환경설정 일괄 적용 (제품 결정: 환경설정 수정 + 게시판 수정)', () => {
  const subTabs = [basicTab, attachmentTab, commentTab, listTab, notificationSubTab, permissionsSubTab, postTab, replyTab];

  /**
   * 하위 탭의 일괄 적용 체크박스 전부가 일괄 적용 권한으로 잠긴다.
   *
   * @scenario case=bulk_apply_without_board_update
   *
   * @effects bulk_apply_locked_without_board_update
   */
  it('일괄 적용 체크박스 전부가 canBulkApply=false 면 disabled', () => {
    const boxes = subTabs.flatMap((tab) => collect(tab, (n) => n.props?.type === 'checkbox' && String(n.props?.checked ?? '').includes('bulkApplyFields')));
    expect(boxes.length).toBe(35);
    for (const box of boxes) {
      expect(evaluate(box.props.disabled, { _computed: { canBulkApply: false } })).toBe(true);
      expect(evaluate(box.props.disabled, { _computed: { canBulkApply: true } })).toBe(false);
    }
  });

  /**
   * 대상 카드 클릭·라디오·게시판 선택·적용 버튼이 잠기고, 수정 권한은 있으나 일괄 적용 권한이 없으면 이유를 보인다.
   *
   * @scenario case=bulk_apply_without_board_update
   *
   * @effects bulk_apply_locked_without_board_update, bulk_apply_reason_shown
   */
  it('대상 카드·라디오·적용 버튼이 잠기고 이유 문구가 보인다', () => {
    const locked = { _computed: { canBulkApply: false, isReadOnly: false }, boards_list: { data: { data: [{ id: 1 }] } } };
    for (const id of ['bulk_apply_target_all', 'bulk_apply_target_selected']) {
      const card = byId(bulkApplyTab, id);
      const click = actionsOf(card).find((a: Node) => a.type === 'click');
      expect(evaluate(click.if, locked), id).toBe(false);
    }
    const radios = collect(bulkApplyTab, (n) => n.props?.type === 'radio');
    expect(radios.length).toBe(2);
    radios.forEach((r) => expect(evaluate(r.props.disabled, locked)).toBe(true));
    const tagInput = collect(bulkApplyTab, (n) => n.name === 'TagInput')[0];
    expect(evaluate(tagInput.props.disabled, locked)).toBe(true);
    expect(evaluate(byId(bulkApplyTab, 'bulk_apply_button').props.disabled, locked)).toBe(true);
    const reason = byId(bulkApplyTab, 'bulk_apply_no_permission');
    expect(evaluate(reason.if, locked)).toBe(true);
    expect(JSON.stringify(reason)).toContain('bulk_apply.no_permission');
  });
});

describe('게시판 유형 관리 (#4)', () => {
  /**
   * 등록 권한이 없으면 [게시판 유형 관리]·모달 안의 쓰기 컨트롤이 잠긴다.
   *
   * @scenario case=board_types_without_create
   *
   * @effects board_type_controls_locked_without_create
   */
  it('canManageBoardTypes=false 면 관리 버튼과 모달 쓰기 컨트롤이 잠긴다', () => {
    const button = byId(basicTab, 'settings_btn_manage_board_types');
    expect(evaluate(button.props.disabled, { _computed: { canManageBoardTypes: false } })).toBe(true);
    expect(evaluate(button.props.disabled, { _computed: { canManageBoardTypes: true } })).toBe(false);

    const parentLocked = { $parent: { _computed: { canManageBoardTypes: false } }, _global: { appConfig: {} }, $locale: 'ko' };
    const text = JSON.stringify(boardTypeModal);
    expect(text).not.toContain('_computed.isReadOnly');
    expect(evaluate(byId(boardTypeModal, 'bt_input_slug').props.disabled, parentLocked)).toBe(true);
    expect(evaluate(byId(boardTypeModal, 'bt_input_name').props.disabled, parentLocked)).toBe(true);
    expect(evaluate(byId(boardTypeModal, 'bt_submit_btn').props.disabled, parentLocked)).toBe(true);
  });
});

describe('일반 탭 날짜 형식 카드 (#6)', () => {
  /**
   * 읽기 전용이면 카드를 눌러도 값이 바뀌지 않는다.
   *
   * @scenario case=general_card_read_only
   *
   * @effects date_card_click_ignored_when_read_only
   */
  it('카드 click 의 setState 가 isReadOnly 면 실행되지 않는다', () => {
    for (const id of ['date_format_option_standard', 'date_format_option_relative']) {
      const click = actionsOf(byId(generalTab, id)).find((a: Node) => a.type === 'click');
      expect(evaluate(click.if, { _computed: { isReadOnly: true } }), id).toBe(false);
      expect(evaluate(click.if, { _computed: { isReadOnly: false } }), id).toBe(true);
    }
  });
});

describe('알림 정의 탭 채널 토글 (#5)', () => {
  /**
   * 채널 토글은 저장 경로(게시판 환경설정 PUT)와 같은 축(isReadOnly)으로 잠근다.
   *
   * @scenario case=channel_toggle_axis
   *
   * @effects channel_toggle_follows_settings_update
   */
  it('토글 disabled 가 isReadOnly 를 본다', () => {
    const toggle = collect(notificationDefinitionsTab, (n) => n.name === 'Toggle')[0];
    expect(toggle.props.disabled).toBe('{{_computed.isReadOnly}}');
  });
});

describe('반응 유형 드래그 손잡이 (#8, G10)', () => {
  /**
   * 읽기 전용이면 손잡이를 그리지 않고 정렬 결과도 반영하지 않는다.
   *
   * @scenario case=reaction_drag_read_only
   *
   * @effects drag_handle_hidden_when_read_only
   */
  it('손잡이 if 와 onSortEnd if 가 isReadOnly 면 false', () => {
    const handle = collect(reactionEditor, (n) => n.props?.['data-drag-handle'] === true)[0];
    expect(handle, '손잡이 노드가 없습니다').toBeTruthy();
    expect(evaluate(handle.if, { _computed: { isReadOnly: true } })).toBe(false);
    const sortEnd = collect(reactionEditor, (n) => n.event === 'onSortEnd')[0];
    expect(evaluate(sortEnd.if, { _computed: { isReadOnly: true } })).toBe(false);
    expect(evaluate(sortEnd.if, { _computed: { isReadOnly: false } })).toBe(true);
  });
});

describe('게시판 생성·복제 폼 (#1, #10)', () => {
  /**
   * 신규·복제 모드는 can_create 로 읽기 전용이 되고, 편집 모드는 종전대로 can_update 를 본다.
   *
   * @scenario case=form_create_without_create
   *
   * @effects form_create_read_only_without_create
   */
  it('isReadOnly 가 모드별 권한 키를 본다', () => {
    const expr = ((formLayout as Node).computed as Record<string, string>).isReadOnly;
    expect(evaluate(expr, { route: {}, boards: { data: { abilities: { can_create: false } } } })).toBe(true);
    expect(evaluate(expr, { route: {}, boards: { data: { abilities: { can_create: true } } } })).toBe(false);
    expect(evaluate(expr, { route: {}, boards: { data: {} } })).toBe(true);
    expect(evaluate(expr, { route: { slug: 'free' }, boards: { data: { abilities: { can_update: false, can_create: true } } } })).toBe(true);
    expect(evaluate(expr, { route: { slug: 'free' }, boards: { data: { abilities: { can_update: true } } } })).toBe(false);
    expect(JSON.stringify(byId(formLayout, 'read_only_banner'))).toContain('create_read_only_message');
  });

  /**
   * 폼의 반응 유형 [관리] 는 _meta.can_manage_reaction_types 로 잠긴다.
   *
   * @scenario case=form_reaction_manage_flag
   *
   * @effects form_reaction_manage_locked
   */
  it('반응 유형 [관리] 버튼이 _meta 플래그로 잠긴다', () => {
    const button = byId(formBasicTab, 'board_form_btn_manage_reaction_types');
    expect(evaluate(button.props.disabled, { _local: { form: { _meta: { can_manage_reaction_types: false } } } })).toBe(true);
    expect(evaluate(button.props.disabled, { _local: { form: { _meta: {} } } })).toBe(true);
    expect(evaluate(button.props.disabled, { _local: { form: { _meta: { can_manage_reaction_types: true } } } })).toBe(false);
  });
});

describe('확장점 (#9 G07, #12)', () => {
  /**
   * 문의 게시판 「관리자 메뉴에 추가」는 고른 게시판 행의 can_update 가 true 일 때만 열린다.
   *
   * @scenario case=inquiry_add_menu_without_update
   *
   * @effects inquiry_add_menu_locked_without_update
   */
  it('관리자 메뉴에 추가 버튼이 행 can_update 로 잠긴다', () => {
    const button = byId(inquiryExtension, 'inquiry_board_add_menu');
    const scope = (can: boolean | undefined) => ({
      settings: { data: { inquiry: { board_slug: 'qna' } } },
      board_list_for_inquiry: { data: { data: [{ slug: 'qna', abilities: can === undefined ? undefined : { can_update: can } }] } },
    });
    expect(evaluate(button.props.disabled, scope(false))).toBe(true);
    expect(evaluate(button.props.disabled, scope(undefined))).toBe(true);
    expect(evaluate(button.props.disabled, scope(true))).toBe(false);
  });

  /**
   * 대시보드 커뮤니티 구획·퀵메뉴 타일은 데이터·이동 화면과 같은 권한으로 그린다.
   *
   * @scenario case=dashboard_extension_permissions
   *
   * @effects dashboard_extension_rendered_by_permission
   */
  it('대시보드 확장 노드에 permissions 가 선언된다', () => {
    expect(byId(communityExtension, 'community_section_wrapper').permissions).toEqual(['sirsoft-board.dashboard.view']);
    expect(byId(quickMenuExtension, 'qm_boards').permissions).toEqual(['sirsoft-board.boards.read']);
    expect(byId(quickMenuExtension, 'qm_reports').permissions).toEqual(['sirsoft-board.reports.view']);
  });
});

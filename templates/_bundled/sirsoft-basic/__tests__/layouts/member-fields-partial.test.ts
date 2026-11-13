/**
 * @file member-fields-partial.test.ts
 * @description 회원 항목 반복 조각(partials/member/_member_fields) 구조 테스트
 *
 * 이 조각은 서버가 준 항목 계약을 돌며 칸을 그린다. 가입 화면, 내 정보 수정, 추가 정보 입력이 함께 쓰므로
 * 반복 원본(부르는 화면의 반복 변수 memberFieldGroup), 칸의 DOM id, 유형 갈래(주소, 파일, 확장 유형, 가입에서 받지 않는 유형),
 * 잠금 사유별 안내, 오류 줄 id 가 계약이다. 한 화면이 카드마다 여러 번 부르므로 조각 루트의 id 는 묶음 이름을 따른다.
 *
 * @scenario-manifest tests/scenarios/member-fields-partial.yaml
 */

import { describe, it, expect } from 'vitest';
import { actionsOf, callbacksOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import partial from '../../layouts/partials/member/_member_fields.json';

type Node = {
  id?: string;
  type?: string;
  name?: string;
  if?: string;
  props?: Record<string, any>;
  children?: Node[];
  iteration?: { source?: string; item_var?: string };
  text?: string;
  actions?: Array<Record<string, any>>;
  responsive?: Record<string, any>;
};

function collect(input: Node | Node[] | undefined, out: Node[] = []): Node[] {
  if (!input) return out;
  for (const node of Array.isArray(input) ? input : [input]) {
    out.push(node);
    collect(node.children, out);
  }
  return out;
}

/** 표현식({{ }})을 주어진 변수로 계산한다 */
function evaluate(expression: string, scope: Record<string, unknown>): unknown {
  const body = expression.replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(scope), 'return (' + body + ');')(...Object.values(scope));
}

const root = partial as Node;
const nodes = collect(root);
const row = (root.children ?? [])[0];

describe('회원 항목 반복 조각', () => {
  /**
   * @scenario part=root
   * @effects root_id_and_layout_follow_group
   */
  it('루트 노드 id 는 묶음 이름을 따르고(한 화면에 여러 번 놓인다), 칸을 늘어놓는 클래스는 묶음이 정한다', () => {
    expect(root.id).toBe('member_fields_group_{{memberFieldGroup.key}}');
    // 고정 id member_fields_root 는 가입 폼 조각의 감싸는 노드가 갖는다(반복 안에서는 id 가 겹친다)
    expect(nodes.some((node) => node.id === 'member_fields_root')).toBe(false);

    const className = root.props!.className as string;
    expect(evaluate(className, { memberFieldGroup: { key: 'contact' } })).toBe('space-y-4');
    expect(evaluate(className, { memberFieldGroup: { key: 'basic', className: 'grid grid-cols-2 gap-4' } })).toBe('grid grid-cols-2 gap-4');

    const portable = root.responsive!.portable.props.className as string;
    expect(evaluate(portable, { memberFieldGroup: { key: 'basic', className: 'grid grid-cols-2 gap-4', portableClassName: 'grid grid-cols-1 gap-3' } })).toBe('grid grid-cols-1 gap-3');
    expect(evaluate(portable, { memberFieldGroup: { key: 'contact' } })).toBe('space-y-4');
  });

  /**
   * @scenario part=iteration
   * @effects iterates_group_fields_except_agreements
   */
  it('반복 변수 memberFieldGroup 의 항목을 돌되 약관 묶음(ui.group = agreements)은 뺀다', () => {
    expect(row.iteration?.item_var).toBe('field');
    const fields = [
      { key: 'name', ui: { group: 'basic' } },
      { key: 'marketing', ui: { group: 'agreements' } },
      { key: 'job' },
    ];
    const result = evaluate(row.iteration!.source!, { memberFieldGroup: { key: 'signup', fields } }) as Array<{ key: string }>;
    expect(result.map((f) => f.key)).toEqual(['name', 'job']);
    expect(evaluate(row.iteration!.source!, { memberFieldGroup: undefined })).toEqual([]);
  });

  /**
   * @scenario part=row
   * @effects row_and_error_ids_follow_field_key
   */
  it('칸의 id 는 field_{키}, 오류 줄의 id 는 mf_error_{키} 다', () => {
    expect(row.id).toBe('field_{{field.key}}');
    const error = nodes.find((node) => node.id === 'mf_error_{{field.key}}');
    expect(error).toBeDefined();
    expect(error?.name).toBe('Span');
  });

  /**
   * @scenario part=label
   * @effects required_star_follows_field_required
   */
  it('필수 별표는 field.required 일 때만 그린다', () => {
    const star = nodes.find((node) => node.name === 'Span' && node.text === '*');
    expect(star?.if).toBe('{{field.required}}');
    expect(star?.props?.className).toContain('text-red-600');
    expect(star?.props?.className).toContain('dark:text-red-400');
  });

  /**
   * @scenario part=input
   * @effects builtin_input_binds_by_field_name
   */
  it('기본 입력칸은 MemberFieldInput 이고 name 을 field.name 으로 준다 (폼 자동 바인딩)', () => {
    const input = nodes.find((node) => node.name === 'MemberFieldInput');
    expect(input?.type).toBe('composite');
    expect(input?.props?.name).toBe('{{field.name}}');
    expect(input?.props?.field).toBe('{{field}}');
    expect(input?.props?.value).toBeUndefined();
    expect(input?.props?.errorId).toBe('mf_error_{{field.key}}');
    // 주소, 확장 유형, 파일, 가입에서 받지 않는 항목에서는 그리지 않는다
    const cases: Array<[Record<string, unknown>, boolean]> = [
      [{ renderer: 'builtin' }, true],
      [{ renderer: 'address' }, false],
      [{ renderer: 'extension' }, false],
      [{ renderer: 'file' }, false],
      [{ renderer: 'builtin', deferred: true }, false],
    ];
    for (const [field, shown] of cases) {
      expect(evaluate(input!.if!, { field })).toBe(shown);
    }
  });

  /**
   * @scenario part=input
   * @effects error_collects_sub_paths
   */
  it('입력칸에 넘기는 오류는 그 칸의 폼 경로와 하위 경로의 첫 오류다', () => {
    const input = nodes.find((node) => node.name === 'MemberFieldInput')!;
    const field = { name: 'custom_fields.period' };
    const errors = {
      'custom_fields.other': ['다른 칸'],
      'custom_fields.period.end': ['끝나는 날이 시작보다 빠릅니다'],
    };
    expect(evaluate(input.props!.error, { field, $computed: { memberFieldCtx: { errors } } })).toBe('끝나는 날이 시작보다 빠릅니다');
    expect(evaluate(input.props!.error, { field, $computed: { memberFieldCtx: { errors: {} } } })).toBe('');
  });

  /**
   * @scenario part=address
   * @effects address_parts_bind_by_parts_paths
   */
  it('주소 갈래는 부분별 폼 경로(field.parts)로 세 칸을 잇고 주소 검색 확장점에 같은 경로를 넘긴다', () => {
    const inputs = nodes.filter((node) => node.name === 'Input' && node.props?.type === 'text');
    expect(inputs.map((node) => node.props?.name)).toEqual([
      '{{field.parts?.zipcode}}',
      '{{field.parts?.address}}',
      '{{field.parts?.address_detail}}',
    ]);
    const slot = nodes.find((node) => node.type === 'extension_point' && node.name === 'address_search_slot');
    expect(slot).toBeDefined();
    expect(slot?.props?.readOnlyFields).toEqual(['zipcode', 'address']);
    const scope = { field: { parts: { zipcode: 'custom_fields.addr.zipcode', address: 'custom_fields.addr.address' } }, $computed: { memberFieldCtx: { formKey: 'registerForm' } } };
    expect(evaluate(slot!.props!.targetFields.zipcode, scope)).toBe('registerForm.custom_fields.addr.zipcode');
    expect(evaluate(slot!.props!.targetFields.address, scope)).toBe('registerForm.custom_fields.addr.address');
    // 시도, 시군구는 폼 최상위 경로로 받는다(내 정보 수정이 종전에 넘기던 경로)
    expect(evaluate(slot!.props!.targetFields.region, scope)).toBe('registerForm.region');
    expect(evaluate(slot!.props!.targetFields.city, scope)).toBe('registerForm.city');
  });

  /**
   * @scenario part=address
   * @effects locked_address_is_readonly_without_search
   */
  it('잠긴 주소 항목은 세 칸 모두 읽기 전용 회색이고 주소 검색 단추를 그리지 않는다', () => {
    const inputs = nodes.filter((node) => node.name === 'Input' && node.props?.type === 'text');
    const slot = nodes.find((node) => node.type === 'extension_point' && node.name === 'address_search_slot')!;
    const locked = { field: { readonly: true }, _global: {} };
    const open = { field: { readonly: false }, _global: {} };
    for (const input of inputs) {
      expect(evaluate(input.props!.readOnly, locked)).toBe(true);
      expect(evaluate(input.props!.className, locked)).toContain('bg-gray-50');
      expect(evaluate(input.props!.className, locked)).toContain('cursor-not-allowed');
      expect(evaluate(input.props!.className, open)).toContain('bg-white');
    }
    // 상세 주소는 잠기지 않았으면 늘 직접 입력한다
    expect(evaluate(inputs[2].props!.readOnly, open)).toBe(false);
    // 앞의 두 칸은 우편번호 검색 플러그인이 켜져 있으면 검색으로만 채운다
    expect(evaluate(inputs[0].props!.readOnly, { field: {}, _global: { activePlugins: ['sirsoft-daum_postcode'] } })).toBe(true);
    expect(evaluate(inputs[0].props!.readOnly, open)).toBe(false);
    expect(evaluate(slot.if!, locked)).toBe(false);
    expect(evaluate(slot.if!, open)).toBe(true);
  });

  /**
   * @scenario part=locked
   * @effects locked_reason_picks_one_notice
   */
  it('잠긴 항목은 사유에 맞는 안내 문구 하나만 보인다 (회사 계정 잠금은 기존 문구 그대로)', () => {
    const notices: Record<string, Node> = {
      sso: nodes.find((node) => node.text === '$t:mypage.profile.locked_by_company')!,
      identity: nodes.find((node) => node.text === '$t:member.fields.locked_identity')!,
      admin_only: nodes.find((node) => node.text === '$t:member.fields.locked_admin_only')!,
      nickname_interval: nodes.find((node) => node.text === '$t:member.fields.locked_until|date={{field.locked_until}}')!,
      other: nodes.find((node) => node.text === '$t:member.fields.locked_generic')!,
    };
    for (const notice of Object.values(notices)) {
      expect(notice).toBeDefined();
      expect(notice.props?.className).toContain('text-xs text-gray-500 dark:text-gray-400');
    }

    const shown = (field: Record<string, unknown>) =>
      Object.entries(notices)
        .filter(([, node]) => evaluate(node.if!, { field }) === true)
        .map(([reason]) => reason);

    expect(shown({ readonly: true, locked_reason: 'sso' })).toEqual(['sso']);
    expect(shown({ readonly: true, locked_reason: 'identity' })).toEqual(['identity']);
    expect(shown({ readonly: true, locked_reason: 'admin_only' })).toEqual(['admin_only']);
    expect(shown({ readonly: true, locked_reason: 'nickname_interval', locked_until: '2026-12-11 17:23' })).toEqual(['nickname_interval']);
    // 날짜가 없는 주기 잠금, 사유를 주지 않은 잠금, 모르는 사유는 일반 문구
    expect(shown({ readonly: true, locked_reason: 'nickname_interval', locked_until: null })).toEqual(['other']);
    expect(shown({ readonly: true, locked_reason: 'locked' })).toEqual(['other']);
    expect(shown({ readonly: true, locked_reason: null })).toEqual(['other']);
    // 잠기지 않은 항목은 아무 문구도 없다
    expect(shown({ readonly: false, locked_reason: null })).toEqual([]);
    expect(shown({ readonly: false, locked_reason: 'sso' })).toEqual([]);
  });

  /**
   * @scenario part=file
   * @effects file_branch_lists_files_and_gates_add_by_count
   */
  it('파일 갈래는 올라간 파일 목록을 그리고, 개수가 남았고 잠기지 않았을 때만 「파일 추가」를 보인다', () => {
    const branch = nodes.find((node) => node.id === 'mf_files_{{field.key}}')!;
    expect(evaluate(branch.if!, { field: { renderer: 'file' } })).toBe(true);
    expect(evaluate(branch.if!, { field: { renderer: 'file', deferred: true } })).toBe(false);
    expect(evaluate(branch.if!, { field: { renderer: 'builtin' } })).toBe(false);

    const item = nodes.find((node) => node.id === 'mf_file_{{field.key}}_{{fieldFile.id}}')!;
    expect(item.iteration?.item_var).toBe('fieldFile');
    expect(evaluate(item.iteration!.source!, { field: { files: [{ id: 1 }] } })).toEqual([{ id: 1 }]);
    expect(evaluate(item.iteration!.source!, { field: { files: null } })).toEqual([]);

    // 이름을 누르면 로그인 토큰이 실리는 경로로 내려받는다
    const download = collect(item).find((node) => actionsOf(node)[0]?.handler === 'downloadAttachment')!;
    expect(download.name).toBe('Button');
    expect(actionsOf(download)[0].params).toEqual({ url: '{{fieldFile.url}}', filename: '{{fieldFile.name}}' });

    const add = nodes.find((node) => node.id === 'mf_file_add_{{field.key}}')!;
    const rules = { max_count: 2 };
    expect(evaluate(add.if!, { field: { files: [{ id: 1 }], file_rules: rules } })).toBe(true);
    expect(evaluate(add.if!, { field: { files: [{ id: 1 }, { id: 2 }], file_rules: rules } })).toBe(false);
    expect(evaluate(add.if!, { field: { files: [], file_rules: rules, readonly: true } })).toBe(false);
    // 제한 값이 없으면 한 개까지
    expect(evaluate(add.if!, { field: { files: null } })).toBe(true);
    expect(evaluate(add.if!, { field: { files: [{ id: 1 }] } })).toBe(false);

    // 읽기 전용(관리자만 수정) 항목은 삭제 단추도 없다
    const remove = collect(item).find((node) => String(node.props?.['aria-label'] ?? '').startsWith('$t:member.fields.file_remove'))!;
    expect(evaluate(remove.if!, { field: { readonly: true } })).toBe(false);
    expect(evaluate(remove.if!, { field: { readonly: false } })).toBe(true);
  });

  /**
   * @scenario part=file
   * @effects file_upload_posts_multipart_and_refetches_fields
   */
  it('파일을 고르면 multipart 로 바로 올리고 성공하면 항목을 다시 받는다', () => {
    const add = nodes.find((node) => node.id === 'mf_file_add_{{field.key}}')!;
    const input = collect(add).find((node) => node.name === 'Input')!;
    expect(input.props?.type).toBe('file');
    expect(input.props?.['aria-label']).toContain('$t:member.fields.file_add_label');
    expect(evaluate(input.props!.accept, { field: { file_rules: { extensions: ['pdf', 'png'] } } })).toBe('.pdf,.png');
    expect(evaluate(input.props!.accept, { field: {} })).toBe('');

    const upload = actionsOf(input)[0];
    expect(upload.type).toBe('change');
    expect(upload.handler).toBe('apiCall');
    expect(upload.target).toBe('/api/me/fields/{{field.key}}/file');
    expect(upload.auth_required).toBe(true);
    expect(upload.params.method).toBe('POST');
    expect(upload.params.contentType).toBe('multipart/form-data');

    const picked = { name: 'biz.pdf' };
    expect(evaluate(upload.params.body, { $event: { target: { files: [picked] } } })).toEqual({ file: picked });
    // 고르기를 취소하면(파일 없음) 보내지 않는다
    expect(evaluate(upload.if, { $event: { target: { files: [] } } })).toBe(false);
    expect(evaluate(upload.if, { $event: { target: { files: [picked] } } })).toBe(true);

    const uploaded = callbacksOf(upload, 'onSuccess');
    expect(uploaded.map((action) => action.handler)).toEqual(['refetchDataSource', 'toast']);
    expect(uploaded[0].params.dataSourceId).toBe('profileFields');
    // 거절 사유(확장자, 크기, 개수)는 서버 문구를 그대로 알린다
    const failMessage = callbacksOf(upload, 'onError').find((action) => action.handler === 'toast')!.params.message;
    expect(evaluate(failMessage, { error: { errors: { file: ['pdf 만 올릴 수 있습니다'] }, message: '검증 실패' } })).toBe('pdf 만 올릴 수 있습니다');
    expect(evaluate(failMessage, { error: { message: '서버 오류' } })).toBe('서버 오류');
  });

  /**
   * @scenario part=file
   * @effects file_delete_sends_attachment_id_and_refetches_fields
   */
  it('삭제 단추는 attachment_id 를 본문에 실어 DELETE 를 보내고 성공하면 항목을 다시 받는다', () => {
    const item = nodes.find((node) => node.id === 'mf_file_{{field.key}}_{{fieldFile.id}}')!;
    const remove = collect(item).find((node) => String(node.props?.['aria-label'] ?? '').startsWith('$t:member.fields.file_remove'))!;
    expect(remove.name).toBe('Button');
    expect(remove.props?.type).toBe('button');

    const sequence = actionsOf(remove)[0];
    expect(sequence.type).toBe('click');
    expect(sequence.handler).toBe('sequence');
    const [busy, call] = callbacksOf(sequence, 'actions');
    expect(busy.params.memberFieldFileBusy).toBe('{{field.key}}');
    expect(call.handler).toBe('apiCall');
    expect(call.target).toBe('/api/me/fields/{{field.key}}/file');
    expect(call.params.method).toBe('DELETE');
    expect(call.params.body).toEqual({ attachment_id: '{{fieldFile.id}}' });
    const removed = callbacksOf(call, 'onSuccess');
    expect(removed.map((action) => action.handler)).toEqual(['setState', 'refetchDataSource', 'toast']);
    expect(removed[1].params.dataSourceId).toBe('profileFields');
    // 지우는 동안에는 그 항목의 삭제 단추가 잠기고, 끝나면(성공, 실패 모두) 풀린다
    expect(evaluate(remove.props!.disabled, { _local: { memberFieldFileBusy: 'biz' }, field: { key: 'biz' } })).toBe(true);
    expect(evaluate(remove.props!.disabled, { _local: { memberFieldFileBusy: null }, field: { key: 'biz' } })).toBe(false);
    expect(removed[0].params.memberFieldFileBusy).toBeNull();
    expect(callbacksOf(call, 'onError')[0].params.memberFieldFileBusy).toBeNull();
  });

  /**
   * @scenario part=extension
   * @effects extension_type_slot_receives_field
   */
  it('확장 유형 갈래는 확장점 member_field_input 에 항목 계약, 오류, 비활성 여부, 폼 이름을 넘긴다', () => {
    const slot = nodes.find((node) => node.type === 'extension_point' && node.name === 'member_field_input');
    expect(slot).toBeDefined();
    expect(Object.keys(slot!.props ?? {}).sort()).toEqual(['disabled', 'error', 'field', 'formKey']);
    expect(slot!.props!.field).toBe('{{field}}');
    expect(evaluate(slot!.if!, { field: { renderer: 'extension' } })).toBe(true);
    expect(evaluate(slot!.if!, { field: { renderer: 'builtin' } })).toBe(false);
  });

  /**
   * @scenario part=deferred
   * @effects deferred_field_shows_notice_only
   */
  it('가입에서 받지 않는 항목은 안내 줄만 그린다', () => {
    const notice = nodes.find((node) => node.id === 'mf_deferred_{{field.key}}');
    expect(notice?.if).toBe('{{field.deferred}}');
    expect(notice?.text).toBe('$t:member.fields.upload_after_signup');
  });

  /**
   * @scenario part=dark
   * @effects every_color_class_has_dark_pair
   */
  it('글자색, 테두리, 배경 클래스에 다크 짝이 있다', () => {
    for (const node of nodes) {
      const className = String(node.props?.className ?? '');
      if (/\btext-(gray|red)-\d+/.test(className)) expect(className).toMatch(/dark:text-/);
      if (/\bbg-white\b/.test(className)) expect(className).toMatch(/dark:bg-/);
      if (/\bborder-gray-\d+/.test(className)) expect(className).toMatch(/dark:border-/);
    }
  });
  /**
   * @scenario part=agreement
   * @effects agreement_meta_shows_required_and_gated_link
   */
  it('동의 항목(약관 묶음)은 필수 여부를 글자로 알리고, 약관 링크는 그 문서를 서빙하는 모듈이 켜져 있을 때만 새 창으로 연다. 하위 동의는 들여 쓴다', () => {
    const meta = nodes.find((node) => node.id === 'mf_agreement_meta_{{field.key}}')!;
    expect(evaluate(meta.if!, { field: { ui: { group: 'agreements' } } })).toBe(true);
    expect(evaluate(meta.if!, { field: { ui: { group: 'basic' } } })).toBe(false);
    expect(evaluate(meta.if!, { field: {} })).toBe(false);

    const [state, link] = meta.children!;
    const $t = (key: string) => key;
    expect(evaluate(state.text!, { field: { required: true }, $t })).toBe('member.fields.agreement_required');
    expect(evaluate(state.text!, { field: { required: false }, $t })).toBe('member.fields.agreement_optional');

    const withLink = { ui: { link: { url: '/page/marketing', label: '보기', title: '마케팅 동의 약관', requires_module: 'sirsoft-page' } }, label: '마케팅 동의' };
    expect(link.id).toBe('mf_agreement_link_{{field.key}}');
    expect(link.props!.target).toBe('_blank');
    expect(link.props!.rel).toBe('noopener noreferrer');
    expect(evaluate(link.if!, { field: withLink, _global: { activeModules: ['sirsoft-page'] } })).toBe(true);
    expect(evaluate(link.if!, { field: withLink, _global: { activeModules: [] } })).toBe(false);
    // 활성 모듈 목록을 주지 않는 옛 코어에서는 그린다
    expect(evaluate(link.if!, { field: withLink, _global: {} })).toBe(true);
    // 약관 문서를 정하지 않은 동의에는 링크가 없다
    expect(evaluate(link.if!, { field: { ui: {} }, _global: {} })).toBe(false);
    expect(evaluate(link.props!.href, { field: withLink })).toBe('/page/marketing');
    expect(evaluate(link.props!['aria-label'], { field: withLink })).toBe('마케팅 동의 약관');

    expect(evaluate(row.props!.className, { field: { ui: { parent: 'marketing_consent' } } })).toBe('ml-6');
    expect(evaluate(row.props!.className, { field: { ui: {} } })).toBe('');
  });
});

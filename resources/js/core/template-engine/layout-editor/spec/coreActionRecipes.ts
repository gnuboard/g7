/**
 * coreActionRecipes.ts — 코어 내장 핸들러 스펙 카탈로그
 *
 * 코어가 제공하는(빌트인) 핸들러의 친화 스펙(actionRecipes) 카탈로그. editorSpecLoader
 * 가 이 카탈로그를 **병합 base(코어 시드)** 로 주입하면, 그 위에 module → plugin →
 * template 이 자기 핸들러 스펙을 더하거나 라벨을 덮는다(코어 기본 → module →
 * plugin → template).
 *
 * 레이아웃은 새 핸들러를 선언할 수 없으므로, 유저가 하는 일은 `① 등록된
 * 핸들러 선택 → ② 순서 배치 → ③ 스펙이 허용한 값 채우기`다. 각 스펙은
 * 표를 SSoT 로 한다(C1~C27). **핸들러명/params 키는 사용자 미노출** — 친화 라벨·입력칸만.
 *
 * 친화 폼 밖 부분(apiCall 의 onSuccess/onError 중첩 트리·sequence/parallel 다단 트리)은
 * `advanced:true` 로 [고급] 잠금(코드 편집 위임). 의 관리/내부 핸들러는 의도적
 * 미정의(스펙 비대상).
 *
 * 모든 친화 라벨·params 라벨은 `$t:layout_editor.action_recipe.*` 다국어 키다(평문 spec 박기
 * 금지 — audit 룰). 키의 ko/en 값은 `lang/partial/{ko,en}/layout_editor.json` 의
 * `action_recipe` 그룹. (편집기 t() 는 `layout_editor.*` 네임스페이스만 해석 — `core.editor.*` 는
 * 로딩 네임스페이스 부재로 원문 누출됐다. 세션 D 검수에서 정정, D-G.)
 *
 * @since engine-v1.50.0
 */

import type { ActionRecipeSpec } from './specTypes';

/** 친화 라벨 다국어 키 prefix — 편집기 t() 는 `layout_editor.*` 네임스페이스를 해석한다(코어 partial
 *  lang/partial/{ko,en}/layout_editor.json). `core.editor.*` 는 로딩 네임스페이스가 없어 원문 누출됐다(D-G). */
const L = 'layout_editor.action_recipe';

/**
 * 코어 핸들러 스펙 카탈로그 — C1~C27.
 *
 * key = 핸들러명(레시피 id). 같은 key 를 확장이 override 할 수 있다(라벨 보강 등).
 * 각 항목의 `build.handler` 가 실제 디스패치될 핸들러다.
 */
export const CORE_ACTION_RECIPES: Record<string, ActionRecipeSpec> = {
  // ── 상태 (C1~C3) ──
  // C1 화면 상태 채우기/바꾸기 — 깊은 중첩 payload 는 키–값 행 한정(고급 경계)
  setState: {
    label: `$t:${L}.set_state.label`,
    params: [
      {
        key: 'target',
        label: `$t:${L}.set_state.param_target`,
        widget: 'select',
        options: [{ value: 'local' }, { value: 'global' }],
      },
      // 상태 키–값 행 — state-key-picker(키) + DataChipValueInput(값: 데이터칩/표현식/평문).
      // setState 의 payload 는 동적 키 맵이라 build 의 스프레드 키(`"..."`)로 그대로 params 에
      // 펼친다(actionRecipeEngine SPREAD_KEY — target/merge 명시 키 외 나머지는 이 맵으로 역추출).
      // 깊은 중첩 값은 DataChipValueInput 안에서 표현식으로(틀 밖 복잡 트리는 [고급] 코드 보기).
      { key: 'state', label: `$t:${L}.set_state.param_state`, widget: 'state-key-value' },
      { key: 'merge', label: `$t:${L}.set_state.param_merge`, widget: 'toggle' },
    ],
    // 실데이터 shape: `{ handler:'setState', params:{ target, merge, ...상태키맵 } }`(target 도
    // params 안 — 번들 setState 전수 일치). 스프레드(`"..."`)가 state 맵을 params 로 흡수한다.
    build: { handler: 'setState', params: { target: '{{target}}', merge: '{{merge}}', '...': '{{state}}' } },
  },
  // C2 저장된 설정 불러오기
  loadFromLocalStorage: {
    label: `$t:${L}.load_local.label`,
    params: [
      { key: 'key', label: `$t:${L}.load_local.param_key`, widget: 'text', required: true },
      {
        key: 'stateKey',
        label: `$t:${L}.load_local.param_state_key`,
        widget: 'state-key-picker',
      },
      { key: 'defaultValue', label: `$t:${L}.load_local.param_default`, widget: 'text' },
    ],
    build: {
      handler: 'loadFromLocalStorage',
      params: { key: '{{key}}', stateKey: '{{stateKey}}', defaultValue: '{{defaultValue}}' },
    },
  },
  // C3 설정 저장하기 — value 는 저장할 "데이터 값"(배열/식)이라 data-chip(DataChipValueInput:
  // 칩+표현식 분해 트리). i18n-text(번역키용 prose)는 복잡식을 read-only 디그레이드해 부적합
  // 사용자 표시 문구가 아닌 데이터 값.
  saveToLocalStorage: {
    label: `$t:${L}.save_local.label`,
    params: [
      { key: 'key', label: `$t:${L}.save_local.param_key`, widget: 'text', required: true },
      { key: 'value', label: `$t:${L}.save_local.param_value`, widget: 'data-chip' },
    ],
    build: { handler: 'saveToLocalStorage', params: { key: '{{key}}', value: '{{value}}' } },
  },

  // ── 이동/창 (C4~C8) ──
  // C4 다른 페이지로 이동 — transition_overlay_target 은 replace=true 시만 유효
  navigate: {
    label: `$t:${L}.navigate.label`,
    params: [
      { key: 'path', label: `$t:${L}.navigate.param_path`, widget: 'page-picker', required: true },
      { key: 'query', label: `$t:${L}.navigate.param_query`, widget: 'key-value' },
      { key: 'mergeQuery', label: `$t:${L}.navigate.param_merge_query`, widget: 'toggle' },
      {
        key: 'scroll',
        label: `$t:${L}.navigate.param_scroll`,
        widget: 'select',
        options: [{ value: 'top' }, { value: 'preserve' }, { value: 'none' }],
      },
      { key: 'replace', label: `$t:${L}.navigate.param_replace`, widget: 'toggle' },
      {
        key: 'transition_overlay_target',
        label: `$t:${L}.navigate.param_overlay_target`,
        widget: 'component-target-picker',
        // replace=true 일 때만 노출(폼이 dependsOn 으로 게이팅). C4 주.
        dependsOn: { param: 'replace', equals: true },
      },
    ],
    build: {
      handler: 'navigate',
      params: {
        path: '{{path}}',
        query: '{{query}}',
        mergeQuery: '{{mergeQuery}}',
        scroll: '{{scroll}}',
        replace: '{{replace}}',
        transition_overlay_target: '{{transition_overlay_target}}',
      },
    },
  },
  // C5 새 창으로 열기
  openWindow: {
    label: `$t:${L}.open_window.label`,
    params: [
      { key: 'target', label: `$t:${L}.open_window.param_target`, widget: 'page-picker', required: true },
      {
        key: 'windowTarget',
        label: `$t:${L}.open_window.param_window_target`,
        widget: 'select',
        options: [{ value: '_blank' }, { value: '_self' }],
      },
    ],
    build: { handler: 'openWindow', target: '{{target}}', params: { windowTarget: '{{windowTarget}}' } },
  },
  // C6 뒤로 가기
  navigateBack: { label: `$t:${L}.navigate_back.label`, params: [], build: { handler: 'navigateBack' } },
  // C7 앞으로 가기
  navigateForward: {
    label: `$t:${L}.navigate_forward.label`,
    params: [],
    build: { handler: 'navigateForward' },
  },
  // C8 URL만 바꾸기(새로고침 없이)
  replaceUrl: {
    label: `$t:${L}.replace_url.label`,
    params: [
      { key: 'path', label: `$t:${L}.replace_url.param_path`, widget: 'page-picker' },
      { key: 'query', label: `$t:${L}.replace_url.param_query`, widget: 'key-value' },
    ],
    build: { handler: 'replaceUrl', params: { path: '{{path}}', query: '{{query}}' } },
  },

  // ── 알림/모달 (C9~C13) ──
  // C9 안내 메시지 보여주기
  toast: {
    label: `$t:${L}.toast.label`,
    params: [
      { key: 'message', label: `$t:${L}.toast.param_message`, widget: 'i18n-text', required: true },
      {
        key: 'type',
        label: `$t:${L}.toast.param_type`,
        widget: 'select',
        options: [{ value: 'info' }, { value: 'success' }, { value: 'warning' }, { value: 'error' }],
      },
      { key: 'duration', label: `$t:${L}.toast.param_duration`, widget: 'number' },
      { key: 'icon', label: `$t:${L}.toast.param_icon`, widget: 'text' },
    ],
    build: {
      handler: 'toast',
      params: { message: '{{message}}', type: '{{type}}', duration: '{{duration}}', icon: '{{icon}}' },
    },
  },
  // C10 알림창(모달) 열기 — target = 레이아웃 modals 선택(modal-picker). 후보 미전달 환경(컴포넌트
  // [동작] 탭 등)에서는 picker 가 자유 입력 폴백으로 디그레이드한다(후보 없으면 평문 입력).
  openModal: {
    label: `$t:${L}.open_modal.label`,
    params: [{ key: 'target', label: `$t:${L}.open_modal.param_target`, widget: 'modal-picker', required: true }],
    build: { handler: 'openModal', target: '{{target}}' },
  },
  // C11 알림창(모달) 닫기
  closeModal: { label: `$t:${L}.close_modal.label`, params: [], build: { handler: 'closeModal' } },
  // C12 브라우저 알림 표시
  showAlert: {
    label: `$t:${L}.show_alert.label`,
    params: [{ key: 'target', label: `$t:${L}.show_alert.param_message`, widget: 'i18n-text', required: true }],
    build: { handler: 'showAlert', target: '{{target}}' },
  },
  // C13 에러 상태 설정
  setError: {
    label: `$t:${L}.set_error.label`,
    params: [
      { key: 'target', label: `$t:${L}.set_error.param_message`, widget: 'i18n-text' },
      {
        key: 'stateTarget',
        label: `$t:${L}.set_error.param_state_target`,
        widget: 'select',
        options: [{ value: 'local' }, { value: 'global' }],
      },
    ],
    build: { handler: 'setError', target: '{{target}}', params: { stateTarget: '{{stateTarget}}' } },
  },

  // ── 데이터 (C14~C16) ──
  // C14 목록 새로고침
  refetchDataSource: {
    label: `$t:${L}.refetch.label`,
    params: [
      {
        key: 'dataSourceId',
        label: `$t:${L}.refetch.param_data_source`,
        widget: 'datasource-picker',
        required: true,
      },
      { key: 'sync', label: `$t:${L}.refetch.param_sync`, widget: 'toggle' },
    ],
    build: { handler: 'refetchDataSource', params: { dataSourceId: '{{dataSourceId}}', sync: '{{sync}}' } },
  },
  // C15 목록에 데이터 더하기 (무한스크롤 맥락)
  appendDataSource: {
    label: `$t:${L}.append_data.label`,
    params: [
      {
        key: 'dataSourceId',
        label: `$t:${L}.append_data.param_data_source`,
        widget: 'datasource-picker',
        required: true,
      },
      { key: 'dataPath', label: `$t:${L}.append_data.param_data_path`, widget: 'text' },
      { key: 'newData', label: `$t:${L}.append_data.param_new_data`, widget: 'data-chip' },
    ],
    build: {
      handler: 'appendDataSource',
      params: { dataSourceId: '{{dataSourceId}}', dataPath: '{{dataPath}}', newData: '{{newData}}' },
    },
  },
  // C16 목록 데이터 바꾸기
  updateDataSource: {
    label: `$t:${L}.update_data.label`,
    params: [
      {
        key: 'dataSourceId',
        label: `$t:${L}.update_data.param_data_source`,
        widget: 'datasource-picker',
        required: true,
      },
      { key: 'data', label: `$t:${L}.update_data.param_data`, widget: 'data-chip' },
      { key: 'merge', label: `$t:${L}.update_data.param_merge`, widget: 'toggle' },
    ],
    build: {
      handler: 'updateDataSource',
      params: { dataSourceId: '{{dataSourceId}}', data: '{{data}}', merge: '{{merge}}' },
    },
  },

  // ── 기타 (C17~C22) ──
  // C17 특정 위치로 스크롤
  scrollIntoView: {
    label: `$t:${L}.scroll.label`,
    params: [
      { key: 'selector', label: `$t:${L}.scroll.param_selector`, widget: 'text', required: true },
      {
        key: 'behavior',
        label: `$t:${L}.scroll.param_behavior`,
        widget: 'select',
        options: [{ value: 'smooth' }, { value: 'auto' }],
      },
      {
        key: 'block',
        label: `$t:${L}.scroll.param_block`,
        widget: 'select',
        options: [{ value: 'start' }, { value: 'center' }, { value: 'end' }, { value: 'nearest' }],
      },
    ],
    build: {
      handler: 'scrollIntoView',
      params: { selector: '{{selector}}', behavior: '{{behavior}}', block: '{{block}}' },
    },
  },
  // C18 로그인 처리
  login: {
    label: `$t:${L}.login.label`,
    params: [{ key: 'body', label: `$t:${L}.login.param_body`, widget: 'key-value' }],
    build: { handler: 'login', params: { body: '{{body}}' } },
  },
  // C19 로그아웃 처리
  logout: {
    label: `$t:${L}.logout.label`,
    params: [{ key: 'target', label: `$t:${L}.logout.param_target`, widget: 'text' }],
    build: { handler: 'logout', target: '{{target}}' },
  },
  // C20 언어 바꾸기
  setLocale: {
    label: `$t:${L}.set_locale.label`,
    params: [{ key: 'target', label: `$t:${L}.set_locale.param_target`, widget: 'locale-picker', required: true }],
    build: { handler: 'setLocale', target: '{{target}}' },
  },
  // C21 컴포넌트 이벤트 보내기
  emitEvent: {
    label: `$t:${L}.emit_event.label`,
    params: [
      { key: 'event', label: `$t:${L}.emit_event.param_event`, widget: 'text', required: true },
      { key: 'data', label: `$t:${L}.emit_event.param_data`, widget: 'data-chip' },
    ],
    build: { handler: 'emitEvent', params: { event: '{{event}}', data: '{{data}}' } },
  },
  // C22 서버에 보내고 결과 처리 — onSuccess/onError 는 친화 중첩 액션 빌더(action-list)로 편집.
  // 응답 후속 동작(결제 진입·상태 저장·이동 등)을 코드 없이 추가/순서/속성 편집한다.
  apiCall: {
    label: `$t:${L}.api_call.label`,
    params: [
      { key: 'target', label: `$t:${L}.api_call.param_target`, widget: 'page-picker', required: true },
      {
        key: 'method',
        label: `$t:${L}.api_call.param_method`,
        widget: 'select',
        options: [{ value: 'GET' }, { value: 'POST' }, { value: 'PUT' }, { value: 'PATCH' }, { value: 'DELETE' }],
      },
      // body 는 항상 필드 맵({temp_order_id, orderer, ...})이라 key-value 위젯으로 키별 데이터 칩 편집.
      // data-chip(스칼라/표현식 입력)으로 객체를 받으면 [object Object] 또는 JSON 분해 깨짐이 생긴다.
      { key: 'body', label: `$t:${L}.api_call.param_body`, widget: 'key-value' },
      { key: 'query', label: `$t:${L}.api_call.param_query`, widget: 'key-value' },
      {
        key: 'identity_target_email',
        label: `$t:${L}.api_call.param_identity_target_email`,
        widget: 'data-chip',
      },
      {
        key: 'identity_target_phone',
        label: `$t:${L}.api_call.param_identity_target_phone`,
        widget: 'data-chip',
      },
      { key: 'onSuccess', label: `$t:${L}.api_call.param_on_success`, widget: 'action-list' },
      { key: 'onError', label: `$t:${L}.api_call.param_on_error`, widget: 'action-list' },
    ],
    build: {
      handler: 'apiCall',
      target: '{{target}}',
      // identity_target: IDV 428 인터셉트 시 인증 대상(이메일·전화). 액션 최상위 속성.
      // email/phone 둘 다 미입력이면 substituteValue 가 빈 객체를 떨궈 키 자체가 사라진다.
      identity_target: { email: '{{identity_target_email}}', phone: '{{identity_target_phone}}' },
      onSuccess: '{{onSuccess}}',
      onError: '{{onError}}',
      params: { method: '{{method}}', body: '{{body}}', query: '{{query}}' },
    },
  },

  // ── 제어 흐름 (C24~C28) — C23 top-level if 는 모든 핸들러 공통 옵션(엔진 처리) ──
  // C29 조건에 따라 분기 — 각 분기는 실행조건(if) + 동작(then). 첫 매칭 분기만 실행(if/else-if/else).
  // branches 는 `[{if?, then}]` 배열이라 action-list(평면 액션) 가 아닌 branch-list 전용 위젯으로
  // 분기별 조건·동작을 친화 편집한다. conditions 는 액션 최상위 키(handleConditions 가 action.conditions
  // 만 읽음 — params 아래 두면 런타임 미인식). switch(값 매핑)와 달리 조건식 분기.
  conditions: {
    label: `$t:${L}.conditions.label`,
    params: [
      { key: 'branches', label: `$t:${L}.conditions.param_branches`, widget: 'branch-list' },
    ],
    build: { handler: 'conditions', conditions: '{{branches}}' },
  },
  // C24 차례로 여러 동작 — 친화 중첩 액션 빌더(action-list)로 단계 추가/순서/속성 편집.
  sequence: {
    label: `$t:${L}.sequence.label`,
    params: [{ key: 'actions', label: `$t:${L}.sequence.param_actions`, widget: 'action-list' }],
    build: { handler: 'sequence', params: { actions: '{{actions}}' } },
  },
  // C25 동시에 여러 동작 — 친화 중첩 액션 빌더.
  parallel: {
    label: `$t:${L}.parallel.label`,
    params: [{ key: 'actions', label: `$t:${L}.parallel.param_actions`, widget: 'action-list' }],
    build: { handler: 'parallel', params: { actions: '{{actions}}' } },
  },
  // C26 값에 따라 분기 — cases 트리는 [고급]
  switch: {
    label: `$t:${L}.switch.label`,
    params: [
      { key: 'value', label: `$t:${L}.switch.param_value`, widget: 'data-chip' },
      { key: 'cases', label: `$t:${L}.switch.param_cases`, widget: 'action-list', advanced: true },
    ],
    build: { handler: 'switch', params: { value: '{{value}}', cases: '{{cases}}' } },
  },
  // C27 아무것도 안 함(에러 억제)
  suppress: { label: `$t:${L}.suppress.label`, params: [], build: { handler: 'suppress' } },
};

/**
 * 코어 시드 스펙으로 노출 — editorSpecLoader `coreSeed` 에 주입.
 *
 * actionRecipes(컴포넌트 [동작] 탭)·initActionRecipes([화면 동작] 탭)·errorRecipes
 * ([에러 처리] 탭) 세 블록 모두 코어 핸들러 스펙을 공유한다(같은 핸들러 카탈로그).
 * 로더가 이 시드를 base 로 병합하고 `__source:{kind:'core'}` 를 부착한다.
 *
 * @return coreSeed 로 쓸 부분 EditorSpec
 */
export function buildCoreActionRecipeSeed(): {
  actionRecipes: Record<string, ActionRecipeSpec>;
  initActionRecipes: Record<string, ActionRecipeSpec>;
  errorRecipes: Record<string, ActionRecipeSpec>;
} {
  return {
    actionRecipes: CORE_ACTION_RECIPES,
    initActionRecipes: CORE_ACTION_RECIPES,
    // [에러 처리] 탭 친화 동작 7종(-63) — 코어 핸들러 카탈로그의 부분집합.
    errorRecipes: {
      navigate: CORE_ACTION_RECIPES.navigate,
      openModal: CORE_ACTION_RECIPES.openModal,
      toast: CORE_ACTION_RECIPES.toast,
      setState: CORE_ACTION_RECIPES.setState,
      sequence: CORE_ACTION_RECIPES.sequence,
      parallel: CORE_ACTION_RECIPES.parallel,
      // showErrorPage — 에러 탭 전용. 안내 페이지 표시. `params.target` = 표시 위치
      // (content=본문만 / full=전체 화면, ShowErrorPageParams). errorCode 는 편집기가 노출하지
      // 않는다 — 엔진 injectErrorCode 가 errorHandling 키값으로 자동 주입(행의 코드와 항상 일치).
      showErrorPage: {
        label: `$t:${L}.show_error_page.label`,
        params: [
          { key: 'target', label: `$t:${L}.show_error_page.param_target`, widget: 'select',
            options: [{ value: 'content' }, { value: 'full' }] },
        ],
        build: { handler: 'showErrorPage', params: { target: '{{target}}' } },
      },
    },
  };
}

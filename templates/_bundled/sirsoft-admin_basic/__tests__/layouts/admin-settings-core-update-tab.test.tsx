import { describe, it, expect } from 'vitest';
import settings from '../../layouts/admin_settings.json';
import updateTab from '../../layouts/partials/admin_settings/_tab_update.json';
import koAdmin from '../../lang/partial/ko/admin.json';
import enAdmin from '../../lang/partial/en/admin.json';
// 번들 ja 팩도 같은 모집단이다 — ko·en 만 보면 ja 누락이 조용히 통과한다.
import jaAdmin from '../../../../../lang-packs/_bundled/g7-template-sirsoft-admin_basic-ja/frontend/partial/admin.json';

/**
 * 환경설정 「업데이트」 탭 (W6).
 *
 * 여기서 잠그는 것은 화면의 모양이 아니라 **그 화면이 성립하기 위한 배선**이다:
 *
 * 1. 탭이 목록·콘텐츠·권한 게이트 셋 모두에 배선돼 있는가 (한 곳만 빠져도 탭이 죽는다)
 * 2. 신규 권한이 없는 관리자에게 탭이 **보이지 않는가** (비활성이 아니라 미표시)
 * 3. 착수 액션이 웹에 없는 선택(force·prune·백업 생략)을 싣지 않는가 (D27)
 * 4. 화면이 쓰는 다국어 키가 ko·en 양쪽에 있는가
 *
 * 효과 요약(마커 아님 — 평문): 권한 없으면 탭 미표시, 착수는 안전한 선택만 싣는다. 시나리오 마커는 각 시험 바로 위에 단다.
 */

type Json = Record<string, unknown>;

/** 레이아웃 트리를 훑어 조건을 만족하는 노드를 모은다. */
function collect(node: unknown, predicate: (obj: Json) => boolean, out: Json[] = []): Json[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, predicate, out));
    return out;
  }
  if (node && typeof node === 'object') {
    const obj = node as Json;
    if (predicate(obj)) out.push(obj);
    Object.values(obj).forEach((value) => collect(value, predicate, out));
  }
  return out;
}

/** 트리 전체를 문자열로 펼친다 (키 존재 검사용). */
const updateTabSource = JSON.stringify(updateTab);
const settingsSource = JSON.stringify(settings);

describe('환경설정 업데이트 탭 — 배선', () => {
  // @scenario actor=with_permission, run_state=idle
  // @effects update_tab_is_hidden_without_permission
  it('탭 목록·콘텐츠 partial 이 함께 배선된다', () => {
    const nav = collect(settings, (obj) => obj.id === 'tab_navigation')[0];
    const tabs = (nav?.props as Json | undefined)?.tabs as Json[] | undefined;

    // 탭 목록은 항목마다 href 를 가진 리터럴 배열이다(새 탭 열기). 업데이트 탭도 그 안에 있어야 한다.
    expect(Array.isArray(tabs), '탭 네비게이션의 탭 목록을 찾지 못했다').toBe(true);
    expect(tabs!.some((tab) => tab.id === 'update')).toBe(true);

    expect(settingsSource).toContain('partials/admin_settings/_tab_update.json');
  });

  // @scenario actor=without_permission, run_state=idle
  // @effects update_tab_is_hidden_without_permission
  it('탭 버튼이 신규 권한으로 걸러진다 (권한 없으면 보이지 않음)', () => {
    const nav = collect(settings, (obj) => obj.id === 'tab_navigation')[0];

    expect(nav, '탭 네비게이션을 찾지 못했다').toBeDefined();

    const tabs = (nav.props as Json).tabs as Json[];
    const updateItem = tabs.find((tab) => tab.id === 'update');

    // 항목의 if 가 거짓이면 TabNavigation 이 그 탭을 그리지 않는다(비활성이 아니라 미표시).
    expect(updateItem, '업데이트 탭 항목이 없다').toBeDefined();
    expect(typeof updateItem!.if, '항목에 if 가 없으면 권한 없는 관리자에게도 탭이 보인다').toBe('string');
    expect(updateItem!.if as string).toContain('can_core_update');
    // 권한 응답이 없을 때 보이는 쪽으로 기울면 안 된다.
    expect(updateItem!.if as string).toContain('?? false');

    // 다른 탭은 이 권한에 묶이지 않는다.
    for (const tab of tabs.filter((t) => t.id !== 'update')) {
      expect(String(tab.if ?? '')).not.toContain('can_core_update');
    }
  });

  // @scenario actor=without_permission, run_state=idle
  // @effects update_tab_is_hidden_without_permission
  it('탭 콘텐츠도 같은 권한을 조건으로 갖는다', () => {
    // 목록만 걸러도 주소로 직접 들어오면 콘텐츠가 렌더된다 — 두 곳 모두 걸어야 한다.
    expect((updateTab as Json).if as string).toContain('can_core_update');
    expect((updateTab as Json).if as string).toContain("=== 'update'");
  });

  // @scenario actor=with_permission, run_state=idle
  // @effects the_start_controls_appear_only_when_there_is_something_to_apply
  it('저장 버튼이 업데이트 탭에서 숨는다', () => {
    const footer = collect(settings, (obj) => obj.id === 'footer_buttons')[0];

    expect(footer).toBeDefined();
    expect(footer.if as string).toContain("!== 'update'");
  });
});

describe('환경설정 업데이트 탭 — 안전 기본값 (D27)', () => {
  // @scenario actor=with_permission, run_state=idle
  // @effects start_sends_only_safe_options
  it('착수 액션이 위험 선택을 싣지 않는다', () => {
    const start = collect(updateTab, (obj) => obj.handler === 'startCoreUpdate')[0];

    expect(start, '착수 핸들러 배선을 찾지 못했다').toBeDefined();

    const params = start.params as Json;

    ['force', 'prune', 'no_backup', 'no_maintenance', 'vendor_mode'].forEach((forbidden) => {
      expect(params, `착수 액션에 ${forbidden} 가 실렸다`).not.toHaveProperty(forbidden);
    });

    // 화면이 보내야 하는 넷 (D28 · D29 · D18)
    ['update_bundled_extensions', 'rebuild_search_index', 'layout_strategy', 'zip_file'].forEach((key) => {
      expect(params).toHaveProperty(key);
    });
  });

  // @scenario actor=with_permission, run_state=idle, layout_strategy=overwrite
  // @effects start_sends_only_safe_options
  it('레이아웃 전략 기본값이 CLI 와 같다 (덮어쓰기)', () => {
    const radio = collect(updateTab, (obj) => obj.id === 'core_update_layout_strategy')[0];

    expect(radio).toBeDefined();
    expect((radio.props as Json).value as string).toContain("?? 'overwrite'");

    const options = (radio.props as Json).options as Json[];
    expect(options.map((option) => option.value)).toEqual(['overwrite', 'keep']);
  });

  // @scenario actor=with_permission, run_state=idle, rebuild_search_index=off
  // @effects start_sends_only_safe_options
  it('검색 인덱스 재생성은 기본 꺼짐이고 그 창에서만 유효하다', () => {
    const checkbox = collect(
      updateTab,
      (obj) => typeof obj.checked === 'string' && (obj.checked as string).includes('coreUpdateRebuildIndex'),
    )[0];

    expect(checkbox, '재생성 체크박스를 찾지 못했다').toBeDefined();
    // `=== true` 이므로 상태가 없으면 꺼짐이다 — 저장되는 설정이 아니라 로컬 상태다.
    expect(checkbox.checked as string).toContain('=== true');
    expect(checkbox.checked as string).toContain('_local.');
  });

  // @scenario actor=with_permission, run_state=idle, bundled_extensions=on
  // @effects start_sends_only_safe_options
  it('번들 확장 함께 업데이트는 기본 켜짐이다 (D28)', () => {
    const checkbox = collect(
      updateTab,
      (obj) => typeof obj.checked === 'string' && (obj.checked as string).includes('coreUpdateBundled'),
    )[0];

    expect(checkbox).toBeDefined();
    expect(checkbox.checked as string).toContain('!== false');
  });
});

describe('환경설정 업데이트 탭 — 진행·실패 표시', () => {
  // @scenario actor=with_permission, run_state=running
  // @effects an_interrupted_run_continues_without_operator_action
  it('진행 중에는 중단 버튼이 없다', () => {
    // 파일 교체 중에 사람이 멈추면 반쪽 상태가 남는다 — 실패 판정과 되돌리기는 시스템이 한다 (W6).
    //
    // 실재한 적 없는 이름을 `not.toContain` 하면 모집단이 0 이라 무엇을 넣어도 통과한다.
    // 그래서 **이 화면이 쓰는 코어 업데이트 핸들러 전량**을 세고 허용 목록과 대조한다 —
    // 중단 성격의 핸들러가 새로 배선되면 그 자리에서 red 가 된다.
    // submitCoreUpdateSelection(7.2.1) 은 서버가 멈춰 물은 선택에 답하고 **이어서 진행**하는 핸들러다 — 중단 성격이 아니다.
    const allowed = ['startCoreUpdate', 'resumeCoreUpdate', 'autoResumeCoreUpdate', 'dismissCoreUpdate', 'refreshCoreUpdateStatus', 'submitCoreUpdateSelection'];
    const used = Array.from(new Set(
      collect(updateTab, (obj) => typeof obj.handler === 'string' && (obj.handler as string).endsWith('CoreUpdate') === false && /CoreUpdate/.test(obj.handler as string))
        .map((obj) => obj.handler as string)
        .concat(collect(updateTab, (obj) => typeof obj.handler === 'string' && /CoreUpdate/.test(obj.handler as string)).map((obj) => obj.handler as string)),
    ));

    expect(used.length, '코어 업데이트 핸들러 배선을 하나도 찾지 못했다 — 모집단이 비면 이 검사는 아무것도 재지 않는다').toBeGreaterThan(0);
    expect(used.filter((handler) => !allowed.includes(handler))).toEqual([]);
  });

  // @scenario actor=with_permission, run_state=finished, rollback_outcome=restored
  // @effects the_failure_screen_offers_a_way_forward
  it('되돌리기 결과가 실패와 분리된 초록 영역으로 표시된다', () => {
    const rolledBack = collect(updateTab, (obj) => obj.id === 'core_update_rolled_back_alert')[0];

    expect(rolledBack, '되돌리기 결과 영역이 없다').toBeDefined();
    expect((rolledBack.props as Json).type).toBe('success');
    expect(rolledBack.if as string).toContain('rollback?.restored');
  });

  // @scenario actor=with_permission, run_state=finished, rollback_outcome=restore_failed
  // @effects a_failed_rollback_opens_the_manual_recovery_guidance
  it('되돌리기까지 실패한 경우 수동 복구 안내가 열린다', () => {
    const failed = collect(updateTab, (obj) => obj.id === 'core_update_rollback_failed_alert')[0];

    expect(failed).toBeDefined();
    expect(failed.if as string).toContain('rollback_failed');
  });

  // @scenario actor=with_permission, run_state=running
  // @effects status_returns_the_shape_the_screen_needs
  it('진행 단계 목록이 서버가 준 단계를 그린다 (화면이 목록을 따로 갖지 않는다)', () => {
    const list = collect(updateTab, (obj) => obj.id === 'core_update_step_list')[0];

    expect(list).toBeDefined();

    const row = (list.children as Json[])[0];
    const iteration = row.iteration as Json;

    expect(iteration.source as string).toContain('coreUpdateSteps');
    expect(iteration.item_var).toBe('step');
  });
});

describe('환경설정 업데이트 탭 — W6 상태 분리·진단·이어받기', () => {
  /** ②(새 버전 있음) 전용 컨트롤 — ①(최신)에서는 보이면 안 된다. */
  const secondStateNodes = ['core_update_options', 'core_update_maintenance_warning', 'core_update_start_row'];

  // @scenario actor=with_permission, run_state=idle, source=github_download
  // @effects the_start_controls_appear_only_when_there_is_something_to_apply
  it.each(secondStateNodes)('%s 는 올릴 것이 있을 때만 보인다', (id) => {
    const node = collect(updateTab, (obj) => obj.id === id)[0];

    expect(node, `${id} 노드를 찾지 못했다`).toBeDefined();

    const gate = node.if as string;

    // 「진행 중이 아니다」만으로는 부족하다 — 최신 상태·확인 전에도 그 조건은 참이다.
    expect(gate).toContain('update_available === true');
    // 외부 접속이 막힌 서버는 확인 자체가 실패한다 — 수동 ZIP 을 고른 경우도 착수 대상이다 (㉯ D18).
    expect(gate).toContain('coreUpdateZipFile');
  });

  // @scenario actor=with_permission, run_state=finished
  // @effects the_current_version_does_not_wait_for_the_slow_server_info
  it('현재 버전 칸은 업데이트 상태 응답의 버전을 먼저 쓴다 (느린 서버 정보 응답을 기다리며 - 로 비지 않는다)', () => {
    const cell = collect(updateTab, (obj) => obj.id === 'core_update_current_version')[0];

    expect(cell, '현재 버전 칸을 찾지 못했다').toBeDefined();

    const text = JSON.stringify(cell);
    const statusAt = text.indexOf('coreUpdateStatus?.current_version');
    const systemInfoAt = text.indexOf('systemInfo?.data?.g7_version');

    // 완료 화면은 업데이트 상태 응답(빠르다)을 이미 받았는데 서버 정보 응답(트리 크기 계산으로 느리다)을
    // 기다리는 동안 「현재 버전 -」 아래에 「현재 버전은 7.x 입니다」가 함께 보였다.
    expect(statusAt, '업데이트 상태 응답의 버전을 읽지 않는다').toBeGreaterThanOrEqual(0);
    expect(systemInfoAt === -1 || statusAt < systemInfoAt, '서버 정보 응답이 먼저 읽히면 같은 공백이 남는다').toBe(true);

    const computed = (settings as Json).computed as Json;
    expect(Object.keys(computed)).toContain('coreUpdateStatus');
  });

  // @scenario actor=with_permission, run_state=finished
  // @effects the_completed_card_states_the_finished_version_not_the_current_one
  it('완료 카드는 지난 실행이 올린 판을 「현재 버전」이라 하지 않는다 (그 뒤 명령줄로 더 올리면 한 화면에 두 판이 보인다)', () => {
    // 완료 기록을 닫지 않은 채 명령줄로 더 올리면 현재 버전 칸은 실제 판(7.2.2), 완료 카드는 지난 실행의 `to_version`(7.2.1)
    // 이다. 카드는 그 실행이 마친 사실만 말하고 현재 판은 현재 버전 칸이 말한다.
    const card = collect(updateTab, (obj) => obj.id === 'core_update_completed')[0];

    expect(card, '완료 카드를 찾지 못했다').toBeDefined();
    expect(JSON.stringify(card)).toContain('completed_desc|version={{_computed.coreUpdateRun?.to_version');

    const completedDesc = (pack: unknown): unknown => (((pack as Json).settings as Json | undefined)?.core_update as Json | undefined)?.completed_desc;
    const messages: Record<string, unknown> = {
      ko: completedDesc(koAdmin),
      en: completedDesc(enAdmin),
      ja: completedDesc(jaAdmin),
    };
    const presentTense: Record<string, RegExp> = { ko: /현재 버전/, en: /current version/i, ja: /現在のバージョン/ };

    for (const [locale, message] of Object.entries(messages)) {
      expect(typeof message, `${locale} 완료 문구가 없다`).toBe('string');
      expect(message as string, `${locale} 완료 문구가 지난 실행의 판을 현재 버전이라 말한다`).not.toMatch(presentTense[locale]);
      expect(message as string, `${locale} 완료 문구에 판 자리표시자가 없다`).toContain('{{version}}');
    }
  });

  // @scenario actor=with_permission, run_state=idle, failing_step=preflight
  // @effects the_start_screen_receives_the_preflight_verdict
  it('사전 조건 미충족 진단이 착수 전에 화면에 있다', () => {
    const block = collect(updateTab, (obj) => obj.id === 'core_update_preflight_blocked')[0];

    expect(block, '진단 영역이 없으면 운영자는 눌러 봐야 막힌 것을 안다').toBeDefined();
    expect(block.if as string).toContain('coreUpdatePreflight?.ok === false');

    // 서버가 준 사유를 그대로 그린다 — 화면이 자기 문구를 만들면 판정 근거가 둘이 된다.
    const list = collect(block, (obj) => !!obj.iteration)[0];
    expect((list.iteration as Json).source as string).toContain('coreUpdatePreflight?.errors');

    // 상태 파생이 상위 레이아웃에 배선돼 있어야 표현식이 값을 갖는다.
    // 부분 문자열로 재면 키 이름이 바뀌어도 통과한다 — 키 자체를 본다.
    const computed = (settings as Json).computed as Json;
    expect(Object.keys(computed)).toContain('coreUpdatePreflight');
    expect(computed.coreUpdatePreflight as string).toContain('preflight');
  });

  // @scenario actor=with_permission, run_state=idle, source=manual_zip
  // @effects a_failed_update_check_leaves_its_reason_on_screen
  it('새 버전 확인이 실패하면 사유가 화면에 남는다', () => {
    const alert = collect(updateTab, (obj) => obj.id === 'core_update_check_error')[0];

    expect(alert, '토스트만으로는 사라진 뒤 단서가 없다').toBeDefined();
    expect(alert.if as string).toContain('coreUpdateCheckError');

    // 실패는 사유를 심고, 성공은 그것을 지운다 — 지우지 않으면 해결된 뒤에도 경고가 남는다.
    const setters = collect(updateTab, (obj) => obj.handler === 'setState')
      .map((obj) => JSON.stringify(obj.params ?? {}));

    expect(setters.some((p) => p.includes('"coreUpdateCheckError"') && !p.includes('null'))).toBe(true);
    expect(setters.some((p) => p.includes('"coreUpdateCheckError":null'))).toBe(true);
  });

  // @scenario actor=with_permission, run_state=rolled_back, rollback_outcome=restored
  // @effects the_failure_screen_offers_a_way_forward
  it('실패 화면에 조치 방법으로 가는 진입점이 있다', () => {
    const button = collect(updateTab, (obj) => obj.id === 'core_update_guide_button')[0];

    expect(button, '되돌리기가 성공해도 다음에 무엇을 할지로 가는 길이 있어야 한다 (W6 ④)').toBeDefined();
    expect(button.if as string).toContain('rolled_back');

    const action = (button.actions as Json[])[0];
    expect(action.handler).toBe('openModal');
    expect(action.target).toBe('core_update_guide_modal');
    // 그 모달이 실제로 이 화면에 실려 있어야 한다.
    expect(settingsSource).toContain('_modal_core_update_guide.json');
  });

  // @scenario actor=with_permission, run_state=running, concurrency=single
  // @effects an_interrupted_run_continues_without_operator_action
  it('진행 중인 실행을 화면이 스스로 이어간다', () => {
    const auto = collect(updateTab, (obj) => obj.id === 'core_update_auto_resume')[0];

    expect(auto, '자동 이어받기 배선이 없으면 창을 닫은 사이트가 점검 모드에 멈춘다').toBeDefined();

    const onMount = ((auto.lifecycle as Json)?.onMount ?? []) as Json[];
    expect(onMount.map((action) => action.handler)).toContain('autoResumeCoreUpdate');

    // 되돌리는 중도 이어가야 한다 — D10 의 「조작 없이」가 그 호출에 기댄다.
    expect(auto.if as string).toContain('rolling_back');
    // 이미 몰고 있는 중이면 겹쳐 부르지 않는다 (서버 점유 409).
    expect(auto.if as string).toContain('coreUpdateBusy');
  });

  // @scenario actor=with_permission, run_state=running
  // @effects server_notices_reach_the_screen
  it('서버가 싣는 고지가 화면에 도달한다', () => {
    const notices = collect(updateTab, (obj) => obj.id === 'core_update_notices')[0];

    expect(notices, '서버가 notices 를 싣는데 화면이 그리지 않으면 그 사실이 도달하지 않는다').toBeDefined();
    expect(notices.if as string).toContain('notices');

    const list = collect(notices, (obj) => !!obj.iteration)[0];
    expect((list.iteration as Json).source as string).toContain('notices');
  });

  // @scenario actor=with_permission, source=manual_zip, run_state=idle
  // @effects manual_zip_lives_behind_advanced
  it('고급 접기의 명령어 안내가 공용 안내 키를 쓴다', () => {
    const guide = collect(updateTab, (obj) => obj.id === 'core_update_cli_guide')[0];

    expect(guide).toBeDefined();

    const source = JSON.stringify(guide);

    // 리터럴을 새로 두면 같은 안내가 두 벌이 되고, 문구가 바뀔 때 한쪽만 낡는다.
    expect(source).not.toContain('php artisan core:update');
    expect(source).toContain('admin.settings.info.update_guide_cmd');
  });

  // @scenario actor=with_permission, source=manual_zip, run_state=idle
  // @effects manual_zip_lives_behind_advanced
  it('고급 접기가 이 템플릿의 아코디언 카드 자산을 쓴다 (꺾쇠·여백·단일 테두리)', () => {
    const card = collect(updateTab, (obj) => obj.id === 'card_core_update_advanced')[0];

    expect(card).toBeDefined();

    // `admin-card` 안에 Accordion 컴포넌트를 넣으면 테두리 상자가 겹치고, trigger 를 직접 넘기면
    // 컴포넌트가 꺾쇠를 그리지 않아 접힌다는 신호가 사라진다 (실측 2026-09-13).
    expect(collect(card, (obj) => obj.name === 'Accordion')).toHaveLength(0);
    expect(String((card.props as Json).className)).toContain('admin-card-accordion');

    const trigger = collect(card, (obj) => obj.id === 'core_update_advanced_trigger')[0];
    expect(String((trigger.props as Json).className)).toContain('card-header');

    const chevron = collect(
      trigger,
      (obj) => obj.name === 'Icon' && (obj.props as Json | undefined)?.name === 'chevron-down'
    )[0];
    expect(chevron, '접힌다는 신호(꺾쇠)가 없다').toBeDefined();
    expect(String((chevron.props as Json).className)).toContain('admin-card-accordion-icon');

    const content = collect(card, (obj) => obj.id === 'core_update_advanced_content')[0];
    expect(String((content.props as Json).className)).toContain('row-stack');

    // 트리거가 쓰는 상태 이름과 본문이 보는 상태 이름이 같아야 눌렀을 때 열린다.
    expect(JSON.stringify(trigger.actions)).toContain('coreUpdateAdvancedOpen');
    expect(String(content.if)).toContain('coreUpdateAdvancedOpen');
  });

  // @scenario actor=with_permission, source=manual_zip, run_state=idle
  // @effects manual_zip_lives_behind_advanced
  it('고급 접기 머리는 키보드로도 펼치는 버튼 요소다 (펼침 상태를 알린다)', () => {
    const trigger = collect(updateTab, (obj) => obj.id === 'core_update_advanced_trigger')[0];

    expect(trigger).toBeDefined();

    // `div role="button"` 에 click 만 걸면 Tab 으로 닿아도 Enter·Space 가 아무 일도 하지 않는다.
    // 버튼 요소는 브라우저가 Enter·Space 를 click 으로 바꿔 주므로 키 처리를 따로 두지 않는다.
    const props = trigger.props as Json;
    expect(trigger.name).toBe('Button');
    expect(props.type).toBe('button');
    expect(props.role, '버튼 요소에 role 을 덧씌우지 않는다').toBeUndefined();
    expect(String(props['aria-expanded'])).toContain('coreUpdateAdvancedOpen');
    expect(props['aria-controls']).toBe('core_update_advanced_content');
  });
});

describe('환경설정 업데이트 탭 — 진행 기록 소스의 권한 응답', () => {
  // @scenario actor=without_permission, run_state=idle
  // @effects update_tab_is_hidden_without_permission
  it('권한 응답이 오기 전에 불린 진행 기록 소스의 403 은 조용히 넘긴다 (형제 소스와 같은 처리)', () => {
    const sources = (settings as Json).data_sources as Json[];
    const status = sources.find((ds) => ds.id === 'core_update_status');

    expect(status, 'core_update_status 소스가 없다').toBeDefined();
    // 소스 조건은 `can_core_update !== false` 라 권한 응답이 비어 있는 동안에도 불린다 — 권한 없는 관리자가
    // 주소로 업데이트 탭에 들어오면 403 이 나고, 처리가 없으면 시스템 기본 처리가 오류 토스트를 띄운다.
    expect(String(status!.if)).toContain('can_core_update !== false');
    expect((status!.errorHandling as Json | undefined)?.['403']).toEqual({ handler: 'suppress' });

    // 비교 기준: 같은 화면의 언어팩 소스가 같은 이유로 같은 처리를 갖는다.
    const languagePacks = sources.find((ds) => ds.id === 'language_packs');
    expect((languagePacks!.errorHandling as Json)['403']).toEqual({ handler: 'suppress' });
  });
});

describe('환경설정 업데이트 탭 — 다국어', () => {
  const usedKeys = Array.from(
    new Set(
      (updateTabSource.match(/admin\.settings\.core_update\.[a-z0-9_.]+/g) ?? []).map((key) =>
        key.replace(/[.|]+$/, ''),
      ),
    ),
  );

  /** 점 경로로 값을 찾는다. */
  function lookup(source: Json, path: string): unknown {
    return path
      .split('.')
      .reduce<unknown>((acc, part) => (acc && typeof acc === 'object' ? (acc as Json)[part] : undefined), source);
  }

  // @scenario actor=with_permission, run_state=idle
  // @effects every_step_has_a_translated_label
  it('화면이 쓰는 키를 하나 이상 수집한다', () => {
    expect(usedKeys.length).toBeGreaterThan(10);
  });

  // @scenario actor=with_permission, run_state=idle
  // @effects every_step_has_a_translated_label
  it.each(['ko', 'en', 'ja'])('%s 다국어에 화면이 쓰는 키가 모두 있다', (locale) => {
    const packs: Record<string, unknown> = { ko: koAdmin, en: enAdmin, ja: jaAdmin };
    const source = packs[locale] as Json;
    const missing = usedKeys.filter((key) => lookup(source, key.replace(/^admin\./, '')) === undefined);

    expect(missing, `${locale} 에 없는 키: ${missing.join(', ')}`).toEqual([]);
  });

  // @scenario actor=with_permission, run_state=idle
  // @effects every_step_has_a_translated_label
  it('탭 이름이 세 로케일 모두에 있다', () => {
    [koAdmin, enAdmin, jaAdmin].forEach((pack) => {
      expect(lookup(pack as unknown as Json, 'settings.tabs.update')).toBeTruthy();
    });
  });
});

describe('환경설정 업데이트 탭 — 진행 중 번들 선택 (7.2.1)', () => {
  const panel = collect(updateTab, (obj) => obj.id === 'core_update_selection')[0];

  // @scenario actor=with_permission, run_state=running
  // @effects selection_panel_shows_only_while_awaiting
  it('선택 패널은 서버가 선택을 기다릴 때만 보이고, [계속] 이 선택 제출 핸들러를 부른다', () => {
    expect(panel, '선택 패널이 없다').toBeDefined();
    expect(String(panel.if)).toContain("status === 'running'");
    expect(String(panel.if)).toContain('awaiting?.kind');

    const proceed = collect(panel, (obj) => obj.id === 'core_update_selection_continue')[0];
    expect(JSON.stringify(proceed.actions)).toContain('submitCoreUpdateSelection');
  });

  // @scenario actor=with_permission, run_state=running
  // @effects selection_panel_shows_only_while_awaiting
  it('항목 체크 기본값은 번들 업데이트면 올림, 미배치 번들이면 배치 안 함이고, 레이아웃 선택은 레이아웃이 있는 확장에만 있다', () => {
    const source = JSON.stringify(panel);

    expect(source).toContain("(_computed.coreUpdateRun?.awaiting?.kind === 'bundled_updates')");
    expect(source).toContain('choiceItem.has_layouts === true');
    expect(source).toContain("'core_update_strategy_' + choiceItem.key");
  });

  // @scenario actor=with_permission, run_state=running
  // @effects selection_panel_shows_only_while_awaiting
  it('선택을 기다리는 동안 [이어서 진행] 은 숨는다 (눌러도 같은 자리에 머문다)', () => {
    const resume = collect(updateTab, (obj) => obj.id === 'core_update_resume_button')[0];

    expect(String(resume.if)).toContain('!_computed.coreUpdateRun?.awaiting?.kind');
  });
});

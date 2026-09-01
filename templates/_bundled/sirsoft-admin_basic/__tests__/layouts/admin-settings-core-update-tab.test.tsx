import { describe, it, expect } from 'vitest';
import settings from '../../layouts/admin_settings.json';
import updateTab from '../../layouts/partials/admin_settings/_tab_update.json';
import koAdmin from '../../lang/partial/ko/admin.json';
import enAdmin from '../../lang/partial/en/admin.json';
// 번들 ja 팩도 같은 모집단이다 — ko·en 만 보면 ja 누락이 조용히 통과한다.
import jaAdmin from '../../../../../lang-packs/_bundled/g7-template-sirsoft-admin_basic-ja/frontend/partial/admin.json';

/**
 * 환경설정 「업데이트」 탭 (W6 — 이슈 #609 G6).
 *
 * 여기서 잠그는 것은 화면의 모양이 아니라 **그 화면이 성립하기 위한 배선**이다:
 *
 * 1. 탭이 목록·콘텐츠·권한 게이트 셋 모두에 배선돼 있는가 (한 곳만 빠져도 탭이 죽는다)
 * 2. 신규 권한이 없는 관리자에게 탭이 **보이지 않는가** (비활성이 아니라 미표시)
 * 3. 착수 액션이 웹에 없는 선택(force·prune·백업 생략)을 싣지 않는가 (D27)
 * 4. 화면이 쓰는 다국어 키가 ko·en 양쪽에 있는가
 *
 * @scenario actor=with_permission, run_state=idle
 * @effects update_tab_is_hidden_without_permission, start_sends_only_safe_options
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
  it('탭 목록·콘텐츠 partial 이 함께 배선된다', () => {
    const tabs = ((settings as Json).defines as Json)?.settingsTabs as Json[] | undefined;

    expect(tabs, 'settingsTabs 정의가 없으면 탭 목록을 걸러 낼 수 없다').toBeDefined();
    expect(tabs!.some((tab) => tab.id === 'update')).toBe(true);

    expect(settingsSource).toContain('partials/admin_settings/_tab_update.json');
  });

  // @scenario actor=without_permission, run_state=idle
  // @effects update_tab_is_hidden_without_permission
  it('탭 버튼이 신규 권한으로 걸러진다 (권한 없으면 보이지 않음)', () => {
    const nav = collect(settings, (obj) => obj.id === 'tab_navigation')[0];

    expect(nav, '탭 네비게이션을 찾지 못했다').toBeDefined();

    const tabsProp = (nav.props as Json).tabs;

    expect(typeof tabsProp, '탭 목록이 리터럴이면 권한으로 거를 수 없다').toBe('string');
    expect(tabsProp as string).toContain('can_core_update');
    expect(tabsProp as string).toContain("t.id !== 'update'");
  });

  // @scenario actor=without_permission, run_state=idle
  // @effects update_tab_is_hidden_without_permission
  it('탭 콘텐츠도 같은 권한을 조건으로 갖는다', () => {
    // 목록만 걸러도 주소로 직접 들어오면 콘텐츠가 렌더된다 — 두 곳 모두 걸어야 한다.
    expect((updateTab as Json).if as string).toContain('can_core_update');
    expect((updateTab as Json).if as string).toContain("=== 'update'");
  });

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
  it('진행 중에는 중단 버튼이 없다', () => {
    // 파일 교체 중에 사람이 멈추면 반쪽 상태가 남는다 — 실패 판정과 되돌리기는 시스템이 한다 (W6).
    //
    // 실재한 적 없는 이름을 `not.toContain` 하면 모집단이 0 이라 무엇을 넣어도 통과한다.
    // 그래서 **이 화면이 쓰는 코어 업데이트 핸들러 전량**을 세고 허용 목록과 대조한다 —
    // 중단 성격의 핸들러가 새로 배선되면 그 자리에서 red 가 된다.
    const allowed = ['startCoreUpdate', 'resumeCoreUpdate', 'autoResumeCoreUpdate', 'dismissCoreUpdate', 'refreshCoreUpdateStatus'];
    const used = Array.from(new Set(
      collect(updateTab, (obj) => typeof obj.handler === 'string' && (obj.handler as string).endsWith('CoreUpdate') === false && /CoreUpdate/.test(obj.handler as string))
        .map((obj) => obj.handler as string)
        .concat(collect(updateTab, (obj) => typeof obj.handler === 'string' && /CoreUpdate/.test(obj.handler as string)).map((obj) => obj.handler as string)),
    ));

    expect(used.length, '코어 업데이트 핸들러 배선을 하나도 찾지 못했다 — 모집단이 비면 이 검사는 아무것도 재지 않는다').toBeGreaterThan(0);
    expect(used.filter((handler) => !allowed.includes(handler))).toEqual([]);
  });

  it('되돌리기 결과가 실패와 분리된 초록 영역으로 표시된다', () => {
    const rolledBack = collect(updateTab, (obj) => obj.id === 'core_update_rolled_back_alert')[0];

    expect(rolledBack, '되돌리기 결과 영역이 없다').toBeDefined();
    expect((rolledBack.props as Json).type).toBe('success');
    expect(rolledBack.if as string).toContain('rollback?.restored');
  });

  it('되돌리기까지 실패한 경우 수동 복구 안내가 열린다', () => {
    const failed = collect(updateTab, (obj) => obj.id === 'core_update_rollback_failed_alert')[0];

    expect(failed).toBeDefined();
    expect(failed.if as string).toContain('rollback_failed');
  });

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

  it('고급 접기의 명령어 안내가 공용 안내 키를 쓴다', () => {
    const guide = collect(updateTab, (obj) => obj.id === 'core_update_cli_guide')[0];

    expect(guide).toBeDefined();

    const source = JSON.stringify(guide);

    // 리터럴을 새로 두면 같은 안내가 두 벌이 되고, 문구가 바뀔 때 한쪽만 낡는다.
    expect(source).not.toContain('php artisan core:update');
    expect(source).toContain('admin.settings.info.update_guide_cmd');
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

  it('화면이 쓰는 키를 하나 이상 수집한다', () => {
    expect(usedKeys.length).toBeGreaterThan(10);
  });

  it.each(['ko', 'en', 'ja'])('%s 다국어에 화면이 쓰는 키가 모두 있다', (locale) => {
    const packs: Record<string, unknown> = { ko: koAdmin, en: enAdmin, ja: jaAdmin };
    const source = packs[locale] as Json;
    const missing = usedKeys.filter((key) => lookup(source, key.replace(/^admin\./, '')) === undefined);

    expect(missing, `${locale} 에 없는 키: ${missing.join(', ')}`).toEqual([]);
  });

  it('탭 이름이 세 로케일 모두에 있다', () => {
    [koAdmin, enAdmin, jaAdmin].forEach((pack) => {
      expect(lookup(pack as unknown as Json, 'settings.tabs.update')).toBeTruthy();
    });
  });
});

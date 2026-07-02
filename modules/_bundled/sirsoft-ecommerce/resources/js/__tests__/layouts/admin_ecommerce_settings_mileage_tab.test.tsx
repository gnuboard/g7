/**
 * 마일리지 설정 탭 구조 검증 테스트
 *
 * @description
 * - 마일리지 설정은 이커머스 환경설정(admin_ecommerce_settings.json)의 서브탭으로 통합됨
 *   (별도 독립 화면 아님 — 합의사항)
 * - 환경설정 메인: mileage 탭 등록 + 탭 콘텐츠 partial(_tab_mileage.json) 참조 + 탭 if 가드
 * - 탭 콘텐츠 partial 5종(탭/기본/통화테이블/통화카드/유효기간/소멸알림) 구조 검증
 * - 카드 4종(기본/통화/유효기간/소멸알림), 토글 3종, 적립시점 기본=구매확정
 * - 통화 테이블 iteration + 기본통화 행 disabled + max_use_type 라디오
 * - read-only 권한 disabled(환경설정 공통)
 * - 소멸 알림 채널: 직접 선택 대신 mileage_expiring_soon definition 의 템플릿별 활성 상태 읽기전용 표시
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import settingsLayout from '../../../layouts/admin/admin_ecommerce_settings.json';
import tabMileage from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_mileage.json';
import basicCard from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_mileage_basic_card.json';
import currencyTable from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_mileage_currency_table.json';
import currencyCards from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_mileage_currency_cards.json';
import expiryCard from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_mileage_expiry_card.json';
import notificationCard from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_mileage_notification_card.json';

function findById(node: any, id: string): any | null {
  if (!node) return null;
  if (node.id === id) return node;
  const kids = [...(node.children ?? []), ...(node.responsive?.portable?.children ?? [])];
  for (const child of kids) {
    const found = findById(child, id);
    if (found) return found;
  }
  return null;
}

function findFirst(node: any, predicate: (n: any) => boolean): any | null {
  if (!node) return null;
  if (predicate(node)) return node;
  const kids = [
    ...(node.children ?? []),
    ...(node.responsive?.portable?.children ?? []),
    ...(node.actions ?? []),
    ...(node.params?.actions ?? []),
  ];
  for (const child of kids) {
    const found = findFirst(child, predicate);
    if (found) return found;
  }
  return null;
}

function findAll(node: any, predicate: (n: any) => boolean): any[] {
  const out: any[] = [];
  if (!node) return out;
  if (predicate(node)) out.push(node);
  const kids = [...(node.children ?? []), ...(node.responsive?.portable?.children ?? [])];
  for (const child of kids) out.push(...findAll(child, predicate));
  return out;
}

describe('마일리지 설정 — 환경설정 서브탭 통합 (별도 화면 아님)', () => {
  const content = (settingsLayout as any).slots.content[0];

  it('환경설정 TabNavigation 에 mileage 탭이 등록된다', () => {
    const tabNav = findById(content, 'tab_navigation');
    const tabIds = tabNav.props.tabs.map((t: any) => t.id);
    expect(tabIds).toContain('mileage');
    const mileageTab = tabNav.props.tabs.find((t: any) => t.id === 'mileage');
    expect(mileageTab.label).toBe('$t:sirsoft-ecommerce.admin.settings.tabs.mileage');
  });

  it('환경설정 메인이 마일리지 탭 콘텐츠 partial 을 참조한다', () => {
    const json = JSON.stringify(settingsLayout);
    expect(json).toContain('_tab_mileage.json');
  });

  it('저장 body 분기에 mileage 탭이 해당 카테고리만 전송한다', () => {
    const save = findById(content, 'save_button');
    const apiCall = findFirst(save.actions[0], (n) => n.handler === 'apiCall');
    expect(apiCall.params.body).toContain("tab === 'mileage'");
    expect(apiCall.params.body).toContain("_tab: 'mileage'");
    expect(apiCall.params.body).toContain('mileage: form.mileage');
  });

  it('인라인 통화 추가가 미확정(체크 버튼 미클릭) 상태면 저장을 차단하고 경고한다 (회귀 가드)', () => {
    // 회귀: 통화 추가 인라인 입력 행을 열고 입력만 한 채 체크 버튼을 누르지 않고 저장하면,
    // 임시 입력(newMileageCurrency)이 currency_rules 배열에 확정되지 않아 저장 시 조용히 사라진다.
    // 저장 sequence는 미확정 상태(isAddingMileageCurrency/isAddingCurrency)에서 차단 + 경고해야 한다.
    const save = findById(content, 'save_button');
    const seq = save.actions[0];
    const actions = seq.params.actions;

    // 1) 차단 경고 toast: 미확정 통화가 있을 때만 발화
    const guardToast = actions.find((a: any) => a.handler === 'toast'
      && String(a.if ?? '').includes('isAddingMileageCurrency'));
    expect(guardToast, '미확정 통화 경고 toast 가드가 없다').toBeTruthy();
    expect(guardToast.if).toContain('isAddingMileageCurrency === true');
    expect(guardToast.if).toContain('isAddingCurrency === true');
    expect(guardToast.params.type).toBe('error');
    expect(guardToast.params.message).toBe('$t:sirsoft-ecommerce.admin.settings.save_pending_currency');

    // 2) 실제 저장(apiCall)과 isSaving setState 는 미확정이 아닐 때만 실행 (부정 조건 가드)
    const apiCall = actions.find((a: any) => a.handler === 'apiCall');
    expect(apiCall.if, 'apiCall 에 미확정 차단 가드가 없다').toBeTruthy();
    expect(apiCall.if).toContain('!(');
    expect(apiCall.if).toContain('isAddingMileageCurrency === true');
    expect(apiCall.if).toContain('isAddingCurrency === true');

    const savingSetState = actions.find((a: any) => a.handler === 'setState'
      && a.params?.isSaving === true);
    expect(savingSetState.if, 'isSaving setState 에 미확정 차단 가드가 없다').toBeTruthy();
    expect(savingSetState.if).toContain('!(');
  });

  it('별도 마일리지 설정 화면(독립 레이아웃)이 제거되었다', async () => {
    // 별도 레이아웃 파일이 더 이상 존재하지 않아야 한다 (import 실패 = 통과)
    await expect(
      import('../../../layouts/admin/admin_ecommerce_mileage_settings.json'),
    ).rejects.toThrow();
  });
});

describe('마일리지 탭 콘텐츠 (_tab_mileage.json)', () => {
  it('탭 if 가드가 mileage 탭 활성 시에만 렌더한다', () => {
    expect(tabMileage.if).toContain("=== 'mileage'");
    expect(tabMileage.if).toContain('activeEcommerceSettingsTab');
  });

  it('카드 partial 5종을 모두 참조한다', () => {
    const json = JSON.stringify(tabMileage);
    expect(json).toContain('_tab_mileage_basic_card.json');
    expect(json).toContain('_tab_mileage_currency_table.json');
    expect(json).toContain('_tab_mileage_currency_cards.json');
    expect(json).toContain('_tab_mileage_expiry_card.json');
    expect(json).toContain('_tab_mileage_notification_card.json');
  });

  it('중첩 partial 참조는 디렉토리 경로 없이 파일명만 사용한다 (partial 내부 partial 해석 기준)', () => {
    // partial 내부에서 다른 partial 을 참조할 때 부모 partial 디렉토리 기준 상대 경로(파일명)만 허용.
    // 전체 경로(partials/...)를 쓰면 경로가 중복 해석되어 빈 화면으로 렌더된다 (회귀 가드).
    const refs: string[] = [];
    const collect = (node: any) => {
      if (!node || typeof node !== 'object') return;
      if (typeof node.partial === 'string') refs.push(node.partial);
      for (const key of Object.keys(node)) {
        const v = node[key];
        if (Array.isArray(v)) v.forEach(collect);
        else if (v && typeof v === 'object') collect(v);
      }
    };
    collect(tabMileage);
    expect(refs.length).toBeGreaterThanOrEqual(5);
    for (const ref of refs) {
      expect(ref).not.toContain('/');
      expect(ref).toMatch(/^_tab_mileage.*\.json$/);
    }
  });

  it('통화 카드는 responsive.portable 로 모바일 카드 partial 을 전환한다', () => {
    const view = findById(tabMileage, 'currency_rules_view');
    expect(view.children[0].partial).toContain('_tab_mileage_currency_table.json');
    expect(view.responsive?.portable?.children[0].partial).toContain(
      '_tab_mileage_currency_cards.json',
    );
  });

  it('[+ 통화 추가] 버튼이 즉시 push 가 아니라 인라인 입력 행을 연다 (환율 설정 패턴 일관)', () => {
    const addBtn = findById(tabMileage, 'add_currency_rule_button');
    const setState = addBtn.actions[0];
    expect(setState.handler).toBe('setState');
    // 즉시 currency_rules 에 push 하지 않는다
    expect(setState.params['form.mileage.currency_rules']).toBeUndefined();
    // 인라인 입력 행 활성화 + 입력 임시 객체 초기화
    expect(setState.params.isAddingMileageCurrency).toBe(true);
    expect(setState.params['form.newMileageCurrency']).toBeTruthy();
    // 추가 중에는 버튼 비활성
    expect(addBtn.props.disabled).toContain('_local.isAddingMileageCurrency === true');
  });

  it('마일리지 내역 보기 버튼이 기본 설정 카드 헤더에 있고 내역 화면으로 이동한다', () => {
    // 공간 절약 위해 별도 헤더 행 대신 기본 설정 카드 우측 상단으로 이동 (요청)
    const btn = findById(basicCard, 'view_transactions_button');
    expect(btn, '내역 보기 버튼이 기본 설정 카드에 없다').toBeTruthy();
    expect(btn.actions[0].handler).toBe('navigate');
    expect(btn.actions[0].params.path).toBe('/admin/ecommerce/mileage-transactions');
    // _tab_mileage.json 의 기존 별도 헤더 행은 제거됨
    expect(findById(tabMileage, 'mileage_tab_header')).toBeNull();
  });
});

describe('기본 설정 카드 (_tab_mileage_basic_card.json)', () => {
  it('enabled 토글이 form.mileage.enabled 에 바인딩되고 read-only 시 disabled', () => {
    const toggle = findFirst(basicCard, (n) => n.name === 'Toggle' && n.props?.name === 'mileage.enabled');
    expect(toggle).toBeTruthy();
    expect(toggle.props.disabled).toContain('_computed.isReadOnly');
  });

  it('적립 시점 Select 기본값이 구매확정(confirmed)이다', () => {
    const select = findFirst(basicCard, (n) => n.name === 'Select' && n.props?.name === 'mileage.earn_trigger');
    expect(select.props.value).toContain("'confirmed'");
    const values = select.props.options.map((o: any) => o.value);
    expect(values).toEqual(['confirmed', 'delivered']);
  });

  it('단일 차감 시점 Select(mileage.deduction_timing)는 더 이상 존재하지 않는다 (결제수단별로 이전 — MP06)', () => {
    // 마일리지 차감 시점은 주문설정 탭의 결제수단별 컨트롤(mileage_deduction_timing)로 이전됨.
    // 마일리지 기본 설정 카드에는 단일 차감 시점 Select 가 없어야 한다.
    const select = findFirst(basicCard, (n) => n.name === 'Select' && n.props?.name === 'mileage.deduction_timing');
    expect(select, '단일 차감 시점 Select 가 아직 남아있다').toBeNull();
  });

  it('적립률 입력이 음수 검증 오류를 표시한다', () => {
    const errSpan = findFirst(basicCard, (n) => n.if && String(n.if).includes('mileage.default_earn_rate'));
    expect(errSpan).toBeTruthy();
  });
});

describe('통화 규칙 테이블 (_tab_mileage_currency_table.json)', () => {
  it('currency_rules 를 iteration 하며 item_var/index_var 를 쓴다', () => {
    const row = findFirst(currencyTable, (n) => n.iteration);
    expect(row.iteration.item_var).toBe('rule');
    expect(row.iteration.index_var).toBe('ruleIndex');
    expect(row.iteration.source).toContain('currency_rules');
  });

  it('기본통화 행(_idx === 0)은 통화코드 입력과 삭제 버튼이 비활성/숨김이다', () => {
    const codeInput = findFirst(currencyTable, (n) => n.name === 'Input' && String(n.props?.name).includes('currency_code'));
    expect(codeInput.props.disabled).toContain('rule._idx === 0');
    const delBtn = findFirst(currencyTable, (n) => n.name === 'Button' && n.if && String(n.if).includes('rule._idx !== 0'));
    expect(delBtn).toBeTruthy();
  });

  it('삭제 버튼이 행 제거 시 hasChanges 를 true 로 만든다', () => {
    const delBtn = findFirst(currencyTable, (n) => n.name === 'Button' && n.if && String(n.if).includes('rule._idx !== 0'));
    const setState = delBtn.actions[0];
    expect(setState.handler).toBe('setState');
    expect(setState.params.hasChanges).toBe(true);
  });

  it('max_use_type 라디오(percent/fixed) 가 있고 비선택 입력이 disabled 된다', () => {
    const radios = findAll(currencyTable, (n) => n.name === 'Input' && n.props?.type === 'radio');
    const values = radios.map((r) => r.props.value);
    expect(values).toEqual(expect.arrayContaining(['percent', 'fixed']));
    const percentInput = findFirst(currencyTable, (n) => n.name === 'Input' && String(n.props?.name).includes('max_use_percent'));
    expect(percentInput.props.disabled).toContain("rule.max_use_type !== 'percent'");
  });

  it('max_use_type 라디오는 부모 Label 클릭으로 상태를 바꾼다 (게시판 검증 패턴 — 라디오 자동바인딩 회피)', () => {
    // 회귀: 라디오 자동바인딩은 문자열 값을 value 바인딩으로 처리해 라디오 그룹을 깨뜨린다.
    // 게시판 date_display_format 의 검증된 패턴: 라디오는 pointer-events-none(시각 전용),
    // 부모 Label 에 click 액션을 달아 해당 행(rule._idx)의 max_use_type 을 배열 map 교체로 갱신.
    const radios = findAll(currencyTable, (n) => n.name === 'Input' && n.props?.type === 'radio'
      && String(n.props?.name).includes('max_use_type'));
    // currency_rules 행(2) + 인라인 추가 행(2) = 4
    expect(radios.length).toBe(4);
    for (const radio of radios) {
      // 라디오는 클릭 이벤트를 받지 않는다 (부모 Label 이 처리)
      expect(radio.props.className, `radio(${radio.props.value}) 에 pointer-events-none 누락`).toContain('pointer-events-none');
      // 라디오 자체에는 actions 가 없다 (자동바인딩/onChange 미사용)
      expect(radio.actions ?? []).toHaveLength(0);
    }

    // 부모 Label 이 click → setState 로 max_use_type 갱신
    const labels = findAll(currencyTable, (n) => n.name === 'Label'
      && (n.actions ?? []).some((a: any) => a.type === 'click' && a.handler === 'setState'
        && JSON.stringify(a.params ?? {}).includes('max_use_type')));
    // currency_rules 행 percent/fixed Label 2개 + 인라인 추가 행 percent/fixed Label 2개 = 4
    expect(labels.length).toBe(4);
    for (const label of labels) {
      const click = label.actions.find((a: any) => a.type === 'click' && a.handler === 'setState');
      expect(click.params.target).toBe('local');
    }

    // currency_rules 행 Label(2개)은 배열 map 교체로 클릭 행만 갱신 (다통화 격리) + hasChanges
    const rowLabels = labels.filter((l: any) => JSON.stringify(l.actions).includes('currency_rules')
      && JSON.stringify(l.actions).includes('rule._idx'));
    expect(rowLabels.length, 'currency_rules 행 Label click 액션이 2개여야 함').toBe(2);
    for (const rowLabel of rowLabels) {
      const rowClick = rowLabel.actions.find((a: any) => a.handler === 'setState');
      const expr = String(rowClick.params['form.mileage.currency_rules']);
      expect(expr).toContain('.map(');
      expect(expr).toContain('i === rule._idx');
      expect(expr).toContain('...c');
      expect(expr).toMatch(/max_use_type:\s*'(percent|fixed)'/);
      expect(rowClick.params.hasChanges).toBe(true);
    }

    // 인라인 추가 행 Label(2개)은 단일 객체 newMileageCurrency.max_use_type 정적 키 갱신
    const inlineLabels = labels.filter((l: any) => JSON.stringify(l.actions).includes('newMileageCurrency'));
    expect(inlineLabels.length, '인라인 추가 행 Label click 액션이 2개여야 함').toBe(2);
    for (const inlineLabel of inlineLabels) {
      const click = inlineLabel.actions.find((a: any) => a.handler === 'setState');
      expect(click.params['form.newMileageCurrency.max_use_type']).toMatch(/^(percent|fixed)$/);
    }
  });

  it('인라인 추가 행(new_mileage_currency_row)이 isAddingMileageCurrency 조건으로 노출된다 (환율 설정 패턴)', () => {
    const newRow = findById(currencyTable, 'new_mileage_currency_row');
    expect(newRow).toBeTruthy();
    expect(newRow.if).toContain('_local.isAddingMileageCurrency === true');
    // 통화 코드는 자유 입력 Input 이 아니라 등록 통화 Select 드롭다운 (M1)
    const codeSelect = findFirst(newRow, (n) => n.name === 'Select' && n.props?.name === 'newMileageCurrency.currency_code');
    expect(codeSelect).toBeTruthy();
    // 자유 텍스트 Input 회귀 차단
    const freeInput = findFirst(newRow, (n) => n.name === 'Input' && n.props?.name === 'newMileageCurrency.currency_code');
    expect(freeInput).toBeNull();
  });

  it('통화 추가 Select 옵션은 등록 통화(language_currency.currencies) 중 미추가분만 노출한다 (M1)', () => {
    const newRow = findById(currencyTable, 'new_mileage_currency_row');
    const codeSelect = findFirst(newRow, (n) => n.name === 'Select' && n.props?.name === 'newMileageCurrency.currency_code');
    const opts = String(codeSelect.props.options);
    // 등록 통화 목록 기반
    expect(opts).toContain('language_currency?.currencies');
    // 이미 마일리지 규칙에 있는 통화는 제외
    expect(opts).toContain('currency_rules');
    expect(opts).toContain('.some(');
  });

  it('모바일 카드의 통화 추가도 Select 드롭다운이다 (M1 패리티)', () => {
    const newCardSelect = findFirst(currencyCards, (n) => n.name === 'Select' && n.props?.name === 'newMileageCurrency.currency_code');
    expect(newCardSelect).toBeTruthy();
    const freeInput = findFirst(currencyCards, (n) => n.name === 'Input' && n.props?.name === 'newMileageCurrency.currency_code');
    expect(freeInput).toBeNull();
  });

  it('인라인 추가 행의 체크 버튼이 중복 검사 후 currency_rules 에 push 한다', () => {
    const newRow = findById(currencyTable, 'new_mileage_currency_row');
    const seq = findFirst(newRow, (n) => n.handler === 'sequence');
    expect(seq).toBeTruthy();
    const actions = seq.actions ?? seq.params?.actions ?? [];
    // 중복 시 error toast
    const dupToast = actions.find((a) => a.handler === 'toast' && String(a.if).includes('duplicate') === false && String(a.if).includes('some('));
    expect(dupToast).toBeTruthy();
    // 정상 시 push + isAddingMileageCurrency=false + hasChanges
    const push = actions.find((a) => a.handler === 'setState' && a.params?.['form.mileage.currency_rules']);
    expect(push).toBeTruthy();
    expect(push.params.isAddingMileageCurrency).toBe(false);
    expect(push.params.hasChanges).toBe(true);
  });
});

describe('통화 규칙 모바일 카드 (_tab_mileage_currency_cards.json)', () => {
  it('PC 테이블과 동일하게 currency_rules 를 iteration 한다', () => {
    const card = findFirst(currencyCards, (n) => n.iteration);
    expect(card.iteration.source).toContain('currency_rules');
  });
});

describe('유효기간 / 소멸 알림 카드', () => {
  it('expiry_enabled / expiry_notification_enabled 토글 바인딩', () => {
    const expiryToggle = findFirst(expiryCard, (n) => n.name === 'Toggle' && n.props?.name === 'mileage.expiry_enabled');
    expect(expiryToggle).toBeTruthy();
    const notiToggle = findFirst(notificationCard, (n) => n.name === 'Toggle' && n.props?.name === 'mileage.expiry_notification_enabled');
    expect(notiToggle).toBeTruthy();
  });

  it('채널 직접 선택 대신 mileage_expiring_soon definition 의 템플릿별 활성 상태를 읽기전용 표시한다', () => {
    // 코어 알림 인프라 도입 후, 채널은 알림 설정에서 관리. 설정 카드는 상태만 표시.
    const json = JSON.stringify(notificationCard);
    expect(json).not.toContain('settings?.data?.mileage?.notification_channels');

    const statusRow = findFirst(
      notificationCard,
      (n) => n.iteration && String(n.iteration.source).includes("'mileage_expiring_soon'"),
    );
    expect(statusRow).toBeTruthy();
    expect(statusRow.iteration.item_var).toBe('tpl');
    expect(String(statusRow.iteration.source)).toContain('templates');
    // 각 행이 tpl.is_active 로 활성/비활성 배지를 표시
    const badge = findFirst(statusRow, (n) => n.name === 'Span' && String(n.text ?? '').includes('tpl.is_active'));
    expect(badge).toBeTruthy();
    // 각 행이 "어떤 템플릿인지" 알 수 있도록 채널명 + 템플릿 제목(subject)을 표시
    const channelLabel = findFirst(statusRow, (n) => n.name === 'Span' && String(n.text ?? '').includes('tpl.channel'));
    expect(channelLabel).toBeTruthy();
    const subject = findFirst(statusRow, (n) => n.name === 'Span' && String(n.text ?? '').includes('tpl.subject'));
    expect(subject).toBeTruthy();
  });

  it('연결된 템플릿이 0개면 안내 문구가 노출된다', () => {
    const empty = findFirst(
      notificationCard,
      (n) => n.if && String(n.if).includes("'mileage_expiring_soon'") && String(n.if).includes('.length === 0'),
    );
    expect(empty).toBeTruthy();
  });

  it('알림 설정으로 이동 버튼이 환경설정 알림 탭으로 전환한다', () => {
    const btn = findById(notificationCard, 'go_to_notification_settings');
    const seq = btn.actions[0];
    expect(seq.handler).toBe('sequence');
    const navigate = seq.params.actions.find((a: any) => a.handler === 'navigate');
    expect(navigate.params.path).toBe('/admin/ecommerce/settings');
    expect(navigate.params.query.tab).toBe('notification_definitions');
    const setGlobal = seq.params.actions.find((a: any) => a.handler === 'setState');
    expect(setGlobal.params.activeEcommerceSettingsTab).toBe('notification_definitions');
  });
});

/**
 * @file adminShippingPolicyClaim.test.ts
 * @description 배송정책 폼 클레임 확장 — 구조 계약 (S10)
 *
 * 배송정책의 클레임 축은 **저장 경로가 둘**이다. 국가별 클레임 배송비 3종은 본 폼 PUT 이
 * 받고, 회수지는 본 폼이 받지 않아 `claim-settings` 로 따로 나간다. 그래서 이 화면에서만
 * 생기는 조용한 실패가 둘 있다:
 *
 *  1. 회수지를 입력했는데 이어지는 요청이 안 나가면 저장 성공 토스트만 뜨고 값이 사라진다.
 *  2. 회수지 저장이 실패했는데 목록으로 나가면 그 실패가 화면에서 사라진다 — 정책은 이미
 *     저장돼 있으므로 운영자는 전부 저장된 줄 안다.
 *
 * @vitest-environment node
 */

import { describe, expect, it } from 'vitest';
import { callbacksOf, initStateOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import shell from '../../../layouts/admin/admin_ecommerce_shipping_policy_form.json';
import claimAddresses from '../../../layouts/admin/partials/admin_ecommerce_shipping_policy_form/_partial_claim_addresses.json';
import claimCountryFee from '../../../layouts/admin/partials/admin_ecommerce_shipping_policy_form/_partial_claim_country_fee.json';

/**
 * 서브트리에서 조건에 맞는 노드를 모은다.
 *
 * @param node 시작 노드
 * @param match 판정 함수
 * @returns 일치한 노드 배열
 */
function collect(node: any, match: (n: any) => boolean): any[] {
  const found: any[] = [];
  const walk = (n: any): void => {
    if (!n || typeof n !== 'object') return;
    if (Array.isArray(n)) { n.forEach(walk); return; }
    if (match(n)) found.push(n);
    Object.values(n).forEach(walk);
  };
  walk(node);

  return found;
}

const partials = collect(shell, (n) => typeof n.partial === 'string').map((n) => n.partial);

/**
 * @scenario claim_scope=pickup_address
 *
 * 마킹을 한 줄로 이으면 안 된다 — 라인 주석 경로의 추출기는 `@scenario` 위치에서 500자만
 * 잘라 그 안에서 `@effects` 를 찾는다. 이 목록은 534자라 마지막 항목이 잘려 존재하지 않는
 * effect 가 만들어지고 9번째는 통째로 사라졌다(6차 검증). docblock 경로에는 그 창이 없고
 * 이어지는 ` * ` 줄을 같은 태그의 값으로 읽으므로, 줄바꿈으로 나눠 적는다.
 *
 * 이 docblock 이 주장하는 effect 는 **바로 아래 describe 가 보는 것**으로 한정한다. 종전에는
 * 9개를 여기 몰아 적었는데 이 describe 는 partial 배치 2건만 본다 — 커버리지 검사는 파일 전체의
 * 마킹을 집합으로 합쳐 비교하므로 어느 케이스가 그 effect 를 실제로 보는지는 검사 대상이 아니고,
 * 그래서 나머지 7개는 "누가 보는지 모르는 채" 초록이었다. 각 effect 는 그것을 검증하는 describe
 * 위에 적는다.
 *
 * @effects shipping_policy_claim_address_block_is_placed_after_basic_info,
 *   shipping_policy_claim_fee_block_is_placed_in_country_tab
 */
describe('배송정책 폼 — 클레임 블록 배치', () => {
  it('회수지는 기본정보 바로 아래에 놓인다', () => {
    const basic = partials.indexOf('partials/admin_ecommerce_shipping_policy_form/_partial_basic_info.json');
    const claim = partials.indexOf('partials/admin_ecommerce_shipping_policy_form/_partial_claim_addresses.json');

    expect(basic).toBeGreaterThanOrEqual(0);
    expect(claim).toBe(basic + 1);
  });

  it('국가별 클레임 배송비는 부과 설정 다음, 추가배송비 앞에 놓인다', () => {
    const charge = partials.indexOf('partials/admin_ecommerce_shipping_policy_form/_partial_charge_settings.json');
    const claimFee = partials.indexOf('partials/admin_ecommerce_shipping_policy_form/_partial_claim_country_fee.json');
    const extra = partials.indexOf('partials/admin_ecommerce_shipping_policy_form/_partial_extra_fee.json');

    expect(claimFee).toBe(charge + 1);
    expect(extra).toBe(claimFee + 1);
  });
});

/**
 * @scenario claim_scope=pickup_address
 *
 * 마킹은 **라인 주석이 아니라 docblock** 으로 적는다. 라인 주석 경로의 추출기는 `@effects`
 * 첫 줄만 읽어 두 번째 줄부터가 조용히 사라지고, 한 줄로 이으면 500자 창에 잘린다. docblock
 * 경로는 이어지는 ` * ` 줄을 같은 태그의 값으로 읽으므로 둘 다 피할 수 있다.
 *
 * @effects shipping_policy_claim_address_loads_only_in_edit_mode,
 *   shipping_policy_claim_address_load_failure_does_not_break_screen,
 *   shipping_policy_claim_address_has_all_fields_per_type,
 *   shipping_policy_claim_address_saves_only_when_touched,
 *   shipping_policy_claim_address_empty_sends_null_to_delete,
 *   shipping_policy_claim_address_failure_keeps_the_screen,
 *   shipping_policy_claim_address_navigates_once_on_success
 */
describe('회수지 — 조회와 저장', () => {
  const source = (shell as any).data_sources.find((d: any) => d.id === 'claim_settings');

  it('수정 모드에서만 회수지를 조회한다 (등록 모드에는 정책 id 가 없다)', () => {
    expect(source).toBeDefined();
    expect(source.if).toBe('{{!!route.id}}');
    expect(source.endpoint).toContain('/claim-settings');
  });

  it('편집 버퍼의 시드는 initLocal 이 아니라 응답 시점의 setState 다', () => {
    // `initLocal` 은 응답 시점 한 번으로 끝나지 않는다 — 마운트 뒤 한 번 더 적용되며, 그
    // 재적용이 **명시적 setState 로 기록한 편집만** 되돌린다(같은 화면의 Form 자동바인딩
    // 필드는 살아남는다). 회수지 5필드는 자동바인딩이 아니라 change→setState 로 기록하므로,
    // 화면이 열린 직후 친 글자가 화면에는 그대로 남은 채 저장 payload 에서만 사라졌다 —
    // 서버는 그 칸을 "필수 항목 누락" 으로 거절한다.
    expect(source.initLocal).toBeUndefined();

    const seeds = (Array.isArray(source.onSuccess) ? source.onSuccess : [source.onSuccess])
      .filter((a: any) => a?.handler === 'setState');

    // 시드 하나가 편집 버퍼와 로딩 표식을 함께 쓴다 — 편집이 먼저 일어났으면(dirty) 편집값을 보존하고
    // `_loaded` 만 합친다. 표식을 별도 키·별도 setState 로 두면 입력 change 뒤에 붙지 않는다(10차 실측).
    expect(seeds).toHaveLength(1);
    expect(seeds[0].params.target).toBe('local');
    expect(seeds[0].params.claimAddresses).toContain('response.data');
    expect(seeds[0].params.claimAddresses).toContain('_loaded: true');
    expect(seeds[0].params.claimAddresses).toContain('_local.claimAddressesDirty === true ? _local.claimAddresses?.addresses');
    expect(seeds[0].if).toBeUndefined();
  });

  it('조회가 끝나기 전에는 회수지 입력이 잠기고 안내가 선다 (수정 화면만)', () => {
    // 로딩 표식은 데이터소스 onSuccess 가 세우는데 편집이 먼저 일어나면 그 쓰기가 붙지 않는다(10차 실측).
    // 그래서 편집 자체를 조회 완료 뒤로 미룬다 — 신규 등록은 init_actions 가 표식을 먼저 세운다.
    const gated = collect(claimAddresses, (n) => typeof n.props?.disabled === 'string');
    // 대조 모집단은 형제 검사(adminEcommerceSettingsClaim: '읽기 전용 운영자에게는 모든 입력이
    // 잠긴다')와 **같은 폭**이어야 한다. 그 검사가 이미 진단한 탈출구가 여기 그대로 남아
    // 있었다 — 이름 화이트리스트가 3종이면 목록 밖의 입력형(Checkbox·Toggle·MultilingualInput·
    // TagInput·ChipCheckbox·NumberInput·DatePicker)이 `disabled` 없이 들어와도 `inputsAndClears`
    // 에 담기지 않아 잠금 검사 밖에 남는다. 저장소가 아는 입력형 전집합으로 맞춘다.
    const INPUT_NAMES = [
      'Input', 'Select', 'TagInput', 'Toggle', 'MultilingualInput',
      'Textarea', 'Checkbox', 'RadioGroup', 'ChipCheckbox', 'NumberInput', 'DatePicker',
    ];
    // 하한이 아니라 정확값이다 — 잠금 표현식이 빠진 입력은 `gated` 에 안 잡혀 검사 밖으로 새므로, 입력 전수(10) + 지우기 버튼(2) 와 대조한다.
    const inputsAndClears = collect(claimAddresses, (n) => INPUT_NAMES.includes(n?.name) || (n?.name === 'Button' && String(n?.props?.className ?? '').includes('clear')) || typeof n?.props?.disabled === 'string');
    expect(gated.length).toBe(12);
    expect(inputsAndClears.filter((n) => typeof n.props?.disabled !== 'string')).toEqual([]);
    gated.forEach((n) => {
      expect(n.props.disabled).toBe('{{_computed.isReadOnly || (!!route.id && _local.claimAddresses?._loaded !== true)}}');
    });

    const notice = collect(claimAddresses, (n) => n.id === 'claim_addresses_loading_notice')[0];
    expect(notice).toBeDefined();
    expect(notice.if).toBe('{{!!route.id && _local.claimAddresses?._loaded !== true}}');
    expect(notice.text).toContain('claim_addresses_loading_notice');
  });

  it('조회가 성공하기 전에는 회수지 저장을 보내지 않는다 — 폴백 null 이 삭제 계약을 타지 않게', () => {
    // 편집 버퍼 초기값과 조회 실패 폴백이 둘 다 `{exchange:null, return:null}` 이라 "못 받았다" 와
    // "비어 있다" 가 값으로 구분되지 않는다. 저장은 두 회수지를 항상 함께 실으므로 조회가 실패한 채
    // 한쪽만 입력해 저장하면 다른 쪽이 확인창 없이 지워진다 — 저장 술어가 loaded 를 함께 본다.
    expect(initStateOf(shell as any).claimAddresses?._loaded).toBe(false);

    const call = collect(shell, (n) => n.handler === 'apiCall' && String(n.target ?? '').includes('claim-settings'))[0];
    expect(call.if).toBe('{{_local.claimAddressesDirty === true && _local.claimAddresses?._loaded === true}}');

    // 신규 등록은 조회 데이터소스가 돌지 않으므로 init_actions 가 플래그를 세운다 — 액션 체인의 `if` 는 `route` 를 읽지 못한다.
    const initFlag = ((shell as any).init_actions ?? []).find((a: any) => a.params?.['claimAddresses._loaded'] === true);
    expect(initFlag, '신규 등록 허용 플래그가 init_actions 에 없다').toBeDefined();
    expect(initFlag.if).toBe('{{!route.id}}');

    const warn = collect(shell, (n) => n.handler === 'toast' && String(n.params?.message ?? '').includes('claim_settings_not_loaded'))[0];
    expect(warn, '손댔는데 미로딩이면 저장하지 않았음을 알린다').toBeDefined();
    expect(warn.if).toBe('{{_local.claimAddressesDirty === true && _local.claimAddresses?._loaded !== true}}');
  });

  it('조회 실패는 화면을 막지 않는다 — 빈 회수지로 떨어진다', () => {
    expect(source.fallback?.data?.addresses).toEqual({ exchange: null, return: null });
  });

  it('교환·반품 회수지가 각각 5개 필드를 갖는다', () => {
    for (const type of ['exchange', 'return']) {
      const inputs = collect(claimAddresses, (n) => typeof n.id === 'string' && n.id.startsWith(`claim_address_${type}_`) && n.name === 'Input');

      expect(inputs.map((i) => i.id)).toEqual([
        `claim_address_${type}_recipient_name`,
        `claim_address_${type}_phone`,
        `claim_address_${type}_zipcode`,
        `claim_address_${type}_address1`,
        `claim_address_${type}_address2`,
      ]);
    }
  });

  it('회수지를 손댄 경우에만 이어지는 요청이 나간다', () => {
    const call = collect(shell, (n) => n.handler === 'apiCall' && String(n.target ?? '').includes('claim-settings'))[0];

    expect(call).toBeDefined();
    expect(call.if).toBe('{{_local.claimAddressesDirty === true && _local.claimAddresses?._loaded === true}}');
    expect(call.params.method).toBe('PUT');
  });

  it('비어 있는 회수지는 null 로 보내 삭제 계약을 탄다', () => {
    const call = collect(shell, (n) => n.handler === 'apiCall' && String(n.target ?? '').includes('claim-settings'))[0];

    for (const type of ['exchange', 'return']) {
      expect(call.params.body.addresses[type]).toContain(': null');
    }
  });

  it('회수지 저장이 실패하면 목록으로 나가지 않는다', () => {
    const call = collect(shell, (n) => n.handler === 'apiCall' && String(n.target ?? '').includes('claim-settings'))[0];

    expect(JSON.stringify(call.onError)).not.toContain('navigate');
    expect(JSON.stringify(call.onError)).toContain('error.errors');
  });

  it('저장 성공 경로는 손댔든 아니든 한 번만 이동한다', () => {
    const policyCall = collect(shell, (n) => n.handler === 'apiCall' && String(n.target ?? '').includes('shipping-policies') && !String(n.target ?? '').includes('claim-settings'))[0];
    const outer = policyCall.onSuccess.filter((a: any) => a.handler === 'navigate');
    const inner = collect(policyCall.onSuccess, (n) => n.handler === 'apiCall')[0].onSuccess.filter((a: any) => a.handler === 'navigate');

    expect(outer).toHaveLength(1);
    expect(inner).toHaveLength(1);
    // 손댔으면(dirty) 이탈은 회수지 PUT 체인이 맡고, 조회가 안 끝난 채 손댄 경우는 경고만 남기고 **머문다** —
    // 종전 `!(dirty && loaded)` 는 그 경우에 경고 토스트 직후 목록으로 나가 '저장되지 않았다' 는 사실을 화면에서 지웠다.
    expect(outer[0].if).toBe('{{_local.claimAddressesDirty !== true}}');
  });
});

/**
 * @scenario claim_scope=country_fee
 *
 * @effects shipping_policy_claim_fee_axes_bound_to_active_country,
 *   shipping_policy_claim_fixed_inputs_only_in_fixed_mode,
 *   shipping_policy_claim_original_policy_mode_explains_itself,
 *   shipping_policy_claim_fee_updates_go_through_country_field_handler
 */
describe('국가별 클레임 배송비', () => {
  const text = JSON.stringify(claimCountryFee);

  it('산정 방식 2종과 고정액 2종이 활성 국가 탭에 붙는다', () => {
    for (const field of ['claim_fee_mode', 'return_fixed_fee', 'exchange_fixed_fee']) {
      // `JSON.stringify` 는 콜론 뒤에 공백을 넣지 않으므로 바늘도 공백 없는 형태여야 한다.
      // 종전 표현은 `|| field` 우변이 **절대 평가되지 않는** 도달 불가 코드였고(좌변이 항상
      // 비어 있지 않은 문자열이다), 뒤따르는 `toContain(field)` 는 필드명이 주석·다른 키에만
      // 있어도 통과하는 더 약한 단언이라 실질 신호가 하나뿐이었다.
      expect(text).toContain(`"field":"${field}"`);
    }
    expect(text).toContain('_computed.activeCountrySetting');
  });

  it('고정액 입력은 고정 금액 모드에서만 보인다', () => {
    const fixedBlock = collect(claimCountryFee, (n) => n.id === 'claim_fixed_fee_fields')[0];

    expect(fixedBlock.if).toContain("=== 'fixed'");
  });

  it('원 정책 재계산 모드는 무엇이 계산되는지 화면이 밝힌다', () => {
    const notice = collect(claimCountryFee, (n) => n.id === 'claim_fee_original_policy_notice')[0];

    expect(notice.if).toContain("=== 'original_policy'");
  });

  it('배열 인덱스 필드 갱신은 전용 핸들러를 거친다 (setState 키에 표현식 금지)', () => {
    const actions = collect(claimCountryFee, (n) => Array.isArray(n.actions)).flatMap((n) => n.actions);

    expect(actions.length).toBeGreaterThan(0);
    for (const action of actions) {
      expect(action.handler).toBe('sirsoft-ecommerce.updateCountryField');
    }
  });

  /**
   * 이 두 케이스는 회수지(별도 엔드포인트) 축이라 이 describe 의 `country_fee` 를 물려받으면
   * 안 된다 — 매니페스트가 두 축을 나눈 이유가 "본 폼 PUT" 과 "별도 엔드포인트" 의 계약이
   * 다르기 때문이고, 상속으로 채워진 셀은 다른 축의 계약으로 채워진 셈이 된다(14차 정정).
   * 이 파일 docblock 이 세운 규율("각 effect 는 그것을 검증하는 describe 위에")의 축 판이다.
   *
   * @scenario claim_scope=pickup_address
   *
   * @effects shipping_policy_claim_address_delete_requires_all_fields_empty
   */
  it('회수지 삭제 판정은 모든 칸이 비었을 때만 내린다', () => {
    // 필수 2칸(수취인명·주소1)만 보고 null 로 바꾸면 두 가지가 조용히 일어난다 —
    // 전화·우편번호만 친 상태는 오류 없이 버려지고(성공 토스트까지 뜼다),
    // 저장된 회수지에서 필수 2칸만 지우면 확인창 없이 회수지가 통째로 삭제된다
    // (전용 지우기 버튼에는 confirm 이 있다). 일부만 채워진 입력은 그대로 보내
    // 서버가 무엇이 빠졌는지 422 로 알려야 한다.
    const call = collect(shell, (n) => n?.handler === 'apiCall' && String(n?.target ?? '').includes('claim-settings'))[0];

    expect(call).toBeDefined();
    for (const type of ['exchange', 'return']) {
      const expr = call.params?.body?.addresses?.[type] ?? '';

      expect(expr).toContain('.some(');
      for (const field of ['recipient_name', 'address1', 'address2', 'zipcode', 'phone']) {
        expect(expr, `${type} 삭제 판정이 ${field} 을 보지 않는다`).toContain(field);
      }
    }
  });

  /**
   * @scenario claim_scope=pickup_address
   *
   * @effects shipping_policy_save_retry_updates_the_same_policy
   */
  it('방금 만든 정책을 기억해 재저장이 같은 정책을 고친다', () => {
    // 신규 등록에서 정책 POST 는 성공했는데 뒤이은 회수지 PUT 이 거절되면 화면에
    // 머무는데, route.id 는 여전히 비어 있어 다시 [저장] 을 누르면 같은 정책이
    // 하나 더 생긴다. 첫 성공 시점에 그 id 를 붙잡아 재저장을 PUT 으로 보낸다.
    const save = collect(shell, (n) => n?.handler === 'apiCall' && String(n?.target ?? '').includes('shipping-policies') && !String(n?.target ?? '').includes('claim-settings'))[0];

    expect(save).toBeDefined();
    expect(save.target).toContain('_local.savedPolicyId');
    expect(save.params?.method).toContain('_local.savedPolicyId');

    const record = callbacksOf(save, 'onSuccess').find((a: any) => a?.handler === 'setState' && 'savedPolicyId' in (a.params ?? {}));

    expect(record, '저장 성공 직후 savedPolicyId 를 기록하지 않는다').toBeDefined();
    expect(initStateOf(shell as any)).toHaveProperty('savedPolicyId');
  });

  /**
   * @effects shipping_policy_claim_fee_mode_radios_form_one_group
   */
  it('산정 방식 라디오 2개는 한 그룹이고 폼 자동바인딩을 타지 않는다', () => {
    // name 이 없으면 네이티브 라디오 그룹이 없어 방향키 이동·스크린리더 그룹
    // 시맨틱이 사라지고, 그대로 name 만 붙이면 값이 _local.form 으로 자동바인딩되어
    // 핸들러가 쓰는 상태와 두 군데가 된다. 선례(_partial_other_info)는 둘을 함께 둔다.
    const radios = collect(claimCountryFee, (n) => n?.props?.type === 'radio');

    expect(radios).toHaveLength(2);
    for (const radio of radios) {
      expect(radio.props.name).toBe('claim_fee_mode');
      expect(radio.props.autoBinding).toBe(false);
      // `toBeTruthy` 는 어떤 문자열이든 통과한다 — 현재 모드와의 비교식이어야 한다.
      expect(radio.props.checked).toMatch(/claim_fee_mode/);
      expect(radio.props.checked).toContain('===');
    }
  });
});

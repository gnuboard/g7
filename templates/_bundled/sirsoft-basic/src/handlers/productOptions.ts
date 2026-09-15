/**
 * 상품 옵션 선택 관련 핸들러
 *
 * 상품 상세 페이지에서 옵션 선택 및 수량 변경을 처리합니다.
 * DB의 option_groups (문자열 값 배열)과 options (ProductOption 레코드)를 사용합니다.
 *
 * G7Core ActionDispatcher는 커스텀 핸들러를 (action, context) 시그니처로 호출합니다.
 * - action.params: 레이아웃 JSON에서 정의한 params (resolveParams로 이미 해석됨)
 * - context.setState: 컴포넌트 상태 업데이트 함수
 *
 * ⚠️ sequence 내에서 setState 후 다음 액션의 context.data._local은 갱신되지 않음
 *    (ActionDispatcher.handleSequence는 state만 동기화, data._local은 미갱신)
 *    따라서 이 핸들러는 newGroupName+newValue를 직접 받아서 currentSelection을 자체 구성합니다.
 */

/**
 * 옵션 그룹 구조 (다국어 지원)
 * API 응답: { name: {ko: "색상", en: "Color"}, name_localized: "색상", values: [...], values_localized: [...] }
 */
interface OptionGroup {
  name: string | Record<string, string>;
  name_localized?: string;
  values: string[] | Array<Record<string, string>>;
  values_localized?: string[];
}

/**
 * 옵션 값 항목 (다국어 지원 배열 형식)
 */
interface OptionValueItem {
  key: string | Record<string, string>;
  value: string | Record<string, string>;
}

/**
 * ProductOption 레코드
 * option_values: 배열 형식 (신규) 또는 객체 형식 (레거시)
 * option_values_localized: 현재 로케일 값 객체 (신규)
 */
interface ProductOptionRecord {
  id: number;
  option_code: string;
  option_values: OptionValueItem[] | Record<string, string>;
  option_values_localized?: Record<string, string>;
  option_name: string | Record<string, string>;
  option_name_localized?: string;
  price_adjustment: number;
  selling_price: number;
  selling_price_formatted: string;
  list_price: number;
  list_price_formatted: string;
  multi_currency_selling_price?: Record<string, { value: number; formatted: string }>;
  multi_currency_list_price?: Record<string, { value: number; formatted: string }>;
  stock_quantity: number;
  is_active: boolean;
}

/**
 * 추가옵션 선택지 (PublicProductResource.additional_options[].values[])
 */
interface AdditionalOptionValue {
  id: number;
  name: string;
  /** 추가금 (쇼핑몰 **기본 통화** 기준 — KRW 고정이 아니다) */
  price_adjustment: number;
  /**
   * 통화별 추가금 (서버 `multi_currency_price_adjustment`).
   *
   * 표시통화가 기본통화와 다르면 `price_adjustment` 를 그대로 쓰면 안 된다.
   * 상품가는 환산되어 오는데 추가금만 기준통화로 남으면 합계에서 통화가 섞인다.
   */
  multi_currency_price_adjustment?: Record<string, { price?: number; value?: number; formatted: string }>;
  is_default: boolean;
  /** 직접입력 허용 — 이 선택지 선택 시 자유 텍스트 입력칸 노출(입력 필수) */
  allow_custom_text?: boolean;
}

/**
 * 추가옵션 그룹 (PublicProductResource.additional_options[])
 */
interface AdditionalOptionGroup {
  id: number;
  name: string;
  is_required: boolean;
  values: AdditionalOptionValue[];
}

/**
 * 블럭별 추가옵션 선택 상태 (additional_option_id → value_id)
 */
type AdditionalOptionSelections = Record<number, number>;

interface SelectedItem {
  id: string;
  optionId: number;
  options: Record<string, string>;
  optionValues: Record<string, string>;
  quantity: number;
  stock: number;
  unitPrice: number;
  unitPriceFormatted: string;
  totalPrice: number;
  totalPriceFormatted: string;
  multiCurrencyUnitPrice?: Record<string, { value: number; formatted: string }>;
  multiCurrencyTotalPrice?: Record<string, { value: number; formatted: string }>;
  /** 블럭별 추가옵션 선택 (그룹ID → 선택지ID) */
  additionalOptionSelections?: AdditionalOptionSelections;
  /** 블럭별 추가옵션 직접입력 텍스트 (그룹ID → custom_text). allow_custom_text 선택지 한정 */
  additionalOptionCustomTexts?: Record<number, string>;
  /** 블럭별 추가옵션 추가금 합계 (기본 통화 기준, 단위당) */
  additionalOptionsTotal?: number;
  /** 블럭별 추가옵션 추가금 합계의 통화별 값 (단위당). 표시통화 합계 계산에 쓴다 */
  additionalOptionsMultiCurrencyTotal?: Record<string, { value: number }>;
}

interface AddSelectedItemParams {
  productId: number;
  optionGroups: OptionGroup[];
  options: ProductOptionRecord[];
  currentSelection: Record<string, string>;
  selectedOptionItems: SelectedItem[];
  preferredCurrency: string;
  /** 상품 추가옵션 카탈로그 (기본 선택지 자동 적용용) */
  additionalOptionGroups?: AdditionalOptionGroup[];
  /** sequence 상태 동기화 우회: 방금 선택한 그룹명 */
  newGroupName?: string;
  /** sequence 상태 동기화 우회: 방금 선택한 값 */
  newValue?: string;
}

interface UpdateQuantityParams {
  itemIndex: number;
  newQuantity: number;
  selectedOptionItems: SelectedItem[];
  preferredCurrency: string;
}

interface SetBlockAdditionalOptionParams {
  itemIndex: number;
  additionalOptionId: number;
  valueId: number | string;
  /** 직접입력 텍스트 입력 시 전달 (value 선택 변경 시에는 미전달) */
  customText?: string;
  selectedOptionItems: SelectedItem[];
  additionalOptionGroups: AdditionalOptionGroup[];
  preferredCurrency: string;
}

/**
 * G7Core ActionContext (ActionDispatcher에서 전달)
 */
interface ActionContext {
  data?: any;
  event?: Event;
  state?: any;
  setState?: (updates: any) => void;
}

/**
 * G7Core ActionDefinition (커스텀 핸들러 첫 번째 인자)
 */
interface ActionDefinition {
  handler: string;
  params?: Record<string, any>;
  target?: string;
  [key: string]: any;
}

/**
 * 통화 코드별 소수 자릿수 폴백 (설정에 decimal_places 가 없을 때만 사용).
 */
const DECIMAL_PLACES_FALLBACK: Record<string, number> = {
  KRW: 0,
  JPY: 0,
};

/**
 * 쇼핑몰 설정에 등록된 통화 목록을 전역 상태에서 읽습니다.
 *
 * @returns currencies 배열 (읽지 못하면 빈 배열)
 */
function getConfiguredCurrencies(): Array<{ code: string; decimal_places?: number; is_default?: boolean }> {
  try {
    const state = (window as any).G7Core?.state?.get?.() || {};
    const lc = state?.modules?.['sirsoft-ecommerce']?.language_currency;

    return Array.isArray(lc?.currencies) ? lc.currencies : [];
  } catch {
    return [];
  }
}

/**
 * 숫자를 통화 형식으로 포맷팅
 *
 * 통화 목록은 운영자가 관리자에서 추가/삭제하므로 고정 통화표를 두지 않는다. 설정에 없는
 * 통화를 특정 통화로 폴백시키면(예: GBP 를 원화 자릿수로) 값은 맞고 단위만 틀린 금액이 나간다.
 * 자릿수는 설정의 decimal_places 를 따르고, 기호·표기 규칙은 Intl 이 통화 코드로 판정한다.
 *
 * @param amount 금액
 * @param currencyCode 통화 코드 (미지정 시 설정의 기본 통화)
 * @returns 포맷된 금액 문자열
 */
function formatPrice(amount: number, currencyCode?: string): string {
  if (!Number.isFinite(amount)) return '0';

  const currencies = getConfiguredCurrencies();
  const code = currencyCode || currencies.find((c) => c.is_default)?.code;

  if (!code) {
    // 통화를 판정할 수 없으면 단위를 임의로 붙이지 않는다.
    return amount.toLocaleString();
  }

  const decimals = currencies.find((c) => c.code === code)?.decimal_places
    ?? DECIMAL_PLACES_FALLBACK[code]
    ?? 2;

  try {
    return new Intl.NumberFormat(undefined, {
      style: 'currency',
      currency: code,
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals,
    }).format(amount);
  } catch {
    return `${amount.toLocaleString(undefined, {
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals,
    })} ${code}`;
  }
}

/**
 * 다중 통화 총 가격 재계산
 *
 * 추가옵션 추가금은 서버가 통화별로 환산해 내려준 맵(`additionalUnitByCurrency`)에서 읽는다.
 *
 * 종전에는 추가금을 KRW 로 간주해 표시통화가 KRW 이면 환산 없이 그대로 더했다. 추가금은
 * 쇼핑몰 **기본 통화** 기준이라 기본통화가 KRW 인 쇼핑몰에서만 우연히 맞았고, 기본통화가
 * 다른 쇼핑몰에서는 "환산된 상품가 + 환산 안 된 추가금" 이라는 통화가 섞인 합계가 나왔다.
 *
 * 서버 맵이 없는 응답(구버전)에서는 단가 비율로 환산한다. 이때 기준은 **기본 통화**다 —
 * 종전처럼 KRW 를 기준으로 잡으면 기본통화가 KRW 가 아닌 쇼핑몰에서 어긋난다. 기본 통화를
 * 판정할 수 없는 형태(내부 `{value, formatted}` 맵)면 마지막 수단으로 KRW 를 기준으로 둔다.
 *
 * @param unitPriceMap 옵션 단가 다통화 맵
 * @param quantity 수량
 * @param additionalUnitByCurrency 통화별 추가옵션 추가금 합계 (단위당)
 * @param additionalUnitBase 기본 통화 기준 추가금 합계 (단위당, 폴백 환산용)
 */
function recalcMultiCurrencyTotal(
  unitPriceMap: Record<string, { value: number; formatted: string }> | undefined,
  quantity: number,
  additionalUnitByCurrency?: Record<string, { value: number }>,
  additionalUnitBase: number = 0
): Record<string, { value: number; formatted: string }> | undefined {
  if (!unitPriceMap) return undefined;

  const readAmount = (entry: any): number => entry?.value ?? entry?.price ?? 0;

  // 폴백 환산의 기준 통화: is_default 로 표시된 통화 → 없으면 KRW (기존 동작 보존)
  let pivotCode: string | undefined;
  for (const [code, data] of Object.entries(unitPriceMap)) {
    if ((data as any)?.is_default) {
      pivotCode = code;
      break;
    }
  }
  pivotCode = pivotCode ?? (unitPriceMap.KRW ? 'KRW' : undefined);
  const pivotUnit = pivotCode ? readAmount(unitPriceMap[pivotCode]) : 0;

  const result: Record<string, { value: number; formatted: string }> = {};
  for (const [code, data] of Object.entries(unitPriceMap)) {
    const unitValue = readAmount(data);

    let additionalValue: number;
    if (additionalUnitByCurrency) {
      // 서버가 통화별로 환산해 준 값이 있으면 그것이 정답이다
      additionalValue = additionalUnitByCurrency[code]?.value ?? 0;
    } else if (additionalUnitBase > 0 && code === pivotCode) {
      additionalValue = additionalUnitBase;
    } else if (additionalUnitBase > 0 && pivotUnit > 0) {
      additionalValue = (additionalUnitBase * unitValue) / pivotUnit;
    } else {
      additionalValue = 0;
    }

    const total = (unitValue + additionalValue) * quantity;
    result[code] = {
      value: total,
      formatted: formatPrice(total, code),
    };
  }
  return result;
}

/**
 * 선택된 옵션 항목들의 통화별 합계 포맷 맵을 계산합니다.
 *
 * 레이아웃 "총 금액"은 표현식 컨텍스트에서 핸들러를 호출할 수 없으므로(엔진 제약),
 * 합계 금액을 통화별로 미리 포맷해 _local 에 노출한다 → 레이아웃은 단순 조회만 한다.
 * (선택 통화 KRW 고정 결함 D2 해소)
 *
 * @param items 선택된 옵션 항목 목록
 * @returns 통화코드 => { value, formatted } 합계 맵
 */
function buildSelectedTotalMultiCurrency(
  items: SelectedItem[]
): Record<string, { value: number; formatted: string }> {
  const totals: Record<string, number> = {};
  for (const item of items) {
    const mc = item?.multiCurrencyTotalPrice;
    if (mc) {
      for (const [code, data] of Object.entries(mc)) {
        const v = (data as any)?.value ?? (data as any)?.price ?? 0;
        totals[code] = (totals[code] ?? 0) + v;
      }
    }
  }
  const result: Record<string, { value: number; formatted: string }> = {};
  for (const [code, value] of Object.entries(totals)) {
    result[code] = { value, formatted: formatPrice(value, code) };
  }
  return result;
}

/**
 * 블럭의 추가옵션 선택으로부터 추가금 합계(KRW, 단위당)를 계산한다.
 *
 * @param selections 블럭별 추가옵션 선택 (그룹ID → 선택지ID)
 * @param groups 상품 추가옵션 카탈로그
 * @returns 추가금 합계 (KRW)
 */
function computeAdditionalOptionsTotal(
  selections: AdditionalOptionSelections | undefined,
  groups: AdditionalOptionGroup[] | undefined
): number {
  if (!selections || !groups?.length) return 0;
  let total = 0;
  for (const group of groups) {
    const valueId = selections[group.id];
    if (valueId == null) continue;
    const value = group.values?.find(v => v.id === Number(valueId));
    if (value) total += value.price_adjustment ?? 0;
  }
  return total;
}

/**
 * 선택된 추가옵션 추가금의 **통화별** 합계를 계산합니다 (단위당).
 *
 * 서버가 선택지마다 내려주는 `multi_currency_price_adjustment` 를 통화코드별로 합산한다.
 * 그 맵이 없는 선택지(구버전 응답)는 기본통화 값만 있는 셈이므로 합산에서 제외하지 않고
 * 통화 구분 없이 더하면 통화가 섞이므로, 맵이 있는 선택지만 통화별 합계에 반영한다.
 *
 * @param selections 블럭별 추가옵션 선택 (그룹ID → 선택지ID)
 * @param groups 추가옵션 카탈로그
 * @returns 통화코드 => { value } 합계 맵 (선택 없음/맵 없음이면 undefined)
 */
function computeAdditionalOptionsMultiCurrencyTotal(
  selections: AdditionalOptionSelections | undefined,
  groups: AdditionalOptionGroup[] | undefined
): Record<string, { value: number }> | undefined {
  if (!selections || !groups?.length) return undefined;

  const totals: Record<string, number> = {};
  let found = false;

  for (const group of groups) {
    const valueId = selections[group.id];
    if (valueId == null) continue;

    const value = group.values?.find(v => v.id === Number(valueId));
    const map = value?.multi_currency_price_adjustment;
    if (!map) continue;

    found = true;
    for (const [code, entry] of Object.entries(map)) {
      const amount = (entry as any)?.value ?? (entry as any)?.price ?? 0;
      totals[code] = (totals[code] ?? 0) + amount;
    }
  }

  if (!found) return undefined;

  const result: Record<string, { value: number }> = {};
  for (const [code, value] of Object.entries(totals)) {
    result[code] = { value };
  }
  return result;
}

/**
 * 추가옵션 선택을 백엔드 입력 형식으로 변환한다.
 * additional_option_selections: [{ additional_option_id, value_id }]
 *
 * @param selections 블럭별 추가옵션 선택 (그룹ID → 선택지ID)
 * @param customTexts 블럭별 직접입력 텍스트 (그룹ID → custom_text)
 * @returns 백엔드 입력 배열
 */
export function toAdditionalOptionSelectionsPayload(
  selections: AdditionalOptionSelections | undefined,
  customTexts?: Record<number, string>
): Array<{ additional_option_id: number; value_id: number; custom_text?: string }> {
  if (!selections) return [];
  return Object.entries(selections)
    .filter(([, valueId]) => valueId != null)
    .map(([groupId, valueId]) => {
      const entry: { additional_option_id: number; value_id: number; custom_text?: string } = {
        additional_option_id: Number(groupId),
        value_id: Number(valueId),
      };
      const customText = customTexts?.[Number(groupId)];
      if (typeof customText === 'string' && customText.trim() !== '') {
        entry.custom_text = customText.trim();
      }
      return entry;
    });
}

/**
 * 옵션 그룹의 키 (name_localized 우선, 폴백으로 name)
 */
function getGroupKey(group: OptionGroup): string {
  if (group.name_localized) return group.name_localized;
  if (typeof group.name === 'string') return group.name;
  return (group.name as Record<string, string>)?.ko ?? '';
}

/**
 * option_values에서 특정 그룹 키의 값 추출
 * 배열 형식(신규)과 객체 형식(레거시) 모두 지원
 */
function getOptionValueByGroupKey(
  optionValues: OptionValueItem[] | Record<string, string>,
  optionValuesLocalized: Record<string, string> | undefined,
  groupKey: string
): string | undefined {
  // option_values_localized가 있으면 우선 사용
  if (optionValuesLocalized && groupKey in optionValuesLocalized) {
    return optionValuesLocalized[groupKey];
  }

  // 배열 형식 (신규)
  if (Array.isArray(optionValues)) {
    const item = optionValues.find(v => {
      if (typeof v.key === 'string') return v.key === groupKey;
      return (v.key as Record<string, string>)?.ko === groupKey || Object.values(v.key).includes(groupKey);
    });
    if (item) {
      if (typeof item.value === 'string') return item.value;
      return (item.value as Record<string, string>)?.ko ?? Object.values(item.value)[0];
    }
    return undefined;
  }

  // 객체 형식 (레거시)
  return optionValues[groupKey];
}

/**
 * option_values를 Record<string, string> 형식으로 변환
 * SelectedItem.optionValues 저장용 (현재 로케일 값으로 변환)
 */
function convertOptionValuesToRecord(
  optionValues: OptionValueItem[] | Record<string, string>,
  optionValuesLocalized?: Record<string, string>
): Record<string, string> {
  // option_values_localized가 있으면 우선 사용
  if (optionValuesLocalized) {
    return optionValuesLocalized;
  }

  // 이미 객체 형식이면 그대로 반환
  if (!Array.isArray(optionValues)) {
    return optionValues;
  }

  // 배열 형식을 객체로 변환
  const result: Record<string, string> = {};
  for (const item of optionValues) {
    const key = typeof item.key === 'string'
      ? item.key
      : (item.key as Record<string, string>)?.ko ?? Object.values(item.key)[0] ?? '';
    const value = typeof item.value === 'string'
      ? item.value
      : (item.value as Record<string, string>)?.ko ?? Object.values(item.value)[0] ?? '';
    if (key) {
      result[key] = value;
    }
  }
  return result;
}

/**
 * 현재 선택된 값으로 매칭되는 ProductOption 찾기
 * name_localized를 키로 사용하여 선택값과 비교
 */
function findMatchingOption(
  optionGroups: OptionGroup[],
  currentSelection: Record<string, string>,
  options: ProductOptionRecord[]
): ProductOptionRecord | undefined {
  return options.find(opt => {
    if (!opt?.option_values || !opt?.is_active) return false;
    return optionGroups.every(group => {
      const groupKey = getGroupKey(group);
      const selectedValue = currentSelection?.[groupKey];
      const optValue = getOptionValueByGroupKey(
        opt.option_values,
        opt.option_values_localized,
        groupKey
      );
      return optValue === selectedValue;
    });
  });
}

/**
 * 옵션 선택 완료 시 selectedItems에 추가
 *
 * 모든 옵션 그룹이 선택되면 매칭되는 ProductOption을 찾아 선택 목록에 추가합니다.
 * 동일한 옵션 조합이 이미 있으면 토스트 알림 + 선택 초기화합니다.
 *
 * ⚠️ sequence 내 setState 후 context.data._local이 갱신되지 않는 G7Core 한계 때문에
 *    newGroupName + newValue를 직접 받아서 currentSelection을 자체 병합합니다.
 *
 * G7Core에서 (action: ActionDefinition, context: ActionContext) 시그니처로 호출됩니다.
 */
export function addSelectedItemIfCompleteHandler(
  action: ActionDefinition,
  context: ActionContext
): void {
  const params = action.params as AddSelectedItemParams;
  if (!params) return;

  const { optionGroups, options, selectedOptionItems, preferredCurrency } = params;

  // sequence 내 setState 미반영 대응: newGroupName/newValue가 있으면 직접 병합
  let currentSelection: Record<string, string>;
  if (params.newGroupName && params.newValue) {
    currentSelection = {
      ...(params.currentSelection ?? {}),
      [params.newGroupName]: params.newValue,
    };
  } else {
    currentSelection = params.currentSelection ?? {};
  }

  // 모든 옵션 그룹이 선택되었는지 확인 (name_localized 키 사용)
  const allSelected = optionGroups?.every(group => {
    const groupKey = getGroupKey(group);
    return currentSelection?.[groupKey];
  });
  if (!allSelected) return;

  // 매칭되는 ProductOption 찾기
  const matchedOption = findMatchingOption(optionGroups, currentSelection, options ?? []);
  if (!matchedOption) {
    context.setState?.({ currentSelection: {}, __mergeMode: 'shallow' });
    return;
  }

  // 옵션 키 (중복 확인용) - name_localized 키 사용
  const optionKey = optionGroups.map(g => currentSelection[getGroupKey(g)]).join('_');
  const items = selectedOptionItems ?? [];
  const existing = items.find(item => item.id === optionKey);

  if (existing) {
    // 이미 추가된 옵션 → 토스트 알림 + 선택 초기화
    const G7Core = (window as any).G7Core;
    G7Core?.toast?.warning?.(G7Core?.t?.('sirsoft-ecommerce.shop.already_added_option') ?? '이미 추가된 옵션입니다.');
    context.setState?.({ currentSelection: {}, __mergeMode: 'shallow' });
    return;
  }

  // 새 옵션 조합 추가
  const unitPrice = matchedOption.selling_price ?? 0;
  const optionLabels: Record<string, string> = {};
  optionGroups.forEach(group => {
    const groupKey = getGroupKey(group);
    optionLabels[groupKey] = currentSelection[groupKey];
  });

  // 추가옵션 초기 선택: 필수(is_required) 그룹에 한해 기본 선택지(is_default)를 자동 적용한다.
  // 비필수 그룹은 "선택하세요" 미선택 상태를 유지해야 한다 — 추가옵션 선택지는 라디오 방식이라
  // 그룹당 1개가 항상 is_default 로 마킹되므로(관리자 폼이 첫 선택지를 기본으로 시드), 비필수까지
  // 자동 적용하면 사용자가 원치 않은 추가금/선택이 강제로 붙는다.
  const additionalOptionGroups = params.additionalOptionGroups ?? [];
  const additionalOptionSelections: AdditionalOptionSelections = {};
  for (const group of additionalOptionGroups) {
    if (!group.is_required) continue;
    const defaultValue = group.values?.find(v => v.is_default);
    if (defaultValue) additionalOptionSelections[group.id] = defaultValue.id;
  }
  const additionalOptionsTotal = computeAdditionalOptionsTotal(
    additionalOptionSelections,
    additionalOptionGroups
  );
  const additionalOptionsMultiCurrencyTotal = computeAdditionalOptionsMultiCurrencyTotal(
    additionalOptionSelections,
    additionalOptionGroups
  );

  const blockUnitTotal = unitPrice + additionalOptionsTotal;

  const newItem: SelectedItem = {
    id: optionKey,
    optionId: matchedOption.id,
    options: optionLabels,
    optionValues: convertOptionValuesToRecord(
      matchedOption.option_values,
      matchedOption.option_values_localized
    ),
    quantity: 1,
    stock: matchedOption.stock_quantity ?? 999,
    unitPrice,
    unitPriceFormatted: formatPrice(unitPrice, preferredCurrency),
    totalPrice: blockUnitTotal,
    totalPriceFormatted: formatPrice(blockUnitTotal, preferredCurrency),
    multiCurrencyUnitPrice: matchedOption.multi_currency_selling_price,
    multiCurrencyTotalPrice: recalcMultiCurrencyTotal(
      matchedOption.multi_currency_selling_price as any,
      1,
      additionalOptionsMultiCurrencyTotal,
      additionalOptionsTotal
    ),
    additionalOptionSelections,
    additionalOptionsTotal,
    additionalOptionsMultiCurrencyTotal,
  };

  const nextItems = [...items, newItem];
  context.setState?.({
    selectedOptionItems: nextItems,
    selectedTotalMultiCurrency: buildSelectedTotalMultiCurrency(nextItems),
    currentSelection: {},
    __mergeMode: 'shallow',
  });
}

/**
 * 선택된 상품의 수량 변경 및 가격 재계산
 *
 * G7Core에서 (action: ActionDefinition, context: ActionContext) 시그니처로 호출됩니다.
 */
export function updateSelectedItemQuantityHandler(
  action: ActionDefinition,
  context: ActionContext
): void {
  const params = action.params as UpdateQuantityParams;
  if (!params) return;

  const { selectedOptionItems, preferredCurrency } = params;
  // $args[0]이 문자열로 전달될 수 있으므로 Number 변환
  const itemIndex = Number(params.itemIndex) || 0;
  const newQuantity = Number(params.newQuantity) || 1;

  const updatedItems = (selectedOptionItems ?? []).map((item, idx) => {
    if (idx !== itemIndex) return item;

    const unitPrice = item?.unitPrice ?? 0;
    const additionalOptionsTotal = item?.additionalOptionsTotal ?? 0;
    const totalPrice = (unitPrice + additionalOptionsTotal) * newQuantity;
    return {
      ...item,
      quantity: newQuantity,
      totalPrice,
      totalPriceFormatted: formatPrice(totalPrice, preferredCurrency),
      multiCurrencyTotalPrice: recalcMultiCurrencyTotal(
        item?.multiCurrencyUnitPrice,
        newQuantity,
        item?.additionalOptionsMultiCurrencyTotal,
        additionalOptionsTotal
      ),
    };
  });

  context.setState?.({
    selectedOptionItems: updatedItems,
    selectedTotalMultiCurrency: buildSelectedTotalMultiCurrency(updatedItems),
  });
}

/**
 * 블럭별 추가옵션 선택 변경 및 가격 재계산
 *
 * 특정 옵션 블럭의 추가옵션 그룹 선택을 갱신하고, 추가금 합계·소계·다통화를 재계산한다.
 * 가격 표시는 클라이언트 계산이며 결제금액 SSoT 는 서버(plan D13).
 *
 * G7Core에서 (action: ActionDefinition, context: ActionContext) 시그니처로 호출됩니다.
 */
export function setBlockAdditionalOptionHandler(
  action: ActionDefinition,
  context: ActionContext
): void {
  const params = action.params as SetBlockAdditionalOptionParams;
  if (!params) return;

  const { selectedOptionItems, additionalOptionGroups, preferredCurrency } = params;
  const itemIndex = Number(params.itemIndex) || 0;
  const additionalOptionId = Number(params.additionalOptionId);
  // customText 키가 params 에 존재하면 직접입력 모드 (value 선택 변경 아님)
  const isCustomTextMode = Object.prototype.hasOwnProperty.call(params, 'customText');
  // 빈 문자열(플레이스홀더) 선택 시 해당 그룹 선택 해제
  const valueId = params.valueId === '' || params.valueId == null ? null : Number(params.valueId);

  const updatedItems = (selectedOptionItems ?? []).map((item, idx) => {
    if (idx !== itemIndex) return item;

    const selections: AdditionalOptionSelections = { ...(item.additionalOptionSelections ?? {}) };
    const customTexts: Record<number, string> = { ...(item.additionalOptionCustomTexts ?? {}) };

    if (isCustomTextMode) {
      // 직접입력 모드: 텍스트만 갱신 (선택 value 는 유지)
      const text = String(params.customText ?? '');
      if (text.trim() === '') {
        delete customTexts[additionalOptionId];
      } else {
        customTexts[additionalOptionId] = text;
      }
    } else if (valueId == null) {
      // 그룹 선택 해제 → 선택·직접입력 모두 정리
      delete selections[additionalOptionId];
      delete customTexts[additionalOptionId];
    } else {
      selections[additionalOptionId] = valueId;
      // 새 선택지가 직접입력을 허용하지 않으면 기존 직접입력 텍스트 정리
      const group = (additionalOptionGroups ?? []).find((g) => g.id === additionalOptionId);
      const value = group?.values?.find((v) => v.id === valueId);
      if (!value?.allow_custom_text) {
        delete customTexts[additionalOptionId];
      }
    }

    const additionalOptionsTotal = computeAdditionalOptionsTotal(selections, additionalOptionGroups);
    const additionalOptionsMultiCurrencyTotal = computeAdditionalOptionsMultiCurrencyTotal(
      selections,
      additionalOptionGroups
    );
    const unitPrice = item?.unitPrice ?? 0;
    const quantity = item?.quantity ?? 1;
    const totalPrice = (unitPrice + additionalOptionsTotal) * quantity;

    return {
      ...item,
      additionalOptionSelections: selections,
      additionalOptionCustomTexts: customTexts,
      additionalOptionsTotal,
      additionalOptionsMultiCurrencyTotal,
      totalPrice,
      totalPriceFormatted: formatPrice(totalPrice, preferredCurrency),
      multiCurrencyTotalPrice: recalcMultiCurrencyTotal(
        item?.multiCurrencyUnitPrice,
        quantity,
        additionalOptionsMultiCurrencyTotal,
        additionalOptionsTotal
      ),
    };
  });

  context.setState?.({
    selectedOptionItems: updatedItems,
    selectedTotalMultiCurrency: buildSelectedTotalMultiCurrency(updatedItems),
  });
}

/**
 * 선택된 옵션 항목을 제거하고 통화별 합계를 재계산합니다.
 *
 * 레이아웃에서 filter 로 직접 제거하면 selectedTotalMultiCurrency(통화별 합계 포맷)를
 * 갱신할 수 없어(표현식은 핸들러 호출 불가) stale 합계가 남는다. 제거도 핸들러로 처리해
 * 합계 맵을 함께 재계산한다(D2).
 *
 * G7Core에서 (action: ActionDefinition, context: ActionContext) 시그니처로 호출됩니다.
 */
export function removeSelectedItemHandler(
  action: ActionDefinition,
  context: ActionContext
): void {
  const params = action.params as { itemIndex?: number | string; selectedOptionItems?: SelectedItem[] };
  if (!params) return;

  const itemIndex = Number(params.itemIndex) || 0;
  const nextItems = (params.selectedOptionItems ?? []).filter((_, idx) => idx !== itemIndex);

  context.setState?.({
    selectedOptionItems: nextItems,
    selectedTotalMultiCurrency: buildSelectedTotalMultiCurrency(nextItems),
  });
}

/**
 * 옵션 없는 상품의 수량을 변경하고 통화별 총액 포맷 맵을 재계산합니다.
 *
 * 옵션 없는 상품 총액 = 단가 × 수량. 레이아웃 표현식은 핸들러를 호출할 수 없어
 * 통화별 포맷(소수점/기호)을 만들 수 없으므로, 수량 변경 시 통화별 총액 formatted 를
 * noOptionTotalMultiCurrency 에 미리 계산해 노출한다(KRW 고정 결함 D3 해소).
 *
 * @param action.params.newQuantity 변경 수량
 * @param action.params.multiCurrencyUnitPrice 단가 통화맵(product.multi_currency_selling_price)
 * G7Core에서 (action: ActionDefinition, context: ActionContext) 시그니처로 호출됩니다.
 */
export function updateNoOptionQuantityHandler(
  action: ActionDefinition,
  context: ActionContext
): void {
  const params = action.params as {
    newQuantity?: number | string;
    multiCurrencyUnitPrice?: Record<string, { value?: number; price?: number; formatted: string }>;
  };
  if (!params) return;

  const quantity = Math.max(1, Number(params.newQuantity) || 1);

  context.setState?.({
    noOptionQuantity: quantity,
    noOptionTotalMultiCurrency: recalcMultiCurrencyTotal(
      params.multiCurrencyUnitPrice as Record<string, { value: number; formatted: string }> | undefined,
      quantity
    ),
  });
}
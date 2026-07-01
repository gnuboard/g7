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
  price_adjustment: number;
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
  /** 블럭별 추가옵션 추가금 합계 (KRW 기준, 단위당) */
  additionalOptionsTotal?: number;
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
 * 통화별 포맷팅 설정
 */
const CURRENCY_CONFIGS: Record<string, { locale: string; decimals: number }> = {
  KRW: { locale: 'ko-KR', decimals: 0 },
  USD: { locale: 'en-US', decimals: 2 },
  JPY: { locale: 'ja-JP', decimals: 0 },
  CNY: { locale: 'zh-CN', decimals: 2 },
  EUR: { locale: 'de-DE', decimals: 2 },
};

/**
 * 숫자를 통화 형식으로 포맷팅
 */
function formatPrice(amount: number, currencyCode: string = 'KRW'): string {
  if (!Number.isFinite(amount)) return '0';
  const config = CURRENCY_CONFIGS[currencyCode] || CURRENCY_CONFIGS.KRW;
  try {
    return new Intl.NumberFormat(config.locale, {
      style: 'currency',
      currency: currencyCode,
      minimumFractionDigits: config.decimals,
      maximumFractionDigits: config.decimals,
    }).format(amount);
  } catch {
    return amount.toLocaleString() + (currencyCode === 'KRW' ? '원' : '');
  }
}

/**
 * 다중 통화 총 가격 재계산
 *
 * 추가옵션 추가금(additionalUnitKrw)이 있으면 KRW 환율 기준으로 환산해 각 통화 단가에 가산한다.
 * 추가옵션은 공개 응답에 다통화 컬럼이 없으므로(plan §245) 기본통화(KRW) 단가 비율로 환산한다.
 *
 * @param unitPriceMap 옵션 단가 다통화 맵
 * @param quantity 수량
 * @param additionalUnitKrw 추가옵션 추가금 합계 (KRW 기준, 단위당). 기본 0.
 */
function recalcMultiCurrencyTotal(
  unitPriceMap: Record<string, { value: number; formatted: string }> | undefined,
  quantity: number,
  additionalUnitKrw: number = 0
): Record<string, { value: number; formatted: string }> | undefined {
  if (!unitPriceMap) return undefined;

  // KRW 단가를 환산 기준으로 사용 (옵션의 KRW 단가 대비 타통화 비율)
  const krwUnit = (unitPriceMap.KRW as any)?.value ?? (unitPriceMap.KRW as any)?.price ?? 0;

  const result: Record<string, { value: number; formatted: string }> = {};
  for (const [code, data] of Object.entries(unitPriceMap)) {
    // API는 { price, formatted } 형태, 내부는 { value, formatted } 형태 — 둘 다 지원
    const unitValue = (data as any)?.value ?? (data as any)?.price ?? 0;
    // 추가금 통화 환산: KRW 기준 추가금 × (해당통화 단가 / KRW 단가). KRW 자신은 그대로.
    let additionalConverted = 0;
    if (additionalUnitKrw > 0) {
      if (code === 'KRW') {
        additionalConverted = additionalUnitKrw;
      } else if (krwUnit > 0) {
        additionalConverted = (additionalUnitKrw * unitValue) / krwUnit;
      }
    }
    const total = (unitValue + additionalConverted) * quantity;
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
      additionalOptionsTotal
    ),
    additionalOptionSelections,
    additionalOptionsTotal,
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
    const unitPrice = item?.unitPrice ?? 0;
    const quantity = item?.quantity ?? 1;
    const totalPrice = (unitPrice + additionalOptionsTotal) * quantity;

    return {
      ...item,
      additionalOptionSelections: selections,
      additionalOptionCustomTexts: customTexts,
      additionalOptionsTotal,
      totalPrice,
      totalPriceFormatted: formatPrice(totalPrice, preferredCurrency),
      multiCurrencyTotalPrice: recalcMultiCurrencyTotal(
        item?.multiCurrencyUnitPrice,
        quantity,
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
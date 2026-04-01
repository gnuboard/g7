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
}

interface AddSelectedItemParams {
  productId: number;
  optionGroups: OptionGroup[];
  options: ProductOptionRecord[];
  currentSelection: Record<string, string>;
  selectedOptionItems: SelectedItem[];
  preferredCurrency: string;
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
 */
function recalcMultiCurrencyTotal(
  unitPriceMap: Record<string, { value: number; formatted: string }> | undefined,
  quantity: number
): Record<string, { value: number; formatted: string }> | undefined {
  if (!unitPriceMap) return undefined;

  const result: Record<string, { value: number; formatted: string }> = {};
  for (const [code, data] of Object.entries(unitPriceMap)) {
    // API는 { price, formatted } 형태, 내부는 { value, formatted } 형태 — 둘 다 지원
    const unitValue = (data as any)?.value ?? (data as any)?.price ?? 0;
    const total = unitValue * quantity;
    result[code] = {
      value: total,
      formatted: formatPrice(total, code),
    };
  }
  return result;
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
    totalPrice: unitPrice,
    totalPriceFormatted: formatPrice(unitPrice, preferredCurrency),
    multiCurrencyUnitPrice: matchedOption.multi_currency_selling_price,
    multiCurrencyTotalPrice: recalcMultiCurrencyTotal(matchedOption.multi_currency_selling_price as any, 1),
  };

  context.setState?.({
    selectedOptionItems: [...items, newItem],
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
    const totalPrice = unitPrice * newQuantity;
    return {
      ...item,
      quantity: newQuantity,
      totalPrice,
      totalPriceFormatted: formatPrice(totalPrice, preferredCurrency),
      multiCurrencyTotalPrice: recalcMultiCurrencyTotal(item?.multiCurrencyUnitPrice, newQuantity),
    };
  });

  context.setState?.({ selectedOptionItems: updatedItems });
}
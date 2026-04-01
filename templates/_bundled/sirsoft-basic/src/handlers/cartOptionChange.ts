/**
 * 장바구니 옵션 변경 관련 핸들러
 *
 * 장바구니 옵션 변경 모달에서 옵션 선택 및 매칭을 처리합니다.
 *
 * 핸들러 시그니처: ActionDispatcher의 ActionHandler 형식을 따름
 * (action: ActionDefinition, context: ActionContext) => void | Promise<void> | any
 *
 * ⚠️ 중요: 커스텀 핸들러에서 dispatch({ target: 'local' })를 사용하면
 * 핸들러 실행 컨텍스트의 _local에 저장됩니다. iteration 내부에서 호출되면
 * 페이지 레벨 _local과 다른 스코프에 저장됩니다.
 *
 * 해결책: 핸들러가 결과를 반환하고, 레이아웃의 onSuccess에서 setState 실행
 */

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Handler:CartOptionChange')) ?? {
  log: (...args: unknown[]) => console.log('[Handler:CartOptionChange]', ...args),
  warn: (...args: unknown[]) => console.warn('[Handler:CartOptionChange]', ...args),
  error: (...args: unknown[]) => console.error('[Handler:CartOptionChange]', ...args),
};

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

interface ProductOption {
  id: number;
  option_values: OptionValueItem[] | Record<string, string>;
  option_values_localized?: Record<string, string>;
  option_name: string | Record<string, string>;
  option_name_localized?: string;
  selling_price_formatted: string;
  stock_quantity: number;
}

interface InitCartOptionResult {
  selectedOptions: Record<string, string>;
  matchedOption: ProductOption | null;
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
 * 선택된 옵션 조합에 맞는 product_option 찾기 (내부 함수)
 * name_localized를 키로 사용하여 선택값과 비교
 */
function findMatchingOption(
  selectedOptions: Record<string, string>,
  optionGroups: OptionGroup[],
  options: ProductOption[]
): ProductOption | null {
  if (!selectedOptions || !optionGroups?.length) {
    return null;
  }

  // 모든 옵션 그룹이 선택되었는지 확인 (name_localized 키 사용)
  const allSelected = optionGroups.every((group) => {
    const groupKey = getGroupKey(group);
    return selectedOptions[groupKey];
  });
  if (!allSelected) {
    logger.log('Not all options selected yet');
    return null;
  }

  // 일치하는 옵션 조합 찾기
  const matched = options?.find((opt) =>
    optionGroups.every((group) => {
      const groupKey = getGroupKey(group);
      const optValue = getOptionValueByGroupKey(
        opt.option_values,
        opt.option_values_localized,
        groupKey
      );
      return optValue === selectedOptions[groupKey];
    })
  );

  return matched || null;
}

/**
 * 선택된 옵션 조합에 맞는 product_option 찾기
 *
 * 모든 옵션 그룹이 선택되면 일치하는 옵션 조합을 찾아 반환합니다.
 * selectedOptions 구조: { "색상": "화이트", "사이즈": "S" }
 *
 * @param action 액션 정의 (params에 selectedOptions, optionGroups, options 포함)
 * @returns { matchedOption: ProductOption | null }
 */
export function findMatchingOptionHandler(
  action?: any,
  _context?: any
): { matchedOption: ProductOption | null } {
  const { selectedOptions, optionGroups, options } = action?.params || {};

  logger.log('findMatchingOption called:', { selectedOptions, optionGroups, optionsCount: options?.length });

  const matchedOption = findMatchingOption(selectedOptions, optionGroups, options);

  logger.log('Matched option:', matchedOption);

  // 결과 반환 - 레이아웃의 onSuccess에서 {{response.matchedOption}}으로 접근
  return { matchedOption };
}

/**
 * 현재 장바구니 아이템의 옵션을 초기 선택값으로 설정
 *
 * 모달이 열릴 때 현재 아이템의 옵션을 선택 상태로 초기화합니다.
 * currentOptionValues 구조: { "색상": "블랙", "사이즈": "S" }
 *
 * @param action 액션 정의 (params에 currentOptionValues, optionGroups, options 포함)
 * @returns { selectedOptions: Record<string, string>, matchedOption: ProductOption | null }
 */
export function initCartOptionSelectionHandler(
  action?: any,
  _context?: any
): InitCartOptionResult {
  const { currentOptionValues, optionGroups, options } = action?.params || {};

  logger.log('initCartOptionSelection called:', { currentOptionValues, optionGroups, optionsCount: options?.length });

  if (!optionGroups?.length || !currentOptionValues) {
    logger.log('No option groups or current values, returning empty');
    return { selectedOptions: {}, matchedOption: null };
  }

  // currentOptionValues를 그대로 selectedOptions로 사용
  // 둘 다 { "색상": "블랙", "사이즈": "S" } 형식 (name_localized 키 사용)
  const selectedOptions: Record<string, string> = {};
  for (const group of optionGroups) {
    const groupKey = getGroupKey(group);
    const currentValue = currentOptionValues[groupKey];
    // values_localized가 있으면 우선 사용, 없으면 values 사용
    const valuesArray = group.values_localized ?? (
      Array.isArray(group.values)
        ? group.values.map((v: string | Record<string, string>) => typeof v === 'string' ? v : (v as Record<string, string>)?.ko ?? '')
        : []
    );
    if (currentValue && valuesArray.includes(currentValue)) {
      selectedOptions[groupKey] = currentValue;
    }
  }

  logger.log('Initial selectedOptions:', selectedOptions);

  // 매칭 옵션도 즉시 찾기
  const matchedOption = findMatchingOption(selectedOptions, optionGroups, options || []);

  logger.log('Matched option:', matchedOption);

  // 결과 반환 - 레이아웃의 onSuccess에서 {{response.selectedOptions}}, {{response.matchedOption}}으로 접근
  return { selectedOptions, matchedOption };
}

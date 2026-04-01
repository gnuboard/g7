/**
 * MultilingualTagInput 외부 모달 핸들러
 *
 * MultilingualTagInput 컴포넌트에서 modalId를 사용할 때,
 * 외부 모달에서 다국어 태그 값을 저장/취소하는 핸들러입니다.
 *
 * 사용법:
 * 1. MultilingualTagInput에 modalId, statePath props 전달
 * 2. 레이아웃 JSON의 modals 섹션에 다국어 입력 모달 정의
 * 3. 모달 저장 버튼에 saveMultilingualTag 핸들러 연결
 * 4. 모달 취소 버튼에 cancelMultilingualTag 핸들러 연결
 *
 * _global.multilingualTagEdit 구조:
 * {
 *   fieldName: string,           // 폼 필드 이름 (예: 'option_values_0')
 *   editingIndex: number | null, // null이면 새 태그 추가, 숫자면 해당 인덱스 수정
 *   values: { [locale]: string }, // 각 언어별 값
 *   supportedLocales: string[],  // 지원 언어 목록
 *   defaultLocale: string,       // 기본 언어
 *   statePath: string,           // 부모 상태 경로 (예: 'ui.optionInputs.0.values')
 *   currentTags: array,          // 현재 태그 배열
 * }
 */

// Logger 설정
const logger = ((window as any).G7Core?.createLogger?.('Handler:MultilingualTag')) ?? {
  log: (...args: unknown[]) => console.log('[Handler:MultilingualTag]', ...args),
  warn: (...args: unknown[]) => console.warn('[Handler:MultilingualTag]', ...args),
  error: (...args: unknown[]) => console.error('[Handler:MultilingualTag]', ...args),
};

/**
 * 다국어 태그 저장 핸들러
 *
 * 외부 모달에서 저장 버튼 클릭 시 호출됩니다.
 * _global.multilingualTagEdit의 values를 부모의 tags 배열에 반영합니다.
 *
 * @param action 액션 정의
 * @param context 액션 컨텍스트
 */
export async function saveMultilingualTagHandler(
  _action: any,
  _context?: any
): Promise<void> {
  const G7Core = (window as any).G7Core;
  if (!G7Core) {
    logger.error('G7Core not available');
    return;
  }

  // 1. 편집 상태 가져오기
  const editState = G7Core.state?.getGlobal?.()?.multilingualTagEdit;
  if (!editState) {
    logger.warn('No multilingualTagEdit state found');
    G7Core.modal?.close?.();
    return;
  }

  const { fieldName, editingIndex, values, statePath, currentTags: existingTags } = editState;

  // 2. 값 검증 - 최소 하나의 값이 있어야 함
  const hasValue = Object.values(values || {}).some(
    (v: any) => typeof v === 'string' && v.trim() !== ''
  );
  if (!hasValue) {
    logger.warn('No values to save');
    G7Core.modal?.close?.();
    clearEditState(G7Core);
    return;
  }

  // 3. 현재 태그 배열 가져오기 (editState에서 전달받은 currentTags 사용)
  const currentTags = Array.isArray(existingTags) ? existingTags : [];

  // 4. 태그 배열 업데이트
  let newTags: any[];
  if (editingIndex === null || editingIndex === undefined) {
    // 새 태그 추가
    newTags = [...currentTags, values];
  } else {
    // 기존 태그 수정
    newTags = currentTags.map((tag, idx) => (idx === editingIndex ? values : tag));
  }

  // 5. 부모 상태 업데이트 (statePath 우선, 없으면 form.fieldName 사용)
  const targetPath = statePath || (fieldName?.startsWith('form.') ? fieldName : `form.${fieldName}`);

  G7Core.state?.setParentLocal?.({
    [targetPath]: newTags,
  });

  // 6. 모달 닫기 및 상태 초기화
  G7Core.modal?.close?.();
  clearEditState(G7Core);
}

/**
 * 다국어 태그 취소 핸들러
 *
 * 외부 모달에서 취소 버튼 클릭 시 호출됩니다.
 * 모달을 닫고 편집 상태를 초기화합니다.
 *
 * @param action 액션 정의
 * @param context 액션 컨텍스트
 */
export async function cancelMultilingualTagHandler(
  _action: any,
  _context?: any
): Promise<void> {
  const G7Core = (window as any).G7Core;
  if (!G7Core) {
    logger.error('G7Core not available');
    return;
  }

  G7Core.modal?.close?.();
  clearEditState(G7Core);
}

/**
 * 다국어 태그 값 변경 핸들러
 *
 * 외부 모달에서 입력 필드 변경 시 호출됩니다.
 * _global.multilingualTagEdit.values를 업데이트합니다.
 *
 * 사용법 (레이아웃 JSON):
 * {
 *   "handler": "updateMultilingualTagValue",
 *   "params": { "locale": "ko" }
 * }
 *
 * @param action 액션 정의 (params.locale 필수)
 * @param context 액션 컨텍스트
 */
export async function updateMultilingualTagValueHandler(
  action: any,
  context?: any
): Promise<void> {
  const G7Core = (window as any).G7Core;
  if (!G7Core) {
    logger.error('G7Core not available');
    return;
  }

  const locale = action?.params?.locale;
  if (!locale) {
    logger.warn('No locale specified');
    return;
  }

  // 이벤트에서 값 추출
  const eventData = context?.event;
  let value: string = '';

  if (eventData?.target?.value !== undefined) {
    value = eventData.target.value;
  } else if (eventData?.value !== undefined) {
    value = eventData.value;
  }

  // 현재 편집 상태 가져오기
  const globalState = G7Core.state?.getGlobal?.() || {};
  const editState = globalState.multilingualTagEdit || {};

  // values 업데이트
  const newValues = {
    ...(editState.values || {}),
    [locale]: value,
  };

  // 전역 상태 업데이트
  G7Core.dispatch?.({
    handler: 'setState',
    params: {
      target: 'global',
      multilingualTagEdit: {
        ...editState,
        values: newValues,
      },
    },
  });
}

/**
 * 편집 상태 초기화 헬퍼
 */
function clearEditState(G7Core: any): void {
  G7Core.dispatch?.({
    handler: 'setState',
    params: {
      target: 'global',
      multilingualTagEdit: null,
    },
  });
}

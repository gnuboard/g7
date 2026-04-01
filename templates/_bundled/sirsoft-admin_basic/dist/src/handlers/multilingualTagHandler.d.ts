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
/**
 * 다국어 태그 저장 핸들러
 *
 * 외부 모달에서 저장 버튼 클릭 시 호출됩니다.
 * _global.multilingualTagEdit의 values를 부모의 tags 배열에 반영합니다.
 *
 * @param action 액션 정의
 * @param context 액션 컨텍스트
 */
export declare function saveMultilingualTagHandler(_action: any, _context?: any): Promise<void>;
/**
 * 다국어 태그 취소 핸들러
 *
 * 외부 모달에서 취소 버튼 클릭 시 호출됩니다.
 * 모달을 닫고 편집 상태를 초기화합니다.
 *
 * @param action 액션 정의
 * @param context 액션 컨텍스트
 */
export declare function cancelMultilingualTagHandler(_action: any, _context?: any): Promise<void>;
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
export declare function updateMultilingualTagValueHandler(action: any, context?: any): Promise<void>;

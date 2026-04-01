/**
 * sirsoft-daum_postcode 플러그인 핸들러
 *
 * 플러그인에서 사용하는 모든 커스텀 핸들러를 정의합니다.
 */

import { setFieldReadOnlyHandler } from './setFieldReadOnly';
import { openPostcodeHandler } from './openPostcode';

/**
 * 핸들러 맵
 *
 * 키: 핸들러 이름 (네임스페이스 없이)
 * 값: 핸들러 함수
 *
 * ActionDispatcher에 등록 시 플러그인 식별자가 네임스페이스로 추가됩니다.
 * 예: 'setFieldReadOnly' -> 'sirsoft-daum_postcode.setFieldReadOnly'
 * 예: 'openPostcode' -> 'sirsoft-daum_postcode.openPostcode'
 */
export const handlerMap = {
    setFieldReadOnly: setFieldReadOnlyHandler,
    openPostcode: openPostcodeHandler,
} as const;

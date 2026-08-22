/**
 * sirsoft-message_bizppurio 플러그인 커스텀 핸들러 맵.
 *
 * 키는 네임스페이스 없는 핸들러 이름이며, ActionDispatcher 등록 시 플러그인
 * 식별자가 네임스페이스로 접두된다. 예: insertVariable → sirsoft-message_bizppurio.insertVariable
 *
 * - insertVariable: 작성 모달·SMS 모달 본문에 `#{변수}` 커서 삽입 (#597 §4.2)
 * - uploadTemplateImage: 이미지형 템플릿 이미지 업로드 프록시 호출 (multipart — apiCall 미적합)
 */

import { insertVariableHandler } from './insertVariable';
import { uploadTemplateImageHandler } from './uploadTemplateImage';

export const handlerMap = {
    insertVariable: insertVariableHandler,
    uploadTemplateImage: uploadTemplateImageHandler,
} as const;

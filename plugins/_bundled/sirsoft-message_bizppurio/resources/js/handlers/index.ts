/**
 * sirsoft-message_bizppurio 플러그인 커스텀 핸들러 맵.
 *
 * 키는 네임스페이스 없는 핸들러 이름이며, ActionDispatcher 등록 시 플러그인
 * 식별자가 네임스페이스로 접두된다.
 *
 * 현재 등록 핸들러 없음(알림톡 템플릿 등록·이미지 업로드 제거로 uploadTemplateImage 제거).
 * 커스텀 핸들러 추가 시 이 맵에 등록한다.
 */

export const handlerMap = {} as const;
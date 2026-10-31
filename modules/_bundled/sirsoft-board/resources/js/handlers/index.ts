/**
 * sirsoft-board 모듈 핸들러 맵
 *
 * 등록 시 `sirsoft-board.` 접두어가 붙는다(레이아웃에서는 `sirsoft-board.downloadAttachment`).
 *
 * - downloadAttachment: 관리자 게시글 상세의 게시글·답변 첨부를 로그인 토큰을 실어 내려받는다
 */

import { downloadAttachmentHandler } from './downloadAttachment';

export const handlerMap = {
    downloadAttachment: downloadAttachmentHandler,
} as const;

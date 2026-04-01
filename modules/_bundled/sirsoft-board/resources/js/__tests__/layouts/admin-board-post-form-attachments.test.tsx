/**
 * 게시판 첨부파일 업로드 방식 변경 - 레이아웃 JSON 구조 검증 테스트
 *
 * @description
 * - 관리자 첨부파일 partial: autoUpload=false, uploadTriggerEvent 설정 검증
 * - 관리자 폼 저장 sequence: emitEvent가 apiCall 앞에 위치하는지 검증
 * - 사용자 폼 동일 패턴 적용 검증
 *
 */

import { describe, it, expect } from 'vitest';

// 레이아웃 JSON 임포트
import adminAttachments from '../../../layouts/admin/partials/admin_board_post_form/_attachments.json';
import adminPostForm from '../../../layouts/admin/admin_board_post_form.json';

// 사용자 폼은 templates 디렉토리에 있으므로 상대경로 사용
// JSON 파일은 직접 import (vitest가 자동 파싱)
import userPostForm from '../../../../../../templates/sirsoft-basic/layouts/partials/board/form/_post_form.json';

/**
 * JSON 트리에서 특정 타입의 컴포넌트를 재귀적으로 찾습니다.
 */
function findComponentsByName(node: any, name: string): any[] {
    const results: any[] = [];
    if (!node) return results;

    if (node.name === name) {
        results.push(node);
    }

    if (node.children && Array.isArray(node.children)) {
        for (const child of node.children) {
            results.push(...findComponentsByName(child, name));
        }
    }

    // slots 내부도 검색
    if (node.slots) {
        for (const slotChildren of Object.values(node.slots)) {
            if (Array.isArray(slotChildren)) {
                for (const child of slotChildren) {
                    results.push(...findComponentsByName(child as any, name));
                }
            }
        }
    }

    return results;
}

/**
 * JSON 트리에서 특정 ID를 가진 노드를 재귀적으로 찾습니다.
 */
function findById(node: any, id: string): any | null {
    if (!node) return null;
    if (node.id === id) return node;

    if (node.children && Array.isArray(node.children)) {
        for (const child of node.children) {
            const found = findById(child, id);
            if (found) return found;
        }
    }

    if (node.slots) {
        for (const slotChildren of Object.values(node.slots)) {
            if (Array.isArray(slotChildren)) {
                for (const child of slotChildren) {
                    const found = findById(child as any, id);
                    if (found) return found;
                }
            }
        }
    }

    return null;
}

/**
 * sequence 액션 내에서 handler 목록을 추출합니다.
 */
function getSequenceHandlers(actions: any[]): string[] {
    for (const action of actions) {
        if (action.handler === 'sequence' && action.actions) {
            return action.actions.map((a: any) => a.handler);
        }
    }
    return [];
}

describe('관리자 첨부파일 Partial (_attachments.json)', () => {
    it('FileUploader에 autoUpload: false가 설정되어야 합니다', () => {
        const fileUploaders = findComponentsByName(adminAttachments, 'FileUploader');
        expect(fileUploaders.length).toBeGreaterThan(0);

        const uploader = fileUploaders[0];
        expect(uploader.props.autoUpload).toBe(false);
    });

    it('FileUploader에 uploadTriggerEvent가 설정되어야 합니다', () => {
        const fileUploaders = findComponentsByName(adminAttachments, 'FileUploader');
        const uploader = fileUploaders[0];
        expect(uploader.props.uploadTriggerEvent).toBe('upload:board_attachments');
    });

    it('FileUploader에 uploadParams가 temp_key를 포함해야 합니다', () => {
        const fileUploaders = findComponentsByName(adminAttachments, 'FileUploader');
        const uploader = fileUploaders[0];
        expect(uploader.props.uploadParams).toBeDefined();
        expect(uploader.props.uploadParams.temp_key).toBeDefined();
        expect(uploader.props.uploadParams.temp_key).toContain('_local.tempKey');
    });

    it('FileUploader의 upload URL에 쿼리스트링이 없어야 합니다', () => {
        const fileUploaders = findComponentsByName(adminAttachments, 'FileUploader');
        const uploader = fileUploaders[0];
        const uploadUrl = uploader.props.apiEndpoints.upload;

        // URL에 쿼리스트링 파라미터가 포함되지 않아야 함 (?.은 optional chaining이므로 허용)
        expect(uploadUrl).not.toMatch(/\?[a-z_]+=/)  // ?key= 형태의 쿼리스트링 금지
        expect(uploadUrl).not.toContain('temp_key=');
        expect(uploadUrl).not.toContain('post_id=');
    });
});

describe('관리자 폼 저장 버튼 (admin_board_post_form.json)', () => {
    it('header_save_button의 sequence에 emitEvent가 포함되어야 합니다', () => {
        const headerButton = findById(adminPostForm, 'header_save_button');
        expect(headerButton).not.toBeNull();

        const handlers = getSequenceHandlers(headerButton.actions);
        expect(handlers).toContain('emitEvent');
    });

    it('header_save_button의 emitEvent가 apiCall 앞에 위치해야 합니다', () => {
        const headerButton = findById(adminPostForm, 'header_save_button');
        const handlers = getSequenceHandlers(headerButton.actions);

        const emitIndex = handlers.indexOf('emitEvent');
        const apiCallIndex = handlers.indexOf('apiCall');

        expect(emitIndex).toBeGreaterThan(-1);
        expect(apiCallIndex).toBeGreaterThan(-1);
        expect(emitIndex).toBeLessThan(apiCallIndex);
    });

    it('header_save_button의 emitEvent가 upload:board_attachments 이벤트를 발행해야 합니다', () => {
        const headerButton = findById(adminPostForm, 'header_save_button');
        const sequenceAction = headerButton.actions.find((a: any) => a.handler === 'sequence');
        const emitAction = sequenceAction.actions.find((a: any) => a.handler === 'emitEvent');

        expect(emitAction.params.event).toBe('upload:board_attachments');
    });

    it('footer_save_button의 sequence에 emitEvent가 포함되어야 합니다', () => {
        const footerButton = findById(adminPostForm, 'footer_save_button');
        expect(footerButton).not.toBeNull();

        const handlers = getSequenceHandlers(footerButton.actions);
        expect(handlers).toContain('emitEvent');
    });

    it('footer_save_button의 emitEvent가 apiCall 앞에 위치해야 합니다', () => {
        const footerButton = findById(adminPostForm, 'footer_save_button');
        const handlers = getSequenceHandlers(footerButton.actions);

        const emitIndex = handlers.indexOf('emitEvent');
        const apiCallIndex = handlers.indexOf('apiCall');

        expect(emitIndex).toBeGreaterThan(-1);
        expect(apiCallIndex).toBeGreaterThan(-1);
        expect(emitIndex).toBeLessThan(apiCallIndex);
    });

    it('저장 sequence 순서: setState → emitEvent → apiCall', () => {
        const headerButton = findById(adminPostForm, 'header_save_button');
        const handlers = getSequenceHandlers(headerButton.actions);

        expect(handlers[0]).toBe('setState');
        expect(handlers[1]).toBe('emitEvent');
        expect(handlers[2]).toBe('apiCall');
    });

    it('apiCall body에 temp_key가 포함되어야 합니다', () => {
        const headerButton = findById(adminPostForm, 'header_save_button');
        const sequenceAction = headerButton.actions.find((a: any) => a.handler === 'sequence');
        const apiCallAction = sequenceAction.actions.find((a: any) => a.handler === 'apiCall');

        expect(apiCallAction.params.body).toContain('temp_key');
        expect(apiCallAction.params.body).toContain('_local.tempKey');
    });
});

describe('사용자 폼 (_post_form.json)', () => {
    it('FileUploader에 autoUpload: false가 설정되어야 합니다', () => {
        const fileUploaders = findComponentsByName(userPostForm, 'FileUploader');
        expect(fileUploaders.length).toBeGreaterThan(0);

        const uploader = fileUploaders[0];
        expect(uploader.props.autoUpload).toBe(false);
    });

    it('FileUploader에 uploadTriggerEvent가 설정되어야 합니다', () => {
        const fileUploaders = findComponentsByName(userPostForm, 'FileUploader');
        const uploader = fileUploaders[0];
        expect(uploader.props.uploadTriggerEvent).toBe('upload:board_attachments');
    });

    it('FileUploader에 uploadParams가 temp_key를 포함해야 합니다', () => {
        const fileUploaders = findComponentsByName(userPostForm, 'FileUploader');
        const uploader = fileUploaders[0];
        expect(uploader.props.uploadParams).toBeDefined();
        expect(uploader.props.uploadParams.temp_key).toContain('_local.tempKey');
    });

    it('FileUploader의 upload URL에 쿼리스트링이 없어야 합니다', () => {
        const fileUploaders = findComponentsByName(userPostForm, 'FileUploader');
        const uploader = fileUploaders[0];
        const uploadUrl = uploader.props.apiEndpoints.upload;

        expect(uploadUrl).not.toContain('?');
        expect(uploadUrl).not.toContain('temp_key=');
        expect(uploadUrl).not.toContain('post_id=');
    });

    it('저장 버튼의 sequence에 emitEvent가 포함되어야 합니다', () => {
        // 사용자 폼에서 저장 버튼은 children 마지막 영역에 위치
        const allButtons = findComponentsByName(userPostForm, 'Button');

        // sequence + apiCall 패턴을 가진 버튼 찾기
        const saveButton = allButtons.find((btn: any) => {
            if (!btn.actions) return false;
            return btn.actions.some((a: any) =>
                a.handler === 'sequence' &&
                a.actions?.some((sa: any) => sa.handler === 'apiCall')
            );
        });

        expect(saveButton).toBeDefined();
        const handlers = getSequenceHandlers(saveButton.actions);
        expect(handlers).toContain('emitEvent');
    });

    it('저장 버튼의 emitEvent가 apiCall 앞에 위치해야 합니다', () => {
        const allButtons = findComponentsByName(userPostForm, 'Button');
        const saveButton = allButtons.find((btn: any) => {
            if (!btn.actions) return false;
            return btn.actions.some((a: any) =>
                a.handler === 'sequence' &&
                a.actions?.some((sa: any) => sa.handler === 'apiCall')
            );
        });

        const handlers = getSequenceHandlers(saveButton.actions);
        const emitIndex = handlers.indexOf('emitEvent');
        const apiCallIndex = handlers.indexOf('apiCall');

        expect(emitIndex).toBeGreaterThan(-1);
        expect(apiCallIndex).toBeGreaterThan(-1);
        expect(emitIndex).toBeLessThan(apiCallIndex);
    });

    it('저장 sequence 순서: setState → emitEvent → apiCall', () => {
        const allButtons = findComponentsByName(userPostForm, 'Button');
        const saveButton = allButtons.find((btn: any) => {
            if (!btn.actions) return false;
            return btn.actions.some((a: any) =>
                a.handler === 'sequence' &&
                a.actions?.some((sa: any) => sa.handler === 'apiCall')
            );
        });

        const handlers = getSequenceHandlers(saveButton.actions);
        expect(handlers[0]).toBe('setState');
        expect(handlers[1]).toBe('emitEvent');
        expect(handlers[2]).toBe('apiCall');
    });
});

describe('업로드 에러 시 isSaving 복구 (onUploadError)', () => {
    it('관리자 첨부파일 onUploadError에서 isSaving: false를 설정해야 합니다', () => {
        const fileUploaders = findComponentsByName(adminAttachments, 'FileUploader');
        const uploader = fileUploaders[0];
        const errorAction = uploader.actions.find((a: any) => a.event === 'onUploadError');

        expect(errorAction).toBeDefined();
        // sequence 내 setState에서 isSaving: false 확인
        const setStateAction = errorAction.actions.find((a: any) => a.handler === 'setState');
        expect(setStateAction).toBeDefined();
        expect(setStateAction.params.isSaving).toBe(false);
    });

    it('사용자 폼 onUploadError에서 isSaving: false를 설정해야 합니다', () => {
        const fileUploaders = findComponentsByName(userPostForm, 'FileUploader');
        const uploader = fileUploaders[0];
        const errorAction = uploader.actions.find((a: any) => a.event === 'onUploadError');

        expect(errorAction).toBeDefined();
        const setStateAction = errorAction.actions.find((a: any) => a.handler === 'setState');
        expect(setStateAction).toBeDefined();
        expect(setStateAction.params.isSaving).toBe(false);
    });

    it('관리자 첨부파일 uploadParams에 post_id가 없어야 합니다', () => {
        const fileUploaders = findComponentsByName(adminAttachments, 'FileUploader');
        const uploader = fileUploaders[0];
        expect(uploader.props.uploadParams.post_id).toBeUndefined();
    });

    it('사용자 폼 uploadParams에 post_id가 없어야 합니다', () => {
        const fileUploaders = findComponentsByName(userPostForm, 'FileUploader');
        const uploader = fileUploaders[0];
        expect(uploader.props.uploadParams.post_id).toBeUndefined();
    });
});

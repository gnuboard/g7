/**
 * LayoutSettingsModal.tsx
 *
 * G7 위지윅 레이아웃 편집기 - 레이아웃 설정 모달
 *
 * 역할:
 * - 레이아웃 레벨 메타데이터 편집
 * - 권한 (permissions) 설정
 * - 레이아웃 이름, 버전 등 메타 정보 표시
 */

import React, { useState, useCallback, useEffect } from 'react';
import { useEditorState } from '../hooks/useEditorState';
import { PermissionSelector } from './PropertyPanel/PermissionSelector';
import { createLogger } from '../../../utils/Logger';

const logger = createLogger('LayoutSettingsModal');

// ============================================================================
// 타입 정의
// ============================================================================

export interface LayoutSettingsModalProps {
    /** 모달 열림 상태 */
    isOpen: boolean;
    /** 모달 닫기 핸들러 */
    onClose: () => void;
}

// ============================================================================
// 메인 컴포넌트
// ============================================================================

export function LayoutSettingsModal({ isOpen, onClose }: LayoutSettingsModalProps) {
    // Zustand 상태
    const layoutData = useEditorState((state) => state.layoutData);
    const updateLayoutMeta = useEditorState((state) => state.updateLayoutMeta);
    const pushHistory = useEditorState((state) => state.pushHistory);

    // 로컬 상태 (편집 중인 permissions)
    const [permissions, setPermissions] = useState<string[]>([]);
    const [isDirty, setIsDirty] = useState(false);

    // layoutData에서 permissions 초기화
    useEffect(() => {
        if (layoutData && isOpen) {
            setPermissions(layoutData.permissions || []);
            setIsDirty(false);
        }
    }, [layoutData, isOpen]);

    // 권한 변경 핸들러
    const handlePermissionsChange = useCallback((newPermissions: string[]) => {
        setPermissions(newPermissions);
        setIsDirty(true);
    }, []);

    // 저장 핸들러
    const handleSave = useCallback(() => {
        if (!layoutData) return;

        logger.log('Saving layout permissions:', permissions);

        // 히스토리에 현재 상태 저장
        pushHistory('레이아웃 권한 변경');

        // 레이아웃 메타데이터 업데이트
        updateLayoutMeta({ permissions });

        setIsDirty(false);
        onClose();
    }, [layoutData, permissions, pushHistory, updateLayoutMeta, onClose]);

    // 취소 핸들러
    const handleCancel = useCallback(() => {
        if (isDirty) {
            const confirmed = window.confirm('변경사항이 저장되지 않습니다. 정말 닫으시겠습니까?');
            if (!confirmed) return;
        }
        onClose();
    }, [isDirty, onClose]);

    // 모달이 열려있지 않으면 렌더링하지 않음
    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center">
            {/* 배경 오버레이 */}
            <div
                className="absolute inset-0 bg-black/50"
                onClick={handleCancel}
            />

            {/* 모달 콘텐츠 */}
            <div className="relative w-full max-w-2xl mx-4 bg-white dark:bg-gray-800 rounded-lg shadow-xl max-h-[90vh] flex flex-col">
                {/* 헤더 */}
                <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <div>
                        <h2 className="text-lg font-semibold text-gray-900 dark:text-white">
                            레이아웃 설정
                        </h2>
                        {layoutData && (
                            <p className="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                                {layoutData.layout_name}
                                {layoutData.version && (
                                    <span className="ml-2 text-xs bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded">
                                        v{layoutData.version}
                                    </span>
                                )}
                            </p>
                        )}
                    </div>
                    <button
                        onClick={handleCancel}
                        className="p-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
                        title="닫기"
                    >
                        <span className="text-xl">✕</span>
                    </button>
                </div>

                {/* 바디 */}
                <div className="flex-1 overflow-auto px-6 py-4">
                    {/* 레이아웃 정보 섹션 */}
                    <div className="mb-6">
                        <h3 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            레이아웃 정보
                        </h3>
                        <div className="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <span className="text-gray-500 dark:text-gray-400">이름:</span>
                                <span className="ml-2 text-gray-900 dark:text-white font-mono">
                                    {layoutData?.layout_name || '-'}
                                </span>
                            </div>
                            <div>
                                <span className="text-gray-500 dark:text-gray-400">버전:</span>
                                <span className="ml-2 text-gray-900 dark:text-white">
                                    {layoutData?.version || '-'}
                                </span>
                            </div>
                            <div>
                                <span className="text-gray-500 dark:text-gray-400">상속:</span>
                                <span className="ml-2 text-gray-900 dark:text-white font-mono">
                                    {layoutData?.extends || '없음'}
                                </span>
                            </div>
                            <div>
                                <span className="text-gray-500 dark:text-gray-400">컴포넌트:</span>
                                <span className="ml-2 text-gray-900 dark:text-white">
                                    {layoutData?.components?.length || 0}개
                                </span>
                            </div>
                        </div>
                    </div>

                    {/* 구분선 */}
                    <hr className="border-gray-200 dark:border-gray-700 mb-6" />

                    {/* 권한 설정 섹션 */}
                    <div>
                        <h3 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            접근 권한
                        </h3>
                        <p className="text-xs text-gray-500 dark:text-gray-400 mb-3">
                            이 레이아웃에 접근하기 위해 필요한 권한을 설정합니다.
                            권한을 선택하지 않으면 공개 레이아웃이 됩니다.
                            선택된 권한은 AND 조건으로 적용됩니다.
                        </p>
                        <PermissionSelector
                            value={permissions}
                            onChange={handlePermissionsChange}
                        />
                    </div>
                </div>

                {/* 푸터 */}
                <div className="flex items-center justify-end gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900">
                    <button
                        onClick={handleCancel}
                        className="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors"
                    >
                        취소
                    </button>
                    <button
                        onClick={handleSave}
                        disabled={!isDirty}
                        className="px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 disabled:bg-gray-400 disabled:cursor-not-allowed rounded-lg transition-colors"
                    >
                        {isDirty ? '저장' : '변경 없음'}
                    </button>
                </div>
            </div>
        </div>
    );
}

export default LayoutSettingsModal;

import { default as React } from 'react';
export interface LayoutEditorHeaderProps {
    /**
     * 레이아웃 이름
     */
    layoutName: string;
    /**
     * 뒤로가기 버튼 클릭 시 콜백
     */
    onBack: () => void;
    /**
     * 미리보기 버튼 클릭 시 콜백
     */
    onPreview: () => void;
    /**
     * 저장 버튼 클릭 시 콜백
     */
    onSave: () => void;
    /**
     * 저장 중 상태 여부
     */
    isSaving?: boolean;
    /**
     * 사용자 정의 클래스
     */
    className?: string;
}
/**
 * LayoutEditorHeader 레이아웃 편집 헤더 컴포넌트
 *
 * 레이아웃 편집 화면의 헤더 영역을 구성하는 composite 컴포넌트입니다.
 * 뒤로가기, 제목, 미리보기, 저장 버튼을 포함합니다.
 *
 * @example
 * // 기본 사용
 * <LayoutEditorHeader
 *   layoutName="메인 레이아웃"
 *   onBack={() => console.log('back')}
 *   onPreview={() => console.log('preview')}
 *   onSave={() => console.log('save')}
 * />
 *
 * // 저장 중 상태
 * <LayoutEditorHeader
 *   layoutName="메인 레이아웃"
 *   onBack={() => console.log('back')}
 *   onPreview={() => console.log('preview')}
 *   onSave={() => console.log('save')}
 *   isSaving={true}
 * />
 */
export declare const LayoutEditorHeader: React.FC<LayoutEditorHeaderProps>;

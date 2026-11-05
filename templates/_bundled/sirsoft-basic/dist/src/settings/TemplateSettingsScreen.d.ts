import { default as React } from 'react';
import { TemplateSettingSection, TemplateSettingsValue } from './TemplateSettingsFields';
import { TemplateMenuRoleOption } from './TemplateMenuField';
/** 조회 응답 `data` 중 화면이 쓰는 부분 */
export interface TemplateSettingsScreenData {
    name?: string;
    type?: 'admin' | 'user' | string;
    is_active?: boolean;
    sections?: TemplateSettingSection[];
    abilities?: {
        can_update?: boolean;
        can_reset?: boolean;
    };
}
/** 저장·되돌리기 결과 안내 */
export interface TemplateSettingsScreenStatus {
    type: 'success' | 'error';
    /** 무엇의 결과인가 — 없으면 저장 */
    action?: 'save' | 'reset';
    /** 서버 문구 (없으면 결과 종류에 맞는 기본 문구) */
    message?: string | null;
}
export interface TemplateSettingsScreenProps {
    /** 조회 응답 `data` (받기 전이면 null) */
    data?: TemplateSettingsScreenData | null;
    /** 폼 값 (섹션 중첩) */
    value?: TemplateSettingsValue | null;
    /** 서버 검증 오류 */
    errors?: Record<string, string[] | string> | null;
    /** 지금 섹션 */
    activeSection?: string | null;
    /** 읽기 전용 */
    readOnly?: boolean;
    /** 저장하지 않은 변경이 있음 */
    dirty?: boolean;
    /** 저장 중 */
    saving?: boolean;
    /** 되돌리는 중 */
    resetting?: boolean;
    /** 마지막 저장·되돌리기 결과 */
    status?: TemplateSettingsScreenStatus | null;
    /** 첨부 칸 업로드 신호 이름 */
    uploadTriggerEvent?: string;
    /** 메뉴 칸 「특정 역할만」 선택지 (`/api/admin/roles/active`) */
    roleOptions?: TemplateMenuRoleOption[];
    /** 칸 값이 바뀜 — 폼 전체 */
    onChange?: (next: TemplateSettingsValue) => void;
    /** 파일만 골라 둠 */
    onDirty?: () => void;
    /** 섹션 옮기기 — (섹션, 저장하지 않은 변경을 버렸는가) */
    onTabChange?: (section: string, discarded: boolean) => void;
    /** 저장 */
    onSave?: () => void;
    /** 기본값으로 되돌리기 (확인 창을 거친 뒤) */
    onReset?: () => void;
    /** 목록으로 */
    onBack?: () => void;
    /** 업로드 실패 문구 */
    onUploadError?: (message: string) => void;
}
/**
 * 템플릿 설정 화면
 */
export declare const TemplateSettingsScreen: React.FC<TemplateSettingsScreenProps>;
export default TemplateSettingsScreen;

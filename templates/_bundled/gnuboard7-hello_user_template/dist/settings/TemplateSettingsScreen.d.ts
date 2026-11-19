import { default as React } from 'react';
/** 칸 서술 — 조회 응답 `sections[].fields[]` 중 이 예제가 쓰는 부분 */
interface SettingField {
    key: string;
    type: string;
    label: string;
    hint?: string | null;
    collection?: string;
    accept?: string;
}
/** 섹션 서술 */
interface SettingSection {
    key: string;
    label: string;
    hint?: string | null;
    fields: SettingField[];
}
type SettingsValue = Record<string, Record<string, any>>;
export interface TemplateSettingsScreenProps {
    data?: {
        name?: string;
        is_active?: boolean;
        sections?: SettingSection[];
        abilities?: {
            can_update?: boolean;
            can_reset?: boolean;
        };
    } | null;
    value?: SettingsValue | null;
    errors?: Record<string, string[] | string> | null;
    activeSection?: string | null;
    readOnly?: boolean;
    dirty?: boolean;
    saving?: boolean;
    resetting?: boolean;
    status?: {
        type: 'success' | 'error';
        action?: 'save' | 'reset';
        message?: string | null;
    } | null;
    uploadTriggerEvent?: string;
    onChange?: (next: SettingsValue) => void;
    onDirty?: () => void;
    onTabChange?: (section: string, discarded: boolean) => void;
    onSave?: () => void;
    onReset?: () => void;
    onBack?: () => void;
    onUploadError?: (message: string) => void;
}
/**
 * 템플릿 설정 화면
 */
export declare const TemplateSettingsScreen: React.FC<TemplateSettingsScreenProps>;
export default TemplateSettingsScreen;

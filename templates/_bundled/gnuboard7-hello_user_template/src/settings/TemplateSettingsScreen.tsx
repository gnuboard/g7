/**
 * 템플릿 설정 화면 — 학습용 최소 예제 (#434 D28·D29)
 *
 * 템플릿이 자기 설정 화면을 공급하는 가장 작은 모양이다. 코어가 관리자 화면에 이 템플릿 설정 묶음(`dist/settings/*`)을 싣고,
 * 이 템플릿 설정 화면 레이아웃(`settings/template_settings.g7.html`)이 이 부품을 `{템플릿id}.TemplateSettingsScreen` 으로 부른다.
 * 어느 관리자 템플릿이 켜져 있든 뜨도록 관리자 템플릿 부품을 쓰지 않고, 모양은 이 묶음 CSS(`settings.css`, `.hello-settings` 안)로만 잡는다.
 *
 * - 받기·저장·되돌리기는 레이아웃 몫이다. 이 부품은 값을 그리고 사용자의 뜻을 이벤트(onChange·onSave·onReset·onTabChange·onBack)로 알린다.
 * - 첨부 칸(파비콘)은 파일만 골라 두고 저장 때 올린다 — 레이아웃이 저장 전에 `uploadTriggerEvent` 를 보내면 여기서 받아 올린 뒤 값을 바꾼다.
 * - 이 템플릿이 선언한 칸 종류(첨부)만 그린다. 다른 종류를 선언하려면 그 종류를 그리는 부품도 함께 공급한다.
 */

import React, { useEffect, useRef, useState } from 'react';
import { Div } from '../components/basic/Div';
import { Span } from '../components/basic/Span';
import { Button } from '../components/basic/Button';
import { H2 } from '../components/basic/H2';
import { Img } from '../components/basic/Img';
import { FilePicker } from './FilePicker';
import { translate } from './translate';

/**
 * 문구를 번역합니다 — 번역이 없거나 엔진 밖(단위 테스트)이면 대체 문구를 쓴다.
 *
 * @param key 번역 키
 * @param fallback 대체 문구
 * @param params 치환 값
 * @returns 문구
 */
const t = (key: string, fallback: string, params?: Record<string, string | number>): string => {
  const translated = translate(key, params);

  return typeof translated === 'string' && translated !== key ? translated : fallback;
};

/** 첨부 하나 (조회 응답 값) */
interface SettingAttachment {
  id?: number;
  hash?: string;
  original_filename?: string;
  url?: string;
  download_url?: string;
}

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
  data?: { name?: string; is_active?: boolean; sections?: SettingSection[]; abilities?: { can_update?: boolean; can_reset?: boolean } } | null;
  value?: SettingsValue | null;
  errors?: Record<string, string[] | string> | null;
  activeSection?: string | null;
  readOnly?: boolean;
  dirty?: boolean;
  saving?: boolean;
  resetting?: boolean;
  status?: { type: 'success' | 'error'; action?: 'save' | 'reset'; message?: string | null } | null;
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
 * 첨부 칸 — 파일을 골라 두고, 저장 신호를 받으면 올려 값을 바꾼다.
 */
const AttachmentField: React.FC<{
  field: SettingField;
  current: SettingAttachment[];
  disabled: boolean;
  uploadTriggerEvent: string;
  onPicked: () => void;
  onUploaded: (next: SettingAttachment[]) => void;
  onError: (message: string) => void;
}> = ({ field, current, disabled, uploadTriggerEvent, onPicked, onUploaded, onError }) => {
  const [picked, setPicked] = useState<File | null>(null);
  const pickedRef = useRef<File | null>(null);
  pickedRef.current = picked;

  useEffect(() => {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const core = (window as any).G7Core;
    const unsubscribe = core?.componentEvent?.on?.(uploadTriggerEvent, async () => {
      const file = pickedRef.current;

      if (!file || !field.collection) {
        return;
      }

      const form = new FormData();
      form.append('file', file);
      form.append('collection', field.collection);

      try {
        const response = await core.api.post('/api/admin/attachments', form, { headers: { 'Content-Type': 'multipart/form-data' } });
        const attachment = response?.data?.data ?? response?.data;
        setPicked(null);
        onUploaded(attachment ? [attachment] : []);
      } catch (error: any) {
        onError(error?.response?.data?.message ?? error?.message ?? t('settings.screen.upload_failed', 'Upload failed'));
      }
    });

    return () => (typeof unsubscribe === 'function' ? unsubscribe() : undefined);
  }, [uploadTriggerEvent, field.collection, onUploaded, onError]);

  const file = current[0];
  const preview = file?.url ?? file?.download_url;

  return (
    <Div className="hello-settings__attachment">
      {file && !picked && (
        <Div className="hello-settings__file">
          {preview && <Img src={preview} alt="" className="hello-settings__preview" />}
          <Span>{file.original_filename ?? file.hash ?? ''}</Span>
          {!disabled && (
            <Button type="button" className="hello-settings__link" onClick={() => onUploaded([])}>
              {t('settings.screen.remove_file', 'Remove')}
            </Button>
          )}
        </Div>
      )}
      {picked && (
        <Div className="hello-settings__file">
          <Span>{t('settings.screen.picked_file', 'Selected: {{name}}', { name: picked.name })}</Span>
        </Div>
      )}
      {!disabled && (
        <FilePicker
          className="hello-settings__button"
          inputClassName="hello-settings__file-input"
          label={t('settings.screen.choose_file', 'Choose file')}
          ariaLabel={field.label}
          accept={field.accept}
          onPick={(next) => {
            setPicked(next);
            if (next) {
              onPicked();
            }
          }}
        />
      )}
    </Div>
  );
};

/**
 * 템플릿 설정 화면
 */
export const TemplateSettingsScreen: React.FC<TemplateSettingsScreenProps> = ({
  data,
  value,
  errors,
  activeSection,
  readOnly = true,
  dirty = false,
  saving = false,
  resetting = false,
  status,
  uploadTriggerEvent = 'upload:template_settings',
  onChange,
  onDirty,
  onTabChange,
  onSave,
  onReset,
  onBack,
  onUploadError,
}) => {
  const [confirm, setConfirm] = useState<{ kind: 'tab'; section: string } | { kind: 'reset' } | null>(null);
  const latest = useRef<SettingsValue>(value ?? {});
  latest.current = value ?? {};

  const sections = data?.sections ?? [];
  const current = sections.find((section) => section.key === activeSection) ?? sections[0];

  const write = (section: string, key: string, next: unknown) => {
    const form = latest.current;
    onChange?.({ ...form, [section]: { ...(form[section] ?? {}), [key]: next } });
  };

  const errorAt = (path: string): string | undefined => {
    const entry = errors?.[path];

    return Array.isArray(entry) ? entry[0] : (entry ?? undefined);
  };

  const statusText = (): string => {
    if (!status) {
      return '';
    }
    if (status.message) {
      return status.message;
    }
    if (status.action === 'reset') {
      return status.type === 'error' ? t('core.template_settings.reset_failed', 'Reset failed') : t('core.template_settings.reset_done', 'Reset done');
    }

    return status.type === 'error' ? t('core.template_settings.save_failed', 'Save failed') : t('core.template_settings.saved', 'Saved');
  };

  return (
    <Div className="hello-settings" data-testid="template-settings-screen">
      <Button type="button" className="hello-settings__link" onClick={() => onBack?.()}>
        {t('core.template_settings.back_to_list', 'Templates')}
      </Button>
      <H2 className="hello-settings__title">{t('core.template_settings.page_title', `${data?.name ?? ''} settings`, { name: data?.name ?? '' })}</H2>
      {data?.is_active === false && <Div role="status" className="hello-settings__notice">{t('core.template_settings.inactive_notice', '')}</Div>}
      {!!data && readOnly && <Div role="status" className="hello-settings__notice" data-testid="template-settings-read-only">{t('core.template_settings.read_only', '')}</Div>}

      <Div role="tablist" className="hello-settings__tabs">
        {sections.map((section) => (
          <Button
            key={section.key}
            type="button"
            role="tab"
            aria-selected={section.key === current?.key}
            data-testid="template-settings-tab"
            className={section.key === current?.key ? 'hello-settings__tab hello-settings__tab--active' : 'hello-settings__tab'}
            onClick={() => {
              if (section.key === current?.key) {
                return;
              }
              if (dirty) {
                setConfirm({ kind: 'tab', section: section.key });
              } else {
                onTabChange?.(section.key, false);
              }
            }}
          >
            {section.label}
          </Button>
        ))}
      </Div>

      {status && (
        <Div role={status.type === 'error' ? 'alert' : 'status'} data-testid="template-settings-status" className={`hello-settings__notice hello-settings__notice--${status.type}`}>
          {statusText()}
        </Div>
      )}

      {current && (
        <Div className="hello-settings__card">
          {current.hint && <Span className="hello-settings__hint">{current.hint}</Span>}
          {current.fields.map((field) => {
            const path = `${current.key}.${field.key}`;
            const sectionValue = (value ?? {})[current.key] ?? {};
            const error = errorAt(path) ?? errorAt(`${path}.0`);

            return (
              <Div key={field.key} className="hello-settings__field" id={`template_settings_field_${current.key}_${field.key}`}>
                <Span className="hello-settings__label">{field.label}</Span>
                {field.type === 'attachments' || field.type === 'image' ? (
                  <AttachmentField
                    field={field}
                    current={Array.isArray(sectionValue[field.key]) ? sectionValue[field.key] : []}
                    disabled={readOnly}
                    uploadTriggerEvent={uploadTriggerEvent}
                    onPicked={() => onDirty?.()}
                    onUploaded={(next) => write(current.key, field.key, next)}
                    onError={(message) => onUploadError?.(message)}
                  />
                ) : (
                  <Span className="hello-settings__hint">{t('settings.screen.unsupported_field', 'This field type is not supported by this screen.')}</Span>
                )}
                {field.hint && <Span className="hello-settings__hint">{field.hint}</Span>}
                {error && <Span role="alert" className="hello-settings__error">{error}</Span>}
              </Div>
            );
          })}
        </Div>
      )}

      {!!data && !readOnly && (
        <Div className="hello-settings__actions" data-testid="template-settings-footer">
          {data.abilities?.can_reset === true && (
            <Button type="button" className="hello-settings__button" disabled={saving || resetting} onClick={() => setConfirm({ kind: 'reset' })} data-testid="template-settings-reset">
              {t('core.template_settings.reset', 'Reset')}
            </Button>
          )}
          <Button type="button" id="save_button" className="hello-settings__button hello-settings__button--primary" disabled={!dirty || saving} onClick={() => onSave?.()} data-testid="template-settings-save">
            {saving ? t('core.template_settings.saving', 'Saving…') : t('core.template_settings.save', 'Save')}
          </Button>
        </Div>
      )}

      {confirm && (
        <Div className="hello-settings__overlay">
          <Div role="dialog" aria-modal="true" className="hello-settings__dialog" data-testid={confirm.kind === 'reset' ? 'template_settings_reset' : 'template_settings_unsaved_tab'}>
            <Span>{confirm.kind === 'reset' ? t('core.template_settings.reset_confirm', 'Reset all settings?') : t('core.template_settings.unsaved_tab_message', 'Unsaved changes will be lost.')}</Span>
            <Div className="hello-settings__actions">
              <Button type="button" className="hello-settings__button" onClick={() => setConfirm(null)}>
                {confirm.kind === 'reset' ? t('core.template_settings.cancel', 'Cancel') : t('core.template_settings.unsaved_tab_stay', 'Stay')}
              </Button>
              <Button
                type="button"
                className="hello-settings__button hello-settings__button--primary"
                data-testid={confirm.kind === 'reset' ? 'template_settings_reset_confirm' : 'template_settings_unsaved_tab_confirm'}
                onClick={() => {
                  const pending = confirm;
                  setConfirm(null);
                  if (pending.kind === 'reset') {
                    onReset?.();
                  } else {
                    onTabChange?.(pending.section, true);
                  }
                }}
              >
                {confirm.kind === 'reset' ? t('core.template_settings.reset', 'Reset') : t('core.template_settings.unsaved_tab_leave', 'Leave without saving')}
              </Button>
            </Div>
          </Div>
        </Div>
      )}
    </Div>
  );
};

export default TemplateSettingsScreen;

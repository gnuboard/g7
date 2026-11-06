/**
 * 템플릿 설정 화면 (sirsoft-admin_basic 공급, #434 D28·D29)
 *
 * 이 템플릿 설정 화면(`settings/template_settings.g7.html`)이 부르는 부품이다. 설정 화면 묶음(`dist/settings/*`)으로 공급돼
 * 어느 관리자 템플릿이 켜져 있든 이 부품으로 그린다 — 머리·안내·섹션 탭·저장 결과·칸·저장 바·확인 창을 직접 그리고, 관리자
 * 템플릿 부품(모달·토스트·탭 줄)에 기대지 않는다(부품이 다섯 개뿐인 관리자 템플릿에도 뜨게).
 *
 * 받기·저장·되돌리기·주소 바꾸기는 레이아웃이 한다 — 이 부품은 값과 상태를 받아 그리고 사용자의 뜻을 이벤트로 알린다.
 */

import React, { useEffect, useRef, useState } from 'react';
import { Div } from '../components/basic/Div';
import { Span } from '../components/basic/Span';
import { Button } from '../components/basic/Button';
import { Icon } from '../components/basic/Icon';
import { P } from '../components/basic/P';
import { H2 } from '../components/basic/H2';
import { A } from '../components/basic/A';
import { TemplateSettingsFields } from './TemplateSettingsFields';
import type { TemplateSettingSection, TemplateSettingsValue } from './TemplateSettingsFields';
import { translate } from '../utils/translate';

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

/** 조회 응답 `data` 중 화면이 쓰는 부분 */
export interface TemplateSettingsScreenData {
  name?: string;
  type?: 'admin' | 'user' | string;
  is_active?: boolean;
  sections?: TemplateSettingSection[];
  abilities?: { can_update?: boolean; can_reset?: boolean };
}

/** 저장·되돌리기 결과 안내 */
export interface TemplateSettingsScreenStatus {
  type: 'success' | 'error';
  /** 무엇의 결과인가 — 없으면 저장 */
  action?: 'save' | 'reset';
  /** 서버 문구 (없으면 결과 종류에 맞는 기본 문구) */
  message?: string | null;
}

/**
 * 결과 안내 문구 — 서버 문구가 있으면 그것, 없으면 결과 종류에 맞는 기본 문구.
 *
 * @param status 결과
 * @returns 문구
 */
const statusMessage = (status: TemplateSettingsScreenStatus): string => {
  if (status.message) {
    return status.message;
  }

  if (status.action === 'reset') {
    return status.type === 'error' ? t('core.template_settings.reset_failed', 'Reset failed') : t('core.template_settings.reset_done', 'Reset done');
  }

  return status.type === 'error' ? t('core.template_settings.save_failed', 'Save failed') : t('core.template_settings.saved', 'Saved');
};

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

/** 확인 창 종류 */
type Pending = { kind: 'tab'; section: string } | { kind: 'reset' } | null;

/**
 * 확인 창 — 화면 위에 겹쳐 그리고 첫 단추에 초점을 둔다. Escape 는 머무르기와 같다.
 */
const ConfirmDialog: React.FC<{
  title: string;
  message: string;
  cancelLabel: string;
  confirmLabel: string;
  danger?: boolean;
  busy?: boolean;
  onCancel: () => void;
  onConfirm: () => void;
  testId: string;
}> = ({ title, message, cancelLabel, confirmLabel, danger = false, busy = false, onCancel, onConfirm, testId }) => {
  const cancelRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    cancelRef.current?.focus();
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        onCancel();
      }
    };
    document.addEventListener('keydown', onKey);

    return () => document.removeEventListener('keydown', onKey);
  }, [onCancel]);

  return (
    <Div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" data-testid={testId}>
      <Div role="dialog" aria-modal="true" aria-labelledby={`${testId}_title`} className="w-full max-w-md rounded-lg bg-white p-5 shadow-xl dark:bg-gray-800">
        <H2 id={`${testId}_title`} className="text-lg font-semibold text-gray-900 dark:text-gray-100">{title}</H2>
        <P className="mt-2 text-sm text-gray-700 dark:text-gray-300">{message}</P>
        <Div className="mt-5 flex justify-end gap-2">
          <Button ref={cancelRef} type="button" className="btn btn-secondary" disabled={busy} onClick={onCancel}>
            {cancelLabel}
          </Button>
          <Button
            type="button"
            className={danger ? 'flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-red-600 dark:hover:bg-red-700' : 'rounded-lg bg-orange-700 px-4 py-2 text-white hover:bg-orange-800 dark:bg-orange-700 dark:hover:bg-orange-800'}
            disabled={busy}
            onClick={onConfirm}
            data-testid={`${testId}_confirm`}
          >
            {busy && <Icon name="spinner" className="spinner-sm" />}
            {confirmLabel}
          </Button>
        </Div>
      </Div>
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
  const [pending, setPending] = useState<Pending>(null);

  // 되돌리기가 끝나면(성공·실패) 확인 창을 닫는다
  const wasResetting = useRef(resetting);
  useEffect(() => {
    if (wasResetting.current && !resetting) {
      setPending(null);
    }
    wasResetting.current = resetting;
  }, [resetting]);

  const sections = data?.sections ?? [];
  const current = sections.find((section) => section.key === activeSection) ?? sections[0];
  const errorMessages = Object.values(errors ?? {}).map((message) => (Array.isArray(message) ? message[0] : message)).filter(Boolean);
  const isAdminTemplate = data?.type === 'admin';

  const chooseTab = (key: string) => {
    if (key === current?.key) {
      return;
    }

    if (dirty) {
      setPending({ kind: 'tab', section: key });

      return;
    }

    onTabChange?.(key, false);
  };

  return (
    <Div className="admin-page-content-responsive" data-testid="template-settings-screen">
      <Div className="mb-4 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
        <Div>
          <Button type="button" className="mb-1 inline-flex items-center gap-1 border-0 bg-transparent p-0 text-sm text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white" onClick={() => onBack?.()}>
            <Icon name="arrow-left" className="text-xs" />
            <Span>{t('core.template_settings.back_to_list', 'Templates')}</Span>
          </Button>
          <H2 className="text-xl font-semibold text-gray-900 dark:text-gray-100">
            {t('core.template_settings.page_title', `${data?.name ?? ''} settings`, { name: data?.name ?? '' })}
          </H2>
          <P className="mt-1 text-sm text-gray-600 dark:text-gray-300">{t('core.template_settings.description', '')}</P>
        </Div>
        {data?.is_active === true && (
          <A href={isAdminTemplate ? '/admin' : '/'} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 text-sm text-blue-600 hover:underline dark:text-blue-400">
            <Icon name="arrow-up-right-from-square" className="text-xs" />
            <Span>{isAdminTemplate ? t('core.template_settings.view_admin', 'View admin') : t('core.template_settings.view_site', 'View site')}</Span>
          </A>
        )}
      </Div>

      {data?.is_active === false && (
        <Div role="status" className="alert-info mb-3">
          <Div className="flex-center gap-3">
            <Icon name="circle-info" className="flex-shrink-0 text-xl text-blue-600 dark:text-blue-400" />
            <P className="text-info-soft">{t('core.template_settings.inactive_notice', '')}</P>
          </Div>
        </Div>
      )}
      {!!data && readOnly && (
        <Div role="status" className="alert-warning mb-3" data-testid="template-settings-read-only">
          <Div className="flex-center gap-3">
            <Icon name="lock" className="flex-shrink-0 text-xl text-yellow-700 dark:text-yellow-400" />
            <P className="text-warning-soft">{t('core.template_settings.read_only', '')}</P>
          </Div>
        </Div>
      )}

      <Div role="tablist" aria-label={t('core.template_settings.page_title_short', 'Template settings')} className="mb-4 flex flex-wrap gap-1 border-b border-gray-200 dark:border-gray-700">
        {/* 설정 묶음은 화면 초기화(preflight)를 싣지 않는다 — 다른 관리자 템플릿 위에서도 단추 기본 바탕·테두리가 남지 않게 직접 지운다 */}
        {sections.map((section) => {
          const selected = section.key === current?.key;

          return (
            <Button
              key={section.key}
              type="button"
              role="tab"
              aria-selected={selected}
              data-testid="template-settings-tab"
              data-section={section.key}
              className={`-mb-px rounded-none border-0 border-b-2 border-solid bg-transparent px-4 py-2 text-sm font-medium ${selected ? 'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400' : 'border-transparent text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white'}`}
              onClick={() => chooseTab(section.key)}
            >
              {section.label}
            </Button>
          );
        })}
      </Div>

      {status && (
        <Div role={status.type === 'error' ? 'alert' : 'status'} data-testid="template-settings-status" data-status={status.type} className={`mb-3 ${status.type === 'error' ? 'alert-danger' : 'alert-success'}`}>
          <P>{statusMessage(status)}</P>
        </Div>
      )}

      {errorMessages.length > 0 && (
        <Div role="alert" className="alert-danger mb-3" data-testid="template-settings-error-summary">
          <Div className="flex gap-3">
            <Icon name="triangle-exclamation" className="flex-shrink-0 text-xl text-red-600 dark:text-red-400" />
            <Div>
              <P className="font-medium text-red-700 dark:text-red-300">{t('core.template_settings.error_summary', 'Check the fields')}</P>
              <Div className="mt-1 text-sm text-red-700 dark:text-red-300">
                {errorMessages.map((message, index) => (
                  <P key={index}>{message}</P>
                ))}
              </Div>
            </Div>
          </Div>
        </Div>
      )}

      <Div className="tab-content">
        <TemplateSettingsFields
          sections={current ? [current] : []}
          value={value ?? {}}
          errors={errors ?? {}}
          disabled={readOnly}
          uploadTriggerEvent={uploadTriggerEvent}
          onChange={(next) => onChange?.(next)}
          onDirty={() => onDirty?.()}
          onUploadError={(message) => onUploadError?.(message)}
        />
      </Div>

      {!!data && !readOnly && (
        <Div className="sticky-footer-buttons" data-testid="template-settings-footer">
          <Button type="button" className="btn btn-secondary" onClick={() => onBack?.()}>
            {t('core.template_settings.cancel', 'Cancel')}
          </Button>
          {data.abilities?.can_reset === true && (
            <Button type="button" className="btn btn-secondary" disabled={saving} onClick={() => setPending({ kind: 'reset' })} data-testid="template-settings-reset">
              {t('core.template_settings.reset', 'Reset')}
            </Button>
          )}
          <Button type="button" id="save_button" className="btn btn-primary flex-center gap-1.5" disabled={readOnly || !dirty || saving} onClick={() => onSave?.()} data-testid="template-settings-save">
            {saving && <Icon name="spinner" className="spinner-sm" />}
            <Span>{saving ? t('core.template_settings.saving', 'Saving…') : t('core.template_settings.save', 'Save')}</Span>
          </Button>
        </Div>
      )}

      {pending?.kind === 'tab' && (
        <ConfirmDialog
          testId="template_settings_unsaved_tab"
          title={t('core.template_settings.unsaved_tab_title', 'Unsaved changes')}
          message={t('core.template_settings.unsaved_tab_message', '')}
          cancelLabel={t('core.template_settings.unsaved_tab_stay', 'Stay')}
          confirmLabel={t('core.template_settings.unsaved_tab_leave', 'Leave without saving')}
          onCancel={() => setPending(null)}
          onConfirm={() => {
            const section = pending.section;
            setPending(null);
            onTabChange?.(section, true);
          }}
        />
      )}
      {pending?.kind === 'reset' && (
        <ConfirmDialog
          testId="template_settings_reset"
          title={t('core.template_settings.reset_title', 'Reset to defaults')}
          message={t('core.template_settings.reset_confirm', '')}
          cancelLabel={t('core.template_settings.cancel', 'Cancel')}
          confirmLabel={t('core.template_settings.reset', 'Reset')}
          danger
          busy={resetting}
          onCancel={() => setPending(null)}
          onConfirm={() => onReset?.()}
        />
      )}
    </Div>
  );
};

export default TemplateSettingsScreen;

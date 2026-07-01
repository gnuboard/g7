/**
 * MetaForm.tsx — [기본 정보] 탭 본체
 *
 * 페이지 설정 [기본 정보] 탭. 레이아웃 최상위 `meta`(title/description/icon/editor_label)
 * 와 최상위 `permissions` 를 친화 위젯으로 편집한다(항목표):
 *
 *  - 페이지 이름(`meta.title`)·설명(`meta.description`)·편집기 트리 라벨(`meta.editor_label`)
 *    = `I18nTextField`(평문/`$t:custom.*`/펼침 번역탭/+데이터 칩). 표현식(`{{...}}`)이면
 *    I18nTextField 가 자체적으로 "바인딩됨(코드 편집)" 읽기전용 배지로 디그레이드한다.
 *  - 메뉴 아이콘(`meta.icon`) = `icon-picker` 위젯(템플릿 등록). 미등록 시 자유 입력 폴백.
 *    그리드는 기본 닫힘(확정 14) — 위젯이 닫힌 트리거를 제공한다.
 *  - 페이지 접근 권한(최상위 `permissions`) = `TagInputControl`(코어+활성확장 권한 후보).
 *
 * 본 폼은 prop 주도(신규 인프라 0) — 셸(PageSettingsModal, 세션 D)이 `usePageSettings` 의
 * getValue/patch 와 후보 풀을 주입한다. **auth_mode/hidden 항목 없음**(2차 사실확인
 * 제거: meta 에 인증 게이트 부재, hidden 은 메뉴 관리 소관).
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만(CSS 라이브러리 비종속).
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useState } from 'react';
import { I18nTextField } from '../property-controls/I18nTextField';
import { TagInputControl } from '../property-controls/TagInputControl';
import { getWidget } from '../../spec/widgetRegistry';
import type { EditorSpec } from '../../spec/specTypes';
import { getControl } from '../../spec/editorSpecLoader';
import type { BindingCandidate } from '../../spec/bindingCandidates';

export interface MetaFormProps {
  /** 최상위 키 1건 읽기(usePageSettings.getValue) */
  getValue: <T = unknown>(key: string, fallback?: T) => T;
  /** 최상위 키 1건 패치(usePageSettings.patch) */
  patch: (key: string, value: unknown, originalValue?: unknown) => void;
  /** 다국어 해석(편집기 UI 라벨용 — `layout_editor.*`) */
  t: (key: string, params?: Record<string, string | number>) => string;
  /**
   * 값 필드(제목/설명) 전용 해석기 — 런타임 앱 lang 키(`board.edit_post` 등)까지 해석한다
   * (editorAwareT/resolveLabel, G7Core.t 폴백). 분기 키 칩화 시 기존 텍스트 보존에 필수
   * 미전달 시 `t` 폴백(기존 동작).
   */
  fieldT?: (key: string, params?: Record<string, string | number>) => string;
  /** 병합 editor-spec — icon-picker 컨트롤 정의 조회용 */
  spec?: EditorSpec | null;
  /** 권한 키 후보(코어+활성확장) — TagInputControl */
  permissionCandidates?: Array<{ value: string; label: string }>;
  /** 데이터 칩 후보 풀 — I18nTextField +데이터 */
  bindingCandidates?: BindingCandidate[];
}

/** meta 객체에서 한 필드를 읽는다(없으면 '') */
function readMeta(getValue: MetaFormProps['getValue'], field: string): string {
  const meta = getValue<Record<string, unknown>>('meta', {});
  const v = meta?.[field];
  return typeof v === 'string' ? v : '';
}

/**
 * [기본 정보] 탭 폼.
 *
 * @param props MetaFormProps
 * @return 기본 정보 폼 엘리먼트
 */
export function MetaForm({
  getValue,
  patch,
  t,
  fieldT,
  spec,
  permissionCandidates,
  bindingCandidates,
}: MetaFormProps): React.ReactElement {
  // 값 필드(제목/설명/트리라벨) 해석기 — 런타임 앱 lang 키까지. 미전달 시 t 폴백.
  const valueT = fieldT ?? t;
  const title = readMeta(getValue, 'title');
  const description = readMeta(getValue, 'description');
  const icon = readMeta(getValue, 'icon');
  const editorLabel = readMeta(getValue, 'editor_label');
  const permissions = getValue<string[]>('permissions', []);

  /** meta 의 한 필드를 무손실 패치(빈 값이면 키 제거 — 비파괴) */
  const patchMetaField = useCallback(
    (field: string, value: string | undefined): void => {
      const meta = { ...(getValue<Record<string, unknown>>('meta', {}) ?? {}) };
      if (value === undefined || value === '') {
        delete meta[field];
      } else {
        meta[field] = value;
      }
      patch('meta', meta);
    },
    [getValue, patch],
  );

  const patchPermissions = useCallback(
    (value: unknown): void => {
      const arr = Array.isArray(value) ? (value as string[]) : [];
      // 빈 배열 = 제약 없음 — 키 자체 제거(비파괴).
      if (arr.length === 0) patch('permissions', undefined);
      else patch('permissions', arr);
    },
    [patch],
  );

  const IconWidget = getWidget('icon-picker');
  // 메뉴 아이콘 카탈로그 — 전용 'icon-picker'/'icon' 컨트롤이 없으면(번들 미정의), 스펙 controls 중
  // widget==='icon-picker' 인 첫 컨트롤의 icons 카탈로그를 재사용한다. 그래야 그리드(아이콘 격자)가
  // 뜬다. 카탈로그가 전혀 없으면 위젯이 자유 텍스트로 디그레이드(무손실). (D-F 정정)
  const iconControl =
    getControl(spec, 'icon-picker') ??
    getControl(spec, 'icon') ??
    (spec?.controls
      ? Object.values(spec.controls).find(
          (c) => c && typeof c === 'object' && (c as { widget?: unknown }).widget === 'icon-picker',
        ) ?? null
      : null);

  return (
    <div className="g7le-meta-form" data-testid="g7le-meta-form" style={form}>
      {/* 페이지 이름 —표현식+다국어(`{{route.id ? '$t:edit': '$t:new'}}`)는 I18nTextField
          가 ConditionalValueEditor 분해 트리로 띄운다(종전 raw 고급 배지 디그레이드 제거). 평문/키/칩은
          그대로, 못 푸는 식만 읽기전용. */}
      <Field label={t('layout_editor.page_settings.meta.title')}>
        <I18nTextField
          value={title}
          onChange={(v) => patchMetaField('title', v)}
          t={valueT}
          testidPrefix="g7le-meta-title"
          candidates={bindingCandidates}
          enableExpressionTree
          expressionTreeCollapsible
        />
      </Field>

      {/* 페이지 설명 — 동일(표현식 분해 트리 위임) */}
      <Field label={t('layout_editor.page_settings.meta.description')}>
        <I18nTextField
          value={description}
          onChange={(v) => patchMetaField('description', v)}
          t={valueT}
          testidPrefix="g7le-meta-description"
          candidates={bindingCandidates}
          enableExpressionTree
          expressionTreeCollapsible
        />
      </Field>

      {/* 메뉴 아이콘 — icon-picker(템플릿 등록). 그리드는 기본 닫힘(S5 합의 — 펼치기 토글). 미등록 시 자유 입력 폴백. */}
      <Field label={t('layout_editor.page_settings.meta.icon')}>
        {IconWidget ? (
          <IconPickerCollapsible
            icon={icon}
            t={t}
            onChange={(v) => patchMetaField('icon', typeof v === 'string' ? v : undefined)}
            IconWidget={IconWidget}
            iconControl={iconControl ?? { widget: 'icon-picker' }}
          />
        ) : (
          <input
            type="text"
            data-testid="g7le-meta-icon"
            value={icon}
            placeholder={t('layout_editor.page_settings.meta.icon_placeholder')}
            onChange={(e) => patchMetaField('icon', e.target.value)}
            style={textInput}
          />
        )}
      </Field>

      {/* 편집기 트리 라벨(선택) */}
      <Field
        label={t('layout_editor.page_settings.meta.editor_label')}
        hint={t('layout_editor.page_settings.meta.editor_label_hint')}
      >
        <I18nTextField
          value={editorLabel}
          onChange={(v) => patchMetaField('editor_label', v)}
          t={valueT}
          testidPrefix="g7le-meta-editor-label"
          candidates={bindingCandidates}
          enableExpressionTree
          expressionTreeCollapsible
        />
      </Field>

      {/* 페이지 접근 권한 */}
      <Field
        label={t('layout_editor.page_settings.meta.permissions')}
        hint={t('layout_editor.page_settings.meta.permissions_hint')}
      >
        <div data-testid="g7le-meta-permissions">
          <TagInputControl
            control={{ widget: 'tag-input' }}
            value={permissions}
            onChange={patchPermissions}
            t={t}
            candidates={permissionCandidates}
          />
        </div>
      </Field>
    </div>
  );
}

/** 라벨 + 본체 + 선택 힌트 행 */
function Field({
  label,
  hint,
  children,
}: {
  label: string;
  hint?: string;
  children: React.ReactNode;
}): React.ReactElement {
  return (
    <div className="g7le-meta-field" style={fieldWrap}>
      <label style={fieldLabel}>{label}</label>
      {children}
      {hint ? <p style={fieldHint}>ⓘ {hint}</p> : null}
    </div>
  );
}

/**
 * 메뉴 아이콘 picker — 그리드 기본 닫힘(S5 합의), 토글로 펼침. 닫힘 상태는 현재 아이콘 + "아이콘 선택"
 * 버튼만 노출(1391칸 상시 렌더 방지). 펼치면 템플릿 icon-picker 위젯(검색+그리드) 렌더.
 *
 * @param props icon/onChange/t/IconWidget/iconControl
 * @return 접이식 아이콘 picker
 */
function IconPickerCollapsible({
  icon,
  onChange,
  t,
  IconWidget,
  iconControl,
}: {
  icon: string;
  onChange: (v: unknown) => void;
  t: MetaFormProps['t'];
  IconWidget: React.ComponentType<Record<string, unknown>>;
  iconControl: Record<string, unknown>;
}): React.ReactElement {
  const [open, setOpen] = useState(false);
  const glyph = icon ? (/\bfa-/.test(icon) ? <i className={icon} aria-hidden="true" /> : icon) : '📄';
  return (
    <div data-testid="g7le-meta-icon" style={{ display: 'flex', flexDirection: 'column', gap: 6, minWidth: 0 }}>
      <button
        type="button"
        data-testid="g7le-meta-icon-toggle"
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
        style={{
          display: 'flex',
          alignItems: 'center',
          gap: 8,
          padding: '5px 8px',
          border: '1px solid #cbd5e1',
          borderRadius: 6,
          background: '#fff',
          cursor: 'pointer',
          fontSize: 12,
          color: '#475569',
          width: 'fit-content',
        }}
      >
        <span style={{ fontSize: 16 }} aria-hidden="true">{glyph}</span>
        <span>{icon || t('layout_editor.page_settings.meta.icon_placeholder')}</span>
        <span aria-hidden="true">{open ? '▴' : '▾'}</span>
      </button>
      {open ? (
        <IconWidget
          control={iconControl}
          value={icon}
          onChange={(v: unknown) => {
            onChange(v);
            setOpen(false);
          }}
          t={t}
        />
      ) : null}
    </div>
  );
}

const form: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 16, minWidth: 0 };
const fieldWrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6, minWidth: 0 };
const fieldLabel: React.CSSProperties = { fontSize: 13, fontWeight: 600, color: '#0f172a' };
const fieldHint: React.CSSProperties = { margin: 0, fontSize: 11, color: '#94a3b8' };
const textInput: React.CSSProperties = { padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, minWidth: 0, width: '100%' };

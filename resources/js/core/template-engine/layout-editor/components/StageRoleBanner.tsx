/**
 * StageRoleBanner.tsx — 검수 서버(실행 역할 stage)에서 편집기 머리에 「검수용 저장」 안내를 보인다
 *
 * 검수 서버에서 이 편집기로 저장한 레이아웃은 검수 서버 표(`stage_template_layouts`)에만 남고 운영 사이트에 반영되지 않는다.
 * 관리자 화면 상단 띠와 코드 편집 화면의 안내는 관리자 템플릿 레이아웃이 그리는데, 이 편집기 셸은 관리자 템플릿 레이아웃
 * 밖이라 실행 역할을 직접 읽는다 — 셸 설정 `G7Config.appConfig.runtime_role`(서버가 요청마다 싣는 값, `docs/backend/runtime-role.md`).
 *
 * 상태 저장소를 읽거나 쓰지 않는 표시 전용 부품이다. 닫기 단추는 두지 않는다 — 편집하는 동안 늘 참인 사실이다.
 * 색은 밝은 색 고정이다(편집기 화면 틀이 다크 모드를 따르지 않음 — SpecLoadErrorBanner 와 같은 이유).
 *
 * 마운트 위치: LayoutEditorChrome 의 EditorToolbar 직하(SaveFeedbackBanner·SpecLoadErrorBanner 와 같은 줄).
 *
 * @since engine-v1.96.4
 */

import React from 'react';
import { useTranslation } from '../../TranslationContext';

/**
 * 이 서버가 검수 서버(stage)인지 셸 설정에서 읽는다.
 *
 * @returns stage 면 true
 */
export function isStageRuntimeRole(): boolean {
  if (typeof window === 'undefined') return false;
  return (window as any).G7Config?.appConfig?.runtime_role === 'stage';
}

export function StageRoleBanner(): React.ReactElement | null {
  const { t } = useTranslation();
  if (!isStageRuntimeRole()) return null;

  return (
    <div
      role="status"
      data-testid="g7le-stage-role-banner"
      className="g7le-stage-role-banner"
      style={{
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        padding: '8px 12px',
        background: '#fffbeb',
        color: '#92400e',
        borderBottom: '1px solid #fde68a',
        fontSize: 13,
      }}
    >
      <span aria-hidden="true">⚠</span>
      <span style={{ flex: '1 1 240px', minWidth: 0 }}>{t('layout_editor.chrome.stage_banner')}</span>
    </div>
  );
}

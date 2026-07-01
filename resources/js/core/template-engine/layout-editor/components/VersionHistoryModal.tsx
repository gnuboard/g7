/**
 * VersionHistoryModal.tsx — 레이아웃 버전 히스토리 모달
 *
 * 현재 편집 대상 레이아웃의 저장 버전 목록을 조회·복원한다. 기존
 * 코드 편집기가 쓰는 `LayoutController` 의 버전 API(versions/restoreVersion)를
 * `useLayoutVersions` 로 재사용하며(신규 백엔드 없음), 복원 성공 시 호출자가
 * `useLayoutDocument.reload()` 로 캔버스를 서버 최신(복원본)으로 재로드한다.
 *
 * 🖼이미지·🌐다국어·⚙데이터 관리 모달과 동형 진입(상단 🕘 툴바 버튼)이며, 편집기
 * 코어 위젯이므로 `g7le-*` + 인라인 스타일만(CSS 라이브러리 비종속).
 *
 * extension 편집 모드는 layoutName 이 없어 버전 대상이 없다 → 툴바 버튼이 비활성된다.
 *
 * 각 행에서 "비교" 를 누르면 직전(이전 번호) 버전과의 Unified diff 를 GitHub 스타일로
 * 보여 준다(VersionDiffView). 저장자 이름도 함께 표시한다(백엔드 created_by_name).
 *
 * @since engine-v1.50.0
 * @since engine-v1.50.0
 */

import React, { useCallback, useEffect, useState } from 'react';
import {
  useLayoutVersions,
  type VersionTarget,
  type LayoutVersionSummary,
  type RestoreResult,
} from '../hooks/useLayoutVersions';
import { VersionDiffView } from './VersionDiffView';

export interface VersionHistoryModalProps {
  /** 편집 대상 템플릿 식별자 */
  templateIdentifier: string;
  /**
   * 버전 기록/복원 대상 — 레이아웃 본체(layoutName) 또는
   * 확장 조각(extensionId). 목록/단건/복원 API 가 대상별로 분기된다.
   */
  target: VersionTarget;
  /** 다국어 해석 함수 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /**
   * 복원 성공 시 호출 — 호출자가 useLayoutDocument.reload() 수행 (캔버스 재로드 +
   * dirty/lock_version 재동기화). 본 모달은 reload 자체를 모른다.
   * 복원으로 적재된 새 버전 번호가 전달된다.
   */
  onRestored: (newVersion?: number) => void | Promise<void>;
  /** 모달 닫기 */
  onClose: () => void;
}

const wrap: React.CSSProperties = {
  display: 'flex',
  flexDirection: 'column',
  height: '100%',
  minHeight: 0,
};

const header: React.CSSProperties = {
  display: 'flex',
  alignItems: 'center',
  gap: 8,
  padding: '12px 16px',
  borderBottom: '1px solid #e2e8f0',
  background: '#f8fafc',
};

const closeBtn: React.CSSProperties = {
  marginLeft: 'auto',
  border: 'none',
  background: 'transparent',
  fontSize: 16,
  color: '#64748b',
  cursor: 'pointer',
  outline: 'none',
};

const body: React.CSSProperties = {
  flex: 1,
  minHeight: 0,
  overflowY: 'auto',
  padding: 12,
};

const hint: React.CSSProperties = {
  padding: '24px 12px',
  textAlign: 'center',
  color: '#94a3b8',
  fontSize: 13,
};

const errorBar: React.CSSProperties = {
  margin: '8px 16px 0',
  padding: '8px 12px',
  borderRadius: 6,
  background: '#fef2f2',
  color: '#b91c1c',
  fontSize: 12,
  border: '1px solid #fecaca',
};

const listWrap: React.CSSProperties = {
  display: 'flex',
  flexDirection: 'column',
  gap: 8,
};

const row: React.CSSProperties = {
  display: 'flex',
  alignItems: 'center',
  gap: 12,
  padding: '10px 12px',
  borderRadius: 8,
  border: '1px solid #e2e8f0',
  background: '#ffffff',
};

const versionBadge: React.CSSProperties = {
  display: 'inline-flex',
  alignItems: 'center',
  justifyContent: 'center',
  minWidth: 44,
  padding: '2px 8px',
  borderRadius: 999,
  background: '#eff6ff',
  color: '#1d4ed8',
  fontSize: 12,
  fontWeight: 700,
};

const latestBadge: React.CSSProperties = {
  display: 'inline-flex',
  alignItems: 'center',
  padding: '1px 8px',
  borderRadius: 999,
  background: '#0f172a',
  color: '#ffffff',
  fontSize: 10,
  fontWeight: 700,
  letterSpacing: 0.3,
};

const diffRow: React.CSSProperties = {
  display: 'flex',
  alignItems: 'center',
  gap: 10,
  fontSize: 11,
  fontWeight: 600,
};

const summaryWrap: React.CSSProperties = {
  display: 'flex',
  flexDirection: 'column',
  gap: 2,
  minWidth: 0,
  flex: 1,
};

const restoreBtn: React.CSSProperties = {
  padding: '6px 12px',
  borderRadius: 6,
  border: '1px solid #cbd5e1',
  background: '#ffffff',
  color: '#0f172a',
  fontSize: 12,
  fontWeight: 600,
  cursor: 'pointer',
  outline: 'none',
  whiteSpace: 'nowrap',
};

/**
 * 버전 detail 에서 diff 비교 대상 content 를 추출.
 *
 * 우선순위: full_content(원본 전체 — slots/extends 등 분해되지 않는 키 포함)가 있으면
 * 그것을 비교 대상으로 쓴다. 구버전 응답/누락 시 분해 키(components/data_sources/
 * metadata/endpoint)로 폴백한다. extends 기반 레이아웃(home 등)은 컴포넌트가 slots 에
 * 있어 분해 키만 쓰면 빈 비교가 되므로 full_content 우선이 핵심.
 */
function stripVersionMeta(detail: {
  endpoint: unknown;
  components: unknown;
  data_sources: unknown;
  metadata: unknown;
  full_content?: Record<string, unknown>;
}): Record<string, unknown> {
  if (detail.full_content && typeof detail.full_content === 'object') {
    return detail.full_content;
  }
  return {
    endpoint: detail.endpoint,
    components: detail.components,
    data_sources: detail.data_sources,
    metadata: detail.metadata,
  };
}

/** ISO timestamp → 로캘 표시 문자열. 파싱 실패 시 원문 그대로. */
function formatTimestamp(value: string | null): string {
  if (!value) return '';
  const ts = Date.parse(value);
  if (Number.isNaN(ts)) return value;
  try {
    return new Date(ts).toLocaleString();
  } catch {
    return value;
  }
}

export function VersionHistoryModal({
  templateIdentifier,
  target,
  t,
  onRestored,
  onClose,
}: VersionHistoryModalProps): React.ReactElement {
  const { versions, isLoading, error, restoringId, loadVersions, restore, loadVersionDetail } =
    useLayoutVersions(templateIdentifier, target, onRestored);
  const [actionError, setActionError] = useState<string | null>(null);

  // diff 뷰 상태 — null 이면 목록 뷰. 값이 있으면 두 버전 content 의 Unified diff 표시.
  const [diffView, setDiffView] = useState<{
    oldVersion: number;
    newVersion: number;
    oldContent: unknown;
    newContent: unknown;
  } | null>(null);
  // 비교 로딩 중인 버전 번호 (null = 없음) — 행 버튼 disabled.
  const [comparingVersion, setComparingVersion] = useState<number | null>(null);

  useEffect(() => {
    void loadVersions();
  }, [loadVersions]);

  // 버전 비교 — 해당 버전과 그 직전(번호-1) 버전의 content 를 fetch 해 diff 뷰로 전환.
  // 가장 오래된 버전(직전 없음)은 빈 객체와 비교(전체 추가로 표시).
  const handleCompare = useCallback(
    async (it: LayoutVersionSummary): Promise<void> => {
      setActionError(null);
      setComparingVersion(it.version);
      try {
        const newResult = await loadVersionDetail(it.version);
        if (newResult.kind !== 'success') {
          setActionError(
            newResult.kind === 'not_found'
              ? t('layout_editor.version_history.restore_not_found')
              : newResult.message,
          );
          return;
        }
        // 직전 버전 — 목록에 존재하면 그 번호로 fetch, 없으면 빈 객체(최초 버전).
        const prevVersion = it.version - 1;
        const hasPrev = versions.some((v) => v.version === prevVersion);
        let oldContent: unknown = {};
        if (hasPrev) {
          const oldResult = await loadVersionDetail(prevVersion);
          if (oldResult.kind === 'success') {
            oldContent = stripVersionMeta(oldResult.detail);
          } else if (oldResult.kind === 'network_error') {
            setActionError(oldResult.message);
            return;
          }
        }
        setDiffView({
          oldVersion: hasPrev ? prevVersion : 0,
          newVersion: it.version,
          oldContent,
          newContent: stripVersionMeta(newResult.detail),
        });
      } finally {
        setComparingVersion(null);
      }
    },
    [loadVersionDetail, versions, t],
  );

  const handleRestore = useCallback(
    async (versionId: number): Promise<void> => {
      setActionError(null);
      const confirmMsg = t('layout_editor.version_history.restore_confirm');
      const proceed = typeof window !== 'undefined' ? window.confirm(confirmMsg) : true;
      if (!proceed) return;
      const result: RestoreResult = await restore(versionId);
      if (result.kind === 'success') {
        // 복원 성공 — 캔버스가 onRestored(reload)로 재로드되었으므로 모달을 닫는다.
        onClose();
      } else if (result.kind === 'not_found') {
        setActionError(t('layout_editor.version_history.restore_not_found'));
      } else {
        setActionError(result.message);
      }
    },
    [restore, onClose, t],
  );

  const displayError = actionError ?? error;

  // diff 뷰 활성 시 — 목록 대신 Unified diff 표시(같은 모달 프레임 내 뷰 전환).
  if (diffView) {
    return (
      <VersionDiffView
        oldVersion={diffView.oldVersion}
        newVersion={diffView.newVersion}
        oldContent={diffView.oldContent}
        newContent={diffView.newContent}
        t={t}
        onBack={() => setDiffView(null)}
        onClose={onClose}
      />
    );
  }

  return (
    <div className="g7le-version-history" data-testid="g7le-version-history" style={wrap}>
      <div data-modal-drag-handle style={header}>
        <span style={{ fontSize: 14, fontWeight: 700 }} data-testid="g7le-version-history-title">
          🕘 {t('layout_editor.version_history.title')}
        </span>
        <button
          type="button"
          aria-label={t('layout_editor.version_history.close')}
          onClick={onClose}
          style={closeBtn}
          data-testid="g7le-version-history-close"
        >
          ✕
        </button>
      </div>

      {displayError && (
        <div style={errorBar} data-testid="g7le-version-history-error">
          {displayError}
        </div>
      )}

      <div style={body}>
        {isLoading ? (
          <div style={hint} data-testid="g7le-version-history-loading">
            {t('layout_editor.version_history.loading')}
          </div>
        ) : versions.length === 0 ? (
          <div style={hint} data-testid="g7le-version-history-empty">
            {t('layout_editor.version_history.empty')}
          </div>
        ) : (
          <div data-testid="g7le-version-history-list" style={listWrap}>
            {versions.map((it, idx) => {
              const cs = it.changes_summary;
              const added = cs?.added_count ?? 0;
              const removed = cs?.removed_count ?? 0;
              const charDiff = cs?.char_diff ?? 0;
              const when = formatTimestamp(it.created_at);
              const isRestoring = restoringId === it.id;
              // 목록은 최근 버전 우선(버전 desc) — 첫 행(idx 0)이 최신.
              const isLatest = idx === 0;
              return (
                <div key={it.id} style={row} data-testid={`g7le-version-row-${it.id}`}>
                  <span style={versionBadge} data-testid={`g7le-version-badge-${it.id}`}>
                    v{it.version}
                  </span>
                  <div style={summaryWrap}>
                    <span style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                      {when && (
                        <span style={{ fontSize: 12, color: '#475569', fontWeight: 600 }}>{when}</span>
                      )}
                      {isLatest && (
                        <span style={latestBadge} data-testid={`g7le-version-latest-${it.id}`}>
                          {t('layout_editor.version_history.latest')}
                        </span>
                      )}
                    </span>
                    {/* 변경량 — 라인 단위(추가/삭제 라인 수 + 문자수). 코드 편집기 버전 히스토리와
                        동일 단위. modified(~변경)는 라인 diff 에 대응 개념이 없어(값 변경 = 삭제+추가
 라인) 항상 0 이므로 컬럼을 두지 않는다. */}
                    <span style={diffRow} data-testid={`g7le-version-summary-${it.id}`}>
                      <span style={{ color: '#16a34a' }} data-testid={`g7le-version-added-${it.id}`}>
                        {t('layout_editor.version_history.added', { count: added })}
                      </span>
                      <span style={{ color: '#dc2626' }} data-testid={`g7le-version-removed-${it.id}`}>
                        {t('layout_editor.version_history.removed', { count: removed })}
                      </span>
                      <span style={{ color: '#94a3b8', fontWeight: 500 }} data-testid={`g7le-version-chars-${it.id}`}>
                        {t('layout_editor.version_history.chars', {
                          diff: `${charDiff >= 0 ? '+' : ''}${charDiff}`,
                        })}
                      </span>
                    </span>
                    {/* 저장자 — 백엔드 created_by_name(이름만). 탈퇴/미상 시 폴백 라벨. */}
                    <span style={{ fontSize: 11, color: '#94a3b8' }} data-testid={`g7le-version-author-${it.id}`}>
                      {t('layout_editor.version_history.saved_by', {
                        name: it.created_by_name ?? t('layout_editor.version_history.unknown_author'),
                      })}
                    </span>
                  </div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                    {/* 비교 — 직전 버전과의 Unified diff. 모든 행에 노출(최초 버전은 빈 기준과 비교). */}
                    <button
                      type="button"
                      onClick={() => void handleCompare(it)}
                      disabled={comparingVersion !== null}
                      style={{
                        ...restoreBtn,
                        background: '#f8fafc',
                        opacity: comparingVersion !== null ? 0.6 : 1,
                      }}
                      data-testid={`g7le-version-compare-${it.id}`}
                    >
                      {comparingVersion === it.version
                        ? t('layout_editor.version_history.comparing')
                        : t('layout_editor.version_history.compare')}
                    </button>
                    {/* 최신 버전은 이미 적용 상태 → 복원 버튼 숨김(코드 편집기 패리티). */}
                    {!isLatest && (
                      <button
                        type="button"
                        onClick={() => void handleRestore(it.id)}
                        disabled={isRestoring || restoringId !== null}
                        style={{ ...restoreBtn, opacity: isRestoring || restoringId !== null ? 0.6 : 1 }}
                        data-testid={`g7le-version-restore-${it.id}`}
                      >
                        {isRestoring
                          ? t('layout_editor.version_history.restoring')
                          : t('layout_editor.version_history.restore')}
                      </button>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
}

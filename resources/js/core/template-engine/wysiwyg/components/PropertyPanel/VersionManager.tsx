/**
 * VersionManager.tsx
 *
 * G7 위지윅 레이아웃 편집기 - 버전 관리자
 *
 * 역할:
 * - 레이아웃 버전 목록 표시
 * - 버전 상세 정보 조회
 * - 버전 간 변경사항 시각화
 * - 버전 복원 기능
 *
 * 백엔드 API 연동:
 * - GET /admin/templates/{templateName}/layouts/{name}/versions
 * - GET /admin/templates/{templateName}/layouts/{name}/versions/{version}
 * - POST /admin/templates/{templateName}/layouts/{name}/versions/{versionId}/restore
 *
 * Phase 4: 버전 관리
 */

import React, { useState, useCallback, useMemo } from 'react';

// ============================================================================
// 타입 정의
// ============================================================================

export interface ChangesSummary {
  /** 추가된 필드 경로 목록 */
  added: string[];
  /** 삭제된 필드 경로 목록 */
  removed: string[];
  /** 수정된 필드 경로 목록 */
  modified: string[];
  /** 추가된 필드 수 */
  added_count?: number;
  /** 삭제된 필드 수 */
  removed_count?: number;
  /** 수정된 필드 수 */
  modified_count?: number;
  /** 문자 수 차이 */
  char_diff?: number;
}

export interface LayoutVersion {
  /** 버전 ID */
  id: number;
  /** 레이아웃 ID */
  layout_id: number;
  /** 버전 번호 (auto-increment) */
  version: number;
  /** 레이아웃 컨텐츠 (JSON) */
  content?: Record<string, unknown>;
  /** 변경사항 요약 */
  changes_summary?: ChangesSummary;
  /** 생성 일시 */
  created_at: string;
}

export interface VersionManagerProps {
  /** 템플릿 이름 */
  templateName: string;
  /** 레이아웃 이름 */
  layoutName: string;
  /** 버전 목록 */
  versions: LayoutVersion[];
  /** 로딩 상태 */
  isLoading?: boolean;
  /** 에러 메시지 */
  error?: string | null;
  /** 버전 목록 새로고침 */
  onRefresh?: () => void;
  /** 버전 복원 */
  onRestore?: (versionId: number) => Promise<void>;
  /** 버전 선택 (상세 보기) */
  onSelectVersion?: (version: LayoutVersion) => void;
  /** 현재 버전 ID (비교용) */
  currentVersionId?: number;
}

// ============================================================================
// 유틸리티 함수
// ============================================================================

/**
 * 날짜를 상대 시간으로 포맷
 */
function formatRelativeTime(dateString: string): string {
  const date = new Date(dateString);
  const now = new Date();
  const diffMs = now.getTime() - date.getTime();
  const diffSec = Math.floor(diffMs / 1000);
  const diffMin = Math.floor(diffSec / 60);
  const diffHour = Math.floor(diffMin / 60);
  const diffDay = Math.floor(diffHour / 24);

  if (diffSec < 60) return '방금 전';
  if (diffMin < 60) return `${diffMin}분 전`;
  if (diffHour < 24) return `${diffHour}시간 전`;
  if (diffDay < 7) return `${diffDay}일 전`;

  return date.toLocaleDateString('ko-KR', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
}

/**
 * 날짜를 전체 포맷으로 표시
 */
function formatFullDateTime(dateString: string): string {
  const date = new Date(dateString);
  return date.toLocaleString('ko-KR', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  });
}

// ============================================================================
// 변경사항 배지 컴포넌트
// ============================================================================

interface ChangesBadgeProps {
  summary: ChangesSummary;
}

function ChangesBadge({ summary }: ChangesBadgeProps) {
  const addedCount = summary.added_count ?? summary.added?.length ?? 0;
  const removedCount = summary.removed_count ?? summary.removed?.length ?? 0;
  const modifiedCount = summary.modified_count ?? summary.modified?.length ?? 0;

  if (addedCount === 0 && removedCount === 0 && modifiedCount === 0) {
    return null;
  }

  return (
    <div className="flex items-center gap-1 text-xs">
      {addedCount > 0 && (
        <span className="px-1.5 py-0.5 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 rounded">
          +{addedCount}
        </span>
      )}
      {removedCount > 0 && (
        <span className="px-1.5 py-0.5 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 rounded">
          -{removedCount}
        </span>
      )}
      {modifiedCount > 0 && (
        <span className="px-1.5 py-0.5 bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400 rounded">
          ~{modifiedCount}
        </span>
      )}
    </div>
  );
}

// ============================================================================
// 변경사항 상세 컴포넌트
// ============================================================================

interface ChangesDetailProps {
  summary: ChangesSummary;
}

function ChangesDetail({ summary }: ChangesDetailProps) {
  const hasChanges =
    summary.added?.length > 0 ||
    summary.removed?.length > 0 ||
    summary.modified?.length > 0;

  if (!hasChanges) {
    return (
      <p className="text-xs text-gray-500 dark:text-gray-400 italic">
        변경사항 정보가 없습니다.
      </p>
    );
  }

  return (
    <div className="space-y-2 text-xs">
      {/* 추가된 항목 */}
      {summary.added?.length > 0 && (
        <div>
          <p className="font-medium text-green-700 dark:text-green-400 mb-1">
            추가됨 ({summary.added.length})
          </p>
          <ul className="list-disc list-inside text-gray-600 dark:text-gray-400 max-h-20 overflow-auto">
            {summary.added.slice(0, 10).map((path, i) => (
              <li key={i} className="truncate" title={path}>
                {path}
              </li>
            ))}
            {summary.added.length > 10 && (
              <li className="text-gray-400">...외 {summary.added.length - 10}개</li>
            )}
          </ul>
        </div>
      )}

      {/* 삭제된 항목 */}
      {summary.removed?.length > 0 && (
        <div>
          <p className="font-medium text-red-700 dark:text-red-400 mb-1">
            삭제됨 ({summary.removed.length})
          </p>
          <ul className="list-disc list-inside text-gray-600 dark:text-gray-400 max-h-20 overflow-auto">
            {summary.removed.slice(0, 10).map((path, i) => (
              <li key={i} className="truncate" title={path}>
                {path}
              </li>
            ))}
            {summary.removed.length > 10 && (
              <li className="text-gray-400">...외 {summary.removed.length - 10}개</li>
            )}
          </ul>
        </div>
      )}

      {/* 수정된 항목 */}
      {summary.modified?.length > 0 && (
        <div>
          <p className="font-medium text-yellow-700 dark:text-yellow-400 mb-1">
            수정됨 ({summary.modified.length})
          </p>
          <ul className="list-disc list-inside text-gray-600 dark:text-gray-400 max-h-20 overflow-auto">
            {summary.modified.slice(0, 10).map((path, i) => (
              <li key={i} className="truncate" title={path}>
                {path}
              </li>
            ))}
            {summary.modified.length > 10 && (
              <li className="text-gray-400">...외 {summary.modified.length - 10}개</li>
            )}
          </ul>
        </div>
      )}

      {/* 문자 수 변화 */}
      {summary.char_diff !== undefined && summary.char_diff !== 0 && (
        <p className="text-gray-500 dark:text-gray-400">
          문자 수 변화: {summary.char_diff > 0 ? '+' : ''}{summary.char_diff}
        </p>
      )}
    </div>
  );
}

// ============================================================================
// 버전 아이템 컴포넌트
// ============================================================================

interface VersionItemProps {
  version: LayoutVersion;
  isOpen: boolean;
  isCurrentVersion?: boolean;
  isRestoring?: boolean;
  onToggle: () => void;
  onRestore?: () => void;
  onSelect?: () => void;
}

function VersionItem({
  version,
  isOpen,
  isCurrentVersion,
  isRestoring,
  onToggle,
  onRestore,
  onSelect,
}: VersionItemProps) {
  return (
    <div
      className={`border rounded overflow-hidden ${
        isCurrentVersion
          ? 'border-blue-300 dark:border-blue-600 bg-blue-50 dark:bg-blue-900/20'
          : 'border-gray-200 dark:border-gray-700'
      }`}
    >
      {/* 헤더 */}
      <div
        className={`flex items-center justify-between px-3 py-2 cursor-pointer transition-colors ${
          isCurrentVersion
            ? 'bg-blue-100 dark:bg-blue-900/30 hover:bg-blue-150 dark:hover:bg-blue-900/40'
            : 'bg-gray-50 dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-750'
        }`}
        onClick={onToggle}
      >
        <div className="flex items-center gap-2">
          <span className="text-gray-500 dark:text-gray-400">📋</span>
          <span className="text-sm font-medium text-gray-900 dark:text-white">
            버전 {version.version}
          </span>
          {isCurrentVersion && (
            <span className="px-1.5 py-0.5 text-xs bg-blue-500 text-white rounded">
              현재
            </span>
          )}
          {version.changes_summary && (
            <ChangesBadge summary={version.changes_summary} />
          )}
        </div>

        <div className="flex items-center gap-2">
          <span className="text-xs text-gray-500 dark:text-gray-400">
            {formatRelativeTime(version.created_at)}
          </span>
          <span
            className={`text-gray-400 transition-transform ${
              isOpen ? 'rotate-180' : ''
            }`}
          >
            ▼
          </span>
        </div>
      </div>

      {/* 상세 정보 */}
      {isOpen && (
        <div className="p-3 space-y-3 border-t border-gray-200 dark:border-gray-700">
          {/* 생성 일시 */}
          <div className="flex items-center gap-2">
            <span className="text-xs text-gray-500 dark:text-gray-400">생성일:</span>
            <span className="text-xs text-gray-700 dark:text-gray-300">
              {formatFullDateTime(version.created_at)}
            </span>
          </div>

          {/* 버전 ID */}
          <div className="flex items-center gap-2">
            <span className="text-xs text-gray-500 dark:text-gray-400">버전 ID:</span>
            <code className="text-xs bg-gray-100 dark:bg-gray-700 px-1 rounded">
              {version.id}
            </code>
          </div>

          {/* 변경사항 상세 */}
          {version.changes_summary && (
            <div>
              <p className="text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                변경사항:
              </p>
              <ChangesDetail summary={version.changes_summary} />
            </div>
          )}

          {/* 액션 버튼 */}
          <div className="flex items-center gap-2 pt-2 border-t border-gray-200 dark:border-gray-700">
            {onSelect && (
              <button
                onClick={(e) => {
                  e.stopPropagation();
                  onSelect();
                }}
                className="px-2 py-1 text-xs text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded"
              >
                상세 보기
              </button>
            )}
            {onRestore && !isCurrentVersion && (
              <button
                onClick={(e) => {
                  e.stopPropagation();
                  onRestore();
                }}
                disabled={isRestoring}
                className={`px-2 py-1 text-xs rounded ${
                  isRestoring
                    ? 'text-gray-400 cursor-not-allowed'
                    : 'text-orange-600 dark:text-orange-400 hover:bg-orange-50 dark:hover:bg-orange-900/20'
                }`}
              >
                {isRestoring ? '복원 중...' : '이 버전으로 복원'}
              </button>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

// ============================================================================
// 복원 확인 모달
// ============================================================================

interface RestoreConfirmModalProps {
  version: LayoutVersion | null;
  isOpen: boolean;
  isRestoring: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}

function RestoreConfirmModal({
  version,
  isOpen,
  isRestoring,
  onConfirm,
  onCancel,
}: RestoreConfirmModalProps) {
  if (!isOpen || !version) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full mx-4 p-4">
        <h3 className="text-lg font-semibold text-gray-900 dark:text-white mb-2">
          버전 복원 확인
        </h3>
        <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">
          버전 {version.version}으로 복원하시겠습니까?
          <br />
          <span className="text-orange-600 dark:text-orange-400">
            현재 레이아웃이 이 버전의 내용으로 교체됩니다.
          </span>
        </p>

        {version.changes_summary && (
          <div className="mb-4 p-2 bg-gray-50 dark:bg-gray-900 rounded text-xs">
            <p className="font-medium text-gray-700 dark:text-gray-300 mb-1">
              이 버전의 변경사항:
            </p>
            <ChangesBadge summary={version.changes_summary} />
          </div>
        )}

        <div className="flex justify-end gap-2">
          <button
            onClick={onCancel}
            disabled={isRestoring}
            className="px-3 py-1.5 text-sm text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 rounded"
          >
            취소
          </button>
          <button
            onClick={onConfirm}
            disabled={isRestoring}
            className={`px-3 py-1.5 text-sm rounded ${
              isRestoring
                ? 'bg-gray-300 text-gray-500 cursor-not-allowed'
                : 'bg-orange-500 text-white hover:bg-orange-600'
            }`}
          >
            {isRestoring ? '복원 중...' : '복원'}
          </button>
        </div>
      </div>
    </div>
  );
}

// ============================================================================
// 메인 컴포넌트
// ============================================================================

export function VersionManager({
  templateName,
  layoutName,
  versions,
  isLoading = false,
  error = null,
  onRefresh,
  onRestore,
  onSelectVersion,
  currentVersionId,
}: VersionManagerProps) {
  const [openVersionId, setOpenVersionId] = useState<number | null>(null);
  const [restoreTarget, setRestoreTarget] = useState<LayoutVersion | null>(null);
  const [isRestoring, setIsRestoring] = useState(false);

  // 버전 토글
  const handleToggle = useCallback((versionId: number) => {
    setOpenVersionId((prev) => (prev === versionId ? null : versionId));
  }, []);

  // 복원 시작
  const handleRestoreClick = useCallback((version: LayoutVersion) => {
    setRestoreTarget(version);
  }, []);

  // 복원 확인
  const handleRestoreConfirm = useCallback(async () => {
    if (!restoreTarget || !onRestore) return;

    setIsRestoring(true);
    try {
      await onRestore(restoreTarget.id);
      setRestoreTarget(null);
    } catch (err) {
      console.error('버전 복원 실패:', err);
    } finally {
      setIsRestoring(false);
    }
  }, [restoreTarget, onRestore]);

  // 복원 취소
  const handleRestoreCancel = useCallback(() => {
    setRestoreTarget(null);
  }, []);

  // 통계 계산
  const stats = useMemo(() => {
    return {
      total: versions.length,
      hasVersions: versions.length > 0,
    };
  }, [versions]);

  return (
    <div className="p-4 space-y-4">
      {/* 헤더 */}
      <div className="flex items-center justify-between">
        <div>
          <h4 className="text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase">
            버전 관리
          </h4>
          <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
            레이아웃의 저장된 버전을 관리합니다.
          </p>
        </div>
        <div className="flex items-center gap-2">
          {stats.hasVersions && (
            <span className="text-xs text-gray-500 dark:text-gray-400">
              {stats.total}개 버전
            </span>
          )}
          {onRefresh && (
            <button
              onClick={onRefresh}
              disabled={isLoading}
              className="p-1 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
              title="새로고침"
            >
              🔄
            </button>
          )}
        </div>
      </div>

      {/* 레이아웃 정보 */}
      <div className="p-2 bg-gray-50 dark:bg-gray-800 rounded text-xs">
        <div className="flex items-center gap-2">
          <span className="text-gray-500 dark:text-gray-400">템플릿:</span>
          <code className="bg-gray-100 dark:bg-gray-700 px-1 rounded">
            {templateName}
          </code>
        </div>
        <div className="flex items-center gap-2 mt-1">
          <span className="text-gray-500 dark:text-gray-400">레이아웃:</span>
          <code className="bg-gray-100 dark:bg-gray-700 px-1 rounded">
            {layoutName}
          </code>
        </div>
      </div>

      {/* 로딩 상태 */}
      {isLoading && (
        <div className="text-center py-8">
          <span className="text-gray-500 dark:text-gray-400">로딩 중...</span>
        </div>
      )}

      {/* 에러 상태 */}
      {error && (
        <div className="p-3 bg-red-50 dark:bg-red-900/20 rounded text-sm text-red-600 dark:text-red-400">
          {error}
        </div>
      )}

      {/* 버전 목록 */}
      {!isLoading && !error && (
        <>
          {stats.hasVersions ? (
            <div className="space-y-2">
              {versions.map((version) => (
                <VersionItem
                  key={version.id}
                  version={version}
                  isOpen={openVersionId === version.id}
                  isCurrentVersion={currentVersionId === version.id}
                  isRestoring={isRestoring && restoreTarget?.id === version.id}
                  onToggle={() => handleToggle(version.id)}
                  onRestore={onRestore ? () => handleRestoreClick(version) : undefined}
                  onSelect={
                    onSelectVersion ? () => onSelectVersion(version) : undefined
                  }
                />
              ))}
            </div>
          ) : (
            <div className="text-center py-8 text-sm text-gray-500 dark:text-gray-400">
              <p>저장된 버전이 없습니다.</p>
              <p className="text-xs mt-1">
                레이아웃을 수정하면 자동으로 버전이 생성됩니다.
              </p>
            </div>
          )}
        </>
      )}

      {/* 도움말 */}
      <div className="p-3 bg-blue-50 dark:bg-blue-900/20 rounded text-xs text-blue-700 dark:text-blue-300">
        <p className="font-medium mb-2">버전 관리 안내:</p>
        <ul className="list-disc list-inside space-y-1 text-blue-600 dark:text-blue-400">
          <li>레이아웃 저장 시 자동으로 버전이 생성됩니다</li>
          <li>이전 버전으로 복원하면 현재 내용이 새 버전으로 저장됩니다</li>
          <li>User 템플릿에서만 버전 관리가 활성화됩니다</li>
          <li>변경사항은 JSON diff로 추적됩니다</li>
        </ul>
      </div>

      {/* 복원 확인 모달 */}
      <RestoreConfirmModal
        version={restoreTarget}
        isOpen={restoreTarget !== null}
        isRestoring={isRestoring}
        onConfirm={handleRestoreConfirm}
        onCancel={handleRestoreCancel}
      />
    </div>
  );
}

export default VersionManager;

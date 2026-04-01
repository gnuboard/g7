import React, { useState } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { VersionList, VersionItem } from './VersionList';

export interface LayoutHistoryPanelProps {
  /** 레이아웃 ID */
  layoutId: string | number;
  /** 버전 목록 */
  versions: VersionItem[];
  /** 버전 복원 콜백 */
  onRestore: (versionId: string | number) => void;
}

/**
 * 레이아웃 히스토리 패널 컴포넌트
 * 버전 목록을 표시하고 선택된 버전을 복원하는 기능을 제공합니다.
 */
export const LayoutHistoryPanel: React.FC<LayoutHistoryPanelProps> = ({
  layoutId: _layoutId,
  versions,
  onRestore,
}) => {
  const [selectedVersionId, setSelectedVersionId] = useState<string | number | undefined>();

  const handleVersionSelect = (versionId: string | number): void => {
    setSelectedVersionId(versionId);
  };

  const handleRestore = (): void => {
    if (selectedVersionId !== undefined) {
      onRestore(selectedVersionId);
    }
  };

  const isRestoreDisabled = selectedVersionId === undefined;

  return (
    <Div
      className="space-y-4"
      role="region"
      aria-label="레이아웃 히스토리 패널"
    >
      {/* 버전 목록 */}
      <Div className="border border-gray-300 dark:border-gray-600 rounded-lg p-4">
        <VersionList
          versions={versions}
          selectedId={selectedVersionId}
          onSelect={handleVersionSelect}
        />
      </Div>

      {/* 복원 버튼 */}
      <Div className="flex justify-end">
        <Button
          variant="primary"
          disabled={isRestoreDisabled}
          onClick={handleRestore}
          aria-label="선택한 버전으로 복원"
        >
          복원
        </Button>
      </Div>
    </Div>
  );
};

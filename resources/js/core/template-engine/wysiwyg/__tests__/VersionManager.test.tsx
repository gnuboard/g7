/**
 * VersionManager.test.tsx
 *
 * VersionManager 컴포넌트 테스트
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import {
  VersionManager,
  LayoutVersion,
  ChangesSummary,
} from '../components/PropertyPanel/VersionManager';

// ============================================================================
// 테스트 헬퍼
// ============================================================================

function createTestChanges(options?: {
  added?: string[];
  removed?: string[];
  modified?: string[];
  charDiff?: number;
}): ChangesSummary {
  return {
    added: options?.added ?? [],
    removed: options?.removed ?? [],
    modified: options?.modified ?? [],
    added_count: options?.added?.length ?? 0,
    removed_count: options?.removed?.length ?? 0,
    modified_count: options?.modified?.length ?? 0,
    char_diff: options?.charDiff ?? 0,
  };
}

function createTestVersion(
  id: number,
  version: number,
  options?: {
    changes?: ChangesSummary;
    createdAt?: string;
  }
): LayoutVersion {
  return {
    id,
    layout_id: 1,
    version,
    created_at: options?.createdAt ?? new Date().toISOString(),
    changes_summary: options?.changes,
  };
}

function createTestVersions(): LayoutVersion[] {
  const now = new Date();
  return [
    createTestVersion(5, 5, {
      createdAt: now.toISOString(),
      changes: createTestChanges({
        modified: ['components.0.props.title'],
      }),
    }),
    createTestVersion(4, 4, {
      createdAt: new Date(now.getTime() - 3600000).toISOString(), // 1시간 전
      changes: createTestChanges({
        added: ['components.5', 'components.5.props'],
        modified: ['data_sources.0.endpoint'],
      }),
    }),
    createTestVersion(3, 3, {
      createdAt: new Date(now.getTime() - 86400000).toISOString(), // 1일 전
      changes: createTestChanges({
        removed: ['components.2'],
        charDiff: -150,
      }),
    }),
    createTestVersion(2, 2, {
      createdAt: new Date(now.getTime() - 172800000).toISOString(), // 2일 전
    }),
    createTestVersion(1, 1, {
      createdAt: new Date(now.getTime() - 604800000).toISOString(), // 7일 전
    }),
  ];
}

// ============================================================================
// 테스트
// ============================================================================

describe('VersionManager', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe('기본 렌더링', () => {
    it('헤더가 표시되어야 함', () => {
      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={[]}
        />
      );

      expect(screen.getByText('버전 관리')).toBeInTheDocument();
    });

    it('템플릿/레이아웃 정보가 표시되어야 함', () => {
      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={[]}
        />
      );

      expect(screen.getByText('sirsoft-basic')).toBeInTheDocument();
      expect(screen.getByText('home')).toBeInTheDocument();
    });

    it('도움말이 표시되어야 함', () => {
      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={[]}
        />
      );

      expect(screen.getByText('버전 관리 안내:')).toBeInTheDocument();
    });

    it('버전이 없으면 안내 메시지가 표시되어야 함', () => {
      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={[]}
        />
      );

      expect(screen.getByText('저장된 버전이 없습니다.')).toBeInTheDocument();
    });
  });

  describe('버전 목록 표시', () => {
    it('버전 목록이 표시되어야 함', () => {
      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={createTestVersions()}
        />
      );

      expect(screen.getByText('버전 5')).toBeInTheDocument();
      expect(screen.getByText('버전 4')).toBeInTheDocument();
      expect(screen.getByText('버전 3')).toBeInTheDocument();
    });

    it('버전 개수가 표시되어야 함', () => {
      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={createTestVersions()}
        />
      );

      expect(screen.getByText('5개 버전')).toBeInTheDocument();
    });

    it('현재 버전에 배지가 표시되어야 함', () => {
      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={createTestVersions()}
          currentVersionId={5}
        />
      );

      expect(screen.getByText('현재')).toBeInTheDocument();
    });

    it('상대 시간이 표시되어야 함', () => {
      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={createTestVersions()}
        />
      );

      // 최근 버전은 "방금 전" 또는 "X분 전" 형태
      const timeTexts = screen.getAllByText(/전$/);
      expect(timeTexts.length).toBeGreaterThanOrEqual(1);
    });
  });

  describe('변경사항 배지', () => {
    it('추가 배지가 표시되어야 함', () => {
      const versions = [
        createTestVersion(1, 1, {
          changes: createTestChanges({
            added: ['components.5', 'components.6'],
          }),
        }),
      ];

      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={versions}
        />
      );

      expect(screen.getByText('+2')).toBeInTheDocument();
    });

    it('삭제 배지가 표시되어야 함', () => {
      const versions = [
        createTestVersion(1, 1, {
          changes: createTestChanges({
            removed: ['components.2'],
          }),
        }),
      ];

      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={versions}
        />
      );

      expect(screen.getByText('-1')).toBeInTheDocument();
    });

    it('수정 배지가 표시되어야 함', () => {
      const versions = [
        createTestVersion(1, 1, {
          changes: createTestChanges({
            modified: ['components.0.props.title', 'data_sources.0.endpoint'],
          }),
        }),
      ];

      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={versions}
        />
      );

      expect(screen.getByText('~2')).toBeInTheDocument();
    });
  });

  describe('버전 상세 보기', () => {
    it('버전 클릭 시 상세 정보가 펼쳐져야 함', () => {
      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={createTestVersions()}
        />
      );

      fireEvent.click(screen.getByText('버전 5'));

      expect(screen.getByText('생성일:')).toBeInTheDocument();
      expect(screen.getByText('버전 ID:')).toBeInTheDocument();
    });

    it('변경사항 상세가 표시되어야 함', () => {
      const versions = [
        createTestVersion(1, 1, {
          changes: createTestChanges({
            added: ['components.5'],
            removed: ['components.2'],
            modified: ['components.0.props.title'],
          }),
        }),
      ];

      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={versions}
        />
      );

      fireEvent.click(screen.getByText('버전 1'));

      expect(screen.getByText('변경사항:')).toBeInTheDocument();
      expect(screen.getByText('추가됨 (1)')).toBeInTheDocument();
      expect(screen.getByText('삭제됨 (1)')).toBeInTheDocument();
      expect(screen.getByText('수정됨 (1)')).toBeInTheDocument();
    });

    it('다시 클릭하면 상세 정보가 접혀야 함', () => {
      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={createTestVersions()}
        />
      );

      const versionHeader = screen.getByText('버전 5');
      fireEvent.click(versionHeader);
      expect(screen.getByText('생성일:')).toBeInTheDocument();

      fireEvent.click(versionHeader);
      expect(screen.queryByText('생성일:')).not.toBeInTheDocument();
    });
  });

  describe('버전 복원', () => {
    it('복원 버튼이 표시되어야 함', () => {
      const onRestore = vi.fn();

      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={createTestVersions()}
          onRestore={onRestore}
        />
      );

      fireEvent.click(screen.getByText('버전 4'));

      expect(screen.getByText('이 버전으로 복원')).toBeInTheDocument();
    });

    it('현재 버전에는 복원 버튼이 없어야 함', () => {
      const onRestore = vi.fn();

      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={createTestVersions()}
          currentVersionId={5}
          onRestore={onRestore}
        />
      );

      fireEvent.click(screen.getByText('버전 5'));

      expect(screen.queryByText('이 버전으로 복원')).not.toBeInTheDocument();
    });

    it('복원 버튼 클릭 시 확인 모달이 표시되어야 함', () => {
      const onRestore = vi.fn();

      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={createTestVersions()}
          onRestore={onRestore}
        />
      );

      fireEvent.click(screen.getByText('버전 4'));
      fireEvent.click(screen.getByText('이 버전으로 복원'));

      expect(screen.getByText('버전 복원 확인')).toBeInTheDocument();
      expect(screen.getByText(/버전 4으로 복원하시겠습니까/)).toBeInTheDocument();
    });

    it('모달에서 취소 클릭 시 모달이 닫혀야 함', () => {
      const onRestore = vi.fn();

      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={createTestVersions()}
          onRestore={onRestore}
        />
      );

      fireEvent.click(screen.getByText('버전 4'));
      fireEvent.click(screen.getByText('이 버전으로 복원'));
      fireEvent.click(screen.getByText('취소'));

      expect(screen.queryByText('버전 복원 확인')).not.toBeInTheDocument();
    });

    it('모달에서 복원 확인 시 onRestore가 호출되어야 함', async () => {
      const onRestore = vi.fn().mockResolvedValue(undefined);

      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={createTestVersions()}
          onRestore={onRestore}
        />
      );

      fireEvent.click(screen.getByText('버전 4'));
      fireEvent.click(screen.getByText('이 버전으로 복원'));

      // 모달의 복원 버튼 클릭
      const modalRestoreButton = screen.getByRole('button', { name: '복원' });
      fireEvent.click(modalRestoreButton);

      await waitFor(() => {
        expect(onRestore).toHaveBeenCalledWith(4);
      });
    });
  });

  describe('상세 보기 버튼', () => {
    it('상세 보기 버튼이 표시되어야 함', () => {
      const onSelectVersion = vi.fn();

      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={createTestVersions()}
          onSelectVersion={onSelectVersion}
        />
      );

      fireEvent.click(screen.getByText('버전 5'));

      expect(screen.getByText('상세 보기')).toBeInTheDocument();
    });

    it('상세 보기 클릭 시 onSelectVersion이 호출되어야 함', () => {
      const onSelectVersion = vi.fn();

      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={createTestVersions()}
          onSelectVersion={onSelectVersion}
        />
      );

      fireEvent.click(screen.getByText('버전 5'));
      fireEvent.click(screen.getByText('상세 보기'));

      expect(onSelectVersion).toHaveBeenCalledWith(
        expect.objectContaining({ id: 5, version: 5 })
      );
    });
  });

  describe('새로고침', () => {
    it('새로고침 버튼이 표시되어야 함', () => {
      const onRefresh = vi.fn();

      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={createTestVersions()}
          onRefresh={onRefresh}
        />
      );

      expect(screen.getByTitle('새로고침')).toBeInTheDocument();
    });

    it('새로고침 클릭 시 onRefresh가 호출되어야 함', () => {
      const onRefresh = vi.fn();

      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={createTestVersions()}
          onRefresh={onRefresh}
        />
      );

      fireEvent.click(screen.getByTitle('새로고침'));

      expect(onRefresh).toHaveBeenCalled();
    });
  });

  describe('로딩 상태', () => {
    it('로딩 중일 때 로딩 메시지가 표시되어야 함', () => {
      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={[]}
          isLoading={true}
        />
      );

      expect(screen.getByText('로딩 중...')).toBeInTheDocument();
    });

    it('로딩 중일 때 버전 목록이 표시되지 않아야 함', () => {
      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={createTestVersions()}
          isLoading={true}
        />
      );

      expect(screen.queryByText('버전 5')).not.toBeInTheDocument();
    });
  });

  describe('에러 상태', () => {
    it('에러가 있으면 에러 메시지가 표시되어야 함', () => {
      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={[]}
          error="버전 목록을 불러오는데 실패했습니다."
        />
      );

      expect(
        screen.getByText('버전 목록을 불러오는데 실패했습니다.')
      ).toBeInTheDocument();
    });

    it('에러가 있으면 버전 목록이 표시되지 않아야 함', () => {
      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={createTestVersions()}
          error="에러 발생"
        />
      );

      expect(screen.queryByText('버전 5')).not.toBeInTheDocument();
    });
  });

  describe('문자 수 변화 표시', () => {
    it('양수 문자 수 변화가 표시되어야 함', () => {
      const versions = [
        createTestVersion(1, 1, {
          changes: createTestChanges({
            added: ['components.5'], // hasChanges가 true가 되도록
            charDiff: 250,
          }),
        }),
      ];

      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={versions}
        />
      );

      fireEvent.click(screen.getByText('버전 1'));

      expect(screen.getByText('문자 수 변화: +250')).toBeInTheDocument();
    });

    it('음수 문자 수 변화가 표시되어야 함', () => {
      const versions = [
        createTestVersion(1, 1, {
          changes: createTestChanges({
            removed: ['components.2'], // hasChanges가 true가 되도록
            charDiff: -100,
          }),
        }),
      ];

      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={versions}
        />
      );

      fireEvent.click(screen.getByText('버전 1'));

      expect(screen.getByText('문자 수 변화: -100')).toBeInTheDocument();
    });
  });

  describe('많은 변경사항 처리', () => {
    it('10개 초과 변경사항은 생략되어야 함', () => {
      const manyAdded = Array.from({ length: 15 }, (_, i) => `components.${i}`);
      const versions = [
        createTestVersion(1, 1, {
          changes: {
            added: manyAdded,
            removed: [],
            modified: [],
            added_count: 15,
            removed_count: 0,
            modified_count: 0,
          },
        }),
      ];

      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={versions}
        />
      );

      fireEvent.click(screen.getByText('버전 1'));

      expect(screen.getByText('추가됨 (15)')).toBeInTheDocument();
      expect(screen.getByText(/외 5개/)).toBeInTheDocument();
    });
  });

  describe('변경사항 없는 버전', () => {
    it('변경사항 정보가 없으면 안내 메시지가 표시되어야 함', () => {
      const versions = [
        createTestVersion(1, 1, {
          changes: createTestChanges(), // 빈 변경사항
        }),
      ];

      render(
        <VersionManager
          templateName="sirsoft-basic"
          layoutName="home"
          versions={versions}
        />
      );

      fireEvent.click(screen.getByText('버전 1'));

      expect(screen.getByText('변경사항 정보가 없습니다.')).toBeInTheDocument();
    });
  });
});

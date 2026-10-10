import { default as React } from 'react';
import { EditorAttrs } from '../../types';
/** 트리 행이 표현하는 파일 1건 — 백엔드 응답 스키마는 바꾸지 않고 레이아웃이 이 모양으로 옮겨 준다. */
export interface FileTreeNode {
    /** 노드 키 — `layout:<name>` 또는 `ext:<id>` */
    key: string;
    kind: 'layout' | 'extension';
    /** 경로 모양 이름 (`partials/admin_user_list/_modal_form`) */
    path: string;
    /** 표시 이름 — 없으면 `path` 의 마지막 세그먼트 */
    label?: string;
    /** 확장 조각의 묶음 라벨 (모듈·플러그인 이름) */
    group?: string;
    /** 확장 조각 묶음의 종류 — 아이콘이 갈린다 */
    groupKind?: 'module' | 'plugin';
    badges?: Array<{
        text: string;
        tone?: 'amber' | 'indigo' | 'red' | 'gray';
    }>;
    /** 보조 정보 — 크기·수정일 */
    meta?: {
        size?: string;
        updatedAt?: string;
        disabled?: boolean;
    };
}
/** 한 파일에서 찾은 내용 매치. */
export interface FileTreeContentMatch {
    key: string;
    count?: number;
    lines?: Array<{
        line: number;
        column?: number;
        preview?: string;
    }>;
}
/** 파일을 열 때 함께 넘기는 좌표. */
export interface FileTreeReveal {
    line: number;
    column?: number;
}
export interface FileTreeProps {
    nodes?: FileTreeNode[];
    /** 지금 열려 있는 파일의 키 */
    selectedKey?: string;
    /** 미저장 파일 키 — ● 점 + 강조 + 상위 폴더 개수 배지 */
    dirtyKeys?: string[];
    /** 제어형 펼침 경로. 주지 않으면 내부 상태 + localStorage 로 관리한다 */
    expandedPaths?: string[];
    /** 파일명 필터·강조에 쓸 검색어 */
    searchKeyword?: string;
    /** 서버가 준 내용 검색 결과 */
    contentMatches?: FileTreeContentMatch[];
    /** 아직 파일이 없는 가상 폴더 (PO 결정 D-2) */
    pendingFolders?: string[];
    /** 행 높이 — 터치 환경은 `touch`(44px) */
    rowSize?: 'compact' | 'touch';
    /** 펼침 상태를 적어 둘 localStorage 키 (비제어일 때만) */
    storageKey?: string;
    /** 파일 조작 버튼을 보일지 (권한 없으면 false) */
    canManage?: boolean;
    /** 목록을 불러오는 중 */
    isLoading?: boolean;
    onSelect?: (key: string, revealAt?: FileTreeReveal) => void;
    onToggle?: (expandedPaths: string[]) => void;
    onCreateFile?: (parentPath: string) => void;
    onRename?: (key: string) => void;
    onDelete?: (key: string) => void;
    /** 검색 결과가 0건일 때 [검색 지우기] */
    onClearSearch?: () => void;
    className?: string;
    id?: string;
    editorAttrs?: EditorAttrs;
}
/**
 * 레이아웃 파일 트리.
 *
 * 관리자 코드 편집기의 좌측 패널이다. 종전에는 144개 파일이 평면 한 줄짜리 목록에 늘어서 있어
 * 원하는 파일을 눈으로 찾는 것이 사실상 불가능했고, `<div>` + click 이라 키보드로는 닿지도 않았다.
 *
 * ## 접근성
 *
 * `role="tree"` 규약을 지키고 `tabindex` 는 **roving** 이다 — 활성 노드 하나만 `0`, 나머지는 `-1`.
 * 144개 파일을 Tab 으로 하나씩 지나가게 두지 않는다. 미저장 표시는 색 하나에 기대지 않고
 * 점 모양·굵기·`aria-label` 셋이 함께 선다(색각 이상·스크린리더 양쪽에 같은 사실이 전달돼야 한다).
 */
export declare const FileTree: React.FC<FileTreeProps>;

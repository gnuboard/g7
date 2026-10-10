import { default as React } from 'react';
import { FileTreeContentMatch, FileTreeNode, FileTreeReveal } from './FileTree';
import { EditorAttrs } from '../../types';
/** 트리를 어떤 그릇에 담을지. */
export type FileTreePickerMode = 'inline' | 'drawer' | 'sheet' | 'auto';
export interface FileTreePickerProps {
    nodes?: FileTreeNode[];
    selectedKey?: string;
    dirtyKeys?: string[];
    searchKeyword?: string;
    contentMatches?: FileTreeContentMatch[];
    pendingFolders?: string[];
    /**
     * `auto` 면 창 폭으로 고른다 — ≥1024 인라인 / 768~1023 서랍 / <768 시트.
     */
    mode?: FileTreePickerMode;
    /** 펼침 상태를 적어 둘 localStorage 키 */
    storageKey?: string;
    /** 파일 조작 가능 여부 (권한) */
    canManage?: boolean;
    isLoading?: boolean;
    /**
     * 내용 검색이 걸린 파일 수 — 0 이면 요약을 보이지 않는다.
     *
     * 문구가 아니라 **수치**를 받는 이유: 엔진의 `$t:key|p={{...}}` 파이프 치환은 속성이 리터럴
     * 템플릿일 때만 동작한다. 레이아웃에서 문자열을 이어 붙여 만들면 `$t:` 가 이미 계산된 값으로
     * 넘어와 파라미터가 `:matches` 원문 그대로 화면에 나온다(브라우저 실측 2026-09-12).
     */
    searchFileCount?: number;
    /** 내용 검색이 찾은 매치 총수 */
    searchMatchCount?: number;
    /**
     * 값이 바뀔 때마다 검색창에 포커스를 준다 — 단축키 «파일 빠른 이동»(mod+k)·«내용 검색»
     * (mod+shift+f)이 쓰는 자리다(계획서 §4.4). 서랍·시트 형태면 먼저 연다(열리면서 포커스된다).
     * 레이아웃은 전역 카운터를 1씩 올려 넘긴다 — 같은 값이 두 번 오면 아무 일도 없다.
     */
    focusSearchNonce?: number;
    onSelect?: (key: string, revealAt?: FileTreeReveal) => void;
    onSearch?: (keyword: string) => void;
    onCreateFile?: (parentPath: string) => void;
    onCreateFolder?: (parentPath: string) => void;
    onRename?: (key: string) => void;
    onDelete?: (key: string) => void;
    className?: string;
    id?: string;
    editorAttrs?: EditorAttrs;
}
/**
 * 파일 트리를 기기 폭에 맞는 그릇에 담습니다.
 *
 * - **인라인**(≥1024) — 좌측에 상시 노출. 폭은 드래그로 240~480px 사이에서 조절하고 기억한다.
 * - **서랍**(768~1023) — 좌측 오버레이. 파일을 고르면 스스로 닫힌다.
 * - **시트**(<768) — 전체 화면. 트리 행이 44px 터치 타깃이 된다.
 *
 * 세 형태가 같은 {@link FileTree} 를 쓰므로 검색·접기·미저장 표시의 동작이 기기별로 갈리지 않는다.
 */
export declare const FileTreePicker: React.FC<FileTreePickerProps>;

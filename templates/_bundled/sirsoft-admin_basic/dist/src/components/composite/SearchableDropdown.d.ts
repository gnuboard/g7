import { default as React } from 'react';
import { EditorAttrs } from '../../types';
export interface SearchableDropdownOption {
    value: string | number;
    label: string;
    description?: string;
    disabled?: boolean;
}
export interface SearchableDropdownProps {
    /** 옵션 목록 */
    options?: SearchableDropdownOption[];
    /** 선택된 값 (단일: string|number, 다중: 배열) */
    value?: string | number | (string | number)[];
    /** 값 변경 핸들러 */
    onChange?: (value: string | number | (string | number)[]) => void;
    /** 다중 선택 모드 활성화 */
    multiple?: boolean;
    /** 검색창 placeholder */
    searchPlaceholder?: string;
    /** 외부 검색 핸들러 (백엔드 연동용) */
    onSearch?: (searchTerm: string) => void;
    /** 결과 없음 메시지 */
    noResultsText?: string;
    /** 초기 안내 문구 */
    initialText?: string;
    /** 트리거 버튼 텍스트 */
    triggerLabel?: string;
    /**
     * 다중 선택에서 둘 이상 골랐을 때의 트리거 문구. `{count}` 가 고른 수로 치환된다.
     *
     * 기본값이 영어인 것은 이 컴포넌트가 코어 번역을 읽지 않기 때문이다 — 화면(레이아웃)이
     * `$t:` 로 넘긴다. 넘기지 않으면 한국어 관리자 화면에 「2 selected」 가 그대로 뜬다
     * (2026-09-21 브라우저 실측: 회원 배송지 목록의 회원 필터).
     */
    selectedCountLabel?: string;
    /** 트리거 버튼 아이콘 */
    triggerIcon?: React.ReactNode;
    /** 비활성화 */
    disabled?: boolean;
    /** 추가 클래스 */
    className?: string;
    /**
     * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
     */
    id?: string;
    /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
    editorAttrs?: EditorAttrs;
}
/**
 * 검색 가능한 드롭다운 컴포넌트
 *
 * 검색어 입력으로 옵션을 필터링하고 선택할 수 있습니다.
 * 단일 선택 및 다중 선택 모드를 지원합니다.
 */
export declare const SearchableDropdown: React.FC<SearchableDropdownProps>;

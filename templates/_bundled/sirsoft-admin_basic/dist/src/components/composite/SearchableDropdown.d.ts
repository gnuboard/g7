import { default as React } from 'react';
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
    /** 트리거 버튼 아이콘 */
    triggerIcon?: React.ReactNode;
    /** 비활성화 */
    disabled?: boolean;
    /** 추가 클래스 */
    className?: string;
}
/**
 * 검색 가능한 드롭다운 컴포넌트
 *
 * 검색어 입력으로 옵션을 필터링하고 선택할 수 있습니다.
 * 단일 선택 및 다중 선택 모드를 지원합니다.
 */
export declare const SearchableDropdown: React.FC<SearchableDropdownProps>;

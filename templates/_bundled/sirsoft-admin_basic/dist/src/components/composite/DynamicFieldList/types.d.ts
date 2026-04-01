import { default as React } from 'react';
/**
 * 컬럼 타입
 */
export type ColumnType = 'input' | 'number' | 'select' | 'textarea' | 'multilingual' | 'custom';
/**
 * 셀렉트 옵션
 */
export interface SelectOption {
    value: string | number;
    label: string;
    disabled?: boolean;
}
/**
 * 컬럼 정의
 */
export interface DynamicFieldColumn {
    /** 컬럼 키 (데이터 필드명) */
    key: string;
    /** 컬럼 라벨 (헤더에 표시) */
    label: string;
    /** 컬럼 타입 */
    type: ColumnType;
    /** 컬럼 너비 (Tailwind 클래스 또는 CSS 값) */
    width?: string;
    /** 플레이스홀더 */
    placeholder?: string;
    /** Select 옵션 (type이 'select'일 때) */
    options?: SelectOption[];
    /** 필수 여부 */
    required?: boolean;
    /** 최소값 (type이 'number'일 때) */
    min?: number;
    /** 최대값 (type이 'number'일 때) */
    max?: number;
    /** 단계 (type이 'number'일 때) */
    step?: number;
    /** 읽기 전용 여부 */
    readOnly?: boolean;
    /** 아이템 필드 기반 조건부 readOnly. "!" prefix로 부정 가능 (예: "!is_custom") */
    readOnlyCondition?: string;
    /** 비활성화 여부 */
    disabled?: boolean;
    /** 커스텀 렌더러 (type이 'custom'일 때) */
    render?: (item: Record<string, unknown>, index: number, onChange: (value: unknown) => void) => React.ReactNode;
    /** 추가 className */
    className?: string;
}
/**
 * 행 액션 정의
 */
export interface RowAction {
    /** 액션 키 */
    key: string;
    /** 액션 라벨 */
    label: string;
    /** 아이콘 이름 */
    icon?: string;
    /** 액션 타입 */
    type?: 'button' | 'dropdown';
    /** 위험한 액션 여부 (빨간색 스타일) */
    danger?: boolean;
    /** 비활성화 조건 */
    disabled?: boolean | ((item: Record<string, unknown>, index: number) => boolean);
    /** 클릭 핸들러 */
    onClick?: (item: Record<string, unknown>, index: number) => void;
    /** 드롭다운 항목 (type이 'dropdown'일 때) */
    items?: RowAction[];
}
/**
 * DynamicFieldList Props
 */
export interface DynamicFieldListProps {
    /** 데이터 항목 배열 */
    items: Record<string, unknown>[];
    /** 컬럼 정의 */
    columns: DynamicFieldColumn[];
    /** 데이터 변경 콜백 */
    onChange?: (items: Record<string, unknown>[]) => void;
    /** 항목 추가 콜백 (외부에서 항목 추가 로직 제어) */
    onAddItem?: () => void;
    /** 항목 제거 콜백 */
    onRemoveItem?: (index: number, item: Record<string, unknown>) => void;
    /** 순서 변경 콜백 */
    onReorder?: (items: Record<string, unknown>[]) => void;
    /** 항목 추가 버튼 라벨 */
    addLabel?: string;
    /** 드래그 앤 드롭 활성화 */
    enableDrag?: boolean;
    /** 순번 표시 */
    showIndex?: boolean;
    /** 최소 항목 수 (이 값 이하로 삭제 불가) */
    minItems?: number;
    /** 최대 항목 수 (이 값 이상 추가 불가) */
    maxItems?: number;
    /** 행 액션 정의 */
    rowActions?: RowAction[];
    /** 빈 상태 메시지 */
    emptyMessage?: string;
    /** 테이블 스타일 className */
    className?: string;
    /** 헤더 className */
    headerClassName?: string;
    /** 행 className */
    rowClassName?: string;
    /** 폼 필드 이름 (템플릿 엔진 통합용) */
    name?: string;
    /** 읽기 전용 모드 */
    readOnly?: boolean;
    /** 새 항목 기본값 팩토리 */
    createDefaultItem?: () => Record<string, unknown>;
    /** 계층 구조 지원 - 자식 키 */
    childrenKey?: string;
    /** 항목별 고유 ID 키 */
    itemIdKey?: string;
    /** 검증 에러 객체 (예: {"fields.0.name.ko": ["에러 메시지"], "fields.1.content.ko": ["에러 메시지"]}) */
    errors?: Record<string, string[]>;
}
/**
 * DynamicFieldListItem Props
 */
export interface DynamicFieldListItemProps {
    /** 아이템 데이터 */
    item: Record<string, unknown>;
    /** 아이템 인덱스 */
    index: number;
    /** 아이템 고유 ID */
    id: string | number;
    /** 컬럼 정의 */
    columns: DynamicFieldColumn[];
    /** 드래그 활성화 여부 */
    enableDrag?: boolean;
    /** 순번 표시 여부 */
    showIndex?: boolean;
    /** 삭제 가능 여부 */
    canRemove?: boolean;
    /** 행 액션 */
    rowActions?: RowAction[];
    /** 읽기 전용 */
    readOnly?: boolean;
    /** 값 변경 핸들러 */
    onValueChange?: (key: string, value: unknown) => void;
    /** 삭제 핸들러 */
    onRemove?: () => void;
    /** 행 className */
    className?: string;
    /** 깊이 레벨 (계층 구조용) */
    level?: number;
    /** 해당 행의 컬럼별 에러 (예: {"name.ko": ["에러 메시지"], "content.ko": ["에러 메시지"]}) */
    errors?: Record<string, string[]>;
}

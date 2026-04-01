/**
 * G7 DevTools 상수 및 타입 정의
 *
 * DevToolsPanel에서 사용되는 타입, 상수, 인터페이스를 정의합니다.
 */

// 탭 타입 정의
export type TabType =
    | 'state'
    | 'actions'
    | 'cache'
    | 'diagnose'
    | 'performance'
    | 'lifecycle'
    | 'network'
    | 'form'
    | 'conditional'
    | 'expression'
    | 'handlers'
    | 'events'
    | 'datasources'
    | 'renders'
    | 'styles'
    | 'auth'
    | 'logs'
    | 'layout'
    | 'changedetection'
    | 'staleclosure'
    | 'nestedcontext'
    | 'modalscope'
    | 'namedactions';

// 패널 위치 타입
export interface PanelPosition {
    x: number;
    y: number;
}

// 패널 크기 타입
export interface PanelSize {
    width: number;
    height: number;
}

// 리사이즈 방향
export type ResizeDirection = 'n' | 's' | 'e' | 'w' | 'ne' | 'nw' | 'se' | 'sw' | null;

// 탭 라벨 매핑
export const TAB_LABELS: Record<TabType, string> = {
    state: '상태',
    actions: '액션',
    cache: '캐시',
    diagnose: '진단',
    performance: '성능',
    lifecycle: '라이프사이클',
    network: '네트워크',
    form: 'Form',
    conditional: '조건부',
    expression: '표현식',
    handlers: '핸들러',
    events: '이벤트',
    datasources: '데이터소스',
    renders: '렌더링',
    styles: '스타일',
    auth: '인증',
    logs: '로그',
    layout: '레이아웃',
    changedetection: '변경감지',
    staleclosure: 'Stale Closure',
    nestedcontext: 'Context',
    modalscope: '모달스코프',
    namedactions: 'Named Actions',
};

// 패널 크기 상수
export const DEFAULT_PANEL_WIDTH = 700;
export const DEFAULT_PANEL_HEIGHT = 500;
export const MIN_PANEL_WIDTH = 400;
export const MIN_PANEL_HEIGHT = 300;

// 로컬스토리지 키
export const STORAGE_KEY = 'g7-devtools-panel';

// 저장되는 설정 타입
export interface StoredPanelConfig {
    position: PanelPosition;
    size: PanelSize;
    isOpen: boolean;
}

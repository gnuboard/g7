/**
 * scrollToSection 핸들러
 *
 * 특정 섹션으로 부드럽게 스크롤 이동합니다.
 * - Sticky 헤더를 고려한 offset 적용 지원
 * - React 조건부 렌더링 대응 (재시도 로직)
 * - 페이지/컨테이너 스크롤 자동 감지
 *
 * @param action 액션 정의 (params: targetId, offset, delay, scrollContainerId)
 * @param _context 액션 컨텍스트 (미사용)
 */
export declare function scrollToSectionHandler(action: any, _context?: any): Promise<void>;

/**
 * scrollToSectionHandler 테스트
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { scrollToSectionHandler } from '../scrollToSectionHandler';

describe('scrollToSectionHandler', () => {
  let scrollToSpy: any;
  let consoleWarnSpy: any;

  beforeEach(() => {
    // DOM 초기화
    document.body.innerHTML = '';

    // window.scrollTo mock
    scrollToSpy = vi.spyOn(window, 'scrollTo').mockImplementation(() => {});

    // console 스파이 설정
    consoleWarnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

    // getBoundingClientRect mock
    Element.prototype.getBoundingClientRect = vi.fn().mockReturnValue({
      top: 500,
      left: 0,
      width: 100,
      height: 100,
      bottom: 600,
      right: 100,
      x: 0,
      y: 500,
      toJSON: () => {},
    });

    // window.pageYOffset mock
    Object.defineProperty(window, 'pageYOffset', {
      writable: true,
      value: 0,
    });
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  describe('정상 동작', () => {
    it('targetId가 지정된 요소로 스크롤해야 함', async () => {
      // Mock DOM element
      const mockElement = document.createElement('div');
      mockElement.id = 'test-section';
      document.body.appendChild(mockElement);

      const action = {
        params: { targetId: 'test-section', offset: 100, delay: 0 },
      };

      await scrollToSectionHandler(action);

      expect(scrollToSpy).toHaveBeenCalled();
      const scrollCall = scrollToSpy.mock.calls[0][0];
      expect(scrollCall).toMatchObject({
        top: 400, // elementPosition (500) + pageYOffset (0) - offset (100)
        behavior: 'smooth',
      });
    });

    it('기본 offset 값 (120px)이 적용되어야 함', async () => {
      const mockElement = document.createElement('div');
      mockElement.id = 'test-section';
      document.body.appendChild(mockElement);

      const action = {
        params: { targetId: 'test-section', delay: 0 },
      };

      await scrollToSectionHandler(action);

      expect(scrollToSpy).toHaveBeenCalled();
      const scrollCall = scrollToSpy.mock.calls[0][0];
      expect(scrollCall).toMatchObject({
        top: 380, // elementPosition (500) + pageYOffset (0) - offset (120)
        behavior: 'smooth',
      });
    });

    it('사용자 정의 offset이 적용되어야 함', async () => {
      const mockElement = document.createElement('div');
      mockElement.id = 'test-section';
      document.body.appendChild(mockElement);

      const action = {
        params: { targetId: 'test-section', offset: 200, delay: 0 },
      };

      await scrollToSectionHandler(action);

      expect(scrollToSpy).toHaveBeenCalled();
      const scrollCall = scrollToSpy.mock.calls[0][0];
      expect(scrollCall).toMatchObject({
        top: 300, // elementPosition (500) + pageYOffset (0) - offset (200)
      });
    });

    it('페이지가 이미 스크롤된 상태에서 정확한 위치를 계산해야 함', async () => {
      const mockElement = document.createElement('div');
      mockElement.id = 'test-section';
      document.body.appendChild(mockElement);

      // 페이지가 이미 300px 스크롤된 상태
      Object.defineProperty(window, 'pageYOffset', {
        writable: true,
        value: 300,
      });

      const action = {
        params: { targetId: 'test-section', offset: 120, delay: 0 },
      };

      await scrollToSectionHandler(action);

      expect(scrollToSpy).toHaveBeenCalled();
      const scrollCall = scrollToSpy.mock.calls[0][0];
      expect(scrollCall).toMatchObject({
        top: 680, // elementPosition (500) + pageYOffset (300) - offset (120)
      });
    });
  });

  describe('예외 처리', () => {
    it('targetId가 없으면 경고를 출력하고 스크롤하지 않아야 함', async () => {
      const action = { params: {} };

      await scrollToSectionHandler(action as any);

      expect(consoleWarnSpy).toHaveBeenCalledWith('[Handler:ScrollToSection]', 'targetId is required');
      expect(scrollToSpy).not.toHaveBeenCalled();
    });

    it('존재하지 않는 targetId는 경고를 출력하고 스크롤하지 않아야 함', async () => {
      const action = {
        params: { targetId: 'non-existent', delay: 0 },
      };

      await scrollToSectionHandler(action);

      expect(consoleWarnSpy).toHaveBeenCalledWith(
        '[Handler:ScrollToSection]',
        'Element not found: non-existent'
      );
      expect(scrollToSpy).not.toHaveBeenCalled();
    });

    it('params가 없어도 오류 없이 처리해야 함', async () => {
      const action = {};

      await expect(scrollToSectionHandler(action as any)).resolves.not.toThrow();
      expect(consoleWarnSpy).toHaveBeenCalledWith('[Handler:ScrollToSection]', 'targetId is required');
      expect(scrollToSpy).not.toHaveBeenCalled();
    });
  });

  describe('delay 처리', () => {
    it('기본 delay (100ms) 후 스크롤해야 함', async () => {
      const mockElement = document.createElement('div');
      mockElement.id = 'test-section';
      document.body.appendChild(mockElement);

      const action = {
        params: { targetId: 'test-section' },
      };

      const startTime = Date.now();
      await scrollToSectionHandler(action);
      const endTime = Date.now();

      // delay가 있으므로 최소 100ms 경과해야 함
      expect(endTime - startTime).toBeGreaterThanOrEqual(90); // 약간의 여유
      expect(scrollToSpy).toHaveBeenCalled();
    });

    it('사용자 정의 delay가 적용되어야 함', async () => {
      const mockElement = document.createElement('div');
      mockElement.id = 'test-section';
      document.body.appendChild(mockElement);

      const action = {
        params: { targetId: 'test-section', delay: 50 },
      };

      const startTime = Date.now();
      await scrollToSectionHandler(action);
      const endTime = Date.now();

      expect(endTime - startTime).toBeGreaterThanOrEqual(40); // 약간의 여유
      expect(endTime - startTime).toBeLessThan(150); // 100ms보다 확실히 짧아야 함
      expect(scrollToSpy).toHaveBeenCalled();
    });
  });

  describe('스크롤 컨테이너 자동 검색', () => {
    it('scrollContainerId가 유효하면 해당 컨테이너를 사용해야 함', async () => {
      const mockElement = document.createElement('div');
      mockElement.id = 'test-section';

      const mockContainer = document.createElement('div');
      mockContainer.id = 'scroll-container';
      mockContainer.appendChild(mockElement);
      document.body.appendChild(mockContainer);

      // scrollHeight > clientHeight 모킹
      Object.defineProperty(mockContainer, 'scrollHeight', {
        configurable: true,
        value: 1000,
      });
      Object.defineProperty(mockContainer, 'clientHeight', {
        configurable: true,
        value: 500,
      });
      Object.defineProperty(mockContainer, 'scrollTop', {
        configurable: true,
        writable: true,
        value: 0,
      });

      // scrollTo 함수 정의 (happy-dom에서 기본으로 없을 수 있음)
      const scrollToMock = vi.fn();
      mockContainer.scrollTo = scrollToMock;

      // window.getComputedStyle 모킹 - 무한 재귀 방지를 위해 간단히 처리
      vi.spyOn(window, 'getComputedStyle').mockImplementation((el: Element) => {
        // scroll-container만 스크롤 가능하도록 설정
        if ((el as HTMLElement).id === 'scroll-container') {
          return { overflowY: 'auto' } as CSSStyleDeclaration;
        }
        // 나머지 요소는 스크롤 불가능
        return { overflowY: 'visible' } as CSSStyleDeclaration;
      });

      const action = {
        params: {
          targetId: 'test-section',
          scrollContainerId: 'scroll-container',
          offset: 100,
          delay: 0,
        },
      };

      await scrollToSectionHandler(action);

      expect(scrollToMock).toHaveBeenCalled();
    });

    it('scrollContainerId가 무효하면 부모 요소를 검색해야 함', async () => {
      const mockElement = document.createElement('div');
      mockElement.id = 'test-section';

      const mockContainer = document.createElement('div');
      mockContainer.id = 'parent-container';
      mockContainer.appendChild(mockElement);
      document.body.appendChild(mockContainer);

      // scrollHeight > clientHeight 모킹
      Object.defineProperty(mockContainer, 'scrollHeight', {
        configurable: true,
        value: 1000,
      });
      Object.defineProperty(mockContainer, 'clientHeight', {
        configurable: true,
        value: 500,
      });
      Object.defineProperty(mockContainer, 'scrollTop', {
        configurable: true,
        writable: true,
        value: 0,
      });

      // scrollTo 함수 정의 (happy-dom에서 기본으로 없을 수 있음)
      const scrollToMock = vi.fn();
      mockContainer.scrollTo = scrollToMock;

      // window.getComputedStyle 모킹 - 무한 재귀 방지를 위해 간단히 처리
      vi.spyOn(window, 'getComputedStyle').mockImplementation((el: Element) => {
        // parent-container만 스크롤 가능하도록 설정
        if ((el as HTMLElement).id === 'parent-container') {
          return { overflowY: 'auto' } as CSSStyleDeclaration;
        }
        // 나머지 요소는 스크롤 불가능
        return { overflowY: 'visible' } as CSSStyleDeclaration;
      });

      const action = {
        params: {
          targetId: 'test-section',
          scrollContainerId: 'non-existent-container',
          offset: 100,
          delay: 0,
        },
      };

      await scrollToSectionHandler(action);

      expect(consoleWarnSpy).toHaveBeenCalledWith(
        '[Handler:ScrollToSection]',
        expect.stringContaining("Container 'non-existent-container' not found")
      );
      expect(scrollToMock).toHaveBeenCalled();
    });

    it('스크롤 가능한 부모 요소가 없으면 window를 사용해야 함', async () => {
      const mockElement = document.createElement('div');
      mockElement.id = 'test-section';
      document.body.appendChild(mockElement);

      const action = {
        params: {
          targetId: 'test-section',
          offset: 100,
          delay: 0,
        },
      };

      await scrollToSectionHandler(action);

      expect(scrollToSpy).toHaveBeenCalled();
    });

    it('부모 요소 검색 시 최대 깊이를 제한해야 함', async () => {
      // 11단계 중첩 구조 생성 (body 제외)
      let currentElement = document.body;
      for (let i = 0; i < 11; i++) {
        const div = document.createElement('div');
        div.id = `level-${i}`;
        currentElement.appendChild(div);
        currentElement = div;
      }

      const mockElement = document.createElement('div');
      mockElement.id = 'test-section';
      currentElement.appendChild(mockElement);

      // 모든 부모 요소가 스크롤 불가능하도록 설정
      // (scrollHeight <= clientHeight)
      // 기본적으로 happy-dom에서는 스크롤이 없음

      const action = {
        params: {
          targetId: 'test-section',
          offset: 100,
          delay: 0,
        },
      };

      await scrollToSectionHandler(action);

      // 스크롤 가능한 컨테이너를 찾지 못했으므로 window.scrollTo가 호출되어야 함
      expect(scrollToSpy).toHaveBeenCalled();
    });
  });

  describe('실제 시나리오', () => {
    it('게시판 폼 탭 클릭 시나리오: basic 탭', async () => {
      const mockElement = document.createElement('div');
      mockElement.id = 'tab_content_basic';
      document.body.appendChild(mockElement);

      const action = {
        params: {
          targetId: 'tab_content_basic',
          offset: 120,
          delay: 0,
        },
      };

      await scrollToSectionHandler(action);

      expect(scrollToSpy).toHaveBeenCalled();
    });

    it('게시판 폼 탭 클릭 시나리오: 모든 탭 ID', async () => {
      const tabIds = [
        'tab_content_basic',
        'tab_content_permissions',
        'tab_content_list',
        'tab_content_post',
        'tab_content_notification',
      ];

      for (const tabId of tabIds) {
        // 새 DOM 초기화
        document.body.innerHTML = '';
        scrollToSpy.mockClear();

        const mockElement = document.createElement('div');
        mockElement.id = tabId;
        document.body.appendChild(mockElement);

        const action = {
          params: {
            targetId: tabId,
            offset: 120,
            delay: 0,
          },
        };

        await scrollToSectionHandler(action);

        expect(scrollToSpy).toHaveBeenCalled();
      }
    });
  });
});

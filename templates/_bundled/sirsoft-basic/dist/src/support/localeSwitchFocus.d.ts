import { RefObject } from 'react';
/**
 * 언어 전환으로 다시 마운트된 뒤 포커스를 같은 단추로 돌린다.
 *
 * @param key 인스턴스를 가르는 키(부품 이름과 id). 같은 화면에서 겹치지 않게 한다
 * @param watchRef 포커스가 있는지 볼 영역(단추 자신이나 단추를 품은 감싸개)
 * @param getTarget 돌려줄 요소를 고른다(기본은 watchRef)
 */
export declare function useLocaleSwitchFocusReturn(key: string, watchRef: RefObject<HTMLElement | null>, getTarget?: () => HTMLElement | null): void;

/**
 * 이 모듈의 Vitest 설정(vitest.config.ts)으로 돌 때 DOM 단언(toHaveClass·toHaveAttribute)을 등록한다.
 *
 * 루트 설정(`vitest.config.ts` 의 setup)으로 돌면 이미 등록되지만, 모듈 디렉토리에서 돌면 빠져
 * 「Invalid Chai property」로 실패했다. 의존성은 이 모듈에 따로 설치하지 않고 루트 것을 쓴다.
 */
import '@testing-library/jest-dom/vitest';

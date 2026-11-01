/**
 * hello 모듈 Vitest 준비 파일
 *
 * jest-dom 매처(toHaveAttribute 등)만 등록한다. 코어 공용 준비 파일(resources/js/tests/setup.ts)은
 * window 를 전제해 node 환경 시험을 깨뜨리므로 쓰지 않는다.
 */

import '@testing-library/jest-dom/vitest';

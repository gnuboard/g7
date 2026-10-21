<?php

/**
 * Playwright E2E 시드 커맨드 다국어 (개발자 전용 — 사용자 노출 0).
 *
 * 본 파일은 PlaywrightSeedEcommerce stub 의 i18n 규정 준수를 위한 형식적 파일이다.
 * 이커머스 모듈 작업 세션에서 stub 본문이 실제 시드 로직으로 교체되면 본 파일도 삭제 가능.
 */
return [
    'seed_stub_unimplemented' => 'PlaywrightSeedEcommerce 는 stub 입니다. 이커머스 모듈 작업 세션에서 실제 시드 로직 구현 필요.',
    // Playwright 시드 명령 콘솔 출력
    'guard' => [
        'cli_only' => 'CLI 전용 커맨드입니다.',
        'bypass_required' => 'G7_PLAYWRIGHT_BYPASS=1 환경변수가 필요합니다.',
    ],
    'seed_claim_detail' => [
        'invalid_tag' => '--tag 는 영숫자 1~16자여야 합니다.',
        'tag_required' => '시드에는 --tag 가 필요합니다 (병렬 워커끼리 서로의 신청서를 지우지 않도록).',
    ],
    'seed_site_day_orders' => [
        'no_product_options' => '상품 옵션이 하나도 없어 주문 옵션을 붙일 수 없습니다.',
    ],
];

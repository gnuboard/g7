<?php

namespace Modules\Sirsoft\Ecommerce\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * Playwright E2E 용 이커머스 도메인 시드 커맨드 (stub).
 *
 * 상품/카테고리/주문/배송지 등 모듈 도메인 데이터를 발급하여 stdout 에 JSON 으로 반환한다.
 * 본 stub 은 시그니처와 보안 가드만 구현되어 있으며, 실제 시드 로직은 이커머스 모듈 작업 세션에서
 * Factory 재사용으로 채워질 예정 (계획서 §확장 sample skeleton 참조).
 *
 * 보안 가드 (코어 PlaywrightIssueToken 과 동일 3중 패턴):
 *   ① CLI 한정 — `php_sapi_name() === 'cli'`
 *   ② G7_PLAYWRIGHT_BYPASS=1 환경변수 옵트인
 *   ③ APP_DEBUG=true inline override — production + debug=false 환경에서도 동작
 *
 * 호출 예시 (활성화 후):
 *   $env:G7_PLAYWRIGHT_BYPASS='1'; php artisan playwright:seed-ecommerce --products=5 --json
 */
class PlaywrightSeedEcommerce extends Command
{
    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'playwright:seed-ecommerce
        {--products=0 : 발급할 상품 수}
        {--categories=0 : 발급할 카테고리 수}
        {--orders=0 : 발급할 주문 수}
        {--json : 결과를 JSON 으로 출력}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = 'Playwright E2E 용 이커머스 도메인 데이터 시드 (CLI + G7_PLAYWRIGHT_BYPASS 가드)';

    /**
     * 커맨드를 실행합니다.
     *
     * 본 stub 은 가드 통과 후 LogicException 을 throw 한다.
     * 이커머스 모듈 작업 세션에서 stub 본문을 실제 시드 로직으로 교체할 때까지 활성화 차단.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        // ① CLI 한정
        if (php_sapi_name() !== 'cli') {
            $this->error('CLI 전용 커맨드입니다.');

            return self::FAILURE;
        }

        // ② 명시 옵트인
        if (env('G7_PLAYWRIGHT_BYPASS') !== '1') {
            $this->error('G7_PLAYWRIGHT_BYPASS=1 환경변수가 필요합니다.');

            return self::FAILURE;
        }

        // ③ APP_DEBUG 강제 — SettingsServiceProvider 의 bypass 분기가 settings JSON 덮어쓰기 차단 상태
        Config::set('app.debug', true);

        // TODO (이커머스 모듈 작업 세션):
        //   - 카테고리 N개 생성 (Factory 재사용)
        //   - 상품 N개 생성 (카테고리 연결)
        //   - 주문 N개 생성 (상품 연결)
        //   - 반환: {productIds: [...], categoryIds: [...], orderIds: [...]}
        // 개발자 전용 stub 메시지 — i18n 대상 외 (이커머스 모듈 작업 세션이 본문 교체 시 본 throw 제거 예정).
        // 한국어 표현이지만 사용자 노출 0 — CLI 출력 + 본문 구현 시 즉시 삭제.
        throw new \LogicException( /* i18n:exempt — developer-only stub */
            __('sirsoft-ecommerce::playwright.seed_stub_unimplemented')
        );
    }
}

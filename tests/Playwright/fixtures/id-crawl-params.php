<?php

/**
 * 전 화면 DOM id 크롤 E2E 의 동적 라우트 파라미터 표본
 *
 * 저장소 루트에서 단독으로 실행한다 — 앱을 직접 부팅한다(`analytics-seed.php` 와 같은 방식).
 *
 *   php tests/Playwright/fixtures/id-crawl-params.php
 *
 * **읽기 전용**이다. SELECT 만 하고 어떤 행도 만들거나 바꾸지 않는다. 결과는 JSON 한 덩어리로
 * 표준 출력에 쓴다. 값을 못 구한 키는 `null` 로 두고 `reasons` 에 사유를 적는다 — spec 은 그 사유를
 * 건너뜀(SKIPPED) 목록에 그대로 옮긴다.
 *
 * 표본 기준:
 * - 게시판 slug·글 id: 사용 중 게시판에서 답글 3개 이상 + 첨부가 있는 원글(답글마다 본문 확장점이 하나씩
 *   늘어나므로 같은 조각 반복 주입을 드러내기 좋다). 없으면 조건을 하나씩 늦춘다.
 * - 상품 코드: 판매중·노출이며 옵션이 있는 상품(옵션 영역이 그려지는 상세)
 * - 주문 번호, 클레임 번호(반품 → 교환 → 취소 순), 회원 uuid(위 글 작성자 우선) 등은 존재하는 첫 행
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Support\ShopPathResolver;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** @var array<string, mixed> $params 키 → 표본 값 */
$params = [];

/** @var array<string, string> $reasons 키 → 값을 못 구한 사유 */
$reasons = [];

/**
 * 표본 하나를 기록합니다. 값이 비면 사유를 남깁니다.
 *
 * @param  array<string, mixed>  $params  표본 모음 (참조)
 * @param  array<string, string>  $reasons  사유 모음 (참조)
 * @param  string  $key  표본 키
 * @param  mixed  $value  조회 값
 * @param  string  $reason  값이 비었을 때 사유
 * @return void 반환값 없음 (두 모음을 참조로 채운다)
 */
function crawl_param(array &$params, array &$reasons, string $key, mixed $value, string $reason): void
{
    if ($value === null || $value === '') {
        $params[$key] = null;
        $reasons[$key] = $reason;

        return;
    }

    $params[$key] = is_numeric($value) ? (string) $value : $value;
}

/**
 * 테이블의 첫 행 한 컬럼을 읽습니다. 테이블이 없으면 null 입니다.
 *
 * @param  string  $table  접두어 없는 테이블 이름
 * @param  string  $column  읽을 컬럼
 * @param  array<string, mixed>  $where  같음 조건
 * @param  bool  $softDeletes  `deleted_at` 이 null 인 행만 볼지
 * @return mixed 값 또는 null
 */
function crawl_first(string $table, string $column, array $where = [], bool $softDeletes = false): mixed
{
    if (! Schema::hasTable($table)) {
        return null;
    }

    $query = DB::table($table)->where($where);
    if ($softDeletes && Schema::hasColumn($table, 'deleted_at')) {
        $query->whereNull('deleted_at');
    }

    return $query->orderBy('id')->value($column);
}

// ── 게시판: 답글 3개 이상 + 첨부가 있는 원글 ─────────────────────────────
$boardPost = null;
if (Schema::hasTable('board_posts') && Schema::hasTable('boards')) {
    $replyCount = DB::table('board_posts as r')
        ->selectRaw('count(*)')
        ->whereColumn('r.parent_id', 'p.id')
        ->whereNull('r.deleted_at')
        ->where('r.status', 'published');

    $base = static fn () => DB::table('board_posts as p')
        ->join('boards as b', 'b.id', '=', 'p.board_id')
        ->where('b.is_active', 1)
        ->whereNull('p.deleted_at')
        ->whereNull('p.parent_id')
        ->where('p.status', 'published')
        ->where('p.is_secret', 0)
        ->select('b.slug', 'p.id', 'p.user_id', 'p.attachments_count')
        ->selectSub($replyCount, 'reply_count');

    foreach ([[3, true], [3, false], [1, false], [0, false]] as [$minReplies, $needAttachment]) {
        $query = $base()->having('reply_count', '>=', $minReplies)->orderByDesc('reply_count')->orderBy('p.id');
        if ($needAttachment) {
            $query->where('p.attachments_count', '>', 0);
        }
        $boardPost = $query->first();
        if ($boardPost !== null) {
            $params['board_sample_rule'] = "replies>={$minReplies}".($needAttachment ? '+attachment' : '');
            break;
        }
    }
}
crawl_param($params, $reasons, 'board_slug', $boardPost->slug ?? null, '사용 중 게시판에 공개 원글이 없음');
crawl_param($params, $reasons, 'board_post_id', $boardPost->id ?? null, '사용 중 게시판에 공개 원글이 없음');
$params['board_post_reply_count'] = isset($boardPost->reply_count) ? (int) $boardPost->reply_count : null;

$reportId = crawl_first('boards_reports', 'id', [], true);
crawl_param($params, $reasons, 'board_report_id', $reportId, '신고 행이 없음');

// ── 회원 ──────────────────────────────────────────────────────────────
$userUuid = null;
if (isset($boardPost->user_id) && $boardPost->user_id !== null) {
    $userUuid = DB::table('users')->where('id', $boardPost->user_id)->value('uuid');
}
$userUuid ??= DB::table('users')->whereNull('withdrawn_at')->orderBy('id')->value('uuid');
crawl_param($params, $reasons, 'user_uuid', $userUuid, '탈퇴하지 않은 회원이 없음');
crawl_param($params, $reasons, 'role_id', crawl_first('roles', 'id'), '역할 행이 없음');

// ── 이커머스 ──────────────────────────────────────────────────────────
$productCode = null;
if (Schema::hasTable('ecommerce_products')) {
    $productCode = DB::table('ecommerce_products')
        ->whereNull('deleted_at')
        ->where('sales_status', 'on_sale')
        ->where('display_status', 'visible')
        ->orderByDesc('has_options')
        ->orderBy('id')
        ->value('product_code');
}
crawl_param($params, $reasons, 'product_code', $productCode, '판매중·노출 상품이 없음');
crawl_param($params, $reasons, 'category_slug', crawl_first('ecommerce_categories', 'slug', ['is_active' => 1]), '사용 중 상품 분류가 없음');
crawl_param($params, $reasons, 'order_number', crawl_first('ecommerce_orders', 'order_number', [], true), '주문 행이 없음');

$claimNumber = crawl_first('ecommerce_order_returns', 'return_number')
    ?? crawl_first('ecommerce_order_exchanges', 'exchange_number')
    ?? crawl_first('ecommerce_order_cancels', 'cancel_number');
crawl_param($params, $reasons, 'claim_number', $claimNumber, '반품·교환·취소 신청 행이 없음');
crawl_param($params, $reasons, 'shipping_policy_id', crawl_first('ecommerce_shipping_policies', 'id'), '배송 정책 행이 없음');
crawl_param($params, $reasons, 'coupon_id', crawl_first('ecommerce_promotion_coupons', 'id', [], true), '쿠폰 행이 없음');

try {
    $shopBase = rtrim(ShopPathResolver::path(''), '/');
    $params['shop_base'] = $shopBase;
} catch (Throwable $e) {
    $params['shop_base'] = null;
    $reasons['shop_base'] = '상점 경로를 해석하지 못함: '.$e->getMessage();
}

// ── 페이지·플러그인·기타 모듈 ──────────────────────────────────────────
crawl_param($params, $reasons, 'page_id', crawl_first('pages', 'id'), '페이지 행이 없음');
crawl_param($params, $reasons, 'page_slug', crawl_first('pages', 'slug', ['published' => 1]), '공개 페이지가 없음');
crawl_param($params, $reasons, 'popup_id', crawl_first('popups', 'id'), '팝업 행이 없음');
crawl_param($params, $reasons, 'hero_banner_id', crawl_first('hero_section_banners', 'id'), '히어로 배너 행이 없음');
crawl_param($params, $reasons, 'memo_id', crawl_first('gnuboard7_hello_module_memos', 'id'), '메모 행이 없음');
crawl_param($params, $reasons, 'visitor_key', crawl_first('analytics_visits', 'visitor_key'), '방문 기록이 없음');
crawl_param($params, $reasons, 'form_uuid', crawl_first('form_forms', 'uuid'), '폼 빌더 폼 행이 없음');
crawl_param($params, $reasons, 'form_slug', crawl_first('form_forms', 'slug', ['status' => 'published']), '공개된 폼 빌더 폼이 없음');
crawl_param($params, $reasons, 'form_submission_uuid', crawl_first('form_submissions', 'uuid'), '폼 제출 행이 없음');
crawl_param($params, $reasons, 'notification_hub_plan_id', crawl_first('notification_hub_plans', 'id'), '알림 허브 발송 계획 행이 없음');
crawl_param($params, $reasons, 'notification_hub_attempt_id', crawl_first('notification_hub_delivery_attempts', 'id'), '알림 허브 발송 시도 행이 없음');
crawl_param($params, $reasons, 'notification_hub_dead_letter_id', crawl_first('notification_hub_dead_letters', 'id'), '알림 허브 실패 보관 행이 없음');

// ── 확장 식별자 (언어팩·편집기 화면) ───────────────────────────────────
crawl_param($params, $reasons, 'module_identifier', crawl_first('modules', 'identifier', ['status' => 'active']), '활성 모듈이 없음');
crawl_param($params, $reasons, 'plugin_identifier', crawl_first('plugins', 'identifier', ['status' => 'active']), '활성 플러그인이 없음');
crawl_param($params, $reasons, 'user_template_identifier', crawl_first('templates', 'identifier', ['status' => 'active', 'type' => 'user']), '활성 사용자 템플릿이 없음');

echo json_encode(['params' => $params, 'reasons' => (object) $reasons], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;

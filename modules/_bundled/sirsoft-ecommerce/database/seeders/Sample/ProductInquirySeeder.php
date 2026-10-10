<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample;

use App\Extension\HookManager;
use App\Models\User;
use App\Traits\HasSeederCounts;
use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\Sample\Concerns\PurgesProductInquiries;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;

/**
 * 상품 1:1 문의 더미 데이터 시더
 *
 * 문의 게시판은 설정(inquiry.board_slug)을 따르고, 비어 있으면 게시판 샘플의 product-inquiry 게시판을
 * 설정에 연결합니다(시드 순서: 게시판 → 이커머스). 게시판이 없으면 건너뜁니다.
 *
 * 문의 글(질문·답변)은 게시판이 저장하므로 이 시더는 게시판 모델을 직접 만지지 않고,
 * 이커머스가 정의한 훅에 게시판이 답하게 합니다(운영 경로와 같은 훅 전용 연동).
 * - sirsoft-ecommerce.inquiry.get_settings       : 문의 게시판 존재 확인 (빈 배열 = 게시판 없음·응답 없음)
 * - sirsoft-ecommerce.inquiry.purge_sample_posts : 상품 재생성 전 문의 글(+그 글의 답변) 정리 (PurgesProductInquiries)
 * - sirsoft-ecommerce.inquiry.seed_sample_post   : 문의 글(+답변) 생성 → post_id·inquirable_type 반환
 * 이 시더가 직접 다루는 것은 자기 피벗(ProductInquiry)뿐입니다. 이 시더만 따로 돌렸을 때 문의가 이미
 * 있으면 실제 고객 문의일 수 있어 지우지도 만들지도 않습니다.
 */
class ProductInquirySeeder extends Seeder
{
    use HasSeederCounts;
    use PurgesProductInquiries;

    /**
     * 게시판 샘플이 만드는 상품 문의 게시판 슬러그 (비밀글·답글 1단·댓글 끔)
     */
    public const SAMPLE_BOARD_SLUG = 'product-inquiry';

    /**
     * 문의 생성 비율 (상품 수 대비 %, 상품당 평균 생성 건수)
     */
    private const INQUIRY_RATE = 3;

    /**
     * 답변 작성 비율 (문의 중 답변이 달리는 비율, %)
     */
    private const REPLY_RATE = 50;

    /**
     * 비밀글 비율 (문의 중 비밀글로 작성하는 비율, %)
     */
    private const SECRET_RATE = 60;

    /**
     * 비회원 문의 비율 (문의 중 비회원이 작성하는 비율, %)
     */
    private const GUEST_RATE = 20;

    /**
     * 문의 제목 샘플
     */
    private array $inquiryTitles = [
        '상품 재입고 문의드립니다.',
        '배송 기간이 얼마나 걸리나요?',
        '사이즈 교환 가능한가요?',
        '색상이 사진과 다른 것 같아요.',
        '상품 상세 스펙 문의드립니다.',
        '해외 배송 가능한가요?',
        '벌크 주문 시 할인이 되나요?',
        '선물 포장 가능한가요?',
        '제품 보증 기간이 어떻게 되나요?',
        '구성품이 맞지 않아요.',
        '사용 방법 문의드립니다.',
        '반품 절차가 어떻게 되나요?',
    ];

    /**
     * 문의 내용 샘플
     */
    private array $inquiryContents = [
        '해당 상품이 품절인데 재입고 예정이 있는지 문의드립니다. 언제쯤 구매 가능할까요?',
        '주문 후 배송까지 보통 얼마나 걸리는지 알고 싶습니다. 급하게 필요해서요.',
        '주문한 상품의 사이즈가 맞지 않아서 교환하고 싶은데 교환 절차를 알려주세요.',
        '사진에서 보이는 색상과 실제 상품의 색상이 다른 것 같습니다. 정확한 색상 확인 부탁드립니다.',
        '상품 페이지에 나와 있지 않은 세부 스펙이 있어 문의드립니다. 재질과 치수를 알고 싶습니다.',
        '해외 배송도 가능한지요? 가능하다면 배송비와 기간을 알려주세요.',
        '대량 구매 예정인데 할인 혜택이 있는지 문의드립니다.',
        '생일 선물로 구매할 예정인데 선물 포장 서비스가 있나요?',
        '제품 구매 후 하자가 발생한 경우 A/S나 보증이 어떻게 되는지 궁금합니다.',
        '상품을 받아보니 구성품이 안내된 것과 다릅니다. 확인 부탁드립니다.',
        '처음 사용하는 제품인데 사용 방법을 자세히 알려주실 수 있나요?',
        '단순 변심으로 반품하고 싶은데 반품 가능 기간과 절차를 알려주세요.',
    ];

    /**
     * 관리자 답변 샘플
     */
    private array $replyContents = [
        '문의해 주셔서 감사합니다. 재입고 예정일은 다음 달 초로 예정되어 있습니다. 재입고 알림 신청을 해두시면 입고 시 안내드리겠습니다.',
        '안녕하세요. 주문 후 영업일 기준 2~3일 내 출고되며, 출고 후 1~2일 내 수령 가능합니다.',
        '교환은 수령일로부터 7일 이내 가능합니다. 고객센터로 연락 주시면 자세한 안내 드리겠습니다.',
        '화면 설정에 따라 색상이 다르게 보일 수 있습니다. 실제 상품 색상은 [색상명]이며 사진과 동일합니다.',
        '문의 주신 스펙 정보를 안내드립니다. 재질: [재질], 치수: [가로x세로x높이]입니다.',
        '현재 국내 배송만 가능한 상태입니다. 해외 배송 서비스는 준비 중이며 오픈 시 공지하겠습니다.',
        '대량 구매 관련 문의는 기업 고객 담당 팀으로 연결해 드리겠습니다. 별도 연락 드리겠습니다.',
        '선물 포장 서비스를 제공하고 있습니다. 주문 시 요청사항란에 선물 포장 요청을 남겨 주세요.',
        '제품 구매일로부터 1년간 무상 보증 서비스를 제공하고 있습니다. 불량 발생 시 교환/수리 가능합니다.',
        '불편을 드려 죄송합니다. 빠른 시일 내 정확한 구성품으로 재발송 처리 해드리겠습니다.',
        '사용 방법 가이드를 첨부드립니다. 추가 문의 사항이 있으시면 언제든 연락 주세요.',
        '수령일로부터 7일 이내 반품 가능합니다. 반품 신청 후 상품 회수 후 환불 처리됩니다.',
    ];

    /**
     * 비회원 작성자명 샘플
     */
    private array $guestNames = ['김민준', '이서연', '박지훈', '최수아', '정도윤', '강하은', '조시우', '윤지아'];

    /**
     * 시더 실행
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        $this->command->info('상품 1:1 문의 더미 데이터 생성을 시작합니다.');

        $boardSlug = $this->resolveBoardSlug();

        if (! $boardSlug) {
            $this->command->warn('상품 문의 게시판이 없어 문의 샘플을 건너뜁니다.');
            $this->command->warn('게시판 샘플을 먼저 만들거나(module:seed sirsoft-board --sample → product-inquiry 게시판), 관리자 > 이커머스 설정 > 기본 정보 > 1:1 문의게시판을 설정해 주세요.');

            return;
        }

        // 상품 재생성(ProductSeeder)이 먼저 돌면 기존 문의는 그때 스레드째 정리돼 0건이다.
        // 이 시더만 따로 돌렸는데 문의가 남아 있으면 실제 고객 문의일 수 있어 지우지도, 더 만들지도 않는다.
        $existing = ProductInquiry::withTrashed()->count();
        if ($existing > 0) {
            $this->command->warn("기존 상품 문의 {$existing}건이 있어 문의 샘플을 만들지 않습니다 (실제 문의 보호 — 기존 문의와 게시판 답변은 그대로 둡니다).");
            $this->command->warn('문의 샘플을 새로 만들려면 상품 샘플과 함께 다시 시드하세요: module:seed sirsoft-ecommerce --sample');

            return;
        }

        $this->createInquiries($boardSlug);

        $count = ProductInquiry::count();
        $this->command->info("상품 1:1 문의 더미 데이터 {$count}건이 성공적으로 생성되었습니다.");
    }

    /**
     * 문의 게시판 슬러그를 정합니다.
     *
     * 설정(`inquiry.board_slug`)에 값이 있으면 그 값을 그대로 쓴다(운영자 선택은 덮어쓰지 않는다).
     * 비어 있고 게시판 샘플이 만든 `product-inquiry` 게시판이 있으면 그 슬러그를 설정에 기록한다 —
     * 그래야 상품 상세의 문의 탭과 관리자 문의 목록이 바로 동작한다. 어느 쪽 게시판도 게시판이
     * 응답하지 않으면(게시판 없음·게시판 모듈 미설치) null.
     *
     * @return string|null 문의 게시판 슬러그
     */
    private function resolveBoardSlug(): ?string
    {
        $configured = $this->inquiryBoardSlug();

        if ($configured !== null) {
            if ($this->boardExists($configured)) {
                return $configured;
            }

            $this->command->warn("설정된 문의 게시판 '{$configured}'이(가) 없습니다.");

            return null;
        }

        if (! $this->boardExists(self::SAMPLE_BOARD_SLUG)) {
            return null;
        }

        app(EcommerceSettingsService::class)->setSetting('inquiry.board_slug', self::SAMPLE_BOARD_SLUG);
        $this->command->line('  - 1:1 문의게시판 설정이 비어 있어 '.self::SAMPLE_BOARD_SLUG.' 게시판을 연결했습니다.');

        return self::SAMPLE_BOARD_SLUG;
    }

    /**
     * 게시판이 그 슬러그의 문의 게시판 설정을 돌려주는지(= 게시판이 있는지) 확인합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return bool 게시판이 있으면 true
     */
    private function boardExists(string $slug): bool
    {
        return ! empty(HookManager::applyFilters('sirsoft-ecommerce.inquiry.get_settings', [], $slug));
    }

    /**
     * 문의 더미 데이터 생성
     *
     * @param  string  $boardSlug  문의 게시판 슬러그
     * @return void 반환값 없음
     */
    private function createInquiries(string $boardSlug): void
    {
        $products = Product::all();

        if ($products->isEmpty()) {
            $this->command->warn('상품이 없습니다. ProductSeeder를 먼저 실행하는 것을 권장합니다.');

            return;
        }

        $users = User::where('is_super', false)->get();
        $admin = User::where('is_super', true)->first();

        $inquiryRate = $this->getSeederCount('inquiry_rate', self::INQUIRY_RATE);
        $created = 0;

        $progressBar = $this->command->getOutput()->createProgressBar($products->count());
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');

        foreach ($products as $product) {
            // 상품당 0~N건 문의 생성
            $inquiryCount = rand(0, $inquiryRate);

            for ($i = 0; $i < $inquiryCount; $i++) {
                $isGuest = $users->isEmpty() || rand(1, 100) <= self::GUEST_RATE;
                $isSecret = rand(1, 100) <= self::SECRET_RATE;
                $titleIndex = array_rand($this->inquiryTitles);

                $user = $isGuest ? null : $users->random();

                $createdAt = now()->subDays(rand(1, 60))->subHours(rand(0, 23));
                $hasReply = $admin && rand(1, 100) <= self::REPLY_RATE;
                $answeredAt = $hasReply ? $createdAt->copy()->addHours(rand(2, 72))->min(now()) : null;

                // 문의 글(+답변 글)은 게시판이 만든다 — 돌려받은 참조로 피벗을 잇는다
                $postResult = HookManager::applyFilters(
                    'sirsoft-ecommerce.inquiry.seed_sample_post',
                    null,
                    $boardSlug,
                    [
                        'title' => $this->inquiryTitles[$titleIndex],
                        'content' => $this->inquiryContents[$titleIndex],
                        'user_id' => $user?->id,
                        'author_name' => $isGuest ? $this->guestNames[array_rand($this->guestNames)] : null,
                        'password' => $isGuest ? '1234' : null,
                        'is_secret' => $isSecret,
                        'created_at' => $createdAt,
                    ],
                    $hasReply ? [
                        'content' => $this->replyContents[$titleIndex],
                        'user_id' => $admin->id,
                        'created_at' => $answeredAt,
                    ] : null
                );

                if (! is_array($postResult) || empty($postResult['post_id']) || empty($postResult['inquirable_type'])) {
                    continue;
                }

                // ecommerce_product_inquiries 피벗 생성
                ProductInquiry::create([
                    'product_id' => $product->id,
                    'inquirable_type' => $postResult['inquirable_type'],
                    'inquirable_id' => (int) $postResult['post_id'],
                    'user_id' => $user?->id,
                    'is_answered' => $hasReply,
                    'answered_at' => $answeredAt,
                    'product_name_snapshot' => $product->name ?? [],
                ]);

                $created++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->command->newLine();
        $this->command->line("  - 상품 {$products->count()}개 대상 {$created}건 문의 생성");
    }
}

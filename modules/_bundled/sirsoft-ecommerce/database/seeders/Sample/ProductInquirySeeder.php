<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample;

use App\Models\User;
use App\Traits\HasSeederCounts;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;

/**
 * 상품 1:1 문의 더미 데이터 시더
 *
 * 이커머스 설정에 inquiry.board_slug가 설정된 경우에만 실행됩니다.
 * 게시판 모듈이 설치되어 있어야 합니다.
 */
class ProductInquirySeeder extends Seeder
{
    use HasSeederCounts;

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
     * @return void
     */
    public function run(): void
    {
        $this->command->info('상품 1:1 문의 더미 데이터 생성을 시작합니다.');

        // 이커머스 설정에서 inquiry board_slug 확인
        $boardSlug = $this->getInquiryBoardSlug();

        if (! $boardSlug) {
            $this->command->warn('이커머스 설정에 inquiry.board_slug가 설정되어 있지 않습니다. 시더를 건너뜁니다.');
            $this->command->warn('관리자 > 이커머스 설정 > 기본 정보 > 1:1 문의게시판을 먼저 설정해 주세요.');

            return;
        }

        // 문의 게시판 조회
        $board = Board::where('slug', $boardSlug)->first();

        if (! $board) {
            $this->command->warn("board_slug '{$boardSlug}'에 해당하는 게시판이 존재하지 않습니다. 시더를 건너뜁니다.");

            return;
        }

        $this->deleteExistingInquiries($board);
        $this->createInquiries($board);

        $count = ProductInquiry::count();
        $this->command->info("상품 1:1 문의 더미 데이터 {$count}건이 성공적으로 생성되었습니다.");
    }

    /**
     * 이커머스 설정에서 inquiry board_slug를 반환합니다.
     *
     * @return string|null
     */
    private function getInquiryBoardSlug(): ?string
    {
        try {
            /** @var EcommerceSettingsService $settingsService */
            $settingsService = app(EcommerceSettingsService::class);
            $inquirySettings = $settingsService->getSettings('inquiry');

            return $inquirySettings['board_slug'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 기존 문의 데이터 삭제
     *
     * @param  Board  $board
     * @return void
     */
    private function deleteExistingInquiries(Board $board): void
    {
        $existingCount = ProductInquiry::count();

        if ($existingCount > 0) {
            // 연결된 게시판 Post도 함께 삭제
            $inquirableIds = ProductInquiry::where('inquirable_type', Post::class)->pluck('inquirable_id');

            if ($inquirableIds->isNotEmpty()) {
                Post::whereIn('id', $inquirableIds)->forceDelete();
            }

            // 답변 게시글(parent_id 기준) 삭제
            Post::where('board_id', $board->id)
                ->whereNotNull('parent_id')
                ->forceDelete();

            ProductInquiry::query()->delete();

            $this->command->warn("기존 문의 데이터 {$existingCount}건을 삭제했습니다.");
        }
    }

    /**
     * 문의 더미 데이터 생성
     *
     * @param  Board  $board
     * @return void
     */
    private function createInquiries(Board $board): void
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

                // board_posts에 문의 게시글 생성
                $post = Post::create([
                    'board_id'     => $board->id,
                    'title'        => $this->inquiryTitles[$titleIndex],
                    'content'      => $this->inquiryContents[$titleIndex],
                    'content_mode' => 'text',
                    'user_id'      => $user?->id,
                    'author_name'  => $isGuest ? $this->guestNames[array_rand($this->guestNames)] : null,
                    'password'     => $isGuest ? Hash::make('1234') : null,
                    'ip_address'   => '127.0.0.1',
                    'is_secret'    => $isSecret,
                    'status'       => PostStatus::Published,
                    'created_at'   => now()->subDays(rand(1, 60))->subHours(rand(0, 23)),
                ]);

                $hasReply = $admin && rand(1, 100) <= self::REPLY_RATE;
                $answeredAt = null;

                // 답변 게시글 생성
                if ($hasReply) {
                    $answeredAt = $post->created_at->addDays(rand(0, 3));

                    Post::create([
                        'board_id'     => $board->id,
                        'title'        => 'Re: '.$post->title,
                        'content'      => $this->replyContents[$titleIndex],
                        'content_mode' => 'text',
                        'user_id'      => $admin->id,
                        'ip_address'   => '127.0.0.1',
                        'is_secret'    => false,
                        'status'       => PostStatus::Published,
                        'parent_id'    => $post->id,
                        'depth'        => 1,
                        'created_at'   => $answeredAt,
                    ]);
                }

                // ecommerce_product_inquiries 피벗 생성
                ProductInquiry::create([
                    'product_id'           => $product->id,
                    'inquirable_type'      => Post::class,
                    'inquirable_id'        => $post->id,
                    'user_id'              => $user?->id,
                    'is_answered'          => $hasReply,
                    'answered_at'          => $answeredAt,
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

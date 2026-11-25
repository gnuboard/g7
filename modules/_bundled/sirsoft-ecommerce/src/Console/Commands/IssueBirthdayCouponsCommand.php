<?php

namespace Modules\Sirsoft\Ecommerce\Console\Commands;

use App\Helpers\TimezoneHelper;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\BirthdayMemberRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\BirthdayFieldResolver;
use Modules\Sirsoft\Ecommerce\Services\CouponAutoIssueService;

/**
 * 생일 쿠폰 발급 커맨드 (#952)
 *
 * 쇼핑몰 환경설정 쿠폰 탭에서 지정한 「생일로 쓸 회원 항목」의 월·일이 오늘(사이트 시간대)인 활성 회원에게 생일 쿠폰을
 * 해마다 1회 발급한다. 2월 29일생은 평년이면 2월 28일에 받는다. 같은 날 다시 돌려도 발급 키(`birthday:YYYY`) 유니크로
 * 두 번 나가지 않는다.
 *
 * @example php artisan sirsoft-ecommerce:issue-birthday-coupons
 * @example php artisan sirsoft-ecommerce:issue-birthday-coupons --date=2027-02-28 --dry-run
 */
class IssueBirthdayCouponsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sirsoft-ecommerce:issue-birthday-coupons
                            {--date= : 기준 날짜 (사이트 시간대, YYYY-MM-DD — 없으면 오늘)}
                            {--dry-run : 실제 발급 없이 대상만 확인}
                            {--chunk=500 : 한 번에 처리할 회원 수}';

    /**
     * @var string
     */
    protected $description = '생일인 회원에게 생일 쿠폰을 발급합니다.';

    /**
     * @param  BirthdayFieldResolver  $birthdayField  생일 항목 해석기
     * @param  CouponRepositoryInterface  $couponRepository  쿠폰 저장소
     * @param  BirthdayMemberRepositoryInterface  $members  생일 회원 저장소
     * @param  CouponAutoIssueService  $autoIssueService  쿠폰 자동발급 서비스
     */
    public function __construct(
        protected BirthdayFieldResolver $birthdayField,
        protected CouponRepositoryInterface $couponRepository,
        protected BirthdayMemberRepositoryInterface $members,
        protected CouponAutoIssueService $autoIssueService,
    ) {
        parent::__construct();
    }

    /**
     * 커맨드 실행
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        $definition = $this->birthdayField->resolveDefinition();
        if ($definition === null) {
            $this->info(__('sirsoft-ecommerce::messages.console.issue_birthday_coupons.field_not_configured'));

            return Command::SUCCESS;
        }

        $timezone = TimezoneHelper::getSiteTimezone();
        $dateOption = $this->option('date');

        try {
            $today = is_string($dateOption) && $dateOption !== ''
                ? CarbonImmutable::createFromFormat('!Y-m-d', $dateOption, $timezone)
                : CarbonImmutable::now($timezone)->startOfDay();
            // 달력에 없는 날짜(2027-02-30)는 Carbon 이 다음 달로 굴린다 — 되돌린 문자열이 입력과 다르면 잘못된 날짜로 본다
            // audit:allow datetime-display-user-timezone reason: 입력 검증용 기계값 비교 — 화면·알림에 표시하지 않는다
            if (is_string($dateOption) && $dateOption !== '' && $today->format('Y-m-d') !== $dateOption) {
                throw new \InvalidArgumentException('invalid date');
            }
        } catch (\Throwable) {
            $this->error(__('sirsoft-ecommerce::messages.console.issue_birthday_coupons.invalid_date'));

            return Command::FAILURE;
        }

        // audit:allow datetime-display-user-timezone reason: 사이트 시간대로 만든 기준 날짜의 비교용 기계값(월-일·연월일) — 화면·알림에 표시하지 않는다
        $monthDays = [$today->format('m-d')];
        // 2월 29일생은 평년이면 2월 28일에 받는다
        // audit:allow datetime-display-user-timezone reason: 사이트 시간대로 만든 기준 날짜의 비교용 기계값(월-일·연월일) — 화면·알림에 표시하지 않는다
        if ($today->format('m-d') === '02-28' && ! $today->isLeapYear()) {
            $monthDays[] = '02-29';
        }

        $couponIds = $this->couponRepository->getAutoIssueCouponIds(CouponIssueCondition::BIRTHDAY);
        if ($couponIds === []) {
            $this->info(__('sirsoft-ecommerce::messages.console.issue_birthday_coupons.no_coupons'));

            return Command::SUCCESS;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $year = (int) $today->year;
        $issued = 0;

        $targets = $this->members->chunkActiveUserIdsByBirthday(
            (int) $definition->id,
            $monthDays,
            max(1, (int) $this->option('chunk')),
            function (array $userIds) use ($isDryRun, $year, $couponIds, &$issued) {
                if (! $isDryRun) {
                    $issued += $this->autoIssueService->issueBirthdayCoupons($userIds, $year, $couponIds);
                }
            }
        );

        $this->info(__('sirsoft-ecommerce::messages.console.issue_birthday_coupons.done', [
            // audit:allow datetime-display-user-timezone reason: 사이트 시간대로 만든 기준 날짜의 비교용 기계값(월-일·연월일) — 화면·알림에 표시하지 않는다
            'date' => $today->toDateString(),
            'targets' => $targets,
            'issued' => $issued,
        ]).($isDryRun ? ' [DRY-RUN]' : ''));

        return Command::SUCCESS;
    }
}

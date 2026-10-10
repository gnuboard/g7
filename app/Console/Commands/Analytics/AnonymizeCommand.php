<?php

namespace App\Console\Commands\Analytics;

use App\Console\Commands\Traits\HasUnifiedConfirm;
use App\Enums\Analytics\ArchiveRunStatus;
use App\Services\Analytics\AnonymizeService;
use Illuminate\Console\Command;

/**
 * 쌓인 방문 기록 소급 익명화 커맨드
 *
 * 개인정보 수준을 더 엄격하게 바꾼 뒤, 이미 쌓인 기록에도 같은 수준을 적용합니다.
 * 되돌릴 수 없으므로 실행 전에 한 번 묻습니다.
 *
 * @example php artisan analytics:anonymize --mask-octets=2
 * @example php artisan analytics:anonymize --hash --from=2026-01-01 --to=2026-06-30
 */
class AnonymizeCommand extends Command
{
    use HasUnifiedConfirm;

    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'analytics:anonymize
                            {--mask-octets=0 : 가릴 주소 옥텟 수 (0~3)}
                            {--hash : 주소를 지우고 해시만 남긴다}
                            {--from= : 시작일 (Y-m-d)}
                            {--to= : 종료일 (Y-m-d)}
                            {--force : 확인 없이 진행}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '이미 쌓인 방문 기록에 마스킹·해시를 소급 적용합니다.';

    /**
     * 커맨드 실행
     *
     * @param  AnonymizeService  $service  익명화 서비스
     * @return int 종료 코드
     */
    public function handle(AnonymizeService $service): int
    {
        $maskOctets = (int) $this->option('mask-octets');
        $hash = (bool) $this->option('hash');

        if ($maskOctets < 0 || $maskOctets > 3) {
            $this->error('가릴 옥텟 수는 0 에서 3 사이여야 합니다.');

            return self::FAILURE;
        }

        if ($maskOctets === 0 && ! $hash) {
            $this->error('--mask-octets 또는 --hash 중 하나는 지정해야 합니다.');

            return self::FAILURE;
        }

        if (! $this->option('force')
            && ! $this->unifiedConfirm('이미 쌓인 방문 기록을 되돌릴 수 없게 바꿉니다. 진행할까요?')) {
            $this->info('취소했습니다.');

            return self::SUCCESS;
        }

        $run = $service->anonymize([
            'mask_octets' => $maskOctets,
            'hash' => $hash,
            'from' => $this->option('from'),
            'to' => $this->option('to'),
            'trigger' => 'manual',
        ]);

        $status = $run->status instanceof ArchiveRunStatus ? $run->status->value : (string) $run->status;

        if ($status === ArchiveRunStatus::Failed->value) {
            $this->error('소급 익명화 실패: '.$run->error);

            return self::FAILURE;
        }

        $this->info(sprintf('방문 %d건을 익명화했습니다.', $run->processed_visits));

        return self::SUCCESS;
    }
}

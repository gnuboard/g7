<?php

namespace App\Console\Commands\LanguagePack;

use App\Services\LanguagePackService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GitHub 으로 설치된 언어팩의 최신 릴리스 버전을 확인하여 `latest_version` 컬럼을 갱신합니다.
 *
 * 관리 UI 에서 "업데이트 가능" 배지 노출에 사용되며, 스케줄러에서 주기적으로 실행됩니다.
 */
class CheckLanguagePackUpdatesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'language-pack:check-updates {--identifier= : 특정 언어팩만 확인 (선택)}';

    /**
     * @var string
     */
    protected $description = '설치된 언어팩의 GitHub 업데이트를 확인합니다.';

    /**
     * @param  LanguagePackService  $service  언어팩 Service (실제 로직 SSoT)
     */
    public function __construct(
        private readonly LanguagePackService $service,
    ) {
        parent::__construct();
    }

    /**
     * 커맨드를 실행합니다.
     *
     * 실제 갱신 로직은 LanguagePackService::checkUpdates() 에 위임합니다.
     *
     * @return int 종료 코드 (0=성공, 1=실패)
     */
    public function handle(): int
    {
        $identifier = $this->option('identifier');

        try {
            $result = $this->service->checkUpdates($identifier);

            if ($result['checked'] === 0) {
                $this->info('확인할 GitHub 기반 언어팩이 없습니다.');

                return Command::SUCCESS;
            }

            foreach ($result['details'] as $entry) {
                if ($entry['error']) {
                    $this->warn(sprintf('  [%s] 조회 실패: %s', $entry['identifier'], $entry['error']));

                    continue;
                }

                $line = sprintf(
                    '  [%s] %s → %s%s',
                    $entry['identifier'],
                    $entry['current'],
                    $entry['latest'] ?? '?',
                    $entry['has_update'] ? '  (업데이트 가능)' : '',
                );
                $this->line($line);
            }

            $this->info(sprintf('확인 완료: %d 건 검사, %d 건 업데이트 가능.', $result['checked'], $result['updates']));

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $this->error('업데이트 확인 실패: '.$e->getMessage());
            Log::error('language-pack:check-updates 실패', [
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}

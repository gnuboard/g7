<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Thumbnails\DTO\ThumbnailSpec;
use App\Thumbnails\ImageProcessorResolver;
use App\Thumbnails\ThumbnailPregenerator;
use App\Thumbnails\ThumbnailPresetRegistry;
use App\Thumbnails\ThumbnailSourceProviders;
use Illuminate\Console\Command;

/**
 * 기존 이미지 썸네일 미리 만들기 커맨드
 *
 * 썸네일은 첫 요청 때 만들어지므로 기능을 켠 직후에는 목록 화면 첫 방문이 느릴 수 있다. 이미 올라와 있는
 * 원본을 원본 제공자(`ThumbnailSourceProviders`)별로 나눠 돌며 미리 만든다. 관리자 화면의
 * 「기존 이미지 썸네일 만들기」와 같은 `ThumbnailPregenerator::batch()` 를 `next_cursor` 가 빌 때까지 반복한다.
 * 이미 있는 파생본은 건너뛰므로 중간에 끊겨도 다시 실행하면 이어서 하는 것과 같다.
 *
 * @example php artisan thumbnails:pregenerate --dry-run
 * @example php artisan thumbnails:pregenerate --source=core.attachment --preset=sm --preset=md
 */
class PregenerateThumbnailsCommand extends Command
{
    use LocalizesDescription;

    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'thumbnails:pregenerate
                            {--source=* : 원본 종류 키 (여러 번 지정 가능, 미지정 시 전부)}
                            {--preset=* : 썸네일 규격 이름 (여러 번 지정 가능, 미지정 시 사용 중인 규격 전부 — og 제외)}
                            {--limit= : 이번 실행에서 처리할 최대 원본 수 (미지정 시 끝까지)}
                            {--dry-run : 만들지 않고 대상 원본 수만 확인}';

    /**
     * @param  ThumbnailPregenerator  $pregenerator  미리 만들기
     * @param  ThumbnailSourceProviders  $providers  원본 제공자
     * @param  ThumbnailPresetRegistry  $registry  규격 레지스트리
     * @param  ImageProcessorResolver  $processors  이미지 처리 엔진 해석기
     */
    public function __construct(
        private readonly ThumbnailPregenerator $pregenerator,
        private readonly ThumbnailSourceProviders $providers,
        private readonly ThumbnailPresetRegistry $registry,
        private readonly ImageProcessorResolver $processors,
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
        $sources = $this->listOption('source');
        $presets = $this->listOption('preset');

        if (! $this->validateNames($sources, array_keys($this->providers->all()), 'unknown_source')
            || ! $this->validateNames($presets, array_map(fn (ThumbnailSpec $spec) => $spec->name, $this->registry->all()), 'unknown_preset')) {
            return Command::FAILURE;
        }

        $limit = $this->parseLimit();

        if ($limit === false) {
            return Command::FAILURE;
        }

        $isDryRun = (bool) $this->option('dry-run');

        if (! $isDryRun && $this->processors->resolve() === null) {
            $this->error(__('thumbnail.console.pregenerate.no_engine'));

            return Command::FAILURE;
        }

        $batchSize = max(1, (int) config('core.thumbnail.pregenerate_batch', 20));
        $cursor = null;
        $processed = 0;
        $generated = 0;
        $total = null;
        $bar = null;

        do {
            $size = $limit === null ? $batchSize : min($batchSize, $limit - $processed);
            $result = $this->pregenerator->batch($cursor, $sources, $presets, $size, $isDryRun);

            if ($total === null) {
                $total = $result['total'];

                if ($total === 0) {
                    $this->info(__('thumbnail.console.pregenerate.nothing'));

                    return Command::SUCCESS;
                }

                $bar = $this->output->createProgressBar($limit === null ? $total : min($total, $limit));
                $bar->start();
            }

            $processed += $result['processed'];
            $generated += $result['generated'];
            $bar?->advance($result['processed']);

            // 커서가 그대로인데 처리한 원본이 없으면 제공자가 진행하지 못하는 것이다 — 무한 반복을 막는다.
            $stalled = $result['processed'] === 0 && $result['next_cursor'] === $cursor;
            $cursor = $result['next_cursor'];
        } while ($cursor !== null && ! $stalled && ($limit === null || $processed < $limit));

        $bar?->finish();
        $this->newLine();

        $replace = ['processed' => $processed, 'generated' => $generated, 'total' => $total];

        if ($isDryRun) {
            $this->info('[DRY RUN] '.__('thumbnail.console.pregenerate.dry_run', $replace));

            return Command::SUCCESS;
        }

        $this->info(__('thumbnail.console.pregenerate.done', $replace));

        return Command::SUCCESS;
    }

    /**
     * 여러 번 받는 옵션을 빈 값 없는 목록으로 (`--source=a,b` 쉼표 구분도 받는다)
     *
     * @param  string  $name  옵션 이름
     * @return list<string> 값 목록
     */
    private function listOption(string $name): array
    {
        $values = [];

        foreach ((array) $this->option($name) as $raw) {
            foreach (explode(',', (string) $raw) as $value) {
                $value = trim($value);

                if ($value !== '') {
                    $values[] = $value;
                }
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * 옵션 값이 허용 목록 안에 있는지 확인합니다 — 오타가 「전부」로 해석되지 않게 모르는 값은 오류로 끝냅니다.
     *
     * @param  list<string>  $values  옵션 값
     * @param  list<string>  $allowed  허용 값
     * @param  string  $messageKey  오류 문구 키 (`thumbnail.console.pregenerate.*`)
     * @return bool 모두 허용이면 true
     */
    private function validateNames(array $values, array $allowed, string $messageKey): bool
    {
        foreach ($values as $value) {
            if (! in_array($value, $allowed, true)) {
                $this->error(__("thumbnail.console.pregenerate.{$messageKey}", [
                    'value' => $value,
                    'allowed' => implode(', ', $allowed),
                ]));

                return false;
            }
        }

        return true;
    }

    /**
     * `--limit` 를 읽습니다.
     *
     * @return int|null|false 최대 원본 수, 미지정이면 null, 잘못된 값이면 false (오류는 이미 출력함)
     */
    private function parseLimit(): int|null|false
    {
        $raw = $this->option('limit');

        if ($raw === null || $raw === '') {
            return null;
        }

        $text = trim((string) $raw);

        if (preg_match('/^\d+$/', $text) !== 1 || (int) $text < 1) {
            $this->error(__('thumbnail.console.pregenerate.invalid_limit', ['value' => (string) $raw]));

            return false;
        }

        return (int) $text;
    }
}

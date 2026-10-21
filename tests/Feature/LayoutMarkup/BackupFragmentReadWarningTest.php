<?php

namespace Tests\Feature\LayoutMarkup;

use App\Extension\Layout\FragmentReadCache;
use App\Extension\Layout\LayoutRowSynchronizer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 업데이트 백업의 조각을 읽지 못하면 업데이트 1회에 WARNING 1줄로 모아 알린다 (#725 W2).
 *
 * 백업 조각은 병합 기준(이전 판)을 얻으려고 다시 컴파일한다. 종전에는 등록 경로가 템플릿마다·경로마다 백업을 다시
 * 컴파일했고, 읽지 못한 조각은 공용 읽기 함수가 ERROR 로 남겨 「백업 파일 수 × 템플릿 수 × 경로 수」 줄이 쌓였다
 * (10-01 실제 사례). 기준을 못 얻은 조각은 그것만 비교 기준 없이 진행하면 되는 일이라, 백업 레이아웃 쪽과 같이
 * 업데이트당 WARNING 1줄에 못 읽은 파일마다 원인(행·열)을 싣는다.
 */
class BackupFragmentReadWarningTest extends TestCase
{
    private string $backup;

    /**
     * 깨진 조각 둘과 정상 조각 하나를 담은 임시 백업 디렉토리를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->backup = base_path('storage/framework/testing/backup_fragments_'.substr(md5(uniqid('', true)), 0, 8));
        $extensions = $this->backup.'/resources/extensions';
        File::ensureDirectoryExists($extensions);

        foreach (['broken-one', 'broken-two'] as $key) {
            File::put("{$extensions}/{$key}.g7.html",
                "<extension target-layout=\"admin_user_form\" priority=\"100\">\n  <inject target-id=\"user_form\" position=\"append_child\">\n    <div/>\n");
        }

        File::put("{$extensions}/healthy.g7.html",
            "<extension target-layout=\"admin_user_form\" priority=\"100\">\n  <inject target-id=\"user_form\" position=\"append_child\">\n    <div/>\n  </inject>\n</extension>\n");
    }

    /**
     * 임시 백업 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->backup);
        // 실패 기억은 프로세스 단위다 — 같은 프로세스의 다음 테스트로 새지 않게 비운다.
        FragmentReadCache::flush();

        parent::tearDown();
    }

    /**
     * 두 템플릿 × 두 경로로 백업 조각을 여러 번 읽어도 WARNING 1줄 · ERROR 0줄이고, 정상 조각은 매번 돌려준다.
     *
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects backup_fragment_failures_are_one_warning_per_update
     *
     * @return void 반환값 없음
     */
    public function test_backup_fragment_failures_are_one_warning_per_update(): void
    {
        $synchronizer = app(LayoutRowSynchronizer::class);

        Log::spy();

        // 확장 활성화·갱신 경로가 각자 템플릿마다 부른다 — 템플릿 둘 × 경로 둘.
        foreach (['probe-admin', 'probe-user', 'probe-admin', 'probe-user'] as $template) {
            $fragments = $synchronizer->compileBackupFragments($this->backup, 'probe-backup-ext', $template);

            $this->assertSame(['healthy'], array_keys($fragments), '정상 조각은 비교 기준으로 쓸 수 있어야 한다');
        }

        Log::shouldNotHaveReceived('error');
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []): bool {
                if (! str_contains($message, '업데이트 백업 조각 일부를 읽지 못했습니다')) {
                    return false;
                }

                $errors = $context['errors'] ?? [];

                return ($context['identifier'] ?? null) === 'probe-backup-ext'
                    && array_keys($errors) === ['broken-one.g7.html', 'broken-two.g7.html']
                    && preg_match('/\(\d+행 \d+열\)/u', (string) $errors['broken-one.g7.html']) === 1
                    && preg_match('/\(\d+행 \d+열\)/u', (string) $errors['broken-two.g7.html']) === 1;
            })
            ->once();
    }
}

<?php

namespace Tests\Unit\Extension\Layout;

use App\Extension\Layout\BackupLayoutReader;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 업데이트 백업 레이아웃 읽기는 깨진 파일 하나 때문에 나머지 기준까지 버리지 않는다 (7.1.0 출시 점검 TU-6).
 *
 * 백업 컴파일은 keep 업데이트의 3자 병합 기준(원본 본문이 비어 있는 옛 행)의 출처다. 종전에는 검증 오류가 하나라도 있으면
 * 예외 → 빈 배열이라, 백업 안의 레이아웃 하나가 읽히지 않으면 그 확장의 모든 옛 행이 기준 없이(`kept_no_base`) 남아
 * 새 판 변경이 하나도 들어오지 않았다. 읽을 수 있는 파일은 기준으로 쓰고, 읽지 못한 파일만 빼고 로그에 남긴다.
 */
class BackupLayoutReaderTest extends TestCase
{
    /** @var string|null 임시 백업 디렉토리 */
    private ?string $backup = null;

    /**
     * 임시 백업 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if ($this->backup !== null) {
            File::deleteDirectory($this->backup);
        }

        parent::tearDown();
    }

    /**
     * 깨진 레이아웃 파일 하나가 있어도 읽을 수 있는 레이아웃은 기준으로 돌려준다.
     *
     * @scenario source=template, path=update_keep_backup_base, unit=partial
     *
     * @effects backup_base_survives_one_unreadable_layout
     *
     * @return void 반환값 없음
     */
    public function test_one_broken_backup_layout_does_not_discard_the_others(): void
    {
        $this->backup = storage_path('framework/testing/backup_reader_'.uniqid());
        File::ensureDirectoryExists($this->backup.'/layouts');
        File::put($this->backup.'/layouts/good.json', json_encode([
            'version' => '1.0.0',
            'layout_name' => 'good',
            'components' => [['id' => 'title', 'type' => 'basic', 'name' => 'Div', 'text' => 'GOOD']],
        ]));
        File::put($this->backup.'/layouts/broken.json', '{"layout_name": "broken", ');

        $originals = (new BackupLayoutReader)->layouts($this->backup, 'templates', 'acme-backup-template');

        $this->assertArrayHasKey('good', $originals, '깨진 파일 하나 때문에 읽을 수 있는 백업 레이아웃까지 버렸다');
        $this->assertArrayNotHasKey('broken', $originals);
        $this->assertSame('GOOD', $originals['good']['components'][0]['text']);
    }
}

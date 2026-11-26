<?php

namespace Tests\Unit\Services;

use App\Contracts\Extension\StorageInterface;
use App\Extension\Storage\CoreStorageDriver;
use App\Services\Concerns\ResolvesRowStorage;
use Tests\TestCase;

/**
 * 행 `disk` 를 읽어 서빙·삭제하는 **아홉 지점이 같은 판정을 쓴다.**
 *
 * 왜 재는가:
 *   같은 판정이 번들 확장 넷(board·page·ecommerce·ckeditor5)에는 있고 **코어 두
 *   서비스에만 없었다.** 코어는 행 `disk` 를 존재 검증 없이 `withDisk()` 에 넘겼고,
 *   그 두 서비스가 소비하는 라우트는 무인증 공개 서빙이라 고아 disk 행 하나로
 *   500 이 났다. 넷은 막고 둘은 안 막는 상태는 아무것도 울리지 않는다 — 같은 사이트의
 *   같은 종류 파일이 통로에 따라 404 와 500 으로 갈릴 뿐이다.
 *
 *   고아 disk 는 운영자가 만들지 않아도 생긴다. 그 디스크를 제공하던 플러그인을
 *   비활성화하거나 `FILESYSTEM_DISK` 를 바꾸면 config 에서 사라지고 행은 남는다.
 *
 * 무엇을 재는가:
 *   ① 코어 trait 의 **거동** — 네 입력(null · 빈 문자열 · 등록된 disk · 고아 disk)
 *   ② 그 판정을 쓰는 **아홉 지점의 모집단** — 하나라도 사본이 판정을 잃으면 red
 *
 *   ②를 함께 재는 이유는 ①만으로는 trait 이 옳아도 소비처가 그것을 **안 쓰는** 방향이
 *   드러나지 않기 때문이다. 실제 결함이 그 방향이었다 — 판정은 저장소에 넉 벌 있었고
 *   코어가 그것을 부르지 않았다.
 *
 * 확장 넷이 코어 trait 을 쓰지 않고 자기 사본을 유지하는 것은 의도다 — 그러려면
 * 코어 하한을 올려야 하는데, 이 판정은 확장이 스스로 가질 수 있는 것이라 하한을
 * 올릴 값이 아니다. 대신 그 여섯의 **판정 일치**를 여기서 잠근다.
 */
class RowStorageOrphanDiskParityTest extends TestCase
{
    /**
     * 행 `disk` 를 읽는 지점 전수 (파일 → 그 파일이 판정을 갖고 있음을 보이는 토큰).
     *
     * 모집단을 손으로 적되 **비어 있으면 red** 가 되도록 개수를 함께 잠근다. 목록에서
     * 원소를 지우면 그 지점의 의무가 조용히 사라지는 형태를 막는다.
     */
    private const ROW_DISK_CONSUMERS = [
        'app/Services/AttachmentService.php',
        'app/Services/TemplateLayoutAttachmentService.php',
        'modules/_bundled/sirsoft-board/src/Services/AttachmentService.php',
        'modules/_bundled/sirsoft-page/src/Services/PageAttachmentService.php',
        'modules/_bundled/sirsoft-ecommerce/src/Services/Concerns/ResolvesRowStorage.php',
        'plugins/_bundled/sirsoft-ckeditor5/src/Services/ImageServeService.php',
        // 공개 자산 디스크를 따르게 되며 행 disk 로 읽고·지우게 된 두 플러그인.
        // 사본을 또 두지 않고 코어 도우미 `RowDiskStorage::resolve()`(@since 7.1.5)를 부른다.
        'plugins/_bundled/sirsoft-hero_section/src/Services/BannerImageService.php',
        'plugins/_bundled/sirsoft-popup/src/Services/PopupImageService.php',
        // 내보내기 파일이 비공개 첨부 저장소를 따르게 되며 행 disk(`ecommerce_exports.disk`)로 내리고·지우게 됐다.
        // 이커머스 사본 trait(`use ResolvesRowStorage;`)을 쓴다.
        'modules/_bundled/sirsoft-ecommerce/src/Services/ExportService.php',
    ];

    /**
     * 고아 disk 는 주입 스토리지로 폴백한다 — 예외가 아니라 404 가 정상 degradation 이다.
     *
     * @effects row_disk_orphan_fallback_is_shared_by_every_serving_path
     *
     * @return void 반환값 없음
     */
    public function test_orphan_disk_falls_back_to_the_injected_storage(): void
    {
        $base = $this->fakeStorage('public');
        $subject = $this->subjectUsing($base);

        config(['filesystems.disks.s3_archive' => ['driver' => 's3']]);

        $this->assertSame($base, $subject->call(null), 'null disk 는 주입 스토리지');
        $this->assertSame($base, $subject->call(''), '빈 문자열 disk 는 주입 스토리지');
        $this->assertSame($base, $subject->call('public'), '같은 disk 는 새 인스턴스를 만들지 않는다');

        $this->assertNotSame(
            $base,
            $subject->call('s3_archive'),
            '등록된 다른 disk 는 그 disk 의 스토리지여야 한다 — 아니면 디스크 전환 이전 행이 도달 불가가 된다'
        );

        $this->assertSame(
            $base,
            $subject->call('disk_from_a_removed_plugin'),
            'config 에 없는 disk 를 그대로 넘기면 이후 exists/response/delete 가 예외를 던져 '
                .'무인증 공개 서빙이 500 이 된다 — 주입 스토리지로 폴백해 404 로 떨어져야 한다'
        );
    }

    /**
     * 행 `disk` 를 읽는 지점 전수가 그 판정을 **실제로 경유한다.**
     *
     * @effects row_disk_orphan_fallback_is_shared_by_every_serving_path
     *
     * @return void 반환값 없음
     */
    public function test_every_row_disk_consumer_applies_the_orphan_guard(): void
    {
        $root = dirname(__DIR__, 3);

        $this->assertGreaterThanOrEqual(
            9,
            count(self::ROW_DISK_CONSUMERS),
            '모집단이 줄었다 — 지점을 지웠다면 그 파일이 정말 행 disk 를 안 읽는지 확인하고 목록을 고친다'
        );

        foreach (self::ROW_DISK_CONSUMERS as $relative) {
            $path = $root.'/'.$relative;

            $this->assertFileExists($path, "{$relative} 이 없다 — 옮겼다면 이 목록을 함께 옮긴다");

            $source = (string) file_get_contents($path);

            // 판정을 **자기 안에** 갖거나(확장 사본), 그 판정을 소유한 trait 을 쓰거나
            // (코어) 둘 중 하나여야 한다. 「어느 쪽인지」가 아니라 「경유하는가」가 축이다.
            // 변수명과 극성은 지점마다 다르다 (`$disk` / `$rowDisk`, `=== null` 로 폴백 /
            // `!== null` 로 사용). 판정의 **존재**가 축이지 그 표기가 축이 아니다 —
            // 표기로 좁히면 같은 판정을 다른 이름으로 적은 형제가 위반으로 뜬다.
            $ownsJudgment = (bool) preg_match(
                '/config\(\s*"filesystems\.disks\.\{\$\w+\}"\s*\)\s*[!=]==\s*null/',
                $source
            );
            // 위임은 둘 중 하나다 — 코어 서비스의 trait(`use ResolvesRowStorage;`), 또는
            // 확장이 부르는 코어 도우미(`RowDiskStorage::resolve(`). 둘 다 같은 판정을 소유한다.
            $delegatesJudgment = (bool) preg_match('/use\s+ResolvesRowStorage\s*;|RowDiskStorage::resolve\(/', $source);

            $this->assertTrue(
                $ownsJudgment || $delegatesJudgment,
                "{$relative} 에 고아 disk 존재 검증이 없다 — 미등록 disk 로 withDisk 를 만들면 "
                    .'그 통로만 500 이 되고, 형제 통로는 404 라 같은 사이트가 통로에 따라 갈린다'
            );
        }
    }

    /**
     * 코어 두 서비스가 **직접** `withDisk(행 disk)` 를 부르지 않는다.
     *
     * 앞 테스트는 「판정문이 파일에 있는가」만 본다. 판정을 둔 채 그 옆에서 여전히
     * 날 `withDisk()` 를 부르면 그 검사는 통과하므로 이 축을 따로 잠근다.
     *
     * @effects row_disk_orphan_fallback_is_shared_by_every_serving_path
     *
     * @return void 반환값 없음
     */
    public function test_core_services_do_not_bypass_the_guard(): void
    {
        $root = dirname(__DIR__, 3);

        foreach ([
            'app/Services/AttachmentService.php',
            'app/Services/TemplateLayoutAttachmentService.php',
        ] as $relative) {
            $source = (string) file_get_contents($root.'/'.$relative);

            $this->assertDoesNotMatchRegularExpression(
                '/withDisk\(\s*\$\w+(?:->disk|\[[\'"]disk[\'"]\])\s*\)/',
                $source,
                "{$relative} 이 행 disk 를 그대로 withDisk 에 넘긴다 — storageForRow() 를 경유해야 한다"
            );
        }
    }

    /**
     * trait 을 쓰는 최소 소비자 (주입 스토리지 하나를 갖는 코어 서비스의 축소판).
     *
     * @param  StorageInterface  $storage  주입할 스토리지
     * @return object storageForRow 를 call 로 노출하는 trait 소비자
     */
    private function subjectUsing(StorageInterface $storage): object
    {
        return new class($storage)
        {
            use ResolvesRowStorage;

            /**
             * 행 disk 판정 trait 이 기댈 주입 스토리지를 받는다.
             *
             * @param  StorageInterface  $storage  주입 스토리지
             */
            public function __construct(protected StorageInterface $storage) {}

            /**
             * 보호된 storageForRow 를 시험에서 부를 수 있게 드러낸다.
             *
             * @param  ?string  $disk  행에 저장된 disk 이름
             * @return StorageInterface 그 행을 서빙할 스토리지
             */
            public function call(?string $disk): StorageInterface
            {
                return $this->storageForRow($disk);
            }
        };
    }

    /**
     * 실제 코어 드라이버를 쓴다 — `withDisk()` 가 clone 후 대입만 하고 **검증하지 않는다**는
     * 것이 이 결함의 전제이므로, 그 성질을 흉내 낸 대역으로 재면 전제 자체가 측정 밖이 된다.
     *
     * @param  string  $disk  대입할 행 disk 이름
     * @return StorageInterface 그 disk 를 대입한 코어 드라이버
     */
    private function fakeStorage(string $disk): StorageInterface
    {
        return (new CoreStorageDriver)->withDisk($disk);
    }
}

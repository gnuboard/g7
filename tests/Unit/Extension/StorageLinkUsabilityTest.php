<?php

namespace Tests\Unit\Extension;

use App\Extension\Helpers\StorageLinkHelper;
use Tests\TestCase;

/**
 * `public/storage` 링크 가용 판정(B-3)의 세 상태 계약 테스트.
 *
 * 이 판정이 public 디스크 공개 URL 의 형태를 가른다 — 링크가 있으면 정적 경로, 없으면
 * 읽기 전용 서빙 라우트. 기존 테스트는 전부 `forcePublicStorageLinkUsable()` 로 이 판정을
 * **우회**해서, 실측 로직을 `return true;` 로 바꿔도 전 스위트가 초록이었다. 그 변이는
 * 「링크는 있는데 대상이 없는」 환경에서 전 이미지 404 — 계획서가 명시적으로 금지한
 * 「조용한 반쪽 상태」 — 를 되살린다. 시나리오 매니페스트의 `storage_link` 축
 * (usable·missing·broken)을 여기서 채운다.
 *
 * @scenario storage_link=usable, link_kind=symlink
 */
class StorageLinkUsabilityTest extends TestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = storage_path('app/g7-test-storage-link-'.bin2hex(random_bytes(4)));
        mkdir($this->sandbox.'/target', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (['link', 'target'] as $entry) {
            $path = $this->sandbox.DIRECTORY_SEPARATOR.$entry;

            // 링크 판정은 SSoT 를 쓴다. 사본(`@readlink() !== false`)은 일반 디렉토리를
            // 링크로 오판하고(이 환경의 readlink 는 일반 디렉토리에도 자기 경로를 준다),
            // `is_dir()` 는 정상 junction 을 걸러내지 못해(junction 은 is_dir 이 false)
            // 두 분기 어디에도 걸리지 않은 junction 이 실 트리에 그대로 쌓인다.
            if (StorageLinkHelper::isLinkOrJunction($path)) {
                @unlink($path) || @rmdir($path);

                continue;
            }
            if (is_dir($path)) {
                @rmdir($path);
            }
        }
        @rmdir($this->sandbox);

        parent::tearDown();
    }

    private function makeDirectoryLink(string $link, string $target): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return @symlink($target, $link);
        }

        if (@symlink($target, $link)) {
            return true;
        }

        $command = 'cmd /c mklink /J '.escapeshellarg(str_replace('/', '\\', $link))
            .' '.escapeshellarg(str_replace('/', '\\', $target)).' 2>&1';
        @exec($command, $out, $code);

        return $code === 0 && (is_dir($link) || is_link($link));
    }

    /**
     * @effects public_storage_url_switches_to_route_when_link_missing
     */
    public function test_missing_link_is_not_usable(): void
    {
        $this->assertFalse(
            StorageLinkHelper::isUsableStorageLink($this->sandbox.'/link'),
            '링크가 없으면 대체 서빙 경로(B-3)로 가야 한다'
        );
    }

    public function test_plain_directory_is_not_usable(): void
    {
        mkdir($this->sandbox.'/link', 0755, true);

        $this->assertFalse(
            StorageLinkHelper::isUsableStorageLink($this->sandbox.'/link'),
            '일반 디렉토리는 링크가 아니다 — 링크로 오인하면 storage 갱신이 반영되지 않는다'
        );
    }

    /**
     * @effects public_storage_url_switches_to_route_when_link_missing
     */
    public function test_working_link_is_usable(): void
    {
        if (! $this->makeDirectoryLink($this->sandbox.'/link', $this->sandbox.'/target')) {
            $this->markTestSkipped('이 환경에서는 디렉토리 링크(symlink·junction)를 만들 수 없다');
        }

        $this->assertTrue(
            StorageLinkHelper::isUsableStorageLink($this->sandbox.'/link'),
            '동작하는 링크는 정적 /storage/... 경로를 쓴다'
        );
    }

    /**
     * 「링크는 있는데 대상이 없다」 — 이 반쪽 상태를 가용으로 읽으면 전 이미지가 404 가 된다.
     *
     * @effects public_storage_link_usability_rejects_broken_link
     */
    public function test_broken_link_is_not_usable(): void
    {
        if (! $this->makeDirectoryLink($this->sandbox.'/link', $this->sandbox.'/target')) {
            $this->markTestSkipped('이 환경에서는 디렉토리 링크(symlink·junction)를 만들 수 없다');
        }

        // 대상 제거 → 링크만 남은 상태
        @rmdir($this->sandbox.'/target');

        $this->assertFalse(
            StorageLinkHelper::isUsableStorageLink($this->sandbox.'/link'),
            '끊어진 링크는 불가로 판정해야 한다 (조용한 404 금지)'
        );
    }

    /**
     * 「링크는 정상인데 대상이 설정된 루트와 다르다」 — 이것도 반쪽 상태다.
     *
     * 파일을 **쓰는** 쪽(`Storage::disk('public')`)과 서빙 쪽(`PublicStorageService::rootPath()`)은
     * 이미 `filesystems.disks.public.root` 를 따른다. 그런데 링크 가용 판정만 `public/storage`
     * 의 존재 여부로 끝나면, 운영자가 그 루트를 옮긴 사이트에서 **옛 위치를 가리키는 살아
     * 있는 링크**가 가용으로 읽힌다 — 공개 URL 은 `/storage/...` 로 나가고 그 링크는 빈
     * 트리를 가리키므로 그 사이트의 공개 자산이 통째로 404 가 된다. 오류도 로그도 없다.
     *
     * @effects public_storage_link_usability_rejects_broken_link
     */
    public function test_link_pointing_elsewhere_than_the_configured_root_is_not_usable(): void
    {
        if (! $this->makeDirectoryLink($this->sandbox.'/link', $this->sandbox.'/target')) {
            $this->markTestSkipped('이 환경에서는 디렉토리 링크(symlink·junction)를 만들 수 없다');
        }

        $elsewhere = $this->sandbox.'/elsewhere';
        mkdir($elsewhere, 0755, true);

        try {
            $this->assertTrue(
                StorageLinkHelper::isUsableStorageLink($this->sandbox.'/link', $this->sandbox.'/target'),
                '대상이 설정 루트와 같으면 종전대로 가용이다 (회귀 축)'
            );

            $this->assertFalse(
                StorageLinkHelper::isUsableStorageLink($this->sandbox.'/link', $elsewhere),
                '링크가 설정 루트가 아닌 곳을 가리키면 대체 서빙으로 가야 한다 — 가용으로 읽으면 전 자산이 조용히 404 다'
            );

            // 루트가 아직 만들어지지 않은 상태(로컬 디스크는 첫 쓰기에서 만든다)에서도
            // 「다른 곳을 가리킨다」는 사실은 그대로다 — 여기서 관대하게 통과시키면 결함이 남는다.
            $this->assertFalse(
                StorageLinkHelper::isUsableStorageLink($this->sandbox.'/link', $this->sandbox.'/not-created-yet'),
                '해석되지 않는 루트에 대해서도 링크 대상이 다르면 불가로 판정해야 한다'
            );
        } finally {
            @rmdir($elsewhere);
        }
    }

    /**
     * junction 에 대한 PHP 파일 술어의 **실측 사실**을 고정합니다 (Windows 전용).
     *
     * 이 사실은 검증 라운드에서 두 번 뒤집혔다. 1차가 실측으로 맞게 적었고, 2차가
     * 「정상 junction 은 is_dir·file_exists 둘 다 true」로 잘못 정정하면서 「그러므로 판정식은
     * `is_dir()` 과 결과가 같다」는 근거를 함께 남겼다. 그 근거를 믿고 판정식을 `is_dir()` 로
     * 단순화하면, 정상 junction 이 「링크 없음」으로 판정되어 그 사이트의 공개 자산 URL 이
     * 통째로 대체 서빙 경로로 넘어간다 — 1차가 고친 결함의 재발이다.
     *
     * 사실을 문장으로만 남기면 또 뒤집힌다. 그래서 표 자체를 여기서 잰다.
     */
    public function test_junction_file_predicates_match_the_measured_table(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('junction 은 Windows 전용 (mklink /J)');
        }
        if (! $this->makeDirectoryLink($this->sandbox.'/link', $this->sandbox.'/target')) {
            $this->markTestSkipped('이 환경에서는 디렉토리 링크를 만들 수 없다');
        }
        if (is_link($this->sandbox.'/link')) {
            $this->markTestSkipped('이 환경은 junction 이 아니라 symlink 를 만든다');
        }

        $junction = $this->sandbox.'/link';
        $plainDir = $this->sandbox.'/target';

        $this->assertFalse(is_dir($junction), '정상 junction 은 is_dir() 이 false 다 — is_dir() 로는 링크 가용을 판정할 수 없다');
        $this->assertTrue(file_exists($junction), '정상 junction 은 file_exists() 가 true 다');
        $this->assertFalse(is_file($junction), 'junction 은 파일이 아니다');

        $this->assertTrue(is_dir($plainDir), '일반 디렉토리는 is_dir() 이 true 다');
        $this->assertNotFalse(@readlink($plainDir), '이 환경의 readlink() 는 일반 디렉토리에도 경로를 돌려준다 — readlink 성공만으로 링크를 판정할 수 없다');

        // 위 표가 곧 판정식의 근거다.
        $this->assertTrue(StorageLinkHelper::isUsableStorageLink($junction));
        $this->assertFalse(StorageLinkHelper::isUsableStorageLink($plainDir));
    }
}

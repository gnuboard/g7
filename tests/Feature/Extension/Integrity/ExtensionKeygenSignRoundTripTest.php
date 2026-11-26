<?php

namespace Tests\Feature\Extension\Integrity;

use App\Extension\Integrity\PackageExpectation;
use App\Extension\Integrity\PackageSignatureVerifier;
use App\Extension\Integrity\PackageVerificationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\PackageSignatureFixtures;
use Tests\TestCase;

/**
 * 공개 제작자 명령 `extension:keygen` → `extension:sign` → 검증 왕복
 *
 * 키 파일은 권한 0600·덮어쓰기 거부, 디렉터리 서명은 결정적 ZIP(같은 내용이면 같은 바이트)을 만들고, 만든 서명은 그 공개키를
 * 신뢰 키로 등록한 사이트에서 「서명 확인됨」이다.
 */
class ExtensionKeygenSignRoundTripTest extends TestCase
{
    use PackageSignatureFixtures;
    use RefreshDatabase;

    /**
     * 스키마를 다시 만들지 않고 트랜잭션으로 격리합니다.
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void
    {
        $this->beginDatabaseTransaction();
    }

    /**
     * 시험 파일을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanupPackageFixtures();

        parent::tearDown();
    }

    /**
     * keygen 이 만든 키로 ZIP·디렉터리를 서명하고, 신뢰 키로 등록하면 검증된다.
     *
     * @scenario case=keygen_sign_roundtrip
     *
     * @effects keygen_sign_output_verifies_with_registered_key
     *
     * @return void 반환값 없음
     */
    public function test_keygen_sign_verify_roundtrip(): void
    {
        $dir = $this->packageFixtureDir();
        $keyFile = $dir.DIRECTORY_SEPARATOR.'TEST-acme-key.json';

        $this->artisan('extension:keygen', ['--out' => $keyFile, '--label' => 'acme'])->assertExitCode(0);
        $this->assertFileExists($keyFile);
        $key = json_decode(File::get($keyFile), true);
        $this->assertMatchesRegularExpression('/^acme:[0-9a-f]{16}$/', $key['key_id']);
        if (DIRECTORY_SEPARATOR === '/') {
            $this->assertSame('0600', substr(sprintf('%o', fileperms($keyFile)), -4));
        }

        // 같은 경로는 덮어쓰지 않는다
        $this->artisan('extension:keygen', ['--out' => $keyFile, '--label' => 'acme'])->assertExitCode(1);
        // 이름 규칙
        $this->artisan('extension:keygen', ['--out' => $dir.'/x.json', '--label' => 'bad label!'])->assertExitCode(1);

        $this->artisan('extension:trust-key', ['action' => 'add', 'value' => $key['public_key'], '--label' => 'acme', '--vendor' => ['acme']])->assertExitCode(0);

        // ZIP 서명
        $zip = $this->makePackageZip('plugin', 'acme-pay', '1.0.0');
        $this->artisan('extension:sign', ['path' => $zip, '--key' => $keyFile])->assertExitCode(0);
        $sig = File::get($zip.'.sig.json');
        $doc = json_decode($sig, true);
        $this->assertSame(['type' => 'plugin', 'identifier' => 'acme-pay', 'version' => '1.0.0'], $doc['package']);
        $result = app(PackageSignatureVerifier::class)->verify($zip, $sig, new PackageExpectation('plugin', 'acme-pay', '1.0.0'));
        $this->assertSame(PackageVerificationStatus::Verified, $result->status);

        // 디렉터리 서명 — 결정적 ZIP
        $pkgDir = $dir.DIRECTORY_SEPARATOR.'src-acme-board';
        File::ensureDirectoryExists($pkgDir.'/src');
        File::put($pkgDir.'/module.json', json_encode(['identifier' => 'acme-board', 'version' => '2.1.0']));
        File::put($pkgDir.'/src/Board.php', "<?php\n");
        $outA = $dir.DIRECTORY_SEPARATOR.'out-a';
        $outB = $dir.DIRECTORY_SEPARATOR.'out-b';
        $this->artisan('extension:sign', ['path' => $pkgDir, '--key' => $keyFile, '--out' => $outA, '--signed-at' => '2026-11-20T00:00:00Z'])->assertExitCode(0);
        $this->artisan('extension:sign', ['path' => $pkgDir, '--key' => $keyFile, '--out' => $outB, '--signed-at' => '2026-11-20T00:00:00Z'])->assertExitCode(0);
        $this->assertFileEquals($outA.'/acme-board-2.1.0.zip', $outB.'/acme-board-2.1.0.zip', '같은 디렉터리의 ZIP 이 실행마다 달랐다');
        $this->assertFileEquals($outA.'/acme-board-2.1.0.zip.sig.json', $outB.'/acme-board-2.1.0.zip.sig.json');

        $zipArchive = new \ZipArchive;
        $zipArchive->open($outA.'/acme-board-2.1.0.zip');
        $this->assertNotFalse($zipArchive->locateName('acme-board-2.1.0/module.json'), '루트 디렉터리 하나({identifier}-{version}/) 구조가 아니다');
        $zipArchive->close();

        $dirResult = app(PackageSignatureVerifier::class)->verify(
            $outA.'/acme-board-2.1.0.zip',
            File::get($outA.'/acme-board-2.1.0.zip.sig.json'),
            new PackageExpectation('module', 'acme-board', '2.1.0')
        );
        $this->assertSame(PackageVerificationStatus::Verified, $dirResult->status);

        // 편집된 키 파일은 거부
        $key['key_id'] = 'acme:0000000000000000';
        File::put($keyFile, json_encode($key));
        $this->artisan('extension:sign', ['path' => $zip, '--key' => $keyFile])->assertExitCode(1);
    }
}

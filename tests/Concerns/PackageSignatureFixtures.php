<?php

namespace Tests\Concerns;

use App\Extension\Integrity\BuiltinTrustKeys;
use App\Extension\Integrity\PackageSignatureFormat;
use App\Extension\Integrity\PackageSigner;
use App\Extension\Integrity\TrustKeyStatus;
use App\Extension\Integrity\UnsignedPackagePolicy;
use App\Models\ExtensionTrustKey;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/**
 * 패키지 서명 테스트 공용 도구 — 시험 전용 키(TEST)·패키지 ZIP·서명 파일 만들기 (7.2.0).
 *
 * 키는 `tests/Fixtures/PackageSignature/TEST-*.signing-key.json`(공개된 시드 — 시험 전용) 또는 이름에서 만든 결정적 시험
 * 키다. 어느 것도 운영 신뢰 목록에 들어가지 않는다. 만든 파일은 `packageFixtureDir()` 아래에 두고 tearDown 에서 지운다.
 */
trait PackageSignatureFixtures
{
    /**
     * 이 테스트가 만든 파일을 둘 디렉터리
     *
     * @var string|null
     */
    private ?string $packageFixtureDir = null;

    /**
     * 「미서명 패키지 차단」 스위치를 꺼진 상태로 고정합니다 (Laravel 이 trait 이름 규칙으로 setUp 때 부른다).
     *
     * 설정 읽기는 부팅 때 실제 `storage/app/settings/security.json` 에서 적재된다(TestCase::fakeSettingsDisk 는 쓰기만
     * 막는다). 개발 사이트에서 스위치를 켜 두면 미서명 확인(409, 계속 가능)을 기대하는 테스트가 「차단」(계속 불가)으로
     * 바뀌어 깨진다. 스위치를 켜는 시나리오는 테스트 안에서 직접 켠다.
     *
     * @return void 반환값 없음
     */
    protected function setUpPackageSignatureFixtures(): void
    {
        Config::set('g7_settings.core.'.UnsignedPackagePolicy::BLOCK_SETTING, false);
    }

    /**
     * 시험 파일 디렉터리를 돌려줍니다 (처음 부르면 만든다).
     *
     * @return string 디렉터리 경로
     */
    protected function packageFixtureDir(): string
    {
        if ($this->packageFixtureDir === null) {
            $this->packageFixtureDir = storage_path('framework/testing/package-signature-'.uniqid());
            File::ensureDirectoryExists($this->packageFixtureDir);
        }

        return $this->packageFixtureDir;
    }

    /**
     * 시험 파일과 내장 키 대체를 정리합니다 (tearDown 에서 부른다).
     *
     * @return void 반환값 없음
     */
    protected function cleanupPackageFixtures(): void
    {
        BuiltinTrustKeys::useForTesting(null);

        if ($this->packageFixtureDir !== null && File::isDirectory($this->packageFixtureDir)) {
            File::deleteDirectory($this->packageFixtureDir);
        }
        $this->packageFixtureDir = null;
    }

    /**
     * 저장소 fixture 시험 키를 읽습니다.
     *
     * @param  string  $name  시험 키 이름 (glitter 또는 sirsoft)
     * @return array{label: string, key_id: string, public_key: string, seed: string, public_key_b64: string, file: string} 시험 키 (공개키·시드는 원시 바이트)
     */
    protected function fixtureKey(string $name): array
    {
        $file = base_path('tests/Fixtures/PackageSignature/TEST-'.$name.'.signing-key.json');
        $key = app(PackageSigner::class)->readKeyFile($file);

        return $key + ['public_key_b64' => base64_encode($key['public_key']), 'file' => $file];
    }

    /**
     * 이름에서 결정적 시험 키를 만듭니다 (fixture 에 없는 제작자용).
     *
     * @param  string  $label  서명자 이름
     * @return array{label: string, key_id: string, public_key: string, seed: string, public_key_b64: string, file: string} 키
     */
    protected function makeTestKey(string $label): array
    {
        $signer = app(PackageSigner::class);
        $content = $signer->keyFromSeed($label, hash('sha256', 'G7-TEST-ONLY-KEY '.$label, true));
        $file = $this->packageFixtureDir().DIRECTORY_SEPARATOR.'TEST-'.$label.'-'.uniqid().'.signing-key.json';
        File::put($file, json_encode($content));
        $key = $signer->readKeyFile($file);

        return $key + ['public_key_b64' => base64_encode($key['public_key']), 'file' => $file];
    }

    /**
     * 제작자 키를 신뢰 목록(DB)에 등록합니다.
     *
     * @param  array{label: string, key_id: string, public_key_b64: string}  $key  시험 키
     * @param  array<int, string>  $vendors  신뢰 vendor
     * @return ExtensionTrustKey 등록한 행
     */
    protected function trustVendorKey(array $key, array $vendors): ExtensionTrustKey
    {
        return ExtensionTrustKey::query()->create([
            'key_id' => $key['key_id'],
            'public_key' => $key['public_key_b64'],
            'label' => $key['label'],
            'vendor_scope' => $vendors,
            'created_by' => null,
        ]);
    }

    /**
     * 시험 키를 내장 키로 씁니다 (테스트 동안만).
     *
     * @param  array{label: string, key_id: string, public_key_b64: string}  $key  시험 키
     * @param  string  $status  내장 키 상태 (active·retired·revoked)
     * @return void 반환값 없음
     */
    protected function useBuiltinTestKey(array $key, string $status = TrustKeyStatus::Active->value): void
    {
        BuiltinTrustKeys::useForTesting([[
            'key_id' => $key['key_id'],
            'public_key' => $key['public_key_b64'],
            'label' => $key['label'],
            'status' => $status,
        ]]);
    }

    /**
     * 확장·언어팩 패키지 ZIP 을 만듭니다 (루트 `{identifier}-{version}/` 하나 — 서명 자산 구조).
     *
     * @param  string  $type  패키지 종류 (module·plugin·template·language_pack)
     * @param  string  $identifier  manifest 식별자
     * @param  string  $version  manifest 버전
     * @param  array<string, mixed>  $manifestExtra  manifest 에 더할 칸
     * @param  array<string, string>  $files  더 넣을 파일 (상대 경로 → 내용)
     * @param  string|null  $zipName  ZIP 파일 이름 (기본 `{identifier}-{version}.zip`)
     * @return string ZIP 경로
     */
    protected function makePackageZip(string $type, string $identifier, string $version, array $manifestExtra = [], array $files = [], ?string $zipName = null): string
    {
        $manifestFile = PackageSigner::MANIFEST_FILES[$type];
        $root = $identifier.'-'.$version;
        $zipPath = $this->packageFixtureDir().DIRECTORY_SEPARATOR.($zipName ?? $root.'.zip');

        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString($root.'/'.$manifestFile, (string) json_encode(array_merge([
            'identifier' => $identifier,
            'version' => $version,
            'vendor' => strstr($identifier, '-', true) ?: $identifier,
            'name' => ['ko' => $identifier, 'en' => $identifier],
        ], $manifestExtra), JSON_PRETTY_PRINT));
        foreach ($files as $relative => $content) {
            $zip->addFromString($root.'/'.$relative, $content);
        }
        $zip->close();

        return $zipPath;
    }

    /**
     * ZIP 을 시험 키로 서명해 옆에 `.sig.json` 을 씁니다.
     *
     * @param  string  $zipPath  ZIP 경로
     * @param  array{label: string, key_id: string, public_key: string, seed: string}  $key  시험 키
     * @param  string  $type  패키지 종류
     * @param  string  $identifier  서명할 식별자
     * @param  string  $version  서명할 버전
     * @param  \Closure|null  $tamper  서명 뒤 문서를 바꿀 함수 (서명 불일치 시험)
     * @return string 서명 파일 경로
     */
    protected function signPackage(string $zipPath, array $key, string $type, string $identifier, string $version, ?\Closure $tamper = null): string
    {
        $document = app(PackageSigner::class)->signZip($zipPath, $key, $type, $identifier, $version, '2026-11-20T00:00:00Z');
        if ($tamper !== null) {
            $document = $tamper($document);
        }

        $sigPath = $zipPath.PackageSignatureFormat::FILE_SUFFIX;
        File::put($sigPath, PackageSignatureFormat::encode($document));

        return $sigPath;
    }
}

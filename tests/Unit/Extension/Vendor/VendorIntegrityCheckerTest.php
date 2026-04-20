<?php

namespace Tests\Unit\Extension\Vendor;

use App\Extension\Vendor\VendorBundler;
use App\Extension\Vendor\VendorIntegrityChecker;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class VendorIntegrityCheckerTest extends TestCase
{
    private VendorIntegrityChecker $checker;

    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checker = new VendorIntegrityChecker;
        $this->testDir = storage_path('app/test-vendor-integrity-'.uniqid());
        File::ensureDirectoryExists($this->testDir);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->testDir)) {
            File::deleteDirectory($this->testDir);
        }
        parent::tearDown();
    }

    public function test_verify_fails_when_zip_missing(): void
    {
        $result = $this->checker->verify($this->testDir);

        $this->assertFalse($result->valid);
        $this->assertContains('bundle_zip_missing', $result->errors);
    }

    public function test_verify_fails_when_manifest_missing(): void
    {
        File::put($this->testDir.'/vendor-bundle.zip', 'dummy zip content');

        $result = $this->checker->verify($this->testDir);

        $this->assertFalse($result->valid);
        $this->assertContains('bundle_manifest_missing', $result->errors);
    }

    public function test_verify_fails_when_manifest_invalid_json(): void
    {
        File::put($this->testDir.'/vendor-bundle.zip', 'dummy');
        File::put($this->testDir.'/vendor-bundle.json', '{invalid json');

        $result = $this->checker->verify($this->testDir);

        $this->assertFalse($result->valid);
        $this->assertContains('bundle_manifest_invalid', $result->errors);
    }

    public function test_verify_fails_on_unsupported_schema_version(): void
    {
        File::put($this->testDir.'/vendor-bundle.zip', 'dummy');
        File::put($this->testDir.'/vendor-bundle.json', json_encode([
            'schema_version' => '99.0',
            'zip_sha256' => hash('sha256', 'dummy'),
        ]));

        $result = $this->checker->verify($this->testDir);

        $this->assertFalse($result->valid);
        $this->assertContains('bundle_schema_unsupported', $result->errors);
    }

    public function test_verify_fails_on_zip_hash_mismatch(): void
    {
        File::put($this->testDir.'/vendor-bundle.zip', 'actual content');
        File::put($this->testDir.'/vendor-bundle.json', json_encode([
            'schema_version' => VendorBundler::SCHEMA_VERSION,
            'zip_sha256' => hash('sha256', 'different content'),
        ]));

        $result = $this->checker->verify($this->testDir);

        $this->assertFalse($result->valid);
        $this->assertContains('zip_hash_mismatch', $result->errors);
    }

    public function test_verify_passes_on_matching_hash(): void
    {
        $content = 'matching zip content';
        File::put($this->testDir.'/vendor-bundle.zip', $content);
        File::put($this->testDir.'/vendor-bundle.json', json_encode([
            'schema_version' => VendorBundler::SCHEMA_VERSION,
            'zip_sha256' => hash('sha256', $content),
            'package_count' => 42,
        ]));

        $result = $this->checker->verify($this->testDir);

        $this->assertTrue($result->valid, 'errors: '.implode(',', $result->errors));
        $this->assertEmpty($result->errors);
        $this->assertSame(42, $result->meta['package_count']);
    }

    public function test_verify_detects_composer_json_mismatch(): void
    {
        $zipContent = 'zip content';
        $composerJsonContent = '{"name":"new"}';
        $storedHash = hash('sha256', '{"name":"old"}');

        File::put($this->testDir.'/vendor-bundle.zip', $zipContent);
        File::put($this->testDir.'/composer.json', $composerJsonContent);
        File::put($this->testDir.'/vendor-bundle.json', json_encode([
            'schema_version' => VendorBundler::SCHEMA_VERSION,
            'zip_sha256' => hash('sha256', $zipContent),
            'composer_json_sha256' => $storedHash,
        ]));

        $result = $this->checker->verify($this->testDir);

        $this->assertFalse($result->valid);
        $this->assertContains('composer_json_sha_mismatch', $result->errors);
    }

    public function test_read_manifest_returns_null_when_missing(): void
    {
        $this->assertNull($this->checker->readManifest($this->testDir));
    }

    public function test_read_manifest_returns_parsed_array(): void
    {
        File::put($this->testDir.'/vendor-bundle.json', json_encode(['key' => 'value']));

        $result = $this->checker->readManifest($this->testDir);

        $this->assertIsArray($result);
        $this->assertSame('value', $result['key']);
    }

    public function test_compute_file_hash_returns_sha256(): void
    {
        $path = $this->testDir.'/test.txt';
        File::put($path, 'hello');

        $hash = $this->checker->computeFileHash($path);

        $this->assertSame(hash('sha256', 'hello'), $hash);
    }
}

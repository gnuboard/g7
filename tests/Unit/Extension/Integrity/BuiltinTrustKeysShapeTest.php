<?php

namespace Tests\Unit\Extension\Integrity;

use App\Extension\Integrity\BuiltinTrustKeys;
use App\Extension\Integrity\PackageSignatureFormat;
use App\Extension\Integrity\TrustKeyStatus;
use Illuminate\Support\Facades\File;
use Tests\Concerns\PackageSignatureFixtures;
use Tests\TestCase;

/**
 * 코어 내장 신뢰 키 목록(`BuiltinTrustKeys::KEYS`) 가드 (#916 ④)
 *
 * 이 목록은 코어 패키지를 검증하는 유일한 근거라 잘못 들어간 한 항목이 신뢰 경계를 무너뜨린다. 두 가지를 막는다.
 *
 *  - 시험 전용 키 유입: `tests/Fixtures/PackageSignature/*` 의 공개키(시드가 공개돼 있다)나 서명자 이름·key_id 에 TEST 가 든 키.
 *  - 형태 위반: key_id 가 label·공개키로 다시 계산한 값과 다름, public_key 가 raw 32바이트 표준 base64 가 아님, status 가 STATUSES 밖.
 *
 * 운영 키는 아직 전달되지 않아 목록이 비어 있다. 빈 목록에서 형태 검사가 빈 루프로 끝나 「검사가 돈다」고 착각하지 않도록,
 * 지금 수(0)를 기록하는 단언을 따로 두고, 검사기 자체는 fixture 키로 만든 대체 목록(`useForTesting`)으로 검증한다.
 */
class BuiltinTrustKeysShapeTest extends TestCase
{
    use PackageSignatureFixtures;

    /**
     * 코어에 실린 내장 키 수 — 운영 키를 넣거나 빼면 이 수를 함께 바꾼다 (그 순간 아래 형태·유입 검사가 실제 항목을 본다).
     */
    private const SHIPPED_KEY_COUNT = 1;

    /**
     * 대체 목록과 시험 파일을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanupPackageFixtures();

        parent::tearDown();
    }

    /**
     * 코어에 실린 목록은 기록한 수와 같고, 항목마다 형태를 지키며, 시험 전용 키가 없다.
     *
     * @scenario case=builtin_key_shape_guard
     *
     * @effects builtin_keys_have_valid_shape_and_no_test_keys
     *
     * @return void 반환값 없음
     */
    public function test_shipped_keys_have_valid_shape_and_no_test_keys(): void
    {
        $this->assertCount(self::SHIPPED_KEY_COUNT, BuiltinTrustKeys::KEYS, '내장 키 수가 바뀌었다 — 운영 키를 넣거나 빼면 SHIPPED_KEY_COUNT 를 함께 바꾼다 (지금은 sirsoft 주 키 1개)');
        $this->assertSame([], $this->shapeViolations(BuiltinTrustKeys::KEYS), '내장 키 형태 위반');
        $this->assertSame([], $this->testKeyLeaks(BuiltinTrustKeys::KEYS), '내장 키에 시험 전용 키가 들어갔다');
    }

    /**
     * 형태 검사기는 fixture 키로 만든 올바른 대체 목록을 통과시키고, 항목별 위반을 사유와 함께 잡는다.
     *
     * @scenario case=builtin_key_shape_guard
     *
     * @effects builtin_keys_have_valid_shape_and_no_test_keys
     *
     * @return void 반환값 없음
     */
    public function test_shape_checker_accepts_valid_list_and_catches_each_violation(): void
    {
        $valid = [
            $this->entryFor($this->fixtureKey('sirsoft'), TrustKeyStatus::Active->value),
            $this->entryFor($this->makeTestKey('retiredsigner'), TrustKeyStatus::Retired->value),
        ];

        BuiltinTrustKeys::useForTesting($valid);
        $this->assertSame([], $this->shapeViolations(BuiltinTrustKeys::all()), '올바른 대체 목록을 위반으로 판정했다');

        $base = $valid[0];
        $cases = [
            'key_id_mismatch' => ['key_id' => 'sirsoft:0000000000000000'] + $base,
            'public_key_not_base64' => ['public_key' => 'not*base64'] + $base,
            'public_key_not_32_bytes' => ['public_key' => base64_encode(str_repeat("\x01", 31))] + $base,
            'status_unknown' => ['status' => 'enabled'] + $base,
            'missing_field' => array_diff_key($base, ['label' => true]),
        ];

        foreach ($cases as $expected => $entry) {
            BuiltinTrustKeys::useForTesting([$entry]);
            $codes = array_column($this->shapeViolations(BuiltinTrustKeys::all()), 'code');
            $this->assertContains($expected, $codes, "{$expected} 위반을 잡지 못했다");
        }
    }

    /**
     * 유입 검사기는 fixture 공개키와 TEST 가 든 서명자 이름·key_id 를 잡는다.
     *
     * @scenario case=builtin_key_shape_guard
     *
     * @effects builtin_keys_have_valid_shape_and_no_test_keys
     *
     * @return void 반환값 없음
     */
    public function test_leak_checker_catches_fixture_and_test_labelled_keys(): void
    {
        $this->assertNotSame([], $this->fixturePublicKeys(), 'fixture 공개키를 하나도 읽지 못했다 — 유입 검사가 빈 목록과 비교하고 있다');

        $fixture = $this->entryFor($this->fixtureKey('glitter'), TrustKeyStatus::Active->value);
        $this->assertContains('fixture_public_key', array_column($this->testKeyLeaks([$fixture]), 'code'));

        $labelled = $this->entryFor($this->makeTestKey('TEST-release'), TrustKeyStatus::Active->value);
        $this->assertContains('test_label', array_column($this->testKeyLeaks([$labelled]), 'code'));

        $clean = $this->entryFor($this->makeTestKey('releasebot'), TrustKeyStatus::Active->value);
        $this->assertSame([], $this->testKeyLeaks([$clean]), 'fixture 가 아니고 TEST 도 없는 키를 유입으로 판정했다');
    }

    /**
     * 시험 키로 내장 키 항목을 만듭니다.
     *
     * @param  array{label: string, key_id: string, public_key_b64: string}  $key  시험 키
     * @param  string  $status  상태
     * @return array{key_id: string, public_key: string, label: string, status: string} 내장 키 항목
     */
    private function entryFor(array $key, string $status): array
    {
        return ['key_id' => $key['key_id'], 'public_key' => $key['public_key_b64'], 'label' => $key['label'], 'status' => $status];
    }

    /**
     * 내장 키 목록의 형태 위반을 모읍니다.
     *
     * @param  array<int, mixed>  $keys  내장 키 목록
     * @return list<array{index: int, code: string}> 위반 (없으면 빈 배열)
     */
    private function shapeViolations(array $keys): array
    {
        $violations = [];

        foreach (array_values($keys) as $index => $key) {
            if (! is_array($key) || array_diff(['key_id', 'public_key', 'label', 'status'], array_keys($key)) !== []) {
                $violations[] = ['index' => $index, 'code' => 'missing_field'];

                continue;
            }

            $raw = base64_decode((string) $key['public_key'], true);
            if (! is_string($raw) || base64_encode($raw) !== $key['public_key']) {
                $violations[] = ['index' => $index, 'code' => 'public_key_not_base64'];
            } elseif (strlen($raw) !== 32) {
                $violations[] = ['index' => $index, 'code' => 'public_key_not_32_bytes'];
            } elseif (PackageSignatureFormat::keyId((string) $key['label'], $raw) !== $key['key_id']) {
                $violations[] = ['index' => $index, 'code' => 'key_id_mismatch'];
            }

            if (! in_array($key['status'], TrustKeyStatus::values(), true)) {
                $violations[] = ['index' => $index, 'code' => 'status_unknown'];
            }
        }

        return $violations;
    }

    /**
     * 내장 키 목록에 섞인 시험 전용 키를 모읍니다.
     *
     * @param  array<int, mixed>  $keys  내장 키 목록
     * @return list<array{index: int, code: string}> 유입 (없으면 빈 배열)
     */
    private function testKeyLeaks(array $keys): array
    {
        $fixtureKeys = $this->fixturePublicKeys();
        $leaks = [];

        foreach (array_values($keys) as $index => $key) {
            if (! is_array($key)) {
                continue;
            }

            if (in_array((string) ($key['public_key'] ?? ''), $fixtureKeys, true)) {
                $leaks[] = ['index' => $index, 'code' => 'fixture_public_key'];
            }

            if (stripos((string) ($key['label'] ?? ''), 'test') !== false || stripos((string) ($key['key_id'] ?? ''), 'test') !== false) {
                $leaks[] = ['index' => $index, 'code' => 'test_label'];
            }
        }

        return $leaks;
    }

    /**
     * `tests/Fixtures/PackageSignature/` 의 JSON 에 적힌 공개키를 모두 모읍니다 (키 파일·교차 벡터).
     *
     * @return list<string> 공개키 (표준 base64)
     */
    private function fixturePublicKeys(): array
    {
        $found = [];

        foreach (File::glob(base_path('tests/Fixtures/PackageSignature/*.json')) as $file) {
            $data = json_decode((string) File::get($file), true);
            array_walk_recursive($data, function ($value, $field) use (&$found) {
                if (is_string($field) && str_contains($field, 'public_key') && is_string($value) && $value !== '') {
                    $found[] = $value;
                }
            });
        }

        return array_values(array_unique($found));
    }
}

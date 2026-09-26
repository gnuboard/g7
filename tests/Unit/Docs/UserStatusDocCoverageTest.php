<?php

declare(strict_types=1);

namespace Tests\Unit\Docs;

use App\Enums\UserStatus;
use PHPUnit\Framework\TestCase;

/**
 * 공개 API 문서가 `UserStatus` 어휘 전량을 아는지 잠급니다.
 *
 * `api:docgen --check` 는 이 축을 재지 못합니다 — `docs/backend/api-documentation.md` 가
 * 「표·필드 설명·엔드포인트 서술은 전량 보존된다」를 규약으로 두어 신규 상태 값·필드 행은
 * 사람이 넣어야 하기 때문입니다. 그래서 #636 S4 가 `dormant` 를 더했을 때 여덟 라운드 동안
 * 어느 게이트도 「문서가 그 값을 모른다」를 잡지 못했고, `users.md` 의 **요청 허용값 표**가
 * 실제 API(`Rule::in(UserStatus::values())`)보다 좁은 상태로 남아 있었습니다.
 *
 * 모집단은 손으로 적지 않습니다 — 상태 어휘는 Enum 에서, 문서 목록은 「계정 상태」를 실제로
 * 열거하는 파일을 훑어 파생합니다. 둘 중 하나라도 비면 그 자체를 red 로 만듭니다.
 *
 * 판정 단위는 **파일**입니다. 같은 파일 안에서도 열거 줄마다 완전도가 갈리는 선행 드리프트가
 * 있어(예: `pending_verification` 이 일부 줄에만 있다) 줄 단위로 재면 이 커밋의 범위 밖
 * 문서까지 함께 고쳐야 green 이 됩니다. 그 좁힘을 여기 적어 둡니다 — 이 검사가 막는 것은
 * 「문서가 새 상태를 아예 모른다」이지 「모든 줄이 전량을 적는다」가 아닙니다.
 */
final class UserStatusDocCoverageTest extends TestCase
{
    /** 상태 어휘를 열거하는 공개 문서를 찾을 디렉터리 */
    private const DOC_DIR = 'docs/backend';

    /** 그 문서를 가려내는 표지 — 이 문구가 있는 파일만 상태 어휘를 서술한다 */
    private const ENUMERATION_MARKER = '계정 상태';

    /**
     * 상태 어휘를 서술하는 공개 문서 전량이 `UserStatus` 값 전량을 언급하는지 잠급니다.
     *
     * @scenario surface=public_api_docs
     *
     * @effects every_user_status_value_is_named_in_the_api_docs
     *
     * @return void 단언만 수행한다
     */
    public function test_every_user_status_value_is_named_in_the_api_docs(): void
    {
        $statuses = UserStatus::values();

        $this->assertNotSame(
            [],
            $statuses,
            'UserStatus 어휘가 비었습니다 — 모집단이 비면 아래 단언은 아무것도 재지 않습니다.'
        );

        $docs = $this->docsThatEnumerateStatuses();

        $this->assertNotSame(
            [],
            $docs,
            '「'.self::ENUMERATION_MARKER.'」를 서술하는 문서를 하나도 찾지 못했습니다 — 탐색 경로가 낡았습니다.'
        );

        $gaps = [];

        foreach ($docs as $relPath => $contents) {
            foreach ($statuses as $status) {
                if (! $this->mentionsStatus($contents, $status)) {
                    $gaps[] = $relPath.' → '.$status;
                }
            }
        }

        $this->assertSame(
            [],
            $gaps,
            "공개 문서가 모르는 회원 상태가 있습니다 — 그 문서를 보고 연동하는 쪽은 그 값을 받지도 보내지도 못합니다.\n"
            .implode("\n", $gaps)
        );
    }

    /**
     * `dormant_at` 처럼 상태와 짝인 응답 필드가 문서에 실렸는지 함께 잠급니다.
     *
     * 상태 값만 재면 「상태는 적었는데 그 상태가 만드는 필드는 빠뜨린」 자리가 통과합니다 —
     * 실제로 `UserResource::withAdminInfo()` 의 `dormant_at` 이 그 자리였습니다.
     *
     * @scenario surface=public_api_docs
     *
     * @effects status_paired_timestamp_columns_are_documented
     *
     * @return void 단언만 수행한다
     */
    public function test_status_paired_timestamp_columns_are_documented(): void
    {
        $columns = [];

        foreach (UserStatus::cases() as $case) {
            foreach (array_keys($case->transitionTimestamps()) as $column) {
                $columns[$column] = true;
            }
        }

        $columns = array_keys($columns);

        $this->assertNotSame(
            [],
            $columns,
            '상태 짝 컬럼 모집단이 비었습니다 — transitionTimestamps() 구조가 바뀌었습니다.'
        );

        $usersDoc = $this->read('docs/backend/api/users.md');

        // 낱말 경계로 재야 한다 — `str_contains` 는 `dormant_at_XXX` 같은 더 긴 이름에도
        // 걸려, 컬럼 행을 지우거나 이름을 바꿔도 초록이 유지된다(이 검사를 만들며 실측했다).
        $missing = array_values(array_filter(
            $columns,
            fn (string $column): bool => ! $this->mentionsToken($usersDoc, $column)
        ));

        // `dormant_notified_at` 은 API 로 내보내지 않는 내부 표지라 문서 대상이 아니다.
        $missing = array_values(array_diff($missing, ['dormant_notified_at']));

        $this->assertSame(
            [],
            $missing,
            '회원 API 문서에 상태 짝 시각 컬럼이 빠졌습니다: '.implode(', ', $missing)
        );
    }

    /**
     * 짝 표(`transitionTimestamps()`)를 읽는 쓰기 경로 전량이 개발자 문서에 실렸는지 잠급니다.
     *
     * 그 문서는 「짝 표를 바꿀 때 영향 범위」를 알려 주는 자리라, 경로를 하나 빠뜨리면 표를
     * 고치는 쪽이 그 경로를 확인 대상에서 뺀다. 실제로 「쓰기 경로는 셋」이라 적힌 채
     * 로그인 해제·본인인증 활성화 두 경로가 빠져 있었다. 호출처는 손으로 적지 않고 소스를
     * 훑어 파생한다.
     *
     * @scenario surface=public_api_docs
     *
     * @effects every_transition_timestamp_reader_is_listed_in_the_developer_docs
     *
     * @return void 단언만 수행한다
     */
    public function test_every_transition_timestamp_reader_is_listed_in_the_developer_docs(): void
    {
        $readers = $this->transitionTimestampReaders();

        $this->assertNotSame(
            [],
            $readers,
            'transitionTimestamps() 호출처를 하나도 찾지 못했습니다 — 탐색 경로나 메서드 이름이 낡았습니다.'
        );

        $doc = $this->read('docs/backend/service-repository.md');

        $missing = array_values(array_filter(
            $readers,
            fn (string $class): bool => ! $this->mentionsToken($doc, $class)
        ));

        $this->assertSame(
            [],
            $missing,
            'service-repository.md 가 짝 표를 읽는 쓰기 경로를 빠뜨렸습니다: '.implode(', ', $missing)
        );
    }

    /**
     * `transitionTimestamps()` 를 부르는 클래스의 짧은 이름을 모읍니다(정의부·테스트 제외).
     *
     * @return list<string> 클래스 짧은 이름 목록
     */
    private function transitionTimestampReaders(): array
    {
        $readers = [];

        foreach (['app', 'modules/_bundled', 'plugins/_bundled'] as $scanRoot) {
            $base = $this->repoPath($scanRoot);

            if (! is_dir($base)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $path = str_replace('\\', '/', $file->getPathname());

                if (str_contains($path, '/tests/') || str_ends_with($path, '/app/Enums/UserStatus.php')) {
                    continue;
                }

                // 주석 속 언급이 아니라 실제 호출(`->transitionTimestamps()`)만 센다
                if (preg_match('/->transitionTimestamps\(\)/', (string) file_get_contents($path)) === 1) {
                    $readers[$file->getBasename('.php')] = true;
                }
            }
        }

        $readers = array_keys($readers);
        sort($readers);

        return $readers;
    }

    /**
     * 「계정 상태」를 실제로 서술하는 문서만 모읍니다.
     *
     * @return array<string, string> 저장소 상대 경로 → 본문
     */
    private function docsThatEnumerateStatuses(): array
    {
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->repoPath(self::DOC_DIR), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'md') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (! str_contains($contents, self::ENUMERATION_MARKER)) {
                continue;
            }

            // 열거가 아니라 한 번 스쳐 가는 언급(예: "계정 상태가 활성이 아닌 경우")뿐인
            // 문서는 어휘 소유자가 아니다 — 기존 상태 두 개를 함께 적은 파일만 남긴다.
            if (! str_contains($contents, 'blocked') || ! str_contains($contents, 'withdrawn')) {
                continue;
            }

            $relPath = str_replace('\\', '/', substr($file->getPathname(), strlen($this->repoPath(''))));

            $found[$relPath] = $contents;
        }

        ksort($found);

        return $found;
    }

    /**
     * 본문이 그 상태 값을 낱말 단위로 언급하는지 판정합니다.
     *
     * @param  string  $contents  문서 본문
     * @param  string  $status  상태 값
     * @return bool 언급하면 true
     */
    private function mentionsStatus(string $contents, string $status): bool
    {
        return $this->mentionsToken($contents, $status);
    }

    /**
     * 본문이 그 식별자를 낱말 단위로 언급하는지 판정합니다.
     *
     * 부분 문자열 비교를 쓰지 않습니다 — 더 긴 이름 안에 묻혀도 통과해 가드가 공동화됩니다.
     *
     * @param  string  $contents  문서 본문
     * @param  string  $token  찾을 식별자
     * @return bool 언급하면 true
     */
    private function mentionsToken(string $contents, string $token): bool
    {
        return preg_match('/(?<![0-9A-Za-z_])'.preg_quote($token, '/').'(?![0-9A-Za-z_])/', $contents) === 1;
    }

    /**
     * 저장소 루트 기준 절대 경로를 만듭니다.
     *
     * `base_path()` 를 쓰지 않는 이유는 이 검사가 프레임워크 부팅 없이 돌아야 하기
     * 때문입니다 — 설정 부팅이 유닛 테스트로 새는 것을 막는 저장소 규율과 같은 취지입니다.
     *
     * @param  string  $relPath  저장소 상대 경로 (빈 문자열이면 루트)
     * @return string 절대 경로
     */
    private function repoPath(string $relPath): string
    {
        $root = \dirname(__DIR__, 3);

        return $relPath === '' ? $root.\DIRECTORY_SEPARATOR : $root.\DIRECTORY_SEPARATOR.$relPath;
    }

    /**
     * 문서 한 편을 읽습니다.
     *
     * @param  string  $relPath  저장소 상대 경로
     * @return string 본문
     */
    private function read(string $relPath): string
    {
        $path = $this->repoPath($relPath);

        $this->assertFileExists($path, $relPath.' 를 찾지 못했습니다 — 경로가 낡았습니다.');

        return (string) file_get_contents($path);
    }
}

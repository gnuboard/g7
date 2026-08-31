<?php

namespace Tests\Unit\Extension;

use App\Extension\Helpers\AbstractRootMarkerFileUpdater;
use App\Extension\Helpers\RootHtaccessUpdater;
use Tests\TestCase;

/**
 * 루트 `.htaccess` 마커 블록 갱신기(D25) 단위 테스트.
 *
 * D25 의 두 계약을 함께 고정한다 — ① 코어 업데이트가 루트 보호 파일을 함께 갱신한다
 * ② 운영자가 블록 밖에 추가한 규칙은 보존된다. 증분(3-way) 판정의 base 는 현재 디스크
 * 스냅샷이라 운영자 수정본이 언제나 changed 로 분류되므로, 보존은 이 갱신기의 블록
 * 교체(splice)가 유일한 방어선이다.
 *
 * @scenario destination_state=absent, source_state=with_block, block_delta=same
 */
class RootHtaccessUpdaterTest extends TestCase
{
    /**
     * 검사 대상 갱신기.
     *
     * 케이스 표는 **마커 문법과 무관**하다 — 갱신기의 판정(마커 없음·두 벌·손상·CRLF·읽기/쓰기
     * 실패·링크 대상·부분 쓰기)은 공통 추상화가 소유하고, 자식은 마커 문자열만 다르다. 그래서
     * 형제 갱신기(`web.config`)의 테스트는 이 클래스를 상속해 이 상수만 바꾼다 — 케이스 표를
     * 사본으로 두면 한쪽에만 들어간 보강이 다른 쪽에서 조용히 빠진다 (G3 회차 16 W2 C-1).
     *
     * @var class-string<AbstractRootMarkerFileUpdater>
     */
    protected const UPDATER = RootHtaccessUpdater::class;

    protected string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir().'/g7-root-htaccess-'.bin2hex(random_bytes(4));
        mkdir($this->workDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->workDir);

        parent::tearDown();
    }

    /**
     * 마커 블록을 가진 판본을 생성합니다.
     */
    protected function coreFile(string $ruleLine): string
    {
        return (static::UPDATER)::BEGIN_MARKER."\n"
            ."# core managed\n"
            .$ruleLine."\n"
            .(static::UPDATER)::END_MARKER."\n";
    }

    public function test_every_failure_result_reaches_the_operator_message(): void
    {
        // 결과 코드는 재는데 그 코드의 **문구 도달**은 일부만 잰 상태였다 — `failureMessage()`
        // 의 두 arm 을 `null` 로 바꿔도 초록이라, 그 실패에서는 콘솔에 아무것도 뜨지 않는
        // 회귀가 조용히 들어올 수 있었다.
        //
        // 목록은 **도출한다** — 손으로 열거하면 실패 코드가 하나 늘 때 그 코드만 조용히
        // 이 검사 밖에 남는다(그 형태가 실제로 이 저장소의 반복 결함이다). 종전에는 이
        // 순회의 모집단(`FAILURE_RESULTS`)이 바로 그 손 열거였다 — 검사 자신이 같은 결함을
        // 안고 있었으므로, 새 실패 코드는 등재를 잊는 순간 이 검사 밖으로 사라졌다.
        $this->assertNotEmpty((static::UPDATER)::failureResults());

        foreach ((static::UPDATER)::failureResults() as $result) {
            $this->assertTrue((static::UPDATER)::isFailure($result), $result.' 는 실패로 분류되어야 한다');

            $message = (static::UPDATER)::failureMessage($result, '/srv/site/.htaccess');

            $this->assertNotNull(
                $message,
                $result.' 의 사유가 문구로 도달하지 않으면 그 실패는 로그 파일에만 남는다'
            );

            // `assertNotNull` 만으로는 **아무것도 막지 못한다.** 알려진 arm 을 전부 지워도
            // `default` arm 이 「알 수 없는 사유」 범용 문구를 돌려주므로 그 단언은 통과한다
            // — 운영자는 사유가 사라진 것을 모른 채 범용 문구만 받는다. 그래서 각 결과가
            // **자기 전용 문구**를 갖는지(= 범용 문구와 다른지)를 재는 것이 이 축의 실체다.
            $generic = (string) __('settings.core_update.root_htaccess_unrecognized_failure', [
                'result' => $result,
                'path' => '/srv/site/.htaccess',
            ]);

            $this->assertNotSame(
                $generic,
                $message,
                $result.' 가 전용 사유 문구를 갖지 않는다 — `default` arm 의 범용 문구로 떨어지면 운영자는 무엇이 실패했는지 알 수 없다'
            );
        }
    }

    /**
     * `RESULT_*` 상수 **전수**가 성공 목록 아니면 실패 목록으로 정확히 갈린다.
     *
     * 위 순회는 「실패로 분류된 것」만 본다. 그것만으로는 새 결과 코드가 **어느 쪽에도
     * 등재되지 않은 채** 남는 형태를 잡지 못한다 — 종전 구조에서 그 코드는 실패 목록에
     * 없으니 `isFailure()` 가 false 를 주고, `noticeMessage()` 의 `default => null` 을 타고
     * 소비처가 그 null 을 버려 **콘솔에도 업데이트 기록에도 남지 않았다.** 예외도 경고도
     * 없이 통보만 사라지는 형태라, 목록 안만 순회하는 검사로는 영원히 red 가 되지 않는다.
     *
     * 그래서 모집단을 리플렉션으로 도출해 두 목록의 합집합과 **정확히 일치**함을 잠근다.
     * 새 `RESULT_*` 는 성공 목록에 명시적으로 넣거나(그러면 위 문구 검사 대상에서 빠짐)
     * 실패 문구를 갖추거나 둘 중 하나를 해야 하고, 아무것도 하지 않으면 이 검사가 red 다.
     *
     * @effects root_htaccess_result_codes_are_partitioned_not_hand_enumerated
     */
    public function test_every_result_constant_is_classified_as_success_or_failure(): void
    {
        $declared = [];
        foreach ((new \ReflectionClass(static::UPDATER))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'RESULT_') && is_string($value)) {
                $declared[] = $value;
            }
        }

        $this->assertNotEmpty($declared, 'RESULT_* 상수를 하나도 찾지 못했다 — 이름 규약이 바뀌면 이 검사가 통째로 무력해진다');

        $classified = array_merge((static::UPDATER)::SUCCESS_RESULTS, (static::UPDATER)::failureResults());

        sort($declared);
        sort($classified);

        $this->assertSame(
            $declared,
            $classified,
            '어느 쪽에도 등재되지 않은 결과 코드가 있으면 그 결과는 통보 통로에서 조용히 사라진다'
        );

        // 두 목록은 겹치지 않는다 — 겹치면 같은 코드가 실패이면서 성공이 되어 판정이 갈린다.
        $this->assertSame(
            [],
            array_values(array_intersect((static::UPDATER)::SUCCESS_RESULTS, (static::UPDATER)::failureResults())),
            '성공 목록과 실패 목록이 겹친다'
        );

        // 위 두 단언은 **정의상 언제나 참**이다 — `failureResults()` 가 「모든 RESULT_* 에서
        // SUCCESS_RESULTS 를 뺀 것」으로 도출되므로 합집합은 항등이고 교집합은 공집합이다.
        // 도출이 fail-loud 인 것은 옳지만, 그것을 **재는** 축은 따로 있어야 한다: 새 결과
        // 코드는 성공 목록에 들어가거나 자기 전용 통보 문구를 갖거나 둘 중 하나여야 한다.
        foreach ($declared as $result) {
            if (in_array($result, (static::UPDATER)::SUCCESS_RESULTS, true)) {
                continue;
            }

            $generic = (string) __('settings.core_update.root_htaccess_unrecognized_failure', [
                'result' => $result,
                'path' => '/srv/site/.htaccess',
            ]);

            $this->assertNotSame(
                $generic,
                (static::UPDATER)::failureMessage($result, '/srv/site/.htaccess'),
                $result.' 가 성공 목록에도 없고 전용 사유 문구도 없다 — 등재를 잊은 새 결과 코드다'
            );
        }

        // 등재되지 않은 값은 **실패로 떨어진다**(fail-loud) — 그리고 그 실패도 문구를 갖는다.
        $this->assertTrue(
            (static::UPDATER)::isFailure('some_result_added_later'),
            '알지 못하는 결과가 「실패 아님」으로 떨어지면 등재를 잊은 코드가 조용히 통과한다'
        );
        $this->assertNotNull(
            (static::UPDATER)::failureMessage('some_result_added_later', '/srv/site/.htaccess'),
            '알지 못하는 실패도 사유를 실어야 한다 — null 이면 콘솔에도 기록에도 남지 않는다'
        );
    }

    /**
     * 읽지 못한 것을 「운영자가 지웠다」로 보고하지 않는다.
     *
     * 무가드 읽기는 두 방향으로 깨진다 — 에러 핸들러가 있는 문맥에서는 경고가 예외로
     * 승격돼 `RESULT_*` 계약을 뚫고 나가 업데이트 전체가 롤백되고, 없는 문맥에서는
     * `false` 가 빈 문자열이 되어 `RESULT_PRESERVED`(= 보존이 정상) 라는 사실 아닌
     * 판정이 나간다. 후자가 더 나쁘다 — 콘솔에는 「적용 완료」만 뜬다.
     *
     * 대상이 **파일이긴 한데 읽을 수 없는** 상태를 만들어야 이 경로에 닿는다 — 디렉토리는
     * 앞선 `is_file()` 가드가 먼저 잡는다. 그 상태는 POSIX 퍼미션으로만 만들 수 있어
     * Windows 에서는 skip 한다(형제 선례: 링크 종류별 케이스).
     *
     * @effects root_htaccess_read_failure_is_reported_not_mistaken_for_preserved
     */
    public function test_unreadable_destination_is_reported_as_read_failure(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('읽기 불가 파일은 POSIX 퍼미션으로만 만들 수 있다');
        }

        $src = $this->workDir.'/src.htaccess';
        $dest = $this->workDir.'/dest.htaccess';

        file_put_contents($src, $this->coreFile('RewriteRule ^ public/ [L]'));
        file_put_contents($dest, $this->coreFile('RewriteRule ^ public/ [OLD]'));
        $before = (string) file_get_contents($dest);

        chmod($dest, 0000);

        try {
            $result = (static::UPDATER)::apply($src, $dest);
        } finally {
            chmod($dest, 0644);
        }

        $this->assertSame(
            (static::UPDATER)::RESULT_READ_FAILED,
            $result,
            '읽지 못한 대상을 preserved 로 보고하면 「운영자가 지웠다」로 오인되어 보호 규칙이 영영 적용되지 않는다'
        );
        $this->assertTrue((static::UPDATER)::isFailure($result), '읽기 실패는 사람의 조치가 필요한 결과다');
        $this->assertSame($before, (string) file_get_contents($dest), '읽기 실패는 대상을 건드리지 않아야 한다');
    }

    /**
     * 소스를 읽지 못하는 경우도 같은 결과로 수렴한다 (형제 축).
     *
     * @effects root_htaccess_read_failure_is_reported_not_mistaken_for_preserved
     */
    public function test_unreadable_source_is_reported_as_read_failure(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('읽기 불가 파일은 POSIX 퍼미션으로만 만들 수 있다');
        }

        $src = $this->workDir.'/src.htaccess';
        $dest = $this->workDir.'/dest.htaccess';

        file_put_contents($src, $this->coreFile('RewriteRule ^ public/ [L]'));
        file_put_contents($dest, $this->coreFile('RewriteRule ^ public/ [OLD]'));

        chmod($src, 0000);

        try {
            $result = (static::UPDATER)::apply($src, $dest);
        } finally {
            chmod($src, 0644);
        }

        $this->assertSame((static::UPDATER)::RESULT_READ_FAILED, $result);
    }

    public function test_missing_destination_is_created_from_source(): void
    {
        $src = $this->workDir.'/src.htaccess';
        $dest = $this->workDir.'/dest.htaccess';
        file_put_contents($src, $this->coreFile('RewriteRule ^ public/ [L]'));

        // 「새로 만들었다」는 「블록을 교체했다」와 다른 결과다 — 그 파일이 없던 사이트는
        // 프로젝트 루트가 웹에 열려 있던 배치일 수 있고, 이 파일이 생기는 순간 `/public/…`
        // 주소가 정본 주소로 301 되며 위임이 시작된다. 보호는 개선되지만 **주소 체계가
        // 바뀌는 변화**라 운영자에게 도달해야 한다.
        $this->assertSame((static::UPDATER)::RESULT_CREATED, (static::UPDATER)::apply($src, $dest));
        $this->assertFileExists($dest);
        $this->assertSame(file_get_contents($src), file_get_contents($dest));

        $notice = (static::UPDATER)::noticeMessage((static::UPDATER)::RESULT_CREATED, $dest);
        $this->assertNotNull($notice, '새로 만든 사실이 통보 통로에 실리지 않으면 그 변화는 로그 파일에만 남는다');
        $this->assertFalse((static::UPDATER)::isFailure((static::UPDATER)::RESULT_CREATED), '신규 생성은 실패가 아니다');
    }

    /**
     * 신규 생성 경로의 **결과 검증**이 실제로 걸린다.
     *
     * 종전 판정은 「블록 수가 1인가」만 봤고, 그 검사로는 두 축이 통과한다:
     *   ① 부재 판정과 복사 사이에 운영자가 파일을 올린 경우 — `File::copy()` 가 무조건
     *      덮어쓰므로 결과는 소스 그대로이고 블록 수는 정확히 1 이다(이 축은 지금도
     *      탐지되지 않으며, 구현 주석이 그 사실을 밝힌다).
     *   ② 소스와 다른 내용이 목적지에 남은 경우 — 부분 복사·중복 블록.
     *
     * 이 테스트는 **②를 신규 생성 분기 안에서** 재현한다. 목적지를 미리 만들어 두면
     * `apply()` 가 교체 경로로 갈라져 이 검증을 한 줄도 지나지 않으므로(그 형태의
     * 종전 판본은 어떤 구현에서도 통과하는 장식이었다), 목적지는 **없는 채로** 두고
     * 소스 쪽을 관리 블록 두 벌로 만든다.
     *
     * @scenario copy_outcome=block_count_mismatch, destination_state=absent
     *
     * @effects root_htaccess_creation_reports_only_a_complete_copy
     */
    public function test_creation_result_is_verified_not_assumed(): void
    {
        $src = $this->workDir.'/src-dup.htaccess';
        $dest = $this->workDir.'/dest-dup.htaccess';

        $one = $this->coreFile('RewriteRule ^ public/ [L]');
        file_put_contents($src, $one."\n".$one);

        $this->assertFileDoesNotExist($dest, '목적지가 있으면 신규 생성 분기에 들어가지 않는다');

        $result = (static::UPDATER)::apply($src, $dest);

        $this->assertSame(
            (static::UPDATER)::RESULT_WRITE_FAILED,
            $result,
            '신규 생성 결과를 검증하지 않고 성공으로 보고했다'
        );
        $this->assertTrue(
            (static::UPDATER)::isFailure($result),
            '검증에 걸린 결과가 실패로 분류되지 않는다 — 호출부가 그대로 진행한다'
        );
    }

    /**
     * 대조군 — 정상 소스는 신규 생성으로 통과한다.
     *
     * 이 대조군이 없으면 「무엇을 넣어도 실패로 보고하는」 구현으로도 위 테스트가
     * 초록이 되고, 레이아웃 ②로 전환하는 첫 업데이트가 통째로 막히는 기능 축소를
     * 아무도 잡지 못한다.
     *
     * @scenario copy_outcome=clean, destination_state=absent
     *
     * @effects root_htaccess_creation_reports_only_a_complete_copy
     */
    public function test_clean_source_still_creates(): void
    {
        $src = $this->workDir.'/src-clean.htaccess';
        $dest = $this->workDir.'/dest-clean.htaccess';
        file_put_contents($src, $this->coreFile('RewriteRule ^ public/ [L]'));

        $this->assertSame((static::UPDATER)::RESULT_CREATED, (static::UPDATER)::apply($src, $dest));
        $this->assertSame(file_get_contents($src), file_get_contents($dest));
    }

    /**
     * 신규 복사가 실패하면 「갱신했다」가 아니라 **실패**로 보고된다 (4차 G3).
     *
     * 종전에는 대상 부재 분기가 `FilePermissionHelper::copyFile()`(반환 `void`)을 부르고
     * 결과를 전혀 보지 않은 채 무조건 `RESULT_UPDATED` 를 돌려줬다. 실제 실패는 조용하지
     * 않고 **예외로 터졌지만**, 그 예외를 여기서 잡지 않아 코어 업데이트 실행 전체가 그
     * 자리에서 멈췄다 — 다른 코어 파일은 이미 적용된 뒤다. 이 파일의 갱신 실패는 3차
     * 라운드에서 PO 가 「업데이트는 계속하고 경고로 표시」로 정한 축이므로, 형제 실패
     * (쓰기 실패·중복 블록)와 같은 형태로 보고하고 흐름을 잇는다.
     *
     * 가장 위험한 조합이 바로 이 분기다 — 레이아웃 ②로 전환하는 첫 업데이트(보호 규칙
     * 파일이 처음 도달하는 그 순간) + 프로젝트 루트가 웹 프로세스에 쓰기 불가(FTP 업로드로
     * 소유자 상이). 그 사이트는 보호 규칙 파일이 없는 상태로 `.env` 가 열려 있다.
     *
     * @effects root_htaccess_creation_failure_is_reported_not_claimed_as_updated
     */
    public function test_failed_creation_of_missing_destination_is_reported_as_failure(): void
    {
        $src = $this->workDir.'/src.htaccess';
        file_put_contents($src, $this->coreFile('RewriteRule ^ public/ [L]'));

        // 대상의 부모 자리를 **파일**로 막는다 — 디렉토리를 만들 수 없어 복사가 성립하지
        // 않는다. 플랫폼 무관하게 재현된다(권한 조작은 Windows 에서 재현되지 않고, 단순히
        // 없는 디렉토리는 `copyFile` 이 스스로 만들어 버린다).
        $blocker = $this->workDir.'/blocker';
        file_put_contents($blocker, 'not a directory');
        $dest = $blocker.'/dest.htaccess';

        $this->assertSame(
            (static::UPDATER)::RESULT_WRITE_FAILED,
            (static::UPDATER)::apply($src, $dest),
            '파일이 만들어지지 않았는데 「갱신했다」로 보고하면 운영자가 노출 상태를 알 방법이 없다'
        );
        $this->assertTrue((static::UPDATER)::isFailure((static::UPDATER)::RESULT_WRITE_FAILED));
        $this->assertFileDoesNotExist($dest);
    }

    /**
     * @effects root_htaccess_updates_replace_only_core_block
     */
    public function test_operator_rules_outside_block_survive_core_block_change(): void
    {
        $src = $this->workDir.'/src.htaccess';
        $dest = $this->workDir.'/dest.htaccess';

        file_put_contents($src, $this->coreFile('RewriteRule ^ public/$1 [L] # v2'));
        file_put_contents(
            $dest,
            $this->coreFile('RewriteRule ^ public/ [L] # v1')
            ."\n# operator: custom block\nDeny from 10.0.0.0/8\n"
        );

        $this->assertSame((static::UPDATER)::RESULT_UPDATED, (static::UPDATER)::apply($src, $dest));

        $result = (string) file_get_contents($dest);
        $this->assertStringContainsString('# v2', $result, '코어 블록이 신버전으로 교체되어야 한다');
        $this->assertStringNotContainsString('# v1', $result, '구버전 블록 내용이 남으면 안 된다');
        $this->assertStringContainsString('Deny from 10.0.0.0/8', $result, '블록 밖 운영자 규칙은 보존되어야 한다 (D25)');
    }

    /**
     * @effects root_htaccess_updates_replace_only_core_block
     */
    public function test_operator_rules_before_block_survive_too(): void
    {
        $src = $this->workDir.'/src.htaccess';
        $dest = $this->workDir.'/dest.htaccess';

        file_put_contents($src, $this->coreFile('RewriteRule ^ public/$1 [L] # v2'));
        file_put_contents($dest, "# operator: leading rule\nSetEnv G7 1\n".$this->coreFile('RewriteRule old # v1'));

        $this->assertSame((static::UPDATER)::RESULT_UPDATED, (static::UPDATER)::apply($src, $dest));

        $result = (string) file_get_contents($dest);
        $this->assertStringStartsWith("# operator: leading rule\nSetEnv G7 1\n", $result);
        $this->assertStringContainsString('# v2', $result);
    }

    /**
     * @effects root_htaccess_without_marker_is_preserved
     */
    public function test_destination_without_markers_is_left_untouched(): void
    {
        $src = $this->workDir.'/src.htaccess';
        $dest = $this->workDir.'/dest.htaccess';

        file_put_contents($src, $this->coreFile('RewriteRule ^ public/$1 [L]'));
        $operatorOnly = "# operator removed the G7 block on purpose\nDeny from all\n";
        file_put_contents($dest, $operatorOnly);

        $this->assertSame((static::UPDATER)::RESULT_PRESERVED, (static::UPDATER)::apply($src, $dest));
        $this->assertFalse(
            (static::UPDATER)::isFailure((static::UPDATER)::RESULT_PRESERVED),
            '운영자 의도 존중은 실패가 아니다 — 실패와 섞으면 운영자에게 거짓 경고가 간다'
        );
        $this->assertSame($operatorOnly, file_get_contents($dest), '마커를 지운 파일은 운영자 의도로 보고 건드리지 않는다');
    }

    /**
     * BEGIN 만 남은 파일은 **손상**이지 「운영자 제거」가 아닙니다.
     *
     * 종전에는 `blockRange()` 가 null 을 주면 전부 `preserved` 로 접었다 — 그 안내는
     * 「일부러 지운 것이 아니라면 릴리즈 판본을 두라」는 문구인데, 마커 하나가 **남아 있는**
     * 파일은 지운 것이 아니라 **잘린** 것이다(전송 중단·부분 쓰기·손편집). 그 파일은 Apache
     * 가 닫히지 않은 `<IfModule>` 로 500 을 내거나 위임 규칙이 없어 프로젝트 루트가 열린
     * 상태인데, 안내가 원인을 가려 운영자가 「지웠다」의 반대 사실을 확인하는 데 시간을
     * 쓴다. 손상은 실패로 분류해 그 사유를 그대로 싣는다 (13차 G3 라운드 W4-2).
     *
     * @effects root_htaccess_broken_block_is_reported_not_mistaken_for_preserved
     */
    public function test_begin_only_block_is_reported_as_broken_not_preserved(): void
    {
        $src = $this->workDir.'/src.htaccess';
        $dest = $this->workDir.'/dest.htaccess';

        file_put_contents($src, $this->coreFile('RewriteRule ^ public/$1 [L]'));
        $truncated = (static::UPDATER)::BEGIN_MARKER."\n# core managed\n<IfModule mod_rewrite.c>\n";
        file_put_contents($dest, $truncated);

        $result = (static::UPDATER)::apply($src, $dest);

        $this->assertSame((static::UPDATER)::RESULT_BROKEN_BLOCK, $result, 'BEGIN 만 남은 파일을 「운영자 제거」로 분류하면 안내가 원인을 가린다');
        $this->assertTrue((static::UPDATER)::isFailure($result), '손상은 사람의 조치가 필요한 실패다');
        $this->assertSame($truncated, file_get_contents($dest), '손상 파일은 코어가 추측해 고치지 않는다 — 운영자에게 넘긴다');

        $message = (static::UPDATER)::failureMessage($result, $dest);
        $this->assertNotNull($message);
        $this->assertStringContainsString($dest, (string) $message);
        $this->assertNull((static::UPDATER)::noticeMessage($result, $dest), '실패는 notice 통로가 아니라 failure 통로로 나간다');
    }

    /**
     * END 만 남은 파일도 같은 분류입니다 — 앞부분이 잘린 형태.
     *
     * @effects root_htaccess_broken_block_is_reported_not_mistaken_for_preserved
     */
    public function test_end_only_block_is_reported_as_broken_not_preserved(): void
    {
        $src = $this->workDir.'/src.htaccess';
        $dest = $this->workDir.'/dest.htaccess';

        file_put_contents($src, $this->coreFile('RewriteRule ^ public/$1 [L]'));
        $truncated = "</IfModule>\n".(static::UPDATER)::END_MARKER."\n# operator tail\n";
        file_put_contents($dest, $truncated);

        $result = (static::UPDATER)::apply($src, $dest);

        $this->assertSame((static::UPDATER)::RESULT_BROKEN_BLOCK, $result, 'END 만 남은 파일을 「운영자 제거」로 분류하면 안내가 원인을 가린다');
        $this->assertTrue((static::UPDATER)::isFailure($result));
        $this->assertSame($truncated, file_get_contents($dest));
    }

    /**
     * 역순(END 가 BEGIN 앞)도 손상입니다 — 두 마커가 다 있는데 블록이 서지 않는 형태.
     *
     * @effects root_htaccess_broken_block_is_reported_not_mistaken_for_preserved
     */
    public function test_reversed_markers_are_reported_as_broken_not_preserved(): void
    {
        $src = $this->workDir.'/src.htaccess';
        $dest = $this->workDir.'/dest.htaccess';

        file_put_contents($src, $this->coreFile('RewriteRule ^ public/$1 [L]'));
        $reversed = (static::UPDATER)::END_MARKER."\n# swapped\n".(static::UPDATER)::BEGIN_MARKER."\n";
        file_put_contents($dest, $reversed);

        $this->assertSame((static::UPDATER)::RESULT_BROKEN_BLOCK, (static::UPDATER)::apply($src, $dest));
        $this->assertSame($reversed, file_get_contents($dest));
    }

    /**
     * CR 단독(`\r`) 줄바꿈 파일에서도 블록을 찾아 교체합니다.
     *
     * 줄머리 판정이 `\n` 만 보면 CR-only 파일의 마커는 「줄 시작이 아니다」로 읽혀 블록을
     * 영영 찾지 못하고, 그 결과는 `preserved`(= 운영자가 지웠다)라는 사실 아닌 안내다.
     * 교체된 블록의 줄바꿈은 대상 파일의 것(CR)을 따라야 한 파일 안에서 갈리지 않는다.
     *
     * @effects root_htaccess_write_is_atomic_and_line_endings_follow_destination
     */
    public function test_cr_only_destination_is_recognized_and_updated(): void
    {
        $src = $this->workDir.'/src.htaccess';
        $dest = $this->workDir.'/dest.htaccess';

        file_put_contents($src, $this->coreFile('# v2'));
        $crOnly = "# operator head\r".(static::UPDATER)::BEGIN_MARKER."\r# v1\r".(static::UPDATER)::END_MARKER."\r# operator tail\r";
        file_put_contents($dest, $crOnly);

        $this->assertSame((static::UPDATER)::RESULT_UPDATED, (static::UPDATER)::apply($src, $dest));

        $result = (string) file_get_contents($dest);
        $this->assertStringStartsWith("# operator head\r".(static::UPDATER)::BEGIN_MARKER."\r", $result);
        $this->assertStringEndsWith((static::UPDATER)::END_MARKER."\r# operator tail\r", $result);
        $this->assertStringContainsString("# v2\r", $result);
        $this->assertStringNotContainsString("\n", $result, 'CR-only 파일에 LF 가 섞이면 한 파일 안에서 줄바꿈이 갈린다');
        $this->assertStringNotContainsString('# v1', $result);
    }

    /**
     * 「손대지 않았다」는 조용해서는 안 됩니다 (5차 G3 · 제품 결정).
     *
     * 마커가 없는 파일은 둘 중 하나다 — ① 운영자가 일부러 G7 블록을 지웠다(존중이 맞다)
     * ② 예전 사이트의 `.htaccess` 가 그대로 있거나 전송 중단으로 빈 파일이 됐다. ②에서는
     * **코어 보호 규칙이 영영 적용되지 않는데** 콘솔에는 「적용 완료」만 뜬다 — 문서 루트가
     * 프로젝트 루트인 배치에서 그것은 `.env` 노출이다.
     *
     * 동작(파일을 건드리지 않는다)은 그대로 두고, 그 사실만 운영자에게 알린다.
     */
    public function test_preserved_result_carries_an_operator_notice(): void
    {
        $this->assertFalse(
            (static::UPDATER)::isFailure((static::UPDATER)::RESULT_PRESERVED),
            '동작은 그대로다 — 실패로 올리면 일부러 블록을 지운 운영자가 매번 실패를 본다'
        );

        $notice = (static::UPDATER)::noticeMessage((static::UPDATER)::RESULT_PRESERVED, '/srv/site/.htaccess');

        $this->assertNotNull($notice, '마커 없는 파일은 코어 규칙이 미적용으로 남는다 — 그 사실이 통보되어야 한다');
        $this->assertStringContainsString('/srv/site/.htaccess', $notice);

        $this->assertNull(
            (static::UPDATER)::noticeMessage((static::UPDATER)::RESULT_UPDATED, '/srv/site/.htaccess'),
            '정상 갱신에는 알릴 것이 없다'
        );
        $this->assertNull(
            (static::UPDATER)::noticeMessage((static::UPDATER)::RESULT_UNCHANGED, '/srv/site/.htaccess'),
            '내용이 같아 쓰지 않은 것은 알릴 것이 없다'
        );
    }

    /**
     * 대상이 심볼릭 링크면 링크를 끊지 않고 **링크 대상에** 씁니다 (5차 G3).
     *
     * 원자적 교체(`rename`)는 링크를 일반 파일로 갈아치운다. 운영자가 서버 설정을 한곳에서
     * 관리하려고 `.htaccess` 를 링크로 묶어 두면 그 구성이 조용히 해체되고, 이후 원본을
     * 고쳐도 사이트에 반영되지 않는다(결과는 `updated`, 경고 없음). 형제 파일인 루트
     * `index.php` 는 `copy()` 경로라 링크를 통과해 대상에 쓴다 — 두 파일이 정반대였다.
     */
    public function test_symlinked_destination_is_written_through_not_replaced(): void
    {
        $src = $this->workDir.'/src.htaccess';
        $realFile = $this->workDir.'/real.htaccess';
        $link = $this->workDir.'/linked.htaccess';

        file_put_contents($src, $this->coreFile('# v2'));
        file_put_contents($realFile, $this->coreFile('# v1')."# operator tail\n");

        if (! @symlink($realFile, $link)) {
            $this->markTestSkipped('symlink 생성 실패 (Windows SeCreateSymbolicLink 권한 부족 가능)');
        }

        $this->assertSame((static::UPDATER)::RESULT_UPDATED, (static::UPDATER)::apply($src, $link));

        $this->assertTrue(is_link($link), '링크가 일반 파일로 교체되면 운영자의 설정 관리 구성이 해체된다');
        $this->assertStringContainsString('# v2', (string) file_get_contents($realFile), '링크 대상에 새 블록이 반영되어야 한다');
        $this->assertStringContainsString('# operator tail', (string) file_get_contents($realFile), '블록 밖은 그대로다');
    }

    /**
     * END 마커가 두 벌이어도 중복으로 판정합니다 (5차 G3).
     *
     * BEGIN 만 세면 `BEGIN 1 + END 2` 가 「블록 하나」로 통과하는데, 범위 계산은 첫 END 까지만
     * 잡으므로 교체 후 구버전 규칙 꼬리와 고아 `# END G7` 이 블록 **밖**에 남고 결과는
     * `updated` 다. Apache 는 뒤에 오는 지시자가 이기는 경우가 많아, 3차가 막으려던
     * 「구버전 규칙 생존 + 성공 보고」가 이 형태로 재현된다.
     *
     * @effects root_htaccess_duplicate_blocks_are_refused
     */
    public function test_duplicate_end_marker_is_also_treated_as_duplicate(): void
    {
        $src = $this->workDir.'/src.htaccess';
        $dest = $this->workDir.'/dest.htaccess';

        file_put_contents($src, $this->coreFile('# v2'));

        $damaged = (static::UPDATER)::BEGIN_MARKER."\n# v1\n".(static::UPDATER)::END_MARKER."\n# leftover old rule\n".(static::UPDATER)::END_MARKER."\n";
        file_put_contents($dest, $damaged);

        $this->assertSame((static::UPDATER)::RESULT_DUPLICATE_BLOCK, (static::UPDATER)::apply($src, $dest));
        $this->assertSame($damaged, file_get_contents($dest), '중복이면 코어가 추측하지 않고 그대로 둔다');
        $this->assertTrue((static::UPDATER)::isFailure((static::UPDATER)::RESULT_DUPLICATE_BLOCK));
    }

    /**
     * 줄바꿈 판정 표본은 **블록 밖**입니다 (5차 G3).
     *
     * 파일 전체를 세면 ~100행짜리 LF 코어 블록이 표본을 지배해, 운영자가 CRLF 로 몇 줄
     * 덧붙인 파일에서도 LF 가 이긴다 — 한 파일 안에서 줄바꿈이 갈린 채로 남으며, 그것이
     * 3차 L9 가 닫으려던 상태다.
     */
    public function test_line_ending_sample_excludes_the_core_block(): void
    {
        $src = $this->workDir.'/src.htaccess';
        $dest = $this->workDir.'/dest.htaccess';

        // 소스(릴리즈 판본)는 LF 이고 줄 수가 많다.
        file_put_contents($src, $this->coreFile(implode("\n", array_fill(0, 40, '# core line'))));

        // 대상은 코어 블록(LF, 40행 이상) + 운영자 줄 3행(CRLF).
        $operatorTail = "# operator a\r\n# operator b\r\n# operator c\r\n";
        file_put_contents($dest, $this->coreFile(implode("\n", array_fill(0, 40, '# old line'))).$operatorTail);

        $this->assertSame((static::UPDATER)::RESULT_UPDATED, (static::UPDATER)::apply($src, $dest));

        $written = (string) file_get_contents($dest);
        $this->assertStringContainsString((static::UPDATER)::BEGIN_MARKER."\r\n", $written, '블록 밖이 CRLF 이면 새 블록도 CRLF 여야 한다');
        $this->assertStringContainsString($operatorTail, $written, '운영자 줄은 그대로 보존된다');

        // END 마커 줄의 **종결자**도 블록의 일부다 (G3 회차 15). 종전 범위 계산은 END 줄의
        // 종결자를 블록 밖에 남겨, LF 코어 블록 + CRLF 운영자 꼬리인 파일에서 새 블록은 CRLF 로
        // 맞췄는데 `# END G7` 뒤에는 옛 LF 하나가 그대로 남았다 — 「줄바꿈을 대상에 맞춘다」는
        // 선언과 결과가 한 줄에서 어긋난다.
        $this->assertSame(
            0,
            preg_match('/(?<!\r)\n/', $written),
            '홀로 남은 LF 가 있다 — END 마커 줄의 종결자가 블록과 함께 변환되지 않았다'
        );
    }

    /**
     * 임시 파일은 **링크 대상의 디렉토리**에 만듭니다 (G3 회차 15).
     *
     * 원자적 교체는 임시 파일을 만든 뒤 `rename` 한다. 대상이 링크면 링크 **대상**에 쓰는데
     * (위 형제 테스트), 임시 파일은 링크가 놓인 디렉토리에 만들고 있었다 — 링크 대상이 다른
     * 파일시스템(`/etc/g7/htaccess` 처럼 별도 마운트)에 있으면 `rename` 이 EXDEV 로 실패해
     * `write_failed` 가 된다. 같은 이유로 잔해 스윕도 그 디렉토리를 봐야 한다.
     *
     * `rename` 자체의 교차 장치 실패는 단위 테스트로 재현할 수 없으므로 **잔해 스윕이 보는
     * 자리**로 판정한다 — 임시 파일 생성 규칙과 스윕 규칙은 같은 접두사를 쓰므로, 링크 대상
     * 옆의 오래된 임시 파일이 걷히면 임시 파일도 그 자리에 만들어진다.
     */
    public function test_temp_file_lives_next_to_the_link_target_not_the_link(): void
    {
        $src = $this->workDir.'/src.htaccess';
        $realDir = $this->workDir.'/real';
        $linkDir = $this->workDir.'/linked';
        mkdir($realDir);
        mkdir($linkDir);
        $realFile = $realDir.'/htaccess';
        $link = $linkDir.'/.htaccess';

        file_put_contents($src, $this->coreFile('# v2'));
        file_put_contents($realFile, $this->coreFile('# v1'));

        if (! @symlink($realFile, $link)) {
            $this->markTestSkipped('symlink 생성 실패 (Windows SeCreateSymbolicLink 권한 부족 가능)');
        }

        $staleNextToTarget = $realFile.(static::UPDATER)::TEMP_INFIX.'0123456789abcdef';
        $staleNextToLink = $link.(static::UPDATER)::TEMP_INFIX.'0123456789abcdef';
        file_put_contents($staleNextToTarget, 'stale');
        file_put_contents($staleNextToLink, 'stale');
        touch($staleNextToTarget, time() - 86400);
        touch($staleNextToLink, time() - 86400);

        try {
            $this->assertSame((static::UPDATER)::RESULT_UPDATED, (static::UPDATER)::apply($src, $link));

            $this->assertFileDoesNotExist(
                $staleNextToTarget,
                '링크 대상 옆의 잔해가 남았다 — 임시 파일·스윕이 링크가 놓인 디렉토리를 보고 있다 (교차 파일시스템에서 rename 이 실패한다)'
            );
            $this->assertStringContainsString('# v2', (string) file_get_contents($realFile));
            $this->assertTrue(is_link($link));
        } finally {
            @unlink($staleNextToTarget);
            @unlink($staleNextToLink);
            @unlink($link);
            @unlink($realFile);
            @rmdir($realDir);
            @rmdir($linkDir);
        }
    }

    public function test_identical_block_skips_write(): void
    {
        $src = $this->workDir.'/src.htaccess';
        $dest = $this->workDir.'/dest.htaccess';

        $core = $this->coreFile('RewriteRule ^ public/$1 [L]');
        file_put_contents($src, $core);
        file_put_contents($dest, $core."\n# operator tail\n");

        $this->assertSame(
            (static::UPDATER)::RESULT_UNCHANGED,
            (static::UPDATER)::apply($src, $dest),
            '블록이 동일하면 쓰지 않는다 (mtime 보존)'
        );
        $this->assertFalse(
            (static::UPDATER)::isFailure((static::UPDATER)::RESULT_UNCHANGED),
            '「변경 없음」과 「쓰기 실패」가 같은 값이면 호출부가 실패를 가려낼 수 없다 (3차 H4)'
        );
        $this->assertSame($core."\n# operator tail\n", file_get_contents($dest));
    }

    public function test_markerless_source_is_not_applied(): void
    {
        $src = $this->workDir.'/src.htaccess';
        $dest = $this->workDir.'/dest.htaccess';

        file_put_contents($src, "# broken release artifact without markers\n");
        $destContent = $this->coreFile('RewriteRule ^ public/$1 [L]');
        file_put_contents($dest, $destContent);

        $this->assertSame((static::UPDATER)::RESULT_SOURCE_INVALID, (static::UPDATER)::apply($src, $dest));
        $this->assertSame($destContent, file_get_contents($dest), '마커 없는 릴리즈 산출물은 전파하지 않는다');
    }

    /**
     * 쓰기가 실패하면 「갱신했다」고 보고하지 않는다.
     *
     * 공유 호스팅에서 루트 파일만 읽기 전용인 경우가 있다. 그때 결과를 확인하지 않으면
     * 갱신기는 변경했다고 보고하고 실제로는 옛 보호 규칙이 그대로 남는다 — 다른 코어
     * 파일은 신 버전인데 진입·보호 규칙만 구버전인 상태가 로그 한 줄 없이 생긴다.
     */
    public function test_write_failure_is_reported_and_logged(): void
    {
        $src = $this->workDir.'/src.htaccess';
        $dest = $this->workDir.'/dest.htaccess';
        file_put_contents($src, $this->coreFile('RewriteRule ^ public/$1 [L] # new'));

        $destContent = $this->coreFile('RewriteRule ^ public/$1 [L] # old')."\n# operator rule\n";
        file_put_contents($dest, $destContent);

        chmod($dest, 0444);
        if (@file_put_contents($dest, 'probe') !== false) {
            file_put_contents($dest, $destContent);
            chmod($dest, 0644);
            $this->markTestSkipped('읽기 전용 파일에 쓰기가 성공하는 환경 — 쓰기 실패 경로를 재현할 수 없다');
        }

        try {
            $result = (static::UPDATER)::apply($src, $dest);
            $this->assertSame(
                (static::UPDATER)::RESULT_WRITE_FAILED,
                $result,
                '쓰기에 실패했는데 변경했다고 보고한다 — 보호 규칙이 구버전으로 남은 것을 아무도 모른다'
            );
            $this->assertTrue(
                (static::UPDATER)::isFailure($result),
                '쓰기 실패는 운영자에게 도달해야 하는 실패다'
            );
            $this->assertNotNull((static::UPDATER)::failureMessage($result, $dest));
            $this->assertSame($destContent, file_get_contents($dest), '실패했으면 내용도 그대로여야 한다');
        } finally {
            chmod($dest, 0644);
        }
    }

    /**
     * 코어 블록이 **두 벌** 있으면 갱신을 건너뛰고 실패로 보고한다 (3차 G3 라운드 M2).
     *
     * 종전에는 첫 블록만 교체하고 나머지는 그대로 뒀다. Apache 는 뒤에 오는 지시자가 이기는
     * 경우가 많아 실질적으로 **구버전 규칙이 살아남는데**, 반환값도 로그도 「갱신 성공」이었다.
     *
     * @effects root_htaccess_duplicate_blocks_are_refused
     */
    public function test_duplicate_core_blocks_are_refused_not_half_updated(): void
    {
        $src = $this->workDir.'/src.htaccess';
        $dest = $this->workDir.'/dest.htaccess';

        file_put_contents($src, $this->coreFile('RewriteRule ^ public/$1 [L] # new'));

        $duplicated = $this->coreFile('RewriteRule ^ public/$1 [L] # first')
            ."\n"
            .$this->coreFile('RewriteRule ^ public/$1 [L] # second');
        file_put_contents($dest, $duplicated);

        $result = (static::UPDATER)::apply($src, $dest);

        $this->assertSame((static::UPDATER)::RESULT_DUPLICATE_BLOCK, $result);
        $this->assertTrue((static::UPDATER)::isFailure($result));
        $this->assertSame($duplicated, file_get_contents($dest), '어느 블록을 남길지는 코어가 추측할 문제가 아니다');
        $this->assertStringNotContainsString('# new', (string) file_get_contents($dest));
    }

    /**
     * 대상이 CRLF 파일이면 삽입 블록도 CRLF 로 맞춘다 (3차 G3 라운드 L9).
     *
     * 릴리즈 판본은 항상 LF 다(.gitattributes 강제). 그대로 끼우면 한 파일 안에서 줄바꿈이
     * 갈리고, 이 저장소는 툴체인의 CRLF 사각으로 이미 한 번 사고를 겪었다.
     */
    public function test_block_line_endings_follow_the_destination_file(): void
    {
        $src = $this->workDir.'/src.htaccess';
        $dest = $this->workDir.'/dest.htaccess';

        file_put_contents($src, $this->coreFile('RewriteRule ^ public/$1 [L] # new'));

        $crlfDest = str_replace("\n", "\r\n", $this->coreFile('RewriteRule ^ public/$1 [L] # old')."\n# operator\n");
        file_put_contents($dest, $crlfDest);

        $this->assertSame((static::UPDATER)::RESULT_UPDATED, (static::UPDATER)::apply($src, $dest));

        $written = (string) file_get_contents($dest);
        $this->assertStringContainsString('# new', $written);
        $this->assertSame(
            0,
            preg_match('/(?<!\r)\n/', $written),
            'CRLF 파일에 LF 줄이 섞이면 안 된다 — 한 파일 안에서 줄바꿈이 갈린다'
        );
    }

    /**
     * BOM 이 붙은 대상 파일도 블록을 찾는다 (3차 G3 라운드 L8).
     *
     * 찾지 못하면 갱신이 **영구히** 건너뛰어지고, 로그에는 「운영자가 지웠다」는 사실이
     * 아닌 사유가 남아 다음 라운드가 그 오기록을 전제로 판단한다.
     */
    public function test_bom_prefixed_destination_is_still_updated(): void
    {
        $src = $this->workDir.'/src.htaccess';
        $dest = $this->workDir.'/dest.htaccess';

        file_put_contents($src, $this->coreFile('RewriteRule ^ public/$1 [L] # new'));
        file_put_contents($dest, "\xEF\xBB\xBF".$this->coreFile('RewriteRule ^ public/$1 [L] # old'));

        $this->assertSame((static::UPDATER)::RESULT_UPDATED, (static::UPDATER)::apply($src, $dest));

        $written = (string) file_get_contents($dest);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $written, 'BOM 은 보존한다 — 지우는 것은 별개 결정이다');
        $this->assertStringContainsString('# new', $written);
    }

    /**
     * 쓰기는 임시 파일 + rename 이라 반쪽 파일이 서빙되는 창이 없다 (3차 G3 라운드 M1).
     *
     * 직접 truncate-then-write 는 그 창에 들어온 요청에게 **보호 규칙도 위임 규칙도 없는**
     * `.htaccess` 를 보여 준다. 그 잔해는 「BEGIN 만 남은 파일」로 굳어 이후 모든 업데이트가
     * 「운영자가 지웠다」로 오판해 영구히 건너뛴다.
     */
    public function test_write_goes_through_a_temporary_file_and_leaves_no_residue(): void
    {
        $src = $this->workDir.'/src.htaccess';
        $dest = $this->workDir.'/dest.htaccess';

        file_put_contents($src, $this->coreFile('RewriteRule ^ public/$1 [L] # new'));
        file_put_contents($dest, $this->coreFile('RewriteRule ^ public/$1 [L] # old'));

        $this->assertSame((static::UPDATER)::RESULT_UPDATED, (static::UPDATER)::apply($src, $dest));

        $residue = glob($this->workDir.'/dest.htaccess.tmp.*') ?: [];
        $this->assertSame([], $residue, '임시 파일이 남으면 다음 요청이 그것을 서빙할 수 있다');

        // 쓰기 경로는 공통 추상화가 소유한다 (G3 회차 16 W2 C-1).
        $source = file_get_contents(dirname(__DIR__, 3).'/app/Extension/Helpers/AbstractRootMarkerFileUpdater.php');
        $this->assertStringContainsString(
            'rename(',
            (string) $source,
            '직접 쓰기로 되돌아가면 반쪽 파일이 서빙되는 창이 다시 열린다'
        );
        $this->assertMatchesRegularExpression(
            '/\$written !== strlen\(\$content\)/',
            (string) $source,
            '부분 쓰기를 성공으로 읽으면 잘린 .htaccess 가 그대로 확정된다 (디스크 풀)'
        );
    }

    /**
     * 마커 뒤에 **공백 + 텍스트**가 오는 줄은 마커가 아니다 (G3 회차 14 W4-2).
     *
     * 종전 판정은 마커 뒤 글자가 `\n`·`\r`·공백·탭이면 마커로 인정했다 — 「`# BEGIN G7-extra`
     * 같은 다른 마커와의 오인을 막는다」는 의도였는데, 그 판정은 `# BEGIN G7 마커를 지우지
     * 마세요` 처럼 마커 문자열로 **시작하는 운영자 주석**을 두 번째 BEGIN 으로 세어
     * `duplicate_block`(fail-closed 오분류)을 냈다. 운영자는 지운 것도 겹쳐 쓴 것도 없는데
     * 「중복 블록을 지우라」는 안내를 받고, 갱신은 영구히 건너뛰어진다.
     *
     * 마커는 **줄 끝**(뒤에 공백만 허용)에서만 성립한다 — 릴리즈 판본이 그 형태이고, 뒤에
     * 텍스트가 오는 줄은 마커가 아니라 그것을 언급하는 문장이다.
     */
    public function test_marker_followed_by_text_is_not_counted_as_a_marker(): void
    {
        $src = $this->workDir.'/src.htaccess';
        $dest = $this->workDir.'/dest.htaccess';

        file_put_contents($src, $this->coreFile('RewriteRule ^ public/$1 [L] # v2'));
        file_put_contents(
            $dest,
            "# BEGIN G7 마커를 지우지 마세요 — 코어 업데이트가 이 블록을 갱신합니다\n"
            ."# END G7 뒤에 규칙을 두면 보존됩니다\n"
            .$this->coreFile('RewriteRule ^ public/ [L] # v1')
            ."Deny from 10.0.0.0/8\n"
        );

        $this->assertSame(
            (static::UPDATER)::RESULT_UPDATED,
            (static::UPDATER)::apply($src, $dest),
            '마커 뒤에 텍스트가 오는 운영자 주석을 두 번째 마커로 세면 중복 블록으로 오분류돼 갱신이 영구히 건너뛰어진다'
        );

        $result = (string) file_get_contents($dest);
        $this->assertStringContainsString('# v2', $result);
        $this->assertStringNotContainsString('# v1', $result);
        $this->assertStringContainsString('# BEGIN G7 마커를 지우지 마세요', $result, '운영자 주석은 블록 밖이라 보존돼야 한다');
        $this->assertStringContainsString('Deny from 10.0.0.0/8', $result);
    }

    /**
     * 마커 뒤의 **공백만**은 여전히 마커다 — 편집기가 남긴 후행 공백으로 블록을 잃지 않는다.
     */
    public function test_marker_with_trailing_whitespace_only_is_still_a_marker(): void
    {
        $src = $this->workDir.'/src.htaccess';
        $dest = $this->workDir.'/dest.htaccess';

        file_put_contents($src, $this->coreFile('RewriteRule ^ public/$1 [L] # v2'));
        file_put_contents(
            $dest,
            (static::UPDATER)::BEGIN_MARKER."  \t\n# v1\n".(static::UPDATER)::END_MARKER." \n# tail\n"
        );

        $this->assertSame((static::UPDATER)::RESULT_UPDATED, (static::UPDATER)::apply($src, $dest));

        $result = (string) file_get_contents($dest);
        $this->assertStringContainsString('# v2', $result);
        $this->assertStringNotContainsString('# v1', $result);
        $this->assertStringContainsString('# tail', $result);
    }

    public function test_marker_inside_comment_text_is_not_mistaken_for_block_boundary(): void
    {
        // 실제 배포 파일은 블록 안 주석에 마커 문자열을 언급한다 — 줄 시작이 아닌 언급이
        // 경계로 오인되면 블록 범위가 어긋난다.
        $block = (static::UPDATER)::BEGIN_MARKER."\n"
            .'# 이 블록('.(static::UPDATER)::BEGIN_MARKER.' ~ '.(static::UPDATER)::END_MARKER.")은 코어 업데이트가 갱신한다\n"
            ."RewriteRule ^ public/$1 [L]\n"
            .(static::UPDATER)::END_MARKER;

        // END 줄의 종결자는 블록의 일부다 (G3 회차 15 — 줄바꿈이 대상에 맞춰지려면 그
        // 한 글자도 블록과 함께 교체되어야 한다). 꼬리(`# tail`)는 블록 밖이다.
        $extracted = (static::UPDATER)::extractBlock($block."\n# tail\n");
        $this->assertSame($block."\n", $extracted);
    }
}

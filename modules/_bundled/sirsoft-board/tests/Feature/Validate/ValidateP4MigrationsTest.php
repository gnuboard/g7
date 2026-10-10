<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Validate;

use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use Tests\Support\RecordingSchemaBuilder;

/**
 * 게시판 마이그레이션 재실행 가드 검증 (#599 validate P4)
 *
 * 재실행 가드는 "앞선 실행이 중간에 멈춘 상태" 에서만 드러나는데, 그 상태를 공유 테스트 DB 에
 * 만들면 DDL 이 암묵 커밋되어 테이블이 망가진다. 그래서 스키마 호출을 {@see RecordingSchemaBuilder}
 * 로 바꿔 끼워 **실제 DDL 없이** 무엇을 내려 했는지만 본다.
 */
class ValidateP4MigrationsTest extends ModuleTestCase
{
    /** 첨부 조치 주체 마이그레이션 (모듈 루트 기준). */
    private const TRIGGER_TYPE = 'database/migrations/2026_06_26_000002_add_trigger_type_to_board_attachments_table.php';

    /** 반응 유형 아이콘 첨부 마이그레이션 (모듈 루트 기준). */
    private const REACTION_ICON = 'database/migrations/2026_09_15_000001_add_icon_attachment_id_to_board_reaction_types_table.php';

    /**
     * 모듈 마이그레이션 파일의 절대 경로를 돌려줍니다.
     *
     * @param  string  $relative  모듈 루트 기준 경로
     * @return string 절대 경로
     */
    private function migrationPath(string $relative): string
    {
        return $this->getModuleBasePath().'/'.$relative;
    }

    /**
     * 첨부 조치 주체 마이그레이션은 컬럼만 만들고 멈춘 설치본에서 다시 돌면 컬럼을 건너뛰고
     * 인덱스만 마저 만든다. 그 다음 실행은 아무것도 내지 않는다.
     *
     * 수정 전 실패 이유: 컬럼 추가에 `hasColumn` 가드가 없어, 컬럼 추가 뒤 인덱스 단계에서 멈춘
     * 설치본을 다시 돌리면 "Duplicate column name" 으로 매번 같은 자리에서 실패한다.
     *
     * @scenario defect=rerun_guard
     *
     * @effects board_attachment_trigger_type_rerun_completes_the_missing_index
     *
     * @return void 반환값 없음
     */
    public function test_trigger_type_rerun_completes_the_missing_index(): void
    {
        $schema = new RecordingSchemaBuilder([
            'board_attachments' => ['columns' => ['board_id', 'post_id', 'created_by', 'trigger_type']],
        ]);

        $operations = $schema->run($this->app, $this->migrationPath(self::TRIGGER_TYPE));

        $this->assertCount(1, $operations, '빠진 인덱스 한 건만 만들어야 합니다');
        $this->assertSame([], $operations[0]['added'], '이미 있는 컬럼을 다시 추가하려 했습니다');
        $this->assertSame([['name' => 'index', 'index' => 'idx_board_post_trigger']], $operations[0]['commands']);

        $schema->resetOperations();
        $this->assertSame([], $schema->run($this->app, $this->migrationPath(self::TRIGGER_TYPE)), '두 번째 실행이 DDL 을 냈습니다');
    }

    /**
     * 첨부 조치 주체 마이그레이션의 `down()` 은 인덱스를 못 만들고 멈춘 설치본에서도 되돌릴 수 있다.
     *
     * 수정 전 실패 이유: `down()` 이 컬럼만 확인하고 인덱스를 무조건 지워, 인덱스가 없는 설치본에서
     * "Can't DROP index" 로 되돌리기 자체가 막힌다.
     *
     * @scenario defect=rerun_guard
     *
     * @effects board_attachment_trigger_type_down_survives_a_partial_up
     *
     * @return void 반환값 없음
     */
    public function test_trigger_type_down_survives_a_partial_up(): void
    {
        $schema = new RecordingSchemaBuilder([
            'board_attachments' => ['columns' => ['board_id', 'post_id', 'created_by', 'trigger_type']],
        ]);

        $operations = $schema->run($this->app, $this->migrationPath(self::TRIGGER_TYPE), 'down');

        $this->assertSame(
            [['name' => 'dropColumn', 'index' => null]],
            array_merge(...array_column($operations, 'commands')),
            '없는 인덱스를 지우려 했거나 컬럼을 지우지 않았습니다',
        );
    }

    /**
     * 반응 유형 아이콘 마이그레이션은 컬럼만 만들고 외래키 단계에서 멈춘 설치본에서 다시 돌면
     * 컬럼을 건너뛰고 빠진 외래키만 만든다. 그 다음 실행은 아무것도 내지 않는다.
     *
     * 수정 전 실패 이유: 외래키가 컬럼의 `hasColumn` 가드 안에 묶여 있어, 컬럼이 이미 있으면 외래키를
     * 만들 기회가 영영 없었다(첨부가 지워져도 아이콘 ID 가 NULL 로 돌아가지 않는다).
     *
     * @scenario defect=rerun_guard
     *
     * @effects board_reaction_icon_rerun_completes_the_missing_foreign_key
     *
     * @return void 반환값 없음
     */
    public function test_reaction_icon_rerun_completes_the_missing_foreign_key(): void
    {
        $schema = new RecordingSchemaBuilder([
            'board_reaction_types' => ['columns' => ['id', 'icon', 'icon_attachment_id']],
        ]);

        $operations = $schema->run($this->app, $this->migrationPath(self::REACTION_ICON));

        $this->assertCount(1, $operations, '빠진 외래키 한 건만 만들어야 합니다');
        $this->assertSame([], $operations[0]['added'], '이미 있는 컬럼을 다시 추가하려 했습니다');
        $this->assertSame([['name' => 'foreign', 'index' => 'fk_reaction_type_icon_attachment']], $operations[0]['commands']);

        $schema->resetOperations();
        $this->assertSame([], $schema->run($this->app, $this->migrationPath(self::REACTION_ICON)), '두 번째 실행이 DDL 을 냈습니다');
    }

    /**
     * 반응 유형 아이콘 마이그레이션의 `down()` 은 외래키가 없는 설치본에서도 컬럼을 지운다.
     *
     * 수정 전 실패 이유: `down()` 이 외래키를 확인 없이 지워, 외래키 단계 전에 멈춘 설치본에서는
     * 없는 제약을 지우려다 되돌리기 자체가 막혔다.
     *
     * @scenario defect=rerun_guard
     *
     * @effects board_reaction_icon_down_survives_a_partial_up
     *
     * @return void 반환값 없음
     */
    public function test_reaction_icon_down_survives_a_partial_up(): void
    {
        $schema = new RecordingSchemaBuilder([
            'board_reaction_types' => ['columns' => ['id', 'icon', 'icon_attachment_id']],
        ]);

        $operations = $schema->run($this->app, $this->migrationPath(self::REACTION_ICON), 'down');

        $commands = array_column(array_merge(...array_column($operations, 'commands')), 'name');
        $this->assertNotContains('dropForeign', $commands, '없는 외래키를 지우려 했습니다');
        $this->assertContains('dropColumn', $commands);
        $this->assertFalse($schema->hasColumn('board_reaction_types', 'icon_attachment_id'));
    }
}

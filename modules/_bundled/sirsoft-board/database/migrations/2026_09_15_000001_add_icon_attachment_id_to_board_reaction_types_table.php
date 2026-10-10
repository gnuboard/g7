<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * board_reaction_types 테이블에 아이콘 이미지 첨부 ID 추가
     *
     * 기존 icon 컬럼은 Font Awesome 클래스 문자열(`fas fa-thumbs-up`)이며 시드 기본값 전용이다.
     * 관리자가 업로드한 아이콘 이미지는 코어 attachments 테이블에 저장하고 그 ID를 여기에 건다.
     * 표시 우선순위는 icon_attachment(이미지) > icon(FA 클래스) > 레이아웃 기본 아이콘이다.
     *
     * 첨부 행이 하드 삭제되면 nullOnDelete 로 이 컬럼이 자동 NULL 이 되어 FA 폴백으로 되돌아간다.
     * 2026_08_04_000005 패턴(hasColumn 가드 + after + 한국어 comment + down) 재사용.
     */
    public function up(): void
    {
        if (! Schema::hasTable('board_reaction_types')) {
            return;
        }

        // 컬럼과 외래키는 각각 가드한다 — MySQL 은 DDL 을 되돌리지 않아, 컬럼만 만들고 외래키 단계에서
        // 멈춘 설치본은 컬럼 가드 안에 묶인 외래키를 다시 만들 기회가 영영 없다.
        Schema::table('board_reaction_types', function (Blueprint $table) {
            if (! Schema::hasColumn('board_reaction_types', 'icon_attachment_id')) {
                $table->unsignedBigInteger('icon_attachment_id')
                    ->nullable()
                    ->after('icon')
                    ->comment('아이콘 이미지 첨부 ID (attachments.id, NULL 이면 icon FA 클래스 폴백)');
            }
        });

        if (! $this->hasForeignKey('board_reaction_types', 'fk_reaction_type_icon_attachment')) {
            Schema::table('board_reaction_types', function (Blueprint $table) {
                $table->foreign('icon_attachment_id', 'fk_reaction_type_icon_attachment')
                    ->references('id')
                    ->on('attachments')
                    ->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('board_reaction_types')) {
            return;
        }

        if ($this->hasForeignKey('board_reaction_types', 'fk_reaction_type_icon_attachment')) {
            Schema::table('board_reaction_types', function (Blueprint $table) {
                $table->dropForeign('fk_reaction_type_icon_attachment');
            });
        }

        Schema::table('board_reaction_types', function (Blueprint $table) {
            if (Schema::hasColumn('board_reaction_types', 'icon_attachment_id')) {
                $table->dropColumn('icon_attachment_id');
            }
        });
    }

    /**
     * 테이블에 이름이 같은 외래키 제약이 있는지 확인합니다.
     *
     * @param  string  $table  테이블명
     * @param  string  $name  외래키 제약 이름
     * @return bool 있으면 true
     */
    private function hasForeignKey(string $table, string $name): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (($foreignKey['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 확장 조각 원본 본문 컬럼 추가 — 확장 업데이트·활성화의 3자 병합 기준(base)을 저장한다 (#727).
 *
 * 지금은 원본의 해시·크기만 있어 "어디가 바뀌었는지" 를 알 수 없다. 원본 본문을 남겨야 운영자가 고친 조각은
 * 두고 손대지 않은 조각에만 새 판을 넣을 수 있다. 값은 설치·갱신 때 파일에서 컴파일한 본문이고 조각 출처
 * 표식(`__partial_origin`)을 담는다 — 서비스에는 쓰지 않는다.
 *
 * 백필하지 않는다: 원본을 다시 만들려면 컴파일러를 불러야 해 업그레이드 스텝 격리 규율에 어긋난다. 비어 있는
 * 행은 다음 업데이트가 업데이트 백업을 컴파일해 채우고(해시가 같을 때만), 활성화 경로는 파일이 원본과 같을 때 채운다.
 */
return new class extends Migration
{
    /**
     * 컬럼을 추가합니다.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        if (Schema::hasColumn('template_layout_extensions', 'original_content')) {
            return;
        }

        Schema::table('template_layout_extensions', function (Blueprint $table) {
            $table->longText('original_content')
                ->nullable()
                ->after('original_content_size')
                ->comment('원본 확장 조각 본문 JSON — 3자 병합 기준');
        });
    }

    /**
     * 컬럼을 제거합니다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        if (! Schema::hasColumn('template_layout_extensions', 'original_content')) {
            return;
        }

        Schema::table('template_layout_extensions', function (Blueprint $table) {
            $table->dropColumn('original_content');
        });
    }
};

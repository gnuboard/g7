<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 회원 언어 칸을 언어팩 언어 코드 길이(20자)에 맞춥니다.
     *
     * 언어팩으로 켤 수 있는 `fil-PH`·`zh-Hant-TW` 같은 BCP 47 코드는 다섯 글자를 넘어, 회원 가입·
     * 프로필 저장·관리자 회원 수정이 엄격 모드에서 실패했다(#148). 20 은 `language_packs.locale` 폭이다.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('language', 20)->default('ko')->comment('사용자 언어 설정 (BCP 47 — 예: ko, en, fil-PH)')->change();
        });
    }

    /**
     * 회원 언어 칸을 원래 길이(5)로 되돌립니다.
     *
     * 엄격 모드에서는 잘리는 값이 하나라도 있으면 ALTER 가 실패하므로, 다섯 글자를 넘는 값을
     * 먼저 기본 언어('ko')로 고친다. 길이 판정은 DB 종속 함수 대신 PHP 에서 한다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        DB::table('users')->select(['id', 'language'])->orderBy('id')->chunkById(500, function ($users) {
            $ids = $users->filter(fn ($user) => mb_strlen((string) $user->language) > 5)->pluck('id')->all();
            if ($ids !== []) {
                DB::table('users')->whereIn('id', $ids)->update(['language' => 'ko']);
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('language', 5)->default('ko')->comment('사용자 언어 설정 (ko: 한국어, en: 영어)')->change();
        });
    }
};

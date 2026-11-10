<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * A member's day: the running score, how many they got right and answered,
 * the result grid (one letter per quote, see Scoring::GRID) and when they
 * finished. The daily leaderboard reads only this table.
 */
return Migration::createTable('who_said_it_scores', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('day_id');
    $table->unsignedInteger('user_id');
    $table->unsignedSmallInteger('score')->default(0);
    $table->unsignedTinyInteger('correct')->default(0);
    $table->unsignedTinyInteger('answered')->default(0);
    $table->string('grid', 10)->default('');
    $table->dateTime('started_at');
    $table->dateTime('finished_at')->nullable();

    $table->unique(['day_id', 'user_id']);
    $table->index(['day_id', 'score']);
    $table->index('user_id');
    $table->foreign('day_id')->references('id')->on('who_said_it_days')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});

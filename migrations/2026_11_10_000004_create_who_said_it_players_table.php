<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * A member's all-time record and choices: totals and streaks (updated when
 * they finish a day), whether they want the daily reminder, and whether
 * they opted out of being quoted (or shown as a choice).
 */
return Migration::createTable('who_said_it_players', function (Blueprint $table) {
    $table->unsignedInteger('user_id')->primary();
    $table->unsignedInteger('total_score')->default(0);
    $table->unsignedInteger('quizzes')->default(0);
    $table->unsignedInteger('correct_total')->default(0);
    $table->unsignedInteger('streak')->default(0);
    $table->unsignedInteger('best_streak')->default(0);
    $table->string('last_day', 10)->nullable();
    $table->boolean('remind')->default(false);
    $table->boolean('opted_out')->default(false);

    $table->index('total_score');
    $table->index('remind');
    $table->index('opted_out');
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});

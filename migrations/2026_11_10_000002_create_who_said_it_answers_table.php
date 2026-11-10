<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * A member's go at one quote. The row is made when the quote is first
 * shown (shown_ms, epoch milliseconds: the speed bonus is timed on the
 * server, and a reload doesn't restart the clock); answered_at, choice_id,
 * correct, points and ms are filled in once, by one guarded update.
 */
return Migration::createTable('who_said_it_answers', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('question_id');
    $table->unsignedInteger('user_id');
    $table->unsignedBigInteger('shown_ms');
    $table->dateTime('answered_at')->nullable();
    $table->unsignedInteger('choice_id')->nullable();
    $table->boolean('correct')->nullable();
    $table->unsignedSmallInteger('points')->default(0);
    $table->unsignedInteger('ms')->nullable();

    $table->unique(['question_id', 'user_id']);
    $table->index('user_id');
    $table->foreign('question_id')->references('id')->on('who_said_it_questions')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});

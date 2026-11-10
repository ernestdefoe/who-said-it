<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * A quote of the day. The excerpt is a cleaned copy taken when the quiz was
 * made, so a later edit can't change the question; the post and author are
 * the answer and are never sent to a browser before the member has answered.
 * `choices` is the four user ids in the order they are shown (already
 * shuffled), the author among them.
 */
return Migration::createTable('who_said_it_questions', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('day_id');
    $table->unsignedTinyInteger('position');
    $table->unsignedInteger('post_id')->nullable();
    $table->unsignedInteger('discussion_id')->nullable();
    $table->unsignedInteger('author_id')->nullable();
    $table->text('excerpt');
    $table->string('choices', 100);
    $table->dateTime('created_at');

    $table->unique(['day_id', 'position']);
    $table->index(['post_id', 'created_at']);
    $table->index('author_id');
    $table->foreign('day_id')->references('id')->on('who_said_it_days')->cascadeOnDelete();
    $table->foreign('post_id')->references('id')->on('posts')->nullOnDelete();
    $table->foreign('discussion_id')->references('id')->on('discussions')->nullOnDelete();
    $table->foreign('author_id')->references('id')->on('users')->nullOnDelete();
});

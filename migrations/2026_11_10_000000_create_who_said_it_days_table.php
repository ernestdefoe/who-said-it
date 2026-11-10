<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * One row per quiz day, in the forum's quiz time zone. The unique day is
 * what makes generating idempotent: two schedulers (or a scheduler and a
 * page view) racing for the same day can only insert it once.
 */
return Migration::createTable('who_said_it_days', function (Blueprint $table) {
    $table->increments('id');
    $table->string('day', 10)->unique();
    $table->unsignedTinyInteger('question_count')->default(0);
    $table->dateTime('reminded_at')->nullable();
    $table->dateTime('created_at');
});

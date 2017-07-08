<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateExercisesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('exercises', function (Blueprint $table) {
            $table->increments('exercise_id');
            $table->string('category')->default('default');
            $table->string('exerciseName')->unique();
            $table->string('description')->nullable();
            $table->string('difficulty');
            $table->smallInteger('score');
            $table->string('expectedOutcome');
            $table->timestamps();
            $table->SoftDeletes();
        });

        Schema::create('exercises_solved', function (Blueprint $table) {
          $table->integer('exercise_id')->unsigned();
          $table->integer('user_id')->unsigned();
          $table->integer('team_id')->unsigned();

          $table->foreign('exercise_id')->references('exercise_id')->on('exercises')
              ->onUpdate('cascade')->onDelete('cascade');
          $table->foreign('user_id')->references('user_id')->on('users')
              ->onUpdate('cascade')->onDelete('cascade');
          $table->foreign('team_id')->references('team_id')->on('teams')
              ->onUpdate('cascade')->onDelete('cascade');

          $table->primary(['exercise_id', 'user_id', 'team_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('exercises');
    }
}

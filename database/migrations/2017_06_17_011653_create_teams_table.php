<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTeamsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->increments('team_id');
            $table->string('teamName')->unique();
            $table->string('email')->nullable();
            $table->smallInteger('score')->default('0');
            $table->timestamps();
            $table->SoftDeletes();
        });

        Schema::create('user_team', function (Blueprint $table) {
          $table->integer('user_id')->unsigned();
          $table->integer('team_id')->unsigned();

          $table->foreign('user_id')->references('user_id')->on('users')
              ->onUpdate('cascade')->onDelete('cascade');
          $table->foreign('team_id')->references('team_id')->on('teams')
              ->onUpdate('cascade')->onDelete('cascade');

          $table->primary(['user_id', 'team_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('usersTeams');
    }
}

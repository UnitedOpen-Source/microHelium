<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateHackathonsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('hackathons', function (Blueprint $table) {
            $table->increments('hackathon_id');
            $table->string('eventName')->unique();
            $table->string('description')->nullable();
            $table->datetime('starts_at');
            $table->datetime('ends_at');
            $table->timestamps();
            $table->SoftDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('hackathons');
    }
}

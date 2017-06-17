<?php

namespace Helium;

use Illuminate\Database\Eloquent\Model;

class Exercise extends Model
{
  protected $fillable = ['exerciseName','category','difficulty','score','expectedOutcome'];
  protected $guarded = ['id', 'created_at', 'update_at'];
  protected $table = 'exercises';
}

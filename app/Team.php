<?php

namespace Helium;

use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
  protected $fillable = ['teamName','email','score'];
  protected $guarded = ['id', 'created_at', 'update_at'];
  protected $table = 'usersTeams';
}

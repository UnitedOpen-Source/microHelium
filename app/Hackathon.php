<?php

namespace Helium;

use Illuminate\Database\Eloquent\Model;

class Hackathon extends Model
{
  protected $fillable = ['eventName','description','starts_at','ends_at'];
  protected $guarded = ['id', 'created_at', 'update_at'];
  protected $table = 'hackathons';
}

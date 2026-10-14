<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TemplateEvent extends Model { protected $guarded = []; protected $casts = ['raw' => 'array']; }

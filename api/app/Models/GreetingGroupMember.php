<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GreetingGroupMember extends Model
{
    protected $fillable = ['greeting_group_id', 'contact_wa_id'];
}

<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/** Framework default; the builder has no user accounts (see DECISIONS.md). */
class User extends Authenticatable
{
    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];
}

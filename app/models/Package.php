<?php

use Illuminate\Database\Eloquent\SoftDeletingTrait;

class Package extends Eloquent {

	protected   $table =        'packages';
	protected   $primaryKey =   'id';
	public      $timestamps =   false;

	protected   $fillable =     [];
    protected   $guarded =      [];
    protected   $hidden =       [];

	protected   $dates =        ['created_at', 'updated_at'];

	public static function boot()
	{
		parent::boot();
		// Setup event bindings...
	}

};
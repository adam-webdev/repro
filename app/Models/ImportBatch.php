<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model
{
    protected $guarded = [];

    protected $casts = ['groups' => 'array', 'committed_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function errors()
    {
        return $this->hasMany(ImportError::class);
    }

    public function parsedPath(): string
    {
        return storage_path('app/private/imports/parsed-'.$this->id.'.json');
    }
}

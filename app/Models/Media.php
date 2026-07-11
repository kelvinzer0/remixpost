<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'filename',
        'original_name',
        'mime_type',
        'size',
        'path',
        'url',
        'folder_path',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @method static \Illuminate\Database\Eloquent\Builder|Knowledge query()
 * @method static \Illuminate\Database\Eloquent\Builder|Knowledge updateOrCreate(array $attributes, array $values = [])
 */
class Knowledge extends Model
{
    protected $table = 'v2_knowledge';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'show' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Analysis extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'title',
        'description',
        'original_image_path',
        'processed_image_path',
        'detection_results',
        'weed_coverage_percentage',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'detection_results' => 'array',
    ];

  
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    public function detectionRaws(): HasMany
    {
        return $this->hasMany(DetectionRaw::class);
    }
}

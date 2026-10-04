<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A weighted criterion of the Service Providers Evaluation Matrix. Criteria are
 * versioned; the scoring scale and pass mark are still open (docs/open-questions.md OQ-13).
 */
class EvaluationCriterion extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'version',
        'code',
        'name_ar',
        'name_en',
        'sub_elements_ar',
        'weight_percent',
        'verification_ar',
        'sort_order',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'weight_percent' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }
}

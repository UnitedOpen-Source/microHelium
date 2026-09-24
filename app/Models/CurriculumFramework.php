<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Issue #396 -- um currículo oficial (BNCC Computação, CSTA, KS3...).
 * docs/specs/396-curriculos-oficiais.md.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $jurisdiction
 * @property string $version
 * @property string $locale
 * @property string $source_url
 * @property Carbon $source_consulted_at
 * @property string|null $source_sha256
 * @property string|null $source_notes
 */
class CurriculumFramework extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'jurisdiction',
        'version',
        'locale',
        'source_url',
        'source_consulted_at',
        'source_sha256',
        'source_notes',
    ];

    protected $casts = [
        'source_consulted_at' => 'date',
    ];

    /** @return HasMany<CurriculumOutcome, $this> */
    public function outcomes(): HasMany
    {
        return $this->hasMany(CurriculumOutcome::class)->orderBy('position')->orderBy('id');
    }
}

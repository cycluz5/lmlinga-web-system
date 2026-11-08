<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PhilHealth membership type lookup (S-02). See App\Support\PhilhealthMembershipTypes for the sources.
 */
class PhilhealthMembershipType extends Model
{
    protected $table = 'philhealth_membership_type';

    protected $primaryKey = 'philhealth_membership_type_id';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type_code',
        'type_name',
        'contributor_group',
        'sort_order',
    ];

    /**
     * @return HasMany<Resident, $this>
     */
    public function residents(): HasMany
    {
        return $this->hasMany(Resident::class, 'philhealth_membership_type_id', 'philhealth_membership_type_id');
    }
}

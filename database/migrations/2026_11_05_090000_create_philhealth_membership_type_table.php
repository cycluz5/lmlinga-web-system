<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Panel refinement S-02: PhilHealth membership type of a household member.
 *
 * Additive: one lookup table and one nullable column. No resident row is updated, so every existing
 * resident stays NULL until an authorized health worker sets the type. Idempotent for partially
 * prepared schemas.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: string}> */
    private const OPTIONS = [
        ['employed_private', 'Employed Private', 'direct'],
        ['employed_government', 'Employed Government', 'direct'],
        ['professional_practitioner', 'Professional Practitioner', 'direct'],
        ['self_earning_individual', 'Self-Earning Individual', 'direct'],
        ['kasambahay', 'Kasambahay', 'direct'],
        ['family_driver', 'Family Driver', 'direct'],
        ['migrant_worker', 'Migrant Worker', 'direct'],
        ['lifetime_member', 'Lifetime Member', 'direct'],
        ['dual_citizen_living_abroad', 'Filipinos with Dual Citizenship / Living Abroad', 'direct'],
        ['foreign_national', 'Foreign National', 'direct'],
        ['listahanan', 'Listahanan', 'indirect'],
        ['four_ps_mcct', '4Ps/MCCT', 'indirect'],
        ['senior_citizen', 'Senior Citizen', 'indirect'],
        ['pamana', 'PAMANA', 'indirect'],
        ['kia_kipo', 'KIA/KIPO', 'indirect'],
        ['bangsamoro_normalization', 'Bangsamoro/Normalization', 'indirect'],
        ['lgu_sponsored', 'LGU-sponsored', 'indirect'],
        ['nga_sponsored', 'NGA-sponsored', 'indirect'],
        ['private_sponsored', 'Private-sponsored', 'indirect'],
        ['person_with_disability', 'Person with Disability', 'indirect'],
        ['solo_parent', 'Solo Parent', 'indirect'],
        ['employed_person_with_disability', 'Employed Person with Disability', 'indirect'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('philhealth_membership_type')) {
            Schema::create('philhealth_membership_type', function (Blueprint $table) {
                $table->bigIncrements('philhealth_membership_type_id');
                $table->string('type_code', 40)->unique();
                $table->string('type_name', 100);
                $table->string('contributor_group', 10);
                $table->unsignedSmallInteger('sort_order');
            });
        }

        foreach (self::OPTIONS as $index => [$code, $name, $group]) {
            DB::table('philhealth_membership_type')->updateOrInsert(
                ['type_code' => $code],
                ['type_name' => $name, 'contributor_group' => $group, 'sort_order' => $index + 1],
            );
        }

        if (! Schema::hasTable('residents') || Schema::hasColumn('residents', 'philhealth_membership_type_id')) {
            return;
        }

        Schema::table('residents', function (Blueprint $table) {
            $table->unsignedBigInteger('philhealth_membership_type_id')->nullable();
        });

        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('residents', function (Blueprint $table) {
                $table->foreign('philhealth_membership_type_id', 'fk_resident_philhealth_membership_type')
                    ->references('philhealth_membership_type_id')
                    ->on('philhealth_membership_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('residents') && Schema::hasColumn('residents', 'philhealth_membership_type_id')) {
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                Schema::table('residents', function (Blueprint $table) {
                    $table->dropForeign('fk_resident_philhealth_membership_type');
                });
            }

            Schema::table('residents', function (Blueprint $table) {
                $table->dropColumn('philhealth_membership_type_id');
            });
        }

        Schema::dropIfExists('philhealth_membership_type');
    }
};

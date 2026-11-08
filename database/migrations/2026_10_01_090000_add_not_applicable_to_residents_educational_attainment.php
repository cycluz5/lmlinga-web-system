<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * residents.educational_attainment — add 'Not Applicable'.
 *
 * The member form offers and validates 'Not Applicable' (young children), but the
 * enum lacked it, so saving such a member failed with "Data truncated for column
 * 'educational_attainment'" (HTTP 500). down() clears those rows to NULL first so
 * the narrower enum can be restored.
 */
return new class extends Migration
{
    private const BASE_VALUES = [
        'No Formal Education',
        'Elementary Level',
        'Elementary Graduate',
        'High School Level',
        'High School Graduate',
        'Vocational',
        'College Level',
        'College Graduate',
        'Post Graduate',
    ];

    public function up(): void
    {
        if (! $this->applies()) {
            return;
        }

        $this->setEnum([...self::BASE_VALUES, 'Not Applicable']);
    }

    public function down(): void
    {
        if (! $this->applies()) {
            return;
        }

        DB::table('residents')
            ->where('educational_attainment', 'Not Applicable')
            ->update(['educational_attainment' => null]);

        $this->setEnum(self::BASE_VALUES);
    }

    private function applies(): bool
    {
        return DB::getDriverName() === 'mysql'
            && Schema::hasTable('residents')
            && Schema::hasColumn('residents', 'educational_attainment');
    }

    /**
     * @param  list<string>  $values
     */
    private function setEnum(array $values): void
    {
        $list = implode(',', array_map(fn (string $v) => DB::getPdo()->quote($v), $values));

        DB::statement("ALTER TABLE `residents` MODIFY `educational_attainment` ENUM({$list}) NULL DEFAULT NULL");
    }
};

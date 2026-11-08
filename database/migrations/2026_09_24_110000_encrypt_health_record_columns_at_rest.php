<?php

use App\Support\AtRestColumnMigrator;
use Illuminate\Database\Migrations\Migration;

/**
 * AES-256-GCM at rest for the health-record columns listed in AtRestColumns:
 * medical history, disability, family history, past medical history, red flags,
 * visual screening, risk assessment, death cause/registry number, and
 * HIV / syphilis / hepatitis B results.
 *
 * MySQL/MariaDB columns become TEXT; risk_assessment.blood_pressure_status
 * stops being a generated column and is computed by the app.
 * Requires LMLINGA_AT_REST_KEY. No-op for tables that do not exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new AtRestColumnMigrator)->encryptAll();
    }

    public function down(): void
    {
        (new AtRestColumnMigrator)->decryptAll();
    }
};

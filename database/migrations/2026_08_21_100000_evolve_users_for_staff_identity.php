<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maps Final SQL `user_management` into Laravel's canonical `users` table.
 * Keeps one staff credential store (email/username/password) for Auth.
 *
 * REF-22: per-column / index / foreign-key guards. Do not skip the whole
 * migration when some columns already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addColumnIfMissing('photo_path', function (Blueprint $table): void {
            $table->string('photo_path', 255)->nullable()->after('id');
        });
        $this->addColumnIfMissing('first_name', function (Blueprint $table): void {
            $table->string('first_name', 100)->nullable()->after('photo_path');
        });
        $this->addColumnIfMissing('middle_name', function (Blueprint $table): void {
            $table->string('middle_name', 100)->nullable()->after('first_name');
        });
        $this->addColumnIfMissing('last_name', function (Blueprint $table): void {
            $table->string('last_name', 100)->nullable()->after('middle_name');
        });
        $this->addColumnIfMissing('suffix', function (Blueprint $table): void {
            $table->string('suffix', 20)->nullable()->after('last_name');
        });
        $this->addColumnIfMissing('sex', function (Blueprint $table): void {
            $table->string('sex', 16)->nullable()->after('suffix');
        });
        $this->addColumnIfMissing('date_of_birth', function (Blueprint $table): void {
            $table->date('date_of_birth')->nullable()->after('sex');
        });
        $this->addColumnIfMissing('civil_status', function (Blueprint $table): void {
            $table->string('civil_status', 50)->nullable()->after('date_of_birth');
        });
        $this->addColumnIfMissing('nationality', function (Blueprint $table): void {
            $table->string('nationality', 50)->nullable()->after('civil_status');
        });
        $this->addColumnIfMissing('mobile_number', function (Blueprint $table): void {
            $table->string('mobile_number', 20)->nullable()->after('nationality');
        });
        $this->addColumnIfMissing('house_no', function (Blueprint $table): void {
            $table->string('house_no', 20)->nullable()->after('mobile_number');
        });
        $this->addColumnIfMissing('street', function (Blueprint $table): void {
            $table->string('street', 150)->nullable()->after('house_no');
        });
        $this->addColumnIfMissing('purok_zone', function (Blueprint $table): void {
            $table->string('purok_zone', 20)->nullable()->after('street');
        });
        $this->addColumnIfMissing('barangay', function (Blueprint $table): void {
            $table->string('barangay', 100)->nullable()->after('purok_zone');
        });
        $this->addColumnIfMissing('municipality_city', function (Blueprint $table): void {
            $table->string('municipality_city', 100)->nullable()->after('barangay');
        });
        $this->addColumnIfMissing('province', function (Blueprint $table): void {
            $table->string('province', 100)->nullable()->after('municipality_city');
        });
        $this->addColumnIfMissing('zip_code', function (Blueprint $table): void {
            $table->string('zip_code', 10)->nullable()->after('province');
        });
        $this->addColumnIfMissing('username', function (Blueprint $table): void {
            $table->string('username', 100)->nullable()->after('email');
        });
        $this->addColumnIfMissing('status', function (Blueprint $table): void {
            $table->string('status', 16)->default('Active')->after('password');
        });
        $this->addColumnIfMissing('must_change_password', function (Blueprint $table): void {
            $table->boolean('must_change_password')->default(true)->after('status');
        });

        if (! Schema::hasColumn('users', 'created_by')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->foreignId('created_by')
                    ->nullable()
                    ->after('must_change_password')
                    ->constrained('users')
                    ->restrictOnDelete();
            });
        } elseif (! $this->hasForeignKey('users', 'created_by')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            });
        }

        if (Schema::hasColumn('users', 'username') && ! Schema::hasIndex('users', 'users_username_unique')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unique('username');
            });
        }

        if (Schema::hasColumn('users', 'status') && ! Schema::hasIndex('users', 'users_status_index')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->index('status');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            if ($this->hasForeignKey('users', 'created_by')) {
                $table->dropConstrainedForeignId('created_by');
            } elseif (Schema::hasColumn('users', 'created_by')) {
                $table->dropColumn('created_by');
            }

            if (Schema::hasIndex('users', 'users_status_index')) {
                $table->dropIndex(['status']);
            }
            if (Schema::hasIndex('users', 'users_username_unique')) {
                $table->dropUnique(['username']);
            }

            $drop = [];
            foreach ([
                'photo_path',
                'first_name',
                'middle_name',
                'last_name',
                'suffix',
                'sex',
                'date_of_birth',
                'civil_status',
                'nationality',
                'mobile_number',
                'house_no',
                'street',
                'purok_zone',
                'barangay',
                'municipality_city',
                'province',
                'zip_code',
                'username',
                'status',
                'must_change_password',
            ] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $drop[] = $column;
                }
            }

            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }

    private function addColumnIfMissing(string $column, callable $define): void
    {
        if (Schema::hasColumn('users', $column)) {
            return;
        }

        Schema::table('users', $define);
    }

    private function hasForeignKey(string $table, string $column): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            $columns = $foreignKey['columns'] ?? [];
            if (in_array($column, $columns, true)) {
                return true;
            }
        }

        return false;
    }
};

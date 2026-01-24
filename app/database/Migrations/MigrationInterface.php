<?php

declare(strict_types=1);

namespace Database\Migrations;

interface MigrationInterface
{
    public function up(): void;
    public function down(): void;
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colonnes des comptes de démonstration.
 *
 * Purement additive, donc compatible avec le code de la version précédente :
 * un mot de passe devenu facultatif et trois colonnes avec valeur par défaut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Comptes de démo et comptes sociaux : aucun mot de passe.
            $table->string('password')->nullable()->change();
            $table->boolean('is_demo')->default(false);
            // Index : la purge et le décompte de capacité filtrent dessus.
            $table->timestamp('expires_at')->nullable()->index();
            $table->string('avatar_url', 512)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['is_demo', 'expires_at', 'avatar_url']);
        });
    }
};

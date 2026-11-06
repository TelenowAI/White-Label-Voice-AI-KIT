<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-in identities (App\Kit\Oidc\OidcSignIn): a customer's account at an OpenID Connect provider the operator set up
 * — the provider's id in the kit and the provider's `sub` for the person, one person per pair — so a later sign-in
 * finds them even when their email at the provider changed. `email` is what the provider last said, for staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_id');
            $table->string('subject');
            $table->string('email')->nullable();
            $table->timestamps();
            $table->unique(['provider_id', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_identities');
    }
};

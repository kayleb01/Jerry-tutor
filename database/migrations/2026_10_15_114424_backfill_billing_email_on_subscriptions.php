<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every subscription keeps the email its gateway knows the payer by, so later account edits can't break it.
     */
    public function up(): void
    {
        DB::table('subscriptions')
            ->whereNull('billing_email')
            ->update(['billing_email' => DB::raw('(select lower(users.email) from users where users.id = subscriptions.payer_id)')]);
    }

    public function down(): void
    {
        //
    }
};

<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Encrypt plaintext api_key / password values stored before the encrypted cast was added.
     */
    public function up(): void
    {
        foreach (DB::table('service_settings')->get() as $row) {
            $updates = [];

            foreach (['api_key', 'password'] as $column) {
                $value = $row->{$column};

                if ($value === null || $value === '') {
                    continue;
                }

                try {
                    Crypt::decryptString($value);
                } catch (DecryptException) {
                    $updates[$column] = Crypt::encryptString($value);
                }
            }

            if ($updates !== []) {
                DB::table('service_settings')->where('id', $row->id)->update($updates);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('service_settings')->get() as $row) {
            $updates = [];

            foreach (['api_key', 'password'] as $column) {
                $value = $row->{$column};

                if ($value === null || $value === '') {
                    continue;
                }

                try {
                    $updates[$column] = Crypt::decryptString($value);
                } catch (DecryptException) {
                    // Already plaintext.
                }
            }

            if ($updates !== []) {
                DB::table('service_settings')->where('id', $row->id)->update($updates);
            }
        }
    }
};

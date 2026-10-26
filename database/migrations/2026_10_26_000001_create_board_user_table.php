<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_user', function (Blueprint $table) {
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16)->default('member');
            $table->timestamps();

            $table->primary(['board_id', 'user_id']);
        });

        Schema::table('boards', function (Blueprint $table) {
            $table->boolean('public')->default(false)->after('name');
            $table->string('invite_token', 40)->nullable()->unique()->after('public');
        });

        // Boards from before membership existed were open to everyone; keep
        // them that way rather than locking people out, and make each
        // creator its owner.
        foreach (DB::table('boards')->get(['id', 'created_by']) as $board) {
            DB::table('boards')->where('id', $board->id)->update(['public' => true, 'invite_token' => Str::random(32)]);

            if ($board->created_by) {
                DB::table('board_user')->insert([
                    'board_id' => $board->id,
                    'user_id' => $board->created_by,
                    'role' => 'owner',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->dropUnique(['invite_token']);
            $table->dropColumn(['public', 'invite_token']);
        });

        Schema::dropIfExists('board_user');
    }
};

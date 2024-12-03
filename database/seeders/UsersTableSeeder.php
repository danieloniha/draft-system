<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UsersTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('users')->insert([
            
            // Admin
            [
                'name' => 'Admin',
                'email' => 'admin@sette.com',
                'password' => Hash::make('admin'),
                'role' => 'admin',
                'status' => 'active'
            ],

            // Users
            [
                'name' => 'User1',
                'email' => 'user@gmail.com',
                'password' => Hash::make('1234'),
                'role' => 'user',
                'status' => 'active'
            ],

            [
                'name' => 'User2',
                'email' => 'user2@gmail.com',
                'password' => Hash::make('1234'),
                'role' => 'user',
                'status' => 'active'
            ],

        ]);
    }
}

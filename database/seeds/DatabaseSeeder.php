<?php

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        //$this->call(UsersTableSeeder::class);
        Model::unguard();

        // $this->call(UserTableSeeder::class);
        DB::table('users')->delete();

        $users = array(
            ['name' => 'Ryan Chenkie', 'userName' => 'admin','email' => 'ryanchenkie@gmail.com', 'password' => Hash::make('secret')],
            ['name' => 'Chris Sevilleja', 'userName' => 'organ', 'email' => 'chris@scotch.io', 'password' => Hash::make('secret')],
            ['name' => 'Holly Lloyd', 'userName' => 'parti', 'email' => 'holly@scotch.io', 'password' => Hash::make('secret')],
            ['name' => 'Adnan Kukic', 'userName' => 'parti2', 'email' => 'adnan@scotch.io', 'password' => Hash::make('secret')],
        );

        // Loop through each user above and create the record for them in the database
        foreach ($users as $user)
        {
            User::create($user);
        }

        Model::reguard();
    }
}

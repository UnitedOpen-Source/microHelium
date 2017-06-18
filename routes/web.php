<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Auth::routes();

Route::get('/exercises', 'ExerciseController@index')->name('exercises');

Route::get('/wizard', 'HackathonController@index');

Route::get('/scoreboard', 'TeamController@index');

Route::get('/my-team', 'TeamController@index');

Route::get('/my-account', 'UserController@index');

/*
Route::resource('hackathons', 'HackathonController')->middleware('auth');
Route::resource('teams', 'TeamController')->middleware('auth');
Route::resource('users', 'UserController')->middleware('auth');
Route::resource('exercises', 'ExerciseController')->middleware('auth');
*/

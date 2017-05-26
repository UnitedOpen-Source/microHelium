<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
      //Swagger - https://github.com/DarkaOnLine/L5-Swagger
      $this->app->register(\L5Swagger\L5SwaggerServiceProvider::class);
    }
}

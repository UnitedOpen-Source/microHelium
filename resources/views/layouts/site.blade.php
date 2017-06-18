<!doctype html>
<html lang="{{ app()->getLocale() }}">
  <head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1" />
    <meta content='width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0' name='viewport' />
    <meta name="viewport" content="width=device-width" />
    <link rel="icon" type="image/png" href="{{ asset('img/favicon.ico') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Helium') }}</title>
    <!--  Social tags      -->
    <meta name="keywords" content="hackathon">
    <meta name="description" content="Helping hackathons using Laravel Framework.">
    <link href="{{ asset('css/site.css') }}" rel="stylesheet">
  </head>
  <body>
    <nav class="navbar navbar-transparent navbar-absolute">
      <div class="container">
        <div class="navbar-header">
          <button type="button" class="navbar-toggle" data-toggle="collapse" data-target="#navigation-example-2">
          <span class="sr-only">Toggle navigation</span>
          <span class="icon-bar"></span>
          <span class="icon-bar"></span>
          <span class="icon-bar"></span>
          </button>
        </div>
        <div class="collapse navbar-collapse">
          <ul class="nav navbar-nav navbar-right">
            @if (Auth::guest())
            <li><a href="{{ route('login') }}">Looking to login?</a></li>
            <li><a href="{{ route('register') }}">Register</a></li>
            @else
            <li class="dropdown">
              <a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-expanded="false">
              {{ Auth::user()->name }} <span class="caret"></span>
              </a>
              <ul class="dropdown-menu" role="menu">
                <li>
                  <a href="{{ route('logout') }}" onclick="event.preventDefault();
                    document.getElementById('logout-form').submit();">Logout</a>
                  <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display: none;">
                    {{ csrf_field() }}
                  </form>
                </li>
              </ul>
            </li>
            @endif
          </ul>
        </div>
      </div>
    </nav>
    <div class="wrapper wrapper-full-page">
      <div class="full-page register-page" data-color="blue" data-image="{{ asset('img/full-screen-image.jpg') }}">
        <div class="content">
          <div class="container">
            @yield('content')
          </div>
        </div>
        <footer class="footer footer-transparent">
          <div class="container">
            <p class="copyright text-center">
              &copy; 2017 <a href="http://www.creative-tim.com">UniteOpenSource</a>, made with love for a better web
            </p>
          </div>
        </footer>
      </div>
    </div>
  </body>
  <script src="{{ asset('js/app.js') }}"></script>
  <script type="text/javascript">
    $().ready(function(){
        lbd.checkFullPageBackgroundImage();
          setTimeout(function(){
            // after 1000 ms we add the class animated to the login/register card
            $('.card').removeClass('card-hidden');
        }, 1000)
    });
  </script>
</html>

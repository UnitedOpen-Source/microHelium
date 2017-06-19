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
    <div class="wrapper">
      <div class="sidebar" data-color="blue" data-image="{{ asset('img/sidebar.jpg') }}">
        <div class="sidebar-wrapper">
          <div class="logo">
            <a href="#" class="simple-text">
            <img src="{{ asset('img/Logo.png') }}" width="200px" height="70px" />
            </a>
          </div>
          <ul class="nav">
            <li class="no-active">
              <a href="#"><i class="pe-7s-wristwatch"></i><p>Cronometro: 00:00</p>
              </a>
            </li>
          </ul>
          <ul class="nav">
            <li class="{{ Request::is('home') ? 'active' : '' }}">
              <a href="/home">
                <i class="pe-7s-graph"></i><p>Dashboard</p>
              </a>
            </li>
            <li class="{{ Request::is('exercises') ? 'active' : '' }}">
              <a href="/exercises">
                <i class="pe-7s-note2"></i><p>Exercícios</p>
              </a>
            </li>
            <li class="{{ Request::is('scoreboard') ? 'active' : '' }}">
              <a href="/scoreboard">
                <i class="pe-7s-graph2"></i><p>Placar</p>
              </a>
            </li>
            <li class="{{ Request::is('more-info') ? 'active' : '' }}">
              <a href="/more-info">
                <i class="pe-7s-help1"></i><p>Ajuda/Regulamento</p>
              </a>
            </li>
          </ul>
        </div>
      </div>
      <div class="main-panel">
        <nav class="navbar navbar-default navbar-fixed">
          <div class="container-fluid">
            <div class="navbar-header">
              <button type="button" class="navbar-toggle" data-toggle="collapse" data-target="#navigation-example-2">
              <span class="sr-only">Alternar de navegação</span>
              <span class="icon-bar"></span>
              <span class="icon-bar"></span>
              <span class="icon-bar"></span>
              </button>
              <ul class="nav navbar-nav">
                <li class="dropdown">
                  <a href="#" class="dropdown-toggle" data-toggle="dropdown">
                    <p>Backend <b class="caret"></b></p>
                  </a>
                  <ul class="dropdown-menu">
                    <li><a href="dashboard.html">Nome da Maratona</a></li>
                    <li class="divider"></li>
                    <li><a href="backend/exercises">Exercícios</a></li>
                    <li><a href="backend/users">Usuários</a></li>
                    <li><a href="backend/teams">Times</a></li>
                    <li><a href="backend/configurations">Configurações</a></li>
                    <li class="divider"></li>
                    <li><a href="#"><b class="pe-7s-play"></b> Cronometro: Começar</a></li>
                    <li><a href="#"><b class="pe-7s-stopwatch"></b> Cronometro: Pausar</a></li>
                    <li><a href="#"><b class="pe-7s-timer"></b> Cronometro: Reiniciar</a></li>
                  </ul>
                </li>
              </ul>
            </div>
            <div class="collapse navbar-collapse">
              <ul class="nav navbar-nav navbar-right">
                @if (Auth::check())
                <li>
                  <a href="./my-team"><p>Meu Time</p></a>
                </li>
                <li>
                  <a href="./my-account/{{Auth::user()->id}}"><p>Minha Conta</p></a>
                </li>
                <li>
                  <a href="{{ route('logout') }}" onclick="event.preventDefault();
                  document.getElementById('logout-form').submit();"><p>Logout</p></a>
                  <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display: none;">
                    {{ csrf_field() }}
                  </form>
                </li>
                @endif
                <li class="separator hidden-lg hidden-md"></li>
              </ul>
            </div>
          </div>
        </nav>
        <div class="content">
          <div class="container-fluid">
            @yield('content')
          </div>
        </div>
        <footer class="footer">
          <div class="container-fluid">
            <nav class="pull-left">
              <ul>
                <li><a href="#">Início</a></li>
                <li><a href="#">Regulamento</a></li>
                <li><a href="#">Ajuda</a></li>
                <li><a href="#">Mais Informações</a></li>
              </ul>
            </nav>
            <p class="copyright pull-right">
              &copy; <script>document.write(new Date().getFullYear())</script> <a href="#">UnitedOpenSource</a>, feito com amor para uma internet melhor
            </p>
          </div>
        </footer>
      </div>
    </div>
    <!-- Scripts -->
    <script src="{{ asset('js/app.js') }}"></script>
  </body>
</html>

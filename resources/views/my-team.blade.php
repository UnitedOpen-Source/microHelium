@extends('layouts.app')
@section('content')
<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="header">
                <h4 class="title">Editar Minha Conta</h4>
            </div>
            <div class="content">
                <form>
                    <div class="row">
                        <div class="col-md-5">
                            <div class="form-group">
                                <label>Nome da Equipe (desabilitado)</label>
                                <input type="text" class="form-control" disabled placeholder="Nome da Equipe" value="Hackerz Inc.">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Nome de Usuário</label>
                                <input type="text" class="form-control" placeholder="Username" value="matheusrv">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="exampleInputEmail1">Email</label>
                                <input type="email" class="form-control" placeholder="Email" value="matheusrv@email.com">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-5">
                            <div class="form-group">
                                <label>Primeiro Nome</label>
                                <input type="text" class="form-control" placeholder="Nome" value="Matheus">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Sobrenome</label>
                                <input type="text" class="form-control" placeholder="Sobrenome" value="Rocha Vieira">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Data de Nascimento</label>
                                <input type="text" class="form-control" placeholder="DD/MM/AAAA">
                            </div>
                        </div>
                    </div>


                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Sobre Mim</label>
                                <textarea rows="5" class="form-control" placeholder="Aqui você pode adicionar uma breve descrição..."></textarea>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-info btn-fill pull-right">Atualizar Perfil</button>
                    <div class="clearfix"></div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-user">
            <div class="image">
                <img src="https://ununsplash.imgix.net/photo-1425036458755-dc303a604201?fit=crop&fm=jpg&h=300&q=75&w=400" alt="..."/>
            </div>
            <div class="content">
                <div class="author">
                     <a href="#">
                    <img class="avatar border-gray" src="assets/img/avatar/avatar-matheusrv.png" alt="..."/>

                      <h4 class="title">Matheus Rocha Vieira<br />
                         <small>matheusrv</small>
                      </h4>
                    </a>
                </div>
                <p class="description text-center"> "Aluno de Sistemas de Informação <br>
                                    membro da CPTI, empreendedor, <br>
                                    sem muita criatividade no momento"
                </p>
            </div>
            <hr>
            <div class="text-center">
                <button href="#" class="btn btn-simple"><i class="fa fa-facebook-square"></i></button>
                <button href="#" class="btn btn-simple"><i class="fa fa-twitter"></i></button>
                <button href="#" class="btn btn-simple"><i class="fa fa-google-plus-square"></i></button>

            </div>
        </div>
    </div>

</div>
@endsection

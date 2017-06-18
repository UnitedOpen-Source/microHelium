<?php

namespace Helium\Http\Controllers;

use Helium\UserTeam;
use Illuminate\Http\Request;

class TeamController extends Controller
{
  public function index()
  {
      $userTeams = UserTeam::orderBy('created_at', 'desc')->paginate(10);
      return view('scoreboard',['userTeams' => $userTeams]);
  }
  public function create()
  {
      return view('userTeams.create');
  }
  public function store(UserTeamRequest $request)
  {
      $userTeam = new UserTeam;
      $userTeam->name      = $request->name;
      $userTeam->userTeamName  = $request->userTeamName;
      $userTeam->team      = $request->team;
      $userTeam->email     = $request->email;
      $userTeam->save();
      return redirect()->route('usersTeams.index')->with('message', 'Team created successfully!');
  }
  public function show(UserTeam $userTeam)
  {
      //
  }
  public function edit(UserTeam $userTeam)
  {
    $userTeam = UserTeam::findOrFail($id);
    return view('usersTeams.edit',compact('userTeam'));
  }
  public function update(UserTeamRequest $request, UserTeam $userTeam)
  {
    $userTeam = UserTeam::findOrFail($id);
    $userTeam->name      = $request->name;
    $userTeam->userTeamName  = $request->userTeamName;
    $userTeam->team      = $request->team;
    $userTeam->email     = $request->email;
    $userTeam->save();
    return redirect()->route('usersTeams.index')->with('message', 'Team updated successfully!');
  }
  public function destroy(UserTeam $userTeam)
  {
      $userTeam = UserTeam::findOrFail($id);
      $userTeam->delete();
      return redirect()->route('usersTeams.index')->with('alert-success','Team hasbeen deleted!');
  }
}

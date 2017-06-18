<?php

namespace Helium\Http\Controllers;

use Helium\Team;
use Illuminate\Http\Request;

class TeamController extends Controller
{
  public function index()
  {
      $teams = Team::orderBy('created_at', 'desc')->paginate(10);
      return view('scoreboard',['teams' => $teams]);
  }
  public function create()
  {
      return view('teams.create');
  }
  public function store(TeamRequest $request)
  {
      $team = new Team;
      $team->name      = $request->name;
      $team->teamName  = $request->teamName;
      $team->team      = $request->team;
      $team->email     = $request->email;
      $team->save();
      return redirect()->route('usersTeams.index')->with('message', 'Team created successfully!');
  }
  public function show(Team $team)
  {
      //
  }
  public function edit(Team $team)
  {
    $team = Team::findOrFail($id);
    return view('my-team',compact('team'));
  }
  public function update(TeamRequest $request, Team $team)
  {
    $team = Team::findOrFail($id);
    $team->name      = $request->name;
    $team->teamName  = $request->teamName;
    $team->team      = $request->team;
    $team->email     = $request->email;
    $team->save();
    return redirect()->route('usersTeams.index')->with('message', 'Team updated successfully!');
  }

  public function getScore()
  {
      $teams = Team::orderBy('created_at', 'desc')->paginate(10);
      return view('scoreboard',['teams' => $teams]);
  }

  public function destroy(Team $team)
  {
      $team = Team::findOrFail($id);
      $team->delete();
      return redirect()->route('usersTeams.index')->with('alert-success','Team hasbeen deleted!');
  }
}

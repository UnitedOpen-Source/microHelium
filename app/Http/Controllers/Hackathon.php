<?php

namespace Helium\Http\Controllers;

use Helium\Hackathon;
use Illuminate\Http\Request;

class Hackathon extends Controller
{
  public function index()
  {
      $hackathons = Hackathon::orderBy('created_at', 'desc')->paginate(10);
      return view('hackathons.index',['hackathons' => $hackathons]);
  }
  public function create()
  {
      return view('hackathons.create');
  }
  public function store(HackathonRequest $request)
  {
      $hackathon = new Hackathon;
      $hackathon->name      = $request->name;
      $hackathon->hackathonName  = $request->hackathonName;
      $hackathon->team      = $request->team;
      $hackathon->email     = $request->email;
      $hackathon->save();
      return redirect()->route('hackathons.index')->with('message', 'Hackathon created successfully!');
  }
  public function show(Hackathon $hackathon)
  {
      //
  }
  public function edit(Hackathon $hackathon)
  {
    $hackathon = Hackathon::findOrFail($id);
    return view('hackathons.edit',compact('hackathon'));
  }
  public function update(HackathonRequest $request, Hackathon $hackathon)
  {
    $hackathon = Hackathon::findOrFail($id);
    $hackathon->name      = $request->name;
    $hackathon->hackathonName  = $request->hackathonName;
    $hackathon->team      = $request->team;
    $hackathon->email     = $request->email;
    $hackathon->save();
    return redirect()->route('hackathons.index')->with('message', 'Hackathon updated successfully!');
  }
  public function destroy(Hackathon $hackathon)
  {
      $hackathon = Hackathon::findOrFail($id);
      $hackathon->delete();
      return redirect()->route('hackathons.index')->with('alert-success','Hackathon hasbeen deleted!');
  }
}

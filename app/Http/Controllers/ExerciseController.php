<?php

namespace Helium\Http\Controllers;

use Helium\Exercise;
use Illuminate\Http\Request;

class ExerciseController extends Controller
{
  public function index()
  {
      $exercises = Exercise::orderBy('created_at', 'desc')->paginate(10);
      return view('exercise',['exercises' => $exercises]);
  }
  public function create()
  {
      return view('exercises.create');
  }
  public function store(ExerciseRequest $request)
  {
      $exercise = new Exercise;
      $exercise->name          = $request->name;
      $exercise->exerciseName  = $request->exerciseName;
      $exercise->team          = $request->team;
      $exercise->email         = $request->email;
      $exercise->save();
      return redirect()->route('exercises.index')->with('message', 'Exercise created successfully!');
  }
  public function show(Exercise $exercise)
  {
      //
  }
  public function edit(Exercise $exercise)
  {
    $exercise = Exercise::findOrFail($id);
    return view('exercises.edit',compact('exercise'));
  }
  public function update(ExerciseRequest $request, Exercise $exercise)
  {
    $exercise = Exercise::findOrFail($id);
    $exercise->name      = $request->name;
    $exercise->exerciseName  = $request->exerciseName;
    $exercise->team      = $request->team;
    $exercise->email     = $request->email;
    $exercise->save();
    return redirect()->route('exercises.index')->with('message', 'Exercise updated successfully!');
  }
  public function destroy(Exercise $exercise)
  {
      $exercise = Exercise::findOrFail($id);
      $exercise->delete();
      return redirect()->route('exercises.index')->with('alert-success','Exercise hasbeen deleted!');
  }
}

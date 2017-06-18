<?php

namespace Helium\Http\Controllers;

use Helium\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $users = User::orderBy('created_at', 'desc')->paginate(10);
        return view('users.index',['users' => $users]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('users.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $user = new User;
        $user->name      = $request->name;
        $user->username  = $request->username;
        $user->team      = $request->team;
        $user->email     = $request->email;
        $user->save();
        return redirect()->route('backend.users')->with('message', 'User created successfully!');
    }

    /**
     * Display the specified resource.
     *
     * @param  \Helium\User  $user
     * @return \Illuminate\Http\Response
     */
    public function show(User $user)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \Helium\User  $user
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
      $user = User::findOrFail($id);
      return view('users.edit',compact('user'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Helium\User  $user
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, User $user)
    {
      $user = User::findOrFail($id);
      $user->name      = $request->name;
      $user->username  = $request->username;
      $user->team      = $request->team;
      $user->email     = $request->email;
      $user->save();
      return redirect()->route('users.index')->with('message', 'User updated successfully!');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \Helium\User  $user
     * @return \Illuminate\Http\Response
     */
    public function destroy(User $user)
    {
        $user = User::findOrFail($id);
        $user->delete();
        return redirect()->route('users.index')->with('alert-success','User hasbeen deleted!');
    }
}

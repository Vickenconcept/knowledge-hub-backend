<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    public function changeName(Request $request){
       
        $data =  $request->validate([
            'name' => 'required',
            'email' => 'required|email',
        ]);
        $user = auth()->user();
        $user->update($data);

        return redirect()->back()->with('success','updated successfully');
    }

    public function changePassword(Request $request){

        $password = $request->input('password');
        $new_password = $request->input('new_password');
       
        $data =  $request->validate(  [
            'password' => 'required',
            'new_password' => 'required|string',
        ]);
        $user = auth()->user();

        if (Hash::check($password, $user->password)) {
            $user->password = Hash::make($new_password);
            $user->save();

            Auth::logout();
            return redirect()->to('login');
    
        } else {
            return redirect()->back()->with('error', 'Incorrect old password. Password not changed.');
        }
    }
}

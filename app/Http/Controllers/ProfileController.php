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
        $user = $request->user();
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
        $user = $request->user();

        if (Hash::check($password, $user->password)) {
            $user->password = Hash::make($new_password);
            $user->save();

            Auth::logout();
            return redirect()->to('login');
    
        } else {
            return redirect()->back()->with('error', 'Incorrect old password. Password not changed.');
        }
    }

    /**
     * Get current user profile (API)
     */
    public function show(Request $request)
    {
        $user = $request->user();
        
        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'default_response_style' => $user->default_response_style,
                'ai_preferences' => $user->ai_preferences,
                'org_id' => $user->org_id,
                'created_at' => $user->created_at,
            ],
            'organization' => $user->organization,
        ]);
    }

    /**
     * Update current user profile (API)
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'default_response_style' => 'sometimes|string|in:comprehensive,structured_profile,summary_report,qa_friendly,bullet_brief,executive_summary',
            'ai_preferences' => 'sometimes|array',
        ]);

        $user = $request->user();
        $user->update($validated);

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user->fresh(),
        ]);
    }

    /**
     * Update user password (API)
     */
    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'error' => 'Current password is incorrect',
            ], 400);
        }

        $user->password = Hash::make($validated['new_password']);
        $user->save();

        return response()->json([
            'message' => 'Password updated successfully',
        ]);
    }

    /**
     * Update organization details (API)
     */
    public function updateOrganization(Request $request)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'settings' => 'sometimes|array',
        ]);

        $user = $request->user();
        $organization = $user->organization;

        if (!$organization) {
            return response()->json([
                'error' => 'Organization not found',
            ], 404);
        }

        // Check if user has permission to update organization
        // Only admins or the owner can update organization
        if ($user->role !== 'admin' && $organization->owner_id !== $user->id) {
            return response()->json([
                'error' => 'Unauthorized - Admin or owner access required',
            ], 403);
        }

        $organization->update($validated);

        return response()->json([
            'message' => 'Organization updated successfully',
            'organization' => $organization->fresh(),
        ]);
    }
}

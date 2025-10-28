<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Mail\InviteMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TeamController extends Controller
{
    /**
     * Get all users in the organization
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $orgId = $user->org_id;
            
            $users = User::where('org_id', $orgId)
                ->orderBy('created_at', 'asc') // Owner first (oldest)
                ->get(['id', 'name', 'email', 'role', 'created_at', 'default_response_style']);
            
            // Get organization info
            $org = \App\Models\Organization::find($orgId);
            
            // Mark owner in user list
            $usersWithOwner = $users->map(function($u) use ($org) {
                return array_merge($u->toArray(), [
                    'is_owner' => $u->id === $org->owner_id,
                ]);
            });
            
            // Get tier limits
            $limits = \App\Services\UsageLimitService::canAddUser($orgId);
            
            // Calculate remaining users safely
            $currentUsage = $limits['current_usage'] ?? count($users);
            $maxLimit = $limits['limit'] ?? 999999;
            $remaining = $limits['remaining'] ?? max(0, $maxLimit - $currentUsage);
            
            return response()->json([
                'users' => $usersWithOwner,
                'organization' => [
                    'id' => $org->id,
                    'name' => $org->name,
                    'owner_id' => $org->owner_id,
                ],
                'limits' => [
                    'current' => $currentUsage,
                    'max' => $maxLimit,
                    'can_add' => $limits['allowed'] ?? true,
                    'remaining' => $remaining,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching team members', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'error' => 'Failed to fetch team members',
            ], 500);
        }
    }
    
    /**
     * Invite new user to organization
     */
    public function invite(Request $request)
    {
        try {
            $user = $request->user();
            $orgId = $user->org_id;
            
            // CHECK USER LIMIT BEFORE INVITING
            $userLimit = \App\Services\UsageLimitService::canAddUser($orgId);
            if (!$userLimit['allowed']) {
                return response()->json([
                    'error' => 'User limit exceeded',
                    'message' => $userLimit['reason'],
                    'limit_type' => 'max_users',
                    'current_usage' => $userLimit['current_usage'],
                    'limit' => $userLimit['limit'],
                    'tier' => $userLimit['tier'],
                    'upgrade_required' => true,
                ], 429);
            }
            
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'role' => 'nullable|in:admin,user',
                'send_email' => 'nullable|boolean',
            ]);
            
            // Generate random password
            $tempPassword = Str::random(12);
            
            // Create user
            $newUser = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($tempPassword),
                'org_id' => $orgId,
                'role' => $validated['role'] ?? 'user',
            ]);
            
            // Send invitation email if requested
            if ($validated['send_email'] ?? true) {
                try {
                    Mail::to($newUser->email)->send(new InviteMail(
                        $newUser,
                        $tempPassword,
                        $user->organization->name ?? 'KHub'
                    ));
                    
                    Log::info('Invitation email sent', [
                        'to' => $newUser->email,
                        'org_id' => $orgId,
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Failed to send invitation email', [
                        'error' => $e->getMessage(),
                        'to' => $newUser->email,
                    ]);
                    // Don't fail the whole request if email fails
                }
            }
            
            Log::info('Team member invited', [
                'user_id' => $newUser->id,
                'email' => $newUser->email,
                'org_id' => $orgId,
                'invited_by' => $user->id,
            ]);
            
            return response()->json([
                'message' => 'Team member invited successfully',
                'user' => $newUser,
                'temp_password' => $tempPassword, // Return for manual sharing if email fails
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error inviting team member', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'error' => 'Failed to invite team member',
                'message' => $e->getMessage(),
            ], 400);
        }
    }
    
    /**
     * Update user role
     */
    public function updateRole(Request $request, $id)
    {
        try {
            $currentUser = $request->user();
            $orgId = $currentUser->org_id;
            
            // Only admins can update roles
            if ($currentUser->role !== 'admin' && $currentUser->role !== 'super_admin') {
                return response()->json([
                    'error' => 'Forbidden - Admin access required',
                ], 403);
            }
            
            $validated = $request->validate([
                'role' => 'required|in:admin,user',
            ]);
            
            $targetUser = User::where('id', $id)
                ->where('org_id', $orgId)
                ->firstOrFail();
            
            // Can't change own role
            if ($targetUser->id === $currentUser->id) {
                return response()->json([
                    'error' => 'Cannot change your own role',
                ], 400);
            }
            
            // Check if target is organization owner
            $org = \App\Models\Organization::find($orgId);
            if ($org->owner_id === $targetUser->id) {
                return response()->json([
                    'error' => 'Cannot change the organization owner\'s role. The owner must always be an admin.',
                ], 403);
            }
            
            $targetUser->role = $validated['role'];
            $targetUser->save();
            
            Log::info('User role updated', [
                'user_id' => $targetUser->id,
                'new_role' => $validated['role'],
                'updated_by' => $currentUser->id,
            ]);
            
            return response()->json([
                'message' => 'User role updated successfully',
                'user' => $targetUser,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to update user role',
            ], 400);
        }
    }
    
    /**
     * Remove user from organization
     */
    public function remove(Request $request, $id)
    {
        try {
            $currentUser = $request->user();
            $orgId = $currentUser->org_id;
            
            // Only admins can remove users
            if ($currentUser->role !== 'admin' && $currentUser->role !== 'super_admin') {
                return response()->json([
                    'error' => 'Forbidden - Admin access required',
                ], 403);
            }
            
            $targetUser = User::where('id', $id)
                ->where('org_id', $orgId)
                ->firstOrFail();
            
            // Can't remove self
            if ($targetUser->id === $currentUser->id) {
                return response()->json([
                    'error' => 'Cannot remove yourself from the organization',
                ], 400);
            }
            
            // PROTECTION: Can't remove organization owner
            $org = \App\Models\Organization::find($orgId);
            if ($org->owner_id === $targetUser->id) {
                return response()->json([
                    'error' => 'Cannot remove the organization owner. Transfer ownership first or delete the organization.',
                ], 403);
            }
            
            $userName = $targetUser->name;
            $targetUser->delete();
            
            Log::info('User removed from organization', [
                'removed_user_id' => $id,
                'removed_user_name' => $userName,
                'removed_by' => $currentUser->id,
                'org_id' => $orgId,
            ]);
            
            return response()->json([
                'message' => 'User removed successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to remove user',
            ], 400);
        }
    }
}


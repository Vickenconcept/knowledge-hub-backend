<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminOrganizationController extends Controller
{
    /**
     * Check if user is super_admin
     */
    private function checkSuperAdmin(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'super_admin') {
            return response()->json([
                'error' => 'Forbidden - Super admin access required',
            ], 403);
        }
        return null;
    }

    /**
     * List all organizations with their users
     */
    public function index(Request $request)
    {
        try {
            // Check super admin access
            $checkResult = $this->checkSuperAdmin($request);
            if ($checkResult) {
                return $checkResult;
            }
            $organizations = Organization::with(['users' => function($query) {
                $query->select('id', 'name', 'email', 'role', 'created_at', 'org_id')
                      ->orderBy('created_at', 'asc'); // Owner first
            }])
            ->with(['owner:id,name,email'])
            ->withCount(['users', 'documents', 'connectors'])
            ->orderBy('created_at', 'desc')
            ->get();

            // Get billing information for each organization
            $organizationsWithBilling = $organizations->map(function($org) {
                $billing = DB::table('organization_billing')
                    ->join('pricing_tiers', 'organization_billing.pricing_tier_id', '=', 'pricing_tiers.id')
                    ->where('organization_billing.org_id', $org->id)
                    ->select('pricing_tiers.name', 'pricing_tiers.display_name', 'organization_billing.status')
                    ->first();

                return array_merge($org->toArray(), [
                    'subscription' => [
                        'plan' => $billing->name ?? 'unknown',
                        'plan_display' => $billing->display_name ?? 'Unknown',
                        'status' => $billing->status ?? 'unknown',
                    ],
                    'owner' => [
                        'id' => $org->owner->id ?? null,
                        'name' => $org->owner->name ?? null,
                        'email' => $org->owner->email ?? null,
                    ],
                ]);
            });

            return response()->json([
                'organizations' => $organizationsWithBilling,
                'total' => $organizationsWithBilling->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error listing organizations', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to fetch organizations',
            ], 500);
        }
    }

    /**
     * Get single organization with full details
     */
    public function show(Request $request, string $id)
    {
        try {
            // Check super admin access
            $checkResult = $this->checkSuperAdmin($request);
            if ($checkResult) {
                return $checkResult;
            }
            $organization = Organization::with(['users', 'owner', 'connectors', 'documents'])
                ->withCount(['users', 'documents', 'connectors'])
                ->findOrFail($id);

            $billing = DB::table('organization_billing')
                ->join('pricing_tiers', 'organization_billing.pricing_tier_id', '=', 'pricing_tiers.id')
                ->where('organization_billing.org_id', $organization->id)
                ->select('pricing_tiers.*', 'organization_billing.*')
                ->first();

            return response()->json([
                'organization' => array_merge($organization->toArray(), [
                    'subscription' => $billing,
                ]),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Organization not found',
            ], 404);
        }
    }

    /**
     * Update organization details
     */
    public function update(Request $request, string $id)
    {
        try {
            // Check super admin access
            $checkResult = $this->checkSuperAdmin($request);
            if ($checkResult) {
                return $checkResult;
            }
            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'owner_id' => 'sometimes|string|exists:users,id',
            ]);

            $organization = Organization::findOrFail($id);

            // Update organization
            $organization->update($validated);

            Log::info('Organization updated', [
                'org_id' => $organization->id,
                'changes' => array_keys($validated),
                'updated_by' => $request->user()->id,
            ]);

            return response()->json([
                'message' => 'Organization updated successfully',
                'organization' => $organization->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to update organization',
            ], 400);
        }
    }

    /**
     * Activate an organization
     */
    public function activate(Request $request, string $id)
    {
        try {
            // Check super admin access
            $checkResult = $this->checkSuperAdmin($request);
            if ($checkResult) {
                return $checkResult;
            }
            $organization = Organization::findOrFail($id);

            // Update billing status to active
            DB::table('organization_billing')
                ->where('org_id', $organization->id)
                ->update(['status' => 'active']);

            Log::info('Organization activated', [
                'org_id' => $organization->id,
                'activated_by' => $request->user()->id,
            ]);

            return response()->json([
                'message' => 'Organization activated successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error activating organization', [
                'org_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to activate organization',
            ], 500);
        }
    }

    /**
     * Deactivate an organization (suspends all users)
     */
    public function deactivate(Request $request, string $id)
    {
        try {
            // Check super admin access
            $checkResult = $this->checkSuperAdmin($request);
            if ($checkResult) {
                return $checkResult;
            }
            $organization = Organization::findOrFail($id);

            // Update billing status to suspended
            DB::table('organization_billing')
                ->where('org_id', $organization->id)
                ->update(['status' => 'suspended']);

            Log::info('Organization deactivated', [
                'org_id' => $organization->id,
                'deactivated_by' => $request->user()->id,
            ]);

            return response()->json([
                'message' => 'Organization deactivated successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error deactivating organization', [
                'org_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to deactivate organization',
            ], 500);
        }
    }

    /**
     * Delete an organization and all associated data (CASCADE)
     */
    public function destroy(Request $request, string $id)
    {
        try {
            // Check super admin access
            $checkResult = $this->checkSuperAdmin($request);
            if ($checkResult) {
                return $checkResult;
            }
            $organization = Organization::with(['users', 'documents', 'connectors'])->findOrFail($id);

            $userCount = $organization->users->count();
            $documentCount = $organization->documents->count();
            $connectorCount = $organization->connectors->count();

            // Delete the organization (CASCADE deletes related data)
            $organization->delete();

            Log::warning('Organization deleted with cascade', [
                'org_id' => $id,
                'org_name' => $organization->name,
                'deleted_users' => $userCount,
                'deleted_documents' => $documentCount,
                'deleted_connectors' => $connectorCount,
                'deleted_by' => $request->user()->id,
            ]);

            return response()->json([
                'message' => 'Organization and all associated data deleted successfully',
                'deleted' => [
                    'users' => $userCount,
                    'documents' => $documentCount,
                    'connectors' => $connectorCount,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting organization', [
                'org_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to delete organization',
            ], 500);
        }
    }

    /**
     * Get organization users
     */
    public function users(Request $request, string $id)
    {
        try {
            // Check super admin access
            $checkResult = $this->checkSuperAdmin($request);
            if ($checkResult) {
                return $checkResult;
            }
            $organization = Organization::with(['users:id,name,email,role,created_at,org_id'])->findOrFail($id);

            return response()->json([
                'users' => $organization->users,
                'total' => $organization->users->count(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Organization not found',
            ], 404);
        }
    }
}


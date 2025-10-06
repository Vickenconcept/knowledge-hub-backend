<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        // Manual validation to ensure JSON response
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'org_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        // Create organization if not provided
        if (empty($validated['org_id'])) {
            $organization = Organization::create([
                'name' => $validated['name'] . "'s Organization",
                'settings' => [],
                'plan' => 'free',
            ]);
            $orgId = $organization->id;
        } else {
            $orgId = $validated['org_id'];
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'org_id' => $orgId,
            'role' => 'admin', // First user in organization is admin
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'organization' => $user->organization ?? null,
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        // Ensure we parse JSON properly
        if ($request->isJson()) {
            $data = $request->json()->all();
        } else {
            $data = $request->all();
        }

        // Debug: Log the request
        \Log::info('Login attempt', [
            'method' => $request->method(),
            'content_type' => $request->header('Content-Type'),
            'accept' => $request->header('Accept'),
            'is_json' => $request->isJson(),
            'raw_body' => $request->getContent(),
            'parsed_data' => $data,
        ]);

        // Manual validation to ensure JSON response
        $validator = Validator::make($data, [
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            \Log::warning('Validation failed', ['errors' => $validator->errors()]);
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        $user = User::where('email', $validated['email'])->first();

        \Log::info('User lookup result', [
            'email' => $validated['email'],
            'password_length' => strlen($validated['password']),
            'password_first_chars' => substr($validated['password'], 0, 3),
            'user_found' => $user ? true : false,
            'user_id' => $user?->id,
        ]);

        if (!$user) {
            \Log::warning('Login failed - user not found', [
                'email' => $validated['email'],
            ]);
            
            return response()->json([
                'message' => 'Invalid credentials.',
                'errors' => [
                    'email' => ['Invalid credentials.']
                ]
            ], 401);
        }

        if (!Hash::check($validated['password'], $user->password)) {
            \Log::warning('Login failed - password mismatch', [
                'email' => $validated['email'],
                'user_id' => $user->id,
                'password_provided_length' => strlen($validated['password']),
                'password_hash_prefix' => substr($user->password, 0, 10),
            ]);
            
            return response()->json([
                'message' => 'Invalid credentials.',
                'errors' => [
                    'email' => ['Invalid credentials.']
                ]
            ], 401);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        \Log::info('Login successful', [
            'user_id' => $user->id,
            'email' => $user->email,
            'token_length' => strlen($token),
        ]);

        return response()->json([
            'user' => $user,
            'organization' => $user->organization,
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['message' => 'Logged out']);
    }
}
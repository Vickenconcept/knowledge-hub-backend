<?php

namespace App\Http\Controllers\Connectors;

use App\Http\Controllers\Controller;
use App\Models\Connector;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

/**
 * Base Controller for all connector types
 * 
 * Provides common functionality for OAuth-based connectors
 */
abstract class BaseConnectorController extends Controller
{
    /**
     * Get the connector type (e.g., 'google_drive', 'slack', 'dropbox')
     */
    abstract protected function getConnectorType(): string;
    
    /**
     * Get the display label for the connector
     */
    abstract protected function getConnectorLabel(): string;
    
    /**
     * Create or update a connector with OAuth tokens
     */
    protected function createOrUpdateConnector(
        string $orgId,
        array $tokens,
        array $metadata = [],
        ?string $label = null
    ): Connector {
        $type = $this->getConnectorType();
        $displayLabel = $label ?? $this->getConnectorLabel();
        
        // Check if connector already exists
        $connector = Connector::where('org_id', $orgId)
            ->where('type', $type)
            ->first();

        if ($connector) {
            // Update existing connector
            $connector->update([
                'encrypted_tokens' => encrypt(json_encode($tokens)),
                'status' => 'connected',
                'label' => $displayLabel,
                'metadata' => array_merge($connector->metadata ?? [], $metadata),
            ]);
            
            Log::info("{$type} connector updated", [
                'connector_id' => $connector->id,
                'org_id' => $orgId,
            ]);
        } else {
            // Create new connector
            $connector = Connector::create([
                'id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'type' => $type,
                'label' => $displayLabel,
                'status' => 'connected',
                'encrypted_tokens' => encrypt(json_encode($tokens)),
                'metadata' => $metadata,
            ]);
            
            Log::info("{$type} connector created", [
                'connector_id' => $connector->id,
                'org_id' => $orgId,
            ]);
        }

        return $connector;
    }
    
    /**
     * Get connector by ID and verify ownership
     */
    protected function getConnectorByIdAndOrg(string $connectorId, string $orgId): ?Connector
    {
        return Connector::where('id', $connectorId)
            ->where('org_id', $orgId)
            ->where('type', $this->getConnectorType())
            ->first();
    }
    
    /**
     * Redirect to frontend with success/error message
     */
    protected function redirectToFrontend(bool $success, string $message = ''): \Illuminate\Http\RedirectResponse
    {
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
        $type = $this->getConnectorType();
        
        if ($success) {
            return redirect("{$frontendUrl}/connectors?{$type}_connected=true");
        } else {
            $errorParam = $message ? "error={$message}" : "error=connection_failed";
            return redirect("{$frontendUrl}/connectors?{$errorParam}");
        }
    }
}


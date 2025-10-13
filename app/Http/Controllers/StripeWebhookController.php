<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;

/**
 * Stripe Webhook Handler
 * Processes events from Stripe (payments, subscriptions, etc.)
 */
class StripeWebhookController extends Controller
{
    /**
     * Handle incoming Stripe webhook
     */
    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('services.stripe.webhook_secret');
        
        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (\UnexpectedValueException $e) {
            Log::error('Invalid Stripe payload', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (SignatureVerificationException $e) {
            Log::error('Invalid Stripe signature', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 400);
        }
        
        Log::info('Stripe webhook received', ['type' => $event->type]);
        
        // Handle different event types
        switch ($event->type) {
            case 'payment_intent.succeeded':
                $this->handlePaymentSucceeded($event->data->object);
                break;
                
            case 'payment_intent.payment_failed':
                $this->handlePaymentFailed($event->data->object);
                break;
                
            case 'customer.subscription.created':
                $this->handleSubscriptionCreated($event->data->object);
                break;
                
            case 'customer.subscription.updated':
                $this->handleSubscriptionUpdated($event->data->object);
                break;
                
            case 'customer.subscription.deleted':
                $this->handleSubscriptionCancelled($event->data->object);
                break;
                
            case 'invoice.payment_succeeded':
                $this->handleInvoicePaid($event->data->object);
                break;
                
            case 'invoice.payment_failed':
                $this->handleInvoicePaymentFailed($event->data->object);
                break;
                
            default:
                Log::info('Unhandled webhook event', ['type' => $event->type]);
        }
        
        return response()->json(['status' => 'success']);
    }
    
    /**
     * Payment succeeded
     */
    protected function handlePaymentSucceeded($paymentIntent)
    {
        Log::info('Payment succeeded', [
            'payment_intent_id' => $paymentIntent->id,
            'amount' => $paymentIntent->amount / 100,
            'customer' => $paymentIntent->customer,
        ]);
        
        // Update invoice status if this is for an invoice
        $orgId = $paymentIntent->metadata->org_id ?? null;
        
        if ($orgId) {
            DB::table('invoices')
                ->where('org_id', $orgId)
                ->where('payment_status', 'pending')
                ->latest('created_at')
                ->limit(1)
                ->update([
                    'payment_status' => 'paid',
                    'payment_date' => now(),
                    'payment_method' => 'stripe',
                    'transaction_id' => $paymentIntent->id,
                    'updated_at' => now(),
                ]);
            
            Log::info('Invoice marked as paid', ['org_id' => $orgId]);
        }
    }
    
    /**
     * Payment failed
     */
    protected function handlePaymentFailed($paymentIntent)
    {
        Log::warning('Payment failed', [
            'payment_intent_id' => $paymentIntent->id,
            'customer' => $paymentIntent->customer,
            'error' => $paymentIntent->last_payment_error->message ?? 'Unknown error',
        ]);
        
        $orgId = $paymentIntent->metadata->org_id ?? null;
        
        if ($orgId) {
            DB::table('invoices')
                ->where('org_id', $orgId)
                ->where('payment_status', 'pending')
                ->latest('created_at')
                ->limit(1)
                ->update([
                    'payment_status' => 'failed',
                    'updated_at' => now(),
                ]);
            
            // TODO: Send notification email to customer
        }
    }
    
    /**
     * Subscription created
     */
    protected function handleSubscriptionCreated($subscription)
    {
        Log::info('Subscription created', [
            'subscription_id' => $subscription->id,
            'customer' => $subscription->customer,
        ]);
    }
    
    /**
     * Subscription updated
     */
    protected function handleSubscriptionUpdated($subscription)
    {
        Log::info('Subscription updated', [
            'subscription_id' => $subscription->id,
            'status' => $subscription->status,
        ]);
    }
    
    /**
     * Subscription cancelled
     */
    protected function handleSubscriptionCancelled($subscription)
    {
        Log::warning('Subscription cancelled', [
            'subscription_id' => $subscription->id,
            'customer' => $subscription->customer,
        ]);
        
        // TODO: Handle subscription cancellation
        // - Downgrade to free tier?
        // - Send notification email
    }
    
    /**
     * Invoice paid
     */
    protected function handleInvoicePaid($invoice)
    {
        Log::info('Invoice paid', [
            'invoice_id' => $invoice->id,
            'amount' => $invoice->amount_paid / 100,
        ]);
    }
    
    /**
     * Invoice payment failed
     */
    protected function handleInvoicePaymentFailed($invoice)
    {
        Log::warning('Invoice payment failed', [
            'invoice_id' => $invoice->id,
            'customer' => $invoice->customer,
        ]);
        
        // TODO: Send notification email
        // TODO: Implement dunning management
    }
}


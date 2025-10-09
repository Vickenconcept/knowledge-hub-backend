<?php

namespace App\Services;

use Stripe\Stripe;
use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\Subscription;
use Illuminate\Support\Facades\Log;

/**
 * Stripe Payment Service
 * Handles all Stripe payment operations
 */
class StripeService
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }
    
    /**
     * Create a Stripe customer
     */
    public function createCustomer(string $email, string $name, ?string $orgId = null): array
    {
        try {
            $customer = Customer::create([
                'email' => $email,
                'name' => $name,
                'metadata' => [
                    'org_id' => $orgId,
                ],
            ]);
            
            Log::info('Stripe customer created', [
                'customer_id' => $customer->id,
                'email' => $email,
            ]);
            
            return [
                'success' => true,
                'customer_id' => $customer->id,
                'customer' => $customer,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to create Stripe customer', [
                'error' => $e->getMessage(),
                'email' => $email,
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Create a payment intent for one-time charges
     */
    public function createPaymentIntent(
        float $amount,
        string $currency = 'usd',
        ?string $customerId = null,
        ?array $metadata = []
    ): array {
        try {
            $params = [
                'amount' => (int) ($amount * 100), // Convert to cents
                'currency' => $currency,
                'automatic_payment_methods' => [
                    'enabled' => true,
                ],
                'metadata' => $metadata,
            ];
            
            if ($customerId) {
                $params['customer'] = $customerId;
            }
            
            $intent = PaymentIntent::create($params);
            
            Log::info('Payment intent created', [
                'intent_id' => $intent->id,
                'amount' => $amount,
                'customer_id' => $customerId,
            ]);
            
            return [
                'success' => true,
                'client_secret' => $intent->client_secret,
                'payment_intent_id' => $intent->id,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to create payment intent', [
                'error' => $e->getMessage(),
                'amount' => $amount,
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Create recurring subscription
     */
    public function createSubscription(
        string $customerId,
        string $priceId,
        ?array $metadata = []
    ): array {
        try {
            $subscription = Subscription::create([
                'customer' => $customerId,
                'items' => [
                    ['price' => $priceId],
                ],
                'metadata' => $metadata,
            ]);
            
            Log::info('Subscription created', [
                'subscription_id' => $subscription->id,
                'customer_id' => $customerId,
            ]);
            
            return [
                'success' => true,
                'subscription_id' => $subscription->id,
                'subscription' => $subscription,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to create subscription', [
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Charge customer for monthly invoice
     */
    public function chargeInvoice(
        string $customerId,
        float $amount,
        string $description,
        array $metadata = [],
        ?string $paymentMethodId = null
    ): array {
        try {
            $intentParams = [
                'amount' => (int) ($amount * 100),
                'currency' => 'usd',
                'customer' => $customerId,
                'description' => $description,
                'metadata' => $metadata,
                'confirm' => true,
            ];
            
            // If payment method specified, use it; otherwise use automatic
            if ($paymentMethodId) {
                $intentParams['payment_method'] = $paymentMethodId;
                $intentParams['off_session'] = true;  // Allow charging saved cards
            } else {
                $intentParams['automatic_payment_methods'] = [
                    'enabled' => true,
                    'allow_redirects' => 'never',
                ];
            }
            
            $paymentIntent = PaymentIntent::create($intentParams);
            
            $success = $paymentIntent->status === 'succeeded';
            
            Log::info('Invoice charged via Stripe', [
                'payment_intent_id' => $paymentIntent->id,
                'amount' => $amount,
                'status' => $paymentIntent->status,
                'success' => $success,
            ]);
            
            return [
                'success' => $success,
                'payment_intent_id' => $paymentIntent->id,
                'status' => $paymentIntent->status,
                'amount_charged' => $amount,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to charge invoice', [
                'error' => $e->getMessage(),
                'customer_id' => $customerId,
                'amount' => $amount,
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Get customer's payment methods
     */
    public function getPaymentMethods(string $customerId): array
    {
        try {
            $customer = Customer::retrieve($customerId);
            $paymentMethods = $customer->allPaymentMethods(['limit' => 10]);
            
            return [
                'success' => true,
                'payment_methods' => $paymentMethods->data,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Attach payment method to customer
     */
    public function attachPaymentMethod(string $customerId, string $paymentMethodId): array
    {
        try {
            $paymentMethod = \Stripe\PaymentMethod::retrieve($paymentMethodId);
            
            // Check if payment method is already attached to this customer
            if ($paymentMethod->customer !== $customerId) {
                // Only attach if not already attached to this customer
                $paymentMethod->attach(['customer' => $customerId]);
                
                Log::info('Payment method attached', [
                    'customer_id' => $customerId,
                    'payment_method_id' => $paymentMethodId,
                ]);
            } else {
                Log::info('Payment method already attached to customer', [
                    'customer_id' => $customerId,
                    'payment_method_id' => $paymentMethodId,
                ]);
            }
            
            // Set as default payment method
            Customer::update($customerId, [
                'invoice_settings' => [
                    'default_payment_method' => $paymentMethodId,
                ],
            ]);
            
            return [
                'success' => true,
                'payment_method' => $paymentMethod,
            ];
        } catch (\Exception $e) {
            // Check if the error is about payment method already being attached
            if (strpos($e->getMessage(), 'already been attached') !== false) {
                // If already attached, just set it as default and continue
                try {
                    Customer::update($customerId, [
                        'invoice_settings' => [
                            'default_payment_method' => $paymentMethodId,
                        ],
                    ]);
                    
                    Log::info('Payment method was already attached, set as default', [
                        'customer_id' => $customerId,
                        'payment_method_id' => $paymentMethodId,
                    ]);
                    
                    return [
                        'success' => true,
                        'payment_method' => \Stripe\PaymentMethod::retrieve($paymentMethodId),
                    ];
                } catch (\Exception $updateError) {
                    Log::error('Failed to set default payment method', [
                        'error' => $updateError->getMessage(),
                    ]);
                    
                    return [
                        'success' => false,
                        'error' => $updateError->getMessage(),
                    ];
                }
            }
            
            Log::error('Failed to attach payment method', [
                'error' => $e->getMessage(),
                'customer_id' => $customerId,
                'payment_method_id' => $paymentMethodId,
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}


<?php
declare(strict_types=1);

namespace App\Services\Payment;

use RuntimeException;

/**
 * Provider-neutral boundary for online payment gateway adapters.
 *
 * Implementations must never treat browser redirects or client-side query parameters
 * as proof of payment. Capture verification and webhook parsing must always be
 * authenticated server-side using the selected provider's verified protocol.
 */
interface OnlinePaymentGatewayInterface
{
    /**
     * Canonical identifier for the provider adapter (e.g. 'unconfigured', or future gateway slug).
     */
    public function providerName(): string;

    /**
     * Whether a live online payment provider has been configured and is ready to redirect customers.
     */
    public function isConfigured(): bool;

    /**
     * Create an online checkout/payment session for an active checkout attempt and temporary hold.
     *
     * @param array{
     *   attempt_id:int,
     *   attempt_token:string,
     *   business_unit_id:int,
     *   customer_id:int,
     *   amount:float,
     *   currency:string,
     *   expires_at_utc:string,
     *   items:array<int,array{business_product_id:int,product_name:string,quantity:int,unit_price:float}>,
     *   success_return_url:string,
     *   cancel_return_url:string
     * } $sessionContext
     * @return array{
     *   configured:bool,
     *   provider_name:string,
     *   provider_session_id:?string,
     *   checkout_url:?string
     * }
     * @throws RuntimeException if session creation fails at a configured provider
     */
    public function createCheckoutSession(array $sessionContext): array;

    /**
     * Verify captured payment status directly with the provider server-side.
     *
     * @return array{
     *   status:string,
     *   provider_name:string,
     *   provider_session_id:?string,
     *   provider_payment_id:?string,
     *   captured_amount:float,
     *   payment_method:string,
     *   failure_reason:?string
     * }
     * @throws RuntimeException when no provider is configured or verification fails
     */
    public function verifyPaymentCapture(string $providerSessionId, ?string $providerPaymentId = null): array;

    /**
     * Authenticate and normalize an incoming provider webhook payload into a provider-neutral event.
     *
     * @param array<string,string> $headers
     * @return array{
     *   provider_name:string,
     *   provider_event_id:string,
     *   event_type:string,
     *   provider_session_id:?string,
     *   provider_payment_id:?string,
     *   attempt_token:?string,
     *   captured_amount:?float,
     *   payment_method:?string,
     *   failure_reason:?string,
     *   raw_payload:string
     * }
     * @throws RuntimeException when signature verification fails or no provider is configured
     */
    public function parseAndVerifyWebhook(string $rawBody, array $headers): array;

    /**
     * Integration point for initiating a provider refund (e.g. during post-expiry reconciliation
     * or approved refund payout). Financial refunds remain independent from physical stock returns.
     *
     * @param array{
     *   provider_payment_id:string,
     *   refund_amount:float,
     *   reason:string,
     *   idempotency_key:string
     * } $refundContext
     * @return array{
     *   provider_refund_id:string,
     *   status:string
     * }
     * @throws RuntimeException when no provider is configured or refund API fails
     */
    public function initiateRefund(array $refundContext): array;
}

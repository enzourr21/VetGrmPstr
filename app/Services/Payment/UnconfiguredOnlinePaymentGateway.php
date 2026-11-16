<?php
declare(strict_types=1);

namespace App\Services\Payment;

use RuntimeException;

/**
 * Default placeholder gateway used before a live online payment provider is selected.
 * Preserves the temporary hold and checkout-attempt lifecycle without inventing fake
 * gateway endpoints, credentials, webhook signatures, or simulated payment captures.
 */
final class UnconfiguredOnlinePaymentGateway implements OnlinePaymentGatewayInterface
{
    public function providerName(): string
    {
        return 'unconfigured';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function createCheckoutSession(array $sessionContext): array
    {
        return [
            'configured' => false,
            'provider_name' => $this->providerName(),
            'provider_session_id' => null,
            'checkout_url' => null,
        ];
    }

    public function verifyPaymentCapture(string $providerSessionId, ?string $providerPaymentId = null): array
    {
        throw new RuntimeException('No online payment provider is configured for server-side payment verification.');
    }

    public function parseAndVerifyWebhook(string $rawBody, array $headers): array
    {
        throw new RuntimeException('No online payment provider is configured for webhook verification.');
    }

    public function initiateRefund(array $refundContext): array
    {
        throw new RuntimeException('No online payment provider is configured for provider refund execution.');
    }
}

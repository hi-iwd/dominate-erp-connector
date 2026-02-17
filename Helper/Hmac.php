<?php

namespace Dominate\ErpConnector\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;

/**
 * HMAC signature verification helper.
 */
class Hmac extends AbstractHelper
{
    /**
     * Verify HMAC signature.
     *
     * @param string $apiKey
     * @param int    $timestamp
     * @param string $signature
     * @param string $apiSecret
     * @return bool
     */
    public function verify(string $apiKey, int $timestamp, string $signature, string $apiSecret): bool
    {
        $expected = hash_hmac('sha256', $apiKey . ':' . $timestamp, $apiSecret);

        return hash_equals($expected, $signature);
    }

    /**
     * Check if timestamp is within valid window (5 minutes).
     *
     * @param int $timestamp
     * @return bool
     */
    public function isTimestampValid(int $timestamp): bool
    {
        return abs(time() - $timestamp) <= 300;
    }
}


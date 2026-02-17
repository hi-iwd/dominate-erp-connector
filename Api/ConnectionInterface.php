<?php

namespace Dominate\ErpConnector\Api;

/**
 * Connection check service interface.
 */
interface ConnectionInterface
{
    /**
     * Check connection from Laravel to Magento 2.
     *
     * @param string $api_key
     * @param int    $timestamp
     * @param string $signature
     * @param string $platform
     * @param string $website_url
     * @return mixed[] Response array with 'Error' boolean and optional 'ErrorCode'
     */
    public function check(
        string $api_key,
        int    $timestamp,
        string $signature,
        string $platform,
        string $website_url
    );
}


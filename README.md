**Dominate ERP Connector Extension**

Connect your Magento 2 store to ERP for real-time, bidirectional data sync.

## Features

**Outbound (Magento to ERP)**
- Order export on placement
- Shipment and tracking updates
- Credit memo / refund export
- Customer and address create, update, and delete sync

**Inbound (ERP to Magento)**
- Product import (simple and configurable) with automatic attribute option creation
- Inventory / stock level sync
- Fulfillment status updates
- Refund processing

**Architecture**
- Asynchronous queue with one-minute cron processing
- Automatic retry with exponential backoff
- HMAC-SHA256 API authentication
- REST API endpoints for all inbound operations
- Admin configuration panel with connection status display

## Requirements

- Magento 2.4.x (2.4.6 / 2.4.7 / 2.4.8)
- PHP 8.1, 8.2, 8.3, or 8.4
- Dominate SaaS subscription (https://dominate.co)

## Installation

```
composer require dominate/module-erp-connector
bin/magento module:enable Dominate_ErpConnector
bin/magento setup:upgrade
```

## Configuration

Navigate to **Stores > Configuration > Dominate > ERP Connector** to:
- Enable/disable the connector
- Enter your API key and secret
- Verify connection status

## Support

support@dominate.co

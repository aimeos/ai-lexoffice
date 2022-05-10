# Aimeos Lexoffice

Push orders from Aimeos to Lexoffice web API.

## Installation

Use composer to install the `aimeos/ai-lexoffice` extension:

```
composer req aimeos/ai-lexoffice
```

## Configuration

For your delivery options in the **Setup > Service** panel of the Aimeos admin backend,
use "Lexoffice" as value in the **Provider** field in the service details:

![admin-service-lexoffice](https://user-images.githubusercontent.com/8647429/167571599-a2a916e1-038a-45f1-be2f-77652c3bc040.png)


Available settings are:

- **lexoffice.apikey (required)**: API key you must generate in your Lexoffice account
- **lexoffice.shipping-days (optional)**: Maximum days until order will be shipped
- **lexoffice.payment-days (optional)**: Days until payment is marked as overdue in Lexoffice

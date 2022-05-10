# Aimeos Lexoffice

Push orders from Aimeos to Lexoffice web API.

## Configuration

For your delivery options in the Setup > Service panel of the Aimeos admin backend,
use "Lexoffice" as value in the **Provider** field in the service details.

Available settings are:

lexoffice.apikey (required)
: API key you must generate in your Lexoffice account

lexoffice.shipping-days (optional)
: Maximum days until order will be shipped

lexoffice.payment-days (optional)
: Days until payment is marked as overdue in Lexoffice
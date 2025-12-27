# Sigenergy Battery optimiser

Reads appropriate energy prices through api abstracted behind PriceProviderInterface.
Calculates when to optimally charge/discharge/idle battery based on daily price variations.
Runs artisan command through scheduler/cron to send appropriate command to Sigenergy station.

1. Add sigenergy api details to .env
2. Add sigenergy MCPP certs to /cert. You need to download them from sigenery admin dashboard after "onboarding" the system ID to mqtt service

# Quick Booking for Nextcloud

A Nextcloud app that books Calendar appointment schedules in one go: pick a time,
enter details, confirm, booked. No email confirmation round trip.

![Quick Booking: pick a date and a free time](docs/screenshots/1-date-time.png)

New in 0.6.0: **one-time appointment schedules**. Offer appointments on one or more
chosen dates (e.g. parent-teacher conferences) instead of every week. They have
their own section in the Calendar app, below "Appointment schedules", and are
shared with a normal Calendar booking link.

- App and full documentation: [`quickbook/`](quickbook/README.md)
- Dev environment (Docker): [`dev/`](dev/)
- Build the installable archive: `make package` → `dist/quickbook-<version>.tar.gz` + `.sha256`

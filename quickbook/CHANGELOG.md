# Changelog

The Nextcloud App Store shows the section of the released version.

## 0.6.3 – 2026-11-22

### Added

- Translations: Quick Booking follows the language of the user and, on the public
  booking page, of the visitor's browser. Included are all 57 languages that
  Nextcloud 34 itself offers; English stays the fallback. Most translations were
  made with AI, using the Calendar app's own wording where it exists: corrections
  are welcome (see "Translations" in the README).
- The self-check table in the admin settings is shown in your language.

### Changed

- The booking calendar starts the week on the first day of the visitor's language
  (for example Sunday in English, Monday in Dutch).
- Pages follow the reading direction of right-to-left languages.
- The "Create a Talk room" option follows what Talk itself reports about who may
  start conversations.

### Fixed

- A time that was just taken by someone else no longer counts towards the number of
  bookings per email address.
- One-time appointment schedules are only available to users for whom the Calendar
  app is enabled (it can be limited to groups).

## 0.6.1 – 2026-11-21

No changes to the app itself: this release adds the changelog.

New since 0.6.0, **one-time appointment schedules**: offer appointments on one or
more chosen dates, each with its own times, instead of every week (e.g.
parent-teacher conferences). Create them in the Calendar app under "One-time
appointment schedules", below "Appointment schedules". The form has the same
settings as the Calendar's own appointment editor, and the schedule is shared
with a normal Calendar booking link.

## 0.6.0 – 2026-11-21

### Added

- One-time appointment schedules (see 0.6.1 above for a description).

### Changed

- The free-times lookup is rate limited for logged-in visitors too.
- Email addresses longer than 128 characters are refused with a clear message.
- Admin settings: "Last full check" is shown in your own timezone and language.

<!--
  - SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: CC0-1.0
-->
# Change Log
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

### Changed

- Load the app config values only when the app needs them, they were loaded on every request of the instance
- Refuse keys the admin settings do not send, and values that are not strings, instead of storing them

## 3.6.0 - 2026-10-05

### Added

- Added support for Nextcloud 36
- Added the import_batch_size app setting, how much a single run of the import job downloads before it lets the next one continue

### Changed

- Update @nextcloud/eslint-config to 9 and sync the lint workflows
- Update dependencies & translations

### Fixed

- Stop a file import from blocking forever when a download stalls
- Retry a failed file download once with a freshly fetched download URL, the one from the folder listing may have expired during a long import
- Stop logging a deprecation warning every time another app sends a notification
- Report files that could not be downloaded in the import finished notification, with their names, which also no longer counts them as imported
- Mention files that were already there in the import finished notification, so re-running an import does not look like a failure
- Count imported empty files as imported
- Report the number of files the current import brought, a counter left over from an interrupted import is no longer added to it
- Say in the log which file of an import could not be looked up in the target folder
- Come back to the folder listing page an import stopped in, the files behind it were left out and the import still reported itself as finished
- Stop counting the files an import downloaded itself among the files that were already there
- Try a folder whose listing failed again in a later batch instead of leaving it out of the import
- List a folder again from its first page when the page an import stopped in cannot be listed any more
- Tell the user when an import stops before it has seen the whole drive, it used to end in silence, and write the reason to the log
- Say nothing about an import the user cancelled while it was running, it used to report itself as finished

### Security

- Stop logging OneDrive download URLs; the error of a failed transfer carried the short lived access token from the URL and was logged verbatim

## 3.5.2 - 2026-07-28

### Added

- Added support for Nextcloud 35

### Changed

- Update dependencies & translations

### Fixed

- Correct the spelling of "OneDrive" in the personal settings

## 3.5.1 - 2026-04-21

### Added

- Added support for Nextcloud 34

### Changed

- Update dependencies & translations

### Removed

- Nextcloud 30 to 32 and PHP 8.1 support

## 3.5.0 - 2025-11-13

### Added

- Added support for Nextcloud 33

### Changed

- Update dependencies & translations

### Removed

- Nextcloud 29 support

## 3.4.0 - 2025-05-29

### Added

- Added support for Nextcloud 32

### Removed

- Nextcloud 28 and PHP 8.0 support

### Fixed

- Remove a non-existing filter which led to an error in syncing
- Update PHP packages to fix conflicts with Server, which leads to broken `webdav`
- Change the user agent to comply with RFC 9110
- Optimize the directory listing to use less requests
- Refactor to use direct file downloading to remove `memory exceed` errors

## 3.3.1 - 2024-11-28

### Changed

- Update dependencies

### Fixed

- Encrypt secrets in the database and not expose them to the UI

## 3.3.0 - 2024-09-24

### Added

- Added support for Nextcloud 31

### Changed

- Update dependencies

### Fixed

- Add a `ratelimit` response processing handler so that it doesn't fail when loading large amounts of data

## 3.2.2 - 2024-07-24

### Added

- Added support for Nextcloud 30

### Changed

- Update dependencies

### Removed

- Nextcloud 27 support

## 3.2.1 - 2024-04-18

### Fixed

- Don't allow a shared folder as a target folder for import

## 3.2.0 - 2024-03-24

### Added

- Added support for Nextcloud 29

### Changed

- Update dependencies & translations

### Removed

- Nextcloud 26 support

### Fixed

- Fix potential memory leaks

## 3.1.0 - 2023-10-27

### Added

- Added support for Nextcloud 28

### Changed

- Update translations

## 3.0.0 - 2023-06-30

### Added

- Added support for Nextcloud 27

### Changed

- Update translations

### Removed

- Nextcloud 24 and 25 support
- PHP 7.4 support

## 2.0.3 - 2023-05-05

### Fixed

- Fix the build process

## 2.0.2 - 2023-05-05

### Fixed

- Fix the build process

## 2.0.1 - 2023-05-05

### Fixed

- Fix the build process

## 2.0.0 - 2023-05-05

### Added

- Improve contact import, update if recent changes, more feedback to the user etc...
- Import contact notes

### Changed

- Update dependencies

### Removed

- Nextcloud 22 and 23 support

### Fixed

- Set the last modified date for folders as well
- Add a 1h timeout after which a job is not considered running anymore so another import can start

## 1.1.4 - 2022-09-28

### Added

- Added support for Nextcloud 26

### Fixed

- Use rawurlencode to encode the path in the download request

## 1.1.3 - 2022-08-24

### Added

- Added support for Nextcloud 25
- Import contact photos

### Changed

- Bring back eslint/stylelint on compilation, adjust to the new eslint config
- Use @nextcloud/vue in the settings
- Implement proper token refresh based on the expiration date
- Optionally use a popup to authenticate
- Update dependencies

### Fixed

- Fix the npm scripts
- Fix the method checking if a contact already exists

## 1.1.2 - 2021-11-12

### Added

- Added support for Nextcloud 24

### Fixed

- Handle all crashes in the import job
- Fix file import with SSE enabled, get a temp link and use it on the fly

## 1.1.0 - 2021-06-29

### Fixed

- Do not exclude "src" but "./src", the ortic PHP lib was excluded

## 1.0.2 - 2021-06-28

### Added

- Added support for Nextcloud 23

### Changed

- Get rid of all deprecated stuff
- Clean up the backend code
- Update dependencies

### Removed

- Nextcloud 20 and 21 support

## 1.0.1 - 2021-04-19

### Changed

- Update dependencies

### Fixed

- Potential mess with concurrent import jobs

## 1.0.0 - 2021-03-19

### Changed

- Update dependencies

## 0.0.12 - 2021-02-23

### Fixed

- Avoid a crash when stat() returns a float file size

## 0.0.11 - 2021-02-23

### Fixed

- One log message importance
- Catch ForbiddenException

## 0.0.10 - 2021-02-15

### Added

- Import event colors (based on the first event category)

### Changed

- Optimize the import process, resume where the last job stopped
- Let the user know if a background job is running

## 0.0.9 - 2021-02-12

### Fixed

- Remove an extra slash in the drive request URL
- Reduce the number of requests when getting the contacts number and importing contacts

## 0.0.8 - 2021-02-12

### Added

- Added support for Nextcloud 22

### Changed

- Update dependencies

### Fixed

- Import the @nextcloud/dialogs style

## 0.0.7 - 2021-01-27

### Added

- Option to choose the output directory

### Changed

- Update dependencies

## 0.0.5 - 2020-11-21

### Added

- Contact import
- Calendar import

### Changed

- Update dependencies

### Fixed

- Issue with unlimited user quota

## 0.0.4 - 2020-11-17

### Fixed

- Handle paginated results

## 0.0.3 - 2020-11-11

### Fixed

- Don't close an already closed resource when downloading

## 0.0.2 - 2020-11-08

### Changed

- No more temp files, directly download to the target file (in a stream)

## 0.0.1 - 2020-11-02

### Added

- Initial release for Nextcloud 20 and 21

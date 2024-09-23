# Change Log
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [3.3.0] - 2024-09-24

### Changes
- Bumped js libs

### Fixed
- Added a `ratelimit` response processing handler so that it doesn't fail when loading large amounts of data. Thanks to @baywet

## [3.2.2] - 2024-07-24

### Changes
- Added support for NC 30, removed support for NC 27
- Bumped js libs

## [3.2.1] - 2024-04-18

### Fixed
- Don't allow shared folder as a target folder for import

## [3.2.0] - 2024-03-24

### Changes
- Added support for NC 29, removed support for NC 26

### Fixed
- Fixed potential memory leaks
- Bumped js libs
- Updated translations from Transifex

## [3.1.0] - 2023-10-27

### Changes
 - enh: Add support nc 28

### Fixed
 - Fix(l10n): Update translations from Transifex

## [3.0.0] - 2023-06-30

### Breaking changes

 - Drop Support for php 7.4
 - Drop Support for Nextcloud 24
 - Drop Support for Nextcloud 25

### Fixed

 - More translations from transifex :blue_heart:

## [2.0.3] - 2023-05-05

### Fixed
- Fix build process

## [2.0.2] - 2023-05-05

### Fixed
- Fix build process

## [2.0.1] - 2023-05-05

### Fixed
- Fix build process

## [2.0.0] - 2023-05-05

### Breaking changes
 - Drop Support for Nextcloud 22
 - Drop Support for Nextcloud 23

### New
 - improve contact import, update if recent changes, more feedback to the user etc...
 - import contact notes

### Fixed
 - set last modified date for folders as well
 - update npm pkgs
 - add a 1h timeout after which a job is not considered running anymore so another import can start

## 1.1.4 – 2022-09-28
### Fixed
- use rawurlencode to encode path in download request
[#29](https://github.com/nextcloud/integration_onedrive/pull/29) @n-stein

## 1.1.3 – 2022-08-24
### Added
- import contact photos

### Changed
- bump js libs, bring back eslint/stylelint on compilation, adjust to new eslint config
- use @nextcloud/vue in settings
- implement proper token refresh based on expiration date
- optionally use a popup to authenticate

### Fixed
- npm scripts
- method checking if contact already exists

## 1.1.2 – 2021-11-12
### Fixed
- handle all crashes in import job
- fix file import with SSE enabled, get temp link and use it on the fly

## 1.1.0 – 2021-06-29
### Fixed
- do not exclude "src" but "./src", ortic PHP lib was excluded

## 1.0.2 – 2021-06-28
### Changed
- bump js libs
- get rid of all deprecated stuff
- bump min NC version to 22
- cleanup backend code

## 1.0.1 – 2021-04-19
### Changed
- bump js libs

### Fixed
- potential mess with concurrent import jobs

## 1.0.0 – 2021-03-19
### Changed
- bump js libs

## 0.0.12 – 2021-02-23
### Fixed
- avoid crash when stat() returns float file size

## 0.0.11 – 2021-02-23
### Fixed
- one log message importance
- catch ForbiddenException

## 0.0.10 – 2021-02-15
### Added
- import event colors (based on first event category)

### Changed
- optimize import process, resume where last job stopped
- let user know if a background job is running

## 0.0.9 – 2021-02-12
### Fixed
- remove extra slash in drive request URL
- reduce number of requests when getting contacts number and importing contacts

## 0.0.8 – 2021-02-12
### Changed
- bump js libs
- bump max NC version

### Fixed
- import nc dialogs style

## 0.0.7 – 2021-01-27
### Added
- option to choose output directory

### Changed
- bump js libs

## 0.0.5 – 2020-11-21
### Added
- contact import
- calendar import

### Changed
- bump js libs

### Fixed
- issue with unlimited user quota

## 0.0.4 – 2020-11-17
### Fixed
- handle paginated results
[#3](https://github.com/nextcloud/integration_onedrive/issues/3) @shr3k

## 0.0.3 – 2020-11-11
### Fixed
- don't close already closed resource when downloading

## 0.0.2 – 2020-11-08
### Changed
- no more temp files, directly download to target file (in a stream)

## 0.0.1 – 2020-11-02
### Added
* the app

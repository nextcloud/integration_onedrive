# Change Log
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

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

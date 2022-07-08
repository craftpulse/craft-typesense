# Typesense Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).


## 1.0.0-beta.12 - 2022-07-08
### Fixed
- Fixed the typo on fetching the indexName in the queue job to flush

## 1.0.0-beta.11 - 2022-07-08
### Fixed
- Fixed the deletion of the collection when flushing the collections

### Added
- Added Typesense Service to connect to the Typesense Client
- Added the nearest node settings

### Changed
- Removed the singleton connection in the root
- Removed the key generation

## 1.0.0-beta.10 - 2022-07-07
### Added
- Added support for the Typsense cloud with the nearest cluster

## 1.0.0-beta.9 - 2022-07-07
### Fixed
- Fix the unknown deletion on flush

## 1.0.0-beta.8 - 2022-07-06
### Fixed
- Fix delete collection on flush for the console flush

## 1.0.0-beta.7 - 2022-07-06
### Fixed
- Fix the null check on document delete

## 1.0.0-beta.6 - 2022-06-20
### Added
- Added a console controller to flush the indexes

## 1.0.0-beta.5 - 2022-06-20
### Fixed
- Fix set check on create typesense client if it's not a console request

## 1.0.0-beta.4 - 2022-06-20
### Fixed
- Fixed the session error in yaml

## 1.0.0-beta.3 - 2022-06-20
## Added
- Provide different entry types for sections

### Fixed
- Fixed bug in the job to delete documents that doesn't exists
- Fixed update the type of the section

## 1.0.0-beta.2 - 2022-06-20
### Fixed
- Fixed save entry bug where `craft\elements\MatrixBlock::section` doesn't exist
- Fixed the not upserting of the document after save

## 1.0.0-beta.1 - 2022-06-20
### Added
- Connection to Typesense server / cluster
- Creation of schema's and documents
- Provide documents skeleton through config files

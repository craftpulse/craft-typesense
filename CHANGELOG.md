# Typesense Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

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

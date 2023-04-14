# Typesense Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## 5.0.0 - 2023-03-13 / Official release
### Added
- Docs for the official release
- Seperate typesense logging

### Changed
- Move the deletion of the collection into the sync if it's a flush #7

## 4.0.2 - 2022-11-04
### Added
- Added the possibility to handle save for all the types inside of the section

## 4.0.1 - 2022-10-25
### Fix
- Fixed: the project rebuild command was failing because of project config settings, these were disabled since we don't use them yet

## 4.0.0 - 2022-10-06
### Added
- Added: A sync console command

## 4.0.0-beta.3 - 2022-09-19
### Changed
- Changed: disabled the before routing fetch to check for scheduled posts

## 4.0.0-beta.2 - 2022-09-19
### Added
- Added: Delete the document when setting an enabled entry to disable [#6](https://github.com/percipioglobal/craft-typesense/issues/6)
- Added: Create the document when a scheduled post becomes active [#9](https://github.com/percipioglobal/craft-typesense/issues/9)

## 4.0.0-beta.1 - 2022-08-21
### Added
- Added support for Craft 4

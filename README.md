# Typesense plugin for Craft CMS 5.x

Craft Plugin that synchronises with Typesense.

<!-- Visit our [Demo](https://typesense.percipio.london/demo) to see the Craft Typesense plugin in action. You can read our [docs](https://typesense.percipio.london/docs/about) to setup your project. Need more help with the setup? Follow our blogpost "[Setup the Typsesense plugin with Typesense Cloud with javascript](https://percipio.london/blog/craftcms-plugin-typsesense)" -->

![Screenshot](resources/img/banner.jpg)

## Requirements

This plugin requires Craft CMS 5.0.0 or later.

## Upcoming namespace change (5.9.0)

In 5.9.0 the plugin's internal PHP namespace moves from `percipiolondon\typesense`
to `craftpulse\typesense`. The Composer package name (`craftpulse/craft-typesense`)
and the plugin handle (`typesense`) do not change, so project config, permissions,
and settings are unaffected.

The move is bridged in both directions so you can migrate on your own schedule:

- On this 5.8.x line, a forward-compatibility alias lets you reference the future
  `craftpulse\typesense\*` class names today (for example in `config/typesense.php`
  or your own code). They resolve to the existing `percipiolondon\typesense\*`
  classes.
- On the 5.9.0 line, a backwards-compatibility alias keeps the old
  `percipiolondon\typesense\*` class names working (with a deprecation notice).

If you reference plugin classes directly, update your imports to
`craftpulse\typesense\*` before upgrading to 5.9.0.

## Installation

To install the plugin, follow these instructions.

1.  Open your terminal and go to your Craft project:

        cd /path/to/project

2.  Then tell Composer to load the plugin:

        composer require craftpulse/craft-typesense

3.  In the Control Panel, go to Settings → Plugins and click the “Install” button for Typesense.

## Typesense Documentation

In our [Github Wiki](https://github.com/craftpulse/craft-typesense/wiki) where you can find the information and documentation about the plugin.

Brought to you by [craftpulse](https://craft-pulse.com/)

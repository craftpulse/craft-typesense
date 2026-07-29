---
title: A/B testing (Pro)
description: Defining search experiments and splitting traffic between variants
---
# A/B testing (Pro)

The Pro edition adds an A/B testing composition screen: define an experiment over
a collection with two or more weighted variants, each applying its own
composition (a scoped-key profile, a search preset, and an analytics tag). Traffic
is split by deriving a per-variant scoped search key, so each request is
attributed to its variant server-side.

## Editions and permissions

A/B testing is a Pro feature, gated by `typesense:manage-collections`. In the Free
edition the plugin renders none of the Pro control panel. Every action is
edition-checked server-side.

## Defining an experiment

An experiment names a collection and a set of variants. Each variant carries:

| Field | Purpose |
| --- | --- |
| Handle | The variant identifier. |
| Weight | The variant's share of traffic (relative to the other variants). |
| Scoped-key profile | The scoped-key profile (see `docs/pro-keys.md`) the variant's derived key applies, locking its filter. |
| Preset | An optional search preset the variant uses. |
| Analytics tag | The tag attributed to the variant's searches (requires an analytics-capable server). |

Experiments persist to project config, so they deploy and review like the rest of
your configuration.

## Splitting traffic

In a template, resolve the experiment to a chosen variant and its derived key:

```twig
{% set ab = craft.typesense.experiment('hero-ranking') %}
{# ab.variant  the chosen variant handle
   ab.key      a scoped search key that embeds the variant's analytics tag and
               applies its profile filter
   ab.tag      the analytics tag
   ab.preset   the variant's preset (pass it to your search call) #}
```

The plugin picks a variant by weight, derives the scoped key server-side (the
search-only key never reaches the browser), and embeds the variant's
`analytics_tag` when the server supports analytics tags, so results are attributed
to the variant without the front end having to report anything.

## The comparison panel

Comparing the variants (click-through rate, no-result rate, per-tag) is the
analytics dashboard's job, which arrives in a later phase. This screen defines and
orchestrates the experiment and derives the per-variant keys; the comparison view
reads the analytics those tagged searches produce. The seam is intentional: define
here, compare there.

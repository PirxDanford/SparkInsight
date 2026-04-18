# RFC 0001: Feedback lifecycle for Scrivener review system

Status: Draft

Date: 2026-04-15

## Summary

Define the core feedback lifecycle for the Scrivener-connected review platform.

## Problem

Authors work in Scrivener and should not be forced into a separate workflow.
Reviewers should have encapsulated views and must not see other reviewers' comments.
Changes to the author document must be linked to relevant feedback and require explicit author confirmation.

## Proposal

1. Store every imported Scrivener document as an immutable version.
2. Anchor reviewer comments to specific document version locations.
3. On each new version, detect regions changed relative to the previous version.
4. Flag affected reviews as needing author attention, without auto-resolving them.
5. Let the author mark review status explicitly:
   - `resolved`
   - `still relevant`
   - `acknowledged but ignored`

## Why this works

This model preserves the author’s existing Scrivener workflow.
It keeps reviewer feedback isolated, while giving reviewers history and explicit status updates.

## Open questions

- Should the review platform support manual reviewer closure as well as author closure?
- How granular should document anchoring be: paragraph, section, or semantic block?
- Should author notifications be immediate or batched per version?

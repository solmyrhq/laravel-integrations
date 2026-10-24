---
name: exhaustive-review
description: Review the current branch, or the whole codebase, through seven lenses. Write each finding, and each earlier PR comment that is not yet fully addressed, to report.html in the root of the repository. In CI, post them on the pull request instead.
disable-model-invocation: true
---

# Exhaustive review

In Codex, read [Codex tool mappings](../codex-tools.md) before following this workflow.

This is the only review round this change will get, so the report must be a complete accounting: include each defect that you find in the change or in code that the change calls, and each comment that anyone has already left that is not yet fully addressed. Be thorough, even if the review takes longer or costs more.

## Rules

- Don't modify the working tree. Neither you nor any sub-agent may edit, stage, commit, stash or check out anything in the repository under review.
- Don't post anything. Don't comment on the PR, resolve threads, or send the report anywhere.
- Assess each finding. Give it a confidence (high, medium or low).
- Give each finding a [Conventional Comments](https://conventionalcomments.org) label, such as `issue (blocking):` or `suggestion (non-blocking):`.

## Workflow

- Track the work as tasks. Be liberal with task tracking.
- Decide the scope. If the user asks for a review of the whole codebase, review every file in the repository and treat the whole repository as the change. Otherwise, the change is the diff against the PR's base branch. If there is no PR, the change is the diff against the repository's default branch. If the scope or the base branch is not clear, ask the user.
- If there is a PR, use the `gh` CLI to fetch its description, comments, reviews and review threads. Check each one against the current code. A comment is fully addressed when the problem that it reports is fixed completely in the current code. A comment is also fully addressed when a person has replied that they won't make the change, even if you disagree with that decision. A reply from a bot does not address a comment.
- Plan the coverage for each lens below.
- Do the review. Be thorough.
- Write every finding, and every earlier comment that is not yet fully addressed, to `report.html` in the root of the repository.
- HTML-escape every comment and code snippet that you quote in the report.

## In CI

You are running in CI only when the caller tells you so in the prompt. Don't decide it from the environment. If the caller tells you that you are running in CI, read [references/ci.md](references/ci.md) and follow it. That file contains every instruction for CI.

## Review lenses

### Accessibility

Audit every interface change for WCAG 2.2 AA: alt text on meaningful images, semantic structure, contrast of at least 4.5:1 for normal text and 3:1 for large text and UI components, keyboard access to everything with a logical focus order and a visible focus indicator, labelled inputs, described errors, and a name, role and value on every component. Look for focus traps in modals, a focused element that a sticky header or other content hides entirely, missing landmarks, auto-playing media, and click or touch targets smaller than 24x24 CSS pixels. A smaller target still passes if a 24-pixel-diameter circle centred on it intersects no other target and no other target's circle. Don't report a target of 24x24 or larger as an AA failure, because 44x44 is a Level AAA requirement.

### General Code Review

Review each change for security (injection, XSS, CSRF, broken authentication or authorization, secrets in code, insecure deserialization, path traversal, SSRF), performance (N+1 queries, missing indexes, unbounded queries or loops, quadratic work in hot paths, resource leaks), correctness (empty, null and overflow edge cases, race conditions, error propagation, off-by-one errors, type safety) and maintainability (naming, single responsibility, duplication, test coverage, documentation of non-obvious logic).

### Security as a Researcher

Trace user-controlled input across files, from entry points (parameters, headers, bodies, uploads) to sinks (queries, shell calls, HTML output, file writes), and also audit dependencies, secrets in config, CI and Dockerfiles, cryptography and weak randomness, and business logic such as time-of-check races, money arithmetic and missing rate limits.

Research the whole codebase before you report on the change: confirm that the input is controlled by an attacker and not by server configuration, and check for framework protections and validation upstream. Report what you have confirmed is exploitable, and list each vulnerable pattern whose input source you could not confirm under "needs verification".

### SQL

Check every query for parameterization, `SELECT *` on tables with sensitive columns, missing or redundant indexes, `WHERE` clauses that wrap an indexed column in a function, N+1 patterns, `DISTINCT` used to remove the duplicate rows of a bad join, and joins with no condition. Check every schema change for constraints, foreign keys, appropriate data types (an exact decimal type for money, never a float) and defaults.

### Visual Design

Look for content that overflows or overlaps, inconsistent alignment and spacing, clipped text, layouts that break at 375, 768, 1280 or 1920 pixels wide, touch targets that fail the target-size rule in the Accessibility lens, missing focus states, and fonts or colours that are not in the design system.

### Structural Quality / Principal Architect Review

Be ambitious about structure: look for ways to restructure the code so that you can delete whole branches, layers, modes or helpers without changing the behaviour. Treat these as design problems, not nits: ad-hoc conditionals in unrelated flows, a change that makes a file longer than 1,000 lines, thin wrappers, needless casts, `any` or optional parameters, logic in the wrong layer, a bespoke duplicate of an existing helper, needlessly sequential work, and state that is left half-updated if an update fails partway.

### Framework Best Practices

Check the frameworks/stacks involved for best practices that might be getting missed. Anything you think could've been done better or more idiomatically?

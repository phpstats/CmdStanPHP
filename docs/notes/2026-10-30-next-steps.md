# Next steps after the CmdStanR 1.0 port

## Where things stand

- The CmdStanR 1.0 port (PR #3) is merged into `main` at a853878, and the CI
  run on `main` after the merge passed.
- PR #1 (family renames) and PR #2 (`draws_table()` and `summary_table()`) are
  merged too.
- `main` matches `origin/main`, and there are no open PRs.

## What's left

1. **Site vignettes.** The `site-content` branch (vignettes, benchmarks and
   verifications under `site/`, for phpstats.org) was never merged. It
   predates the 1.0 port, so it needs a rebase onto `main`, then its examples
   need checking against the new API: no `compile()`, padded file names,
   `mle()` without `lp__`, `loo()` without relative efficiencies by default,
   and errors for string table columns. Then open a PR.
   The branch is checked out in a worktree at
   `.claude/worktrees/agent-a5ec1af2c16c7f94b`, which has to be removed before
   the branch can be checked out anywhere else.
2. **Branch cleanup.** `cmdstanr-1.0` and `family-renames` are merged and can
   be deleted locally and on GitHub. `worktree-agent-a5ec1af2c16c7f94b` is a
   local branch left over from a helper agent and can go too.
3. **CmdStanR 1.0.0 final.** CI checks against CmdStanR's release candidate,
   pinned to commit 0a54123 in `.github/workflows/tests.yml`, which reports
   version 0.99.0. When 1.0.0 is released, move the pin to the release and
   update the version check in the same step.

## Open questions

- The phiz-figures work from the phiz session was never coordinated, because
  messages to that session bounced. Check whether it still needs anything from
  CmdStanPHP.

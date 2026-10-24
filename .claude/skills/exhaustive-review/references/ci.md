# Exhaustive review in CI

In CI, a GitHub Actions workflow runs this skill on a pull request. The workflow gives you the number of the pull request, its repository and its head commit. Post the review on the pull request instead of writing `report.html`. In CI, the rule "Don't post anything" in `SKILL.md` does not apply. Follow the instructions in this file instead. The other rules in `SKILL.md` still apply.

## Read the pull request

* Get the state, title and description of the pull request with `gh pr view <number> --json state,isDraft,title,body`. Without `--json`, `gh pr view` also requests the status of the checks. The token in CI cannot read the checks, so the command fails. If the pull request is closed or is a draft, stop and don't post anything.
* The change is the diff from `gh pr diff <number>`. Don't ask anyone about the scope.
* Read the earlier inline comments with `gh api repos/<repository>/pulls/<number>/comments --paginate`. Read the earlier reviews with `gh api repos/<repository>/pulls/<number>/reviews --paginate`. Read the other comments with `gh api repos/<repository>/issues/<number>/comments --paginate`. Don't post a finding if an earlier comment or review already contains it, or if a person has declined it.
* Get the review threads with this GraphQL query. For each thread, the query returns its ID and whether it is resolved. The REST endpoints above return neither of these. `<repository>` is `<owner>/<repo>`.

  ```sh
  gh api graphql --paginate -f owner=<owner> -f repo=<repo> -F number=<number> -f query='
  query($owner: String!, $repo: String!, $number: Int!, $endCursor: String) {
    repository(owner: $owner, name: $repo) {
      pullRequest(number: $number) {
        reviewThreads(first: 100, after: $endCursor) {
          pageInfo { hasNextPage endCursor }
          nodes {
            id isResolved isOutdated path line originalLine
            comments(first: 100) { nodes { author { login } body createdAt } }
          }
        }
      }
    }
  }'
  ```

## Resolve the threads that are fully addressed

Examine each unresolved thread in which the Claude GitHub App posted the first comment. In the GraphQL query, the login of the Claude GitHub App is `claude`. In the REST endpoints, its login is `claude[bot]`. These threads include threads from earlier runs of this review. They also include threads from other Claude workflows in the repository, for example `/code-review --comment` runs and `@claude` jobs. If one of these threads is fully addressed, resolve it (see the definition in `SKILL.md`).

GitHub marks a thread as outdated when the lines that the thread is on change. The outdated flag does not mean that the problem is fixed. Check the code at the head commit.

To resolve a thread, run:

```sh
gh api graphql -f threadId=<id> -f query='
mutation($threadId: ID!) {
  resolveReviewThread(input: { threadId: $threadId }) { thread { isResolved } }
}'
```

Never resolve a thread if a person or a bot other than the Claude GitHub App posted its first comment. Never unresolve a thread.

## Post the review

* Post each finding that is on a line in the diff as a separate inline comment on that line. Use `mcp__github_inline_comment__create_inline_comment`, and pass `confirmed: true`. Start the comment with the finding's Conventional Comments label and its confidence. Add a suggestion block only if committing the suggestion unchanged would fix the problem completely.
* Then post one summary comment with `gh pr comment <number>`. In the summary comment:
  * Name the head commit.
  * Give the number of findings that you posted for each label.
  * List each finding that is not on a line in the diff, and each earlier comment that is not yet fully addressed.
  * List each thread that you resolved, with one line that gives the reason: the commit that contains the fix, or the person who declined the change.
  * If you found nothing, say so.

# Branch rulesets

`main-regression-required.json` makes the **regression** check (the *Regression suites* workflow, which runs
on every pull request) a required status check on the default branch. Repository settings cannot be changed
through the GitHub access these Claude sessions have, so this is a two-click import for a repository admin:

1. GitHub → repository **Settings** → **Rules** → **Rulesets**
2. **New ruleset** ▾ → **Import a ruleset** → choose `.github/rulesets/main-regression-required.json`
3. Check that Enforcement reads **Active** and **Create**

`integration_id` 15368 is GitHub Actions. The *Version consistency* check is deliberately not required here:
it only runs when the engine files change, and a required check that does not run leaves a pull request
waiting forever.

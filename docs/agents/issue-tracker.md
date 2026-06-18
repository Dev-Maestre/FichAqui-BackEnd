# Issue tracker: GitHub

Issues and PRDs for this repo live as GitHub issues in **`Dev-Maestre/FichAqui-BackEnd`**. Use the `gh` CLI for all operations.

## Conventions

- **Create an issue**: `gh issue create --repo Dev-Maestre/FichAqui-BackEnd --title "..." --body "..."`. Use a heredoc for multi-line bodies.
- **Read an issue**: `gh issue view <number> --repo Dev-Maestre/FichAqui-BackEnd --comments`, filtering comments by `jq` and also fetching labels.
- **List issues**: `gh issue list --repo Dev-Maestre/FichAqui-BackEnd --state open --json number,title,body,labels,comments --jq '[.[] | {number, title, body, labels: [.labels[].name], comments: [.comments[].body]}]'` with appropriate `--label` and `--state` filters.
- **Comment on an issue**: `gh issue comment <number> --repo Dev-Maestre/FichAqui-BackEnd --body "..."`
- **Apply / remove labels**: `gh issue edit <number> --repo Dev-Maestre/FichAqui-BackEnd --add-label "..."` / `--remove-label "..."`
- **Close**: `gh issue close <number> --repo Dev-Maestre/FichAqui-BackEnd --comment "..."`

When run inside `FichAqui-BackEnd/` (a git clone of that repo), `gh` infers the repo automatically and `--repo` can be omitted.

## When a skill says "publish to the issue tracker"

Create a GitHub issue in `Dev-Maestre/FichAqui-BackEnd`.

## When a skill says "fetch the relevant ticket"

Run `gh issue view <number> --repo Dev-Maestre/FichAqui-BackEnd --comments`.

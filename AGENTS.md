# komtet-kassa-bitrix

## About the Project

`komtet-kassa-bitrix` is a plugin for 1C-Bitrix CMS for integration with the Komtet Kassa service.
The plugin is written in PHP 8.2. It subscribes to order creation and update events — both for the legacy core and the D7 core — and has separate handlers for each of these events. When order conditions are met, the handler uses the `komtet-kassa-php-sdk` SDK methods to build the JSON structure of a fiscal receipt and sends it to the Komtet Kassa service via a POST or PUT request.

## Terminology

The term "explicit instruction" in this document means a direct statement in the user's task.
Conclusions, guesses, and the agent's own decisions do not constitute an explicit instruction.

## Technologies

- PHP 8.2
- 1C-Bitrix CMS (legacy core and D7 core)
- `komtet-kassa-php-sdk` SDK
- Git submodules

## Repository Structure

- `data/` — database. Do not process, ignore.
- `php/` — installed 1C-Bitrix CMS. Do not process, ignore.
- `komtet.kassa/` — `komtet-kassa-bitrix` plugin code.
- `komtet.kassa/lib/komtet-kassa-php-sdk/` — `komtet-kassa-php-sdk` SDK code, connected as a git submodule.
- `komtet.kassa/skills/` — skills for agents.
- `komtet-kassa-bitrix-delivery/komtet.delivery/` — if it exists, `komtet-kassa-bitrix-delivery` plugin code.
- `komtet-kassa-bitrix-delivery/komtet.delivery/lib/komtet-kassa-php-sdk/` — if it exists, `komtet-kassa-php-sdk` SDK code, connected as a git submodule (the directory may be absent if `komtet-kassa-bitrix-delivery/` is absent).

## Scope

- Make primary changes in `komtet.kassa/`.
- Work in `komtet-kassa-bitrix-delivery/komtet.delivery/` is allowed only when there is a separate task and only if the directory exists. If the directory does not exist, the task for that plugin is not performed and the directory is not created.
- If a directory is absent, do not create it without an explicit instruction.

## Events and Handlers

- The plugin subscribes to order creation and update events.
- Separate event handlers are provided for the legacy core and the D7 core.
- When making changes, consider both subscriptions and both handler groups: changing one handler without synchronously changing the paired handler for the other core is not allowed unless the task is explicitly limited to one core.
- Do not change the order of event subscriptions without an explicit instruction.
- A receipt is generated only when order conditions are met.
- Use `komtet-kassa-php-sdk` methods to build the receipt JSON structure and send it to the service.

## Working with the SDK and Submodules

- Any changes to the SDK code are forbidden.
- Do not inspect the SDK code unless directly necessary — i.e., unless it is required to understand or modify the plugin code.
- If necessary, you may propose SDK changes — as a description or suggestion in the report, without editing the submodule itself.
- Do not modify or move the git submodule.
- Updating the SDK submodule reference is forbidden.
- Updating the commit reference in the SDK submodule — only by explicit instruction; state this in the report.
- Do not mix changes to the main plugin and the SDK in a single commit.
- Do not mix changes to the `komtet.kassa` and `komtet-kassa-bitrix-delivery` plugins in a single commit.

## Development Rules

- Target PHP version — 8.2.
- Follow the existing code style.
- Do not add dependencies unnecessarily.
- Ensure compatibility with 1C-Bitrix and both cores (legacy and D7).

## Prohibitions

- Modifying or moving the git submodule is forbidden.
- Deleting comments is forbidden unless the code they refer to is also deleted.
- Modifying comments is forbidden unless the code they refer to is also modified.
- Renaming in code is forbidden. Renaming proposals are allowed — describe them in the report rather than applying them to the code.
- Refactoring is forbidden. Refactoring proposals are allowed — describe them in the report rather than applying them to the code.
- Adding secrets, tokens, passwords, or local configuration files to the code is forbidden, as is committing them.
- Running destructive delete, clean, or reset commands without confirmation is forbidden.
- Running migrations or database operations without an explicit instruction is forbidden.
- Changing the CMS configuration is forbidden.

## Reading `data/` and `php/`

- Do not read or analyze the `data/` and `php/` directories.
- Stop any task that requires reading these directories and ask for the reason: these directories usually exceed 1 GB in size, and reading them may consume an enormous number of tokens.

## What the Agent Should Do

1. Clarify the task and the affected directories.
2. Ensure the task does not require reading `data/` or `php/`.
3. Account for the paired handlers for the legacy core and D7, and for both subscriptions — to order creation and update.
4. Make minimal changes without violating the prohibitions in the "Prohibitions" section.
5. Check PHP syntax.
6. In the report, list the changed files, the checks performed, and the events and cores affected by the changes.
7. Do not include a risk audit in the report — separate skills exist for that.

## Skills

- The `komtet.kassa/skills/` directory contains skills for agents.

## Language

- Documentation and comments — in Russian, unless otherwise established in the project.
- Commit messages — according to project rules; if there are no rules, keep them brief and to the point.